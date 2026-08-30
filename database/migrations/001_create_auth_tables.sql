-- QRIVO — Authentication Tables Migration
-- Migration: 001_create_auth_tables
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
--
-- Creates: users, roles, permissions, role_permissions, user_roles,
--          login_attempts, device_sessions, security_events, audit_logs
--
-- Run this migration before starting the backend application.

SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci';
SET FOREIGN_KEY_CHECKS = 0;

-- ─── roles ────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `roles` (
    `id`           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `name`         VARCHAR(50)      NOT NULL COMMENT 'e.g. SUPER_ADMIN, ADMIN, TEACHER, STUDENT',
    `display_name` VARCHAR(100)     NOT NULL,
    `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_roles_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='System roles: SUPER_ADMIN, ADMIN, TEACHER, STUDENT';

-- ─── permissions ──────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `permissions` (
    `id`           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `name`         VARCHAR(100)     NOT NULL COMMENT 'e.g. attendance.session.start',
    `display_name` VARCHAR(150)     NOT NULL,
    `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_permissions_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Granular permission definitions for RBAC';

-- ─── role_permissions ─────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `role_permissions` (
    `role_id`       INT UNSIGNED NOT NULL,
    `permission_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`),
    CONSTRAINT `fk_rp_role`       FOREIGN KEY (`role_id`)       REFERENCES `roles`(`id`)       ON DELETE CASCADE,
    CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Many-to-many: roles to permissions';

-- ─── users ────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `users` (
    `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `uuid`          CHAR(36)         NOT NULL COMMENT 'UUIDv4; used for external references',
    `email`         VARCHAR(255)     NOT NULL COMMENT 'Login identifier; unique',
    `password_hash` VARCHAR(255)     NOT NULL COMMENT 'Argon2id hash — NEVER store plaintext',
    `first_name`    VARCHAR(100)     NOT NULL,
    `last_name`     VARCHAR(100)     NOT NULL,
    `is_active`     TINYINT(1)       NOT NULL DEFAULT 0,
    `is_approved`   TINYINT(1)       NOT NULL DEFAULT 0,
    `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`    DATETIME             NULL DEFAULT NULL COMMENT 'Soft delete',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_uuid`  (`uuid`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_email`       (`email`),
    KEY `idx_users_uuid`        (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Core identity record; password_hash is Argon2id only';

-- ─── user_roles ───────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `user_roles` (
    `user_id`    BIGINT UNSIGNED NOT NULL,
    `role_id`    INT UNSIGNED    NOT NULL,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `role_id`),
    CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Many-to-many: users to roles';

-- ─── login_attempts ───────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`          BIGINT UNSIGNED     NULL DEFAULT NULL COMMENT 'NULL if user not found',
    `email_attempted`  VARCHAR(255)    NOT NULL COMMENT 'What was submitted',
    `ip_address`       VARCHAR(45)     NOT NULL COMMENT 'IPv4 or IPv6',
    `user_agent`       TEXT                NULL,
    `success`          TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '1 = successful login',
    `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_la_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    KEY `idx_la_ip_time`    (`ip_address`, `created_at`),
    KEY `idx_la_user_time`  (`user_id`, `created_at`),
    KEY `idx_la_email_time` (`email_attempted`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Auth attempts for rate limiting and security monitoring';

-- ─── device_sessions ──────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `device_sessions` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`                CHAR(36)        NOT NULL COMMENT 'Unique session identifier',
    `user_id`             BIGINT UNSIGNED NOT NULL,
    `device_fingerprint`  VARCHAR(255)        NULL DEFAULT NULL,
    `device_name`         VARCHAR(255)        NULL DEFAULT NULL,
    `ip_address`          VARCHAR(45)         NULL DEFAULT NULL,
    `user_agent`          TEXT                NULL,
    `access_token_hash`   VARCHAR(255)        NULL DEFAULT NULL COMMENT 'SHA-256 of access token; NEVER store plaintext',
    `refresh_token_hash`  VARCHAR(255)        NULL DEFAULT NULL COMMENT 'SHA-256 of refresh token; NEVER store plaintext',
    `expires_at`          DATETIME        NOT NULL,
    `last_active_at`      DATETIME            NULL DEFAULT NULL,
    `revoked_at`          DATETIME            NULL DEFAULT NULL COMMENT 'Non-null = explicitly revoked (logout)',
    `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ds_uuid`                (`uuid`),
    CONSTRAINT `fk_ds_user` FOREIGN KEY   (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    KEY `idx_ds_user_expires`              (`user_id`, `expires_at`),
    KEY `idx_ds_access_token_hash`         (`access_token_hash`),
    KEY `idx_ds_refresh_token_hash`        (`refresh_token_hash`),
    KEY `idx_ds_expires`                   (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Authenticated device sessions; tokens stored as hashes only';

-- ─── security_events ──────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `security_events` (
    `id`                    BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `event_type`            VARCHAR(100)     NOT NULL COMMENT 'e.g. LOGIN_FAILURE, QR_REPLAY',
    `severity`              ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL,
    `user_id`               BIGINT UNSIGNED      NULL DEFAULT NULL,
    `attendance_session_id` BIGINT UNSIGNED      NULL DEFAULT NULL,
    `ip_address`            VARCHAR(45)          NULL DEFAULT NULL,
    `user_agent`            TEXT                 NULL,
    `details`               JSON                 NULL COMMENT 'Must never contain passwords, tokens, or private keys',
    `created_at`            DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_se_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    KEY `idx_se_type_time`     (`event_type`, `created_at`),
    KEY `idx_se_user_time`     (`user_id`, `created_at`),
    KEY `idx_se_severity_time` (`severity`, `created_at`),
    KEY `idx_se_time`          (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Security event log; append-only; no secrets in details';

-- ─── audit_logs ───────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_type`    VARCHAR(100)    NOT NULL COMMENT 'e.g. LOGIN_SUCCESS, ATTENDANCE_STATUS_CHANGED',
    `actor_user_id` BIGINT UNSIGNED     NULL DEFAULT NULL,
    `target_entity` VARCHAR(100)    NOT NULL,
    `target_id`     BIGINT UNSIGNED     NULL DEFAULT NULL,
    `old_value`     JSON                NULL COMMENT 'State before; no secrets',
    `new_value`     JSON                NULL COMMENT 'State after; no secrets',
    `reason`        TEXT                NULL,
    `ip_address`    VARCHAR(45)         NULL DEFAULT NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_al_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    KEY `idx_al_actor_time`    (`actor_user_id`, `created_at`),
    KEY `idx_al_entity`        (`target_entity`, `target_id`),
    KEY `idx_al_type_time`     (`event_type`, `created_at`),
    KEY `idx_al_time`          (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit trail; append-only; no secrets';

-- ─── Seed: default roles ──────────────────────────────────────────────────────

INSERT IGNORE INTO `roles` (`name`, `display_name`) VALUES
    ('SUPER_ADMIN', 'Super Administrator'),
    ('ADMIN',       'Administrator'),
    ('TEACHER',     'Teacher'),
    ('STUDENT',     'Student');

SET FOREIGN_KEY_CHECKS = 1;
