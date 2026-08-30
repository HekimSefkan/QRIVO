<?php

declare(strict_types=1);

return [
    // Token lifetimes
    'access_token_ttl'  => (int) ($_ENV['AUTH_ACCESS_TOKEN_TTL']  ?? 3600),    // 1 hour
    'refresh_token_ttl' => (int) ($_ENV['AUTH_REFRESH_TOKEN_TTL'] ?? 2592000), // 30 days

    // Rate limiting (server-side only)
    'rate_limit' => [
        'max_by_ip'      => (int) ($_ENV['AUTH_RATE_LIMIT_MAX_IP']      ?? 20),
        'max_by_email'   => (int) ($_ENV['AUTH_RATE_LIMIT_MAX_EMAIL']   ?? 10),
        'window_seconds' => (int) ($_ENV['AUTH_RATE_LIMIT_WINDOW']      ?? 900), // 15 minutes
    ],
];
