-- ============================================================================
--  ORPHANS MODEL NURSERY AND PRIMARY SCHOOL (MAKARANTAR MARAYU)
--  Zazzau Orphans Initiative Foundation
--  School Result Management Portal - PHASE 3 MIGRATION
--  Academic sessions, terms and the assignment / enrolment layer
--
--  This migration ADDS to the Phase 1 and Phase 2 schema. It never drops or
--  modifies `users`, `students`, `teachers`, `classes` or `subjects`, so every
--  record created in the earlier phases is preserved.
--
--  `students.class_id` is deliberately KEPT: it is the student's current class
--  for convenience. The new `student_class_enrollments` table records the class
--  a student belonged to in a given academic session, so history is never
--  overwritten when a student is promoted.
--
--  Import through phpMyAdmin:
--    1. Open  http://localhost/phpmyadmin
--    2. Select the database `school_result_portal` in the left panel
--    3. Click the "Import" tab
--    4. Choose this file, keep Character set of the file = utf-8, press "Go"
--
--  The file is safe to import more than once: every object is created with
--  IF NOT EXISTS and every insert uses INSERT IGNORE, so nothing is duplicated
--  and no data is lost.
--
--  "At most one" rules are enforced by the database, not only by PHP. A UNIQUE
--  index treats every NULL as distinct, so a VIRTUAL generated column is used
--  that returns 1 for the row that currently holds the flag and NULL for all
--  the others. That allows any number of non-current rows while still making a
--  second current row impossible.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `school_result_portal`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `school_result_portal`;

-- ---------------------------------------------------------------------------
-- 1. ACADEMIC SESSIONS
--    Created first because terms and every assignment reference it.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `academic_sessions` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_name`    VARCHAR(20)  NOT NULL COMMENT 'e.g. 2026/2027',
    `start_date`      DATE         NOT NULL,
    `end_date`        DATE         NOT NULL,
    `status`          ENUM('Active', 'Closed', 'Inactive') NOT NULL DEFAULT 'Active',
    `is_current`      TINYINT(1)   NOT NULL DEFAULT 0,
    -- 1 while this session is current, NULL otherwise.
    `current_marker`  TINYINT(1) GENERATED ALWAYS AS
                          (IF(`is_current` = 1, 1, NULL)) VIRTUAL,
    `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_academic_sessions_name` (`session_name`),
    -- At most one current session in the whole portal.
    UNIQUE KEY `uq_academic_sessions_current` (`current_marker`),
    KEY `idx_academic_sessions_status` (`status`),
    KEY `idx_academic_sessions_dates` (`start_date`, `end_date`),
    CONSTRAINT `chk_academic_sessions_dates` CHECK (`end_date` > `start_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. TERMS
