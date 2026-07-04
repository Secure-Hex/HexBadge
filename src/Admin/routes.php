<?php

/**
 * Registro de rutas del PANEL ADMIN (incluye endpoints públicos de Open
 * Badges y la API REST, que viven en este mismo docroot).
 *
 * IMPORTANTE: las rutas específicas se registran ANTES que las que llevan
 * parámetros {x} para que '/admin/templates/new' no caiga en '{uuid}'.
 *
 * @var \HexBadge\Core\Router $router
 */

declare(strict_types=1);

use HexBadge\Admin\Controllers\AuthController;
use HexBadge\Admin\Controllers\DashboardController;
use HexBadge\Admin\Controllers\AccountController;
use HexBadge\Admin\Controllers\BadgeTemplateController;
use HexBadge\Admin\Controllers\IssueController;
use HexBadge\Admin\Controllers\BulkIssueController;
use HexBadge\Admin\Controllers\BadgeController;
use HexBadge\Admin\Controllers\EarnerController;
use HexBadge\Admin\Controllers\AnalyticsController;
use HexBadge\Admin\Controllers\UserController;
use HexBadge\Admin\Controllers\ApiKeyController;
use HexBadge\Admin\Controllers\AuditController;
use HexBadge\Admin\Controllers\SettingsController;
use HexBadge\Admin\Controllers\CertificateController;
use HexBadge\Admin\Controllers\DiplomaTemplateController;
use HexBadge\Admin\Controllers\CompanyController;
use HexBadge\Admin\Controllers\FontController;
use HexBadge\Admin\Controllers\ApiController;
use HexBadge\Admin\Controllers\InstallController;

/** @var \HexBadge\Core\Router $router */

// --- Autenticación ---
$router->get('/', [AuthController::class, 'showLogin']);
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/logout', [AuthController::class, 'logout']);
$router->get('/login/2fa', [AuthController::class, 'showTwoFactor']);
$router->post('/login/2fa', [AuthController::class, 'twoFactor']);
$router->get('/forgot-password', [AuthController::class, 'showForgot']);
$router->post('/forgot-password', [AuthController::class, 'sendReset']);
$router->get('/reset-password/{token}', [AuthController::class, 'showReset']);
$router->post('/reset-password/{token}', [AuthController::class, 'reset']);
$router->get('/install', [InstallController::class, 'alreadyInstalled']);

// --- Cuenta del usuario (contraseña + 2FA) ---
$router->get('/admin/account', [AccountController::class, 'index']);
$router->post('/admin/account/password', [AccountController::class, 'changePassword']);
$router->get('/admin/account/totp', [AccountController::class, 'totpSetup']);
$router->post('/admin/account/totp', [AccountController::class, 'totpEnable']);
$router->post('/admin/account/totp/disable', [AccountController::class, 'totpDisable']);

// --- Alta de usuarios por invitación (público con token) ---
$router->get('/accept-invite/{token}', [UserController::class, 'showAccept']);
$router->post('/accept-invite/{token}', [UserController::class, 'accept']);

// NOTA: la verificación pública (/verify, /badges, /issuer) y las imágenes de
// badges viven en el DOMINIO PÚBLICO (portal de personas), no en admin.

// --- Dashboard ---
$router->get('/admin', [DashboardController::class, 'index']);

// --- Templates (específicas antes que {uuid}) ---
$router->get('/admin/templates', [BadgeTemplateController::class, 'index']);
$router->get('/admin/templates/new', [BadgeTemplateController::class, 'create']);
$router->post('/admin/templates', [BadgeTemplateController::class, 'store']);
$router->get('/admin/templates/{uuid}/edit', [BadgeTemplateController::class, 'edit']);
$router->post('/admin/templates/{uuid}/archive', [BadgeTemplateController::class, 'archive']);
$router->get('/admin/templates/{uuid}/certificates', [CertificateController::class, 'downloadIndex']);
$router->post('/admin/templates/{uuid}/certificates', [CertificateController::class, 'downloadBundle']);
$router->get('/admin/templates/{uuid}/certificate', [CertificateController::class, 'show']);
$router->post('/admin/templates/{uuid}/certificate/delete', [CertificateController::class, 'delete']);
$router->post('/admin/templates/{uuid}/certificate', [CertificateController::class, 'save']);
$router->get('/admin/templates/{uuid}', [BadgeTemplateController::class, 'show']);
$router->post('/admin/templates/{uuid}', [BadgeTemplateController::class, 'update']);

