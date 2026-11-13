<?php

declare(strict_types=1);

/**
 * One-sided read adapter for DigitalInvoice document/PDF renderers.
 * The sandbox and mock data are NEVER presented as a verified NRS credential.
 * Do not add a client-write route for this table.
 */
function nrsDocumentEvidence(mysqli $conn, int $orgId, string $type, int $documentId): array
{
    if (!in_array($type, ['invoice','credit_note','payment_receipt'], true) || $orgId < 1 || $documentId < 1) {
        throw new InvalidArgumentException('Invalid NRS evidence query.');
    }
    $statement = $conn->prepare(
        'SELECT irn, csid, qr_payload, evidence_environment, verification_status, verified_at '
        . 'FROM nrs_document_evidence WHERE organization_id = ? AND document_type = ? AND document_id = ? LIMIT 1'
    );
    $statement->bind_param('isi', $orgId, $type, $documentId);
    $statement->execute();
    $entry = $statement->get_result()->fetch_assoc();
    $statement->close();
    if (!$entry || $entry['verification_status'] !== 'confirmed' || $entry['evidence_environment'] !== 'production' || !$entry['verified_at']
        || !$entry['irn'] || !$entry['csid'] || !$entry['qr_payload']) {
        return ['verified' => false, 'message' => 'Not yet submitted to NRS'];
    }
    return [
        'verified' => true,
        'environment' => 'production',
        'irn' => (string) $entry['irn'],
        'csid' => (string) $entry['csid'],
        'qr_payload' => (string) $entry['qr_payload'],
        'verified_at' => $entry['verified_at'],
    ];
}

/**
 * Save a sandbox-only signing result obtained by the authenticated NRS client.
 * Intentionally cannot create a production-verification record. No public route
 * exposes this helper; future sandbox orchestrator may call it only after
 * genuine NRS sign + confirm responses have been checked.
 */
function nrsSaveSandboxDocumentEvidence(
    mysqli $conn,
    int $organizationId,
    string $documentType,
    int $documentId,
    array $result,
    string $operation = 'sign'
): bool {
    if (!in_array($documentType, ['invoice', 'credit_note', 'payment_receipt'], true)
        || $organizationId < 1 || $documentId < 1 || !in_array($operation, ['sign', 'confirm'], true)) {
        throw new InvalidArgumentException('Invalid sandbox evidence identity.');
    }
    $body = (array) ($result['data'] ?? []);
    if (($result['status'] ?? 0) < 200 || ($result['status'] ?? 0) >= 300 || $body === []) return false;
    require_once __DIR__ . '/nrsClient.php';
    $evidence = nrsReadEvidence($body);
    if ($evidence['irn'] === '' || $evidence['csid'] === '' || $evidence['qr_payload'] === '') return false;
    $digest = hash('sha256', json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $stmt = $conn->prepare(
        "INSERT INTO nrs_document_evidence (organization_id, document_type, document_id, evidence_environment, verification_status, irn, csid, qr_payload, source_operation, response_sha256) "
        . "VALUES (?, ?, ?, 'sandbox', 'pending', ?, ?, ?, ?, ?) "
        . "ON DUPLICATE KEY UPDATE "
        . "irn = IF(evidence_environment='sandbox', VALUES(irn), irn), "
        . "csid = IF(evidence_environment='sandbox', VALUES(csid), csid), "
        . "qr_payload = IF(evidence_environment='sandbox', VALUES(qr_payload), qr_payload), "
        . "source_operation = IF(evidence_environment='sandbox', VALUES(source_operation), source_operation), "
        . "response_sha256 = IF(evidence_environment='sandbox', VALUES(response_sha256), response_sha256), "
        . "updated_at = NOW()"
    );
    $stmt->bind_param('isisssss', $organizationId, $documentType, $documentId,
        $evidence['irn'], $evidence['csid'], $evidence['qr_payload'], $operation, $digest);
    $stmt->execute();
    $stmt->close();
    return true;
}
