<?php

declare(strict_types=1);

return [
    'login'        => (int) env('RATE_LIMIT_LOGIN', '5'),
    'login_window' => (int) env('RATE_LIMIT_LOGIN_WINDOW', '900'),
    'api'          => (int) env('RATE_LIMIT_API', '100'),
    'verify'       => (int) env('RATE_LIMIT_VERIFY', '30'),
    'csv'          => (int) env('RATE_LIMIT_CSV', '3'),
];
