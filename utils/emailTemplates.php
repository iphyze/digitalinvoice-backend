<?php

declare(strict_types=1);

function mailEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mailBrandLogo(): string
{
    return trim((string) config('MAIL_BRAND_LOGO_URL', ''));
}

function mailSupportEmail(): string
{
    return defaultSupportEmail();
}

function mailWebsiteUrl(): string
{
    return trim((string) config('MAIL_WEBSITE_URL', 'https://digitalinvoiceng.com'));
}

function emailButton(string $label, string $url): string
{
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:30px 0 28px;"><tr><td style="border-radius:16px;background:#15c9a7;background-image:linear-gradient(135deg,#15c9a7,#1e9be7);box-shadow:0 14px 30px rgba(30,155,231,0.22);">'
        . '<a href="' . mailEscape($url) . '" style="display:inline-block;padding:15px 26px;color:#ffffff;font-size:14px;font-weight:800;line-height:20px;text-decoration:none;border-radius:16px;letter-spacing:-0.1px;">'
        . mailEscape($label) . '&nbsp;&nbsp;→</a></td></tr></table>';
}

function emailLinkFallback(string $url): string
{
    return '<p style="margin:24px 0 0;color:#6b7d90;font-size:12px;line-height:20px;">If the button does not work, copy and paste this link into your browser:<br>'
        . '<a href="' . mailEscape($url) . '" style="color:#1e9be7;word-break:break-all;text-decoration:none;font-weight:700;">' . mailEscape($url) . '</a></p>';
}

function emailShell(string $preheader, string $title, string $content): string
{
    $brand = mailEscape(appName());
    $logoUrl = mailBrandLogo();
    $support = mailEscape(mailSupportEmail());
    $website = mailEscape(mailWebsiteUrl());
    $fontStack = "Inter, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    $logo = $logoUrl !== ''
        ? '<img src="' . mailEscape($logoUrl) . '" alt="' . $brand . '" width="190" style="display:block;width:190px;max-width:100%;height:auto;border:0;border-radius:12px;">'
        : '<span style="display:inline-block;font-size:24px;font-weight:900;letter-spacing:-0.9px;color:#0f2742;line-height:28px;">Digital<span style="color:#17c8a6;">Invoice</span><span style="font-size:12px;color:#7890a6;vertical-align:top;margin-left:3px;">NG</span></span>';

    return '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
        . mailEscape($title) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#eef4f9;font-family:' . $fontStack . ';color:#0f2742;-webkit-font-smoothing:antialiased;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . mailEscape($preheader) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef4f9;margin:0;padding:0;width:100%;"><tr><td align="center" style="padding:38px 16px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;max-width:660px;">'
        . '<tr><td style="padding:0 4px 18px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
        . '<td align="left" style="vertical-align:middle;">' . $logo . '</td>'
        . '<td align="right" style="vertical-align:middle;color:#6b7d90;font-size:12px;line-height:18px;font-weight:700;">Secure notification</td>'
        . '</tr></table></td></tr>'
        . '<tr><td style="background:#ffffff;border-radius:28px;overflow:hidden;border:1px solid #dfe9f2;box-shadow:0 26px 70px rgba(15,39,66,0.10);">'
        . '<div style="padding:0;background:#0f2742;background-image:linear-gradient(135deg,#0f2742,#123656 48%,#1e9be7);">'
        . '<div style="padding:22px 34px;color:#ffffff;">'
        . '<div style="display:inline-block;padding:7px 11px;border-radius:999px;background:rgba(255,255,255,0.13);color:#dff9f4;font-size:11px;letter-spacing:1.5px;text-transform:uppercase;font-weight:900;">DigitalInvoice</div>'
        . '<h1 style="margin:18px 0 0;color:#ffffff;font-size:29px;line-height:38px;letter-spacing:-0.8px;font-weight:900;">' . mailEscape($title) . '</h1>'
        . '</div></div>'
        . '<div style="padding:34px 38px 36px;background:#ffffff;">'
        . $content
        . '</div></td></tr>'
        . '<tr><td style="padding:25px 22px 0;text-align:center;color:#6b7d90;font-size:12px;line-height:20px;">'
        . '<p style="margin:0 0 8px;font-weight:700;color:#52677c;">Smart invoicing, clean reporting and compliance-ready records.</p>'
        . '<p style="margin:0;"><a href="' . $website . '" style="color:#1e9be7;text-decoration:none;font-weight:800;">' . $website . '</a> &nbsp;•&nbsp; <a href="mailto:' . $support . '" style="color:#1e9be7;text-decoration:none;font-weight:800;">' . $support . '</a></p>'
        . '<p style="margin:14px 0 0;color:#8ba0b2;">© ' . date('Y') . ' ' . $brand . '. This is an automated transactional message.</p>'
        . '</td></tr></table></td></tr></table></body></html>';
}

function emailSoftPanel(string $content, string $tone = 'blue'): string
{
    $styles = [
        'blue' => ['bg' => '#f3f8fc', 'border' => '#dfeaf4', 'text' => '#314a63'],
        'green' => ['bg' => '#effbf8', 'border' => '#ccefe8', 'text' => '#234f49'],
        'amber' => ['bg' => '#fff8e8', 'border' => '#f3dfb5', 'text' => '#5c4321'],
    ];
    $style = $styles[$tone] ?? $styles['blue'];
    return '<div style="margin:22px 0 0;padding:16px 17px;border-radius:16px;background:' . $style['bg'] . ';border:1px solid ' . $style['border'] . ';color:' . $style['text'] . ';font-size:13px;line-height:22px;">' . $content . '</div>';
}

