<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/reportingService.php';
require_once __DIR__ . '/../../utils/emailTemplates.php';
require_once __DIR__ . '/../../utils/mailer.php';

const CUSTOMER_STATEMENT_PDF_ATTACHMENT_MAX_BYTES = 4194304;

function statementEmailValue(array $data, string $key, string $label, int $max = 190): string
{
    $value = trim((string) ($data[$key] ?? ''));
    if ($value === '') {
        throw new Exception($label . ' is required.', 422);
    }
    if (mb_strlen($value) > $max) {
        throw new Exception($label . ' is too long.', 422);
    }
    return $value;
}

function statementNullableText(mixed $value, string $label, int $max): ?string
{
    $text = trim((string) ($value ?? ''));
    if ($text === '') {
        return null;
    }
    if (mb_strlen($text) > $max) {
        throw new Exception($label . ' may not exceed ' . $max . ' characters.', 422);
    }
    return $text;
}

function statementDeliveryMode(array $data): string
{
    $mode = strtolower(trim((string) ($data['delivery_mode'] ?? 'template_only')));
    return $mode === 'pdf_attachment' ? 'pdf_attachment' : 'template_only';
}

function statementAttachmentFilename(string $value, string $fallback): string
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

function statementPdfAttachmentFromPayload(array $data, string $fallbackFilename): ?array
{
    if (statementDeliveryMode($data) !== 'pdf_attachment') {
        return null;
    }

    $payload = $data['pdf_attachment'] ?? null;
    if (!is_array($payload)) {
        throw new Exception('PDF attachment payload is required when sending the customer statement with attachment.', 422);
    }

    $mimeType = strtolower(trim((string) ($payload['mime_type'] ?? 'application/pdf')));
    if ($mimeType !== 'application/pdf') {
        throw new Exception('Only PDF statement attachments are allowed.', 422);
    }

    $base64 = trim((string) ($payload['content_base64'] ?? ''));
    if ($base64 === '') {
        throw new Exception('PDF statement attachment content is required.', 422);
    }

    $base64 = preg_replace('/^data:application\/pdf;base64,/i', '', $base64) ?? $base64;
    $base64 = preg_replace('/\s+/', '', $base64) ?? $base64;
    if (strlen($base64) > (int) ceil(CUSTOMER_STATEMENT_PDF_ATTACHMENT_MAX_BYTES * 1.38)) {
        throw new Exception('Statement PDF is too large. Please send the statement using template-only delivery.', 413);
    }

    $content = base64_decode($base64, true);
    if ($content === false || $content === '') {
        throw new Exception('Invalid statement PDF attachment content.', 422);
    }
    if (strlen($content) > CUSTOMER_STATEMENT_PDF_ATTACHMENT_MAX_BYTES) {
        throw new Exception('Statement PDF is too large. Please send the statement using template-only delivery.', 413);
    }
    if (!str_starts_with($content, '%PDF')) {
        throw new Exception('Invalid statement PDF file.', 422);
    }

    return [
        'filename' => statementAttachmentFilename((string) ($payload['filename'] ?? ''), $fallbackFilename),
        'mime_type' => 'application/pdf',
        'content' => $content,
    ];
}

try {
    $user = requirePermission('reports.send', true);
    $data = readJsonBody();
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];

    $recipientEmail = statementEmailValue($data, 'recipient_email', 'Customer email');
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Enter a valid customer email address.', 422);
    }
    $recipientName = statementNullableText($data['recipient_name'] ?? null, 'Recipient name', 120) ?: 'Customer';
    $senderNote = statementNullableText($data['message'] ?? null, 'Message to customer', 1200);

    $statement = reportCustomerStatement($conn, $organizationId, [
        'customer_id' => $data['customer_id'] ?? 0,
        'from' => $data['from'] ?? null,
        'to' => $data['to'] ?? null,
        'currency' => $data['currency'] ?? null,
    ]);

    $customer = $statement['customer'];
    $organization = $statement['organization'];
    $period = $statement['period'];
    $businessName = (string) ($organization['display_name'] ?? $organization['name'] ?? appName());
    $safeCustomerName = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($customer['name'] ?? 'customer')) ?: 'customer';
    $fallbackFilename = 'statement-' . trim($safeCustomerName, '-') . '-' . $period['from'] . '-to-' . $period['to'] . '.pdf';
    $deliveryMode = statementDeliveryMode($data);
    $pdfAttachment = statementPdfAttachmentFromPayload($data, $fallbackFilename);
    $attachments = $pdfAttachment !== null ? [$pdfAttachment] : [];

    $subject = 'Customer statement from ' . $businessName . ' · ' . $period['from'] . ' to ' . $period['to'];
    $htmlBody = customerStatementEmail($statement, $senderNote, $pdfAttachment !== null);
    $delivery = sendTransactionalMail(
        $conn,
        'statement.customer_statement',
        $recipientEmail,
        $recipientName,
        $subject,
        $htmlBody,
        $userId,
        $organizationId,
        null,
        [
            'customer_id' => (int) ($customer['id'] ?? 0),
            'customer_name' => $customer['name'] ?? null,
            'from' => $period['from'],
            'to' => $period['to'],
            'currency' => $statement['currency'],
            'closing_balance' => $statement['summary']['closing_balance'] ?? null,
            'delivery_mode' => $deliveryMode,
            'pdf_attached' => $pdfAttachment !== null,
        ],
        $attachments
    );

    if (!$delivery['success']) {
        throw new Exception('Customer statement email could not be delivered. Check mail settings and try again.', 502);
    }

    auditLog($conn, $userId, 'statement.sent_to_customer', 'Customer statement sent to customer.', $organizationId, json_encode([
        'customer_id' => (int) ($customer['id'] ?? 0),
        'customer_name' => $customer['name'] ?? null,
        'recipient_email' => $recipientEmail,
        'from' => $period['from'],
        'to' => $period['to'],
        'currency' => $statement['currency'],
        'delivery_status' => $delivery['status'],
        'delivery_mode' => $deliveryMode,
        'pdf_attached' => $pdfAttachment !== null,
    ], JSON_UNESCAPED_SLASHES));

    jsonSuccess([
        'message' => $delivery['status'] === 'logged'
            ? ($pdfAttachment !== null ? 'Customer statement email with PDF attachment logged successfully.' : 'Customer statement email logged successfully.')
            : ($pdfAttachment !== null ? 'Customer statement sent with PDF attachment successfully.' : 'Customer statement sent successfully.'),
        'contract_version' => 'customer-statement-v1',
        'data' => ['statement' => $statement, 'delivery' => ['status' => $delivery['status'], 'delivery_id' => $delivery['delivery_id']]],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    $statusCode = ($code >= 400 && $code <= 599) ? $code : 500;
    error_log('Customer statement send failed: ' . $e->getMessage());
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to send customer statement.', $statusCode);
}
