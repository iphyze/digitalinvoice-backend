<?php

declare(strict_types=1);

require_once __DIR__ . '/invoiceService.php';
require_once __DIR__ . '/paymentService.php';
require_once __DIR__ . '/creditNoteService.php';
require_once __DIR__ . '/supportingDocumentService.php';
require_once __DIR__ . '/nrsDocumentEvidence.php';
require_once __DIR__ . '/../utils/mailer.php';
require_once __DIR__ . '/../utils/emailTemplates.php';

const CUSTOMER_PORTAL_DOCUMENT_TYPES = ['invoice', 'credit_note', 'payment_receipt', 'proforma_invoice', 'delivery_note'];

function customerPortalFrontendBaseUrl(): string
{
    $url = trim((string) (config('FRONTEND_URL', config('APP_URL', 'http://localhost:5173')) ?? 'http://localhost:5173'));
    return rtrim($url !== '' ? $url : 'http://localhost:5173', '/');
}

function customerPortalTokenHash(string $token): string
{
    return hash('sha256', $token);
}

function customerPortalDocumentType(mixed $value): string
{
    $type = strtolower(trim((string) $value));
    if (!in_array($type, CUSTOMER_PORTAL_DOCUMENT_TYPES, true)) {
        throw new Exception('Select a valid document type for the secure customer link.', 422);
    }
    return $type;
}

function customerPortalDocumentLabel(string $type): string
{
    return match ($type) {
        'invoice' => 'Invoice',
        'credit_note' => 'Credit Note',
        'payment_receipt' => 'Payment Receipt',
        'proforma_invoice' => 'Proforma Invoice',
        'delivery_note' => 'Delivery Note',
        default => 'Document',
    };
}

function customerPortalDocumentPayload(mysqli $conn, int $organizationId, string $type, int $documentId): array
{
    if ($documentId < 1) {
        throw new Exception('Document ID is required.', 422);
    }

    $payload = match ($type) {
        'invoice' => invoiceDocumentPayload($conn, $organizationId, $documentId),
        'credit_note' => creditNoteDocumentPayload($conn, $organizationId, $documentId),
        'payment_receipt' => paymentDocumentPayload($conn, $organizationId, $documentId),
        'proforma_invoice' => proformaDocumentPayload($conn, $organizationId, $documentId),
        'delivery_note' => deliveryNoteDocumentPayload($conn, $organizationId, $documentId),
        default => throw new Exception('Unsupported secure link document type.', 422),
    };

    customerPortalEnsureDocumentShareable($type, $payload);
    return $payload;
}

function customerPortalEnsureDocumentShareable(string $type, array $payload): void
{
    $record = customerPortalPrimaryDocument($type, $payload);
    $status = strtolower((string) ($record['status'] ?? ''));

    if ($type === 'invoice' && $status !== 'approved') {
        throw new Exception('Only approved invoices can be shared through secure customer links.', 409);
    }
    if ($type === 'credit_note' && $status !== 'approved') {
        throw new Exception('Only approved credit notes can be shared through secure customer links.', 409);
    }
    if ($type === 'payment_receipt' && $status === 'void') {
        throw new Exception('Voided payment receipts cannot be shared through secure customer links.', 409);
    }
    if ($type === 'proforma_invoice' && !in_array($status, ['issued', 'converted'], true)) {
        throw new Exception('Only issued proforma invoices can be shared through secure customer links.', 409);
    }
    if ($type === 'delivery_note' && $status !== 'issued') {
        throw new Exception('Only issued delivery notes can be shared through secure customer links.', 409);
    }
}

function customerPortalPrimaryDocument(string $type, array $payload): array
{
    $key = match ($type) {
        'invoice' => 'invoice',
        'credit_note' => 'credit_note',
        'payment_receipt' => 'payment',
        'proforma_invoice' => 'proforma',
        'delivery_note' => 'delivery_note',
        default => '',
    };
    $document = $payload[$key] ?? null;
    if (!is_array($document)) {
        throw new Exception('Secure link document payload is not available.', 500);
    }
    return $document;
}

function customerPortalDocumentReference(string $type, array $document): string
{
    return (string) match ($type) {
        'invoice' => $document['invoice_number'] ?? ('Invoice #' . ($document['id'] ?? '')),
        'credit_note' => $document['credit_note_number'] ?? ('Credit Note #' . ($document['id'] ?? '')),
        'payment_receipt' => $document['receipt_number'] ?? ('Receipt #' . ($document['id'] ?? '')),
        'proforma_invoice' => $document['proforma_number'] ?? ('Proforma #' . ($document['id'] ?? '')),
        'delivery_note' => $document['delivery_note_number'] ?? ('Delivery Note #' . ($document['id'] ?? '')),
        default => 'Document',
    };
}

