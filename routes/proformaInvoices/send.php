<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/supportingDocumentService.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';
require_once __DIR__ . '/../../utils/mailer.php';

const PROFORMA_PDF_ATTACHMENT_MAX_BYTES = 4194304;

function proformaDeliveryMode(array $data): string
{
    return strtolower(trim((string) ($data['delivery_mode'] ?? 'template_only'))) === 'pdf_attachment' ? 'pdf_attachment' : 'template_only';
}

function proformaPdfAttachmentFromPayload(array $data, string $fallbackFilename): ?array
{
    if (proformaDeliveryMode($data) !== 'pdf_attachment') return null;
    $payload = $data['pdf_attachment'] ?? null;
    if (!is_array($payload)) throw new Exception('PDF attachment payload is required.', 422);
    $base64 = preg_replace('/^data:application\/pdf;base64,/i', '', trim((string) ($payload['content_base64'] ?? ''))) ?? '';
    $base64 = preg_replace('/\s+/', '', $base64) ?? $base64;
    if ($base64 === '') throw new Exception('PDF attachment content is required.', 422);
    if (strlen($base64) > (int) ceil(PROFORMA_PDF_ATTACHMENT_MAX_BYTES * 1.38)) throw new Exception('PDF attachment is too large.', 413);
    $content = base64_decode($base64, true);
    if ($content === false || $content === '' || !str_starts_with($content, '%PDF')) throw new Exception('Invalid PDF attachment file.', 422);
    if (strlen($content) > PROFORMA_PDF_ATTACHMENT_MAX_BYTES) throw new Exception('PDF attachment is too large.', 413);
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($payload['filename'] ?? $fallbackFilename)) ?: $fallbackFilename;
    $filename = trim($filename, '.-');
    if ($filename === '') $filename = $fallbackFilename;
    if (!str_ends_with(strtolower($filename), '.pdf')) $filename .= '.pdf';
    return ['filename' => mb_substr($filename, 0, 140), 'mime_type' => 'application/pdf', 'content' => $content];
}

try {
    $user = requirePermission('proformas.send', true);
    $data = readJsonBody();
    $proformaId = (int) ($data['id'] ?? 0);
    if ($proformaId < 1) throw new Exception('Proforma invoice ID is required.', 422);
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];
    $document = proformaDocumentPayload($conn, $organizationId, $proformaId);
    $proforma = $document['proforma'];
    $organization = $document['organization'];
    if (($proforma['status'] ?? '') !== 'issued') throw new Exception('Only issued proforma invoices can be sent to customers.', 409);

    $recipient = proformaSendableRecipient($proforma, $data);
    $senderNote = catalogueNullableText($data['message'] ?? null, 'Message to customer', 1200);
    $number = (string) ($proforma['proforma_number'] ?: ('Proforma #' . $proforma['id']));
    $deliveryMode = proformaDeliveryMode($data);
    $pdfAttachment = proformaPdfAttachmentFromPayload($data, 'proforma-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $number) . '.pdf');
    $subject = 'Proforma invoice ' . $number . ' from ' . (string) ($organization['trading_name'] ?: $organization['name']);
    $htmlBody = proformaInvoiceCustomerEmail($proforma, $organization, $senderNote, $pdfAttachment !== null);
    $delivery = sendTransactionalMail($conn, 'proforma.customer_document', $recipient['email'], $recipient['name'], $subject, $htmlBody, $userId, $organizationId, null, [
        'proforma_id' => $proformaId,
        'proforma_number' => $proforma['proforma_number'],
        'delivery_mode' => $deliveryMode,
        'pdf_attached' => $pdfAttachment !== null,
    ], $pdfAttachment !== null ? [$pdfAttachment] : []);
    if (!$delivery['success']) throw new Exception('Proforma email could not be delivered. Check mail settings and try again.', 502);

    $deliveryStatus = $delivery['status'];
    $conn->begin_transaction();
    try {
        $history = $conn->prepare('INSERT INTO proforma_invoice_delivery_history (proforma_invoice_id, organization_id, sent_by_user_id, recipient_email, recipient_name, subject, delivery_mode, attached_pdf, delivery_status, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $attached = $pdfAttachment !== null ? 1 : 0;
        $history->bind_param('iiissssis', $proformaId, $organizationId, $userId, $recipient['email'], $recipient['name'], $subject, $deliveryMode, $attached, $deliveryStatus);
        $history->execute();
        $history->close();
        $update = $conn->prepare('UPDATE proforma_invoices SET sent_count = sent_count + 1, last_sent_at = NOW(), last_sent_by_user_id = ?, updated_by_user_id = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?');
        $update->bind_param('iiii', $userId, $userId, $proformaId, $organizationId);
        $update->execute();
        $update->close();
        auditLog($conn, $userId, 'proforma.sent_to_customer', 'Proforma invoice sent to customer.', $organizationId, json_encode(['proforma_id' => $proformaId, 'recipient_email' => $recipient['email'], 'delivery_mode' => $deliveryMode, 'pdf_attached' => $pdfAttachment !== null], JSON_UNESCAPED_SLASHES));
        $conn->commit();
    } catch (Throwable $inner) {
        $conn->rollback();
        throw $inner;
    }

    jsonSuccess(['message' => $pdfAttachment !== null ? 'Proforma invoice sent with PDF attachment successfully.' : 'Proforma invoice sent successfully.', 'data' => proformaDocumentPayload($conn, $organizationId, $proformaId)]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    $statusCode = ($code >= 400 && $code <= 599) ? $code : 500;
    error_log('Proforma send failed: ' . $e->getMessage());
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to send proforma invoice to customer.', $statusCode);
}
