-- ============================================================================
--  ORPHANS MODEL NURSERY AND PRIMARY SCHOOL (MAKARANTAR MARAYU)
--  Zazzau Orphans Initiative Foundation
--  School Result Management Portal - PHASE 2 MIGRATION
--  Student / Teacher / Class / Subject Management
--
--  This migration ADDS to the Phase 1 schema. It never drops or modifies the
--  existing `users` table, so the administrator account created in Phase 1 is
--  preserved.
--
--  Import through phpMyAdmin:
--    1. Open  http://localhost/phpmyadmin
--    2. Select the database `school_result_portal` in the left panel
--    3. Click the "Import" tab
--    4. Choose this file, keep Character set of the file = utf-8, press "Go"
--
--  The file is safe to import more than once: every object is created with
--  IF NOT EXISTS, so nothing is duplicated.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `school_result_portal`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `school_result_portal`;

-- ---------------------------------------------------------------------------
-- 1. TEACHERS
--    Created first because `classes.class_teacher_id` references it.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `teachers` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `staff_number`  VARCHAR(30)  NOT NULL,
    `first_name`    VARCHAR(50)  NOT NULL,
    `middle_name`   VARCHAR(50)      NULL DEFAULT NULL,
    `last_name`     VARCHAR(50)      NULL DEFAULT NULL,
    `gender`        ENUM('Male', 'Female') NOT NULL,
    `phone`         VARCHAR(30)      NULL DEFAULT NULL,
    `email`         VARCHAR(120)     NULL DEFAULT NULL,
    `address`       VARCHAR(255)     NULL DEFAULT NULL,
    `qualification` VARCHAR(120)     NULL DEFAULT NULL,
    `department`    VARCHAR(80)      NULL DEFAULT NULL,
    `user_id`       INT UNSIGNED     NULL DEFAULT NULL COMMENT 'Optional portal login account',
    `status`        ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_teachers_staff_number` (`staff_number`),
    UNIQUE KEY `uq_teachers_email` (`email`),
    UNIQUE KEY `uq_teachers_user` (`user_id`),
    KEY `idx_teachers_status` (`status`),
    KEY `idx_teachers_name` (`last_name`, `first_name`),
    KEY `idx_teachers_user_fk` (`user_id`),
    CONSTRAINT `fk_teachers_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. CLASSES
--    Created after teachers and before students (students.class_id -> classes).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `classes` (
    `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `class_name`       VARCHAR(50)   NOT NULL,
    `section`          ENUM('Nursery', 'Primary', 'Secondary') NOT NULL,
    `class_teacher_id` INT UNSIGNED      NULL DEFAULT NULL,
    `capacity`         SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    `status`           ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_classes_name_section` (`class_name`, `section`),
    KEY `idx_classes_section` (`section`),
    KEY `idx_classes_status` (`status`),
    KEY `idx_classes_teacher_fk` (`class_teacher_id`),
    CONSTRAINT `fk_classes_teacher`
        FOREIGN KEY (`class_teacher_id`) REFERENCES `teachers` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. STUDENTS
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `students` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `admission_number`  VARCHAR(30)  NOT NULL,
    `first_name`        VARCHAR(50)  NOT NULL,
    `middle_name`       VARCHAR(50)      NULL DEFAULT NULL,
    `last_name`         VARCHAR(50)      NULL DEFAULT NULL,
    `gender`            ENUM('Male', 'Female') NOT NULL,
    `date_of_birth`     DATE             NULL DEFAULT NULL,
    `passport_photo`    VARCHAR(255)     NULL DEFAULT NULL,
    `parent_name`       VARCHAR(120)     NULL DEFAULT NULL,
    `parent_phone`      VARCHAR(30)      NULL DEFAULT NULL,
    `parent_email`      VARCHAR(120)     NULL DEFAULT NULL,
    `address`           VARCHAR(255)     NULL DEFAULT NULL,
    `section`           ENUM('Nursery', 'Primary', 'Secondary') NOT NULL,
    `class_id`          INT UNSIGNED     NULL DEFAULT NULL,
    `admission_date`    DATE             NULL DEFAULT NULL,
    `status`            ENUM('Active', 'Graduated', 'Transferred', 'Suspended', 'Inactive') NOT NULL DEFAULT 'Active',
    `created_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_students_admission_number` (`admission_number`),
    KEY `idx_students_name` (`last_name`, `first_name`),
    KEY `idx_students_section` (`section`),
    KEY `idx_students_status` (`status`),
    KEY `idx_students_class` (`class_id`),
    CONSTRAINT `fk_students_class`
        FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. SUBJECTS
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subjects` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `subject_code` VARCHAR(20)  NOT NULL,
    `subject_name` VARCHAR(120) NOT NULL,
    `section`      ENUM('Nursery', 'Primary', 'Secondary') NOT NULL,
    `status`       ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_subjects_code` (`subject_code`),
    KEY `idx_subjects_name` (`subject_name`),
    KEY `idx_subjects_section` (`section`),
    KEY `idx_subjects_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. SUPPORTING OBJECTS
-- ---------------------------------------------------------------------------

-- Keeps the upload folder reachable but not executable.
CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `version`     VARCHAR(30)  NOT NULL,
    `applied_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('phase2');

-- ---------------------------------------------------------------------------
--  End of Phase 2 migration.
--  The `users` table and the default administrator account are untouched.
--  Result entry, report cards, attendance, sessions and terms are NOT part of
--  this migration - they belong to later phases.
-- ---------------------------------------------------------------------------
