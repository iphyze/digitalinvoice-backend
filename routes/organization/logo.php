<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/subscriptionMiddleware.php';

function logoStorageDirectory(): string
{
    return dirname(__DIR__, 2) . '/storage/organization-logos';
}

function removeOrganizationLogoFile(?string $storedName): void
{
    if ($storedName === null || $storedName === '') {
        return;
    }
    $path = logoStorageDirectory() . '/' . basename($storedName);
    if (is_file($path)) {
        @unlink($path);
    }
}

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $user = $method === 'GET' ? requireProductAccess() : requireOnboardingManager();
    $organizationId = (int) $user['organization_id'];
    $stmt = $conn->prepare('SELECT logo_stored_name, logo_original_name, logo_mime_type FROM organizations WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $organizationId);
    $stmt->execute();
    $logo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($method === 'GET') {
        if (!$logo || empty($logo['logo_stored_name'])) {
            throw new Exception('No organisation logo has been uploaded.', 404);
        }
        $file = logoStorageDirectory() . '/' . basename((string) $logo['logo_stored_name']);
        if (!is_file($file)) {
            throw new Exception('Logo file could not be located.', 404);
        }
        header('Content-Type: ' . (string) $logo['logo_mime_type']);
        header('Content-Disposition: inline; filename="' . basename((string) $logo['logo_original_name']) . '"');
        header('Content-Length: ' . (string) filesize($file));
        readfile($file);
        exit;
    }

    if ($method === 'DELETE') {
        removeOrganizationLogoFile($logo['logo_stored_name'] ?? null);
        $clear = $conn->prepare('UPDATE organizations SET logo_stored_name = NULL, logo_original_name = NULL, logo_mime_type = NULL, logo_updated_at = NULL, updated_at = NOW() WHERE id = ?');
        $clear->bind_param('i', $organizationId);
        $clear->execute();
        $clear->close();
        auditLog($conn, (int) $user['id'], 'organization.logo_removed', 'Organisation logo removed.', $organizationId);
        jsonSuccess(['message' => 'Logo removed successfully.']);
    }

    if ($method !== 'POST') {
        throw new Exception('Method not supported.', 405);
    }
    if (!isset($_FILES['logo']) || !is_array($_FILES['logo'])) {
        throw new Exception('Upload a logo file using the logo form-data field.', 422);
    }
    $file = $_FILES['logo'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new Exception('Logo upload failed.', 422);
    }
    if ((int) ($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new Exception('Logo must not be larger than 2MB.', 422);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
    $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        throw new Exception('Logo must be a PNG, JPG or WEBP image.', 422);
    }
    $directory = logoStorageDirectory();
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to prepare logo storage.');
    }
    $storedName = 'org-' . $organizationId . '-' . randomUrlSafeToken(16) . '.' . $extensions[$mime];
    if (!move_uploaded_file((string) $file['tmp_name'], $directory . '/' . $storedName)) {
        throw new RuntimeException('Unable to store uploaded logo.');
    }
    removeOrganizationLogoFile($logo['logo_stored_name'] ?? null);
    $originalName = substr(basename((string) $file['name']), 0, 190);
    $update = $conn->prepare('UPDATE organizations SET logo_stored_name = ?, logo_original_name = ?, logo_mime_type = ?, logo_updated_at = NOW(), updated_at = NOW() WHERE id = ?');
    $update->bind_param('sssi', $storedName, $originalName, $mime, $organizationId);
    $update->execute();
    $update->close();
    auditLog($conn, (int) $user['id'], 'organization.logo_uploaded', 'Organisation logo uploaded.', $organizationId);
    jsonSuccess(['message' => 'Logo uploaded successfully.', 'data' => ['logo_available' => true, 'mime_type' => $mime]], 201);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to process logo request.', ($code >= 400 && $code <= 599) ? $code : 500);
}
