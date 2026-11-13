<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/masterDataService.php';

function itemFirsResourceCode(mixed $value, string $label, ?string $default = null): ?string
{
    $code = trim((string) ($value ?? $default ?? ''));
    if ($code === '') {
        return null;
    }
    if (mb_strlen($code) > 100 || !preg_match('/^[A-Za-z0-9._\/-]+$/', $code)) {
        throw new Exception($label . ' may contain only letters, numbers, dots, slashes, underscores and hyphens.', 422);
    }
    return strtoupper($code);
}

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        $user = requirePermission('catalogue.view', true);
        jsonSuccess(['data' => listItems($conn, (int) $user['organization_id'])]);
    }

    if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
        throw new Exception('Method not supported.', 405);
    }
    $user = requirePermission('catalogue.manage', true);
    $data = readJsonBody();
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];
    $id = (int) ($data['id'] ?? 0);

    if ($method === 'DELETE') {
        if ($id < 1) {
            throw new Exception('Product or service ID is required.', 422);
        }
        $stmt = $conn->prepare(
            'UPDATE items SET is_active = 0, archived_at = COALESCE(archived_at, NOW()), updated_by_user_id = ?, updated_at = NOW() '
            . 'WHERE id = ? AND organization_id = ? AND archived_at IS NULL'
        );
        $stmt->bind_param('iii', $userId, $id, $organizationId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected === 0) {
            throw new Exception('Product or service was not found or has already been archived.', 404);
        }
        auditLog($conn, $userId, 'catalogue.item_archived', 'Product or service archived.', $organizationId, json_encode(['item_id' => $id]));
        jsonSuccess(['message' => 'Product or service archived successfully.']);
    }

    $existing = null;
    if ($method === 'PUT') {
        if ($id < 1) {
            throw new Exception('Product or service ID is required.', 422);
        }
        $existing = fetchItem($conn, $organizationId, $id);
        if (!$existing) {
            throw new Exception('Product or service was not found.', 404);
        }
        if ($existing['archived_at'] !== null) {
            throw new Exception('Archived products or services cannot be edited.', 409);
        }
    }

    if ($method === 'POST') {
        requirePlanLimitAvailable($conn, $user, 'catalogue_items', 'products/services');
    }

    $itemCode = catalogueOptionalCode($data['item_code'] ?? null, 'Item code');
    $itemType = strtolower(catalogueRequiredText($data, 'item_type', 'Item type', 20));
    if (!in_array($itemType, ['product', 'service'], true)) {
        throw new Exception('Select a valid item type.', 422);
    }
    $name = catalogueRequiredText($data, 'name', 'Product or service name', 180);
    $description = catalogueNullableText($data['description'] ?? null, 'Description', 2000);
    $rawUnitId = $data['unit_of_measure_id'] ?? null;
    $unitId = 0;
    if ($rawUnitId !== null && $rawUnitId !== '') {
        $validatedUnitId = filter_var($rawUnitId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($validatedUnitId === false) {
            throw new Exception('Select a valid unit of measure.', 422);
        }
        $unitId = (int) $validatedUnitId;
    }
    if ($unitId === 0 && $itemType === 'service') {
        // The service form may omit the unit. Keep the existing NOT NULL item
        // and invoice-line foreign keys by storing the standard service unit.
        $unitId = defaultServiceUnitId($conn);
    }
    if ($unitId < 1 || !fetchUnitForItem($conn, $organizationId, $unitId, $itemType)) {
        throw new Exception('Select a valid unit of measure for this product or service.', 422);
    }
    $inputPrice = $data['default_unit_price'] ?? '';
    $price = catalogueAmount($itemType === 'service' && trim((string) $inputPrice) === '' ? '0.00' : $inputPrice, 'Default unit price');
    $currency = catalogueCurrency($data['currency'] ?? 'NGN');
    ensureOrganizationTaxRates($conn, $organizationId);
    $taxRateId = (int) ($data['tax_rate_id'] ?? 0);
    if ($taxRateId < 1) {
        $defaultTaxRate = defaultOrganizationTaxRate($conn, $organizationId);
        $taxRateId = (int) ($defaultTaxRate['id'] ?? 0);
    }
    if ($taxRateId < 1 || !fetchOrganizationTaxRate($conn, $organizationId, $taxRateId)) {
        throw new Exception('Select a valid active tax rate.', 422);
    }
    $discountAllowed = array_key_exists('discount_allowed', $data) ? catalogueBoolean($data['discount_allowed'], 'Discount allowed') : 1;
    $nrsClassificationCode = itemFirsResourceCode($data['nrs_item_classification_code'] ?? null, 'Goods HS / service classification code');
    // Officially synced resource lists may be enforced. Before credentials exist,
    // manual codes remain DRAFT and must never be represented as NRS verified.
    if ($nrsClassificationCode !== null) {
        $resourceKey = $itemType === 'product' ? 'hs_codes' : 'service_codes';
        $resource = $conn->prepare('SELECT status, payload FROM firs_resource_cache WHERE resource_key = ? LIMIT 1');
        $resource->bind_param('s', $resourceKey);
        $resource->execute();
        $record = $resource->get_result()->fetch_assoc();
        $resource->close();
        if (($record['status'] ?? '') === 'synced') {
            $codes = array_column(json_decode((string) ($record['payload'] ?? '[]'), true) ?: [], 'code');
            $codes = array_map('strtoupper', array_map('strval', $codes));
            if (!in_array($nrsClassificationCode, $codes, true)) {
                throw new Exception('Select a code from the verified NRS classification list for this item type.', 422);
            }
        }
    }
    $firsItemType = strtolower(trim((string) ($data['firs_item_type'] ?? ($itemType === 'product' ? 'goods' : 'service'))));
    if (!in_array($firsItemType, ['goods', 'service'], true)) {
        throw new Exception('Select a valid FIRS/MBS item type.', 422);
    }
    if ($firsItemType !== ($itemType === 'product' ? 'goods' : 'service')) {
        throw new Exception('A product must use goods classification; a service must use service classification.', 422);
    }
    $firsTaxCategoryCode = itemFirsResourceCode($data['firs_tax_category_code'] ?? null, 'FIRS/MBS tax category code');
    $firsVatExemptionCode = itemFirsResourceCode($data['firs_vat_exemption_code'] ?? null, 'FIRS/MBS VAT exemption code');
    $firsUnitCode = itemFirsResourceCode($data['firs_unit_code'] ?? null, 'FIRS/MBS unit code');
    $isActive = array_key_exists('is_active', $data) ? catalogueBoolean($data['is_active'], 'Active item') : 1;

    $conn->begin_transaction();
    try {
        if ($method === 'POST') {
            $stmt = $conn->prepare(
                'INSERT INTO items (organization_id, item_code, item_type, name, description, unit_of_measure_id, default_unit_price, currency, tax_rate_id, '
                . 'discount_allowed, nrs_item_classification_code, firs_item_type, firs_tax_category_code, firs_vat_exemption_code, firs_unit_code, is_active, created_by_user_id, updated_by_user_id) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $params = [$organizationId, $itemCode, $itemType, $name, $description, $unitId, $price, $currency, $taxRateId, $discountAllowed, $nrsClassificationCode, $firsItemType, $firsTaxCategoryCode, $firsVatExemptionCode, $firsUnitCode, $isActive, $userId, $userId];
            bindCatalogueParams($stmt, 'i' . str_repeat('s', 4) . 'i' . str_repeat('s', 2) . 'ii' . str_repeat('s', 5) . 'iii', $params);
            $stmt->execute();
            $id = (int) $stmt->insert_id;
            $stmt->close();
            if ($itemCode === null) {
                $itemCode = generatedScopedCode('ITM', $id);
                $codeUpdate = $conn->prepare('UPDATE items SET item_code = ? WHERE id = ? AND organization_id = ?');
                $codeUpdate->bind_param('sii', $itemCode, $id, $organizationId);
                $codeUpdate->execute();
                $codeUpdate->close();
            }
            $action = 'catalogue.item_created';
            $message = 'Product or service created successfully.';
        } else {
            $stmt = $conn->prepare(
                'UPDATE items SET item_code = ?, item_type = ?, name = ?, description = ?, unit_of_measure_id = ?, default_unit_price = ?, currency = ?, '
                . 'tax_rate_id = ?, discount_allowed = ?, nrs_item_classification_code = ?, firs_item_type = ?, firs_tax_category_code = ?, firs_vat_exemption_code = ?, firs_unit_code = ?, is_active = ?, updated_by_user_id = ?, updated_at = NOW() '
                . 'WHERE id = ? AND organization_id = ?'
            );
            $params = [$itemCode ?? (string) $existing['item_code'], $itemType, $name, $description, $unitId, $price, $currency, $taxRateId, $discountAllowed, $nrsClassificationCode, $firsItemType, $firsTaxCategoryCode, $firsVatExemptionCode, $firsUnitCode, $isActive, $userId, $id, $organizationId];
            bindCatalogueParams($stmt, str_repeat('s', 4) . 'i' . str_repeat('s', 2) . 'ii' . str_repeat('s', 5) . 'iiii', $params);
            $stmt->execute();
            $stmt->close();
            $action = 'catalogue.item_updated';
            $message = 'Product or service updated successfully.';
        }
        auditLog($conn, $userId, $action, $message, $organizationId, json_encode(['item_id' => $id, 'item_code' => $itemCode], JSON_UNESCAPED_SLASHES));
        $conn->commit();
    } catch (mysqli_sql_exception $error) {
        $conn->rollback();
        if ((int) $error->getCode() === 1062) {
            throw new Exception('A product or service with this item code already exists.', 409);
        }
        throw $error;
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    jsonSuccess(['message' => $message, 'data' => ['item' => fetchItem($conn, $organizationId, $id)]], $method === 'POST' ? 201 : 200);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to process product or service request.', ($code >= 400 && $code <= 599) ? $code : 500);
}
