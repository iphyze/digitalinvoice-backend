<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Firebase\JWT\JWT;

/**
 * First valid email address among the given config keys, or null.
 * Used for every default contact/sender address so mail never goes to a domain
 * we don't own (older defaults pointed at digitalinvoice.com / digitalinvoiceng.com).
 */
function firstConfiguredEmail(string ...$keys): ?string
{
    foreach ($keys as $key) {
        $value = trim((string) (config($key, '') ?? ''));
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return $value;
        }
    }

    return null;
}

/** Default support/reply-to address: MAIL_SUPPORT_EMAIL, MAIL_REPLY_TO_ADDRESS, then MAIL_FROM_ADDRESS. */
function defaultSupportEmail(): string
{
    return firstConfiguredEmail('MAIL_SUPPORT_EMAIL', 'MAIL_REPLY_TO_ADDRESS', 'MAIL_FROM_ADDRESS', 'SMTP_USERNAME') ?? '';
}

function loadEnvironment(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $root = dirname(__DIR__);
    if (file_exists($root . '/.env')) {
        Dotenv::createImmutable($root)->safeLoad();
    }

    $loaded = true;
}

function config(string $key, ?string $default = null): ?string
{
    loadEnvironment();
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return (string) $value;
}

function requiredConfig(string $key): string
{
    $value = trim((string) config($key, ''));
    if ($value === '') {
        throw new RuntimeException("Required environment variable {$key} is not configured.");
    }

    return $value;
}

function configBool(string $key, bool $default = false): bool
{
    $value = config($key);
    if ($value === null) {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
}

function appName(): string
{
    return config('APP_NAME', 'DigitalInvoice Nigeria') ?? 'DigitalInvoice Nigeria';
}

function isLocalEnvironment(): bool
{
    return in_array(strtolower(config('APP_ENV', 'production') ?? 'production'), ['local', 'development', 'dev'], true);
}

function apiBasePath(): string
{
    return rtrim(config('API_BASE_PATH', '/digitalinvoice-server/api') ?? '/digitalinvoice-server/api', '/');
}

function cookieSecure(): bool
{
    return configBool('COOKIE_SECURE', !isLocalEnvironment());
}

function cookieSameSite(): string
{
    $configured = ucfirst(strtolower(config('COOKIE_SAMESITE', 'Lax') ?? 'Lax'));
    return in_array($configured, ['Strict', 'Lax', 'None'], true) ? $configured : 'Lax';
}

function cookieDomain(): ?string
{
    return config('COOKIE_DOMAIN');
}

function accessCookieName(): string
{
    return config('AUTH_ACCESS_COOKIE', 'digitalinvoice_access') ?? 'digitalinvoice_access';
}

function refreshCookieName(): string
{
    return config('AUTH_REFRESH_COOKIE', 'digitalinvoice_refresh') ?? 'digitalinvoice_refresh';
}

function csrfCookieName(): string
{
    return config('AUTH_CSRF_COOKIE', 'digitalinvoice_csrf') ?? 'digitalinvoice_csrf';
}

function accessTokenTtlSeconds(): int
{
    return max(300, (int) (config('JWT_ACCESS_EXPIRES_IN', '900') ?? '900'));
}

function accessTokenRefreshThresholdSeconds(): int
{
    $default = (string) max(120, min(300, accessTokenTtlSeconds() - 60));
    $configured = max(60, (int) (config('JWT_ACCESS_REFRESH_THRESHOLD', $default) ?? $default));

    return min($configured, max(60, accessTokenTtlSeconds() - 60));
}

function refreshTokenTtlSeconds(): int
{
    return max(900, (int) (config('JWT_REFRESH_EXPIRES_IN', '43200') ?? '43200'));
}

function sessionIdleTimeoutSeconds(): int
{
    return max(300, (int) (config('SESSION_IDLE_TIMEOUT', '1800') ?? '1800'));
}

function sessionAbsoluteTimeoutSeconds(): int
{
    return max(900, (int) (config('SESSION_ABSOLUTE_TIMEOUT', '43200') ?? '43200'));
}

function sessionActivityTouchIntervalSeconds(): int
{
    return max(0, (int) (config('SESSION_ACTIVITY_TOUCH_INTERVAL', '60') ?? '60'));
}

function csrfTokenTtlSeconds(): int
{
    return max(300, (int) (config('CSRF_TOKEN_EXPIRES_IN', '43200') ?? '43200'));
}

function setAppCookie(string $name, string $value, int $expiresAt, string $path, bool $httpOnly): void
{
    $options = [
        'expires' => $expiresAt,
        'path' => $path,
        'secure' => cookieSecure(),
        'httponly' => $httpOnly,
        'samesite' => cookieSameSite(),
    ];

    $domain = cookieDomain();
    if ($domain !== null && trim($domain) !== '') {
        $options['domain'] = trim($domain);
    }

    setcookie($name, $value, $options);
}

function issueAuthCookies(string $accessToken, string $refreshToken, string $csrfToken, int $sessionExpiresAt): void
{
    $now = time();
    $accessExpiresAt = min($now + accessTokenTtlSeconds(), $sessionExpiresAt);
    $basePath = apiBasePath();

    setAppCookie(accessCookieName(), $accessToken, $accessExpiresAt, $basePath, true);
    setAppCookie(refreshCookieName(), $refreshToken, $sessionExpiresAt, $basePath . '/auth', true);
    setAppCookie(csrfCookieName(), $csrfToken, $sessionExpiresAt, $basePath, false);
}

function issueAccessCookie(string $accessToken, int $sessionExpiresAt): void
{
    $now = time();
    $accessExpiresAt = min($now + accessTokenTtlSeconds(), $sessionExpiresAt);
    setAppCookie(accessCookieName(), $accessToken, $accessExpiresAt, apiBasePath(), true);
}

function issueCsrfCookie(string $csrfToken): void
{
    setAppCookie(csrfCookieName(), $csrfToken, time() + csrfTokenTtlSeconds(), apiBasePath(), false);
}

function clearAccessCookie(): void
{
    setAppCookie(accessCookieName(), '', time() - 3600, apiBasePath(), true);
}

function clearAuthCookies(): void
{
    $expired = time() - 3600;
    $basePath = apiBasePath();
    clearAccessCookie();
    setAppCookie(refreshCookieName(), '', $expired, $basePath . '/auth', true);
    setAppCookie(csrfCookieName(), '', $expired, $basePath, false);
}

function randomUrlSafeToken(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        throw new Exception('Invalid request format. Expected JSON.', 400);
    }

    return $data;
}

