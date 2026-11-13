<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/authMiddleware.php';
require_once __DIR__ . '/../../includes/onboarding.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';

try {
    $user = authenticateUser();
    if (!empty($user['email_verified_at'])) {
        jsonSuccess(['message' => 'Your email address is already verified.']);
    }

    $recent = $conn->prepare('SELECT created_at FROM email_verification_tokens WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    $recent->bind_param('i', $user['id']);
    $recent->execute();
    $last = $recent->get_result()->fetch_assoc();
    $recent->close();
    if ($last && strtotime((string) $last['created_at']) > time() - 60) {
        throw new Exception('Please wait a minute before requesting another verification email.', 429);
    }

    $token = createVerificationToken($conn, (int) $user['id']);
    $url = verificationUrl($token);
    $delivery = sendTransactionalMail(
        $conn,
        'auth.email_verification_resend',
        (string) $user['email'],
        (string) $user['name'],
        'Verify your DigitalInvoice email address',
        verificationEmail((string) $user['name'], $url),
        (int) $user['id'],
        (int) $user['organization_id'],
        'verification:' . (int) $user['id'] . ':' . hash('sha256', $token)
    );
    auditLog($conn, (int) $user['id'], 'auth.verification_resent', 'Verification email requested again.', (int) $user['organization_id']);

    if (!$delivery['success']) {
        throw new Exception('Unable to deliver the verification email right now. Please try again shortly.', 502);
    }

    $responseData = ['email_delivery_status' => $delivery['status']];
    if (isLocalEnvironment() && mailTransport() === 'log') {
        $responseData['development_verification_url'] = $url;
    }
    jsonSuccess(['message' => 'A new verification email has been sent.', 'data' => $responseData]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to resend verification email.', ($code >= 400 && $code <= 599) ? $code : 500);
}
