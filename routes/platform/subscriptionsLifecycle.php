<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/platformService.php';
require_once __DIR__ . '/../../includes/subscriptionService.php';
require_once __DIR__ . '/../../includes/subscriptionNotificationService.php';

try {
    global $conn;

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET') {
        requirePlatformAdmin('platform.subscriptions.view');
        jsonSuccess([
            'message' => 'Subscription lifecycle diagnostics retrieved successfully.',
            'data' => subscriptionLifecycleSummary($conn),
        ]);
    }

    if ($method === 'POST') {
        $actor = requirePlatformAdmin('platform.subscriptions.manage');
        $data = readJsonBody();
        $action = strtolower(trim((string) ($data['action'] ?? 'sync_expired')));
        if (!in_array($action, ['sync_expired', 'run_expiry_sync', 'send_notifications', 'send_reminders', 'sync_and_notify'], true)) {
            throw new Exception('This lifecycle action is not supported yet.', 422);
        }

        $result = ['expired_count' => 0, 'organization_count' => 0, 'subscription_ids' => []];
        $notificationResult = null;

        if (in_array($action, ['sync_expired', 'run_expiry_sync', 'sync_and_notify'], true)) {
            $conn->begin_transaction();
            try {
                $result = syncExpiredSubscriptions($conn, null, (int) $actor['id'], true);
                auditLog(
                    $conn,
                    (int) $actor['id'],
                    'platform.subscription_lifecycle_synced',
                    'Platform subscription lifecycle expiry sync was run from the owner console.',
                    null,
                    json_encode($result, JSON_UNESCAPED_SLASHES)
                );
                $conn->commit();
            } catch (Throwable $error) {
                $conn->rollback();
                throw $error;
            }
        }

        if (in_array($action, ['send_notifications', 'send_reminders', 'sync_and_notify'], true)) {
            $notificationResult = runSubscriptionNotificationSweep($conn, (int) $actor['id']);
        }

        $message = match ($action) {
            'send_notifications', 'send_reminders' => ($notificationResult['sent'] ?? 0) > 0
                ? 'Subscription notifications were processed and eligible reminders were sent.'
                : 'Subscription notifications were processed. No new reminder needed sending.',
            'sync_and_notify' => 'Lifecycle sync and subscription notification sweep completed.',
            default => ($result['expired_count'] ?? 0) > 0
                ? 'Subscription lifecycle sync completed and expired workspaces were updated.'
                : 'Subscription lifecycle sync completed. No expired workspace needed updating.',
        };

        jsonSuccess([
            'message' => $message,
            'data' => [
                'sync' => $result,
                'notifications' => $notificationResult,
                'lifecycle' => subscriptionLifecycleSummary($conn),
            ],
        ]);
    }

    jsonError('Method not allowed.', 405);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }

    jsonError($error->getMessage() ?: 'Unable to process subscription lifecycle request.', $status);
}