function requestHeader(string $name): ?string
{
    $normalisedName = strtoupper(str_replace('-', '_', $name));
    $key = 'HTTP_' . $normalisedName;
    $value = $_SERVER[$key] ?? null;

    // Some Apache/FastCGI and cPanel configurations expose Authorization under this fallback key.
    if (($value === null || $value === '') && $normalisedName === 'AUTHORIZATION') {
        $value = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    }

    if (($value === null || $value === '') && function_exists('getallheaders')) {
        foreach (getallheaders() as $headerName => $headerValue) {
            if (strcasecmp((string) $headerName, $name) === 0) {
                $value = $headerValue;
                break;
            }
        }
    }

    return is_string($value) && trim($value) !== '' ? trim($value) : null;
}

function csrfBearerEnabled(): bool
{
    return configBool('AUTH_CSRF_BEARER_ENABLED', true);
}

function bearerTokenFromRequest(): ?string
{
    $authorization = trim((string) (requestHeader('Authorization') ?? ''));
    if ($authorization === '' || preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) !== 1) {
        return null;
    }

    $token = trim((string) ($matches[1] ?? ''));
    return $token !== '' ? $token : null;
}

function csrfTokenFromRequest(): string
{
    $csrfHeader = trim((string) (requestHeader('X-CSRF-Token') ?? ''));
    if ($csrfHeader !== '') {
        return $csrfHeader;
    }

    if (csrfBearerEnabled()) {
        return bearerTokenFromRequest() ?? '';
    }

    return '';
}

function requireCsrfProtection(): void
{
    if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }

    $cookieToken = (string) ($_COOKIE[csrfCookieName()] ?? '');
    $requestToken = csrfTokenFromRequest();

    if ($cookieToken === '' || $requestToken === '' || !hash_equals($cookieToken, $requestToken)) {
        jsonError('Security validation failed. Send the CSRF token as Authorization: Bearer <token> or X-CSRF-Token, then try again.', 419);
    }
}

function allowedOrigins(): array
{
    $raw = config('ALLOWED_ORIGINS', '') ?? '';
    return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

function secureRequestTransport(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    $trusted = array_filter(array_map('trim', explode(',', config('TRUSTED_PROXY_IPS', '') ?? '')));
    return in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), $trusted, true)
        && strtolower(trim((string) (requestHeader('X-Forwarded-Proto') ?? ''))) === 'https';
}

