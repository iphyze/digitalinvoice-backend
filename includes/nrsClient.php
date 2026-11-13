<?php

declare(strict_types=1);

/**
 * NRS v1.1 sandbox transport. NO scheduled worker calls this class.
 * No production HTTP is implemented: licence and independent security review required.
 * The sandbox HTTP gate is checked on every call, not just in the UI.
 */
function nrsIrnFromIssuedInvoice(string $invoiceNumber, string $serviceId, string $issueDate): string
{
    if (!preg_match('/^[A-Za-z0-9]{1,60}$/D', $invoiceNumber)) {
        throw new InvalidArgumentException('NRS invoice number must contain only letters and digits.');
    }
    if (!preg_match('/^[A-Za-z0-9]{8}$/D', $serviceId)) {
        throw new InvalidArgumentException('An NRS-assigned, eight-character service ID is required.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $issueDate);
    if (!$date || $date->format('Y-m-d') !== $issueDate) {
        throw new InvalidArgumentException('A valid invoice issue date in YYYY-MM-DD format is required.');
    }
    return $invoiceNumber . '-' . $serviceId . '-' . $date->format('Ymd');
}

function nrsEndpoint(string $name, array $params = []): array
{
    $spec = [
        'validate' => ['POST', '/validate'],
        'irn_validate' => ['POST', '/irn/validate'],
        'sign' => ['POST', '/sign'],
        'confirm' => ['GET', '/confirm/{irn}'],
        'download' => ['GET', '/download/{irn}'],
        'update' => ['PATCH', '/update/{irn}'],
        'transmit_health' => ['GET', '/transmit/self-health-check'],
        'transmit_lookup' => ['GET', '/transmit/lookup/{irn}'],
        'transmit_lookup_tin' => ['GET', '/transmit/lookup/tin/{tin}'],
        'transmit' => ['POST', '/transmit/{irn}'],
        'transmit_ack' => ['PATCH', '/transmit/{irn}'],
        'transmit_pull' => ['GET', '/transmit/pull'],
    ];
    if (str_starts_with($name, 'resource_')) {
        $resources = [
            'invoice_types' => 'invoice-types',
            'payment_means' => 'payment_means', // current NRS v1.1 documentation
            'tax_categories' => 'tax-categories',
            'currencies' => 'currencies',
            'vat_exemptions' => 'vat-exemptions',
            'hs_codes' => 'hs-codes',
            'service_codes' => 'services-codes',
            'quantity_codes' => 'invoice-quantity-codes',
            'states' => 'states',
            'lgas' => 'lgas',
        ];
        $key = substr($name, 9);
        if (!isset($resources[$key])) throw new InvalidArgumentException('Unknown NRS resource.');
        return ['method' => 'GET', 'path' => '/resources/' . $resources[$key], 'name' => $name];
    }
    if (!isset($spec[$name])) throw new InvalidArgumentException('Unknown NRS operation.');
    [$method, $path] = $spec[$name];
    foreach (['irn', 'tin'] as $field) {
        if (!str_contains($path, '{' . $field . '}')) continue;
        $value = trim((string) ($params[$field] ?? ''));
        if ($field === 'irn' && !preg_match('/^[A-Za-z0-9]+-[A-Za-z0-9]{8}-[0-9]{8}$/D', $value)) {
            throw new InvalidArgumentException('Invalid NRS IRN.');
        }
        if ($field === 'tin' && !preg_match('/^[A-Za-z0-9-]{4,50}$/D', $value)) {
            throw new InvalidArgumentException('Invalid NRS TIN.');
        }
        $path = str_replace('{' . $field . '}', rawurlencode($value), $path);
    }
    return ['method' => $method, 'path' => $path, 'name' => $name];
}

function nrsSecretHeaderName(string $value): string
{
    $header = strtolower(trim($value));
    if (!in_array($header, ['x-api-secret', 'x-secret-key'], true)) {
        throw new InvalidArgumentException('Unsupported NRS secret header name.');
    }
    return $header;
}

/** A simple metadata-only audit record; neither invoice PII nor credentials leave this helper. */
function nrsAuditSummary(string $name, string $method, array $request, array $response, int $status): array
{
    return [
        'operation' => $name,
        'method' => $method,
        'request_digest' => hash('sha256', json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        'response_digest' => hash('sha256', json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        'status' => $status,
        'nrs_code' => is_scalar($response['code'] ?? null) ? substr((string) $response['code'], 0, 20) : null,
        'response_fields' => array_slice(array_keys($response), 0, 25),
    ];
}

/**
 * Provider-specific response fields are not assumed to be a cryptographic
 * proof; the adapter records them only when an authenticated NRS response is
 * independently confirmed in the enabled, audited production workflow.
 */
function nrsReadEvidence(array $response): array
{
    $body = is_array($response['data'] ?? null) ? $response['data'] : $response;
    return [
        'irn' => trim((string) ($body['irn'] ?? $body['IRN'] ?? '')),
        'csid' => trim((string) ($body['csid'] ?? $body['CSID'] ?? '')),
        'qr_payload' => trim((string) ($body['qr_code'] ?? $body['qr_payload'] ?? '')),
    ];
}

function nrsSandboxGate(array $settings, array $env): array
{
    if ((string) ($settings['firs_environment'] ?? '') !== 'sandbox') {
        throw new DomainException('NRS production requests are locked until licensing and review.', 403);
    }
    if ((string) ($settings['firs_integration_status'] ?? '') !== 'sandbox_ready'
        || (string) ($settings['firs_sandbox_enabled'] ?? '0') !== '1'
        || ($env['NRS_SANDBOX_HTTP_ENABLED'] ?? '') !== '1') {
        throw new DomainException('NRS sandbox HTTP is disabled.', 403);
    }
    $url = rtrim(trim((string) ($settings['firs_base_url'] ?? '')), '/');
    $allowed = rtrim(trim((string) ($env['NRS_SANDBOX_BASE_URL'] ?? '')), '/');
    if ($allowed === '' || !hash_equals($allowed, $url)) {
        throw new DomainException('Sandbox URL must match the administrator-approved server environment URL.', 403);
    }
    $parts = parse_url($url);
    if (!$parts || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || !empty($parts['user']) || !empty($parts['pass']) || !empty($parts['query']) || !empty($parts['fragment'])
        || ((int) ($parts['port'] ?? 443)) !== 443 || empty($parts['host'])) {
        throw new DomainException('Only approved HTTPS sandbox URLs on port 443 are permitted.', 403);
    }
    $host = strtolower((string) $parts['host']);
    if (filter_var($host, FILTER_VALIDATE_IP) || $host === 'localhost'
        || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/D', $host)
        || str_ends_with($host, '.local')) {
        throw new DomainException('Sandbox hostname is not permitted.', 403);
    }
    if (empty($settings['firs_api_key']) || empty($settings['firs_secret_key'])) {
        throw new DomainException('NRS sandbox API key and secret key are required.', 403);
    }
    return ['url' => $url, 'secret_header' => nrsSecretHeaderName((string) ($env['NRS_SECRET_HEADER'] ?? 'x-api-secret'))];
}

/**
 * Actual network function. Only callable after nrsSandboxGate succeeds.
 * Request + response payloads must never be directly written to firs_api_logs.
 */
function nrsSandboxCurl(array $gate, array $settings, array $operation, array $body = []): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL is required for sandbox testing.');
    $url = $gate['url'] . '/api/v1/invoice' . $operation['path'];
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'x-api-key: ' . $settings['firs_api_key'],
        $gate['secret_header'] . ': ' . $settings['firs_secret_key'],
    ];
    $method = $operation['method'];
    $handle = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROXY => '',
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_MAXFILESIZE => 2 * 1024 * 1024,
    ];
    if ($method !== 'GET') $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    curl_setopt_array($handle, $options);
    $raw = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = $raw === false ? curl_error($handle) : '';
    curl_close($handle);
    if ($raw === false) throw new RuntimeException('NRS sandbox network request failed (transport).');
    if (strlen($raw) > 2 * 1024 * 1024) throw new RuntimeException('NRS sandbox response exceeded size limit.');
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException('NRS sandbox response was not JSON.');
    return ['status' => $status, 'data' => $data];
}

function nrsSandboxRequest(array $settings, array $env, string $operation, array $params = [], array $body = [], ?callable $audit = null): array
{
    $gate = nrsSandboxGate($settings, $env);
    $endpoint = nrsEndpoint($operation, $params);
    // Never blindly retry sign, confirm, transmit, acknowledgement or payment status changes.
    $max = $endpoint['method'] === 'GET' ? 2 : 1;
    $last = null;
    for ($attempt = 0; $attempt < $max; $attempt++) {
        try {
            $result = nrsSandboxCurl($gate, $settings, $endpoint, $body);
            if ($audit) $audit(nrsAuditSummary($operation, $endpoint['method'], $body, $result['data'], $result['status']));
            $last = $result;
            if ($result['status'] < 500 || $attempt === $max - 1) return $result;
        } catch (Throwable $e) {
            if ($audit) $audit(['operation' => $operation, 'method' => $endpoint['method'], 'status' => 0, 'error_code' => 'transport_failure']);
            if ($attempt === $max - 1) throw $e;
        }
        usleep(200000 * ($attempt + 1));
    }
    return $last ?? ['status' => 0, 'data' => []];
}

/**
 * Offline-only exercise of the endpoint sequence, for unit tests and UAT fixtures.
 * Never persists evidence, issues credentials, or invokes nrsSandboxCurl().
 * All output is explicitly marked as mock and MUST NOT be sent to documents.
 */
function nrsMockValidateSignConfirm(array $ubl, string $irn, callable $fixtureTransport): array
{
    $steps = [];
    foreach ([
        ['name' => 'validate', 'body' => $ubl, 'params' => []],
        ['name' => 'sign', 'body' => $ubl, 'params' => []],
        ['name' => 'confirm', 'body' => [], 'params' => ['irn' => $irn]],
    ] as $step) {
        $spec = nrsEndpoint($step['name'], $step['params']);
        $response = $fixtureTransport($spec, $step['body']);
        if (!is_array($response) || (int) ($response['status'] ?? 0) < 200 || (int) ($response['status'] ?? 0) >= 300) {
            throw new RuntimeException('Offline mock ' . $step['name'] . ' failed.');
        }
        $steps[] = ['operation' => $step['name'], 'http_status' => (int) $response['status'], 'mock' => true];
    }
    return ['mode' => 'mock', 'official_nrs_evidence' => false, 'database_changes' => 0, 'steps' => $steps];
}
