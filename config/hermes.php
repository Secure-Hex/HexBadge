<?php

declare(strict_types=1);

// Login del panel admin con Hermes (OpenID Connect, docs: Hermes docs/integration.md).
return [
    'issuer'        => env('HERMES_ISSUER', 'https://hermes.securehex.cl'),
    'client_id'     => env('HERMES_CLIENT_ID', ''),
    'client_secret' => env('HERMES_CLIENT_SECRET', ''),
    'redirect_uri'  => env('HERMES_REDIRECT_URI', 'https://badge.securehex.cl/auth/hermes/callback'),
    'scopes'        => 'openid profile email roles',
    // Break-glass: login local con contraseña, solo superadmins. Apagado por defecto.
    'local_login'   => filter_var(env('ADMIN_LOCAL_LOGIN', 'false'), FILTER_VALIDATE_BOOLEAN),
];