--    A term belongs to exactly one academic session. First Term, Second Term and
--    Third Term are the only names, and a name may appear once per session.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `terms` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id`      INT UNSIGNED NOT NULL,
    `term_name`       ENUM('First Term', 'Second Term', 'Third Term') NOT NULL,
    `start_date`      DATE         NOT NULL,
    `end_date`        DATE         NOT NULL,
    `status`          ENUM('Active', 'Closed', 'Inactive') NOT NULL DEFAULT 'Active',
    `is_current`      TINYINT(1)   NOT NULL DEFAULT 0,
    -- The session id while this term is current, NULL otherwise.
    `current_marker`  INT UNSIGNED GENERATED ALWAYS AS
                          (IF(`is_current` = 1, `session_id`, NULL)) VIRTUAL,
    `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- No duplicate term name inside one academic session.
    UNIQUE KEY `uq_terms_session_term` (`session_id`, `term_name`),
    -- At most one current term inside one academic session.
    UNIQUE KEY `uq_terms_session_current` (`current_marker`),
    KEY `idx_terms_status` (`status`),
    KEY `idx_terms_session_fk` (`session_id`),
    CONSTRAINT `fk_terms_session`
        FOREIGN KEY (`session_id`) REFERENCES `academic_sessions` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `chk_terms_dates` CHECK (`end_date` > `start_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. TEACHER CLASS ASSIGNMENTS
--    A teacher is made the class teacher of one class for one session.
--    Only one ACTIVE row may exist per teacher / class / session, while any
--    number of closed (Inactive) history rows are allowed.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `teacher_class_assignments` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `teacher_id`     INT UNSIGNED NOT NULL,
    `class_id`       INT UNSIGNED NOT NULL,
    `session_id`     INT UNSIGNED NOT NULL,
    `assigned_date`  DATE         NOT NULL,
    `status`         ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    `active_marker`  TINYINT(1) GENERATED ALWAYS AS
                         (IF(`status` = 'Active', 1, NULL)) VIRTUAL,
    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_teacher_class_active` (`teacher_id`, `class_id`, `session_id`, `active_marker`),
    KEY `idx_tca_session` (`session_id`),
    KEY `idx_tca_class` (`class_id`),
    KEY `idx_tca_status` (`status`),
    KEY `idx_tca_teacher_fk` (`teacher_id`),
    CONSTRAINT `fk_tca_teacher`
        FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_tca_class`
        FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_tca_session`
        FOREIGN KEY (`session_id`) REFERENCES `academic_sessions` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. TEACHER SUBJECT ASSIGNMENTS
--    Teacher -> Subject -> Class -> Session, the full teaching assignment.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `teacher_subject_assignments` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `teacher_id`     INT UNSIGNED NOT NULL,
    `subject_id`     INT UNSIGNED NOT NULL,
    `class_id`       INT UNSIGNED NOT NULL,
    `session_id`     INT UNSIGNED NOT NULL,
    `status`         ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    `active_marker`  TINYINT(1) GENERATED ALWAYS AS
                         (IF(`status` = 'Active', 1, NULL)) VIRTUAL,
    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_teacher_subject_active`
        (`teacher_id`, `subject_id`, `class_id`, `session_id`, `active_marker`),
    KEY `idx_tsa_session` (`session_id`),
    KEY `idx_tsa_class` (`class_id`),
    KEY `idx_tsa_subject` (`subject_id`),
    KEY `idx_tsa_status` (`status`),
    KEY `idx_tsa_teacher_fk` (`teacher_id`),
    CONSTRAINT `fk_tsa_teacher`
        FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_tsa_subject`
        FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_tsa_class`
        FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_tsa_session`
        FOREIGN KEY (`session_id`) REFERENCES `academic_sessions` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. STUDENT CLASS ENROLLMENTS
--    The academic record of which class a student belonged to in a given
--    session. A promotion adds a NEW row, so the previous session's record is
--    never overwritten.
--
--    `students.class_id` is intentionally NOT dropped: it remains the student's
--    current class and is kept in step with the newest active enrolment.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `student_class_enrollments` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `student_id`      INT UNSIGNED NOT NULL,
    `class_id`        INT UNSIGNED NOT NULL,
    `session_id`      INT UNSIGNED NOT NULL,
    `enrollment_date` DATE         NOT NULL,
    `status`          ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    `active_marker`   TINYINT(1) GENERATED ALWAYS AS
                          (IF(`status` = 'Active', 1, NULL)) VIRTUAL,
    `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- No student twice in the same academic session.
    UNIQUE KEY `uq_student_session_active` (`student_id`, `session_id`, `active_marker`),
    KEY `idx_sce_session` (`session_id`),
    KEY `idx_sce_class` (`class_id`),
    KEY `idx_sce_status` (`status`),
    KEY `idx_sce_student_fk` (`student_id`),
    CONSTRAINT `fk_sce_student`
        FOREIGN KEY (`student_id`) REFERENCES `students` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sce_class`
        FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sce_session`
        FOREIGN KEY (`session_id`) REFERENCES `academic_sessions` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 6. SUPPORTING OBJECTS
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `version`     VARCHAR(30)  NOT NULL,
    `applied_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INSERT IGNORE keeps this safe when the file is imported more than once.
INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('phase1');
INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('phase2');
INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('phase3');

-- ---------------------------------------------------------------------------
-- 7. FIRST ACADEMIC SESSION
--    Convenience only: the administrator can rename, edit or close it. It
--    already exists after a re-import because of INSERT IGNORE.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `academic_sessions`
    (`session_name`, `start_date`, `end_date`, `status`, `is_current`)
VALUES
    ('2026/2027', '2026-09-01', '2027-07-31', 'Active', 1);

-- ---------------------------------------------------------------------------
--  End of Phase 3 migration.
--  Phase 1 (users) and Phase 2 (students, teachers, classes, subjects) data is
--  untouched. Result entry, result calculation, grading, position, report
--  cards, the public result checker, attendance, psychomotor skills and
--  effective areas are NOT part of this migration.
-- ---------------------------------------------------------------------------
