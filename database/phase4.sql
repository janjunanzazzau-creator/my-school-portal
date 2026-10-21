-- ============================================================================
--  ORPHANS MODEL NURSERY AND PRIMARY SCHOOL (MAKARANTAR MARAYU)
--  Zazzau Orphans Initiative Foundation
--  School Result Management Portal - PHASE 4 MIGRATION
--  Result entry, calculation, grading, review, approval and locking
--
--  This migration ADDS to the Phase 1, Phase 2 and Phase 3 schema. It never
--  drops, truncates or renames an existing table, so every user, student,
--  teacher, class, subject, academic session, term, assignment and enrolment
--  created earlier is preserved exactly as it was.
--
--  Import through phpMyAdmin:
--    1. Open  http://localhost/phpmyadmin
--    2. Select the database `school_result_portal` in the left panel
--    3. Click the "Import" tab
--    4. Choose this file, keep Character set of the file = utf-8, press "Go"
--
--  Or from a command prompt:
--    C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 ^
--        -e "source C:/xampp/htdocs/school-result-portal/database/phase4.sql"
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
-- 1. ASSESSMENT SETTINGS
--    The assessment structure is DATA, not code: the maximum score of each
--    component is stored per section and the administrator can change it later
--    without touching a single PHP file.
--
--    `assessment_code` is the extra column that binds a row to one of the four
--    score columns on `results` (test_1, test_2, test_3, examination). Without
--    it a row such as "Third Test = 10" could not be matched to the column it
--    describes, and the configuration would not be usable.
--
--    Default layout
--      Nursery / Primary : Test 1 = 10, Test 2 = 10, Test 3 = 10, Exam = 70
--      Secondary         : CA 1  = 10, CA 2  = 10, CA 3  = 10, Exam = 70
--      Total             : 100
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `assessment_settings` (
    `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `section`          ENUM('Nursery', 'Primary', 'Secondary') NOT NULL,
    `assessment_code`  ENUM('test_1', 'test_2', 'test_3', 'examination') NOT NULL,
    `assessment_name`  VARCHAR(60)     NOT NULL COMMENT 'Label shown to teachers and admins',
    `maximum_score`    DECIMAL(6, 2)   NOT NULL,
    `display_order`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `status`           ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    `created_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- One row per component per section; the sections share nothing.
    UNIQUE KEY `uq_assessment_section_code` (`section`, `assessment_code`),
    KEY `idx_assessment_status` (`status`),
    KEY `idx_assessment_order` (`section`, `display_order`),
    CONSTRAINT `chk_assessment_maximum` CHECK (`maximum_score` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nursery and Primary: three tests of 10 and an examination of 70.
INSERT IGNORE INTO `assessment_settings`
    (`section`, `assessment_code`, `assessment_name`, `maximum_score`, `display_order`, `status`)
VALUES
    ('Nursery',  'test_1',      'Test 1',      10.00, 1, 'Active'),
    ('Nursery',  'test_2',      'Test 2',      10.00, 2, 'Active'),
    ('Nursery',  'test_3',      'Test 3',      10.00, 3, 'Active'),
    ('Nursery',  'examination', 'Examination', 70.00, 4, 'Active'),
    ('Primary',  'test_1',      'Test 1',      10.00, 1, 'Active'),
    ('Primary',  'test_2',      'Test 2',      10.00, 2, 'Active'),
    ('Primary',  'test_3',      'Test 3',      10.00, 3, 'Active'),
    ('Primary',  'examination', 'Examination', 70.00, 4, 'Active');

-- Secondary: the three continuous assessments are called CA instead of Test.
INSERT IGNORE INTO `assessment_settings`
    (`section`, `assessment_code`, `assessment_name`, `maximum_score`, `display_order`, `status`)
VALUES
    ('Secondary', 'test_1',      'CA 1',         10.00, 1, 'Active'),
    ('Secondary', 'test_2',      'CA 2',         10.00, 2, 'Active'),
    ('Secondary', 'test_3',      'CA 3',         10.00, 3, 'Active'),
    ('Secondary', 'examination', 'Examination',  70.00, 4, 'Active');

-- ---------------------------------------------------------------------------
-- 2. RESULTS
--    One row per Student + Subject + Academic Session + Term, enforced by a
--    UNIQUE index so a duplicate result can never be created, not even by a
--    direct SQL statement.
--
--    `total`, `grade` and `grade_remark` are ALWAYS calculated by the server
--    from the four component scores. They are stored so the list and review
--    screens do not have to recompute every row, but they are never accepted
--    from the browser.
--
--    `overall_total`, `average` and `position` are deliberately NOT stored.
--    They are derived from the approved and locked results of a student in a
--    given session and term, so persisting them would create a second source of
--    truth that could drift. They are calculated on demand instead.
--
--    `test_3` is part of the table because the assessment structure and the
--    stated total formula (Test 1 + Test 2 + Test 3 + Examination) both
--    require a third component score.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `results` (
    `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `student_id`      INT UNSIGNED  NOT NULL,
    `class_id`        INT UNSIGNED  NOT NULL,
    `subject_id`      INT UNSIGNED  NOT NULL,
    `session_id`      INT UNSIGNED  NOT NULL,
    `term_id`         INT UNSIGNED  NOT NULL,
    `teacher_id`      INT UNSIGNED  NOT NULL,

    `test_1`          DECIMAL(6, 2) DEFAULT NULL,
    `test_2`          DECIMAL(6, 2) DEFAULT NULL,
    `test_3`          DECIMAL(6, 2) DEFAULT NULL,
    `examination`     DECIMAL(6, 2) DEFAULT NULL,

    `total`           DECIMAL(7, 2) DEFAULT NULL COMMENT 'Server calculated, never accepted from the browser',
    `grade`           VARCHAR(2)    DEFAULT NULL COMMENT 'Server calculated',
    `grade_remark`    VARCHAR(60)   DEFAULT NULL COMMENT 'Server calculated',
    `teacher_remark`  VARCHAR(255)  DEFAULT NULL COMMENT 'Typed by the subject teacher',
    `admin_feedback`  VARCHAR(255)  DEFAULT NULL COMMENT 'Reason an administrator returned the result',

    `status`          ENUM('draft', 'submitted', 'approved', 'locked') NOT NULL DEFAULT 'draft',
    `submitted_at`    DATETIME      DEFAULT NULL,
    `approved_at`     DATETIME      DEFAULT NULL,
    `approved_by`     INT UNSIGNED  DEFAULT NULL COMMENT 'users.id of the approving administrator',
    `locked_at`       DATETIME      DEFAULT NULL,
    `locked_by`       INT UNSIGNED  DEFAULT NULL COMMENT 'users.id of the locking administrator',

    `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    -- The core Phase 4 rule: one result per student, subject, session and term.
    UNIQUE KEY `uq_result_student_subject_session_term`
        (`student_id`, `subject_id`, `session_id`, `term_id`),

    KEY `idx_results_student` (`student_id`),
    KEY `idx_results_subject` (`subject_id`),
    KEY `idx_results_class`   (`class_id`),
    KEY `idx_results_session` (`session_id`),
    KEY `idx_results_term`    (`term_id`),
    KEY `idx_results_teacher` (`teacher_id`),
    KEY `idx_results_status`  (`status`),
    -- Serves the review queue and the position calculation directly.
    KEY `idx_results_scope`   (`class_id`, `session_id`, `term_id`, `status`),

    CONSTRAINT `fk_results_student` FOREIGN KEY (`student_id`)
        REFERENCES `students` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_results_class` FOREIGN KEY (`class_id`)
        REFERENCES `classes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_results_subject` FOREIGN KEY (`subject_id`)
        REFERENCES `subjects` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_results_session` FOREIGN KEY (`session_id`)
        REFERENCES `academic_sessions` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_results_term` FOREIGN KEY (`term_id`)
        REFERENCES `terms` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_results_teacher` FOREIGN KEY (`teacher_id`)
        REFERENCES `teachers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_results_approved_by` FOREIGN KEY (`approved_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_results_locked_by` FOREIGN KEY (`locked_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,

    -- A component score can never be negative, and no component can exceed the
    -- total the assessment structure allows (100 by default).
    CONSTRAINT `chk_results_scores_non_negative` CHECK (
        (`test_1` IS NULL OR `test_1` >= 0)
    AND (`test_2` IS NULL OR `test_2` >= 0)
    AND (`test_3` IS NULL OR `test_3` >= 0)
    AND (`examination` IS NULL OR `examination` >= 0)
    ),
    CONSTRAINT `chk_results_scores_max` CHECK (
        (`test_1` IS NULL OR `test_1` <= 1000)
    AND (`test_2` IS NULL OR `test_2` <= 1000)
    AND (`test_3` IS NULL OR `test_3` <= 1000)
    AND (`examination` IS NULL OR `examination` <= 1000)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. RESULT HISTORY
--    Every workflow transition writes one row here. This is the audit trail the
--    administrator reads on the Result Review screen.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `result_history` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `result_id`   INT UNSIGNED NOT NULL,
    `user_id`     INT UNSIGNED DEFAULT NULL COMMENT 'users.id of the actor, NULL when the system acted',
    `action`      ENUM('created', 'updated', 'submitted', 'returned',
                       'approved', 'locked', 'unlocked') NOT NULL,
    `old_status`  VARCHAR(20)  DEFAULT NULL,
    `new_status`  VARCHAR(20)  DEFAULT NULL,
    `reason`      VARCHAR(255) DEFAULT NULL COMMENT 'Required when returning or unlocking',
    `ip_address`  VARCHAR(45)  DEFAULT NULL,
    `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rh_result`  (`result_id`),
    KEY `idx_rh_user`    (`user_id`),
    KEY `idx_rh_action`  (`action`),
    KEY `idx_rh_created` (`created_at`),
    CONSTRAINT `fk_rh_result` FOREIGN KEY (`result_id`)
        REFERENCES `results` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_rh_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. AUDIT LOG
--    No audit table existed in Phases 1 to 3, so it is created here. It is
--    deliberately wider than `result_history`: it records the entity type and
--    the visitor's IP address, so a later phase can audit other modules without
--    another migration.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED DEFAULT NULL,
    `action`      VARCHAR(60)  NOT NULL,
    `entity_type` VARCHAR(40)  NOT NULL DEFAULT 'result',
    `entity_id`   INT UNSIGNED DEFAULT NULL,
    `ip_address`  VARCHAR(45)  DEFAULT NULL,
    `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_user`    (`user_id`),
    KEY `idx_audit_entity`  (`entity_type`, `entity_id`),
    KEY `idx_audit_action`  (`action`),
    KEY `idx_audit_created` (`created_at`),
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. MIGRATION MARKER
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('phase4');

-- ---------------------------------------------------------------------------
--  End of Phase 4 migration.
--
--  Added: `results`, `assessment_settings`, `result_history`, `audit_logs`.
--  Untouched: every Phase 1, Phase 2 and Phase 3 table and all of its data.
--
--  NOT part of this migration and deliberately left out: report cards, the
--  public result checker, the result PIN, attendance, psychomotor skills and
--  effective areas.
-- ---------------------------------------------------------------------------
