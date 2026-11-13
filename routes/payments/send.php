<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/paymentService.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';
require_once __DIR__ . '/../../utils/mailer.php';

const PAYMENT_RECEIPT_PDF_ATTACHMENT_MAX_BYTES = 4194304; // 4 MB.

function paymentReceiptDeliveryMode(array $data): string
{
    $mode = strtolower(trim((string) ($data['delivery_mode'] ?? 'template_only')));
    return $mode === 'pdf_attachment' ? 'pdf_attachment' : 'template_only';
}

function paymentReceiptAttachmentFilename(string $value, string $fallback): string
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

function paymentReceiptPdfAttachmentFromPayload(array $data, string $fallbackFilename): ?array
{
    if (paymentReceiptDeliveryMode($data) !== 'pdf_attachment') {
        return null;
    }

    $payload = $data['pdf_attachment'] ?? null;
    if (!is_array($payload)) {
        throw new Exception('PDF attachment payload is required when sending receipt with attachment.', 422);
    }

    $mimeType = strtolower(trim((string) ($payload['mime_type'] ?? 'application/pdf')));
    if ($mimeType !== 'application/pdf') {
        throw new Exception('Only PDF receipt attachments are allowed.', 422);
    }

    $base64 = trim((string) ($payload['content_base64'] ?? ''));
    if ($base64 === '') {
        throw new Exception('PDF attachment content is required.', 422);
    }

    $base64 = preg_replace('/^data:application\/pdf;base64,/i', '', $base64) ?? $base64;
    $base64 = preg_replace('/\s+/', '', $base64) ?? $base64;
    if (strlen($base64) > (int) ceil(PAYMENT_RECEIPT_PDF_ATTACHMENT_MAX_BYTES * 1.38)) {
        throw new Exception('PDF attachment is too large. Please send the receipt using template-only delivery.', 413);
    }

    $content = base64_decode($base64, true);
    if ($content === false || $content === '') {
        throw new Exception('Invalid PDF attachment content.', 422);
    }
    if (strlen($content) > PAYMENT_RECEIPT_PDF_ATTACHMENT_MAX_BYTES) {
        throw new Exception('PDF attachment is too large. Please send the receipt using template-only delivery.', 413);
    }
    if (!str_starts_with($content, '%PDF')) {
        throw new Exception('Invalid PDF attachment file.', 422);
    }

    return [
        'filename' => paymentReceiptAttachmentFilename((string) ($payload['filename'] ?? ''), $fallbackFilename),
        'mime_type' => 'application/pdf',
        'content' => $content,
    ];
}

try {
    $user = requirePermission('payments.send', true);
    $data = readJsonBody();
    $paymentId = (int) ($data['id'] ?? 0);
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];

    if ($paymentId < 1) {
        throw new Exception('Payment ID is required.', 422);
    }

    $document = paymentDocumentPayload($conn, $organizationId, $paymentId);
    $payment = $document['payment'];
    $organization = $document['organization'];

    if (($payment['status'] ?? '') === 'void') {
        throw new Exception('Voided payments cannot be sent as customer receipts.', 409);
    }

    $recipient = paymentSendableRecipient($payment, $data);
    $senderNote = catalogueNullableText($data['message'] ?? null, 'Message to customer', 1200);
    $receiptNumber = (string) ($payment['receipt_number'] ?: 'Receipt #' . $payment['id']);
    $deliveryMode = paymentReceiptDeliveryMode($data);
    $pdfAttachment = paymentReceiptPdfAttachmentFromPayload($data, 'receipt-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $receiptNumber) . '.pdf');
    $attachments = $pdfAttachment !== null ? [$pdfAttachment] : [];
    $subject = 'Payment receipt ' . $receiptNumber . ' from ' . (string) ($organization['trading_name'] ?: $organization['name']);
    $htmlBody = paymentReceiptCustomerEmail($payment, $organization, $senderNote, $pdfAttachment !== null);

    $delivery = sendTransactionalMail(
        $conn,
        'payment.customer_receipt',
        $recipient['email'],
        $recipient['name'],
        $subject,
        $htmlBody,
        $userId,
        $organizationId,
        null,
        [
            'payment_id' => $paymentId,
            'receipt_number' => $payment['receipt_number'],
            'amount' => $payment['amount'],
            'currency' => $payment['currency'],
            'delivery_mode' => $deliveryMode,
            'pdf_attached' => $pdfAttachment !== null,
        ],
        $attachments
    );

    if (!$delivery['success']) {
        throw new Exception('Receipt email could not be delivered. Check mail settings and try again.', 502);
    }

    $deliveryStatus = $delivery['status'];
    $deliveryId = $delivery['delivery_id'];
    $conn->begin_transaction();
    try {
        $history = $conn->prepare(
            'INSERT INTO payment_receipt_delivery_history '
            . '(payment_id, organization_id, email_delivery_log_id, sent_by_user_id, recipient_email, recipient_name, subject, delivery_status, message, sent_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $history->bind_param(
            'iiiisssss',
            $paymentId,
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
            'UPDATE invoice_payments SET sent_count = sent_count + 1, last_sent_at = NOW(), last_sent_by_user_id = ?, updated_by_user_id = ?, updated_at = NOW() '
            . 'WHERE id = ? AND organization_id = ?'
        );
        $update->bind_param('iiii', $userId, $userId, $paymentId, $organizationId);
        $update->execute();
        $update->close();

        auditLog($conn, $userId, 'payment.receipt_sent_to_customer', 'Payment receipt sent to customer.', $organizationId, json_encode([
            'payment_id' => $paymentId,
            'receipt_number' => $payment['receipt_number'],
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

    $updatedDocument = paymentDocumentPayload($conn, $organizationId, $paymentId);
    jsonSuccess([
        'message' => $deliveryStatus === 'logged'
            ? ($pdfAttachment !== null ? 'Receipt email with PDF attachment logged successfully.' : 'Receipt email logged successfully.')
            : ($pdfAttachment !== null ? 'Receipt sent to customer with PDF attachment successfully.' : 'Receipt sent to customer successfully.'),
        'contract_version' => 'payment-receipt-output-v1',
        'data' => $updatedDocument,
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    $statusCode = ($code >= 400 && $code <= 599) ? $code : 500;
    error_log('Payment receipt send failed: ' . $e->getMessage());
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to send payment receipt to customer.', $statusCode);
}
