<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/masterDataService.php';

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        $user = requirePermission('tax_rates.view', true);
        $includeInactive = in_array(strtolower((string) ($_GET['include_inactive'] ?? '')), ['1', 'true', 'yes'], true)
            && userHasPermission($user, 'tax_rates.manage');
        jsonSuccess(['data' => ['tax_rates' => fetchOrganizationTaxRates($conn, (int) $user['organization_id'], $includeInactive)]]);
    }

    if (!in_array($method, ['POST', 'PUT'], true)) {
        throw new Exception('Method not supported.', 405);
    }
    $user = requirePermission('tax_rates.manage', true);
    $data = readJsonBody();
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];
    $id = (int) ($data['id'] ?? 0);
    if ($method === 'PUT' && $id < 1) {
        throw new Exception('Tax rate ID is required.', 422);
    }

    $code = catalogueOptionalCode($data['code'] ?? null, 'Tax rate code', 40);
    if ($code === null) {
        throw new Exception('Tax rate code is required.', 422);
    }
    $name = catalogueRequiredText($data, 'name', 'Tax rate name', 100);
    $description = catalogueNullableText($data['description'] ?? null, 'Tax rate description', 255);
    $treatment = strtolower(catalogueRequiredText($data, 'treatment', 'Tax treatment', 30));
    if (!in_array($treatment, ['standard', 'zero_rated', 'exempt', 'non_taxable'], true)) {
        throw new Exception('Select a valid tax treatment.', 422);
    }
    $rate = catalogueRatePercent($data['rate_percent'] ?? '0');
    if ($treatment !== 'standard' && (float) $rate !== 0.0) {
        throw new Exception('Zero-rated, exempt and non-taxable treatments must use a 0% rate.', 422);
    }
    $isDefault = array_key_exists('is_default', $data) ? catalogueBoolean($data['is_default'], 'Default tax rate') : 0;
    $isActive = array_key_exists('is_active', $data) ? catalogueBoolean($data['is_active'], 'Active tax rate') : 1;
    if ($isDefault === 1 && $isActive !== 1) {
        throw new Exception('An inactive tax rate cannot be the default.', 422);
    }

    if ($method === 'PUT' && $isActive !== 1) {
        $inUse = $conn->prepare('SELECT COUNT(*) AS total FROM items WHERE organization_id = ? AND tax_rate_id = ? AND archived_at IS NULL AND is_active = 1');
        $inUse->bind_param('ii', $organizationId, $id);
        $inUse->execute();
        $activeItems = (int) ($inUse->get_result()->fetch_assoc()['total'] ?? 0);
        $inUse->close();
        if ($activeItems > 0) {
            throw new Exception('This tax rate is still assigned to active products or services. Reassign those items before deactivating it.', 409);
        }
    }

    $conn->begin_transaction();
    try {
        if ($isDefault === 1) {
            $clear = $conn->prepare('UPDATE tax_rates SET is_default = 0, updated_at = NOW() WHERE organization_id = ?');
            $clear->bind_param('i', $organizationId);
            $clear->execute();
            $clear->close();
        }
        if ($method === 'POST') {
            $stmt = $conn->prepare(
                'INSERT INTO tax_rates (organization_id, code, name, description, treatment, rate_percent, is_default, is_active, created_by_user_id, updated_by_user_id) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $params = [$organizationId, $code, $name, $description, $treatment, $rate, $isDefault, $isActive, $userId, $userId];
            bindCatalogueParams($stmt, 'isssssiiii', $params);
            $stmt->execute();
            $id = (int) $stmt->insert_id;
            $stmt->close();
            $action = 'reference.tax_rate_created';
            $message = 'Tax rate created successfully.';
        } else {
            if (!fetchOrganizationTaxRate($conn, $organizationId, $id, false)) {
                throw new Exception('Tax rate was not found.', 404);
            }
            $stmt = $conn->prepare(
                'UPDATE tax_rates SET code = ?, name = ?, description = ?, treatment = ?, rate_percent = ?, is_default = ?, is_active = ?, '
                . 'archived_at = CASE WHEN ? = 1 THEN NULL ELSE COALESCE(archived_at, NOW()) END, updated_by_user_id = ?, updated_at = NOW() '
                . 'WHERE id = ? AND organization_id = ?'
            );
            $params = [$code, $name, $description, $treatment, $rate, $isDefault, $isActive, $isActive, $userId, $id, $organizationId];
            bindCatalogueParams($stmt, 'sssssiiiiii', $params);
            $stmt->execute();
            $stmt->close();
            $action = 'reference.tax_rate_updated';
            $message = 'Tax rate updated successfully.';
        }
        auditLog($conn, $userId, $action, $message, $organizationId, json_encode(['tax_rate_id' => $id, 'code' => $code], JSON_UNESCAPED_SLASHES));
        $conn->commit();
    } catch (mysqli_sql_exception $error) {
        $conn->rollback();
        if ((int) $error->getCode() === 1062) {
            throw new Exception('A tax rate with this code already exists.', 409);
        }
        throw $error;
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    jsonSuccess([
        'message' => $message,
        'data' => ['tax_rates' => fetchOrganizationTaxRates($conn, $organizationId, true)],
    ], $method === 'POST' ? 201 : 200);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to process tax rate request.', ($code >= 400 && $code <= 599) ? $code : 500);
}
