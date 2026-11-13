<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';

try {
    $user = requireOperationalWorkspaceAccess();
    if (!in_array((string) $user['role'], ['owner', 'admin'], true)) throw new Exception('Not permitted to view ERP webhook deliveries.', 403);
    $org = (int) $user['organization_id'];
    $stmt = $conn->prepare('SELECT d.id, d.event_id, d.endpoint_id, d.status, d.attempt_count, d.next_attempt_at, d.delivered_at, d.last_http_status, d.last_error, d.created_at, e.event_type '
        . 'FROM erp_webhook_deliveries d JOIN erp_webhook_events e ON e.id=d.event_id WHERE e.organization_id=? ORDER BY d.id DESC LIMIT 100');
    $stmt->bind_param('i', $org);
    $stmt->execute();
    $deliveries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    jsonSuccess(['data' => ['deliveries' => $deliveries, 'limit' => 100]]);
} catch (Throwable $error) {
    $code = (int) $error->getCode();
    jsonError($code >= 400 && $code <= 599 ? $error->getMessage() : 'Unable to load webhook deliveries.', $code >= 400 && $code <= 599 ? $code : 500);
}
