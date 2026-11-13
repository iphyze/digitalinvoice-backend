<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/security.php';

loadEnvironment();
date_default_timezone_set(config('TIMEZONE', 'Africa/Lagos') ?? 'Africa/Lagos');
applyApiSecurityHeaders();

$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$basePath = apiBasePath();
$relativePath = str_starts_with($requestUri, $basePath) ? substr($requestUri, strlen($basePath)) : $requestUri;
$relativePath = '/' . ltrim($relativePath, '/');
$relativePath = $relativePath !== '/' ? rtrim($relativePath, '/') : '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$routes = [
    'GET /' => 'routes/welcome.php',

    'GET /auth/csrf' => 'routes/auth/csrf.php',
    'GET /auth/config' => 'routes/auth/config.php',
    'POST /auth/register' => 'routes/auth/register.php',
    'POST /auth/verify-email' => 'routes/auth/verifyEmail.php',
    'POST /auth/resend-verification' => 'routes/auth/resendVerification.php',
    'POST /auth/login' => 'routes/auth/login.php',
    'POST /auth/mfa/verify' => 'routes/auth/mfaVerify.php',
    'POST /auth/google' => 'routes/auth/google.php',
    'GET /auth/session' => 'routes/auth/session.php',
    'POST /auth/refresh' => 'routes/auth/refresh.php',
    'POST /auth/logout' => 'routes/auth/logout.php',
    'POST /auth/forgot-password' => 'routes/auth/forgotPassword.php',
    'POST /auth/reset-password' => 'routes/auth/resetPassword.php',

    'GET /user/profile' => 'routes/user/profile.php',
    'PUT /user/profile' => 'routes/user/profile.php',
    'PUT /user/password' => 'routes/user/password.php',
    'GET /user/mfa' => 'routes/user/mfa.php',
    'POST /user/mfa-setup' => 'routes/user/mfa.php',
    'POST /user/mfa-confirm' => 'routes/user/mfa.php',
    'POST /user/mfa-disable' => 'routes/user/mfa.php',
    'POST /user/mfa-recovery-regenerate' => 'routes/user/mfa.php',

    'POST /demo/request' => 'routes/demo/requestDemo.php',

    'GET /public/customer-portal' => 'routes/public/customerPortal.php',

    'GET /plans' => 'routes/plans/listPlans.php',
    'GET /subscriptions/status' => 'routes/subscriptions/status.php',
    'POST /subscriptions/initialize' => 'routes/subscriptions/initialize.php',
    'POST /subscriptions/verify' => 'routes/subscriptions/verify.php',
    'POST /subscriptions/cancel' => 'routes/subscriptions/cancel.php',
    'POST /premium-requests' => 'routes/premiumRequests/request.php',

    'POST /webhooks/paystack' => 'routes/webhooks/paystack.php',

    // Step 5 — isolated machine-to-machine ERP API, not browser session authentication.
    'POST /oauth/token' => 'routes/integrations/token.php',
    'GET /integrations/clients' => 'routes/integrations/clients.php',
    'POST /integrations/clients' => 'routes/integrations/clients.php',
    'PUT /integrations/clients' => 'routes/integrations/clients.php',
    'GET /integrations/webhooks' => 'routes/integrations/webhooks.php',
    'POST /integrations/webhooks' => 'routes/integrations/webhooks.php',
    'PUT /integrations/webhooks' => 'routes/integrations/webhooks.php',
    'GET /integrations/webhooks/deliveries' => 'routes/integrations/deliveries.php',
    'GET /integrations/v1/customers' => 'routes/integrations/resources.php',
    'GET /integrations/v1/items' => 'routes/integrations/resources.php',
    'GET /integrations/v1/invoices' => 'routes/integrations/resources.php',
    'GET /integrations/v1/invoice' => 'routes/integrations/resources.php',
    'GET /integrations/v1/credit-notes' => 'routes/integrations/resources.php',
    'GET /integrations/v1/credit-note' => 'routes/integrations/resources.php',
    'GET /integrations/v1/payments' => 'routes/integrations/resources.php',
    'GET /integrations/v1/payment' => 'routes/integrations/resources.php',
    'GET /integrations/v1/nrs-status' => 'routes/integrations/resources.php',

    'GET /onboarding/status' => 'routes/onboarding/status.php',
    'POST /onboarding/complete' => 'routes/onboarding/complete.php',

    'GET /organization/profile' => 'routes/organization/profile.php',
    'PUT /organization/profile' => 'routes/organization/profile.php',
    'GET /organization/invoice-settings' => 'routes/organization/invoiceSettings.php',
    'PUT /organization/invoice-settings' => 'routes/organization/invoiceSettings.php',
    'GET /organization/compliance-profile' => 'routes/organization/complianceProfile.php',
    'PUT /organization/compliance-profile' => 'routes/organization/complianceProfile.php',
    'GET /organization/bank-accounts' => 'routes/organization/bankAccounts.php',
    'POST /organization/bank-accounts' => 'routes/organization/bankAccounts.php',
    'PUT /organization/bank-accounts' => 'routes/organization/bankAccounts.php',
    'DELETE /organization/bank-accounts' => 'routes/organization/bankAccounts.php',
    'GET /organization/logo' => 'routes/organization/logo.php',
    'POST /organization/logo' => 'routes/organization/logo.php',
    'DELETE /organization/logo' => 'routes/organization/logo.php',
    'PUT /organization/account' => 'routes/organization/account.php',

    'GET /reference/units' => 'routes/reference/units.php',
    'POST /reference/units' => 'routes/reference/units.php',
    'GET /reference/tax-rates' => 'routes/reference/taxRates.php',
    'POST /reference/tax-rates' => 'routes/reference/taxRates.php',
    'PUT /reference/tax-rates' => 'routes/reference/taxRates.php',

    'GET /customers' => 'routes/customers/customers.php',
    'POST /customers' => 'routes/customers/customers.php',
    'PUT /customers' => 'routes/customers/customers.php',
    'DELETE /customers' => 'routes/customers/customers.php',
    'GET /customers/show' => 'routes/customers/show.php',

    'GET /items' => 'routes/items/items.php',
    'POST /items' => 'routes/items/items.php',
    'PUT /items' => 'routes/items/items.php',
    'DELETE /items' => 'routes/items/items.php',
    'GET /items/show' => 'routes/items/show.php',

    'GET /invoices' => 'routes/invoices/invoices.php',
    'POST /invoices' => 'routes/invoices/invoices.php',
    'PUT /invoices' => 'routes/invoices/invoices.php',
    'DELETE /invoices' => 'routes/invoices/invoices.php',
    'GET /invoices/show' => 'routes/invoices/show.php',
    'GET /invoices/document' => 'routes/invoices/document.php',
    'POST /invoices/send' => 'routes/invoices/send.php',
    'POST /invoices/calculate' => 'routes/invoices/calculate.php',
    'POST /invoices/submit-for-approval' => 'routes/invoices/submitForApproval.php',
    'POST /invoices/approve' => 'routes/invoices/approve.php',
    'POST /invoices/reject' => 'routes/invoices/reject.php',
    'GET /invoices/outstanding' => 'routes/invoices/outstanding.php',

    'GET /credit-notes' => 'routes/creditNotes/creditNotes.php',
    'POST /credit-notes' => 'routes/creditNotes/creditNotes.php',
    'PUT /credit-notes' => 'routes/creditNotes/creditNotes.php',
    'DELETE /credit-notes' => 'routes/creditNotes/creditNotes.php',
    'GET /credit-notes/show' => 'routes/creditNotes/show.php',
    'GET /credit-notes/document' => 'routes/creditNotes/document.php',
    'POST /credit-notes/send' => 'routes/creditNotes/send.php',
    'GET /credit-notes/source-invoice' => 'routes/creditNotes/sourceInvoice.php',
    'POST /credit-notes/submit-for-approval' => 'routes/creditNotes/submitForApproval.php',
    'POST /credit-notes/approve' => 'routes/creditNotes/approve.php',
    'POST /credit-notes/reject' => 'routes/creditNotes/reject.php',

    'GET /proforma-invoices' => 'routes/proformaInvoices/proformaInvoices.php',
    'POST /proforma-invoices' => 'routes/proformaInvoices/proformaInvoices.php',
    'PUT /proforma-invoices' => 'routes/proformaInvoices/proformaInvoices.php',
    'DELETE /proforma-invoices' => 'routes/proformaInvoices/proformaInvoices.php',
    'GET /proforma-invoices/show' => 'routes/proformaInvoices/show.php',
    'GET /proforma-invoices/document' => 'routes/proformaInvoices/document.php',
    'POST /proforma-invoices/send' => 'routes/proformaInvoices/send.php',

    'GET /delivery-notes' => 'routes/deliveryNotes/deliveryNotes.php',
    'POST /delivery-notes' => 'routes/deliveryNotes/deliveryNotes.php',
    'PUT /delivery-notes' => 'routes/deliveryNotes/deliveryNotes.php',
    'DELETE /delivery-notes' => 'routes/deliveryNotes/deliveryNotes.php',
    'GET /delivery-notes/show' => 'routes/deliveryNotes/show.php',
    'GET /delivery-notes/document' => 'routes/deliveryNotes/document.php',
    'GET /delivery-notes/source-invoice' => 'routes/deliveryNotes/sourceInvoice.php',
    'POST /delivery-notes/send' => 'routes/deliveryNotes/send.php',

    'GET /payments' => 'routes/payments/payments.php',
    'POST /payments' => 'routes/payments/payments.php',
    'GET /payments/show' => 'routes/payments/show.php',
    'GET /payments/document' => 'routes/payments/document.php',
    'POST /payments/send' => 'routes/payments/send.php',
    'POST /payments/allocate' => 'routes/payments/allocate.php',
    'POST /payments/void' => 'routes/payments/void.php',

    'GET /dashboard/summary' => 'routes/dashboard/summary.php',
    'GET /dashboard/trends' => 'routes/dashboard/trends.php',
    'GET /dashboard/recent-activity' => 'routes/dashboard/recentActivity.php',
    'GET /global-search' => 'routes/search/global.php',

    'GET /platform/dashboard' => 'routes/platform/dashboard.php',
    'GET /platform/search' => 'routes/platform/search.php',
    'GET /platform/clients' => 'routes/platform/clients.php',
    'GET /platform/client' => 'routes/platform/client.php',
    'PUT /platform/client' => 'routes/platform/client.php',
    'GET /platform/plans' => 'routes/platform/plans.php',
    'POST /platform/plans' => 'routes/platform/plans.php',
    'PUT /platform/plans' => 'routes/platform/plans.php',
    'GET /platform/subscriptions' => 'routes/platform/subscriptions.php',
    'GET /platform/premium-requests' => 'routes/platform/premiumRequests.php',
    'PUT /platform/premium-requests' => 'routes/platform/premiumRequests.php',
    'GET /platform/subscriptions/lifecycle' => 'routes/platform/subscriptionsLifecycle.php',
    'POST /platform/subscriptions/lifecycle' => 'routes/platform/subscriptionsLifecycle.php',
    'GET /platform/revenue' => 'routes/platform/revenue.php',
    'GET /platform/activity' => 'routes/platform/activity.php',
    'GET /platform/settings' => 'routes/platform/settings.php',
    'PUT /platform/settings' => 'routes/platform/settings.php',
    'POST /platform/firs-sandbox-health' => 'routes/platform/firsHealthCheck.php',
    'GET /platform/firs-integration' => 'routes/platform/firsIntegration.php',
    'PUT /platform/firs-integration' => 'routes/platform/firsIntegration.php',
    'GET /platform/firs-resources' => 'routes/platform/firsResources.php',
    'PUT /platform/firs-resources' => 'routes/platform/firsResources.php',
    'GET /platform/profile' => 'routes/platform/profile.php',
    'PUT /platform/profile' => 'routes/platform/profile.php',
    'GET /platform/subscription' => 'routes/platform/subscription.php',
    'PUT /platform/subscription' => 'routes/platform/subscription.php',
    'GET /platform/users' => 'routes/platform/users.php',
    'POST /platform/users' => 'routes/platform/users.php',
    'PUT /platform/users' => 'routes/platform/users.php',
    'DELETE /platform/users' => 'routes/platform/users.php',
    'GET /platform/invitations/validate' => 'routes/platform/validateInvitation.php',
    'POST /platform/invitations/accept' => 'routes/platform/acceptInvitation.php',

    'GET /activity-log' => 'routes/activity/activityLog.php',

    'GET /portal-links' => 'routes/portalLinks/portalLinks.php',
    'POST /portal-links' => 'routes/portalLinks/portalLinks.php',
    'POST /portal-links/revoke' => 'routes/portalLinks/revoke.php',

    'GET /reports/invoices' => 'routes/reports/invoices.php',
    'GET /reports/payments' => 'routes/reports/payments.php',
    'GET /reports/receivables-aging' => 'routes/reports/receivablesAging.php',
    'GET /reports/customer-statement' => 'routes/reports/customerStatement.php',
    'POST /reports/customer-statement/send' => 'routes/reports/sendCustomerStatement.php',
    'GET /reports/vat-summary' => 'routes/reports/vatSummary.php',
    'GET /reports/compliance-readiness' => 'routes/reports/complianceReadiness.php',

    'GET /firs/status' => 'routes/firs/status.php',
    'GET /firs/options' => 'routes/firs/options.php',
    'GET /firs/classification-codes' => 'routes/firs/classificationCodes.php',
    'GET /firs/submission-queue' => 'routes/firs/submissionQueue.php',
    'POST /firs/invoice-readiness' => 'routes/firs/invoiceReadiness.php',
    'POST /firs/credit-note-readiness' => 'routes/firs/creditNoteReadiness.php',

    'GET /team/roles' => 'routes/team/roles.php',
    'GET /team/members' => 'routes/team/members.php',
    'POST /team/invitations' => 'routes/team/invitations.php',
    'DELETE /team/invitations' => 'routes/team/invitations.php',
    'POST /team/invitations/resend' => 'routes/team/resendInvitation.php',
    'GET /team/invitations/validate' => 'routes/team/validateInvitation.php',
    'POST /team/invitations/accept' => 'routes/team/acceptInvitation.php',
    'PUT /team/members/role' => 'routes/team/updateMemberRole.php',
    'PUT /team/members/status' => 'routes/team/updateMemberStatus.php',

    // All operational modules below enforce active workspace and permission checks in their route handlers.
    'GET /workspace/access' => 'routes/workspace/access.php',
];

$routeKey = $method . ' ' . $relativePath;
if (!isset($routes[$routeKey])) {
    jsonError('Route not found.', 404);
}

require __DIR__ . '/' . $routes[$routeKey];
