<?php

declare(strict_types=1);

require_once __DIR__ . '/authMiddleware.php';
require_once __DIR__ . '/../utils/mailer.php';
require_once __DIR__ . '/../utils/emailTemplates.php';

function premiumRequestStatuses(): array
{
    return ['new', 'contacted', 'negotiating', 'converted', 'closed'];
}

function premiumRequestTableReady(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $result = $conn->query("SHOW TABLES LIKE 'premium_plan_requests'");
        $ready = $result && $result->num_rows > 0;
        if ($result) {
            $result->free();
        }
        return $ready;
    } catch (Throwable) {
        $ready = false;
        return false;
    }
}

function premiumRequestRequireTable(mysqli $conn): void
{
    if (!premiumRequestTableReady($conn)) {
        throw new Exception('Premium request table is not available. Run the premium request migration first.', 500);
    }
}

function premiumRequestQueryRow(mysqli $conn, string $sql, string $types = '', array $values = []): array
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$values);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

function premiumRequestQueryRows(mysqli $conn, string $sql, string $types = '', array $values = []): array
{
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$values);
    }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function premiumRequestPaginationParams(array $filters, int $defaultLimit = 10, int $maxLimit = 50): array
{
    $page = max(1, (int) ($filters['page'] ?? 1));
    $limit = min($maxLimit, max(1, (int) ($filters['limit'] ?? $defaultLimit)));
    return ['page' => $page, 'limit' => $limit, 'offset' => ($page - 1) * $limit];
}

function premiumRequestPaginationPayload(int $total, int $page, int $limit): array
{
    return [
        'page' => $page,
        'limit' => $limit,
        'total' => $total,
        'total_pages' => (int) max(1, ceil($total / max(1, $limit))),
    ];
}

function premiumRequestCleanText(mixed $value, int $maxLength): string
{
    $text = trim((string) $value);
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return mb_substr($text, 0, $maxLength);
}

function premiumRequestCleanLongText(mixed $value, int $maxLength): string
{
    $text = trim((string) $value);
    return mb_substr($text, 0, $maxLength);
}

function premiumRequestFeatureList(mixed $features): array
{
    if (is_string($features)) {
        $decoded = json_decode($features, true);
        $features = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $features)));
    }

    if (!is_array($features)) {
        return [];
    }

    $clean = [];
    foreach ($features as $feature) {
        $value = premiumRequestCleanText($feature, 90);
        if ($value !== '' && !in_array($value, $clean, true)) {
            $clean[] = $value;
        }
    }

    return array_values(array_slice($clean, 0, 12));
}

function premiumRequestPayload(array $row): array
{
    $features = [];
    if (isset($row['required_features'])) {
        $decoded = json_decode((string) $row['required_features'], true);
        $features = is_array($decoded) ? $decoded : [];
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'organization_id' => isset($row['organization_id']) ? (int) $row['organization_id'] : null,
        'requested_by_user_id' => isset($row['requested_by_user_id']) ? (int) $row['requested_by_user_id'] : null,
        'assigned_to_user_id' => isset($row['assigned_to_user_id']) ? (int) $row['assigned_to_user_id'] : null,
        'business_name' => (string) ($row['business_name'] ?? ''),
        'contact_name' => (string) ($row['contact_name'] ?? ''),
        'contact_email' => (string) ($row['contact_email'] ?? ''),
        'contact_phone' => $row['contact_phone'] ?? null,
        'expected_invoice_volume' => $row['expected_invoice_volume'] ?? null,
        'expected_team_size' => $row['expected_team_size'] ?? null,
        'required_features' => $features,
        'message' => $row['message'] ?? null,
        'admin_note' => $row['admin_note'] ?? null,
        'status' => (string) ($row['status'] ?? 'new'),
        'contacted_at' => $row['contacted_at'] ?? null,
        'converted_at' => $row['converted_at'] ?? null,
        'closed_at' => $row['closed_at'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
        'organization_name' => $row['organization_name'] ?? null,
        'organization_email' => $row['organization_email'] ?? null,
        'requested_by_name' => $row['requested_by_name'] ?? null,
        'requested_by_email' => $row['requested_by_email'] ?? null,
        'assigned_to_name' => $row['assigned_to_name'] ?? null,
        'assigned_to_email' => $row['assigned_to_email'] ?? null,
    ];
}

function premiumRequestFetchById(mysqli $conn, int $requestId): ?array
{
    $row = premiumRequestQueryRow(
        $conn,
        'SELECT pr.*, o.name AS organization_name, o.business_email AS organization_email, requester.name AS requested_by_name, requester.email AS requested_by_email, assigned.name AS assigned_to_name, assigned.email AS assigned_to_email '
        . 'FROM premium_plan_requests pr '
        . 'LEFT JOIN organizations o ON o.id = pr.organization_id '
        . 'LEFT JOIN users requester ON requester.id = pr.requested_by_user_id '
        . 'LEFT JOIN users assigned ON assigned.id = pr.assigned_to_user_id '
        . 'WHERE pr.id = ? LIMIT 1',
        'i',
        [$requestId]
    );

    return $row ? premiumRequestPayload($row) : null;
}

function premiumRequestNotificationEmailAddress(mysqli $conn): string
{
    $configured = trim((string) config('PREMIUM_REQUEST_NOTIFICATION_EMAIL', ''));
    if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_EMAIL)) {
        return $configured;
    }

    try {
        $row = premiumRequestQueryRow($conn, "SELECT setting_value FROM platform_settings WHERE setting_key = 'billing_email' LIMIT 1");
        $email = trim((string) ($row['setting_value'] ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }
    } catch (Throwable) {
        // Fall back to support mail below.
    }

    $support = trim((string) mailSupportEmail());
    return filter_var($support, FILTER_VALIDATE_EMAIL) ? $support : '';
}

