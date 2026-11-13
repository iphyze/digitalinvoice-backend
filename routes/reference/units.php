<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/masterDataService.php';

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        $user = requirePermission('catalogue.view', true);
        jsonSuccess(['data' => ['units' => fetchUnitsOfMeasure($conn, (int) $user['organization_id'])]]);
    }
    if ($method !== 'POST') {
        throw new Exception('Method not supported.', 405);
    }

    $user = requirePermission('catalogue.manage', true);
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];
    $fields = validateOrganizationUnitFields(readJsonBody());

    // This is a separate ownership mapping rather than a schema change to the
    // shared unit ID used in historic invoices. Never expose another tenant's
    // private unit in the reference API.
    $duplicate = $conn->prepare(
        'SELECT u.id FROM units_of_measure u '
        . 'JOIN organization_unit_ownership own ON own.unit_id = u.id '
        . 'WHERE own.organization_id = ? AND LOWER(u.name) = LOWER(?) AND u.category = ? LIMIT 1'
    );
    $duplicate->bind_param('iss', $organizationId, $fields['name'], $fields['category']);
    $duplicate->execute();
    $alreadyExists = (bool) $duplicate->get_result()->fetch_assoc();
    $duplicate->close();
    if ($alreadyExists) {
        throw new Exception('A unit with this name and availability already exists in your workspace.', 409);
    }

    // A globally unique opaque code prevents collisions across tenants while
    // letting users customise the label/symbol. It is NOT an NRS unit code.
    $code = 'C' . strtoupper(bin2hex(random_bytes(6)));
    $name = $fields['name'];
    $symbol = $fields['symbol'];
    $category = $fields['category'];
    $nameKey = mb_strtolower($name, 'UTF-8');

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'INSERT INTO units_of_measure (code, name, symbol, category, nrs_code, is_active) '
            . 'VALUES (?, ?, ?, ?, NULL, 1)'
        );
        $stmt->bind_param('ssss', $code, $name, $symbol, $category);
        $stmt->execute();
        $unitId = (int) $stmt->insert_id;
        $stmt->close();

        $ownership = $conn->prepare(
            'INSERT INTO organization_unit_ownership (unit_id, organization_id, created_by_user_id, name_key, category) VALUES (?, ?, ?, ?, ?)'
        );
        $ownership->bind_param('iiiss', $unitId, $organizationId, $userId, $nameKey, $category);
        $ownership->execute();
        $ownership->close();

        auditLog($conn, $userId, 'reference.unit_created', 'Custom unit created.', $organizationId,
            json_encode(['unit_id' => $unitId, 'name' => $name, 'category' => $category], JSON_UNESCAPED_SLASHES));
        $conn->commit();
    } catch (mysqli_sql_exception $error) {
        $conn->rollback();
        if ((int) $error->getCode() === 1062) {
            throw new Exception('A matching unit was already created. Refresh your list and try again.', 409);
        }
        throw $error;
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    // Do not return a generated code as an official regulatory classification.
    $units = fetchUnitsOfMeasure($conn, $organizationId);
    $created = null;
    foreach ($units as $unit) {
        if ((int) $unit['id'] === $unitId) {
            $created = $unit;
            break;
        }
    }
    jsonSuccess(['message' => 'Unit created successfully.', 'data' => ['unit' => $created, 'units' => $units]], 201);
} catch (Throwable $error) {
    $code = (int) $error->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $error->getMessage() : 'Unable to process unit of measure.',
        ($code >= 400 && $code <= 599) ? $code : 500);
}