function verificationEmail(string $name, string $verificationUrl): string
{
    return emailShell(
        'Verify your email to activate your DigitalInvoice account.',
        'Verify your email address',
        '<p style="margin:0 0 15px;color:#364d63;font-size:15px;line-height:25px;">Hello <strong>' . mailEscape($name) . '</strong>,</p>'
        . '<p style="margin:0;color:#364d63;font-size:15px;line-height:25px;">Welcome to DigitalInvoice. Verify your email address to secure your new business workspace and proceed to subscription activation.</p>'
        . emailButton('Verify email address', $verificationUrl)
        . '<p style="margin:0;color:#60748a;font-size:13px;line-height:21px;"><strong>This link expires in 24 hours.</strong> If you did not create this account, you can safely ignore this message.</p>'
        . emailLinkFallback($verificationUrl)
    );
}

function verifiedAccountEmail(string $name, string $plansUrl): string
{
    return emailShell(
        'Your email is verified. Select a DigitalInvoice plan to continue.',
        'Your account is verified',
        '<p style="margin:0 0 15px;color:#364d63;font-size:15px;line-height:25px;">Hello <strong>' . mailEscape($name) . '</strong>,</p>'
        . '<p style="margin:0;color:#364d63;font-size:15px;line-height:25px;">Your email has been successfully verified. Choose a subscription plan to activate invoicing access for your business workspace.</p>'
        . emailButton('Select a plan', $plansUrl)
        . '<p style="margin:0;color:#60748a;font-size:13px;line-height:21px;">Your invoices remain private and protected by your secure account session.</p>'
    );
}

function googleWelcomeEmail(string $name, string $organizationName, string $plansUrl): string
{
    return emailShell(
        'Your DigitalInvoice account has been created using Google Sign-In.',
        'Welcome to DigitalInvoice',
        '<p style="margin:0 0 15px;color:#364d63;font-size:15px;line-height:25px;">Hello <strong>' . mailEscape($name) . '</strong>,</p>'
        . '<p style="margin:0;color:#364d63;font-size:15px;line-height:25px;">Your secure workspace for <strong>' . mailEscape($organizationName) . '</strong> has been created with Google Sign-In. Select a plan to activate your invoicing experience.</p>'
        . emailButton('Choose subscription plan', $plansUrl)
        . '<p style="margin:0;color:#60748a;font-size:13px;line-height:21px;">Sign-in alerts and critical account activity will be sent to this email address.</p>'
    );
}

function googleAccountLinkedEmail(string $name, string $loginUrl): string
{
    return emailShell(
        'Google Sign-In has been connected to your DigitalInvoice account.',
        'Google Sign-In connected',
        '<p style="margin:0 0 15px;color:#364d63;font-size:15px;line-height:25px;">Hello <strong>' . mailEscape($name) . '</strong>,</p>'
        . '<p style="margin:0;color:#364d63;font-size:15px;line-height:25px;">Your verified Google account has been securely connected to your existing DigitalInvoice account. You can now use Google Sign-In for faster access to your workspace.</p>'
        . emailButton('Open secure sign-in', $loginUrl)
        . '<p style="margin:0;color:#60748a;font-size:13px;line-height:21px;">If you did not connect Google Sign-In, please change your password and contact support immediately.</p>'
    );
}

function passwordResetEmail(string $name, string $resetUrl): string
{
    return emailShell(
        'Use this secure link to reset your DigitalInvoice password.',
        'Reset your password',
        '<p style="margin:0 0 15px;color:#364d63;font-size:15px;line-height:25px;">Hello <strong>' . mailEscape($name) . '</strong>,</p>'
        . '<p style="margin:0;color:#364d63;font-size:15px;line-height:25px;">We received a request to reset the password for your DigitalInvoice account. Use the secure button below to set a new password.</p>'
        . emailButton('Reset password', $resetUrl)
        . '<p style="margin:0;color:#60748a;font-size:13px;line-height:21px;"><strong>This link expires in 30 minutes.</strong> If you did not request this reset, no action is required and your current password remains valid.</p>'
        . emailLinkFallback($resetUrl)
    );
}

function passwordChangedEmail(string $name, string $loginUrl): string
{
    return emailShell(
        'Your DigitalInvoice password was changed successfully.',
        'Password changed successfully',
        '<p style="margin:0 0 15px;color:#364d63;font-size:15px;line-height:25px;">Hello <strong>' . mailEscape($name) . '</strong>,</p>'
        . '<p style="margin:0;color:#364d63;font-size:15px;line-height:25px;">Your password was updated successfully. Existing sign-in sessions have been closed for your security.</p>'
        . emailButton('Sign in securely', $loginUrl)
        . '<p style="margin:0;color:#60748a;font-size:13px;line-height:21px;">If you did not make this change, please contact support immediately.</p>'
    );
}

