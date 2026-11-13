<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/erpWebhookService.php';

try {
    $actor = requireOperationalWorkspaceAccess();
    if (!in_array((string) ($actor['role'] ?? ''), ['owner', 'admin'], true)) {
        throw new Exception('Only organisation owners and administrators can manage webhooks.', 403);
    }
    $org = (int) $actor['organization_id'];
    $userId = (int) $actor['id'];
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        $stmt = $conn->prepare('SELECT id, name, destination_url, event_types, is_active, created_at, updated_at FROM erp_webhook_endpoints WHERE organization_id=? ORDER BY id DESC LIMIT 100');
        $stmt->bind_param('i', $org);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as &$row) {
            $row['events'] = json_decode((string) $row['event_types'], true) ?: [];
            unset($row['event_types']);
        }
        unset($row);
        jsonSuccess(['data' => ['endpoints' => $rows, 'supported_events' => erpWebhookEvents()]]);
    }
    $data = readJsonBody();
    if ($method === 'POST') {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) throw new Exception('Webhook name must contain 1 to 120 characters.', 422);
        $url = trim((string) ($data['url'] ?? ''));
        $parsed = erpWebhookValidateUrl($url);
        erpWebhookPublicIp($parsed['host']);
        $events = json_encode(erpWebhookParseEvents($data['events'] ?? []), JSON_THROW_ON_ERROR);
        $secret = 'whsec_' . randomUrlSafeToken(48);
        $conn->begin_transaction();
        try {
            $placeholder = 'pending';
            $stmt = $conn->prepare('INSERT INTO erp_webhook_endpoints (organization_id, name, destination_url, event_types, signing_secret_encrypted, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('issssi', $org, $name, $url, $events, $placeholder, $userId);
            $stmt->execute();
            $id = (int) $stmt->insert_id;
            $stmt->close();
            $encrypted = encryptSensitiveSetting(erpWebhookSigningKey($id), $secret);
            $stmt = $conn->prepare('UPDATE erp_webhook_endpoints SET signing_secret_encrypted=? WHERE id=? AND organization_id=?');
            $stmt->bind_param('sii', $encrypted, $id, $org);
            $stmt->execute();
            $stmt->close();
            auditLog($conn, $userId, 'erp.webhook_created', 'ERP webhook endpoint created.', $org, json_encode(['endpoint_id' => $id]));
            $conn->commit();
        } catch (Throwable $error) { $conn->rollback(); throw $error; }
        jsonSuccess(['message' => 'Save this signing secret now; it cannot be retrieved later.', 'data' => ['id' => $id, 'signing_secret' => $secret]], 201);
    }
    if ($method !== 'PUT') throw new Exception('Method not supported.', 405);
    $id = (int) ($data['id'] ?? 0);
    $action = trim((string) ($data['action'] ?? ''));
    if ($id < 1 || !in_array($action, ['update', 'disable', 'rotate_secret'], true)) throw new Exception('Valid endpoint ID and action required.', 422);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT id, is_active, destination_url, event_types FROM erp_webhook_endpoints WHERE id=? AND organization_id=? FOR UPDATE');
        $stmt->bind_param('ii', $id, $org);
        $stmt->execute();
        $endpoint = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$endpoint || (int) $endpoint['is_active'] !== 1) throw new Exception('Active webhook endpoint not found.', 404);
        $secret = null;
        $cancelQueued = false;
        if ($action === 'disable') {
            $stmt = $conn->prepare('UPDATE erp_webhook_endpoints SET is_active=0 WHERE id=? AND organization_id=?');
            $stmt->bind_param('ii', $id, $org);
        } elseif ($action === 'rotate_secret') {
            $secret = 'whsec_' . randomUrlSafeToken(48);
            $encrypted = encryptSensitiveSetting(erpWebhookSigningKey($id), $secret);
            $stmt = $conn->prepare('UPDATE erp_webhook_endpoints SET signing_secret_encrypted=? WHERE id=? AND organization_id=?');
            $stmt->bind_param('sii', $encrypted, $id, $org);
        } else {
            $url = trim((string) ($data['url'] ?? ''));
            $parsed = erpWebhookValidateUrl($url);
            erpWebhookPublicIp($parsed['host']);
            $events = json_encode(erpWebhookParseEvents($data['events'] ?? []), JSON_THROW_ON_ERROR);
            // Queued events were created for the OLD destination/subscription.
            // Never send their payload to a newly configured destination.
            $cancelQueued = $url !== (string) $endpoint['destination_url']
                || $events !== (string) $endpoint['event_types'];
            $stmt = $conn->prepare('UPDATE erp_webhook_endpoints SET destination_url=?, event_types=? WHERE id=? AND organization_id=?');
            $stmt->bind_param('ssii', $url, $events, $id, $org);
        }
        $stmt->execute();
        $stmt->close();
        if ($action === 'disable' || $cancelQueued) {
            // Pending jobs belong to the previous subscription. Jobs already in
            // flight can still complete; receivers must deduplicate deliveries.
            $sql = $action === 'disable'
                ? "UPDATE erp_webhook_deliveries SET status='cancelled' WHERE endpoint_id=? AND status IN ('pending','processing')"
                : "UPDATE erp_webhook_deliveries SET status='cancelled' WHERE endpoint_id=? AND status='pending'";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        }
        auditLog($conn, $userId, 'erp.webhook_' . $action, 'ERP webhook endpoint updated.', $org, json_encode(['endpoint_id' => $id]));
        $conn->commit();
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
    jsonSuccess(['message' => 'Webhook endpoint updated.', 'data' => array_filter(['id' => $id, 'signing_secret' => $secret])]);
} catch (Throwable $error) {
    $code = (int) $error->getCode();
    jsonError($code >= 400 && $code <= 599 ? $error->getMessage() : 'Unable to manage ERP webhooks.', $code >= 400 && $code <= 599 ? $code : 500);
}
