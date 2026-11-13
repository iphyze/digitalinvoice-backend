<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/firsIntegrationService.php';

try {
    $user = requirePermission('catalogue.view', true);
    $kind = strtolower(trim((string) ($_GET['kind'] ?? '')));
    if (!in_array($kind, ['goods','service'], true)) throw new Exception('Select goods or service.', 422);
    $key = $kind === 'goods' ? 'hs_codes' : 'service_codes';
    firsUpsertDefaultResources($conn);
    $stmt = $conn->prepare('SELECT payload, status, synced_at FROM firs_resource_cache WHERE resource_key = ? LIMIT 1');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $entries = firsNormaliseResourceEntries(json_decode((string) ($record['payload'] ?? '[]'), true) ?: []);
    $verified = ($record['status'] ?? '') === 'synced';
    $options = array_map(static fn(array $e): array => ['code' => $e['code'], 'label' => $e['label'], 'verified' => $verified], array_values(array_filter($entries, static fn(array $e): bool => (bool) ($e['active'] ?? true))));
    jsonSuccess(['data' => ['kind' => $kind, 'source' => (string) ($record['status'] ?? 'pending_license'), 'verified' => $verified, 'options' => $options, 'notice' => $verified ? 'Values imported from the NRS resource catalogue; independently verify currency before live use.' : 'Only local draft/manual values are available. No verified NRS codes have been imported.']]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError($code >= 400 && $code <= 599 ? $e->getMessage() : 'Unable to load classification codes.', $code >= 400 && $code <= 599 ? $code : 500);
}
