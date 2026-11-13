<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/masterDataService.php';

function activityGroupClause(string $group): array
{
    return match ($group) {
        'auth' => [" AND al.action LIKE 'auth.%'", []],
        'subscription' => [" AND al.action LIKE 'subscription.%'", []],
        'organization' => [" AND (al.action LIKE 'organization.%' OR al.action LIKE 'onboarding.%')", []],
        'customers' => [" AND al.action LIKE 'customer.%'", []],
        'catalogue' => [" AND al.action LIKE 'catalogue.%'", []],
        'invoices' => [" AND al.action LIKE 'invoice.%'", []],
        'payments' => [" AND al.action LIKE 'payment.%'", []],
        'team' => [" AND al.action LIKE 'team.%'", []],
        default => ['', []],
    };
}

function activityDateParam(mixed $value, string $label): ?string
{
    $date = trim((string) ($value ?? ''));
    if ($date === '') {
        return null;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new Exception($label . ' must be a valid date in YYYY-MM-DD format.', 422);
    }
    return $date;
}

try {
    $user = requirePermission('organization.manage', true);
    requireEntitledFeature($conn, $user, 'activity_log');
    $organizationId = (int) $user['organization_id'];

    [$page, $limit, $offset] = catalogueListPaging();
    $search = trim((string) ($_GET['search'] ?? ''));
    if (mb_strlen($search) > 120) {
        throw new Exception('Search text is too long.', 422);
    }
    $group = strtolower(trim((string) ($_GET['group'] ?? 'all')));
    $from = activityDateParam($_GET['from'] ?? null, 'From date');
    $to = activityDateParam($_GET['to'] ?? null, 'To date');
    if ($from !== null && $to !== null && $to < $from) {
        throw new Exception('To date cannot be earlier than from date.', 422);
    }

    $where = ' WHERE al.organization_id = ?';
    $types = 'i';
    $values = [$organizationId];

    [$groupSql] = activityGroupClause($group);
    $where .= $groupSql;

    if ($search !== '') {
        $where .= ' AND (al.action LIKE ? OR al.description LIKE ? OR u.name LIKE ? OR u.email LIKE ?)';
        $like = '%' . $search . '%';
        array_push($values, $like, $like, $like, $like);
        $types .= 'ssss';
    }
    if ($from !== null) {
        $where .= ' AND DATE(al.created_at) >= ?';
        $values[] = $from;
        $types .= 's';
    }
    if ($to !== null) {
        $where .= ' AND DATE(al.created_at) <= ?';
        $values[] = $to;
        $types .= 's';
    }

    $countSql = "SELECT COUNT(*) AS total FROM activity_log al LEFT JOIN users u ON u.id = al.user_id" . $where;
    $countStmt = $conn->prepare($countSql);
    bindCatalogueParams($countStmt, $types, $values);
    $countStmt->execute();
    $total = (int) (($countStmt->get_result()->fetch_assoc()['total'] ?? 0));
    $countStmt->close();

    $rowsSql = "SELECT al.id, al.user_id, al.action, al.description, al.ip_address, al.metadata, al.created_at, u.name AS user_name, u.email AS user_email "
        . "FROM activity_log al LEFT JOIN users u ON u.id = al.user_id"
        . $where
        . ' ORDER BY al.created_at DESC, al.id DESC LIMIT ? OFFSET ?';
    $rowTypes = $types . 'ii';
    $rowValues = array_merge($values, [$limit, $offset]);
    $stmt = $conn->prepare($rowsSql);
    bindCatalogueParams($stmt, $rowTypes, $rowValues);
    $stmt->execute();
    $activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($activities as &$activity) {
        $decoded = null;
        if (!empty($activity['metadata'])) {
            $decoded = json_decode((string) $activity['metadata'], true);
        }
        $activity['id'] = (int) $activity['id'];
        $activity['user_id'] = isset($activity['user_id']) ? (int) $activity['user_id'] : null;
        $activity['metadata'] = is_array($decoded) ? $decoded : null;
    }
    unset($activity);

    $summaryStmt = $conn->prepare(
        "SELECT "
        . "SUM(CASE WHEN action LIKE 'invoice.%' THEN 1 ELSE 0 END) AS invoice_events, "
        . "SUM(CASE WHEN action LIKE 'payment.%' THEN 1 ELSE 0 END) AS payment_events, "
        . "SUM(CASE WHEN action LIKE 'auth.%' THEN 1 ELSE 0 END) AS auth_events, "
        . "SUM(CASE WHEN action LIKE 'team.%' THEN 1 ELSE 0 END) AS team_events, "
        . "COUNT(*) AS total_events "
        . "FROM activity_log WHERE organization_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    $summaryStmt->bind_param('i', $organizationId);
    $summaryStmt->execute();
    $summary = $summaryStmt->get_result()->fetch_assoc() ?: [];
    $summaryStmt->close();

    jsonSuccess([
        'data' => [
            'activities' => $activities,
            'summary' => [
                'total_events' => (int) ($summary['total_events'] ?? 0),
                'invoice_events' => (int) ($summary['invoice_events'] ?? 0),
                'payment_events' => (int) ($summary['payment_events'] ?? 0),
                'auth_events' => (int) ($summary['auth_events'] ?? 0),
                'team_events' => (int) ($summary['team_events'] ?? 0),
            ],
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => (int) max(1, ceil($total / $limit)),
            ],
        ],
    ]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to load activity log.', ($code >= 400 && $code <= 599) ? $code : 500);
}
