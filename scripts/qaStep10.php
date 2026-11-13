<?php
/**
 * DigitalInvoice Step 10 read-only backend release gate.
 * No Composer dependencies, writes, authentication attempts, or secret output.
 *
 * php scripts/qaStep10.php --mode=source
 * php scripts/qaStep10.php --mode=deployment --environment=staging --db
 * php scripts/qaStep10.php --mode=deployment --environment=production --db --frontend-origin=https://digitalinvoice.ng
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function qaStep10ParseArgs(array $argv): array
{
    $options = ['mode' => 'source', 'environment' => 'staging', 'db' => false];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--db') {
            $options['db'] = true;
            continue;
        }
        if (!preg_match('/^--(mode|environment|frontend-origin)=([^\s]+)$/', $argument, $match)) {
            throw new InvalidArgumentException('Invalid argument. Use --mode=source|deployment --environment=staging|production [--db] [--frontend-origin=URL].');
        }
        $options[$match[1]] = $match[2];
    }
    if (!in_array($options['mode'], ['source', 'deployment'], true)
        || !in_array($options['environment'], ['staging', 'production'], true)) {
        throw new InvalidArgumentException('Unknown mode or environment.');
    }
    if ($options['db'] && $options['mode'] !== 'deployment') {
        throw new InvalidArgumentException('--db requires --mode=deployment.');
    }
    return $options;
}

/** Read only the local .env for deployment checks; never echo or persist secrets. */
function qaStep10Env(string $root): array
{
    $env = [];
    $path = $root . '/.env';
    if (is_file($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=\s*(.*?)\s*$/', $line, $match)) {
                $value = trim($match[2]);
                if (strlen($value) >= 2 && $value[0] === $value[strlen($value) - 1]
                    && ($value[0] === '"' || $value[0] === "'")) {
                    $value = substr($value, 1, -1);
                } else {
                    $value = preg_replace('/\s+#.*$/', '', $value);
                }
                $env[$match[1]] = $value;
            }
        }
    }
    foreach (array_keys($env) as $key) {
        $override = getenv($key);
        if ($override !== false) $env[$key] = $override;
    }
    foreach (['APP_ENV', 'REQUIRE_HTTPS', 'COOKIE_SECURE', 'COOKIE_SAMESITE',
        'ALLOWED_ORIGINS', 'API_BASE_PATH', 'JWT_SECRET', 'MFA_ENCRYPTION_KEY',
        'FIELD_ENCRYPTION_KEY', 'ERP_INTEGRATIONS_ENABLED', 'ERP_WEBHOOKS_ENABLED',
        'NRS_SANDBOX_HTTP_ENABLED', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
        if (!array_key_exists($key, $env) && getenv($key) !== false) $env[$key] = (string) getenv($key);
    }
    return $env;
}

function qaStep10Enabled(array $env, string $key): bool
{
    return filter_var($env[$key] ?? '0', FILTER_VALIDATE_BOOLEAN);
}

