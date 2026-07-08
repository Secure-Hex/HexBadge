<?php

declare(strict_types=1);

return [
    'name'   => env('APP_NAME', 'HexBadge'),
    'version' => env('APP_VERSION', '1.0'),
    'url'    => rtrim(env('APP_URL', 'http://localhost'), '/'),
    // URL del portal earner (frontend separado). Si no se define, cae a APP_URL.
    'earner_url' => rtrim(env('APP_EARNER_URL', '') ?: env('APP_URL', 'http://localhost'), '/'),
    'env'    => env('APP_ENV', 'production'),
    'debug'  => filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
    'secret' => env('APP_SECRET', ''),
];
