<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

function catalogueRequiredText(array $data, string $key, string $label, int $maxLength): string
{
    $value = trim((string) ($data[$key] ?? ''));
    if ($value === '') {
        throw new Exception($label . ' is required.', 422);
    }
    if (mb_strlen($value) > $maxLength) {
        throw new Exception($label . ' may not be longer than ' . $maxLength . ' characters.', 422);
    }
    return $value;
}

function catalogueNullableText(mixed $value, string $label, int $maxLength): ?string
{
    $value = trim((string) ($value ?? ''));
    if ($value === '') {
        return null;
    }
    if (mb_strlen($value) > $maxLength) {
        throw new Exception($label . ' may not be longer than ' . $maxLength . ' characters.', 422);
    }
    return $value;
}

function catalogueBoolean(mixed $value, string $label): int
{
    if (is_bool($value)) {
        return $value ? 1 : 0;
    }
    if (in_array($value, [0, 1, '0', '1'], true)) {
        return (int) $value;
    }
    throw new Exception($label . ' must be true or false.', 422);
}

function catalogueEmail(mixed $value, string $label): ?string
{
    $email = catalogueNullableText($value, $label, 190);
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception($label . ' must be a valid email address.', 422);
    }
    return $email !== null ? strtolower($email) : null;
}

function catalogueCurrency(mixed $value, string $label = 'Currency'): string
{
    $currency = strtoupper(trim((string) $value));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        throw new Exception($label . ' must be a three-letter currency code such as NGN.', 422);
    }
    return $currency;
}

function catalogueCountry(mixed $value): string
{
    $country = strtoupper(trim((string) ($value ?? 'NG')));
    if (!preg_match('/^[A-Z]{2}$/', $country)) {
        throw new Exception('Country must be a two-letter ISO country code such as NG.', 422);
    }
    return $country;
}

function catalogueOptionalCode(mixed $value, string $label, int $maxLength = 50): ?string
{
    $code = catalogueNullableText($value, $label, $maxLength);
    if ($code === null) {
        return null;
    }
    $code = strtoupper($code);
    if (!preg_match('/^[A-Z0-9._\/-]+$/', $code)) {
        throw new Exception($label . ' may contain only letters, numbers, dots, slashes, underscores and hyphens.', 422);
    }
    return $code;
}

function catalogueAmount(mixed $value, string $label): string
{
    $amount = trim((string) $value);
    if ($amount === '' || !preg_match('/^\d{1,16}(?:\.\d{1,2})?$/', $amount)) {
        throw new Exception($label . ' must be a valid non-negative amount with at most two decimal places.', 422);
    }
    [$whole, $decimal] = array_pad(explode('.', $amount, 2), 2, '');
    $whole = ltrim($whole, '0');
    $whole = $whole === '' ? '0' : $whole;
    return $whole . '.' . str_pad($decimal, 2, '0');
}

function catalogueRatePercent(mixed $value): string
{
    $rate = trim((string) $value);
    if ($rate === '' || !preg_match('/^\d{1,3}(?:\.\d{1,4})?$/', $rate) || (float) $rate > 100) {
        throw new Exception('Tax rate must be between 0 and 100 with at most four decimal places.', 422);
    }
    return number_format((float) $rate, 4, '.', '');
}

function catalogueListPaging(): array
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $limit = max(1, min(100, (int) ($_GET['limit'] ?? 20)));
    return [$page, $limit, ($page - 1) * $limit];
}

function bindCatalogueParams(mysqli_stmt $stmt, string $types, array &$values): void
{
    if ($types === '') {
        return;
    }
    $references = [];
    foreach ($values as &$value) {
        $references[] = &$value;
    }
    $stmt->bind_param($types, ...$references);
}

