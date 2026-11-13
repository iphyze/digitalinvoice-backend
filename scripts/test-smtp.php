<?php
/**
 * SMTP diagnostic: sends one test email with the app's real mail settings and prints
 * the SMTP conversation, so connection, encryption and login problems are visible.
 *
 * Run on the server (cPanel Terminal or SSH), from the backend folder:
 *   php scripts/test-smtp.php you@example.com
 * Passwords are never printed (PHPMailer masks AUTH data at debug level 2).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../utils/mailer.php';
loadEnvironment();

$to = $argv[1] ?? '';
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/test-smtp.php you@example.com\n");
    exit(1);
}
if (mailTransport() !== 'smtp') {
    echo "MAIL_DRIVER is not 'smtp' (emails are only written to storage/mail.log).\n";
    exit(1);
}

$mail = buildSmtpMailer();
printf("Host %s, port %d, encryption %s, auth %s, timeout %ds\n\n",
    $mail->Host, $mail->Port, $mail->SMTPSecure ?: ($mail->SMTPAutoTLS ? 'auto (STARTTLS if offered)' : 'none'),
    $mail->SMTPAuth ? 'on' : 'off', $mail->Timeout);
$mail->SMTPDebug = 2;
$mail->Debugoutput = static function (string $line): void { echo rtrim($line), "\n"; };
$mail->addAddress($to);
$mail->Subject = 'DigitalInvoice SMTP test';
$mail->Body = 'This is a test email from the DigitalInvoice backend. If you received it, SMTP is working.';

$start = microtime(true);
try {
    $mail->send();
    printf("\nSUCCESS: test email sent in %.1fs. Check the inbox (and spam folder) of %s.\n", microtime(true) - $start, $to);
} catch (Throwable $e) {
    printf("\nFAILED after %.1fs: %s\n", microtime(true) - $start, $mail->ErrorInfo ?: $e->getMessage());
    echo "Hints: port 465 needs SMTP_ENCRYPTION=smtps (default for 465); port 587 needs starttls;\n";
    echo "'Could not authenticate' means SMTP_USERNAME/SMTP_PASSWORD are wrong (use the full email address);\n";
    echo "'Could not connect' means wrong host/port or the host blocks outgoing SMTP (use your cPanel mail server).\n";
    exit(1);
}
