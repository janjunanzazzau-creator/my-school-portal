-- ============================================================================
--  ORPHANS MODEL NURSERY AND PRIMARY SCHOOL (MAKARANTAR MARAYU)
--  Zazzau Orphans Initiative Foundation
--  School Result Management Portal - PHASE 5 MIGRATION
--  Student Report Card and Result Processing System
--
--  This migration ADDS to the Phase 1 to Phase 4 schema. It never drops,
--  truncates or renames an existing table, so every user, student, teacher,
--  class, subject, session, term, assignment, enrolment and result created
--  earlier is preserved exactly as it was.
--
--  No subject mark is copied anywhere. Individual marks continue to live in the
--  Phase 4 `results` table and are read from there every time a report card is
--  shown, so a corrected mark can never be contradicted by a stale copy.
--
--  Import through phpMyAdmin:
--    1. Open  http://localhost/phpmyadmin
--    2. Select the database `school_result_portal` in the left panel
--    3. Click the "Import" tab
--    4. Choose this file, keep Character set of the file = utf-8, press "Go"
--
--  Or from a command prompt:
--    C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 ^
--        -e "source C:/xampp/htdocs/school-result-portal/database/phase5.sql"
--
--  The file is safe to import more than once: every object is created with
--  IF NOT EXISTS and every insert uses INSERT IGNORE, so nothing is duplicated
--  and no data is lost.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `school_result_portal`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `school_result_portal`;

