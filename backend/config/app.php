<?php

declare(strict_types=1);

return [
    'name'     => $_ENV['APP_NAME']    ?? 'QRIVO',
    'env'      => $_ENV['APP_ENV']     ?? 'production',
    'debug'    => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'url'      => $_ENV['APP_URL']     ?? 'http://localhost',
    'timezone' => $_ENV['APP_TIMEZONE'] ?? 'UTC',
    'locale'   => $_ENV['APP_LOCALE']  ?? 'en',

    'cors' => [
        'allowed_origins' => array_filter(explode(',', $_ENV['CORS_ALLOWED_ORIGINS'] ?? '*')),
        'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'Accept', 'X-Requested-With'],
        'max_age'         => 86400,
    ],

    'api' => [
        'version' => 'v1',
        'prefix'  => '/api/v1',
    ],
];
