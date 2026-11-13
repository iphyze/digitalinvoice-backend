<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/firsIntegrationService.php';
require_once __DIR__ . '/../../includes/nrsSandboxValidation.php';

try {
    $user = requirePermission('invoices.approve', true);
    $payload = readJsonBody();
    $invoiceId = (int) ($payload['invoice_id'] ?? 0);
    if ($invoiceId < 1) throw new DomainException('An approved invoice ID is required.', 422);
    $orgId = (int) $user['organization_id'];
    $local = firsLocalInvoicePayload($conn, $orgId, $invoiceId);
    $prepared = nrsPrepareSandboxValidation($local, firsFetchResourceCatalogue($conn));
    $settings = firsFetchPlatformSettings($conn, true)['settings'];
    $env = [
        'NRS_SANDBOX_HTTP_ENABLED' => (string) (config('NRS_SANDBOX_HTTP_ENABLED') ?? ''),
        'NRS_SANDBOX_BASE_URL' => (string) (config('NRS_SANDBOX_BASE_URL') ?? ''),
        'NRS_SECRET_HEADER' => (string) (config('NRS_SECRET_HEADER') ?? 'x-api-secret'),
    ];
    // Fail before HTTP if sandbox is disabled, not approved, or host mismatched.
    nrsSandboxGate($settings, $env);
    $audit = static function (array $summary) use ($conn, $orgId, $invoiceId): void {
        // No PII, full invoice JSON or credential material is saved here.
        $http = (int) ($summary['status'] ?? 0);
        $ok = $http === 200 && (string) ($summary['nrs_code'] ?? '') === '200';
        firsLogEvent($conn, $orgId, $invoiceId, 'sandbox_validate', 'sandbox', 'POST', null,
            ['digest' => $summary['request_digest'] ?? null],
            ['http_status' => $http, 'response_digest' => $summary['response_digest'] ?? null,
                'nrs_code' => $summary['nrs_code'] ?? null],
            $http, $ok ? 'success' : 'failed', 'Authenticated sandbox validation only; no signing or submission.');
    };
    $response = nrsSandboxRequest($settings, $env, 'validate', [], $prepared['ubl'], $audit);
    $result = nrsSandboxValidationResult($response, $prepared['irn']);
    jsonSuccess(['message' => $result['message'], 'data' => $result]);
} catch (Throwable $error) {
    $code = (int) $error->getCode();
    if ($code < 400 || $code >= 500) $code = 502;
    jsonError($code === 502 ? 'NRS sandbox validation was unavailable or unsuccessful.' : $error->getMessage(), $code);
}