-- ---------------------------------------------------------------------------
-- 1. REPORT CARDS
--
--    One official report per Student + Class + Academic Session + Term,
--    enforced by a UNIQUE index so a duplicate can never be created, not even
--    by a direct SQL statement.
--
--    The calculated columns are STORED so a signed-off report card shows the
--    same figures forever, which is what an official document has to do. They
--    are still never accepted from the browser: generateReportCard() recomputes
--    every one of them from the approved and locked rows in `results` and
--    refuses to save anything else. Regenerating a report therefore always
--    produces the same official figures, and a corrected result cannot silently
--    disagree with a locked report because a locked report is not regenerated.
--
--    `position` is INT (nullable) rather than a stored word, so sorting and
--    comparing positions stays numeric. It is rendered as "4th" on screen by
--    ordinalSuffix().
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `report_cards` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `report_number`      VARCHAR(40)  NOT NULL COMMENT 'Public number, e.g. ORM-2026-2027-P5-0001',

    `student_id`         INT UNSIGNED NOT NULL,
    `class_id`           INT UNSIGNED NOT NULL,
    `session_id`         INT UNSIGNED NOT NULL,
    `term_id`            INT UNSIGNED NOT NULL,

    -- Calculated from approved and locked `results` only. Never typed in.
    `total_score`        DECIMAL(8, 2) DEFAULT NULL COMMENT 'Sum of the counted subject totals',
    `average_score`      DECIMAL(6, 2) DEFAULT NULL COMMENT 'total_score / subjects_offered, 2dp',
    `position`           INT UNSIGNED DEFAULT NULL COMMENT 'Competition rank within the class',
    `total_students`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Students enrolled in the class for the session',
    `subjects_offered`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `subjects_passed`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `subjects_failed`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    -- Copied from `attendance_summary` at generation time so the printed figure
    -- is fixed. The editable source of truth stays in attendance_summary.
    `attendance_present` SMALLINT UNSIGNED DEFAULT NULL,
    `attendance_absent`  SMALLINT UNSIGNED DEFAULT NULL,
    `attendance_total`   SMALLINT UNSIGNED DEFAULT NULL,

    `teacher_comment`    VARCHAR(500) DEFAULT NULL,
    `principal_comment`  VARCHAR(500) DEFAULT NULL,

    -- The administrator decides this. The portal never chooses for them.
    `promotion_status`   ENUM('Promoted', 'Promoted on Trial', 'Repeat', 'Graduated', 'Pending')
                         NOT NULL DEFAULT 'Pending',

    `status`             ENUM('draft', 'generated', 'approved', 'locked') NOT NULL DEFAULT 'draft',
    `generated_at`       DATETIME     DEFAULT NULL,
    `approved_at`        DATETIME     DEFAULT NULL,
    `approved_by`        INT UNSIGNED DEFAULT NULL COMMENT 'users.id',
    `locked_at`          DATETIME     DEFAULT NULL,
    `locked_by`          INT UNSIGNED DEFAULT NULL COMMENT 'users.id',

    `created_at`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    -- The public number has to be unique too, because it is what a parent is
    -- told to quote. This index is also the backstop for the sequence generator.
    UNIQUE KEY `uq_report_number` (`report_number`),

    -- The core Phase 5 rule: one report card per student, class, session, term.
    UNIQUE KEY `uq_report_student_class_session_term`
        (`student_id`, `class_id`, `session_id`, `term_id`),

    KEY `idx_report_class`   (`class_id`, `session_id`, `term_id`),
    KEY `idx_report_status`  (`status`),
    KEY `idx_report_session` (`session_id`),
    KEY `idx_report_student` (`student_id`),
    KEY `idx_report_number_lookup` (`report_number`),

    CONSTRAINT `fk_report_student` FOREIGN KEY (`student_id`)
        REFERENCES `students` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_report_class` FOREIGN KEY (`class_id`)
        REFERENCES `classes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_report_session` FOREIGN KEY (`session_id`)
        REFERENCES `academic_sessions` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_report_term` FOREIGN KEY (`term_id`)
        REFERENCES `terms` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_report_approved_by` FOREIGN KEY (`approved_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_report_locked_by` FOREIGN KEY (`locked_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,

    -- A passed count and a failed count can never together exceed what was offered.
    CONSTRAINT `chk_report_subject_counts` CHECK (
        `subjects_passed` + `subjects_failed` <= `subjects_offered`
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. ATTENDANCE SUMMARY
--
--    Term level attendance. Daily attendance register is a later phase; this
--    table holds the official term totals the report card prints, and they are
--    always entered, never invented.
--
--    days_present + days_absent = school_days is enforced by a CHECK constraint
--    as well as by the application, so the arithmetic can never be wrong even if
--    a value is written directly.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_summary` (
    `id`               INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `student_id`       INT UNSIGNED   NOT NULL,
    `class_id`         INT UNSIGNED   NOT NULL,
    `session_id`       INT UNSIGNED   NOT NULL,
    `term_id`          INT UNSIGNED   NOT NULL,
    `school_days`      SMALLINT UNSIGNED NOT NULL,
    `days_present`     SMALLINT UNSIGNED NOT NULL,
    `days_absent`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- One attendance record per student, class, session and term.
    UNIQUE KEY `uq_attendance_student_class_session_term`
        (`student_id`, `class_id`, `session_id`, `term_id`),
    KEY `idx_attendance_class`   (`class_id`, `session_id`, `term_id`),
    KEY `idx_attendance_student` (`student_id`),
    KEY `idx_attendance_session` (`session_id`),
    CONSTRAINT `fk_attendance_student` FOREIGN KEY (`student_id`)
        REFERENCES `students` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_attendance_class` FOREIGN KEY (`class_id`)
        REFERENCES `classes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_attendance_session` FOREIGN KEY (`session_id`)
        REFERENCES `academic_sessions` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_attendance_term` FOREIGN KEY (`term_id`)
        REFERENCES `terms` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `chk_attendance_arithmetic` CHECK (`days_present` + `days_absent` = `school_days`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. PSYCHOMOTOR ASSESSMENTS
--
--    Ten behavioural traits rated 1 to 5. The scale is fixed in the database as
--    well as in the form, so an out of range rating cannot be stored:
--        1 = Poor, 2 = Fair, 3 = Good, 4 = Very Good, 5 = Excellent
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `psychomotor_assessments` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id`       INT UNSIGNED NOT NULL,
    `class_id`         INT UNSIGNED NOT NULL,
    `session_id`       INT UNSIGNED NOT NULL,
    `term_id`          INT UNSIGNED NOT NULL,
    `punctuality`      TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `neatness`         TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `honesty`          TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `cooperation`      TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `leadership`       TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `attentiveness`    TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `perseverance`     TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `politeness`       TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `self_control`     TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `responsibility`   TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_psychomotor_student_class_session_term`
        (`student_id`, `class_id`, `session_id`, `term_id`),
    KEY `idx_psychomotor_class`   (`class_id`, `session_id`, `term_id`),
    KEY `idx_psychomotor_student` (`student_id`),
    CONSTRAINT `fk_psychomotor_student` FOREIGN KEY (`student_id`)
        REFERENCES `students` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_psychomotor_class` FOREIGN KEY (`class_id`)
        REFERENCES `classes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_psychomotor_session` FOREIGN KEY (`session_id`)
        REFERENCES `academic_sessions` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_psychomotor_term` FOREIGN KEY (`term_id`)
        REFERENCES `terms` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `chk_psychomotor_scale` CHECK (
        `punctuality` BETWEEN 1 AND 5
    AND `neatness` BETWEEN 1 AND 5
    AND `honesty` BETWEEN 1 AND 5
    AND `cooperation` BETWEEN 1 AND 5
    AND `leadership` BETWEEN 1 AND 5
    AND `attentiveness` BETWEEN 1 AND 5
    AND `perseverance` BETWEEN 1 AND 5
    AND `politeness` BETWEEN 1 AND 5
    AND `self_control` BETWEEN 1 AND 5
    AND `responsibility` BETWEEN 1 AND 5
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. EFFECTIVE AREAS
--
--    Six learning and life skills rated on the same 1 to 5 scale.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `effective_areas` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id`          INT UNSIGNED NOT NULL,
    `class_id`            INT UNSIGNED NOT NULL,
    `session_id`          INT UNSIGNED NOT NULL,
    `term_id`             INT UNSIGNED NOT NULL,
    `communication`       TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `creativity`          TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `problem_solving`     TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `teamwork`            TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `critical_thinking`   TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `practical_skills`    TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `created_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_effective_student_class_session_term`
        (`student_id`, `class_id`, `session_id`, `term_id`),
    KEY `idx_effective_class`   (`class_id`, `session_id`, `term_id`),
    KEY `idx_effective_student` (`student_id`),
    CONSTRAINT `fk_effective_student` FOREIGN KEY (`student_id`)
        REFERENCES `students` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_effective_class` FOREIGN KEY (`class_id`)
        REFERENCES `classes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_effective_session` FOREIGN KEY (`session_id`)
        REFERENCES `academic_sessions` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_effective_term` FOREIGN KEY (`term_id`)
        REFERENCES `terms` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `chk_effective_scale` CHECK (
        `communication` BETWEEN 1 AND 5
    AND `creativity` BETWEEN 1 AND 5
    AND `problem_solving` BETWEEN 1 AND 5
    AND `teamwork` BETWEEN 1 AND 5
    AND `critical_thinking` BETWEEN 1 AND 5
    AND `practical_skills` BETWEEN 1 AND 5
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. REPORT CARD HISTORY
--
--    Every workflow transition on a report card is recorded here: generated,
--    updated, approved, locked and unlocked. This is the audit trail the
--    administrator reads on the report card screen.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `report_card_history` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `report_card_id`  INT UNSIGNED NOT NULL,
    `user_id`         INT UNSIGNED DEFAULT NULL COMMENT 'users.id of the actor',
    `action`          ENUM('generated', 'updated', 'approved', 'locked', 'unlocked') NOT NULL,
    `old_status`      VARCHAR(20)  DEFAULT NULL,
    `new_status`      VARCHAR(20)  DEFAULT NULL,
    `reason`          VARCHAR(255) DEFAULT NULL COMMENT 'Required when unlocking',
    `ip_address`      VARCHAR(45)  DEFAULT NULL,
    `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rch_report` (`report_card_id`),
    KEY `idx_rch_user`   (`user_id`),
    KEY `idx_rch_action` (`action`),
    KEY `idx_rch_created` (`created_at`),
    CONSTRAINT `fk_rch_report` FOREIGN KEY (`report_card_id`)
        REFERENCES `report_cards` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_rch_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 6. MIGRATION MARKER
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('phase5');

-- ---------------------------------------------------------------------------
--  End of Phase 5 migration.
--
--  Added: `report_cards`, `attendance_summary`, `psychomotor_assessments`,
--         `effective_areas`, `report_card_history`.
--  Untouched: every Phase 1 to Phase 4 table and all of its data.
--
--  NOT part of this migration and deliberately left out: the public online
--  result checker and the result PIN, which belong to a later phase.
-- ---------------------------------------------------------------------------