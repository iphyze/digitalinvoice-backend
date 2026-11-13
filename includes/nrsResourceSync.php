<?php

declare(strict_types=1);

/**
 * NRS v1.1 reference-data import. All external calls are explicitly sandbox-
 * gated by nrsSandboxRequest(); no user-supplied URL or endpoint is accepted.
 * Only the response from the authenticated transport can set status=synced.
 */
require_once __DIR__ . '/nrsClient.php';

function nrsSyncableResourceKeys(): array
{
    return [
        'invoice_types', 'payment_means', 'tax_categories', 'currencies',
        'hs_codes', 'service_codes', 'quantity_codes', 'states', 'lgas',
    ];
}

function nrsResourceTruncate(string $value, int $maxChars): string
{
    if (function_exists('mb_substr')) return mb_substr($value, 0, $maxChars, 'UTF-8');
    if (!preg_match('/\A.{0,' . $maxChars . '}/us', $value, $match)) {
        throw new RuntimeException('NRS resource response contains invalid UTF-8 text.');
    }
    return $match[0];
}

/**
 * Strictly consume an NRS resource response; never accept manually entered
 * entries as proof of having retrieved them from the provider.
 */
function nrsParseResourceResponse(array $response, string $key): array
{
    if (!in_array($key, nrsSyncableResourceKeys(), true)) {
        throw new InvalidArgumentException('This resource is not supported for NRS synchronisation.', 422);
    }
    $status = (int) ($response['status'] ?? 0);
    $body = $response['data'] ?? null;
    if ($status !== 200 || !is_array($body)) {
        throw new RuntimeException('NRS resource request did not succeed. Previous catalogue retained.');
    }
    // The API returns {code:200, data:[...]}; bare lists are accepted for
    // provider resources that document a top-level array (e.g. payment means).
    if (array_is_list($body)) {
        $rows = $body;
    } else {
        if (!array_key_exists('code', $body) || (string) $body['code'] !== '200'
            || !isset($body['data']) || !is_array($body['data']) || !array_is_list($body['data'])) {
            throw new RuntimeException('NRS resource response envelope was not recognised. Previous catalogue retained.');
        }
        $rows = $body['data'];
    }
    if ($rows === [] || count($rows) > 20000) {
        throw new RuntimeException('NRS returned an empty or oversized resource catalogue. Previous catalogue retained.');
    }
    $entries = [];
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            throw new RuntimeException('NRS resource entry has an unexpected structure at row ' . ($index + 1) . '.');
        }
        $value = $row['code'] ?? $row['id'] ?? null;
        if (!is_string($value) && !is_int($value)) {
            throw new RuntimeException('NRS resource entry is missing its code.');
        }
        // Preserve leading zeros and supplier-provided code case for the
        // official response. Uppercase is safe for NRS alphanumeric codes.
        $code = strtoupper(trim((string) $value));
        if ($code === '' || strlen($code) > 100 || !preg_match('/^[A-Z0-9_.\/-]+$/D', $code)) {
            throw new RuntimeException('NRS resource response includes an invalid code.');
        }
        if (isset($entries[$code])) {
            throw new RuntimeException('NRS resource response contains duplicate codes.');
        }
        $label = trim((string) ($row['name'] ?? $row['value'] ?? $row['label'] ?? $row['description'] ?? $code));
        $description = trim((string) ($row['description'] ?? ''));
        $entries[$code] = [
            'code' => $code,
            'value' => $code,
            'label' => nrsResourceTruncate($label !== '' ? $label : $code, 240),
            'description' => $description !== '' ? nrsResourceTruncate($description, 500) : null,
            'active' => true,
        ];
    }
    return array_values($entries);
}

/** Update one cache atomically; preserve previous values on failure. */
function nrsStoreSyncedResources(mysqli $conn, string $key, array $entries, int $actorId): void
{
    if (!in_array($key, nrsSyncableResourceKeys(), true) || !$entries || $actorId < 1) {
        throw new InvalidArgumentException('Invalid NRS import request.', 422);
    }
    require_once __DIR__ . '/firsIntegrationService.php';
    $definitions = firsDefaultResourceCatalogue();
    $definition = $definitions[$key] ?? null;
    if (!$definition) throw new RuntimeException('NRS resource definition is missing.');
    $json = json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $stmt = $conn->prepare(
        "INSERT INTO firs_resource_cache (resource_key, resource_label, endpoint_path, description, status, payload, synced_at, updated_by_user_id) "
        . "VALUES (?, ?, ?, ?, 'synced', ?, NOW(), ?) ON DUPLICATE KEY UPDATE "
        . "resource_label=VALUES(resource_label), endpoint_path=VALUES(endpoint_path), description=VALUES(description), "
        . "status='synced', payload=VALUES(payload), synced_at=NOW(), updated_by_user_id=VALUES(updated_by_user_id), updated_at=NOW()"
    );
    $stmt->bind_param('sssssi', $key, $definition['label'], $definition['endpoint'], $definition['description'], $json, $actorId);
    $stmt->execute();
    $stmt->close();
}

/**
 * Only invoked by platform-admin controller. No credential, invoice or full
 * resource response payload is stored in the API audit log.
 */
function nrsSyncSandboxResource(mysqli $conn, int $actorId, string $key, array $settings, array $env): array
{
    if (!in_array($key, nrsSyncableResourceKeys(), true)) {
        throw new InvalidArgumentException('Unsupported NRS resource.', 422);
    }
    // Authentication, approved HTTPS origin, env kill switch are checked here
    // before any outbound connection is attempted.
    nrsSandboxGate($settings, $env);
    try {
        $result = nrsSandboxRequest($settings, $env, 'resource_' . $key);
        $entries = nrsParseResourceResponse($result, $key);
    } catch (Throwable $error) {
        // Do not copy provider response bodies, secrets, URLs or user data to
        // diagnostics. Rejections never replace a previously synced list.
        require_once __DIR__ . '/firsIntegrationService.php';
        firsLogEvent($conn, null, null, 'resource_' . $key, 'sandbox', 'GET', null,
            ['resource_key' => $key], ['response_accepted' => false], null,
            'failed', 'NRS sandbox resource synchronisation failed; prior cache preserved.');
        throw $error;
    }
    $catalogueDigest = hash('sha256', json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $conn->begin_transaction();
    try {
        nrsStoreSyncedResources($conn, $key, $entries, $actorId);
        require_once __DIR__ . '/firsIntegrationService.php';
        auditLog($conn, $actorId, 'platform.nrs_resource_synced', 'NRS sandbox resource synchronised.',
            platformWorkspaceOrganizationId($conn), json_encode(['resource_key' => $key, 'entry_count' => count($entries), 'catalogue_sha256' => $catalogueDigest]));
        firsLogEvent($conn, null, null, 'resource_' . $key, 'sandbox', 'GET', null,
            ['resource_key' => $key], ['entry_count' => count($entries), 'catalogue_sha256' => $catalogueDigest], (int) $result['status'],
            'success', 'Authenticated NRS sandbox reference-data response; no invoice submitted.');
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    return ['resource_key' => $key, 'entry_count' => count($entries), 'source' => 'authenticated_nrs_sandbox',
        'synced' => true, 'invoice_submitted' => false];
}
