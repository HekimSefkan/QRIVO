-- QRIVO — System Settings Migration
-- Migration: 008_create_system_settings
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
--
-- Depends on: nothing (standalone key/value store)
--
-- Creates:
--   system_settings — configurable system parameters (TABLES.md Group 8)
--
-- Seeds the risk-scoring parameters (PROJECT_SPECIFICATION.md §6.14 /
-- ATTENDANCE_ALGORITHM.md §9). These rows are the highest-priority source for
-- the risk engine; `config/risk.php` is the fallback, and
-- QRIVO\Domain\Enum\RiskSignal::defaultWeight() is the last resort. Editing a
-- row here re-tunes risk scoring without a deploy. The signal catalogue itself
-- is fixed in code and is NOT configurable.

SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci';

CREATE TABLE IF NOT EXISTS `system_settings` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `key`         VARCHAR(100)  NOT NULL,
    `value`       TEXT          NOT NULL COMMENT 'String-encoded value; interpret per `type`',
    `type`        VARCHAR(50)   NOT NULL DEFAULT 'string' COMMENT 'string | integer | boolean | json',
    `description` TEXT              NULL,
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_system_settings_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Configurable system parameters — risk thresholds, attendance behaviour';

-- ─── Risk scoring: per-signal weights ───────────────────────────────────────
INSERT IGNORE INTO `system_settings` (`key`, `value`, `type`, `description`) VALUES
    ('risk.weight.expired_qr',                '15',  'integer', 'Points added when a recent EXPIRED_QR event is attributed to the student'),
    ('risk.weight.replay_attempt',            '60',  'integer', 'Points added for a recent REPLAY_ATTEMPT (QR nonce / challenge reuse)'),
    ('risk.weight.invalid_challenge',         '25',  'integer', 'Points added for a recent INVALID_CHALLENGE / invalid QR event'),
    ('risk.weight.excessive_retry',           '30',  'integer', 'Points added when challenge requests in the window reach risk.retry.excessive_count'),
    ('risk.weight.duplicate_attendance',      '50',  'integer', 'Points added for a recent DUPLICATE_ATTENDANCE event'),
    ('risk.weight.new_device',                '15',  'integer', 'Points added when the attempt comes from a newly seen device'),
    ('risk.weight.multiple_device_activity',  '40',  'integer', 'Points added for multiple active devices / a device-fingerprint mismatch'),
    ('risk.weight.suspicious_ip',             '15',  'integer', 'Points added when the request IP is on risk.ip.suspicious_list'),
    ('risk.weight.location_mismatch',         '40',  'integer', 'Points added when a location-mismatch signal is supplied (OQ-003)'),
    ('risk.weight.unauthorized_relationship', '100', 'integer', 'Points added for a recent UNAUTHORIZED_ATTENDANCE event (not enrolled)'),
-- ─── Risk scoring: score → level thresholds ─────────────────────────────────
    ('risk.threshold.medium',  '30',  'integer', 'Score >= this ⇒ MEDIUM (PRESENT + SECURITY_EVENT)'),
    ('risk.threshold.high',    '60',  'integer', 'Score >= this ⇒ HIGH (PENDING_REVIEW)'),
    ('risk.threshold.blocked', '100', 'integer', 'Score >= this ⇒ BLOCKED (no attendance record)'),
-- ─── Risk scoring: detection windows ────────────────────────────────────────
    ('risk.retry.window_seconds',   '300', 'integer', 'Look-back for counting challenge requests per (student, session)'),
    ('risk.retry.excessive_count',  '6',   'integer', 'Challenge requests in the window that trip EXCESSIVE_RETRY'),
    ('risk.history.window_seconds', '900', 'integer', 'Look-back over security_events for recent-abuse signals'),
    ('risk.ip.suspicious_list',     '',    'string',  'Comma-separated exact IPs that trip SUSPICIOUS_IP (empty ⇒ never; OQ-010)');
