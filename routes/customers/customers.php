<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/masterDataService.php';

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        $user = requirePermission('customers.view', true);
        jsonSuccess(['data' => listCustomers($conn, (int) $user['organization_id'])]);
    }

    if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
        throw new Exception('Method not supported.', 405);
    }
    $user = requirePermission('customers.manage', true);
    $data = readJsonBody();
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];
    $id = (int) ($data['id'] ?? 0);

    if ($method === 'DELETE') {
        if ($id < 1) {
            throw new Exception('Customer ID is required.', 422);
        }
        $stmt = $conn->prepare(
            'UPDATE customers SET is_active = 0, archived_at = COALESCE(archived_at, NOW()), updated_by_user_id = ?, updated_at = NOW() '
            . 'WHERE id = ? AND organization_id = ? AND archived_at IS NULL'
        );
        $stmt->bind_param('iii', $userId, $id, $organizationId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected === 0) {
            throw new Exception('Customer was not found or has already been archived.', 404);
        }
        auditLog($conn, $userId, 'customer.archived', 'Customer archived.', $organizationId, json_encode(['customer_id' => $id]));
        jsonSuccess(['message' => 'Customer archived successfully.']);
    }

    if ($method === 'PUT') {
        if ($id < 1) {
            throw new Exception('Customer ID is required.', 422);
        }
        $existing = fetchCustomer($conn, $organizationId, $id);
        if (!$existing) {
            throw new Exception('Customer was not found.', 404);
        }
        if ($existing['archived_at'] !== null) {
            throw new Exception('Archived customers cannot be edited.', 409);
        }
    }

    if ($method === 'POST') {
        requirePlanLimitAvailable($conn, $user, 'customers', 'customers');
    }

    $customerCode = catalogueOptionalCode($data['customer_code'] ?? null, 'Customer code');
    $customerType = strtolower(catalogueRequiredText($data, 'customer_type', 'Customer type', 30));
    if (!in_array($customerType, ['business', 'individual', 'government', 'international'], true)) {
        throw new Exception('Select a valid customer type.', 422);
    }
    $name = catalogueRequiredText($data, 'name', 'Customer name', 180);
    $email = catalogueEmail($data['email'] ?? null, 'Customer email');
    $phone = catalogueNullableText($data['phone'] ?? null, 'Customer phone number', 30);
    $contactName = catalogueNullableText($data['contact_person_name'] ?? null, 'Contact person name', 120);
    $contactEmail = catalogueEmail($data['contact_person_email'] ?? null, 'Contact person email');
    $contactPhone = catalogueNullableText($data['contact_person_phone'] ?? null, 'Contact person phone number', 30);
    $tin = catalogueNullableText($data['tax_identification_number'] ?? null, 'Tax identification number', 60);
    $cac = catalogueNullableText($data['cac_registration_number'] ?? null, 'CAC registration number', 60);
    $vatNumber = catalogueNullableText($data['vat_number'] ?? null, 'VAT number', 60);
    $nrsBuyerId = catalogueNullableText($data['nrs_buyer_identifier'] ?? null, 'NRS buyer identifier', 100);
    $currency = catalogueCurrency($data['preferred_currency'] ?? 'NGN', 'Preferred currency');
    $address1 = catalogueNullableText($data['billing_address_line_1'] ?? null, 'Billing address', 180);
    $address2 = catalogueNullableText($data['billing_address_line_2'] ?? null, 'Billing address line 2', 180);
    $city = catalogueNullableText($data['city'] ?? null, 'City', 80);
    $state = catalogueNullableText($data['state'] ?? null, 'State', 80);
    $country = catalogueCountry($data['country'] ?? 'NG');
    $nrsPostalZone = catalogueNullableText($data['nrs_postal_zone'] ?? null, 'NRS postal zone', 20);
    $nrsStateCode = catalogueNullableText($data['nrs_state_code'] ?? null, 'NRS state code', 100);
    $nrsLgaCode = catalogueNullableText($data['nrs_lga_code'] ?? null, 'NRS LGA code', 100);
    $notes = catalogueNullableText($data['notes'] ?? null, 'Customer notes', 2000);
    $isActive = array_key_exists('is_active', $data) ? catalogueBoolean($data['is_active'], 'Active customer') : 1;

    $conn->begin_transaction();
    try {
        if ($method === 'POST') {
            $stmt = $conn->prepare(
                'INSERT INTO customers (organization_id, customer_code, customer_type, name, email, phone, contact_person_name, contact_person_email, contact_person_phone, '
                . 'tax_identification_number, cac_registration_number, vat_number, nrs_buyer_identifier, preferred_currency, billing_address_line_1, billing_address_line_2, city, state, country, nrs_postal_zone, nrs_state_code, nrs_lga_code, notes, '
                . 'is_active, created_by_user_id, updated_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $params = [$organizationId, $customerCode, $customerType, $name, $email, $phone, $contactName, $contactEmail, $contactPhone, $tin, $cac, $vatNumber, $nrsBuyerId, $currency, $address1, $address2, $city, $state, $country, $nrsPostalZone, $nrsStateCode, $nrsLgaCode, $notes, $isActive, $userId, $userId];
            bindCatalogueParams($stmt, 'i' . str_repeat('s', 22) . 'iii', $params);
            $stmt->execute();
            $id = (int) $stmt->insert_id;
            $stmt->close();
            if ($customerCode === null) {
                $customerCode = generatedScopedCode('CUS', $id);
                $codeUpdate = $conn->prepare('UPDATE customers SET customer_code = ? WHERE id = ? AND organization_id = ?');
                $codeUpdate->bind_param('sii', $customerCode, $id, $organizationId);
                $codeUpdate->execute();
                $codeUpdate->close();
            }
            $action = 'customer.created';
            $message = 'Customer created successfully.';
        } else {
            $stmt = $conn->prepare(
                'UPDATE customers SET customer_code = ?, customer_type = ?, name = ?, email = ?, phone = ?, contact_person_name = ?, contact_person_email = ?, contact_person_phone = ?, '
                . 'tax_identification_number = ?, cac_registration_number = ?, vat_number = ?, nrs_buyer_identifier = ?, preferred_currency = ?, billing_address_line_1 = ?, billing_address_line_2 = ?, '
                . 'city = ?, state = ?, country = ?, nrs_postal_zone = ?, nrs_state_code = ?, nrs_lga_code = ?, notes = ?, is_active = ?, updated_by_user_id = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?'
            );
            $params = [$customerCode ?? (string) $existing['customer_code'], $customerType, $name, $email, $phone, $contactName, $contactEmail, $contactPhone, $tin, $cac, $vatNumber, $nrsBuyerId, $currency, $address1, $address2, $city, $state, $country, $nrsPostalZone, $nrsStateCode, $nrsLgaCode, $notes, $isActive, $userId, $id, $organizationId];
            bindCatalogueParams($stmt, str_repeat('s', 22) . 'iiii', $params);
            $stmt->execute();
            $stmt->close();
            $action = 'customer.updated';
            $message = 'Customer updated successfully.';
        }
        auditLog($conn, $userId, $action, $message, $organizationId, json_encode(['customer_id' => $id, 'customer_code' => $customerCode], JSON_UNESCAPED_SLASHES));
        $conn->commit();
    } catch (mysqli_sql_exception $error) {
        $conn->rollback();
        if ((int) $error->getCode() === 1062) {
            throw new Exception('A customer with this customer code already exists.', 409);
        }
        throw $error;
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    jsonSuccess(['message' => $message, 'data' => ['customer' => fetchCustomer($conn, $organizationId, $id)]], $method === 'POST' ? 201 : 200);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to process customer request.', ($code >= 400 && $code <= 599) ? $code : 500);
}