function ensureOrganizationTaxRates(mysqli $conn, int $organizationId): void
{
    $defaults = [
        ['VAT_STANDARD', 'Standard VAT', 'Standard taxable supply VAT rate.', 'standard', '7.5000', 1],
        ['VAT_ZERO', 'Zero Rated', 'Taxable supply charged at zero percent.', 'zero_rated', '0.0000', 0],
        ['VAT_EXEMPT', 'VAT Exempt', 'Supply treated as exempt from VAT.', 'exempt', '0.0000', 0],
        ['NON_TAXABLE', 'Non-Taxable', 'Line item is outside taxable VAT treatment.', 'non_taxable', '0.0000', 0],
    ];
    $stmt = $conn->prepare(
        'INSERT INTO tax_rates (organization_id, code, name, description, treatment, rate_percent, is_default, is_system_seed) '
        . 'SELECT ?, ?, ?, ?, ?, ?, ?, 1 WHERE NOT EXISTS '
        . '(SELECT 1 FROM tax_rates WHERE organization_id = ? AND code = ?)'
    );
    foreach ($defaults as [$code, $name, $description, $treatment, $rate, $isDefault]) {
        $stmt->bind_param('isssssiis', $organizationId, $code, $name, $description, $treatment, $rate, $isDefault, $organizationId, $code);
        $stmt->execute();
    }
    $stmt->close();
}

/**
 * Global catalogue units are shared. Organisation-created units live in the
 * existing units table (for invoice-line foreign keys) but are visible only to
 * their owner via the ownership mapping added in the Batch 2 migration.
 */
function fetchUnitsOfMeasure(mysqli $conn, int $organizationId): array
{
    $stmt = $conn->prepare(
        'SELECT u.id, u.code, u.name, u.symbol, u.category, u.nrs_code, u.is_active, '
        . 'CASE WHEN own.unit_id IS NULL THEN 0 ELSE 1 END AS is_custom '
        . 'FROM units_of_measure u LEFT JOIN organization_unit_ownership own ON own.unit_id = u.id '
        . 'WHERE u.is_active = 1 AND (own.unit_id IS NULL OR own.organization_id = ?) '
        . 'ORDER BY is_custom ASC, u.category ASC, u.name ASC'
    );
    $stmt->bind_param('i', $organizationId);
    $stmt->execute();
    $units = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $units;
}

/** One-time user-defined names and symbols are not official NRS unit codes. */
function validateOrganizationUnitFields(array $data): array
{
    $name = catalogueRequiredText($data, 'name', 'Unit name', 80);
    $symbol = catalogueNullableText($data['symbol'] ?? null, 'Unit symbol', 16);
    $category = strtolower(catalogueRequiredText($data, 'category', 'Unit availability', 20));
    if (!in_array($category, ['service', 'product', 'both'], true)) {
        throw new Exception('Choose service, product or both for unit availability.', 422);
    }
    if (preg_match('/[\x00-\x1F\x7F]/u', $name . ($symbol ?? ''))) {
        throw new Exception('Unit names and symbols cannot contain control characters.', 422);
    }
    return compact('name', 'symbol', 'category');
}

function defaultServiceUnitId(mysqli $conn): int
{
    // Use the seeded generic service unit; never use an unrelated customer unit.
    $result = $conn->query(
        "SELECT u.id FROM units_of_measure u "
        . "LEFT JOIN organization_unit_ownership own ON own.unit_id = u.id "
        . "WHERE u.is_active = 1 AND own.unit_id IS NULL AND u.code = 'SERVICE' "
        . "AND u.category IN ('service', 'both') LIMIT 1"
    );
    $id = (int) ($result->fetch_assoc()['id'] ?? 0);
    if ($id < 1) {
        throw new Exception('The standard Service unit is missing. Apply the catalogue units migration.', 409);
    }
    return $id;
}

function fetchOrganizationTaxRates(mysqli $conn, int $organizationId, bool $includeInactive = false): array
{
    ensureOrganizationTaxRates($conn, $organizationId);
    $sql = 'SELECT id, code, name, description, treatment, rate_percent, is_default, is_system_seed, is_active, archived_at, created_at, updated_at '
        . 'FROM tax_rates WHERE organization_id = ?';
    if (!$includeInactive) {
        $sql .= ' AND is_active = 1 AND archived_at IS NULL';
    }
    $sql .= ' ORDER BY is_default DESC, is_active DESC, name ASC';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $organizationId);
    $stmt->execute();
    $rates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rates;
}

function fetchOrganizationTaxRate(mysqli $conn, int $organizationId, int $taxRateId, bool $activeOnly = true): ?array
{
    ensureOrganizationTaxRates($conn, $organizationId);
    $sql = 'SELECT id, organization_id, code, name, description, treatment, rate_percent, is_default, is_system_seed, is_active, archived_at '
        . 'FROM tax_rates WHERE id = ? AND organization_id = ?';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1 AND archived_at IS NULL';
    }
    $sql .= ' LIMIT 1';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $taxRateId, $organizationId);
    $stmt->execute();
    $rate = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $rate ?: null;
}

