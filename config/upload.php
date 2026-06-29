<?php

declare(strict_types=1);

return [
    'max_size_mb' => (int) env('UPLOAD_MAX_SIZE_MB', '2'),
    'path'        => env('UPLOAD_PATH', 'public/uploads/badges/'),
];
