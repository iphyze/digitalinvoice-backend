<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/security.php';

/**
 * Transactional email transport for DigitalInvoice.
 *
 * - MAIL_DRIVER=log keeps mail local for diagnostics only.
 * - MAIL_DRIVER=smtp sends genuine email from localhost or production.
 * - Every attempt is recorded in email_delivery_logs for audit/diagnostics.
 */
function mailTransport(): string
{
    return strtolower(trim((string) config('MAIL_DRIVER', 'log'))) === 'smtp' ? 'smtp' : 'log';
}

function mailTextBody(string $htmlBody): string
{
    $withBreaks = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $htmlBody) ?? $htmlBody;
    $withParagraphs = preg_replace('/<\s*\/p\s*>/i', "\n\n", $withBreaks) ?? $withBreaks;
    return trim(html_entity_decode(strip_tags($withParagraphs), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function mailAttachmentSummary(array $attachments): array
{
    return array_map(static function (array $attachment): array {
        return [
            'filename' => (string) ($attachment['filename'] ?? 'attachment'),
            'mime_type' => (string) ($attachment['mime_type'] ?? 'application/octet-stream'),
            'size_bytes' => strlen((string) ($attachment['content'] ?? '')),
        ];
    }, $attachments);
}

function emailDeliveryAlreadyCompleted(mysqli $conn, string $dedupeKey): bool
{
    $stmt = $conn->prepare("SELECT id FROM email_delivery_logs WHERE dedupe_key = ? AND status IN ('sent','logged') LIMIT 1");
    $stmt->bind_param('s', $dedupeKey);
    $stmt->execute();
    $found = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $found;
}

function createEmailDeliveryLog(
    mysqli $conn,
    string $templateKey,
    string $recipientEmail,
    string $recipientName,
    string $subject,
    string $transport,
    ?int $userId,
    ?int $organizationId,
    ?string $dedupeKey,
    array $metadata
): int {
    $status = 'queued';
    $metadataJson = $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $stmt = $conn->prepare(
        'INSERT INTO email_delivery_logs '
        . '(organization_id, user_id, template_key, dedupe_key, recipient_email, recipient_name, subject, transport, status, metadata) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'iissssssss',
        $organizationId,
        $userId,
        $templateKey,
        $dedupeKey,
        $recipientEmail,
        $recipientName,
        $subject,
        $transport,
        $status,
        $metadataJson
    );
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();
    return $id;
}

function updateEmailDeliveryLog(mysqli $conn, int $id, string $status, ?string $error = null): void
{
    $trimmedError = $error !== null ? mb_substr($error, 0, 500) : null;
    $stmt = $conn->prepare(
        'UPDATE email_delivery_logs SET status = ?, error_message = ?, sent_at = CASE WHEN ? IN (\'sent\', \'logged\') THEN NOW() ELSE sent_at END, updated_at = NOW() WHERE id = ?'
    );
    $stmt->bind_param('sssi', $status, $trimmedError, $status, $id);
    $stmt->execute();
    $stmt->close();
}

function buildSmtpMailer(): PHPMailer
{
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->SMTPAuth = configBool('SMTP_AUTH', true);
    $mail->Host = requiredConfig('SMTP_HOST');
    $mail->Port = (int) (config('SMTP_PORT', '465') ?? '465');
    $mail->Username = requiredConfig('SMTP_USERNAME');
    $mail->Password = requiredConfig('SMTP_PASSWORD');


    // Encryption must match the port, or PHPMailer and the server wait on each other
    // until the timeout (port 465 expects TLS from the first byte). This was disabled
    // before, which made password-reset requests hang for a minute and never deliver.
    // SMTP_ENCRYPTION overrides: smtps|ssl, starttls|tls, none. Default follows the port.
    $encryption = strtolower(trim((string) (config('SMTP_ENCRYPTION', '') ?? '')));
    if ($encryption === '') {
        $encryption = match ($mail->Port) {
            465 => 'smtps',
            587 => 'starttls',
            default => 'auto',
        };
    }
    if (in_array($encryption, ['smtps', 'ssl'], true)) {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->SMTPAutoTLS = false;
    } elseif (in_array($encryption, ['starttls', 'tls'], true)) {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAutoTLS = true;
    } elseif (in_array($encryption, ['none', 'off', 'false'], true)) {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    } else {
        // 'auto': plain connection, upgraded with STARTTLS when the server offers it.
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = true;
    }

    // Keep well below typical shared-hosting request limits so a mail problem fails fast.
    $mail->Timeout = min(60, max(5, (int) (config('SMTP_TIMEOUT', '10') ?? '10')));
    $mail->CharSet = PHPMailer::CHARSET_UTF8;
    $mail->Encoding = PHPMailer::ENCODING_BASE64;
    // Send as the authenticated mailbox's domain; never a domain we don't own (that gets
    // rejected or marked as spam). MAIL_FROM_ADDRESS, falling back to SMTP_USERNAME.
    $from = firstConfiguredEmail('MAIL_FROM_ADDRESS', 'SMTP_USERNAME');
    if ($from === null) {
        throw new RuntimeException('Set MAIL_FROM_ADDRESS (or an email address as SMTP_USERNAME) to send email.');
    }
    $mail->setFrom($from, config('MAIL_FROM_NAME', appName()) ?? appName());

    $replyTo = trim((string) config('MAIL_REPLY_TO_ADDRESS', ''));
    if ($replyTo !== '') {
        $mail->addReplyTo($replyTo, config('MAIL_REPLY_TO_NAME', appName()) ?? appName());
    }

    if (!configBool('SMTP_VERIFY_PEER', true)) {
        // Local debugging escape hatch only. Keep SMTP_VERIFY_PEER=true in production.
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ];
    }

    return $mail;
}

/**
 * @return array{success: bool, status: string, delivery_id: ?int}
 */
function sendTransactionalMail(
    mysqli $conn,
    string $templateKey,
    string $to,
    string $toName,
    string $subject,
    string $htmlBody,
    ?int $userId = null,
    ?int $organizationId = null,
    ?string $dedupeKey = null,
    array $metadata = [],
    array $attachments = []
): array {
    if ($dedupeKey !== null && emailDeliveryAlreadyCompleted($conn, $dedupeKey)) {
        return ['success' => true, 'status' => 'already_sent', 'delivery_id' => null];
    }

    $transport = mailTransport();
    if ($attachments !== []) {
        $metadata['attachments'] = mailAttachmentSummary($attachments);
    }
    $deliveryId = createEmailDeliveryLog($conn, $templateKey, $to, $toName, $subject, $transport, $userId, $organizationId, $dedupeKey, $metadata);

    if ($transport === 'log') {
        $directory = dirname(__DIR__) . '/storage';
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $attachmentLine = $attachments !== []
            ? ' | ATTACHMENTS: ' . implode(', ', array_map(static fn(array $attachment): string => (string) ($attachment['filename'] ?? 'attachment'), $attachments))
            : '';
        $entry = sprintf(
            "[%s] TEMPLATE: %s | TO: %s <%s> | SUBJECT: %s%s\n%s\n\n",
            date('c'),
            $templateKey,
            $toName,
            $to,
            $subject,
            $attachmentLine,
            mailTextBody($htmlBody)
        );
        file_put_contents($directory . '/mail.log', $entry, FILE_APPEND | LOCK_EX);
        updateEmailDeliveryLog($conn, $deliveryId, 'logged');
        return ['success' => true, 'status' => 'logged', 'delivery_id' => $deliveryId];
    }

    try {
        $mail = buildSmtpMailer();
        $mail->addAddress($to, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = mailTextBody($htmlBody);
        foreach ($attachments as $attachment) {
            $content = (string) ($attachment['content'] ?? '');
            $filename = (string) ($attachment['filename'] ?? 'attachment');
            $mimeType = (string) ($attachment['mime_type'] ?? 'application/octet-stream');
            if ($content !== '' && $filename !== '') {
                $mail->addStringAttachment($content, $filename, PHPMailer::ENCODING_BASE64, $mimeType);
            }
        }
        $mail->send();
        updateEmailDeliveryLog($conn, $deliveryId, 'sent');
        return ['success' => true, 'status' => 'sent', 'delivery_id' => $deliveryId];
    } catch (MailException | RuntimeException $error) {
        error_log('DigitalInvoice mail delivery error [' . $templateKey . ']: ' . $error->getMessage());
        updateEmailDeliveryLog($conn, $deliveryId, 'failed', $error->getMessage());
        return ['success' => false, 'status' => 'failed', 'delivery_id' => $deliveryId];
    }
}


/**
 * Send a JSON response to the client now and keep running (PHP-FPM / LiteSpeed),
 * so slow work such as SMTP delivery doesn't keep the user waiting.
 * Returns false when the server can't finish early; the caller then responds normally.
 */
function respondJsonAndContinue(array $payload, int $statusCode = 200): bool
{
    $finish = function_exists('fastcgi_finish_request') ? 'fastcgi_finish_request'
        : (function_exists('litespeed_finish_request') ? 'litespeed_finish_request' : null);
    if ($finish === null || headers_sent()) {
        return false;
    }
    ignore_user_abort(true);
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    $finish();
    return true;
}
