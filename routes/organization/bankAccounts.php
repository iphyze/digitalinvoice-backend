<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/subscriptionMiddleware.php';

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        $user = requireProductAccess();
        jsonSuccess(['data' => ['bank_accounts' => organizationBankAccounts($conn, (int) $user['organization_id'])]]);
    }

    $user = requireOnboardingManager();
    $data = readJsonBody();
    $organizationId = (int) $user['organization_id'];
    $userId = (int) $user['id'];

    if ($method === 'DELETE') {
        $id = (int) ($data['id'] ?? 0);
        if ($id < 1) {
            throw new Exception('Bank account ID is required.', 422);
        }
        $stmt = $conn->prepare('UPDATE organization_bank_accounts SET is_active = 0, is_default = 0, updated_at = NOW() WHERE id = ? AND organization_id = ?');
        $stmt->bind_param('ii', $id, $organizationId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected === 0) {
            throw new Exception('Bank account was not found.', 404);
        }
        auditLog($conn, $userId, 'organization.bank_account_removed', 'Payment bank account removed.', $organizationId, json_encode(['bank_account_id' => $id]));
        jsonSuccess(['message' => 'Bank account removed successfully.', 'data' => ['bank_accounts' => organizationBankAccounts($conn, $organizationId)]]);
    }

    $bankName = requiredOnboardingText($data, 'bank_name', 'Bank name', 120);
    $accountName = requiredOnboardingText($data, 'account_name', 'Account name', 180);
    $accountNumber = requiredOnboardingText($data, 'account_number', 'Account number', 30);
    if (!preg_match('/^[0-9]{10,30}$/', $accountNumber)) {
        throw new Exception('Account number must contain between 10 and 30 digits.', 422);
    }
    $currency = strtoupper(requiredOnboardingText($data, 'currency', 'Currency', 3));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        throw new Exception('Currency must be a three-letter currency code such as NGN.', 422);
    }
    $isDefault = booleanInput($data['is_default'] ?? false, 'Default account');
    $id = (int) ($data['id'] ?? 0);

    $conn->begin_transaction();
    try {
        if ($isDefault) {
            $clear = $conn->prepare('UPDATE organization_bank_accounts SET is_default = 0, updated_at = NOW() WHERE organization_id = ?');
            $clear->bind_param('i', $organizationId);
            $clear->execute();
            $clear->close();
        }

        if ($method === 'POST') {
            $stmt = $conn->prepare('INSERT INTO organization_bank_accounts (organization_id, bank_name, account_name, account_number, currency, is_default, created_by_user_id, updated_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('issssiii', $organizationId, $bankName, $accountName, $accountNumber, $currency, $isDefault, $userId, $userId);
            $stmt->execute();
            $id = (int) $stmt->insert_id;
            $stmt->close();
            $action = 'organization.bank_account_created';
            $message = 'Bank account added successfully.';
        } elseif ($method === 'PUT') {
            if ($id < 1) {
                throw new Exception('Bank account ID is required.', 422);
            }
            $stmt = $conn->prepare('UPDATE organization_bank_accounts SET bank_name = ?, account_name = ?, account_number = ?, currency = ?, is_default = ?, is_active = 1, updated_by_user_id = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?');
            $stmt->bind_param('ssssiiii', $bankName, $accountName, $accountNumber, $currency, $isDefault, $userId, $id, $organizationId);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected === 0) {
                throw new Exception('Bank account was not found or no values changed.', 404);
            }
            $action = 'organization.bank_account_updated';
            $message = 'Bank account updated successfully.';
        } else {
            throw new Exception('Method not supported.', 405);
        }
        auditLog($conn, $userId, $action, $message, $organizationId, json_encode(['bank_account_id' => $id]));
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    jsonSuccess(['message' => $message, 'data' => ['bank_accounts' => organizationBankAccounts($conn, $organizationId)]], $method === 'POST' ? 201 : 200);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to process bank account request.', ($code >= 400 && $code <= 599) ? $code : 500);
}
