<?php

/**
 * Rutas del PORTAL EARNER (frontend separado del panel admin).
 *
 * @var \HexBadge\Core\Router $router
 */

declare(strict_types=1);

use HexBadge\Earner\Controllers\HomeController;
use HexBadge\Earner\Controllers\AcceptController;
use HexBadge\Earner\Controllers\WalletController;
use HexBadge\Earner\Controllers\EarnerAuthController;
use HexBadge\Earner\Controllers\ProfileController;
use HexBadge\Earner\Controllers\SecurityController;
use HexBadge\Earner\Controllers\VerifyController;
use HexBadge\Earner\Controllers\CertificateController;

/** @var \HexBadge\Core\Router $router */

$router->get('/', [HomeController::class, 'index']);

// --- Verificación pública + Open Badges (dominio público) ---
$router->get('/issuer', [VerifyController::class, 'issuer']);
$router->get('/verify/{uuid}.json', [VerifyController::class, 'assertionJson']);
$router->get('/verify/{uuid}', [VerifyController::class, 'show']);
$router->get('/badges/{uuid}', [VerifyController::class, 'badgeClass']);
$router->get('/certificate/{uuid}.pdf', [CertificateController::class, 'download']);

// Login / logout del receptor
$router->get('/login', [EarnerAuthController::class, 'showLogin']);
$router->post('/login', [EarnerAuthController::class, 'login']);
$router->get('/login/2fa', [EarnerAuthController::class, 'showTwoFactor']);
$router->post('/login/2fa', [EarnerAuthController::class, 'twoFactor']);
$router->get('/logout', [EarnerAuthController::class, 'logout']);

// Seguridad de la cuenta (contraseña + 2FA)
$router->get('/me/security', [SecurityController::class, 'index']);
$router->post('/me/security/password', [SecurityController::class, 'changePassword']);
$router->get('/me/security/totp', [SecurityController::class, 'totpSetup']);
$router->post('/me/security/totp', [SecurityController::class, 'totpEnable']);
$router->post('/me/security/totp/disable', [SecurityController::class, 'totpDisable']);

// Claim del badge (login o registro + aceptación)
$router->get('/accept/{token}', [AcceptController::class, 'show']);
$router->post('/accept/{token}', [AcceptController::class, 'claim']);

// Panel privado / perfil
$router->get('/me', [ProfileController::class, 'me']);
$router->get('/me/profile', [ProfileController::class, 'editProfile']);
$router->post('/me/profile', [ProfileController::class, 'saveProfile']);

// Decidir sobre un badge pendiente (dueño autenticado)
$router->get('/me/badge/{uuid}', [ProfileController::class, 'showBadge']);
$router->post('/me/badge/{uuid}/accept', [ProfileController::class, 'acceptBadge']);
$router->post('/me/badge/{uuid}/reject', [ProfileController::class, 'rejectBadge']);

// Wallet pública por uuid
$router->get('/earner/{uuid}', [WalletController::class, 'show']);
