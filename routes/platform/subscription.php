<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/platformService.php';

try {
    global $conn;

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET') {
        requirePlatformAdmin('platform.subscriptions.view');

        $id = (int) ($_GET['id'] ?? 0);
        if ($id === 0) {
            jsonError('Subscription id is required.', 422);
        }

        jsonSuccess(['message' => 'Platform subscription details retrieved successfully.', 'data' => fetchPlatformSubscriptionDetails($conn, $id)]);
    }

    if ($method === 'PUT') {
        $actor = requirePlatformAdmin('platform.subscriptions.manage');
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            throw new Exception('Invalid request format. Expected JSON object.', 400);
        }

        $id = (int) ($payload['id'] ?? $_GET['id'] ?? 0);
        unset($payload['id']);

        $providerActions = ['verify_payment', 'refresh_provider', 'refresh_paystack'];
        $action = strtolower(trim((string) ($payload['action'] ?? '')));
        $data = in_array($action, $providerActions, true)
            ? refreshPlatformSubscriptionProvider($conn, $actor, $id, $payload)
            : managePlatformSubscription($conn, $actor, $id, $payload);

        jsonSuccess(['message' => 'Subscription control action completed successfully.', 'data' => $data]);
    }

    jsonError('Method not allowed.', 405);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    jsonError($error->getMessage() ?: 'Unable to process platform subscription request.', $status);
}