function teamInvitationEmail(string $name, string $organizationName, string $role, string $invitationUrl): string
{
    $displayRole = ucwords(str_replace('_', ' ', $role));
    return emailShell(
        'You have been invited to a DigitalInvoice workspace.',
        'You are invited to collaborate',
        '<p style="margin:0 0 15px;color:#364d63;font-size:15px;line-height:25px;">Hello <strong>' . mailEscape($name) . '</strong>,</p>'
        . '<p style="margin:0;color:#364d63;font-size:15px;line-height:25px;"><strong>' . mailEscape($organizationName) . '</strong> invited you to join its DigitalInvoice workspace as <strong>' . mailEscape($displayRole) . '</strong>.</p>'
        . emailButton('Accept invitation', $invitationUrl)
        . '<p style="margin:0;color:#60748a;font-size:13px;line-height:21px;">This invitation expires automatically. Ignore this email if you were not expecting access to this organisation.</p>'
        . emailLinkFallback($invitationUrl)
    );
}

function subscriptionActivatedEmail(string $name, string $organizationName, string $onboardingUrl): string
{
    return emailShell(
        'Payment confirmed. Your DigitalInvoice subscription is active.',
        'Your workspace is now active',
        '<p style="margin:0 0 15px;color:#364d63;font-size:15px;line-height:25px;">Hello <strong>' . mailEscape($name) . '</strong>,</p>'
        . '<p style="margin:0;color:#364d63;font-size:15px;line-height:25px;">Payment has been confirmed and <strong>' . mailEscape($organizationName) . '</strong> now has active DigitalInvoice access. Complete your business setup to begin issuing invoices.</p>'
        . emailButton('Complete business setup', $onboardingUrl)
        . '<p style="margin:0;color:#60748a;font-size:13px;line-height:21px;">Invoices will remain marked as not submitted to NRS until regulatory integration is enabled for your workspace.</p>'
    );
}

function demoRequestConfirmationEmail(string $name, string $companyName): string
{
    return emailShell(
        'We received your DigitalInvoice demo request.',
        'Your demo request is received',
        '<p style="margin:0 0 15px;color:#364d63;font-size:15px;line-height:25px;">Hello <strong>' . mailEscape($name) . '</strong>,</p>'
        . '<p style="margin:0;color:#364d63;font-size:15px;line-height:25px;">Thank you for your interest in DigitalInvoice for <strong>' . mailEscape($companyName) . '</strong>. Our team will review your request and contact you shortly.</p>'
        . '<div style="margin:26px 0;padding:17px 18px;border-radius:13px;background:#f2f8fd;color:#364d63;font-size:14px;line-height:22px;">We can walk you through invoice creation, payment tracking, reporting and your compliance-readiness workflow.</div>'
        . '<p style="margin:0;color:#60748a;font-size:13px;line-height:21px;">You can reply to this email if there is anything specific you would like covered during the demonstration.</p>'
    );
}

function demoRequestNotificationEmail(string $name, string $email, string $companyName, string $phone, string $preferredDate, string $message): string
{
    return emailShell(
        'A new DigitalInvoice demo request has been submitted.',
        'New demo request',
        '<p style="margin:0 0 18px;color:#364d63;font-size:15px;line-height:25px;">A new prospect has requested a DigitalInvoice demonstration.</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f8fb;border-radius:12px;padding:14px 16px;color:#364d63;font-size:14px;line-height:23px;">'
        . '<tr><td style="padding:3px 0;font-weight:700;width:130px;">Name</td><td>' . mailEscape($name) . '</td></tr>'
        . '<tr><td style="padding:3px 0;font-weight:700;">Company</td><td>' . mailEscape($companyName) . '</td></tr>'
        . '<tr><td style="padding:3px 0;font-weight:700;">Email</td><td>' . mailEscape($email) . '</td></tr>'
        . '<tr><td style="padding:3px 0;font-weight:700;">Phone</td><td>' . mailEscape($phone ?: 'Not provided') . '</td></tr>'
        . '<tr><td style="padding:3px 0;font-weight:700;">Preferred date</td><td>' . mailEscape($preferredDate ?: 'Not provided') . '</td></tr></table>'
        . ($message !== '' ? '<p style="margin:22px 0 0;color:#364d63;font-size:14px;line-height:23px;"><strong>Message:</strong><br>' . nl2br(mailEscape($message)) . '</p>' : '')
    );
}


function customerPortalSecureLinkEmail(string $name, string $businessName, string $documentLabel, string $reference, string $portalUrl, string $expiresAt): string
{
    $safeLabel = trim($documentLabel) !== '' ? $documentLabel : 'Document';
    $safeReference = trim($reference) !== '' ? $reference : 'customer document';
    $expiry = mailDateValue($expiresAt);

    return emailShell(
        $businessName . ' shared a secure customer portal link with you.',
        'Secure ' . $safeLabel . ' link',
        '<p style="margin:0 0 15px;color:#364d63;font-size:15px;line-height:25px;">Hello <strong>' . mailEscape($name) . '</strong>,</p>'
        . '<p style="margin:0;color:#364d63;font-size:15px;line-height:25px;"><strong>' . mailEscape($businessName) . '</strong> shared ' . mailEscape(strtolower($safeLabel)) . ' <strong>' . mailEscape($safeReference) . '</strong> with you through DigitalInvoice\'s secure customer portal.</p>'
        . emailButton('Open secure document', $portalUrl)
        . emailSoftPanel('<strong>Security note:</strong> This link expires on ' . mailEscape($expiry) . '. Please do not forward it unless you trust the recipient.', 'blue')
        . '<p style="margin:22px 0 0;color:#60748a;font-size:13px;line-height:21px;">You can view the shared document without signing in. If you were not expecting this message, please ignore it or contact ' . mailEscape($businessName) . ' directly.</p>'
        . emailLinkFallback($portalUrl)
    );
}

