<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../utils/mailer.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';

try {
    $data = readJsonBody();
    $name = trim((string) ($data['name'] ?? ''));
    $email = strtolower(trim((string) ($data['email'] ?? '')));
    $company = trim((string) ($data['company_name'] ?? ''));
    $phone = trim((string) ($data['phone'] ?? ''));
    $businessSize = trim((string) ($data['business_size'] ?? ''));
    $preferredDate = trim((string) ($data['preferred_date'] ?? ''));
    $message = trim((string) ($data['message'] ?? ''));

    if ($name === '' || $company === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Name, business name and a valid email address are required.', 422);
    }
    if (mb_strlen($message) > 2000) {
        throw new Exception('Message is too long.', 422);
    }
    $ip = clientIpAddress();
    $rate = $conn->prepare('SELECT COUNT(*) AS requests FROM demo_requests WHERE email = ? AND ip_address = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)');
    $rate->bind_param('ss', $email, $ip);
    $rate->execute();
    $requests = (int) ($rate->get_result()->fetch_assoc()['requests'] ?? 0);
    $rate->close();
    if ($requests >= 3) {
        throw new Exception('Your demo request has already been received. Our team will reach out shortly.', 429);
    }

    $stmt = $conn->prepare('INSERT INTO demo_requests (name, email, company_name, phone, business_size, preferred_date, message, ip_address) VALUES (?, ?, ?, ?, ?, NULLIF(?, \'\'), ?, ?)');
    $stmt->bind_param('ssssssss', $name, $email, $company, $phone, $businessSize, $preferredDate, $message, $ip);
    $stmt->execute();
    $demoRequestId = (int) $stmt->insert_id;
    $stmt->close();

    $confirmation = sendTransactionalMail(
        $conn,
        'demo.request_confirmation',
        $email,
        $name,
        'We received your DigitalInvoice demo request',
        demoRequestConfirmationEmail($name, $company),
        null,
        null,
        'demo_confirmation:' . $demoRequestId,
        ['demo_request_id' => $demoRequestId]
    );

    $notificationEmail = trim((string) config('DEMO_NOTIFICATION_EMAIL', ''));
    if ($notificationEmail !== '' && filter_var($notificationEmail, FILTER_VALIDATE_EMAIL)) {
        sendTransactionalMail(
            $conn,
            'demo.internal_notification',
            $notificationEmail,
            config('DEMO_NOTIFICATION_NAME', appName()) ?? appName(),
            'New DigitalInvoice demo request from ' . $company,
            demoRequestNotificationEmail($name, $email, $company, $phone, $preferredDate, $message),
            null,
            null,
            'demo_internal:' . $demoRequestId,
            ['demo_request_id' => $demoRequestId]
        );
    }

    jsonSuccess([
        'message' => 'Your demo request has been received. Our team will contact you shortly.',
        'data' => ['email_delivery_status' => $confirmation['status']],
    ], 201);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code < 500) ? $e->getMessage() : 'Unable to submit demo request.', ($code >= 400 && $code <= 599) ? $code : 500);
}
