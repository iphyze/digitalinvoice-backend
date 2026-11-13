<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/premiumRequestService.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('Method not allowed.', 405);
    }

    global $conn;
    $user = authenticateUser();
    $payload = readJsonBody();

    $result = createPremiumRequest($conn, $user, $payload);
    $message = ($result['mode'] ?? 'created') === 'updated'
        ? 'Your existing Premium request has been updated. Our team will follow up shortly.'
        : 'Your Premium request has been received. Our team will follow up shortly.';

    jsonSuccess(['message' => $message, 'data' => $result], ($result['mode'] ?? 'created') === 'updated' ? 200 : 201);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    jsonError($error->getMessage() ?: 'Unable to submit Premium request.', $status);
}
