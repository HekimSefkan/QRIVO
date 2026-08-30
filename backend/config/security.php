<?php

declare(strict_types=1);

/**
 * Device & session security (PROJECT_SPECIFICATION.md §6.13, SECURITY_RULES.md §10).
 *
 * Spec §6.14 requires risk/security values to be configuration, never hard-coded.
 * A future move to `system_settings` is deferred to the risk-scoring phase
 * (see docs/ACCEPTED_DEVIATIONS.md AD-013).
 */
return [
    'device' => [
        // A user with more than this many concurrently active (non-revoked,
        // non-expired) device sessions is flagged with a SUSPICIOUS_DEVICE event.
        'max_active_sessions' => (int) ($_ENV['SECURITY_MAX_ACTIVE_SESSIONS'] ?? 5),

        // When true, a request whose device fingerprint does not match the
        // fingerprint recorded when the session was issued is REJECTED (401).
        // Default false: the mismatch is always logged as a SUSPICIOUS_DEVICE
        // event and fed to risk scoring, but a fingerprint derived only from a
        // User-Agent is not reliable enough to hard-block on. Deployments that
        // require a stable X-Device-Id can turn this on.
        'enforce_fingerprint_binding' =>
            filter_var($_ENV['SECURITY_ENFORCE_DEVICE_BINDING'] ?? false, FILTER_VALIDATE_BOOL),

        // Idle-session timeout in seconds. 0 disables it. When > 0, a session
        // whose last activity is older than this is rejected on the next request
        // (in addition to the absolute `expires_at`). Enforced server-side.
        'idle_timeout_seconds' => (int) ($_ENV['SECURITY_SESSION_IDLE_TIMEOUT'] ?? 0),
    ],
];
