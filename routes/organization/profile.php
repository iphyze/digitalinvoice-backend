<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/subscriptionMiddleware.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $user = requireProductAccess();
        jsonSuccess(['data' => ['profile' => organizationProfile($conn, (int) $user['organization_id'])]]);
    }

    $user = requireOnboardingManager();
    $data = readJsonBody();
    $registeredName = requiredOnboardingText($data, 'registered_business_name', 'Registered business name', 180);
    $businessEmail = strtolower(requiredOnboardingText($data, 'business_email', 'Business email', 190));
    if (!filter_var($businessEmail, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Business email address is invalid.', 422);
    }
    $phone = requiredOnboardingText($data, 'phone', 'Phone number', 30);
    $businessType = requiredOnboardingText($data, 'business_type', 'Business type', 50);
    $allowedTypes = ['limited_company', 'business_name', 'sole_proprietorship', 'ngo', 'government', 'other'];
    if (!in_array($businessType, $allowedTypes, true)) {
        throw new Exception('Select a valid business type.', 422);
    }
    $industry = requiredOnboardingText($data, 'industry', 'Industry', 100);
    $addressLine1 = requiredOnboardingText($data, 'address_line_1', 'Business address', 180);
    $city = requiredOnboardingText($data, 'city', 'City', 80);
    $state = requiredOnboardingText($data, 'state', 'State', 80);
    $country = strtoupper(requiredOnboardingText($data, 'country', 'Country', 2));
    if (!preg_match('/^[A-Z]{2}$/', $country)) {
        throw new Exception('Country must be a two-letter ISO country code such as NG.', 422);
    }
    $tradingName = nullableTrimmedValue($data['trading_name'] ?? null, 180);
    $cacNumber = nullableTrimmedValue($data['cac_registration_number'] ?? null, 60);
    $website = nullableTrimmedValue($data['website'] ?? null, 190);
    if ($website !== null && !filter_var($website, FILTER_VALIDATE_URL)) {
        throw new Exception('Website must be a valid URL.', 422);
    }
    $addressLine2 = nullableTrimmedValue($data['address_line_2'] ?? null, 180);
    $organizationId = (int) $user['organization_id'];

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'UPDATE organizations SET name = ?, trading_name = ?, business_email = ?, phone = ?, cac_registration_number = ?, business_type = ?, industry = ?, website = ?, '
            . 'address_line_1 = ?, address_line_2 = ?, city = ?, state = ?, country = ?, updated_at = NOW() WHERE id = ?'
        );
        $stmt->bind_param(
            'sssssssssssssi',
            $registeredName,
            $tradingName,
            $businessEmail,
            $phone,
            $cacNumber,
            $businessType,
            $industry,
            $website,
            $addressLine1,
            $addressLine2,
            $city,
            $state,
            $country,
            $organizationId
        );
        $stmt->execute();
        $stmt->close();
        markOnboardingStepComplete($conn, $organizationId, (int) $user['id'], 'business_profile');
        auditLog($conn, (int) $user['id'], 'organization.profile_updated', 'Organisation business profile updated.', $organizationId);
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    jsonSuccess([
        'message' => 'Business profile saved successfully.',
        'data' => [
            'profile' => organizationProfile($conn, $organizationId),
            'onboarding' => organizationOnboardingState($conn, $user),
        ],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to save business profile.', ($code >= 400 && $code <= 599) ? $code : 500);
}