function premiumRequestSendEmails(mysqli $conn, array $request): array
{
    $statuses = [];
    $requestId = (int) $request['id'];
    $organizationId = $request['organization_id'] !== null ? (int) $request['organization_id'] : null;
    $userId = $request['requested_by_user_id'] !== null ? (int) $request['requested_by_user_id'] : null;

    $confirmation = sendTransactionalMail(
        $conn,
        'premium.request_confirmation',
        (string) $request['contact_email'],
        (string) $request['contact_name'],
        'We received your DigitalInvoice Premium request',
        premiumRequestConfirmationEmail((string) $request['contact_name'], (string) $request['business_name']),
        $userId,
        $organizationId,
        'premium_request_confirmation:' . $requestId,
        ['premium_request_id' => $requestId]
    );
    $statuses['confirmation'] = $confirmation['status'];

    $notificationEmail = premiumRequestNotificationEmailAddress($conn);
    if ($notificationEmail !== '') {
        $notification = sendTransactionalMail(
            $conn,
            'premium.internal_notification',
            $notificationEmail,
            config('PREMIUM_REQUEST_NOTIFICATION_NAME', appName()) ?? appName(),
            'New DigitalInvoice Premium request from ' . (string) $request['business_name'],
            premiumRequestNotificationEmail($request),
            $userId,
            $organizationId,
            'premium_request_internal:' . $requestId,
            ['premium_request_id' => $requestId]
        );
        $statuses['internal_notification'] = $notification['status'];
    }

    return $statuses;
}

