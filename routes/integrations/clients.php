<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/erpOAuthService.php';

try {
    $actor = requireOperationalWorkspaceAccess();
    if (!in_array((string) ($actor['role'] ?? ''), ['owner', 'admin'], true)) {
        throw new Exception('Only organisation owners and administrators can manage ERP clients.', 403);
    }
    $org = (int) $actor['organization_id'];
    $userId = (int) $actor['id'];
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        $stmt = $conn->prepare('SELECT id, name, client_identifier, allowed_scopes, is_active, created_at, updated_at, revoked_at, last_used_at FROM erp_oauth_clients WHERE organization_id=? ORDER BY created_at DESC LIMIT 100');
        $stmt->bind_param('i', $org);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as &$row) {
            $row['scopes'] = explode(' ', (string) $row['allowed_scopes']);
            unset($row['allowed_scopes']);
        }
        unset($row);
        jsonSuccess(['data' => ['clients' => $rows, 'allowed_scopes' => erpAllowedScopes()]]);
    }
    $data = readJsonBody();
    if ($method === 'POST') {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new Exception('ERP client name must be between 1 and 120 characters.', 422);
        }
        $scopes = implode(' ', erpParseScopes($data['scopes'] ?? []));
        $identifier = erpClientIdentifier();
        $secret = erpClientSecret();
        $digest = hash('sha256', $secret);
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('INSERT INTO erp_oauth_clients (organization_id, name, client_identifier, client_secret_sha256, allowed_scopes, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('issssi', $org, $name, $identifier, $digest, $scopes, $userId);
            $stmt->execute();
            $id = (int) $stmt->insert_id;
            $stmt->close();
            auditLog($conn, $userId, 'erp.client_created', 'ERP OAuth client created.', $org, json_encode(['client_id' => $id]));
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }
        jsonSuccess(['message' => 'Store this secret now. It will not be shown again.', 'data' => ['id' => $id, 'client_id' => $identifier, 'client_secret' => $secret, 'scope' => $scopes]], 201);
    }
    if ($method !== 'PUT') {
        throw new Exception('Method not supported.', 405);
    }
    $id = (int) ($data['id'] ?? 0);
    $action = trim((string) ($data['action'] ?? ''));
    if ($id < 1 || !in_array($action, ['rotate_secret', 'revoke', 'update_scopes'], true)) {
        throw new Exception('A valid client ID and action are required.', 422);
    }
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT id, is_active, revoked_at FROM erp_oauth_clients WHERE id=? AND organization_id=? FOR UPDATE');
        $stmt->bind_param('ii', $id, $org);
        $stmt->execute();
        $client = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$client || (int) $client['is_active'] !== 1 || $client['revoked_at'] !== null) {
            throw new Exception('Active ERP client not found.', 404);
        }
        $secret = null;
        if ($action === 'revoke') {
            $stmt = $conn->prepare('UPDATE erp_oauth_clients SET is_active=0, revoked_at=NOW() WHERE id=? AND organization_id=?');
            $stmt->bind_param('ii', $id, $org);
        } elseif ($action === 'rotate_secret') {
            $secret = erpClientSecret();
            $digest = hash('sha256', $secret);
            $stmt = $conn->prepare('UPDATE erp_oauth_clients SET client_secret_sha256=? WHERE id=? AND organization_id=?');
            $stmt->bind_param('sii', $digest, $id, $org);
        } else {
            $scopes = implode(' ', erpParseScopes($data['scopes'] ?? []));
            $stmt = $conn->prepare('UPDATE erp_oauth_clients SET allowed_scopes=? WHERE id=? AND organization_id=?');
            $stmt->bind_param('sii', $scopes, $id, $org);
        }
        $stmt->execute();
        $stmt->close();
        // Revoke all existing bearer tokens on ANY privilege or credential change.
        $stmt = $conn->prepare('UPDATE erp_oauth_tokens SET revoked_at=COALESCE(revoked_at,NOW()) WHERE client_id=? AND revoked_at IS NULL');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        auditLog($conn, $userId, 'erp.client_' . $action, 'ERP OAuth client security settings updated.', $org, json_encode(['client_id' => $id]));
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    $payload = ['id' => $id];
    if ($secret !== null) $payload['client_secret'] = $secret;
    jsonSuccess(['message' => $secret ? 'Store the replacement secret now. The old one has been revoked.' : 'ERP client updated and outstanding access tokens revoked.', 'data' => $payload]);
} catch (Throwable $error) {
    $code = (int) $error->getCode();
    jsonError($code >= 400 && $code <= 599 ? $error->getMessage() : 'Unable to manage ERP clients.', $code >= 400 && $code <= 599 ? $code : 500);
}
