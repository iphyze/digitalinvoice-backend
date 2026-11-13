<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

/** Machine-to-machine read scopes; never expose NRS production writes. */
function erpAllowedScopes(): array
{
    return ['customers:read', 'items:read', 'invoices:read', 'credit_notes:read', 'payments:read', 'nrs:read'];
}

function erpParseScopes(mixed $input, bool $allowEmpty = false): array
{
    if (is_string($input)) {
        $input = preg_split('/\s+/', trim($input)) ?: [];
    }
    if (!is_array($input) || array_filter($input, static fn ($v) => !is_string($v))) {
        throw new Exception('Scopes must be a space-separated string or a list of scope names.', 422);
    }
    $scopes = array_values(array_unique(array_filter(array_map('trim', $input), 'strlen')));
    if ((!$allowEmpty && !$scopes) || count(array_diff($scopes, erpAllowedScopes())) > 0) {
        throw new Exception('One or more requested ERP scopes are invalid.', 422);
    }
    sort($scopes, SORT_STRING);
    return $scopes;
}

function erpClientIdentifier(): string
{
    return 'di_' . bin2hex(random_bytes(20));
}

function erpClientSecret(): string
{
    return 'di_secret_' . randomUrlSafeToken(48);
}

function erpTokenTtl(): int
{
    return max(300, min(900, (int) (config('ERP_ACCESS_TOKEN_TTL', '900') ?? '900')));
}

/** Enforce organisation suspension, subscription and completed onboarding on every machine request. */
function erpOrganizationEnabled(mysqli $conn, int $organizationId): bool
{
    $stmt = $conn->prepare(
        "SELECT o.id FROM organizations o JOIN onboarding_progress p ON p.organization_id=o.id "
        . "WHERE o.id=? AND o.status = 'active' "
        . 'AND p.onboarding_completed_at IS NOT NULL AND p.business_profile_completed_at IS NOT NULL '
        . 'AND p.invoice_settings_completed_at IS NOT NULL AND p.compliance_profile_completed_at IS NOT NULL '
        . "AND EXISTS (SELECT 1 FROM subscriptions s WHERE s.organization_id=o.id AND s.status IN ('active','trialing','non_renewing') "
        . 'AND (s.current_period_end IS NULL OR s.current_period_end >= NOW())) LIMIT 1'
    );
    $stmt->bind_param('i', $organizationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (bool) $row;
}

function erpAuthenticateClientSecret(mysqli $conn, string $identifier, string $secret): ?array
{
    if (!preg_match('/^di_[a-f0-9]{40}$/D', $identifier)
        || !preg_match('/^di_secret_[A-Za-z0-9_-]{64}$/D', $secret)) {
        return null;
    }
    $stmt = $conn->prepare('SELECT * FROM erp_oauth_clients WHERE client_identifier=? AND is_active=1 AND revoked_at IS NULL LIMIT 1');
    $stmt->bind_param('s', $identifier);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$client || !hash_equals((string) $client['client_secret_sha256'], hash('sha256', $secret))) {
        return null;
    }
    if (!erpOrganizationEnabled($conn, (int) $client['organization_id'])) {
        return null;
    }
    return $client;
}

function erpTokenFromAuthorization(): string
{
    $header = requestHeader('Authorization') ?? '';
    if (!preg_match('/^Bearer\s+(di_access_[A-Za-z0-9_-]{64})$/iD', $header, $matches)) {
        header('WWW-Authenticate: Bearer realm="DigitalInvoice ERP API"');
        throw new Exception('An ERP bearer access token is required.', 401);
    }
    return $matches[1];
}

function erpAuthoriseScope(mysqli $conn, string $scope): array
{
    if (!in_array($scope, erpAllowedScopes(), true)) {
        throw new LogicException('Unsupported ERP API scope.');
    }
    $digest = hash('sha256', erpTokenFromAuthorization());
    $stmt = $conn->prepare(
        'SELECT t.id AS token_id, t.granted_scopes, c.id AS client_id, c.organization_id, c.allowed_scopes '
        . 'FROM erp_oauth_tokens t JOIN erp_oauth_clients c ON c.id=t.client_id '
        . 'WHERE t.token_sha256=? AND t.expires_at > NOW() AND t.revoked_at IS NULL '
        . 'AND c.is_active=1 AND c.revoked_at IS NULL LIMIT 1'
    );
    $stmt->bind_param('s', $digest);
    $stmt->execute();
    $client = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$client || !erpOrganizationEnabled($conn, (int) $client['organization_id'])) {
        header('WWW-Authenticate: Bearer realm="DigitalInvoice ERP API", error="invalid_token"');
        throw new Exception('ERP access token is invalid or expired.', 401);
    }
    $granted = explode(' ', trim((string) $client['granted_scopes']));
    $allowed = explode(' ', trim((string) $client['allowed_scopes']));
    if (!in_array($scope, $granted, true) || !in_array($scope, $allowed, true)) {
        header('WWW-Authenticate: Bearer realm="DigitalInvoice ERP API", error="insufficient_scope"');
        throw new Exception('ERP token does not include the required scope: ' . $scope, 403);
    }
    $tokenId = (int) $client['token_id'];
    $update = $conn->prepare('UPDATE erp_oauth_tokens SET last_used_at=NOW() WHERE id=? AND (last_used_at IS NULL OR last_used_at < NOW() - INTERVAL 60 SECOND)');
    $update->bind_param('i', $tokenId);
    $update->execute();
    $update->close();
    return ['client_id' => (int) $client['client_id'], 'organization_id' => (int) $client['organization_id']];
}