// --- Plantillas de diplomas reutilizables (específicas antes que {uuid}) ---
$router->get('/admin/diploma-templates', [DiplomaTemplateController::class, 'index']);
$router->get('/admin/diploma-templates/new', [DiplomaTemplateController::class, 'create']);
$router->post('/admin/diploma-templates', [DiplomaTemplateController::class, 'store']);
$router->get('/admin/diploma-templates/{uuid}/edit', [DiplomaTemplateController::class, 'edit']);
$router->get('/admin/diploma-templates/{uuid}/mark', [DiplomaTemplateController::class, 'mark']);
$router->post('/admin/diploma-templates/{uuid}/mark', [DiplomaTemplateController::class, 'save']);
$router->post('/admin/diploma-templates/{uuid}/delete', [DiplomaTemplateController::class, 'delete']);
$router->post('/admin/diploma-templates/{uuid}', [DiplomaTemplateController::class, 'update']);

// --- Emisión individual ---
$router->get('/admin/issue', [IssueController::class, 'form']);
$router->post('/admin/issue', [IssueController::class, 'issue']);

// --- Emisión masiva CSV ---
$router->get('/admin/bulk-issue', [BulkIssueController::class, 'form']);
$router->post('/admin/bulk-issue', [BulkIssueController::class, 'upload']);
$router->get('/admin/bulk-issue/{uuid}', [BulkIssueController::class, 'show']);

// --- Badges emitidos ---
$router->get('/admin/badges', [BadgeController::class, 'index']);
$router->post('/admin/badges/{uuid}/revoke', [BadgeController::class, 'revoke']);
$router->post('/admin/badges/{uuid}/resend', [BadgeController::class, 'resend']);
$router->get('/admin/badges/{uuid}', [BadgeController::class, 'show']);

// --- Earners ---
$router->get('/admin/earners', [EarnerController::class, 'index']);
$router->get('/admin/earners/export', [EarnerController::class, 'export']);
$router->get('/admin/earners/{uuid}', [EarnerController::class, 'show']);

// --- Analytics ---
$router->get('/admin/analytics', [AnalyticsController::class, 'index']);
$router->get('/admin/analytics/export', [AnalyticsController::class, 'export']);

// --- Usuarios (solo superadmin) ---
$router->get('/admin/users', [UserController::class, 'index']);
$router->post('/admin/users', [UserController::class, 'invite']);
$router->get('/admin/users/{uuid}/edit', [UserController::class, 'edit']);
$router->post('/admin/users/{uuid}', [UserController::class, 'update']);

// --- API keys ---
$router->get('/admin/api-keys', [ApiKeyController::class, 'index']);
$router->post('/admin/api-keys', [ApiKeyController::class, 'store']);
$router->post('/admin/api-keys/{id}/revoke', [ApiKeyController::class, 'revoke']);

// --- Auditoría ---
$router->get('/admin/audit', [AuditController::class, 'index']);

// --- Empresas (multitenancy) ---
$router->get('/admin/company', [CompanyController::class, 'mine']);            // admin edita su empresa
$router->get('/admin/companies', [CompanyController::class, 'index']);        // superadmin: lista
$router->get('/admin/companies/new', [CompanyController::class, 'create']);
$router->post('/admin/companies', [CompanyController::class, 'store']);
$router->get('/admin/companies/{uuid}/edit', [CompanyController::class, 'edit']);
$router->post('/admin/companies/{uuid}/smtp-test', [CompanyController::class, 'smtpTest']);
$router->post('/admin/companies/{uuid}', [CompanyController::class, 'update']);

// --- Tipografías para certificados ---
$router->get('/admin/fonts', [FontController::class, 'index']);
$router->post('/admin/fonts', [FontController::class, 'store']);
$router->get('/admin/fonts/{id}/file', [FontController::class, 'file']);
$router->post('/admin/fonts/{id}/delete', [FontController::class, 'delete']);

// --- Configuración (SMTP) ---
$router->get('/admin/settings', [SettingsController::class, 'index']);
$router->post('/admin/settings', [SettingsController::class, 'save']);
$router->post('/admin/settings/test', [SettingsController::class, 'test']);

// --- API REST v1 ---
$router->get('/api/v1/templates', [ApiController::class, 'listTemplates']);
$router->get('/api/v1/templates/{uuid}', [ApiController::class, 'getTemplate']);
$router->post('/api/v1/badges/issue', [ApiController::class, 'issueBadge']);
$router->post('/api/v1/badges/bulk-issue', [ApiController::class, 'bulkIssue']);
$router->get('/api/v1/badges/{uuid}', [ApiController::class, 'getBadge']);
$router->delete('/api/v1/badges/{uuid}', [ApiController::class, 'revokeBadge']);
$router->get('/api/v1/earners/{email}/badges', [ApiController::class, 'earnerBadges']);