function mailMoney(mixed $amount, string $currency = 'NGN'): string
{
    return strtoupper($currency) . ' ' . number_format((float) $amount, 2);
}

function mailDateValue(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '—';
    }
    $timestamp = strtotime($value);
    return $timestamp ? date('M j, Y', $timestamp) : $value;
}

function invoiceCustomerEmail(array $invoice, array $organization, ?string $senderNote = null, bool $hasPdfAttachment = false): string
{
    $invoiceNumber = (string) ($invoice['invoice_number'] ?: ('Invoice #' . ($invoice['id'] ?? '')));
    $businessName = (string) ($organization['trading_name'] ?: $organization['name']);
    $currency = (string) ($invoice['currency'] ?? 'NGN');
    $status = str_replace('_', ' ', strtoupper((string) ($invoice['payment_status'] ?? 'unpaid')));
    $items = is_array($invoice['items'] ?? null) ? $invoice['items'] : [];

    $rows = '';
    foreach ($items as $item) {
        $description = trim((string) ($item['item_description_snapshot'] ?? ''));
        $rows .= '<tr>'
            . '<td style="padding:12px 10px;border-bottom:1px solid #e8eef5;vertical-align:top;">'
            . '<strong style="display:block;color:#10263d;font-size:13px;line-height:18px;">' . mailEscape($item['item_name_snapshot'] ?? 'Invoice item') . '</strong>'
            . ($description !== '' ? '<span style="display:block;margin-top:3px;color:#60748a;font-size:12px;line-height:18px;">' . mailEscape($description) . '</span>' : '')
            . '</td>'
            . '<td align="right" style="padding:12px 10px;border-bottom:1px solid #e8eef5;color:#364d63;font-size:13px;white-space:nowrap;">' . mailEscape((string) ($item['quantity'] ?? '1')) . ' ' . mailEscape((string) ($item['unit_code_snapshot'] ?? '')) . '</td>'
            . '<td align="right" style="padding:12px 10px;border-bottom:1px solid #e8eef5;color:#364d63;font-size:13px;white-space:nowrap;">' . mailEscape(mailMoney($item['total_amount'] ?? 0, $currency)) . '</td>'
            . '</tr>';
    }

    $bankBlock = '';
    if (!empty($invoice['bank_name_snapshot']) || !empty($invoice['bank_account_number_snapshot'])) {
        $bankBlock = '<div style="margin:22px 0 0;padding:16px 17px;border-radius:14px;background:#f3f8fc;border:1px solid #e3edf6;color:#364d63;font-size:13px;line-height:22px;">'
            . '<strong style="display:block;margin-bottom:6px;color:#10263d;">Payment account</strong>'
            . '<div>Bank: ' . mailEscape($invoice['bank_name_snapshot'] ?? '—') . '</div>'
            . '<div>Account name: ' . mailEscape($invoice['bank_account_name_snapshot'] ?? '—') . '</div>'
            . '<div>Account number: ' . mailEscape($invoice['bank_account_number_snapshot'] ?? '—') . '</div>'
            . '</div>';
    }

    $noteBlock = $senderNote !== null && trim($senderNote) !== ''
        ? '<div style="margin:20px 0 0;padding:15px 16px;border-radius:14px;background:#fff8e8;border:1px solid #f3dfb5;color:#5c4321;font-size:13px;line-height:22px;"><strong>Message from ' . mailEscape($businessName) . ':</strong><br>' . nl2br(mailEscape($senderNote)) . '</div>'
        : '';

    $instructions = trim((string) ($invoice['payment_instructions'] ?? ''));
    $paymentInstructions = $instructions !== ''
        ? '<p style="margin:15px 0 0;color:#364d63;font-size:13px;line-height:22px;"><strong>Payment instructions:</strong><br>' . nl2br(mailEscape($instructions)) . '</p>'
        : '';

    $attachmentNotice = $hasPdfAttachment
        ? '<div style="margin:20px 0 0;padding:14px 15px;border-radius:14px;background:#eefbf8;border:1px solid #ccefe8;color:#234f49;font-size:13px;line-height:22px;"><strong>PDF attached</strong><br>A PDF copy of this invoice is attached to this email for your records.</div>'
        : '<div style="margin:20px 0 0;padding:14px 15px;border-radius:14px;background:#f3f8fc;border:1px solid #e3edf6;color:#364d63;font-size:13px;line-height:22px;"><strong>Invoice summary</strong><br>This email includes the invoice summary and payment instructions below.</div>';

    $businessAddress = trim((string) ($organization['address'] ?? ''));
    $businessMeta = array_filter([
        $businessAddress,
        trim((string) ($organization['business_email'] ?? '')),
        trim((string) ($organization['phone'] ?? '')),
        trim((string) ($organization['tax_identification_number'] ?? '')) !== '' ? 'TIN: ' . trim((string) $organization['tax_identification_number']) : '',
    ], static fn(string $part): bool => $part !== '');

    return emailShell(
        'Invoice ' . $invoiceNumber . ' from ' . $businessName . ' is ready.',
        'Invoice ' . $invoiceNumber,
        '<p style="margin:0 0 15px;color:#364d63;font-size:15px;line-height:25px;">Hello <strong>' . mailEscape($invoice['customer_name_snapshot'] ?? 'Customer') . '</strong>,</p>'
        . '<p style="margin:0;color:#364d63;font-size:15px;line-height:25px;">Please find your invoice from <strong>' . mailEscape($businessName) . '</strong> below.</p>'
        . $noteBlock
        . $attachmentNotice
        . '<div style="margin:24px 0 0;border-radius:18px;border:1px solid #e4edf6;overflow:hidden;background:#ffffff;">'
        . '<div style="padding:19px 20px;background:#10263d;background-image:linear-gradient(135deg,#10263d,#1686dd);color:#ffffff;">'
        . '<div style="font-size:12px;opacity:.8;text-transform:uppercase;letter-spacing:1px;">Tax invoice</div>'
        . '<div style="margin-top:5px;font-size:24px;line-height:30px;font-weight:800;">' . mailEscape($invoiceNumber) . '</div>'
        . '<div style="margin-top:9px;font-size:13px;line-height:20px;opacity:.88;">' . mailEscape(implode(' • ', $businessMeta)) . '</div>'
        . '</div>'
        . '<div style="padding:17px 20px;display:block;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;font-size:13px;color:#364d63;line-height:22px;"><tr>'
        . '<td style="padding:0 0 9px;"><strong>Invoice date</strong><br>' . mailEscape(mailDateValue($invoice['invoice_date'] ?? null)) . '</td>'
        . '<td align="right" style="padding:0 0 9px;"><strong>Due date</strong><br>' . mailEscape(mailDateValue($invoice['due_date'] ?? null)) . '</td>'
        . '</tr><tr>'
        . '<td style="padding:0;"><strong>Status</strong><br>' . mailEscape($status) . '</td>'
        . '<td align="right" style="padding:0;"><strong>Total due</strong><br><span style="font-size:18px;color:#0eb894;font-weight:800;">' . mailEscape(mailMoney($invoice['balance_due'] ?? $invoice['total_amount'] ?? 0, $currency)) . '</span></td>'
        . '</tr></table>'
        . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;">'
        . '<thead><tr style="background:#f4f8fc;color:#60748a;font-size:11px;text-transform:uppercase;letter-spacing:.7px;"><th align="left" style="padding:11px 10px;">Item</th><th align="right" style="padding:11px 10px;">Qty</th><th align="right" style="padding:11px 10px;">Total</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>'
        . '<div style="padding:17px 20px;background:#fbfdff;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;font-size:13px;color:#364d63;line-height:24px;">'
        . '<tr><td>Subtotal</td><td align="right">' . mailEscape(mailMoney($invoice['subtotal_amount'] ?? 0, $currency)) . '</td></tr>'
        . '<tr><td>Discounts</td><td align="right">-' . mailEscape(mailMoney(((float) ($invoice['line_discount_amount'] ?? 0)) + ((float) ($invoice['invoice_discount_amount'] ?? 0)), $currency)) . '</td></tr>'
        . '<tr><td>VAT / Tax</td><td align="right">' . mailEscape(mailMoney($invoice['tax_amount'] ?? 0, $currency)) . '</td></tr>'
        . '<tr><td style="padding-top:9px;border-top:1px solid #e4edf6;color:#10263d;font-weight:800;">Total</td><td align="right" style="padding-top:9px;border-top:1px solid #e4edf6;color:#10263d;font-weight:800;">' . mailEscape(mailMoney($invoice['total_amount'] ?? 0, $currency)) . '</td></tr>'
        . '</table>'
        . '</div></div>'
        . $bankBlock
        . $paymentInstructions
        . '<p style="margin:22px 0 0;color:#60748a;font-size:12px;line-height:20px;">This invoice is generated by DigitalInvoice. NRS/FIRS live submission will be reflected once regulatory integration is enabled for this workspace.</p>'
    );
}