function applyApiSecurityHeaders(): void
{
    if (secureRequestTransport()) {
        header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
    } elseif (configBool('REQUIRE_HTTPS', !isLocalEnvironment())) {
        jsonError('Secure HTTPS transport is required.', 403);
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        if (!in_array($origin, allowedOrigins(), true)) {
            jsonError('Origin is not permitted.', 403);
        }

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-Requested-With');
    header('Access-Control-Max-Age: 600');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function clientIpAddress(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function userAgent(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 255);
}

function jwtIssuer(): string
{
    return config('JWT_ISSUER', config('APP_URL', 'digitalinvoice-api')) ?? 'digitalinvoice-api';
}

function jwtAudience(): string
{
    return config('JWT_AUDIENCE', 'digitalinvoice-web') ?? 'digitalinvoice-web';
}

function jwtSecret(): string
{
    $secret = requiredConfig('JWT_SECRET');
    if (strlen($secret) < 64) {
        throw new RuntimeException('JWT_SECRET must be at least 64 characters long.');
    }

    return $secret;
}

function buildAccessToken(array $user, string $sessionSelector, int $absoluteExpiresAt): string
{
    $now = time();
    $expiresAt = min($now + accessTokenTtlSeconds(), $absoluteExpiresAt);

    return JWT::encode([
        'iss' => jwtIssuer(),
        'aud' => jwtAudience(),
        'sub' => (string) $user['id'],
        'id' => (int) $user['id'],
        'organization_id' => isset($user['organization_id']) ? (int) $user['organization_id'] : null,
        'email' => $user['email'],
        'role' => $user['role'],
        'ver' => (int) ($user['auth_version'] ?? 1),
        'sid' => $sessionSelector,
        'session_exp' => $absoluteExpiresAt,
        'type' => 'access',
        'iat' => $now,
        'nbf' => $now,
        'exp' => $expiresAt,
    ], jwtSecret(), 'HS256');
}

function createRefreshSession(
    mysqli $conn,
    int $userId,
    string $csrfToken,
    ?int $sessionStartedAt = null,
    ?int $absoluteExpiresAt = null
): array {
    $now = time();
    $sessionStartedAt ??= $now;
    $absoluteExpiresAt ??= $sessionStartedAt + sessionAbsoluteTimeoutSeconds();
    $refreshExpiresAt = min($now + refreshTokenTtlSeconds(), $absoluteExpiresAt);

    if ($refreshExpiresAt <= $now) {
        throw new Exception('Your maximum session duration has been reached. Please sign in again.', 401);
    }

    $selector = bin2hex(random_bytes(16));
    $validator = randomUrlSafeToken(32);
    $validatorHash = hash('sha256', $validator);
    $csrfHash = hash('sha256', $csrfToken);
    $expiresAtDate = date('Y-m-d H:i:s', $refreshExpiresAt);
    $sessionStartedDate = date('Y-m-d H:i:s', $sessionStartedAt);
    $activityDate = date('Y-m-d H:i:s', $now);
    $absoluteExpiresDate = date('Y-m-d H:i:s', $absoluteExpiresAt);
    $ip = clientIpAddress();
    $agent = userAgent();

    $stmt = $conn->prepare(
        'INSERT INTO auth_refresh_tokens '
        . '(user_id, selector, token_hash, csrf_hash, expires_at, session_started_at, last_activity_at, absolute_expires_at, ip_address, user_agent) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'isssssssss',
        $userId,
        $selector,
        $validatorHash,
        $csrfHash,
        $expiresAtDate,
        $sessionStartedDate,
        $activityDate,
        $absoluteExpiresDate,
        $ip,
        $agent
    );
    $stmt->execute();
    $stmt->close();

    return [
        'token' => $selector . '.' . $validator,
        'selector' => $selector,
        'expires_at' => $refreshExpiresAt,
        'session_started_at' => $sessionStartedAt,
        'absolute_expires_at' => $absoluteExpiresAt,
    ];
}

function parseRefreshCookie(?string $cookieValue): ?array
{
    if ($cookieValue === null || !preg_match('/^([a-f0-9]{32})\.([A-Za-z0-9_-]{40,})$/', $cookieValue, $matches)) {
        return null;
    }

    return ['selector' => $matches[1], 'validator' => $matches[2]];
}

function fetchRefreshSessionBySelector(mysqli $conn, string $selector): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, user_id, selector, token_hash, csrf_hash, expires_at, session_started_at, last_activity_at, absolute_expires_at, revoked_at, revoked_reason '
        . 'FROM auth_refresh_tokens WHERE selector = ? LIMIT 1'
    );
    $stmt->bind_param('s', $selector);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $session ?: null;
}

