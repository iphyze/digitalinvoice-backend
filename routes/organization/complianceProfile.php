<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/subscriptionMiddleware.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $user = requireProductAccess();
        jsonSuccess(['data' => ['profile' => complianceProfile($conn, (int) $user['organization_id'])]]);
    }

    $user = requireOnboardingManager();
    $data = readJsonBody();
    $tin = requiredOnboardingText($data, 'tax_identification_number', 'Tax identification number', 60);
    if (!preg_match('/^[A-Za-z0-9\/-]+$/', $tin)) {
        throw new Exception('Tax identification number contains invalid characters.', 422);
    }
    $issuerType = requiredOnboardingText($data, 'issuer_type', 'Issuer type', 40);
    if (!in_array($issuerType, ['corporate', 'business_name', 'ngo', 'government', 'individual', 'other'], true)) {
        throw new Exception('Select a valid issuer type.', 422);
    }
    $registrationType = requiredOnboardingText($data, 'business_registration_type', 'Business registration type', 40);
    if (!in_array($registrationType, ['cac_rc', 'cac_bn', 'incorporated_trustee', 'government', 'not_applicable', 'other'], true)) {
        throw new Exception('Select a valid business registration type.', 422);
    }
    $vatRegistered = booleanInput($data['is_vat_registered'] ?? null, 'VAT registration status');
    $vatNumber = nullableTrimmedValue($data['vat_number'] ?? null, 60);
    if ($vatRegistered && $vatNumber === null) {
        throw new Exception('VAT number is required when the business is VAT registered.', 422);
    }
    $confirmed = booleanInput($data['compliance_confirmed'] ?? null, 'Compliance confirmation');
    if ($confirmed !== 1) {
        throw new Exception('You must confirm that the submitted compliance information is accurate.', 422);
    }
    // NRS-issued identifiers are optional until the taxpayer is enabled.
    $nrsBusinessId = nullableTrimmedValue($data['nrs_business_id'] ?? null, 36);
    if ($nrsBusinessId !== null && !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $nrsBusinessId)) {
        throw new Exception('NRS business ID must be a valid UUID issued by NRS.', 422);
    }
    $nrsServiceId = nullableTrimmedValue($data['nrs_service_id'] ?? null, 8);
    if ($nrsServiceId !== null && !preg_match('/^[a-z0-9]{8}$/i', $nrsServiceId)) {
        throw new Exception('NRS service ID must contain eight alphanumeric characters.', 422);
    }
    $nrsPostalZone = nullableTrimmedValue($data['nrs_postal_zone'] ?? null, 20);
    $nrsStateCode = nullableTrimmedValue($data['nrs_state_code'] ?? null, 100);
    $nrsLgaCode = nullableTrimmedValue($data['nrs_lga_code'] ?? null, 100);
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];

    $conn->begin_transaction();
    try {
        $organization = $conn->prepare('UPDATE organizations SET tax_identification_number = ?, updated_at = NOW() WHERE id = ?');
        $organization->bind_param('si', $tin, $organizationId);
        $organization->execute();
        $organization->close();

        $stmt = $conn->prepare(
            'INSERT INTO organization_compliance_profiles '
            . '(organization_id, issuer_type, business_registration_type, is_vat_registered, vat_number, nrs_business_id, nrs_service_id, nrs_postal_zone, nrs_state_code, nrs_lga_code, compliance_confirmed_at, confirmed_by_user_id) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?) '
            . 'ON DUPLICATE KEY UPDATE issuer_type = VALUES(issuer_type), business_registration_type = VALUES(business_registration_type), '
            . 'is_vat_registered = VALUES(is_vat_registered), vat_number = VALUES(vat_number), nrs_business_id = VALUES(nrs_business_id), nrs_service_id = VALUES(nrs_service_id), nrs_postal_zone = VALUES(nrs_postal_zone), nrs_state_code = VALUES(nrs_state_code), nrs_lga_code = VALUES(nrs_lga_code), compliance_confirmed_at = NOW(), '
            . 'confirmed_by_user_id = VALUES(confirmed_by_user_id), updated_at = NOW()'
        );
        $stmt->bind_param('ississssssi', $organizationId, $issuerType, $registrationType, $vatRegistered, $vatNumber, $nrsBusinessId, $nrsServiceId, $nrsPostalZone, $nrsStateCode, $nrsLgaCode, $userId);
        $stmt->execute();
        $stmt->close();
        markOnboardingStepComplete($conn, $organizationId, $userId, 'compliance_profile');
        auditLog($conn, $userId, 'organization.compliance_profile_confirmed', 'Compliance profile information confirmed.', $organizationId);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    jsonSuccess([
        'message' => 'Compliance profile saved successfully.',
        'data' => [
            'profile' => complianceProfile($conn, $organizationId),
            'onboarding' => organizationOnboardingState($conn, $user),
        ],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to save compliance profile.', ($code >= 400 && $code <= 599) ? $code : 500);
}