function customerStatementEmail(array $statement, ?string $senderNote = null, bool $hasPdfAttachment = false): string
{
    $customer = is_array($statement['customer'] ?? null) ? $statement['customer'] : [];
    $organization = is_array($statement['organization'] ?? null) ? $statement['organization'] : [];
    $period = is_array($statement['period'] ?? null) ? $statement['period'] : [];
    $summary = is_array($statement['summary'] ?? null) ? $statement['summary'] : [];
    $transactions = is_array($statement['transactions'] ?? null) ? $statement['transactions'] : [];
    $currency = (string) ($statement['currency'] ?? 'NGN');
    $businessName = (string) (($organization['display_name'] ?? '') ?: ($organization['trading_name'] ?? '') ?: ($organization['name'] ?? appName()));
    $customerName = (string) ($customer['name'] ?? 'Customer');
    $from = (string) ($period['from'] ?? '');
    $to = (string) ($period['to'] ?? '');

    $noteBlock = $senderNote !== null && trim($senderNote) !== ''
        ? emailSoftPanel('<strong style="display:block;margin-bottom:5px;color:#5c4321;">Message from ' . mailEscape($businessName) . '</strong>' . nl2br(mailEscape($senderNote)), 'amber')
        : '';

    $attachmentNotice = $hasPdfAttachment
        ? emailSoftPanel('<strong style="display:block;margin-bottom:5px;color:#234f49;">PDF statement attached</strong>A polished PDF copy of this customer statement is attached for your records.', 'green')
        : emailSoftPanel('<strong style="display:block;margin-bottom:5px;color:#314a63;">Statement summary</strong>This email contains the statement summary and recent activity for the selected period.', 'blue');

    $transactionRows = '';
    $visibleTransactions = array_slice($transactions, 0, 8);
    foreach ($visibleTransactions as $transaction) {
        $isPayment = ($transaction['type'] ?? '') === 'payment';
        $amount = $isPayment ? (float) ($transaction['credit'] ?? 0) : (float) ($transaction['debit'] ?? 0);
        $transactionRows .= '<tr>'
            . '<td style="padding:12px 10px;border-bottom:1px solid #e8eef5;color:#314a63;font-size:13px;line-height:18px;">'
            . '<strong style="display:block;color:#10263d;">' . mailEscape($transaction['reference'] ?? 'Transaction') . '</strong>'
            . '<span style="color:#6b7d90;">' . mailEscape(mailDateValue($transaction['transaction_date'] ?? null)) . ' · ' . mailEscape($transaction['description'] ?? '') . '</span>'
            . '</td>'
            . '<td align="right" style="padding:12px 10px;border-bottom:1px solid #e8eef5;color:' . ($isPayment ? '#0eb894' : '#10263d') . ';font-size:13px;font-weight:800;white-space:nowrap;">' . ($isPayment ? '-' : '') . mailEscape(mailMoney($amount, $currency)) . '</td>'
            . '</tr>';
    }
    if ($transactionRows === '') {
        $transactionRows = '<tr><td colspan="2" style="padding:18px 10px;color:#6b7d90;font-size:13px;line-height:20px;text-align:center;">No transactions were recorded in this statement period.</td></tr>';
    }

    $businessMeta = array_filter([
        trim((string) ($organization['address'] ?? '')),
        trim((string) ($organization['business_email'] ?? '')),
        trim((string) ($organization['phone'] ?? '')),
    ], static fn(string $part): bool => $part !== '');

    return emailShell(
        'Customer statement from ' . $businessName . ' for ' . $customerName . '.',
        'Customer statement',
        '<p style="margin:0 0 15px;color:#314a63;font-size:15px;line-height:25px;">Hello <strong>' . mailEscape($customerName) . '</strong>,</p>'
        . '<p style="margin:0;color:#314a63;font-size:15px;line-height:25px;">Please find your account statement from <strong>' . mailEscape($businessName) . '</strong> for <strong>' . mailEscape(mailDateValue($from)) . ' to ' . mailEscape(mailDateValue($to)) . '</strong>.</p>'
        . $noteBlock
        . $attachmentNotice
        . '<div style="margin:24px 0 0;border-radius:20px;border:1px solid #e1ebf4;overflow:hidden;background:#ffffff;">'
        . '<div style="padding:20px 21px;background:#0f2742;background-image:linear-gradient(135deg,#0f2742,#123656 50%,#15c9a7);color:#ffffff;">'
        . '<div style="font-size:11px;opacity:.78;text-transform:uppercase;letter-spacing:1.5px;font-weight:900;">Statement balance</div>'
        . '<div style="margin-top:7px;font-size:26px;line-height:32px;font-weight:900;letter-spacing:-0.5px;">' . mailEscape(mailMoney($summary['closing_balance'] ?? 0, $currency)) . '</div>'
        . '<div style="margin-top:8px;font-size:13px;line-height:20px;opacity:.9;">' . mailEscape(implode(' • ', $businessMeta)) . '</div>'
        . '</div>'
        . '<div style="padding:18px 20px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;font-size:13px;color:#314a63;line-height:24px;">'
        . '<tr><td>Opening balance</td><td align="right">' . mailEscape(mailMoney($summary['opening_balance'] ?? 0, $currency)) . '</td></tr>'
        . '<tr><td>Invoices in period</td><td align="right">' . mailEscape(mailMoney($summary['period_invoice_amount'] ?? 0, $currency)) . '</td></tr>'
        . '<tr><td>Payments in period</td><td align="right">-' . mailEscape(mailMoney($summary['period_payment_amount'] ?? 0, $currency)) . '</td></tr>'
        . '<tr><td style="padding-top:10px;border-top:1px solid #e4edf6;color:#10263d;font-weight:900;">Closing balance</td><td align="right" style="padding-top:10px;border-top:1px solid #e4edf6;color:#10263d;font-weight:900;">' . mailEscape(mailMoney($summary['closing_balance'] ?? 0, $currency)) . '</td></tr>'
        . '</table></div></div>'
        . '<div style="margin:20px 0 0;border-radius:18px;border:1px solid #e4edf6;overflow:hidden;background:#ffffff;">'
        . '<div style="padding:13px 16px;background:#f4f8fc;color:#6b7d90;font-size:11px;text-transform:uppercase;letter-spacing:.9px;font-weight:900;">Recent statement activity</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;">'
        . '<tbody>' . $transactionRows . '</tbody></table></div>'
        . '<p style="margin:20px 0 0;color:#6b7d90;font-size:12px;line-height:20px;">This statement is generated from approved invoices and non-void customer payments recorded in DigitalInvoice.</p>'
    );
}


