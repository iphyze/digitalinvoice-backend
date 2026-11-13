<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/fieldEncryption.php';
require_once __DIR__ . '/erpOAuthService.php';

function erpWebhookEventMap(): array
{
    return [
        'customer.created' => ['customer.created', 'customer', 'customer_id'],
        'customer.updated' => ['customer.updated', 'customer', 'customer_id'],
        'invoice.draft_created' => ['invoice.created', 'invoice', 'invoice_id'],
        'invoice.draft_updated' => ['invoice.updated', 'invoice', 'invoice_id'],
        'invoice.approved' => ['invoice.approved', 'invoice', 'invoice_id'],
        'credit_note.approved' => ['credit_note.approved', 'credit_note', 'credit_note_id'],
        'payment.recorded' => ['payment.recorded', 'payment', 'payment_id'],
        'payment.voided' => ['payment.voided', 'payment', 'payment_id'],
    ];
}

function erpWebhookEvents(): array
{
    return array_values(array_unique(array_column(erpWebhookEventMap(), 0)));
}

function erpWebhookParseEvents(mixed $events): array
{
    if (!is_array($events) || !$events) throw new Exception('Select one or more webhook events.', 422);
    $list = array_values(array_unique($events));
    if (count($list) > count(erpWebhookEvents())) throw new Exception('Too many webhook events.', 422);
    foreach ($list as $event) {
        if (!is_string($event) || !in_array($event, erpWebhookEvents(), true)) {
            throw new Exception('Unsupported webhook event.', 422);
        }
    }
    sort($list, SORT_STRING);
    return $list;
}

/** Validate before save and again just before each outbound request. */
function erpWebhookValidateUrl(string $url): array
{
    if (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) {
        throw new Exception('Enter a valid public HTTPS webhook URL.', 422);
    }
    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || $host === '' || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['fragment']) || (isset($parts['port']) && (int) $parts['port'] !== 443)
        || filter_var($host, FILTER_VALIDATE_IP)
        || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host)
        || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
        throw new Exception('Webhooks require a public DNS hostname on HTTPS port 443.', 422);
    }
    return ['host' => $host, 'url' => $url];
}

function erpWebhookPublicIp(string $host): string
{
    // IPv4-only delivery intentionally blocks IPv6/DNS-rebinding fallbacks.
    $ips = gethostbynamel($host);
    if (!$ips) throw new RuntimeException('Webhook DNS resolution failed.');
    foreach ($ips as $ip) {
        if (!erpWebhookIsPublicIpv4($ip)) {
            throw new RuntimeException('Webhook hostname resolves to a non-public address.');
        }
    }
    return $ips[0];
}

/** Reject private, loopback, link-local, carrier NAT, documentation, benchmark and multicast ranges. */
function erpWebhookIsPublicIpv4(string $ip): bool
{
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    $number = ip2long($ip);
    if ($number === false) return false;
    $ranges = [
        ['100.64.0.0', 10], ['192.0.0.0', 24], ['192.0.2.0', 24],
        ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24],
        ['224.0.0.0', 4], ['240.0.0.0', 4],
    ];
    foreach ($ranges as [$start, $prefix]) {
        $mask = (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
        if (($number & $mask) === (ip2long($start) & $mask)) return false;
    }
    return true;
}

function erpWebhookSigningKey(int $endpointId): string
{
    return 'erp_webhook:' . $endpointId;
}

function erpWebhookSignature(string $secret, int $timestamp, string $body): string
{
    return 'v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
}