function createPremiumRequest(mysqli $conn, array $user, array $input): array
{
    premiumRequestRequireTable($conn);

    $role = (string) ($user['role'] ?? '');
    if (!in_array($role, ['owner', 'admin'], true)) {
        throw new Exception('Only a workspace owner or admin can request a Premium plan.', 403);
    }

    $organizationId = (int) ($user['organization_id'] ?? 0);
    if ($organizationId < 1) {
        throw new Exception('A client workspace is required to request Premium.', 422);
    }

    $organization = premiumRequestQueryRow($conn, 'SELECT id, name, business_email, phone FROM organizations WHERE id = ? LIMIT 1', 'i', [$organizationId]);
    if (!$organization) {
        throw new Exception('Workspace was not found.', 404);
    }

    $businessName = premiumRequestCleanText($input['business_name'] ?? $organization['name'] ?? '', 180);
    $contactName = premiumRequestCleanText($input['contact_name'] ?? $user['name'] ?? '', 120);
    $contactEmail = strtolower(premiumRequestCleanText($input['contact_email'] ?? $user['email'] ?? $organization['business_email'] ?? '', 190));
    $contactPhone = premiumRequestCleanText($input['contact_phone'] ?? $input['phone'] ?? $organization['phone'] ?? '', 30);
    $invoiceVolume = premiumRequestCleanText($input['expected_invoice_volume'] ?? '', 80);
    $teamSize = premiumRequestCleanText($input['expected_team_size'] ?? '', 80);
    $message = premiumRequestCleanLongText($input['message'] ?? '', 2500);
    $features = premiumRequestFeatureList($input['required_features'] ?? []);

    if ($businessName === '' || $contactName === '' || !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Business name, contact person and a valid contact email are required.', 422);
    }
    if ($message === '') {
        throw new Exception('Please describe what you need in the Premium setup.', 422);
    }

    $featuresJson = json_encode($features, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    $contactPhoneValue = $contactPhone !== '' ? $contactPhone : null;
    $invoiceVolumeValue = $invoiceVolume !== '' ? $invoiceVolume : null;
    $teamSizeValue = $teamSize !== '' ? $teamSize : null;
    $userId = (int) $user['id'];
    $openStatuses = ['new', 'contacted', 'negotiating'];

    $existing = premiumRequestQueryRow(
        $conn,
        "SELECT id FROM premium_plan_requests WHERE organization_id = ? AND status IN ('new','contacted','negotiating') ORDER BY id DESC LIMIT 1",
        'i',
        [$organizationId]
    );

    if ($existing) {
        $requestId = (int) $existing['id'];
        $stmt = $conn->prepare(
            'UPDATE premium_plan_requests SET business_name = ?, contact_name = ?, contact_email = ?, contact_phone = ?, expected_invoice_volume = ?, expected_team_size = ?, required_features = ?, message = ?, requested_by_user_id = ?, updated_at = NOW() WHERE id = ?'
        );
        $stmt->bind_param('ssssssssii', $businessName, $contactName, $contactEmail, $contactPhoneValue, $invoiceVolumeValue, $teamSizeValue, $featuresJson, $message, $userId, $requestId);
        $stmt->execute();
        $stmt->close();

        auditLog($conn, $userId, 'premium.request_updated', 'Premium request details updated by the workspace.', $organizationId, json_encode(['premium_request_id' => $requestId], JSON_UNESCAPED_SLASHES));
        $request = premiumRequestFetchById($conn, $requestId);
        return ['request' => $request, 'mode' => 'updated', 'email_delivery_status' => []];
    }

    $stmt = $conn->prepare(
        'INSERT INTO premium_plan_requests (organization_id, requested_by_user_id, business_name, contact_name, contact_email, contact_phone, expected_invoice_volume, expected_team_size, required_features, message, status, created_at, updated_at) '
        . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', NOW(), NOW())"
    );
    $stmt->bind_param('iissssssss', $organizationId, $userId, $businessName, $contactName, $contactEmail, $contactPhoneValue, $invoiceVolumeValue, $teamSizeValue, $featuresJson, $message);
    $stmt->execute();
    $requestId = (int) $stmt->insert_id;
    $stmt->close();

    auditLog($conn, $userId, 'premium.request_created', 'Premium request submitted by the workspace.', $organizationId, json_encode(['premium_request_id' => $requestId], JSON_UNESCAPED_SLASHES));

    $request = premiumRequestFetchById($conn, $requestId);
    $emailStatus = $request ? premiumRequestSendEmails($conn, $request) : [];

    return ['request' => $request, 'mode' => 'created', 'email_delivery_status' => $emailStatus];
}

function fetchPlatformPremiumRequests(mysqli $conn, array $filters = []): array
{
    premiumRequestRequireTable($conn);

    $search = trim((string) ($filters['search'] ?? ''));
    $status = strtolower(trim((string) ($filters['status'] ?? '')));
    $pagination = premiumRequestPaginationParams($filters, 10, 50);
    $page = $pagination['page'];
    $limit = $pagination['limit'];
    $offset = $pagination['offset'];

    $where = [];
    $types = '';
    $values = [];

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(pr.business_name LIKE ? OR pr.contact_name LIKE ? OR pr.contact_email LIKE ? OR pr.contact_phone LIKE ? OR o.name LIKE ? OR requester.name LIKE ? OR requester.email LIKE ?)';
        $types .= 'sssssss';
        array_push($values, $like, $like, $like, $like, $like, $like, $like);
    }

    if ($status !== '' && in_array($status, premiumRequestStatuses(), true)) {
        $where[] = 'pr.status = ?';
        $types .= 's';
        $values[] = $status;
    }

    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $summaryRows = premiumRequestQueryRows($conn, 'SELECT status, COUNT(*) AS total FROM premium_plan_requests GROUP BY status');
    $summary = ['total_requests' => 0, 'new_requests' => 0, 'contacted_requests' => 0, 'negotiating_requests' => 0, 'converted_requests' => 0, 'closed_requests' => 0, 'open_requests' => 0];
    foreach ($summaryRows as $row) {
        $key = (string) $row['status'];
        $total = (int) $row['total'];
        $summary['total_requests'] += $total;
        $summary[$key . '_requests'] = $total;
        if (in_array($key, ['new', 'contacted', 'negotiating'], true)) {
            $summary['open_requests'] += $total;
        }
    }

    $totalRow = premiumRequestQueryRow(
        $conn,
        'SELECT COUNT(*) AS total FROM premium_plan_requests pr LEFT JOIN organizations o ON o.id = pr.organization_id LEFT JOIN users requester ON requester.id = pr.requested_by_user_id' . $whereSql,
        $types,
        $values
    );
    $total = (int) ($totalRow['total'] ?? 0);

    $rows = premiumRequestQueryRows(
        $conn,
        'SELECT pr.*, o.name AS organization_name, o.business_email AS organization_email, requester.name AS requested_by_name, requester.email AS requested_by_email, assigned.name AS assigned_to_name, assigned.email AS assigned_to_email '
        . 'FROM premium_plan_requests pr '
        . 'LEFT JOIN organizations o ON o.id = pr.organization_id '
        . 'LEFT JOIN users requester ON requester.id = pr.requested_by_user_id '
        . 'LEFT JOIN users assigned ON assigned.id = pr.assigned_to_user_id'
        . $whereSql
        . ' ORDER BY FIELD(pr.status, \'new\', \'contacted\', \'negotiating\', \'converted\', \'closed\'), pr.created_at DESC, pr.id DESC LIMIT ? OFFSET ?',
        $types . 'ii',
        [...$values, $limit, $offset]
    );

    return [
        'generated_at' => date('Y-m-d H:i:s'),
        'summary' => $summary,
        'filters' => ['search' => $search, 'status' => $status],
        'pagination' => premiumRequestPaginationPayload($total, $page, $limit),
        'requests' => array_map('premiumRequestPayload', $rows),
        'status_options' => premiumRequestStatuses(),
    ];
}

