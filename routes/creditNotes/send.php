<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/creditNoteService.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';
require_once __DIR__ . '/../../utils/mailer.php';

const CREDIT_NOTE_PDF_ATTACHMENT_MAX_BYTES = 4194304; // 4 MB.

function creditNoteSendDeliveryMode(array $data): string
{
    $mode = strtolower(trim((string) ($data['delivery_mode'] ?? 'template_only')));
    return $mode === 'pdf_attachment' ? 'pdf_attachment' : 'template_only';
}

function creditNoteAttachmentFilename(string $value, string $fallback): string
{
    $filename = trim($value) !== '' ? trim($value) : $fallback;
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: $fallback;
    $filename = trim($filename, '.-');
    if ($filename === '') {
        $filename = $fallback;
    }
    if (!str_ends_with(strtolower($filename), '.pdf')) {
        $filename .= '.pdf';
    }
    return mb_substr($filename, 0, 140);
}

function creditNotePdfAttachmentFromPayload(array $data, string $fallbackFilename): ?array
{
    if (creditNoteSendDeliveryMode($data) !== 'pdf_attachment') {
        return null;
    }

    $payload = $data['pdf_attachment'] ?? null;
    if (!is_array($payload)) {
        throw new Exception('PDF attachment payload is required when sending credit note with attachment.', 422);
    }

    $mimeType = strtolower(trim((string) ($payload['mime_type'] ?? 'application/pdf')));
    if ($mimeType !== 'application/pdf') {
        throw new Exception('Only PDF credit note attachments are allowed.', 422);
    }

    $base64 = trim((string) ($payload['content_base64'] ?? ''));
    if ($base64 === '') {
        throw new Exception('PDF attachment content is required.', 422);
    }

    $base64 = preg_replace('/^data:application\/pdf;base64,/i', '', $base64) ?? $base64;
    $base64 = preg_replace('/\s+/', '', $base64) ?? $base64;
    if (strlen($base64) > (int) ceil(CREDIT_NOTE_PDF_ATTACHMENT_MAX_BYTES * 1.38)) {
        throw new Exception('PDF attachment is too large. Please send the credit note using template-only delivery.', 413);
    }

    $content = base64_decode($base64, true);
    if ($content === false || $content === '') {
        throw new Exception('Invalid PDF attachment content.', 422);
    }
    if (strlen($content) > CREDIT_NOTE_PDF_ATTACHMENT_MAX_BYTES) {
        throw new Exception('PDF attachment is too large. Please send the credit note using template-only delivery.', 413);
    }
    if (!str_starts_with($content, '%PDF')) {
        throw new Exception('Invalid PDF attachment file.', 422);
    }

    return [
        'filename' => creditNoteAttachmentFilename((string) ($payload['filename'] ?? ''), $fallbackFilename),
        'mime_type' => 'application/pdf',
        'content' => $content,
    ];
}

try {
    $user = requirePermission('credit_notes.send', true);
    $data = readJsonBody();
    $creditNoteId = (int) ($data['id'] ?? 0);
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];

    if ($creditNoteId < 1) {
        throw new Exception('Credit note ID is required.', 422);
    }

    $document = creditNoteDocumentPayload($conn, $organizationId, $creditNoteId);
    $creditNote = $document['credit_note'];
    $organization = $document['organization'];

    if (($creditNote['status'] ?? '') !== 'approved') {
        throw new Exception('Only approved credit notes can be sent to customers.', 409);
    }

    $recipient = creditNoteSendableRecipient($creditNote, $data);
    $senderNote = creditNoteNullableText($data['message'] ?? null, 'Message to customer', 1200);
    $creditNoteNumber = (string) ($creditNote['credit_note_number'] ?: 'Credit note #' . $creditNote['id']);
    $deliveryMode = creditNoteSendDeliveryMode($data);
    $pdfAttachment = creditNotePdfAttachmentFromPayload($data, 'credit-note-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $creditNoteNumber) . '.pdf');
    $attachments = $pdfAttachment !== null ? [$pdfAttachment] : [];
    $subject = 'Credit note ' . $creditNoteNumber . ' from ' . (string) ($organization['trading_name'] ?: $organization['name']);
    $htmlBody = creditNoteCustomerEmail($creditNote, $organization, $senderNote, $pdfAttachment !== null);

    $delivery = sendTransactionalMail(
        $conn,
        'credit_note.customer_credit_note',
        $recipient['email'],
        $recipient['name'],
        $subject,
        $htmlBody,
        $userId,
        $organizationId,
        null,
        [
            'credit_note_id' => $creditNoteId,
            'credit_note_number' => $creditNote['credit_note_number'],
            'invoice_id' => $creditNote['invoice_id'],
            'invoice_number' => $creditNote['invoice_number'],
            'total_amount' => $creditNote['total_amount'],
            'currency' => $creditNote['currency'],
            'delivery_mode' => $deliveryMode,
            'pdf_attached' => $pdfAttachment !== null,
        ],
        $attachments
    );

    if (!$delivery['success']) {
        throw new Exception('Credit note email could not be delivered. Check mail settings and try again.', 502);
    }

    $deliveryStatus = $delivery['status'];
    $deliveryId = $delivery['delivery_id'];
    $conn->begin_transaction();
    try {
        $history = $conn->prepare(
            'INSERT INTO credit_note_delivery_history '
            . '(credit_note_id, organization_id, email_delivery_log_id, sent_by_user_id, recipient_email, recipient_name, subject, delivery_status, message, sent_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $history->bind_param(
            'iiiisssss',
            $creditNoteId,
            $organizationId,
            $deliveryId,
            $userId,
            $recipient['email'],
            $recipient['name'],
            $subject,
            $deliveryStatus,
            $senderNote
        );
        $history->execute();
        $history->close();

        $update = $conn->prepare(
            'UPDATE credit_notes SET sent_count = sent_count + 1, last_sent_at = NOW(), last_sent_by_user_id = ?, updated_by_user_id = ?, updated_at = NOW() '
            . 'WHERE id = ? AND organization_id = ?'
        );
        $update->bind_param('iiii', $userId, $userId, $creditNoteId, $organizationId);
        $update->execute();
        $update->close();

        auditLog($conn, $userId, 'credit_note.sent_to_customer', 'Credit note sent to customer.', $organizationId, json_encode([
            'credit_note_id' => $creditNoteId,
            'credit_note_number' => $creditNote['credit_note_number'],
            'invoice_id' => $creditNote['invoice_id'],
            'invoice_number' => $creditNote['invoice_number'],
            'recipient_email' => $recipient['email'],
            'delivery_status' => $deliveryStatus,
            'delivery_mode' => $deliveryMode,
            'pdf_attached' => $pdfAttachment !== null,
        ], JSON_UNESCAPED_SLASHES));
        $conn->commit();
    } catch (Throwable $inner) {
        $conn->rollback();
        throw $inner;
    }

    $updatedDocument = creditNoteDocumentPayload($conn, $organizationId, $creditNoteId);
    jsonSuccess([
        'message' => $deliveryStatus === 'logged'
            ? ($pdfAttachment !== null ? 'Credit note email with PDF attachment logged successfully.' : 'Credit note email logged successfully.')
            : ($pdfAttachment !== null ? 'Credit note sent to customer with PDF attachment successfully.' : 'Credit note sent to customer successfully.'),
        'contract_version' => 'credit-note-output-v1',
        'data' => $updatedDocument,
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    $statusCode = ($code >= 400 && $code <= 599) ? $code : 500;
    error_log('Credit note send failed: ' . $e->getMessage());
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to send credit note to customer.', $statusCode);
}
