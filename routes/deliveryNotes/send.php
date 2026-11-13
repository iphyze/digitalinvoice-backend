<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/supportingDocumentService.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';
require_once __DIR__ . '/../../utils/mailer.php';

const DELIVERY_NOTE_PDF_ATTACHMENT_MAX_BYTES = 4194304;

function deliveryNoteDeliveryMode(array $data): string
{
    return strtolower(trim((string) ($data['delivery_mode'] ?? 'template_only'))) === 'pdf_attachment' ? 'pdf_attachment' : 'template_only';
}

function deliveryNotePdfAttachmentFromPayload(array $data, string $fallbackFilename): ?array
{
    if (deliveryNoteDeliveryMode($data) !== 'pdf_attachment') return null;
    $payload = $data['pdf_attachment'] ?? null;
    if (!is_array($payload)) throw new Exception('PDF attachment payload is required.', 422);
    $base64 = preg_replace('/^data:application\/pdf;base64,/i', '', trim((string) ($payload['content_base64'] ?? ''))) ?? '';
    $base64 = preg_replace('/\s+/', '', $base64) ?? $base64;
    if ($base64 === '') throw new Exception('PDF attachment content is required.', 422);
    if (strlen($base64) > (int) ceil(DELIVERY_NOTE_PDF_ATTACHMENT_MAX_BYTES * 1.38)) throw new Exception('PDF attachment is too large.', 413);
    $content = base64_decode($base64, true);
    if ($content === false || $content === '' || !str_starts_with($content, '%PDF')) throw new Exception('Invalid PDF attachment file.', 422);
    if (strlen($content) > DELIVERY_NOTE_PDF_ATTACHMENT_MAX_BYTES) throw new Exception('PDF attachment is too large.', 413);
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($payload['filename'] ?? $fallbackFilename)) ?: $fallbackFilename;
    $filename = trim($filename, '.-');
    if ($filename === '') $filename = $fallbackFilename;
    if (!str_ends_with(strtolower($filename), '.pdf')) $filename .= '.pdf';
    return ['filename' => mb_substr($filename, 0, 140), 'mime_type' => 'application/pdf', 'content' => $content];
}

try {
    $user = requirePermission('delivery_notes.send', true);
    $data = readJsonBody();
    $deliveryNoteId = (int) ($data['id'] ?? 0);
    if ($deliveryNoteId < 1) throw new Exception('Delivery note ID is required.', 422);
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];
    $document = deliveryNoteDocumentPayload($conn, $organizationId, $deliveryNoteId);
    $deliveryNote = $document['delivery_note'];
    $organization = $document['organization'];
    if (($deliveryNote['status'] ?? '') !== 'issued') throw new Exception('Only issued delivery notes can be sent to customers.', 409);

    $recipient = deliveryNoteSendableRecipient($deliveryNote, $data);
    $senderNote = catalogueNullableText($data['message'] ?? null, 'Message to customer', 1200);
    $number = (string) ($deliveryNote['delivery_note_number'] ?: ('Delivery note #' . $deliveryNote['id']));
    $deliveryMode = deliveryNoteDeliveryMode($data);
    $pdfAttachment = deliveryNotePdfAttachmentFromPayload($data, 'delivery-note-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $number) . '.pdf');
    $subject = 'Delivery note ' . $number . ' from ' . (string) ($organization['trading_name'] ?: $organization['name']);
    $htmlBody = deliveryNoteCustomerEmail($deliveryNote, $organization, $senderNote, $pdfAttachment !== null);
    $delivery = sendTransactionalMail($conn, 'delivery_note.customer_document', $recipient['email'], $recipient['name'], $subject, $htmlBody, $userId, $organizationId, null, [
        'delivery_note_id' => $deliveryNoteId,
        'delivery_note_number' => $deliveryNote['delivery_note_number'],
        'delivery_mode' => $deliveryMode,
        'pdf_attached' => $pdfAttachment !== null,
    ], $pdfAttachment !== null ? [$pdfAttachment] : []);
    if (!$delivery['success']) throw new Exception('Delivery note email could not be delivered. Check mail settings and try again.', 502);

    $deliveryStatus = $delivery['status'];
    $conn->begin_transaction();
    try {
        $history = $conn->prepare('INSERT INTO delivery_note_delivery_history (delivery_note_id, organization_id, sent_by_user_id, recipient_email, recipient_name, subject, delivery_mode, attached_pdf, delivery_status, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $attached = $pdfAttachment !== null ? 1 : 0;
        $history->bind_param('iiissssis', $deliveryNoteId, $organizationId, $userId, $recipient['email'], $recipient['name'], $subject, $deliveryMode, $attached, $deliveryStatus);
        $history->execute();
        $history->close();
        $update = $conn->prepare('UPDATE delivery_notes SET sent_count = sent_count + 1, last_sent_at = NOW(), last_sent_by_user_id = ?, updated_by_user_id = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?');
        $update->bind_param('iiii', $userId, $userId, $deliveryNoteId, $organizationId);
        $update->execute();
        $update->close();
        auditLog($conn, $userId, 'delivery_note.sent_to_customer', 'Delivery note sent to customer.', $organizationId, json_encode(['delivery_note_id' => $deliveryNoteId, 'recipient_email' => $recipient['email'], 'delivery_mode' => $deliveryMode, 'pdf_attached' => $pdfAttachment !== null], JSON_UNESCAPED_SLASHES));
        $conn->commit();
    } catch (Throwable $inner) {
        $conn->rollback();
        throw $inner;
    }

    jsonSuccess(['message' => $pdfAttachment !== null ? 'Delivery note sent with PDF attachment successfully.' : 'Delivery note sent successfully.', 'data' => deliveryNoteDocumentPayload($conn, $organizationId, $deliveryNoteId)]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    $statusCode = ($code >= 400 && $code <= 599) ? $code : 500;
    error_log('Delivery note send failed: ' . $e->getMessage());
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to send delivery note to customer.', $statusCode);
}
