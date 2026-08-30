<?php

declare(strict_types=1);

return [
    // Dynamic QR (ATTENDANCE_ALGORITHM.md §3, SECURITY_RULES.md §5)
    'qr' => [
        // How long a generated QR payload stays valid, in seconds.
        // OQ-008: `.env.example` default is 30. Configurable here; a move to
        // `system_settings` is deferred.
        'ttl_seconds'        => (int) ($_ENV['QR_TTL_SECONDS'] ?? 30),

        // How often the teacher screen should refresh the QR (defaults to the TTL).
        'refresh_seconds'    => (int) ($_ENV['QR_REFRESH_SECONDS'] ?? ($_ENV['QR_TTL_SECONDS'] ?? 30)),

        // Small allowance for client/server clock skew when checking QR age.
        'clock_skew_seconds' => (int) ($_ENV['QR_CLOCK_SKEW_SECONDS'] ?? 5),
    ],
];
