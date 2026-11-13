<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/platformService.php';
require_once __DIR__ . '/../../includes/firsIntegrationService.php';
require_once __DIR__ . '/../../includes/nrsClient.php';

try {
    // POST only; stateful/authenticated with CSRF middleware from existing router.
    $admin = requirePlatformAdmin('platform.firs.manage');
    $current = firsFetchPlatformSettings($conn, true);
    $settings = $current['settings'];
    $env = [
        'NRS_SANDBOX_HTTP_ENABLED' => (string) (config('NRS_SANDBOX_HTTP_ENABLED') ?? ''),
        'NRS_SANDBOX_BASE_URL' => (string) (config('NRS_SANDBOX_BASE_URL') ?? ''),
        'NRS_SECRET_HEADER' => (string) (config('NRS_SECRET_HEADER') ?? 'x-api-secret'),
    ];
    $log = static function (array $summary) use ($conn): void {
        firsLogEvent($conn, null, null, 'resource_currencies', 'sandbox', 'GET', null,
            ['digest' => $summary['request_digest'] ?? null],
            ['status' => $summary['status'] ?? 0, 'response_digest' => $summary['response_digest'] ?? null, 'nrs_code' => $summary['nrs_code'] ?? null],
            (int) ($summary['status'] ?? 0),
            (($summary['status'] ?? 0) >= 200 && ($summary['status'] ?? 0) < 300) ? 'success' : 'failed',
            'Sandbox resources connectivity test; request/response bodies and credentials intentionally excluded.');
    };
    $result = nrsSandboxRequest($settings, $env, 'resource_currencies', [], [], $log);
    if ($result['status'] < 200 || $result['status'] >= 300) {
        jsonError('NRS sandbox connectivity test returned HTTP ' . $result['status'] . '.', 502);
    }
    jsonSuccess(['message' => 'NRS sandbox API authentication succeeded (resources test). This is not invoice clearance.',
        'data' => ['environment' => 'sandbox', 'http_status' => $result['status'], 'operation' => 'resource_currencies', 'invoice_submitted' => false]]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError($code >= 400 && $code < 500 ? $e->getMessage() : 'Unable to complete sandbox connectivity test.', $code >= 400 && $code < 500 ? $code : 502);
}