function qaStep10Run(array $options, ?string $root = null, ?callable $out = null): array
{
    $root ??= dirname(__DIR__);
    $out ??= static function (string $line): void { echo $line . PHP_EOL; };
    $failures = [];
    $skips = [];
    $checks = 0;
    $check = static function (bool $ok, string $label) use (&$checks, &$failures, $out): void {
        $checks++;
        $out(($ok ? 'PASS  ' : 'FAIL  ') . $label);
        if (!$ok) $failures[] = $label;
    };
    $skip = static function (string $label) use (&$skips, $out): void {
        $skips[] = $label;
        $out('SKIP  ' . $label);
    };

    $check(version_compare(PHP_VERSION, '8.1.0', '>='), 'PHP >= 8.1');
    $check(is_file($root . '/index.php'), 'PHP API front controller exists');
    $check(is_file($root . '/.htaccess'), 'API Apache rules exist');
    if (is_file($root . '/.htaccess')) {
        $apache = file_get_contents($root . '/.htaccess') ?: '';
        $check(str_contains($apache, 'HTTP_AUTHORIZATION') && str_contains($apache, 'index.php'), 'API Apache forwards Bearer token and routes requests');
        $check(str_contains($apache, 'Require all denied') && str_contains($apache, '.env'), 'API Apache denies environment file requests');
    }
    $requiredMigrations = [
        '20261105_mfa.sql', '20261106_nrs_ubl_profile_fields.sql',
        '20261106_nrs_step3_queue.sql', '20261106_nrs_step4_document_evidence.sql',
        '20261107_erp_oauth_webhooks.sql', '20261107_invoice_buyer_address_snapshots.sql',
    ];
    foreach ($requiredMigrations as $file) $check(is_file($root . '/migrations/' . $file), "Migration supplied: $file");
    $router = is_file($root . '/index.php') ? (file_get_contents($root . '/index.php') ?: '') : '';
    $check(str_contains($router, 'POST /oauth/token') && str_contains($router, 'GET /integrations/v1/nrs-status'), 'ERP OAuth and read routes registered');
    $check(!str_contains($router, 'POST /integrations/v1/invoices') && !str_contains($router, 'POST /integrations/v1/nrs-status'), 'ERP exposes no NRS/invoice write route');
    $nrs = is_file($root . '/includes/nrsClient.php') ? (file_get_contents($root . '/includes/nrsClient.php') ?: '') : '';
    $check(str_contains($nrs, 'NRS production requests are locked') && str_contains($nrs, 'NRS_SANDBOX_HTTP_ENABLED'), 'NRS production blocked; sandbox requires explicit switch');
    $token = is_file($root . '/routes/integrations/token.php') ? (file_get_contents($root . '/routes/integrations/token.php') ?: '') : '';
    $check(str_contains($token, 'ERP_INTEGRATIONS_ENABLED'), 'ERP token issuance has a feature flag');
    $worker = is_file($root . '/scripts/dispatchErpWebhooks.php') ? (file_get_contents($root . '/scripts/dispatchErpWebhooks.php') ?: '') : '';
    $check(str_contains($worker, 'ERP_WEBHOOKS_ENABLED') && str_contains($worker, 'ERP_INTEGRATIONS_ENABLED'), 'Webhook worker has both feature gates');

    if ($options['mode'] === 'deployment') {
        foreach (['curl', 'mysqli', 'json', 'openssl'] as $extension) $check(extension_loaded($extension), "Required PHP extension loaded: $extension");
        $check(is_file($root . '/vendor/autoload.php'), 'Composer dependencies installed');
        $env = qaStep10Env($root);
        $check(strtolower($env['APP_ENV'] ?? '') === $options['environment'], 'APP_ENV matches deployment target');
        $check(!empty($env['JWT_SECRET']) && strlen((string) $env['JWT_SECRET']) >= 64, 'JWT_SECRET configured with at least 64 characters');
        foreach (['MFA_ENCRYPTION_KEY', 'FIELD_ENCRYPTION_KEY'] as $key) {
            $decoded = base64_decode((string) ($env[$key] ?? ''), true);
            $check($decoded !== false && strlen($decoded) === 32, "$key is a 32-byte base64 key");
        }
        $check(qaStep10Enabled($env, 'REQUIRE_HTTPS'), 'REQUIRE_HTTPS enabled');
        $check(qaStep10Enabled($env, 'COOKIE_SECURE'), 'Secure authentication cookies enabled');
        $check(!qaStep10Enabled($env, 'NRS_SANDBOX_HTTP_ENABLED') || $options['environment'] !== 'production', 'Production NRS sandbox HTTP remains disabled');
        if ($options['environment'] === 'production') {
            $check(!qaStep10Enabled($env, 'ERP_INTEGRATIONS_ENABLED') && !qaStep10Enabled($env, 'ERP_WEBHOOKS_ENABLED'), 'Production ERP flags remain disabled until signed-off staging');
        } else {
            $skip('ERP staging flags depend on the documented OAuth/webhook rollout sequence');
        }
        $origins = array_filter(array_map('trim', explode(',', (string) ($env['ALLOWED_ORIGINS'] ?? ''))));
        $check(count($origins) > 0 && !in_array('*', $origins, true), 'CORS ALLOWED_ORIGINS contains explicit origins (never *)');
        if (!empty($options['frontend-origin'])) {
            $check(in_array($options['frontend-origin'], $origins, true), 'Expected frontend origin is in API CORS allowlist');
        }
        if (!empty($options['db'])) {
            if (!extension_loaded('mysqli')) {
                $check(false, 'Database schema verification requires ext-mysqli');
            } else {
                try {
                    $connection = new mysqli((string) ($env['DB_HOST'] ?? 'localhost'),
                        (string) ($env['DB_USERNAME'] ?? ''), (string) ($env['DB_PASSWORD'] ?? ''),
                        (string) ($env['DB_NAME'] ?? ''), (int) ($env['DB_PORT'] ?? 3306));
                    $connection->set_charset('utf8mb4');
                    $schema = [
                        'invoices' => ['customer_city_snapshot', 'customer_nrs_postal_zone_snapshot', 'customer_nrs_state_code_snapshot', 'customer_nrs_lga_code_snapshot'],
                        'nrs_document_evidence' => [],
                        'erp_oauth_clients' => [], 'erp_oauth_tokens' => [],
                        'erp_webhook_endpoints' => [], 'erp_webhook_events' => [], 'erp_webhook_deliveries' => [],
                        'auth_mfa_credentials' => [], 'nrs_submission_queue' => [],
                    ];
                    $tables = $connection->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()");
                    $found = [];
                    while ($record = $tables->fetch_assoc()) $found[$record['TABLE_NAME']][] = $record['COLUMN_NAME'];
                    foreach ($schema as $table => $columns) {
                        $check(isset($found[$table]), "Database table exists: $table");
                        foreach ($columns as $column) {
                            $check(in_array($column, $found[$table] ?? [], true), "Database column exists: $table.$column");
                        }
                    }
                    $connection->close();
                } catch (Throwable $exception) {
                    $check(false, 'Read-only database connection/schema inspection failed (inspect local DB logs)');
                }
            }
        } else $skip('Database schema not checked; rerun --db in staging/production');
    } else {
        $skip('PHP runtime extensions, secrets, Composer dependencies, and database require --mode=deployment');
    }
    $out('RESULT ' . (count($failures) ? 'BLOCKED' : 'CHECKS PASSED') . ": $checks checks, " . count($failures) . ' failures, ' . count($skips) . ' skipped scopes');
    return ['ok' => count($failures) === 0, 'checks' => $checks, 'failures' => $failures, 'skips' => $skips];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $result = qaStep10Run(qaStep10ParseArgs($argv));
        exit($result['ok'] ? 0 : 1);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'QA configuration error: ' . $exception->getMessage() . PHP_EOL);
        exit(2);
    }
}
