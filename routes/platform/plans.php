<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/platformService.php';

try {
    global $conn;

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        requirePlatformAdmin('platform.plans.view');

        $filters = [
            'search' => $_GET['search'] ?? '',
            'status' => $_GET['status'] ?? '',
            'billing_interval' => $_GET['billing_interval'] ?? '',
            'page' => $_GET['page'] ?? 1,
            'limit' => $_GET['limit'] ?? 10,
        ];

        jsonSuccess(['message' => 'Platform plans retrieved successfully.', 'data' => fetchPlatformPlans($conn, $filters)]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $actor = requirePlatformAdmin('platform.plans.manage');
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            throw new Exception('Invalid request format. Expected JSON object.', 400);
        }

        jsonSuccess(['message' => 'Subscription plan created successfully.', 'data' => createPlatformPlan($conn, $actor, $payload)], 201);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        $actor = requirePlatformAdmin('platform.plans.manage');
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            throw new Exception('Invalid request format. Expected JSON object.', 400);
        }

        $planId = (int) ($payload['id'] ?? $_GET['id'] ?? 0);
        unset($payload['id']);

        jsonSuccess(['message' => 'Subscription plan updated successfully.', 'data' => updatePlatformPlan($conn, $actor, $planId, $payload)]);
    }

    jsonError('Method not allowed.', 405);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    jsonError($error->getMessage() ?: 'Unable to process platform plans request.', $status);
}
