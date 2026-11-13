<?php

declare(strict_types=1);

// Pure local readiness checks; no network or database access required.

function firsPayloadChecks(array $payload): array
{
    $errors = [];
    $warnings = [];
    $checks = [];
    $addCheck = static function (string $key, string $label, string $status, string $message) use (&$checks): void {
        $checks[] = ['key' => $key, 'label' => $label, 'status' => $status, 'message' => $message];
    };

    $transactionType = (string) ($payload['firs_metadata']['transaction_type'] ?? 'b2b');
    $documentType = (string) ($payload['firs_metadata']['document_type'] ?? 'tax_invoice');
    $paymentMeans = (string) ($payload['firs_metadata']['payment_means_code'] ?? 'direct_transfer');
    $taxExemptionCode = trim((string) ($payload['firs_metadata']['tax_exemption_code'] ?? ''));
    $items = $payload['items'] ?? [];

    if (($payload['status'] ?? '') !== 'approved') {
        $errors[] = 'Invoice must be approved before it can be prepared for FIRS/MBS submission.';
        $addCheck('invoice_status', 'Invoice approval', 'blocked', 'Approve this invoice before any FIRS/MBS readiness or future submission step.');
    } else {
        $addCheck('invoice_status', 'Invoice approval', 'passed', 'Invoice is approved and locked for customer-facing output.');
    }

    if (trim((string) ($payload['invoice_number'] ?? '')) === '') {
        $errors[] = 'Invoice number is required.';
        $addCheck('invoice_number', 'Invoice number', 'blocked', 'Approved invoices must have an assigned invoice number.');
    } else {
        $addCheck('invoice_number', 'Invoice number', 'passed', 'Invoice number is present.');
    }

    if ($transactionType === 'b2b' && $documentType !== 'tax_invoice') {
        $errors[] = 'B2B transactions should be prepared as tax invoices.';
        $addCheck('document_type', 'Document classification', 'blocked', 'B2B transactions require the Tax invoice document type.');
    } elseif ($transactionType === 'b2c' && $documentType !== 'simplified_tax_invoice') {
        $warnings[] = 'B2C transactions are normally prepared as simplified tax invoices.';
        $addCheck('document_type', 'Document classification', 'warning', 'B2C transactions usually use Simplified tax invoice. Confirm before future submission.');
    } else {
        $addCheck('document_type', 'Document classification', 'passed', 'Transaction model and document type align.');
    }

    if (trim((string) ($payload['supplier']['tin'] ?? '')) === '') {
        $errors[] = 'Supplier TIN is required on the organisation profile.';
        $addCheck('supplier_tin', 'Supplier TIN', 'blocked', 'Add the business TIN on the organisation profile.');
    } else {
        $addCheck('supplier_tin', 'Supplier TIN', 'passed', 'Supplier TIN is available.');
    }
    if (trim((string) ($payload['supplier']['address'] ?? '')) === '') {
        $errors[] = 'Supplier address is required on the organisation profile.';
        $addCheck('supplier_address', 'Supplier address', 'blocked', 'Complete business address on the organisation profile.');
    } else {
        $addCheck('supplier_address', 'Supplier address', 'passed', 'Supplier address is available.');
    }

    if (trim((string) ($payload['buyer']['name'] ?? '')) === '') {
        $errors[] = 'Buyer/customer name is required.';
        $addCheck('buyer_name', 'Buyer name', 'blocked', 'Select a customer with a valid name.');
    } else {
        $addCheck('buyer_name', 'Buyer name', 'passed', 'Buyer/customer name is available.');
    }
    if ($transactionType === 'b2b' && trim((string) ($payload['buyer']['tin'] ?? '')) === '') {
        $errors[] = 'Buyer TIN is required for B2B tax invoices.';
        $addCheck('buyer_tin', 'Buyer TIN', 'blocked', 'Add the buyer/customer TIN before preparing a B2B tax invoice.');
    } elseif (trim((string) ($payload['buyer']['tin'] ?? '')) === '') {
        $warnings[] = 'Buyer TIN is missing. This is usually acceptable for B2C simplified invoices.';
        $addCheck('buyer_tin', 'Buyer TIN', 'warning', 'Buyer TIN is missing, but B2C simplified invoices may not require it.');
    } else {
        $addCheck('buyer_tin', 'Buyer TIN', 'passed', 'Buyer TIN is available.');
    }
    if ($transactionType === 'b2b' && trim((string) ($payload['buyer']['address'] ?? '')) === '') {
        $errors[] = 'Buyer address is required for B2B tax invoices.';
        $addCheck('buyer_address', 'Buyer address', 'blocked', 'Add the buyer/customer billing address before preparing a B2B tax invoice.');
    } elseif (trim((string) ($payload['buyer']['address'] ?? '')) === '') {
        $warnings[] = 'Buyer address is missing. B2C simplified invoices may still be acceptable.';
        $addCheck('buyer_address', 'Buyer address', 'warning', 'Buyer address is missing.');
    } else {
        $addCheck('buyer_address', 'Buyer address', 'passed', 'Buyer address is available.');
    }

    // This is a local data-integrity warning, not a claim that each field is
    // mandatory under an NRS production profile. Historical approved invoices
    // intentionally never borrow current customer location data.
    $buyerPostal = $payload['buyer']['postal_address'] ?? [];
    $missingBuyerLocation = [];
    foreach (['city_name' => 'city', 'postal_zone' => 'postal zone', 'state' => 'state code', 'lga' => 'LGA code'] as $key => $label) {
        if (trim((string) ($buyerPostal[$key] ?? '')) === '') {
            $missingBuyerLocation[] = $label;
        }
    }
    if ($missingBuyerLocation !== []) {
        $warnings[] = 'Buyer NRS address snapshot is incomplete (' . implode(', ', $missingBuyerLocation) . '). Verify original invoice-time details before future submission; current customer details must not be substituted.';
        $addCheck('buyer_nrs_address_snapshot', 'Buyer NRS address snapshot', 'warning', 'Review the buyer address on the original record before any future live submission.');
    } else {
        $addCheck('buyer_nrs_address_snapshot', 'Buyer NRS address snapshot', 'passed', 'Buyer location fields are available from the invoice snapshot.');
    }

    if (!preg_match('/^[A-Z]{3}$/', (string) ($payload['currency_code'] ?? ''))) {
        $errors[] = 'Currency code must be a valid ISO 4217 code.';
        $addCheck('currency', 'Currency', 'blocked', 'Use a valid ISO 4217 currency code.');
    } else {
        $addCheck('currency', 'Currency', 'passed', 'Currency code is valid.');
    }

    if ($paymentMeans === '') {
        $errors[] = 'Payment means is required for FIRS/MBS payload readiness.';
        $addCheck('payment_means', 'Payment means', 'blocked', 'Select a recognized payment means.');
    } elseif ($paymentMeans === 'firs_pay_now') {
        $warnings[] = 'FIRS Pay-Now is selected but live Pay-Now links remain disabled until license credentials are issued.';
        $addCheck('payment_means', 'Payment means', 'warning', 'FIRS Pay-Now is selected for future use. It will not generate a link yet.');
    } else {
        $addCheck('payment_means', 'Payment means', 'passed', 'Payment means is selected.');
    }

    if (!is_array($items) || count($items) < 1) {
        $errors[] = 'At least one invoice line is required.';
        $addCheck('line_items', 'Line items', 'blocked', 'Add at least one invoice line.');
    } else {
        $addCheck('line_items', 'Line items', 'passed', count($items) . ' invoice line(s) available.');
    }

    $hasExemptOrZeroRatedLine = false;
    foreach (($items ?? []) as $index => $line) {
        $lineNumber = $index + 1;
        if (trim((string) ($line['name'] ?? '')) === '') {
            $errors[] = "Line {$lineNumber}: item name is required.";
        }
        if (trim((string) ($line['unit_code'] ?? '')) === '') {
            $errors[] = "Line {$lineNumber}: unit of measure code is required.";
        }
        if (trim((string) ($line['classification_code'] ?? '')) === '') {
            $warnings[] = "Line {$lineNumber}: item/service classification code is missing.";
        }
        if (trim((string) ($line['tax_code'] ?? '')) === '') {
            $warnings[] = "Line {$lineNumber}: tax category/code is missing.";
        }
        if (in_array((string) ($line['tax_treatment'] ?? ''), ['zero_rated', 'exempt', 'non_taxable'], true)) {
            $hasExemptOrZeroRatedLine = true;
        }
    }

    if ($hasExemptOrZeroRatedLine && $taxExemptionCode === '') {
        $warnings[] = 'One or more invoice lines are zero-rated, exempt, or non-taxable. Add a VAT exemption code where FIRS requires it.';
        $addCheck('vat_exemption', 'VAT exemption mapping', 'warning', 'Add a VAT exemption code when applicable for exempt/zero-rated lines.');
    } else {
        $addCheck('vat_exemption', 'VAT exemption mapping', 'passed', 'VAT exemption mapping does not currently block readiness.');
    }

    $lineBlockingIssues = array_filter($errors, static fn(string $error): bool => str_starts_with($error, 'Line '));
    if (count($lineBlockingIssues) > 0) {
        $addCheck('line_validation', 'Line validation', 'blocked', count($lineBlockingIssues) . ' blocking line issue(s) found.');
    } else {
        $addCheck('line_validation', 'Line validation', count($warnings) > 0 ? 'warning' : 'passed', 'Line level fields are structurally ready for local validation.');
    }

    return ['errors' => $errors, 'warnings' => $warnings, 'checks' => $checks];
}

