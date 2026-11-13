<?php

declare(strict_types=1);

use Google\Client as GoogleClient;

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/onboarding.php';
require_once __DIR__ . '/../../includes/mfaService.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';

try {
    if (!configBool('GOOGLE_LOGIN_ENABLED', false)) {
        throw new Exception('Google sign-in has not been enabled.', 503);
    }

    $clientId = requiredConfig('GOOGLE_CLIENT_ID');
    $data = readJsonBody();
    $credential = trim((string) ($data['credential'] ?? ''));
    $intent = strtolower(trim((string) ($data['intent'] ?? 'login')));
    if (!in_array($intent, ['login', 'register'], true)) {
        throw new Exception('Google sign-in intent is invalid.', 422);
    }
    if ($credential === '') {
        throw new Exception('Google credential is required.', 422);
    }

    $google = new GoogleClient(['client_id' => $clientId]);
    $payload = $google->verifyIdToken($credential);
    if (!$payload || empty($payload['sub']) || empty($payload['email']) || empty($payload['email_verified'])) {
        throw new Exception('Google sign-in could not be verified.', 401);
    }

    $provider = 'google';
    $providerUserId = (string) $payload['sub'];
    $email = strtolower(trim((string) $payload['email']));
    $googleName = trim((string) ($payload['name'] ?? $data['name'] ?? 'DigitalInvoice User'));
    // Google advises using email-based automatic association only where Google is authoritative:
    // Gmail addresses, or verified Google Workspace addresses with a hosted-domain claim.
    $googleIsAuthoritativeForEmail = str_ends_with($email, '@gmail.com') || (!empty($payload['hd']) && !empty($payload['email_verified']));
    $developmentVerificationUrl = null;
    $emailDeliveryStatus = null;
    $newAccount = false;
    $linkedExistingAccount = false;
    $deferredGoogleLink = false;

    $identity = $conn->prepare("SELECT user_id FROM auth_identities WHERE provider = 'google' AND provider_user_id = ? LIMIT 1");
    $identity->bind_param('s', $providerUserId);
    $identity->execute();
    $linked = $identity->get_result()->fetch_assoc();
    $identity->close();

    if ($linked) {
        $user = fetchUserWithOrganization($conn, (int) $linked['user_id']);
    } else {
        $emailLookup = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $emailLookup->bind_param('s', $email);
        $emailLookup->execute();
        $existingEmail = $emailLookup->get_result()->fetch_assoc();
        $emailLookup->close();

        if ($existingEmail) {
            if (!$googleIsAuthoritativeForEmail) {
                throw new Exception('An account already uses this email. Sign in with your password first before connecting this Google profile.', 409);
            }

            $user = fetchUserWithOrganization($conn, (int) $existingEmail['id']);
            if (!$user || (int) $user['is_active'] !== 1 || in_array((string) $user['organization_status'], ['suspended', 'archived'], true)) {
                throw new Exception('Your account is unavailable. Please contact support.', 403);
            }

            // Do not link a new login provider before the existing account's MFA is passed.
            if (mfaEnabled($conn, (int) $user['id'])) {
                $deferredGoogleLink = true;
            } else {
            $conn->begin_transaction();
            try {
                if (empty($user['email_verified_at'])) {
                    $verifyExisting = $conn->prepare('UPDATE users SET email_verified_at = NOW(), updated_at = NOW() WHERE id = ?');
                    $verifyExisting->bind_param('i', $user['id']);
                    $verifyExisting->execute();
                    $verifyExisting->close();

                    $activatePendingOrganization = $conn->prepare("UPDATE organizations SET status = 'subscription_required', updated_at = NOW() WHERE id = ? AND status = 'pending_verification'");
                    $activatePendingOrganization->bind_param('i', $user['organization_id']);
                    $activatePendingOrganization->execute();
                    $activatePendingOrganization->close();
                }

                $createIdentity = $conn->prepare('INSERT INTO auth_identities (user_id, provider, provider_user_id, provider_email) VALUES (?, ?, ?, ?)');
                $createIdentity->bind_param('isss', $user['id'], $provider, $providerUserId, $email);
                $createIdentity->execute();
                $createIdentity->close();

                auditLog($conn, (int) $user['id'], 'auth.google_linked', 'Google Sign-In was securely connected to the existing account.', (int) $user['organization_id']);
                $conn->commit();
            } catch (Throwable $error) {
                $conn->rollback();
                throw $error;
            }

            $user = fetchUserWithOrganization($conn, (int) $existingEmail['id']);
            $linkedExistingAccount = true;
            $loginUrl = rtrim(config('FRONTEND_URL', 'http://localhost:5173') ?? 'http://localhost:5173', '/') . '/login';
            $delivery = sendTransactionalMail(
                $conn,
                'auth.google_linked',
                (string) $user['email'],
                (string) $user['name'],
                'Google Sign-In connected to your DigitalInvoice account',
                googleAccountLinkedEmail((string) $user['name'], $loginUrl),
                (int) $user['id'],
                (int) $user['organization_id'],
                'google_linked:' . (int) $user['id'] . ':' . hash('sha256', $providerUserId)
            );
            $emailDeliveryStatus = $delivery['status'];
            } // Non-MFA accounts retain the original verified Google auto-linking flow.
        } else {
            if ($intent !== 'register') {
                throw new Exception('No DigitalInvoice account matches this Google profile. Create your business account first.', 404);
            }

            $organizationName = trim((string) ($data['organization_name'] ?? ''));
            if ($organizationName === '') {
                throw new Exception('Business name is required when creating an account with Google.', 422);
            }
            if (mb_strlen($organizationName) > 180) {
                throw new Exception('Business name is too long.', 422);
            }

            $conn->begin_transaction();
            try {
                $user = createOrganizationAndOwner($conn, $googleName, $email, null, $organizationName, $googleIsAuthoritativeForEmail);
                $link = $conn->prepare('INSERT INTO auth_identities (user_id, provider, provider_user_id, provider_email) VALUES (?, ?, ?, ?)');
                $link->bind_param('isss', $user['id'], $provider, $providerUserId, $email);
                $link->execute();
                $link->close();
                if (!$googleIsAuthoritativeForEmail) {
                    $verificationToken = createVerificationToken($conn, (int) $user['id']);
                    $developmentVerificationUrl = verificationUrl($verificationToken);
                }
                auditLog($conn, (int) $user['id'], 'auth.google_registered', 'Owner registered with Google.', (int) $user['organization_id']);
                $conn->commit();
                $newAccount = true;
            } catch (Throwable $error) {
                $conn->rollback();
                throw $error;
            }

            if ($developmentVerificationUrl !== null) {
                $delivery = sendTransactionalMail(
                    $conn,
                    'auth.google_email_verification',
                    $email,
                    $googleName,
                    'Verify your DigitalInvoice email address',
                    verificationEmail($googleName, $developmentVerificationUrl),
                    (int) $user['id'],
                    (int) $user['organization_id'],
                    'google_verification:' . (int) $user['id']
                );
            } else {
                $plansUrl = rtrim(config('FRONTEND_URL', 'http://localhost:5173') ?? 'http://localhost:5173', '/') . '/subscription/plans';
                $delivery = sendTransactionalMail(
                    $conn,
                    'auth.google_welcome',
                    $email,
                    $googleName,
                    'Welcome to DigitalInvoice',
                    googleWelcomeEmail($googleName, $organizationName, $plansUrl),
                    (int) $user['id'],
                    (int) $user['organization_id'],
                    'google_welcome:' . (int) $user['id']
                );
            }
            $emailDeliveryStatus = $delivery['status'];
        }
    }

    if (!$user || (int) $user['is_active'] !== 1 || in_array((string) $user['organization_status'], ['suspended', 'archived'], true)) {
        throw new Exception('Your account is unavailable. Please contact support.', 403);
    }

    if (mfaEnabled($conn, (int) $user['id'])) {
        $challenge = mfaCreateLoginChallenge($conn, $user, $deferredGoogleLink ? ['subject' => $providerUserId, 'email' => $email] : null);
        jsonSuccess(['message' => 'Enter your authenticator code to finish Google sign-in.', 'data' => [
            'mfa_required' => true,
            'challenge_token' => $challenge,
            'method' => 'totp',
            'expires_in_seconds' => 300,
        ]]);
    }

    $update = $conn->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
    $update->bind_param('i', $user['id']);
    $update->execute();
    $update->close();
    revokeCurrentRefreshSessionIfPresent($conn, 'replaced_by_google_login');
    $csrfToken = issueAuthenticatedSession($conn, $user);
    auditLog($conn, (int) $user['id'], 'auth.google_login', 'User signed in with Google.', (int) $user['organization_id']);
    $user['last_login'] = date('Y-m-d H:i:s');
    $responseData = authPayload($conn, $user, $csrfToken);
    $responseData['google_new_account'] = $newAccount;
    $responseData['google_account_linked'] = $linkedExistingAccount;
    if ($emailDeliveryStatus !== null) {
        $responseData['email_delivery_status'] = $emailDeliveryStatus;
    }
    if ($developmentVerificationUrl !== null && isLocalEnvironment() && mailTransport() === 'log') {
        $responseData['development_verification_url'] = $developmentVerificationUrl;
    }

    $message = $newAccount
        ? 'Google account registration successful.'
        : ($linkedExistingAccount ? 'Google Sign-In has been connected to your existing DigitalInvoice account.' : 'Google sign-in successful.');
    jsonSuccess(['message' => $message, 'data' => $responseData]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) {
        error_log('Google Sign-In Error: ' . $e->getMessage());
        $code = 500;
    }
    jsonError($code === 500 ? 'Unable to complete Google sign-in.' : $e->getMessage(), $code);
}
