<?php

declare(strict_types=1);

/**
 * Risk scoring (PROJECT_SPECIFICATION.md §6.14, ATTENDANCE_ALGORITHM.md §9,
 * SECURITY_RULES.md §8).
 *
 * Spec §6.14: "Risk values managed via `system_settings` or configuration — not
 * hard-coded." Resolution order for every value below:
 *
 *   system_settings row  →  this file (env-overridable)  →  RiskSignal::defaultWeight()
 *
 * These are the ONLY tunables. The signal catalogue itself is fixed in
 * QRIVO\Domain\Enum\RiskSignal; the level→outcome mapping is fixed in
 * QRIVO\Domain\Enum\RiskLevel::toOutcome().
 */
return [
    // Per-signal weights (points added to the score when the signal fires).
    'weight' => [
        'expired_qr'                => (int) ($_ENV['RISK_WEIGHT_EXPIRED_QR']                ?? 15),
        'replay_attempt'            => (int) ($_ENV['RISK_WEIGHT_REPLAY_ATTEMPT']            ?? 60),
        'invalid_challenge'         => (int) ($_ENV['RISK_WEIGHT_INVALID_CHALLENGE']         ?? 25),
        'excessive_retry'           => (int) ($_ENV['RISK_WEIGHT_EXCESSIVE_RETRY']           ?? 30),
        'duplicate_attendance'      => (int) ($_ENV['RISK_WEIGHT_DUPLICATE_ATTENDANCE']      ?? 50),
        'new_device'                => (int) ($_ENV['RISK_WEIGHT_NEW_DEVICE']                ?? 15),
        'multiple_device_activity'  => (int) ($_ENV['RISK_WEIGHT_MULTIPLE_DEVICE_ACTIVITY']  ?? 40),
        'suspicious_ip'             => (int) ($_ENV['RISK_WEIGHT_SUSPICIOUS_IP']             ?? 15),
        'location_mismatch'         => (int) ($_ENV['RISK_WEIGHT_LOCATION_MISMATCH']         ?? 40),
        'unauthorized_relationship' => (int) ($_ENV['RISK_WEIGHT_UNAUTHORIZED_RELATIONSHIP'] ?? 100),
    ],

    // Score → level thresholds. score ≥ threshold ⇒ (at least) that level.
    'threshold' => [
        'medium'  => (int) ($_ENV['RISK_THRESHOLD_MEDIUM']  ?? 30),
        'high'    => (int) ($_ENV['RISK_THRESHOLD_HIGH']    ?? 60),
        'blocked' => (int) ($_ENV['RISK_THRESHOLD_BLOCKED'] ?? 100),
    ],

    // Challenge-retry detection (EXCESSIVE_RETRY).
    'retry' => [
        'window_seconds'  => (int) ($_ENV['RISK_RETRY_WINDOW_SECONDS']  ?? 300),
        'excessive_count' => (int) ($_ENV['RISK_RETRY_EXCESSIVE_COUNT'] ?? 6),
    ],

    // Look-back over `security_events` for recent-abuse signals (EXPIRED_QR,
    // REPLAY_ATTEMPT, INVALID_CHALLENGE, DUPLICATE_ATTENDANCE,
    // UNAUTHORIZED_RELATIONSHIP) attributed to the same user.
    'history' => [
        'window_seconds' => (int) ($_ENV['RISK_HISTORY_WINDOW_SECONDS'] ?? 900),
    ],

    // SUSPICIOUS_IP: exact IPs that trip the signal. Empty by default so campus
    // shared WiFi never produces a false positive (OQ-010). Comma-separated.
    'ip' => [
        'suspicious_list' => (string) ($_ENV['RISK_SUSPICIOUS_IPS'] ?? ''),
    ],
];