function customerPortalDocumentCustomer(array $document): array
{
    return [
        'id' => isset($document['customer_id']) ? (int) $document['customer_id'] : null,
        'name' => $document['customer_name_snapshot'] ?? null,
        'email' => $document['customer_email_snapshot'] ?? null,
    ];
}

function customerPortalOrganizationWithLogoData(array $organization): array
{
    $organization['logo_data_url'] = null;
    if (empty($organization['logo_available']) || empty($organization['id'])) {
        return $organization;
    }

    $orgId = (int) $organization['id'];
    $storedName = null;
    $mimeType = $organization['logo_mime_type'] ?? null;

    // The public payload may be built from profile helpers that omit the stored filename,
    // so fetch it directly using the already-open request connection.
    try {
        global $conn;
        if (isset($conn) && $conn instanceof mysqli) {
            $stmt = $conn->prepare('SELECT logo_stored_name, logo_mime_type FROM organizations WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $orgId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $storedName = $row['logo_stored_name'] ?? null;
            $mimeType = $row['logo_mime_type'] ?? $mimeType;
        }
    } catch (Throwable) {
        $storedName = null;
    }

    $storedName = $storedName ?: ($organization['logo_stored_name'] ?? null);
    if (!$storedName) {
        return $organization;
    }
    $path = dirname(__DIR__) . '/storage/organization-logos/' . basename((string) $storedName);
    if (!is_file($path) || !is_readable($path)) {
        return $organization;
    }
    $bytes = file_get_contents($path);
    if ($bytes === false) {
        return $organization;
    }
    $organization['logo_data_url'] = 'data:' . ($mimeType ?: 'image/png') . ';base64,' . base64_encode($bytes);
    return $organization;
}

function customerPortalLinkUrl(string $token): string
{
    return customerPortalFrontendBaseUrl() . '/portal/' . rawurlencode($token);
}

function customerPortalRecipientName(?string $name, ?string $email): string
{
    $display = trim((string) $name);
    if ($display !== '') {
        return $display;
    }
    $email = trim((string) $email);
    return $email !== '' ? $email : 'Customer';
}

function customerPortalEmailSecureLink(mysqli $conn, int $organizationId, int $userId, array $link, array $payload, string $portalUrl, string $reference): array
{
    $recipientEmail = trim((string) ($link['recipient_email'] ?? ''));
    if ($recipientEmail === '') {
        return ['success' => false, 'status' => 'missing_recipient', 'delivery_id' => null];
    }

    $recipientName = customerPortalRecipientName($link['recipient_name'] ?? null, $recipientEmail);
    $organization = is_array($payload['organization'] ?? null) ? $payload['organization'] : [];
    $businessName = trim((string) (($organization['trading_name'] ?? '') ?: ($organization['name'] ?? appName())));
    $documentLabel = customerPortalDocumentLabel((string) ($link['document_type'] ?? ''));
    $expiresAt = (string) ($link['expires_at'] ?? '');
    $subject = $businessName . ' shared ' . $documentLabel . ' ' . $reference . ' with you';

    return sendTransactionalMail(
        $conn,
        'customer_portal_secure_link',
        $recipientEmail,
        $recipientName,
        $subject,
        customerPortalSecureLinkEmail($recipientName, $businessName, $documentLabel, $reference, $portalUrl, $expiresAt),
        $userId,
        $organizationId,
        'customer_portal_secure_link_' . (int) ($link['id'] ?? 0),
        [
            'portal_link_id' => (int) ($link['id'] ?? 0),
            'document_type' => (string) ($link['document_type'] ?? ''),
            'document_id' => (int) ($link['document_id'] ?? 0),
            'document_reference' => $reference,
            'expires_at' => $expiresAt,
        ]
    );
}

function customerPortalCreateLink(mysqli $conn, array $user, array $data): array
{
    $organizationId = (int) $user['organization_id'];
    $documentType = customerPortalDocumentType($data['document_type'] ?? '');
    $documentId = (int) ($data['document_id'] ?? 0);
    $expiresInDays = (int) ($data['expires_in_days'] ?? 30);
    if ($expiresInDays < 1 || $expiresInDays > 365) {
        throw new Exception('Secure link expiry must be between 1 and 365 days.', 422);
    }

    $payload = customerPortalDocumentPayload($conn, $organizationId, $documentType, $documentId);
    $document = customerPortalPrimaryDocument($documentType, $payload);
    $customer = customerPortalDocumentCustomer($document);
    $reference = customerPortalDocumentReference($documentType, $document);
    $title = customerPortalDocumentLabel($documentType) . ' ' . $reference;
    $recipientEmail = catalogueEmail($data['recipient_email'] ?? $customer['email'] ?? null, 'Recipient email');
    $recipientName = catalogueNullableText($data['recipient_name'] ?? $customer['name'] ?? null, 'Recipient name', 120);
    $token = randomUrlSafeToken(36);
    $hash = customerPortalTokenHash($token);
    $expiresAt = (new DateTimeImmutable('now'))->modify('+' . $expiresInDays . ' days')->format('Y-m-d H:i:s');
    $customerId = $customer['id'];
    $userId = (int) $user['id'];

    $stmt = $conn->prepare(
        'INSERT INTO customer_portal_links (organization_id, customer_id, document_type, document_id, token_hash, title, recipient_email, recipient_name, expires_at, created_by_user_id) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iisisssssi', $organizationId, $customerId, $documentType, $documentId, $hash, $title, $recipientEmail, $recipientName, $expiresAt, $userId);
    $stmt->execute();
    $linkId = (int) $stmt->insert_id;
    $stmt->close();

    $link = customerPortalFindLinkById($conn, $organizationId, $linkId);
    $link['portal_url'] = customerPortalLinkUrl($token);
    $link['token'] = $token;
    $emailResult = customerPortalEmailSecureLink($conn, $organizationId, $userId, $link, $payload, $link['portal_url'], $reference);
    $link['email_delivery_status'] = $emailResult['status'] ?? 'failed';
    $link['email_delivery_id'] = $emailResult['delivery_id'] ?? null;
    $link['email_sent'] = in_array($link['email_delivery_status'], ['sent', 'logged', 'already_sent'], true);
    return $link;
}

function customerPortalFindLinkById(mysqli $conn, int $organizationId, int $linkId): array
{
    $stmt = $conn->prepare(
        'SELECT l.id, l.organization_id, l.customer_id, l.document_type, l.document_id, l.title, l.recipient_email, l.recipient_name, l.expires_at, '
        . 'l.revoked_at, l.last_accessed_at, l.access_count, l.created_at, u.name AS created_by_name, ur.name AS revoked_by_name '
        . 'FROM customer_portal_links l LEFT JOIN users u ON u.id = l.created_by_user_id LEFT JOIN users ur ON ur.id = l.revoked_by_user_id '
        . 'WHERE l.id = ? AND l.organization_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $linkId, $organizationId);
    $stmt->execute();
    $link = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$link) {
        throw new Exception('Secure link was not found.', 404);
    }
    return customerPortalPublicLinkShape($link);
}

function customerPortalPublicLinkShape(array $link): array
{
    $link['id'] = (int) $link['id'];
    $link['organization_id'] = (int) $link['organization_id'];
    $link['customer_id'] = isset($link['customer_id']) ? (int) $link['customer_id'] : null;
    $link['document_id'] = (int) $link['document_id'];
    $link['access_count'] = (int) ($link['access_count'] ?? 0);
    $link['is_expired'] = strtotime((string) $link['expires_at']) < time();
    $link['is_revoked'] = !empty($link['revoked_at']);
    return $link;
}

function customerPortalListLinks(mysqli $conn, int $organizationId): array
{
    $rawType = trim((string) ($_GET['document_type'] ?? ''));
    $documentId = (int) ($_GET['document_id'] ?? 0);

    $baseSql = 'SELECT l.id, l.organization_id, l.customer_id, l.document_type, l.document_id, l.title, l.recipient_email, l.recipient_name, l.expires_at, '
        . 'l.revoked_at, l.last_accessed_at, l.access_count, l.created_at, u.name AS created_by_name, ur.name AS revoked_by_name '
        . 'FROM customer_portal_links l LEFT JOIN users u ON u.id = l.created_by_user_id LEFT JOIN users ur ON ur.id = l.revoked_by_user_id ';

    if ($rawType !== '' || $documentId > 0) {
        $documentType = customerPortalDocumentType($rawType);
        if ($documentId < 1) {
            throw new Exception('Document ID is required.', 422);
        }
        $stmt = $conn->prepare($baseSql . 'WHERE l.organization_id = ? AND l.document_type = ? AND l.document_id = ? ORDER BY l.id DESC LIMIT 20');
        $stmt->bind_param('isi', $organizationId, $documentType, $documentId);
    } else {
        $stmt = $conn->prepare($baseSql . 'WHERE l.organization_id = ? ORDER BY l.id DESC LIMIT 80');
        $stmt->bind_param('i', $organizationId);
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map('customerPortalPublicLinkShape', $rows);
}

function customerPortalRevokeLink(mysqli $conn, array $user, array $data): array
{
    $organizationId = (int) $user['organization_id'];
    $linkId = (int) ($data['id'] ?? 0);
    if ($linkId < 1) {
        throw new Exception('Secure link ID is required.', 422);
    }
    $userId = (int) $user['id'];
    $stmt = $conn->prepare('UPDATE customer_portal_links SET revoked_at = COALESCE(revoked_at, NOW()), revoked_by_user_id = COALESCE(revoked_by_user_id, ?) WHERE id = ? AND organization_id = ?');
    $stmt->bind_param('iii', $userId, $linkId, $organizationId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    if ($affected < 1) {
        throw new Exception('Secure link was not found.', 404);
    }
    return customerPortalFindLinkById($conn, $organizationId, $linkId);
}

function customerPortalAccessLink(mysqli $conn, string $token, bool $trackAccess = true): array
{
    $token = trim($token);
    if ($token === '' || strlen($token) < 20) {
        throw new Exception('This customer portal link is invalid.', 404);
    }
    $hash = customerPortalTokenHash($token);
    $stmt = $conn->prepare(
        'SELECT l.id, l.organization_id, l.customer_id, l.document_type, l.document_id, l.title, l.recipient_email, l.recipient_name, l.expires_at, '
        . 'l.revoked_at, l.last_accessed_at, l.access_count, l.created_at, u.name AS created_by_name '
        . 'FROM customer_portal_links l LEFT JOIN users u ON u.id = l.created_by_user_id WHERE l.token_hash = ? LIMIT 1'
    );
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $link = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$link) {
        throw new Exception('This customer portal link is invalid or no longer available.', 404);
    }
    $link = customerPortalPublicLinkShape($link);
    if ($link['is_revoked']) {
        throw new Exception('This customer portal link has been revoked.', 410);
    }
    if ($link['is_expired']) {
        throw new Exception('This customer portal link has expired. Please request a new copy.', 410);
    }

    $payload = customerPortalDocumentPayload($conn, (int) $link['organization_id'], (string) $link['document_type'], (int) $link['document_id']);
    // Public links may show only the production-confirmed NRS evidence returned
    // by the same read-only gate as authenticated document endpoints. Never
    // leak sandbox/mock evidence or expose internal NRS credentials.
    $evidenceDocumentKey = match ((string) $link['document_type']) {
        'invoice' => 'invoice',
        'credit_note' => 'credit_note',
        'payment_receipt' => 'payment',
        default => null,
    };
    if ($evidenceDocumentKey !== null && isset($payload[$evidenceDocumentKey]) && is_array($payload[$evidenceDocumentKey])) {
        $payload[$evidenceDocumentKey]['nrs_evidence'] = nrsDocumentEvidence(
            $conn,
            (int) $link['organization_id'],
            (string) $link['document_type'],
            (int) $link['document_id']
        );
    }
    if (isset($payload['organization']) && is_array($payload['organization'])) {
        $payload['organization'] = customerPortalOrganizationWithLogoData($payload['organization']);
    }

    if ($trackAccess) {
        $update = $conn->prepare('UPDATE customer_portal_links SET access_count = access_count + 1, last_accessed_at = NOW() WHERE id = ?');
        $update->bind_param('i', $link['id']);
        $update->execute();
        $update->close();
        $link['access_count']++;
        $link['last_accessed_at'] = date('Y-m-d H:i:s');
    }

    return [
        'link' => $link,
        'document_type' => $link['document_type'],
        'document_label' => customerPortalDocumentLabel((string) $link['document_type']),
        'document_reference' => customerPortalDocumentReference((string) $link['document_type'], customerPortalPrimaryDocument((string) $link['document_type'], $payload)),
        'data' => $payload,
    ];
}