function premiumRequestConfirmationEmail(string $name, string $businessName): string
{
    return emailShell(
        'We received your DigitalInvoice Premium request.',
        'Premium request received',
        '<p style="margin:0 0 15px;color:#364d63;font-size:15px;line-height:25px;">Hello <strong>' . mailEscape($name) . '</strong>,</p>'
        . '<p style="margin:0;color:#364d63;font-size:15px;line-height:25px;">Thank you for requesting a customizable DigitalInvoice Premium setup for <strong>' . mailEscape($businessName) . '</strong>. Our team will review your workflow, user needs, reporting expectations and integration requirements.</p>'
        . emailSoftPanel('<strong>What happens next:</strong> We will contact you to clarify your implementation needs and prepare a package designed around your business.', 'green')
        . '<p style="margin:22px 0 0;color:#60748a;font-size:13px;line-height:21px;">You can reply to this email with extra details about approval flows, invoice volume, integrations or compliance expectations.</p>'
    );
}

function premiumRequestNotificationEmail(array $request): string
{
    $features = is_array($request['required_features'] ?? null) ? $request['required_features'] : [];
    $featuresHtml = $features !== []
        ? '<p style="margin:18px 0 0;color:#364d63;font-size:14px;line-height:23px;"><strong>Requested features:</strong><br>' . mailEscape(implode(', ', $features)) . '</p>'
        : '';

    return emailShell(
        'A client requested a DigitalInvoice Premium/custom package.',
        'New Premium request',
        '<p style="margin:0 0 18px;color:#364d63;font-size:15px;line-height:25px;">A client has requested a customizable DigitalInvoice Premium setup.</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f8fb;border-radius:12px;padding:14px 16px;color:#364d63;font-size:14px;line-height:23px;">'
        . '<tr><td style="padding:3px 0;font-weight:700;width:150px;">Business</td><td>' . mailEscape((string) ($request['business_name'] ?? '')) . '</td></tr>'
        . '<tr><td style="padding:3px 0;font-weight:700;">Contact</td><td>' . mailEscape((string) ($request['contact_name'] ?? '')) . '</td></tr>'
        . '<tr><td style="padding:3px 0;font-weight:700;">Email</td><td>' . mailEscape((string) ($request['contact_email'] ?? '')) . '</td></tr>'
        . '<tr><td style="padding:3px 0;font-weight:700;">Phone</td><td>' . mailEscape((string) ($request['contact_phone'] ?? 'Not provided')) . '</td></tr>'
        . '<tr><td style="padding:3px 0;font-weight:700;">Invoice volume</td><td>' . mailEscape((string) ($request['expected_invoice_volume'] ?? 'Not provided')) . '</td></tr>'
        . '<tr><td style="padding:3px 0;font-weight:700;">Team size</td><td>' . mailEscape((string) ($request['expected_team_size'] ?? 'Not provided')) . '</td></tr></table>'
        . $featuresHtml
        . (trim((string) ($request['message'] ?? '')) !== '' ? '<p style="margin:22px 0 0;color:#364d63;font-size:14px;line-height:23px;"><strong>Message:</strong><br>' . nl2br(mailEscape((string) $request['message'])) . '</p>' : '')
        . emailSoftPanel('Open the Platform Console to update the request status, add internal notes and move the request through contact, negotiation, conversion or closure.', 'blue')
    );
}


