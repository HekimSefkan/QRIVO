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

    // Challenge-response (ATTENDANCE_ALGORITHM.md §4)
    'challenge' => [
        // Challenge lifetime — short-lived. The mobile must submit its response
        // within this window.
        'ttl_seconds'         => (int) ($_ENV['ATTENDANCE_CHALLENGE_TTL_SECONDS'] ?? 120),

        // Rate limiting (step 11): max challenge requests per (student, session)
        // within the window.
        'max_per_window'      => (int) ($_ENV['ATTENDANCE_CHALLENGE_MAX_PER_WINDOW'] ?? 10),
        'window_seconds'      => (int) ($_ENV['ATTENDANCE_CHALLENGE_WINDOW_SECONDS'] ?? 300),
    ],

    // Risk scoring (step 13) — BASIC in Phase 12; the full engine + `system_settings`
    // integration is Phase 19. Values are configurable, never hard-coded (spec §6.14).
    'risk' => [
        // Soft signal: challenge requests for a (student, session) at/over this
        // count within the window elevate risk to MEDIUM.
        'soft_retry_threshold'  => (int) ($_ENV['ATTENDANCE_RISK_SOFT_RETRY'] ?? 6),
        // High signal threshold -> PENDING_REVIEW.
        'high_retry_threshold'  => (int) ($_ENV['ATTENDANCE_RISK_HIGH_RETRY'] ?? 9),
        'retry_window_seconds'  => (int) ($_ENV['ATTENDANCE_RISK_RETRY_WINDOW'] ?? 300),
    ],
];
