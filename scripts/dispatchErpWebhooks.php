<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/connection.php';
require_once __DIR__ . '/../includes/erpWebhookService.php';
if (!configBool('ERP_WEBHOOKS_ENABLED', false) || !configBool('ERP_INTEGRATIONS_ENABLED', false)) {
    fwrite(STDERR, "ERP webhook dispatch is disabled. Set ERP_WEBHOOKS_ENABLED=1 after testing.\n");
    exit(2);
}
try {
    $limit = max(1, min(100, (int) ($argv[1] ?? 25)));
    $result = erpWebhookProcessBatch($conn, $limit);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    error_log('ERP webhook dispatcher: ' . $error->getMessage());
    fwrite(STDERR, "ERP webhook delivery batch failed.\n");
    exit(1);
}
