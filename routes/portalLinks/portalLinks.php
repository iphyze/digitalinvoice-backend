<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/connection.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/customerPortalService.php';

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        $user = requirePermission('portal_links.manage', true);
        jsonSuccess([
            'contract_version' => 'customer-portal-links-v1',
            'data' => [
                'links' => customerPortalListLinks($conn, (int) $user['organization_id']),
            ],
        ]);
    }

    if ($method === 'POST') {
        $user = requirePermission('portal_links.manage', true);
        $data = readJsonBody();
        $link = customerPortalCreateLink($conn, $user, $data);
        $message = !empty($link['email_sent'])
            ? 'Secure customer link generated and emailed successfully.'
            : 'Secure customer link generated. Email delivery was not completed; copy the link manually.';
        jsonSuccess([
            'contract_version' => 'customer-portal-links-v1',
            'message' => $message,
            'data' => ['link' => $link],
        ], 201);
    }

    jsonError('Method not allowed.', 405);
} catch (Throwable $e) {
    $code = (int) $e->getCode();
    jsonError(($code >= 400 && $code <= 599) ? $e->getMessage() : 'Unable to process secure customer link request.', ($code >= 400 && $code <= 599) ? $code : 500);
}