function sessionExpiryReason(?array $session): ?string
{
    if (!$session) {
        return 'invalid';
    }
    if ($session['revoked_at'] !== null) {
        return 'revoked';
    }

    $now = time();
    $absoluteExpiry = strtotime((string) $session['absolute_expires_at']);
    $refreshExpiry = strtotime((string) $session['expires_at']);
    $lastActivity = strtotime((string) $session['last_activity_at']);

    if ($absoluteExpiry === false || $absoluteExpiry <= $now) {
        return 'absolute_timeout';
    }
    if ($refreshExpiry === false || $refreshExpiry <= $now) {
        return 'expired';
    }
    if ($lastActivity === false || $lastActivity <= ($now - sessionIdleTimeoutSeconds())) {
        return 'idle_timeout';
    }

    return null;
}

function sessionExpiryMessage(string $reason): string
{
    return match ($reason) {
        'idle_timeout' => 'Your session expired due to inactivity. Please sign in again.',
        'absolute_timeout' => 'Your maximum session duration has been reached. Please sign in again.',
        default => 'Session expired. Please sign in again.',
    };
}

/**
 * Seconds a recently rotated refresh token stays recognisable as "already refreshed"
 * instead of failing. Overlapping refreshes (two tabs, slow networks) present the same
 * token; the late one must not clear the cookies the winning refresh just issued.
 */
function refreshRotationGraceSeconds(): int
{
    return min(300, max(0, (int) (config('SESSION_REFRESH_GRACE_SECONDS', '60') ?? '60')));
}

/** True when the token was rotated (not revoked for another reason) within the grace window. */
function refreshTokenRecentlyRotated(mysqli $conn, array $session): bool
{
    $grace = refreshRotationGraceSeconds();
    if ($grace === 0 || ($session['revoked_reason'] ?? null) !== 'rotated' || ($session['revoked_at'] ?? null) === null) {
        return false;
    }
    $stmt = $conn->prepare('SELECT TIMESTAMPDIFF(SECOND, revoked_at, NOW()) AS age FROM auth_refresh_tokens WHERE id = ? LIMIT 1');
    $id = (int) $session['id'];
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row !== null && $row['age'] !== null && (int) $row['age'] >= 0 && (int) $row['age'] <= $grace;
}

function touchRefreshSessionActivity(mysqli $conn, string $selector): void
{
    $interval = sessionActivityTouchIntervalSeconds();
    if ($interval === 0) {
        $stmt = $conn->prepare('UPDATE auth_refresh_tokens SET last_activity_at = NOW() WHERE selector = ? AND revoked_at IS NULL');
        $stmt->bind_param('s', $selector);
    } else {
        // Compare in SQL so the threshold and NOW() always share one clock.
        $stmt = $conn->prepare('UPDATE auth_refresh_tokens SET last_activity_at = NOW() WHERE selector = ? AND revoked_at IS NULL AND last_activity_at <= (NOW() - INTERVAL ? SECOND)');
        $stmt->bind_param('si', $selector, $interval);
    }
    $stmt->execute();
    $stmt->close();
}

function activeSessionCookieExpiry(array $session): int
{
    $refreshExpiresAt = strtotime((string) ($session['expires_at'] ?? '')) ?: 0;
    $absoluteExpiresAt = strtotime((string) ($session['absolute_expires_at'] ?? '')) ?: 0;
    $expires = array_filter([$refreshExpiresAt, $absoluteExpiresAt], static fn ($value) => $value > time());

    return $expires ? min($expires) : time();
}

function maybeRefreshAccessCookieForActivity(array $user, array $session, array $decodedAccessToken): void
{
    $expiresAt = (int) ($decodedAccessToken['exp'] ?? 0);
    $remainingSeconds = $expiresAt - time();

    if ($remainingSeconds > accessTokenRefreshThresholdSeconds()) {
        return;
    }

    $selector = trim((string) ($session['selector'] ?? ''));
    $absoluteExpiresAt = strtotime((string) ($session['absolute_expires_at'] ?? '')) ?: time();
    $sessionExpiresAt = activeSessionCookieExpiry($session);

    if ($selector === '' || $sessionExpiresAt <= time()) {
        return;
    }

    $accessToken = buildAccessToken($user, $selector, $absoluteExpiresAt);
    issueAccessCookie($accessToken, $sessionExpiresAt);
}

