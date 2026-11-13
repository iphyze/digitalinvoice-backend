<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/platformService.php';
require_once __DIR__ . '/../../includes/firsIntegrationService.php';
require_once __DIR__ . '/../../includes/nrsResourceSync.php';

try {
    // The router's existing authenticated POST+CSRF checks apply here too.
    $actor = requirePlatformAdmin('platform.firs.manage');
    $input = readJsonBody();
    $key = trim((string) ($input['resource_key'] ?? ''));
    if (!in_array($key, nrsSyncableResourceKeys(), true)) {
        jsonError('Select a supported NRS resource.', 422);
    }
    $settings = firsFetchPlatformSettings($conn, true)['settings'];
    $env = [
        'NRS_SANDBOX_HTTP_ENABLED' => (string) (config('NRS_SANDBOX_HTTP_ENABLED') ?? ''),
        'NRS_SANDBOX_BASE_URL' => (string) (config('NRS_SANDBOX_BASE_URL') ?? ''),
        'NRS_SECRET_HEADER' => (string) (config('NRS_SECRET_HEADER') ?? 'x-api-secret'),
    ];
    $result = nrsSyncSandboxResource($conn, (int) $actor['id'], $key, $settings, $env);
    jsonSuccess(['message' => 'NRS sandbox resource synchronised.', 'data' => [
        'result' => $result, 'overview' => firsPlatformOverview($conn),
    ]]);
} catch (Throwable $error) {
    $status = (int) $error->getCode();
    if ($status < 400 || $status >= 500) $status = 502;
    jsonError($status === 502 ? 'NRS sandbox synchronisation failed. Existing resources were preserved.' : $error->getMessage(), $status);
}