/** Called from auditLog within the same business transaction whenever possible. */
function erpWebhookQueueAudit(mysqli $conn, string $action, ?int $organizationId, ?string $metadata): void
{
    $map = erpWebhookEventMap();
    if (!$organizationId || !isset($map[$action])) return;
    [$eventType, $resourceType, $metadataKey] = $map[$action];
    $values = json_decode((string) ($metadata ?? '{}'), true);
    $id = (int) (($values[$metadataKey] ?? 0));
    if ($id < 1) return;
    $stmt = $conn->prepare('SELECT id, event_types FROM erp_webhook_endpoints WHERE organization_id=? AND is_active=1');
    $stmt->bind_param('i', $organizationId);
    $stmt->execute();
    $endpoints = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $selected = [];
    foreach ($endpoints as $endpoint) {
        $events = json_decode((string) $endpoint['event_types'], true);
        if (is_array($events) && in_array($eventType, $events, true)) $selected[] = (int) $endpoint['id'];
    }
    if (!$selected) return;
    $initial = '{}';
    $stmt = $conn->prepare('INSERT INTO erp_webhook_events (organization_id, event_type, resource_type, resource_id, payload_json) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('issis', $organizationId, $eventType, $resourceType, $id, $initial);
    $stmt->execute();
    $eventId = (int) $stmt->insert_id;
    $stmt->close();
    $payload = json_encode([
        'id' => 'evt_' . $eventId,
        'type' => $eventType,
        'api_version' => 'v1',
        'created_at' => gmdate('c'),
        'data' => ['object' => $resourceType, 'id' => $id],
        'note' => 'Fetch current resource state using a scoped ERP access token.',
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $stmt = $conn->prepare('UPDATE erp_webhook_events SET payload_json=? WHERE id=?');
    $stmt->bind_param('si', $payload, $eventId);
    $stmt->execute();
    $stmt->close();
    $stmt = $conn->prepare('INSERT INTO erp_webhook_deliveries (event_id, endpoint_id) VALUES (?, ?)');
    foreach ($selected as $endpointId) {
        $stmt->bind_param('ii', $eventId, $endpointId);
        $stmt->execute();
    }
    $stmt->close();
}

/** Delivery-time check: subscriptions can be edited after an event was queued. */
function erpWebhookDeliveryCancelled(?array $delivery): bool
{
    if (!$delivery || (int) ($delivery['is_active'] ?? 0) !== 1) return true;
    $events = json_decode((string) ($delivery['event_types'] ?? ''), true);
    return !is_array($events) || !in_array((string) ($delivery['event_type'] ?? ''), $events, true);
}

/** Network sender: strict HTTPS validation + pinned public DNS result, no redirects, no cookies. */
function erpWebhookHttpSend(string $url, string $body, string $eventType, int $deliveryId, string $secret): array
{
    $parts = erpWebhookValidateUrl($url);
    $host = $parts['host'];
    $ip = erpWebhookPublicIp($host);
    $timestamp = time();
    $headers = [
        'Content-Type: application/json',
        'User-Agent: DigitalInvoice-Webhooks/1.0',
        'X-DigitalInvoice-Event: ' . $eventType,
        'X-DigitalInvoice-Delivery: ' . $deliveryId,
        'X-DigitalInvoice-Timestamp: ' . $timestamp,
        'X-DigitalInvoice-Signature: ' . erpWebhookSignature($secret, $timestamp, $body),
    ];
    $handle = curl_init($url);
    if ($handle === false) throw new RuntimeException('Unable to initialize webhook transport.');
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION => static fn ($handle, string $chunk): int => strlen($chunk),
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
        CURLOPT_RESOLVE => [$host . ':443:' . $ip],
        CURLOPT_USERAGENT => 'DigitalInvoice-Webhooks/1.0',
        CURLOPT_MAXFILESIZE => 1024 * 128,
    ]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $curlError = $response === false ? (string) curl_error($handle) : '';
    curl_close($handle);
    return ['status' => $status, 'ok' => $response !== false && $status >= 200 && $status < 300,
        'error' => $curlError !== '' ? 'Transport failed' : ($status >= 200 && $status < 300 ? null : 'HTTP ' . $status)];
}

function erpWebhookProcessBatch(mysqli $conn, int $limit = 25): array
{
    $limit = max(1, min(100, $limit));
    $processed = 0;
    $delivered = 0;
    for ($i = 0; $i < $limit; $i++) {
        $conn->begin_transaction();
        try {
            $result = $conn->query(
                "SELECT d.id, d.event_id, d.endpoint_id, d.attempt_count FROM erp_webhook_deliveries d "
                . "WHERE (d.status='pending' AND d.next_attempt_at <= NOW()) "
                . "OR (d.status='processing' AND d.locked_at < NOW() - INTERVAL 10 MINUTE) "
                . 'ORDER BY d.id ASC LIMIT 1 FOR UPDATE SKIP LOCKED'
            );
            $row = $result->fetch_assoc();
            $result->free();
            if (!$row) {
                $conn->commit();
                break;
            }
            $deliveryId = (int) $row['id'];
            $claim = $conn->prepare("UPDATE erp_webhook_deliveries SET status='processing', locked_at=NOW(), attempt_count=attempt_count+1 WHERE id=?");
            $claim->bind_param('i', $deliveryId);
            $claim->execute();
            $claim->close();
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }
        $processed++;
        $query = $conn->prepare(
            'SELECT d.attempt_count, e.event_type, e.payload_json, e.organization_id, '
            . 'h.is_active, h.event_types, h.destination_url, h.signing_secret_encrypted '
            . 'FROM erp_webhook_deliveries d JOIN erp_webhook_events e ON e.id=d.event_id '
            . 'JOIN erp_webhook_endpoints h ON h.id=d.endpoint_id AND h.organization_id=e.organization_id WHERE d.id=? LIMIT 1'
        );
        $query->bind_param('i', $deliveryId);
        $query->execute();
        $info = $query->get_result()->fetch_assoc();
        $query->close();
        $httpStatus = null;
        $errorText = null;
        $success = false;
        $cancelled = erpWebhookDeliveryCancelled($info)
            || !erpOrganizationEnabled($conn, (int) ($info['organization_id'] ?? 0));
        if (!$cancelled) {
            try {
                $secret = decryptSensitiveSetting(erpWebhookSigningKey((int) $row['endpoint_id']), (string) $info['signing_secret_encrypted']);
                $result = erpWebhookHttpSend((string) $info['destination_url'], (string) $info['payload_json'], (string) $info['event_type'], $deliveryId, $secret);
                $httpStatus = (int) $result['status'];
                $success = $result['ok'];
                $errorText = $result['error'];
            } catch (Throwable $error) {
                $errorText = 'Webhook destination or transport validation failed';
                error_log('ERP webhook delivery failed validation, id=' . $deliveryId . ': ' . get_class($error));
            }
        }
        $attempts = (int) ($info['attempt_count'] ?? ($row['attempt_count'] + 1));
        $terminal = $attempts >= 8 || ($httpStatus !== null && $httpStatus >= 400 && $httpStatus < 500 && $httpStatus !== 408 && $httpStatus !== 429);
        $status = $cancelled ? 'cancelled' : ($success ? 'delivered' : ($terminal ? 'failed' : 'pending'));
        $backoff = min(21600, 60 * (2 ** min($attempts - 1, 8)));
        $next = date('Y-m-d H:i:s', time() + $backoff);
        $update = $conn->prepare('UPDATE erp_webhook_deliveries SET status=?, next_attempt_at=?, locked_at=NULL, delivered_at=IF(?=\'delivered\',NOW(),delivered_at), last_http_status=?, last_error=? WHERE id=? AND status=\'processing\'');
        $update->bind_param('sssisi', $status, $next, $status, $httpStatus, $errorText, $deliveryId);
        $update->execute();
        $update->close();
        if ($success) $delivered++;
    }
    return ['processed' => $processed, 'delivered' => $delivered];
}
