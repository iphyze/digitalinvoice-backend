<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/platformService.php';

try {
    requirePlatformAdmin();
    jsonSuccess(['data' => platformDashboardOverview($conn)]);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    if ($code < 400 || $code > 599) {
        error_log('Platform dashboard error: ' . $e->getMessage());
        $code = 500;
    }
    jsonError($code === 500 ? 'Unable to load platform dashboard.' : $e->getMessage(), $code);
}
