<?php

declare(strict_types=1);

require_once __DIR__ . '/nrsClient.php';
require_once __DIR__ . '/nrsCompliancePreflight.php';
require_once __DIR__ . '/firsUblAdapter.php';

/**
 * Construct a sandbox /validate request from an approved invoice's frozen
 * snapshot. Never generate a different issued invoice number silently: NRS's
 * IRN forbids punctuation, while DigitalInvoice may issue e.g. INV-000001.
 * Such invoices need an explicitly designed, collision-safe alias migration.
 */
function nrsPrepareSandboxValidation(array $local, array $resources): array
{
    if (($local['status'] ?? '') !== 'approved') {
        throw new DomainException('Only an approved invoice may be tested against NRS sandbox.', 422);
    }
    if (!in_array((string) ($local['firs_metadata']['transaction_type'] ?? ''), ['b2b', 'b2c'], true)) {
        throw new DomainException('Only the implemented B2B/B2C invoice models may be tested.', 422);
    }
    $irn = nrsIrnFromIssuedInvoice(
        (string) ($local['invoice_number'] ?? ''),
        (string) ($local['nrs_service_id'] ?? ''),
        (string) ($local['issue_date'] ?? '')
    );
    $ubl = firsUblPayload($local, [
        'business_id' => $local['nrs_business_id'] ?? null,
        'irn' => $irn,
    ]);
    $checks = firsUblValidate($ubl, $local);
    if (!$checks['schema_valid']) {
        throw new DomainException('Local NRS UBL checks failed. Correct the invoice/organisation fields before sandbox validation.', 422);
    }
    $codes = nrsOfficialCodePreflight($ubl, $resources);
    if (!$codes['passed']) {
        throw new DomainException('Synchronise official NRS resource catalogues and correct unmapped codes before sandbox validation.', 422);
    }
    return ['irn' => $irn, 'ubl' => $ubl];
}

/** An authenticated HTTP 200 is not the same as an invoice cleared by NRS. */
function nrsSandboxValidationResult(array $result, string $expectedIrn): array
{
    $body = $result['data'] ?? null;
    $status = (int) ($result['status'] ?? 0);
    $code = is_array($body) ? ($body['code'] ?? null) : null;
    $successful = $status === 200 && (string) $code === '200'
        && empty($body['errors']) && (($body['success'] ?? true) !== false)
        && (($body['data']['valid'] ?? true) !== false)
        && empty($body['data']['errors']);
    $issuedIrn = null;
    if ($successful && is_array($body['data'] ?? null)) {
        $candidate = $body['data']['irn'] ?? $body['data']['IRN'] ?? null;
        if ($candidate !== null) $issuedIrn = trim((string) $candidate);
    }
    if ($issuedIrn !== null && !hash_equals($expectedIrn, $issuedIrn)) $successful = false;
    return [
        'environment' => 'sandbox',
        'operation' => 'validate',
        'http_status' => $status,
        'nrs_code' => is_scalar($code) ? (string) $code : null,
        'validation_response_ok' => $successful,
        'invoice_submitted' => false,
        'invoice_cleared' => false,
        'production_evidence' => false,
        'message' => $successful ? 'NRS sandbox responded successfully to validation; this is not signing, clearance or production submission.' : 'NRS sandbox validation was unsuccessful or had an inconsistent IRN.',
    ];
}