function updatePlatformPremiumRequest(mysqli $conn, array $actor, int $requestId, array $input): array
{
    premiumRequestRequireTable($conn);

    if ($requestId < 1) {
        throw new Exception('Select a valid Premium request.', 422);
    }

    $existing = premiumRequestFetchById($conn, $requestId);
    if (!$existing) {
        throw new Exception('Premium request was not found.', 404);
    }

    $status = strtolower(trim((string) ($input['status'] ?? $existing['status'] ?? 'new')));
    if (!in_array($status, premiumRequestStatuses(), true)) {
        throw new Exception('Select a valid Premium request status.', 422);
    }

    $adminNote = premiumRequestCleanLongText($input['admin_note'] ?? $existing['admin_note'] ?? '', 5000);
    $assignedTo = isset($input['assigned_to_user_id']) && (int) $input['assigned_to_user_id'] > 0 ? (int) $input['assigned_to_user_id'] : null;

    if ($assignedTo !== null) {
        $assigned = premiumRequestQueryRow($conn, "SELECT pau.user_id FROM platform_admin_users pau WHERE pau.user_id = ? AND pau.status = 'active' LIMIT 1", 'i', [$assignedTo]);
        if (!$assigned) {
            throw new Exception('Assign the request to an active platform user.', 422);
        }
    }

    $stmt = $conn->prepare(
        'UPDATE premium_plan_requests SET status = ?, admin_note = NULLIF(?, \'\'), assigned_to_user_id = ?, '
        . "contacted_at = CASE WHEN ? IN ('contacted','negotiating') THEN COALESCE(contacted_at, NOW()) ELSE contacted_at END, "
        . "converted_at = CASE WHEN ? = 'converted' THEN COALESCE(converted_at, NOW()) ELSE converted_at END, "
        . "closed_at = CASE WHEN ? = 'closed' THEN COALESCE(closed_at, NOW()) ELSE closed_at END, updated_at = NOW() WHERE id = ?"
    );
    $stmt->bind_param('ssisssi', $status, $adminNote, $assignedTo, $status, $status, $status, $requestId);
    $stmt->execute();
    $stmt->close();

    auditLog(
        $conn,
        (int) $actor['id'],
        'platform.premium_request_updated',
        'Premium request updated from the owner console.',
        $existing['organization_id'] !== null ? (int) $existing['organization_id'] : null,
        json_encode(['premium_request_id' => $requestId, 'status' => $status], JSON_UNESCAPED_SLASHES)
    );

    return premiumRequestFetchById($conn, $requestId) ?: [];
}
