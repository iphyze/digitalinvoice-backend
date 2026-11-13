<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/onboarding.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';

try {
    $data = readJsonBody();
    $name = trim((string) ($data['name'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $password = (string) ($data['password'] ?? '');
    $confirmation = (string) ($data['password_confirmation'] ?? '');
    $organizationName = trim((string) ($data['organization_name'] ?? ''));

    if ($name === '' || $organizationName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Full name, business name and a valid email address are required.', 422);
    }
    if (mb_strlen($name) > 120 || mb_strlen($organizationName) > 180) {
        throw new Exception('Name or business name is too long.', 422);
    }
    if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^a-zA-Z0-9]/', $password)) {
        throw new Exception('Password must be at least 8 characters and contain an uppercase letter, a number and a special character.', 422);
    }
    if ($password !== $confirmation) {
        throw new Exception('Passwords do not match.', 422);
    }

    $existing = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $existing->bind_param('s', $email);
    $existing->execute();
    $alreadyExists = $existing->get_result()->fetch_assoc();
    $existing->close();
    if ($alreadyExists) {
        throw new Exception('An account with this email address already exists.', 409);
    }

    $conn->begin_transaction();
    try {
        $user = createOrganizationAndOwner($conn, $name, $email, password_hash($password, PASSWORD_DEFAULT), $organizationName, false);
        $token = createVerificationToken($conn, (int) $user['id']);
        auditLog($conn, (int) $user['id'], 'auth.registered', 'Owner registered a DigitalInvoice business account.', (int) $user['organization_id']);
        $csrfToken = issueAuthenticatedSession($conn, $user);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        clearAuthCookies();
        throw $error;
    }

    $verifyUrl = verificationUrl($token);
    $delivery = sendTransactionalMail(
        $conn,
        'auth.email_verification',
        $email,
        $name,
        'Verify your DigitalInvoice email address',
        verificationEmail($name, $verifyUrl),
        (int) $user['id'],
        (int) $user['organization_id'],
        'verification:' . (int) $user['id'] . ':' . hash('sha256', $token)
    );

    $message = $delivery['success']
        ? 'Registration successful. Check your email to verify your account and continue.'
        : 'Your account was created, but the verification email could not be delivered. Please use resend verification shortly.';
    $response = [
        'message' => $message,
        'data' => array_merge(authPayload($conn, $user, $csrfToken), [
            'email_delivery_status' => $delivery['status'],
        ]),
    ];
    if (isLocalEnvironment() && mailTransport() === 'log') {
        $response['data']['development_verification_url'] = $verifyUrl;
    }
    jsonSuccess($response, 201);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) {
        $code = 500;
        error_log('Register Error: ' . $e->getMessage());
    }
    jsonError($code === 500 ? 'Unable to create your account at this time.' : $e->getMessage(), $code);
}
