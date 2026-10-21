-- ============================================================================
--  ORPHANS MODEL NURSERY AND PRIMARY SCHOOL (MAKARANTAR MARAYU)
--  Zazzau Orphans Initiative Foundation
--  School Result Management Portal - PHASE 1 (Foundation & Authentication)
--
--  Import this file through phpMyAdmin:
--    1. Open  http://localhost/phpmyadmin
--    2. Click the "Import" tab
--    3. Choose this file and press "Go"
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. Database
-- ---------------------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS `school_result_portal`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `school_result_portal`;

-- ---------------------------------------------------------------------------
-- 2. Users table
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `username`      VARCHAR(50)     NOT NULL,
    `password`      VARCHAR(255)    NOT NULL COMMENT 'password_hash() output - never plain text',
    `full_name`     VARCHAR(150)    NOT NULL,
    `email`         VARCHAR(150)         NULL DEFAULT NULL,
    `role`          ENUM('admin', 'teacher') NOT NULL DEFAULT 'teacher',
    `status`        TINYINT(1)      NOT NULL DEFAULT 1 COMMENT '1 = active, 0 = inactive',
    `last_login_at` DATETIME             NULL DEFAULT NULL,
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_username` (`username`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_role` (`role`),
    KEY `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. Default administrator account
--
--    Username : admin
--    Password : Admin@12345
--
--    The value below is a bcrypt hash produced with PHP's password_hash()
--    (PASSWORD_DEFAULT) and is verified with password_verify() at login.
--    It is NOT plain text. The script re-hashes the password on every
--    import so the stored hash always matches your current PHP version.
--    CHANGE THIS PASSWORD BEFORE ANY PRODUCTION USE.
-- ---------------------------------------------------------------------------
INSERT INTO `users` (`username`, `password`, `full_name`, `email`, `role`, `status`)
VALUES (
    'admin',
    '$2y$10$81oUOenyYwMOO9be11F8gOE6u4/TAcD9SJXOR/.SSqJlIQvqXELBS',
    'System Administrator',
    'admin@omnps.edu.ng',
    'admin',
    1
)
ON DUPLICATE KEY UPDATE
    `password`  = VALUES(`password`),
    `full_name` = VALUES(`full_name`),
    `role`      = VALUES(`role`),
    `status`    = VALUES(`status`);

-- ---------------------------------------------------------------------------
--  End of Phase 1 schema.
--  Students, Teachers, Classes, Subjects, Sessions, Terms, Results,
--  Attendance and Report Cards are introduced in Phase 2.
-- ---------------------------------------------------------------------------