function defaultOrganizationTaxRate(mysqli $conn, int $organizationId): ?array
{
    ensureOrganizationTaxRates($conn, $organizationId);
    $stmt = $conn->prepare(
        'SELECT id, organization_id, code, name, treatment, rate_percent, is_default, is_active FROM tax_rates '
        . 'WHERE organization_id = ? AND is_active = 1 AND archived_at IS NULL ORDER BY is_default DESC, id ASC LIMIT 1'
    );
    $stmt->bind_param('i', $organizationId);
    $stmt->execute();
    $rate = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $rate ?: null;
}

function fetchUnitForItem(mysqli $conn, int $organizationId, int $unitId, string $itemType): ?array
{
    $stmt = $conn->prepare(
        'SELECT u.id, u.code, u.name, u.symbol, u.category FROM units_of_measure u '
        . 'LEFT JOIN organization_unit_ownership own ON own.unit_id = u.id '
        . "WHERE u.id = ? AND u.is_active = 1 AND (u.category = ? OR u.category = 'both') "
        . 'AND (own.unit_id IS NULL OR own.organization_id = ?) LIMIT 1'
    );
    $stmt->bind_param('isi', $unitId, $itemType, $organizationId);
    $stmt->execute();
    $unit = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $unit ?: null;
}

function generatedScopedCode(string $prefix, int $id): string
{
    return $prefix . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

function fetchCustomer(mysqli $conn, int $organizationId, int $customerId): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, customer_code, customer_type, name, email, phone, contact_person_name, contact_person_email, contact_person_phone, '
        . 'tax_identification_number, cac_registration_number, vat_number, nrs_buyer_identifier, preferred_currency, billing_address_line_1, '
        . 'billing_address_line_2, city, state, country, nrs_postal_zone, nrs_state_code, nrs_lga_code, notes, is_active, archived_at, created_at, updated_at '
        . 'FROM customers WHERE organization_id = ? AND id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $organizationId, $customerId);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $customer ?: null;
}

function listCustomers(mysqli $conn, int $organizationId): array
{
    [$page, $limit, $offset] = catalogueListPaging();
    $search = trim((string) ($_GET['search'] ?? ''));
    $type = strtolower(trim((string) ($_GET['type'] ?? '')));
    $status = strtolower(trim((string) ($_GET['status'] ?? 'active')));
    if ($type !== '' && !in_array($type, ['business', 'individual', 'government', 'international'], true)) {
        throw new Exception('Select a valid customer type filter.', 422);
    }
    if (!in_array($status, ['active', 'inactive', 'archived', 'all'], true)) {
        throw new Exception('Select a valid customer status filter.', 422);
    }

    $where = ['organization_id = ?'];
    $types = 'i';
    $values = [$organizationId];
    if ($status === 'active') {
        $where[] = 'archived_at IS NULL AND is_active = 1';
    } elseif ($status === 'inactive') {
        $where[] = 'archived_at IS NULL AND is_active = 0';
    } elseif ($status === 'archived') {
        $where[] = 'archived_at IS NOT NULL';
    }
    if ($type !== '') {
        $where[] = 'customer_type = ?';
        $types .= 's';
        $values[] = $type;
    }
    if ($search !== '') {
        $where[] = '(name LIKE ? OR customer_code LIKE ? OR email LIKE ? OR tax_identification_number LIKE ?)';
        $like = '%' . $search . '%';
        $types .= 'ssss';
        array_push($values, $like, $like, $like, $like);
    }
    $filterSql = implode(' AND ', $where);

    $count = $conn->prepare('SELECT COUNT(*) AS total FROM customers WHERE ' . $filterSql);
    bindCatalogueParams($count, $types, $values);
    $count->execute();
    $total = (int) ($count->get_result()->fetch_assoc()['total'] ?? 0);
    $count->close();

    $listValues = $values;
    $listTypes = $types . 'ii';
    $listValues[] = $limit;
    $listValues[] = $offset;
    $stmt = $conn->prepare(
        'SELECT id, customer_code, customer_type, name, email, phone, tax_identification_number, preferred_currency, city, state, country, '
        . 'is_active, archived_at, created_at, updated_at FROM customers WHERE ' . $filterSql . ' ORDER BY name ASC, id DESC LIMIT ? OFFSET ?'
    );
    bindCatalogueParams($stmt, $listTypes, $listValues);
    $stmt->execute();
    $customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return [
        'customers' => $customers,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 0,
        ],
    ];
}

