<?php

declare(strict_types=1);

/** CLI-only offline queue preview; no NRS API keys or outgoing network calls. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (!in_array('--dry-run', $argv, true)) {
    fwrite(STDERR, "Usage: php scripts/nrsQueueWorker.php --dry-run [--limit=100]\n");
    exit(2);
}
require_once __DIR__ . '/../includes/connection.php';
require_once __DIR__ . '/../includes/nrsSubmissionQueue.php';

try {
    $limit = 100;
    foreach ($argv as $arg) if (preg_match('/^--limit=(\d+)$/', $arg, $match)) $limit = min(500, max(1, (int) $match[1]));
    $backfilled = nrsQueueBackfill($conn);
    $result = nrsQueueDryRun($conn, $limit);
    $alerts = nrsQueueRaiseOverdueAlerts($conn);
    echo json_encode(['mode' => 'dry_run', 'backfilled' => $backfilled, ...$result, 'new_overdue_alerts' => $alerts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "NRS dry-run worker failed: " . $e->getMessage() . "\n");
    exit(1);
}
