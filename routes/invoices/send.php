<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/invoiceService.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';
require_once __DIR__ . '/../../utils/mailer.php';


const INVOICE_PDF_ATTACHMENT_MAX_BYTES = 4194304; // 4 MB, keeps base64 payloads safe for common PHP post limits.

function invoiceSendDeliveryMode(array $data): string
{
    $mode = strtolower(trim((string) ($data['delivery_mode'] ?? 'template_only')));
    return $mode === 'pdf_attachment' ? 'pdf_attachment' : 'template_only';
}

function invoiceAttachmentFilename(string $value, string $fallback): string
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

function invoicePdfAttachmentFromPayload(array $data, string $fallbackFilename): ?array
{
    if (invoiceSendDeliveryMode($data) !== 'pdf_attachment') {
        return null;
    }

    $payload = $data['pdf_attachment'] ?? null;
    if (!is_array($payload)) {
        throw new Exception('PDF attachment payload is required when sending invoice with attachment.', 422);
    }

    $mimeType = strtolower(trim((string) ($payload['mime_type'] ?? 'application/pdf')));
    if ($mimeType !== 'application/pdf') {
        throw new Exception('Only PDF invoice attachments are allowed.', 422);
    }

    $base64 = trim((string) ($payload['content_base64'] ?? ''));
    if ($base64 === '') {
        throw new Exception('PDF attachment content is required.', 422);
    }

    $base64 = preg_replace('/^data:application\/pdf;base64,/i', '', $base64) ?? $base64;
    $base64 = preg_replace('/\s+/', '', $base64) ?? $base64;
    if (strlen($base64) > (int) ceil(INVOICE_PDF_ATTACHMENT_MAX_BYTES * 1.38)) {
        throw new Exception('PDF attachment is too large. Please send the invoice using template-only delivery.', 413);
    }

    $content = base64_decode($base64, true);
    if ($content === false || $content === '') {
        throw new Exception('Invalid PDF attachment content.', 422);
    }
    if (strlen($content) > INVOICE_PDF_ATTACHMENT_MAX_BYTES) {
        throw new Exception('PDF attachment is too large. Please send the invoice using template-only delivery.', 413);
    }
    if (!str_starts_with($content, '%PDF')) {
        throw new Exception('Invalid PDF attachment file.', 422);
    }

    return [
        'filename' => invoiceAttachmentFilename((string) ($payload['filename'] ?? ''), $fallbackFilename),
        'mime_type' => 'application/pdf',
        'content' => $content,
    ];
}

try {
    $user = requirePermission('invoices.send', true);
    $data = readJsonBody();
    $invoiceId = (int) ($data['id'] ?? 0);
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];

    if ($invoiceId < 1) {
        throw new Exception('Invoice ID is required.', 422);
    }

    $document = invoiceDocumentPayload($conn, $organizationId, $invoiceId);
    $invoice = $document['invoice'];
    $organization = $document['organization'];

    if (($invoice['status'] ?? '') !== 'approved') {
        throw new Exception('Only approved invoices can be sent to customers.', 409);
    }

    // Opt-in only until NRS is licensed. Enable this on production when clearance is required.
    if (filter_var((string) config('NRS_ENFORCE_B2B_CLEARANCE', 'false'), FILTER_VALIDATE_BOOLEAN)
        && ($invoice['firs_transaction_type'] ?? 'b2b') === 'b2b'
        && (($invoice['nrs_submission_status'] ?? '') !== 'accepted' || empty($invoice['nrs_invoice_identifier']))) {
        throw new Exception('This B2B invoice requires confirmed NRS clearance before customer delivery.', 409);
    }

    $recipient = invoiceSendableRecipient($invoice, $data);
    $senderNote = invoiceNullableText($data['message'] ?? null, 'Message to customer', 1200);
    $invoiceNumber = (string) ($invoice['invoice_number'] ?: 'Invoice #' . $invoice['id']);
    $deliveryMode = invoiceSendDeliveryMode($data);
    $pdfAttachment = invoicePdfAttachmentFromPayload($data, 'invoice-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $invoiceNumber) . '.pdf');
    $attachments = $pdfAttachment !== null ? [$pdfAttachment] : [];
    $subject = 'Invoice ' . $invoiceNumber . ' from ' . (string) ($organization['trading_name'] ?: $organization['name']);
    $htmlBody = invoiceCustomerEmail($invoice, $organization, $senderNote, $pdfAttachment !== null);

    $delivery = sendTransactionalMail(
        $conn,
        'invoice.customer_invoice',
        $recipient['email'],
        $recipient['name'],
        $subject,
        $htmlBody,
        $userId,
        $organizationId,
        null,
        [
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoice['invoice_number'],
            'total_amount' => $invoice['total_amount'],
            'currency' => $invoice['currency'],
            'delivery_mode' => $deliveryMode,
            'pdf_attached' => $pdfAttachment !== null,
        ],
        $attachments
    );

    if (!$delivery['success']) {
        throw new Exception('Invoice email could not be delivered. Check mail settings and try again.', 502);
    }

    $deliveryStatus = $delivery['status'];
    $deliveryId = $delivery['delivery_id'];
    $conn->begin_transaction();
    try {
        $history = $conn->prepare(
            'INSERT INTO invoice_delivery_history '
            . '(invoice_id, organization_id, email_delivery_log_id, sent_by_user_id, recipient_email, recipient_name, subject, delivery_status, message, sent_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $history->bind_param(
            'iiiisssss',
            $invoiceId,
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
            'UPDATE invoices SET sent_count = sent_count + 1, last_sent_at = NOW(), last_sent_by_user_id = ?, updated_by_user_id = ?, updated_at = NOW() '
            . 'WHERE id = ? AND organization_id = ?'
        );
        $update->bind_param('iiii', $userId, $userId, $invoiceId, $organizationId);
        $update->execute();
        $update->close();

        auditLog($conn, $userId, 'invoice.sent_to_customer', 'Invoice sent to customer.', $organizationId, json_encode([
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoice['invoice_number'],
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

    $updatedDocument = invoiceDocumentPayload($conn, $organizationId, $invoiceId);
    jsonSuccess([
        'message' => $deliveryStatus === 'logged'
            ? ($pdfAttachment !== null ? 'Invoice email with PDF attachment logged successfully.' : 'Invoice email logged successfully.')
            : ($pdfAttachment !== null ? 'Invoice sent to customer with PDF attachment successfully.' : 'Invoice sent to customer successfully.'),
        'contract_version' => 'invoice-output-v1',
        'data' => $updatedDocument,
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    $statusCode = ($code >= 400 && $code <= 599) ? $code : 500;
    error_log('Invoice send failed: ' . $e->getMessage());
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to send invoice to customer.', $statusCode);
}