function fetchItem(mysqli $conn, int $organizationId, int $itemId): ?array
{
    $stmt = $conn->prepare(
        'SELECT i.id, i.item_code, i.item_type, i.name, i.description, i.unit_of_measure_id, i.default_unit_price, i.currency, '
        . 'i.tax_rate_id, i.discount_allowed, i.nrs_item_classification_code, i.firs_item_type, i.firs_tax_category_code, i.firs_vat_exemption_code, i.firs_unit_code, i.is_active, i.archived_at, i.created_at, i.updated_at, '
        . 'u.code AS unit_code, u.name AS unit_name, u.symbol AS unit_symbol, '
        . 't.code AS tax_code, t.name AS tax_name, t.treatment AS tax_treatment, t.rate_percent AS tax_rate_percent '
        . 'FROM items i JOIN units_of_measure u ON u.id = i.unit_of_measure_id JOIN tax_rates t ON t.id = i.tax_rate_id '
        . 'WHERE i.organization_id = ? AND i.id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $organizationId, $itemId);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $item ?: null;
}

function listItems(mysqli $conn, int $organizationId): array
{
    [$page, $limit, $offset] = catalogueListPaging();
    $search = trim((string) ($_GET['search'] ?? ''));
    $type = strtolower(trim((string) ($_GET['type'] ?? '')));
    $status = strtolower(trim((string) ($_GET['status'] ?? 'active')));
    if ($type !== '' && !in_array($type, ['product', 'service'], true)) {
        throw new Exception('Select a valid item type filter.', 422);
    }
    if (!in_array($status, ['active', 'inactive', 'archived', 'all'], true)) {
        throw new Exception('Select a valid item status filter.', 422);
    }

    $where = ['i.organization_id = ?'];
    $types = 'i';
    $values = [$organizationId];
    if ($status === 'active') {
        $where[] = 'i.archived_at IS NULL AND i.is_active = 1';
    } elseif ($status === 'inactive') {
        $where[] = 'i.archived_at IS NULL AND i.is_active = 0';
    } elseif ($status === 'archived') {
        $where[] = 'i.archived_at IS NOT NULL';
    }
    if ($type !== '') {
        $where[] = 'i.item_type = ?';
        $types .= 's';
        $values[] = $type;
    }
    if ($search !== '') {
        $where[] = '(i.name LIKE ? OR i.item_code LIKE ? OR i.description LIKE ?)';
        $like = '%' . $search . '%';
        $types .= 'sss';
        array_push($values, $like, $like, $like);
    }
    $filterSql = implode(' AND ', $where);

    $count = $conn->prepare('SELECT COUNT(*) AS total FROM items i WHERE ' . $filterSql);
    bindCatalogueParams($count, $types, $values);
    $count->execute();
    $total = (int) ($count->get_result()->fetch_assoc()['total'] ?? 0);
    $count->close();

    $listValues = $values;
    $listTypes = $types . 'ii';
    $listValues[] = $limit;
    $listValues[] = $offset;
    $stmt = $conn->prepare(
        'SELECT i.id, i.item_code, i.item_type, i.name, i.default_unit_price, i.currency, i.discount_allowed, i.nrs_item_classification_code, i.firs_item_type, i.firs_tax_category_code, i.firs_vat_exemption_code, i.firs_unit_code, i.is_active, i.archived_at, '
        . 'u.code AS unit_code, u.name AS unit_name, t.code AS tax_code, t.name AS tax_name, t.treatment AS tax_treatment, t.rate_percent AS tax_rate_percent '
        . 'FROM items i JOIN units_of_measure u ON u.id = i.unit_of_measure_id JOIN tax_rates t ON t.id = i.tax_rate_id '
        . 'WHERE ' . $filterSql . ' ORDER BY i.name ASC, i.id DESC LIMIT ? OFFSET ?'
    );
    bindCatalogueParams($stmt, $listTypes, $listValues);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return [
        'items' => $items,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 0,
        ],
    ];
}