function subscriptionBillingIntervalLabel(string $interval): string
{
    return match ($interval) {
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'biannually' => '6 months',
        'annually' => 'Yearly',
        default => ucwords(str_replace('_', ' ', $interval)),
    };
}

function subscriptionLifecycleNoticeEmail(array $content): string
{
    $name = (string) ($content['name'] ?? 'there');
    $organizationName = (string) ($content['organization_name'] ?? 'your workspace');
    $planName = (string) ($content['plan_name'] ?? 'DigitalInvoice plan');
    $billingInterval = subscriptionBillingIntervalLabel((string) ($content['billing_interval'] ?? 'monthly'));
    $amountLabel = (string) ($content['amount_label'] ?? '');
    $periodEnd = mailDateValue($content['period_end'] ?? null);
    $reference = trim((string) ($content['reference'] ?? ''));
    $panel = trim((string) ($content['panel'] ?? ''));
    $tone = (string) ($content['tone'] ?? 'blue');
    $ctaLabel = (string) ($content['cta_label'] ?? 'Open subscription');
    $ctaUrl = (string) ($content['cta_url'] ?? mailWebsiteUrl());

    $rows = '';
    foreach ([
        'Workspace' => $organizationName,
        'Plan' => $planName,
        'Billing period' => $billingInterval,
        'Plan amount' => $amountLabel,
        'Current period ends' => $periodEnd,
    ] as $label => $value) {
        if (trim((string) $value) === '') {
            continue;
        }
        $rows .= '<tr><td style="padding:5px 0;color:#6b7d90;font-size:13px;font-weight:800;width:46%;">' . mailEscape($label) . '</td><td align="right" style="padding:5px 0;color:#10263d;font-size:13px;font-weight:900;">' . mailEscape((string) $value) . '</td></tr>';
    }
    if ($reference !== '') {
        $rows .= '<tr><td style="padding:5px 0;color:#6b7d90;font-size:13px;font-weight:800;width:46%;">Reference</td><td align="right" style="padding:5px 0;color:#10263d;font-size:13px;font-weight:900;word-break:break-all;">' . mailEscape($reference) . '</td></tr>';
    }

    $panelHtml = $panel !== '' ? emailSoftPanel(mailEscape($panel), $tone === 'green' ? 'green' : ($tone === 'amber' ? 'amber' : 'blue')) : '';

    return emailShell(
        (string) ($content['preheader'] ?? 'DigitalInvoice subscription notification.'),
        (string) ($content['title'] ?? 'Subscription update'),
        '<p style="margin:0 0 15px;color:#364d63;font-size:15px;line-height:25px;">Hello <strong>' . mailEscape($name) . '</strong>,</p>'
        . '<p style="margin:0;color:#364d63;font-size:15px;line-height:25px;">' . mailEscape((string) ($content['intro'] ?? 'There is an update on your DigitalInvoice subscription.')) . '</p>'
        . '<p style="margin:14px 0 0;color:#364d63;font-size:15px;line-height:25px;">' . mailEscape((string) ($content['body'] ?? 'Open your subscription page to review the latest status.')) . '</p>'
        . '<div style="margin:24px 0 0;border-radius:18px;border:1px solid #e1ebf4;background:#f8fbfe;overflow:hidden;">'
        . '<div style="padding:13px 16px;background:#f0f7fc;color:#6b7d90;font-size:11px;text-transform:uppercase;letter-spacing:.9px;font-weight:900;">Subscription summary</div>'
        . '<div style="padding:15px 17px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;">' . $rows . '</table></div>'
        . '</div>'
        . $panelHtml
        . emailButton($ctaLabel, $ctaUrl)
        . '<p style="margin:0;color:#60748a;font-size:13px;line-height:21px;">Billing and subscription management stay available even when plan-protected product features are restricted.</p>'
        . emailLinkFallback($ctaUrl)
    );
}