function revokeRefreshTokenBySelector(mysqli $conn, string $selector, string $reason = 'revoked'): void
{
    $stmt = $conn->prepare('UPDATE auth_refresh_tokens SET revoked_at = COALESCE(revoked_at, NOW()), revoked_reason = COALESCE(revoked_reason, ?) WHERE selector = ?');
    $stmt->bind_param('ss', $reason, $selector);
    $stmt->execute();
    $stmt->close();
}

function revokeCurrentRefreshSessionIfPresent(mysqli $conn, string $reason): void
{
    $parsed = parseRefreshCookie($_COOKIE[refreshCookieName()] ?? null);
    if ($parsed !== null) {
        revokeRefreshTokenBySelector($conn, $parsed['selector'], $reason);
    }
}

function revokeRefreshTokensForUser(mysqli $conn, int $userId, string $reason = 'revoked'): void
{
    $stmt = $conn->prepare('UPDATE auth_refresh_tokens SET revoked_at = COALESCE(revoked_at, NOW()), revoked_reason = COALESCE(revoked_reason, ?) WHERE user_id = ? AND revoked_at IS NULL');
    $stmt->bind_param('si', $reason, $userId);
    $stmt->execute();
    $stmt->close();
}

function jsonResponse(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonSuccess(array $payload = [], int $statusCode = 200): never
{
    jsonResponse(array_merge(['status' => 'success'], $payload), $statusCode);
}

function jsonError(string $message, int $statusCode = 400, array $extra = []): never
{
    jsonResponse(array_merge(['status' => 'failed', 'message' => $message], $extra), $statusCode);
}

function publicUserData(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'organization_id' => isset($user['organization_id']) ? (int) $user['organization_id'] : null,
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'email_verified_at' => $user['email_verified_at'] ?? null,
        'is_active' => (int) ($user['is_active'] ?? 1),
        'last_login' => $user['last_login'] ?? null,
        'created_at' => $user['created_at'] ?? null,
        'updated_at' => $user['updated_at'] ?? null,
    ];
}

function issueAuthenticatedSession(mysqli $conn, array $user, ?array $continuingSession = null): string
{
    $csrfToken = randomUrlSafeToken(32);
    $sessionStartedAt = $continuingSession ? strtotime((string) $continuingSession['session_started_at']) : null;
    $absoluteExpiresAt = $continuingSession ? strtotime((string) $continuingSession['absolute_expires_at']) : null;
    $refreshSession = createRefreshSession(
        $conn,
        (int) $user['id'],
        $csrfToken,
        $sessionStartedAt !== false ? $sessionStartedAt : null,
        $absoluteExpiresAt !== false ? $absoluteExpiresAt : null
    );
    $accessToken = buildAccessToken($user, $refreshSession['selector'], $refreshSession['absolute_expires_at']);
    issueAuthCookies($accessToken, $refreshSession['token'], $csrfToken, $refreshSession['expires_at']);
    return $csrfToken;
}

function auditLog(mysqli $conn, ?int $userId, string $action, string $description, ?int $organizationId = null, ?string $metadata = null): void
{
    $ip = clientIpAddress();
    $stmt = $conn->prepare('INSERT INTO activity_log (organization_id, user_id, action, description, ip_address, metadata) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('iissss', $organizationId, $userId, $action, $description, $ip, $metadata);
    $stmt->execute();
    $stmt->close();

    // Transactional outbox: enqueue selected business events after audit insertion.
    // ERP/webhook failures must not prevent core invoice/payment workflows from completing.
    // Only enqueue after BOTH ERP gates are enabled. Otherwise installation or
    // later activation could unexpectedly replay old business events to a new
    // endpoint. Existing activity logging remains unconditional.
    if ($organizationId !== null && $organizationId > 0
        && configBool('ERP_INTEGRATIONS_ENABLED', false)
        && configBool('ERP_WEBHOOKS_ENABLED', false)) {
        require_once __DIR__ . '/erpWebhookService.php';
        if (isset(erpWebhookEventMap()[$action])) {
            try {
                erpWebhookQueueAudit($conn, $action, $organizationId, $metadata);
            } catch (Throwable $webhookError) {
                error_log('ERP webhook outbox enqueue failed: ' . get_class($webhookError));
            }
        }
    }
}

function createUniqueSlug(mysqli $conn, string $name): string
{
    $base = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
    $base = $base !== '' ? substr($base, 0, 80) : 'business';
    $slug = $base;
    $counter = 1;

    while (true) {
        $stmt = $conn->prepare('SELECT id FROM organizations WHERE slug = ? LIMIT 1');
        $stmt->bind_param('s', $slug);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$exists) {
            return $slug;
        }
        $counter++;
        $slug = substr($base, 0, 70) . '-' . $counter;
    }
}
