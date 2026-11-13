<?php

declare(strict_types=1);

/**
 * Certificate inspection without exporting private keys or signing anything.
 * A valid EC certificate does NOT prove JAdES conformance or NRS enrollment.
 */
function nrsInspectSigningCertificate(string $certificatePem, ?DateTimeImmutable $now = null): array
{
    if (!function_exists('openssl_x509_read') || !function_exists('openssl_pkey_get_details')) {
        throw new RuntimeException('PHP OpenSSL extension is required for certificate checks.');
    }
    $certificate = @openssl_x509_read($certificatePem);
    if ($certificate === false) throw new InvalidArgumentException('Provide a valid X.509 PEM certificate.');
    $info = openssl_x509_parse($certificate, false);
    $publicKey = openssl_pkey_get_public($certificate);
    $details = $publicKey ? openssl_pkey_get_details($publicKey) : false;
    if (!is_array($info) || !is_array($details)) throw new InvalidArgumentException('Signing certificate public key could not be inspected.');
    $curve = (string) ($details['ec']['curve_name'] ?? '');
    if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC || !in_array($curve, ['prime256v1', 'secp384r1', 'secp521r1'], true)) {
        throw new InvalidArgumentException('Signing certificate must contain a supported ECDSA public key.');
    }
    $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $timestamp = $now->getTimestamp();
    $start = (int) ($info['validFrom_time_t'] ?? 0);
    $end = (int) ($info['validTo_time_t'] ?? 0);
    $fingerprint = openssl_x509_fingerprint($certificate, 'sha256');
    return [
        'valid_now' => $timestamp >= $start && $timestamp < $end,
        'expires_within_30_days' => $end > $timestamp && $end - $timestamp < 30 * 86400,
        'algorithm' => 'ECDSA',
        'curve' => $curve,
        'certificate_sha256' => $fingerprint,
        'valid_from_utc' => gmdate('c', $start),
        'valid_until_utc' => gmdate('c', $end),
        'jades_verified' => false,
        'nrs_enrollment_verified' => false,
    ];
}

/**
 * A certificate is necessary but insufficient: a secure HSM/KMS signing
 * service, JAdES interoperability testing and NRS onboarding are independent
 * controls. None may be asserted merely because this helper succeeds.
 */
function nrsSigningReadiness(?array $certificateInfo, bool $hsmOrKmsProvisioned = false,
    bool $jadesIndependentTestPassed = false, bool $nrsCertificateEnrollmentConfirmed = false): array
{
    $issues = [];
    if (!$certificateInfo || empty($certificateInfo['valid_now'])) $issues[] = 'A valid, current ECDSA signing certificate is required.';
    if (!empty($certificateInfo['expires_within_30_days'])) $issues[] = 'Signing certificate renewal is due within 30 days.';
    if (!$hsmOrKmsProvisioned) $issues[] = 'An approved non-exportable private-key signer (HSM or secure KMS) is not verified.';
    if (!$jadesIndependentTestPassed) $issues[] = 'JAdES format and interoperability have not been independently validated.';
    if (!$nrsCertificateEnrollmentConfirmed) $issues[] = 'NRS certificate registration / status has not been confirmed.';
    return ['ready' => $issues === [], 'issues' => $issues,
        'note' => 'Readiness evidence must come from real signing and onboarding tests; no signature is issued here.'];
}

/**
 * Pre-launch gate is advisory. Actual production HTTP remains permanently
 * blocked by nrsSandboxGate and has no scheduled worker in this release.
 */
function nrsProductionActivationReview(array $settings, array $env, array $signing, array $codePreflight): array
{
    $issues = [];
    if (!in_array((string) ($settings['firs_integration_status'] ?? ''), ['licensed', 'production_ready'], true)) {
        $issues[] = 'Platform licence status has not been recorded.';
    }
    if ((string) ($settings['firs_environment'] ?? '') !== 'production') $issues[] = 'Production environment has not been selected.';
    if (empty($settings['firs_api_key']) || empty($settings['firs_secret_key'])) $issues[] = 'NRS production credentials are missing.';
    if (($env['NRS_PRODUCTION_AUTHORIZED'] ?? '') !== '1' || empty($env['NRS_PRODUCTION_LICENSE_REFERENCE'])) {
        $issues[] = 'Independent server-side NRS production authorisation and licence reference are missing.';
    }
    if (empty($env['NRS_PRODUCTION_BASE_URL']) || ($settings['firs_base_url'] ?? null) !== ($env['NRS_PRODUCTION_BASE_URL'] ?? null)) {
        $issues[] = 'An approved production HTTPS origin has not been configured.';
    }
    if (empty($signing['ready'])) $issues[] = 'ECDSA/JAdES signing readiness has not been demonstrated.';
    if (empty($codePreflight['passed'])) $issues[] = 'Official code-list reconciliation is incomplete.';
    return ['preflight_passed' => $issues === [], 'production_submission_enabled' => false,
        'issues' => $issues, 'note' => 'Passing advisory checks does not enable production HTTP. Regulatory approval and a reviewed production worker are required.'];
}
