<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/erpOAuthService.php';

/** Standard OAuth2 client_credentials token exchange: HTTP Basic, form-encoded body. */
function erpOAuthError(string $error, int $httpStatus, string $description): never
{
    if ($httpStatus === 401) header('WWW-Authenticate: Basic realm="DigitalInvoice ERP OAuth"');
    jsonResponse(['error' => $error, 'error_description' => $description], $httpStatus);
}

if (!configBool('ERP_INTEGRATIONS_ENABLED', false)) {
    erpOAuthError('temporarily_unavailable', 503, 'ERP API access is not enabled on this server.');
}
$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/x-www-form-urlencoded') {
    erpOAuthError('invalid_request', 400, 'Send application/x-www-form-urlencoded.');
}
if (($_POST['grant_type'] ?? '') !== 'client_credentials') {
    erpOAuthError('unsupported_grant_type', 400, 'Only client_credentials is supported.');
}
$authorization = requestHeader('Authorization') ?? '';
if (!preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/iD', $authorization, $matches)) {
    erpOAuthError('invalid_client', 401, 'HTTP Basic client authentication is required.');
}
$decoded = base64_decode($matches[1], true);
if ($decoded === false || !str_contains($decoded, ':')) {
    erpOAuthError('invalid_client', 401, 'Invalid client credentials.');
}
[$identifier, $secret] = explode(':', $decoded, 2);
try {
    $client = erpAuthenticateClientSecret($conn, $identifier, $secret);
    if ($client === null) erpOAuthError('invalid_client', 401, 'Invalid client credentials.');
    $allowed = erpParseScopes((string) $client['allowed_scopes']);
    $requested = isset($_POST['scope']) && trim((string) $_POST['scope']) !== ''
        ? erpParseScopes((string) $_POST['scope']) : $allowed;
    if (array_diff($requested, $allowed)) {
        erpOAuthError('invalid_scope', 400, 'Requested scope exceeds client permissions.');
    }
    $scope = implode(' ', $requested);
    $clientId = (int) $client['id'];
    // Rate-limit issuance per client; does not rely on session cookies or CSRF.
    $count = $conn->prepare('SELECT COUNT(*) AS total FROM erp_oauth_tokens WHERE client_id=? AND created_at >= NOW() - INTERVAL 1 MINUTE');
    $count->bind_param('i', $clientId);
    $count->execute();
    $recent = (int) ($count->get_result()->fetch_assoc()['total'] ?? 0);
    $count->close();
    if ($recent >= 60) erpOAuthError('temporarily_unavailable', 429, 'Too many token requests.');
    $token = 'di_access_' . randomUrlSafeToken(48);
    $digest = hash('sha256', $token);
    $ttl = erpTokenTtl();
    $expires = date('Y-m-d H:i:s', time() + $ttl);
    $stmt = $conn->prepare('INSERT INTO erp_oauth_tokens (client_id, token_sha256, granted_scopes, expires_at) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('isss', $clientId, $digest, $scope, $expires);
    $stmt->execute();
    $stmt->close();
    $stmt = $conn->prepare('UPDATE erp_oauth_clients SET last_used_at=NOW() WHERE id=?');
    $stmt->bind_param('i', $clientId);
    $stmt->execute();
    $stmt->close();
    jsonResponse(['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $ttl, 'scope' => $scope]);
} catch (Throwable $error) {
    if ((int) $error->getCode() === 422) erpOAuthError('invalid_scope', 400, 'Requested scopes are invalid.');
    error_log('ERP OAuth token exchange failed: ' . get_class($error));
    erpOAuthError('server_error', 500, 'Unable to issue an ERP access token.');
}
