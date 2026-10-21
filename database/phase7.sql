-- ============================================================================
--  ORPHANS MODEL NURSERY AND PRIMARY SCHOOL (MAKARANTAR MARAYU)
--  Zazzau Orphans Initiative Foundation
--  School Result Management Portal - PHASE 7 MIGRATION
--  School Settings and Automatic Registration Number
--
--  This migration ADDS to the Phase 1 to Phase 6 schema.
--  Import through phpMyAdmin or command line.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `school_result_portal`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `school_result_portal`;

-- ---------------------------------------------------------------------------
-- 1. SCHOOL SETTINGS
--    Dynamic school identity configuration.
--    Single row (id=1) holds all school branding information.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `school_settings` (
    `id`                    TINYINT UNSIGNED NOT NULL DEFAULT 1,

    `school_name`           VARCHAR(120) NOT NULL,
    `school_short_name`     VARCHAR(20)  NOT NULL,
    `school_campaign`       VARCHAR(80)  DEFAULT NULL,
    `school_foundation`     VARCHAR(120) DEFAULT NULL,
    `school_motto`          VARCHAR(120) DEFAULT NULL,
    `school_address`        VARCHAR(255) DEFAULT NULL,
    `school_phone`          VARCHAR(40)  DEFAULT NULL,
    `school_email`          VARCHAR(120) DEFAULT NULL,
    `school_website`        VARCHAR(120) DEFAULT NULL,

    `logo_path`             VARCHAR(255) DEFAULT NULL COMMENT 'Path to school logo image',
    `stamp_path`            VARCHAR(255) DEFAULT NULL COMMENT 'Path to school stamp/seal image',

    `created_at`            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default school settings (matches current hardcoded values)
INSERT INTO `school_settings` (
    `id`, `school_name`, `school_short_name`, `school_campaign`,
    `school_foundation`, `school_motto`, `school_address`,
    `school_phone`, `school_email`, `school_website`,
    `logo_path`, `stamp_path`
) VALUES (
    1,
    'ORPHANS MODEL NURSERY AND PRIMARY SCHOOL',
    'OMNPS',
    '(MAKARANTAR MARAYU)',
    'ZAZZAU ORPHANS INITIATIVE FOUNDATION',
    'KNOWLEDGE IS SHIELD TO THE ORPHANS',
    'Zazzau, Kaduna State, Nigeria',
    '+234 803 000 0000',
    'info@omnpzazzau.edu.ng',
    'https://omnpzazzau.edu.ng',
    'assets/images/logo.jpeg',
    NULL
) ON DUPLICATE KEY UPDATE
    `school_name`       = VALUES(`school_name`),
    `school_short_name` = VALUES(`school_short_name`),
    `school_campaign`   = VALUES(`school_campaign`),
    `school_foundation` = VALUES(`school_foundation`),
    `school_motto`      = VALUES(`school_motto`),
    `school_address`    = VALUES(`school_address`),
    `school_phone`      = VALUES(`school_phone`),
    `school_email`      = VALUES(`school_email`),
    `school_website`    = VALUES(`school_website`),
    `logo_path`         = VALUES(`logo_path`),
    `stamp_path`        = VALUES(`stamp_path`);

-- ---------------------------------------------------------------------------
-- 2. REGISTRATION NUMBER SEQUENCES
--    Tracks the next sequence number per school abbreviation per year.
--    Format: [ABBREVIATION]/[YY]/[SEQUENCE] e.g. OMNPS/26/001
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `registration_sequences` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,

    `school_short_name`     VARCHAR(20)  NOT NULL COMMENT 'School abbreviation',
    `year_two_digit`        SMALLINT UNSIGNED NOT NULL COMMENT 'Two-digit year (e.g. 26 for 2026)',

    `next_sequence`         INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Next sequence number to assign',

    `created_at`            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    -- One sequence per school per year
    UNIQUE KEY `uq_reg_seq_school_year` (`school_short_name`, `year_two_digit`),

    KEY `idx_reg_seq_school` (`school_short_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. MIGRATION MARKER
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('phase7');

-- ---------------------------------------------------------------------------
-- End of Phase 7 migration.
-- ---------------------------------------------------------------------------