function platformRoleDisplayName(string $role): string
{
    return ucwords(str_replace('_', ' ', $role));
}

function platformInvitationEmail(string $name, string $invitedByName, string $platformRole, string $platformUrl): string
{
    $displayRole = platformRoleDisplayName($platformRole);
    return emailShell(
        'You have been invited to the DigitalInvoice Platform Console.',
        'Platform access invitation',
        '<p style="margin:0 0 15px;color:#364d63;font-size:15px;line-height:25px;">Hello <strong>' . mailEscape($name) . '</strong>,</p>'
        . '<p style="margin:0;color:#364d63;font-size:15px;line-height:25px;"><strong>' . mailEscape($invitedByName) . '</strong> invited you to join the DigitalInvoice owner console as <strong>' . mailEscape($displayRole) . '</strong>.</p>'
        . '<div style="margin:24px 0;padding:18px;border-radius:15px;background:#f2f8fd;border:1px solid #dceaf7;color:#364d63;font-size:14px;line-height:23px;">This is platform-level access for managing DigitalInvoice clients, subscriptions and internal operations. Use only an authorised account that belongs to you.</div>'
        . emailButton('Accept platform invitation', $platformUrl)
        . '<p style="margin:0;color:#60748a;font-size:13px;line-height:21px;">You will be asked to create your own password before entering the owner console. Ignore this email if you were not expecting platform access.</p>'
        . emailLinkFallback($platformUrl)
    );
}

function platformAccessGrantedEmail(string $name, string $grantedByName, string $platformRole, string $platformUrl): string
{
    $displayRole = platformRoleDisplayName($platformRole);
    return emailShell(
        'Your DigitalInvoice Platform Console access has been updated.',
        'Platform access granted',
        '<p style="margin:0 0 15px;color:#364d63;font-size:15px;line-height:25px;">Hello <strong>' . mailEscape($name) . '</strong>,</p>'
        . '<p style="margin:0;color:#364d63;font-size:15px;line-height:25px;"><strong>' . mailEscape($grantedByName) . '</strong> granted you DigitalInvoice Platform Console access as <strong>' . mailEscape($displayRole) . '</strong>.</p>'
        . '<div style="margin:24px 0;padding:18px;border-radius:15px;background:#f2f8fd;border:1px solid #dceaf7;color:#364d63;font-size:14px;line-height:23px;">This access is separate from normal client workspace roles and should be used only for DigitalInvoice platform administration.</div>'
        . emailButton('Open platform console', $platformUrl)
        . '<p style="margin:0;color:#60748a;font-size:13px;line-height:21px;">If you were not expecting platform access, please contact the DigitalInvoice owner team immediately.</p>'
        . emailLinkFallback($platformUrl)
    );
}
