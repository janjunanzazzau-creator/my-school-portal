-- Consolidated database setup for School Student Result Management Portal
-- Requires MariaDB 10.4+ (the project migration syntax is MariaDB-compatible).
--
-- Fresh database setup:
--   1. Create an EMPTY database with utf8mb4 / utf8mb4_unicode_ci.
--   2. In phpMyAdmin, select that database, then import this file.
--      Or run: mariadb --default-character-set=utf8mb4 -u USER -p DATABASE_NAME < database/setup.sql
--
-- This applies phases 1 through 7 in order. Sample-data files are not included.
-- The script does not create or select a database, so it works with a hosted
-- database whose name differs from the local development database.
-- Tables and migration changes are additive. Re-importing preserves an existing
-- administrator password and customized school settings.
--
-- A new install seeds the default administrator: admin / Admin@12345.
-- Change that password immediately after the first login. Do not use the default
-- credentials for real school records.

SET NAMES utf8mb4;

-- ============================================================================
-- PHASE 1 — Foundation and authentication
-- Source: database/school_result_portal.sql
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. Users table
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
--    The value below is a bcrypt hash verified with password_verify() at login.
--    A re-import preserves an existing administrator password.
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
ON DUPLICATE KEY UPDATE `id` = `id`;

-- ---------------------------------------------------------------------------
--  End of Phase 1 schema.
--  Students, Teachers, Classes, Subjects, Sessions, Terms, Results,
--  Attendance and Report Cards are introduced in Phase 2.
-- ---------------------------------------------------------------------------

-- ============================================================================
-- PHASE 2 — School records
-- Source: database/phase2.sql
-- ============================================================================

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

-- ============================================================================
-- PHASE 3 — Academic structure
-- Source: database/phase3.sql
-- ============================================================================

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

-- ============================================================================
-- PHASE 4 — Results and assessments
-- Source: database/phase4.sql
-- ============================================================================

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

-- ============================================================================
-- PHASE 5 — Report cards
-- Source: database/phase5.sql
-- ============================================================================

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

-- ============================================================================
-- PHASE 6 — Public result checker
-- Source: database/phase6.sql
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. RESULT PINS
--
--    The secret a parent is given so they can read one student's result without
--    an account. Three rules shape this table:
--
--      a) `pin_hash` stores password_hash() output only. The PIN itself is never
--         written anywhere. The one time the school sees it is on screen, on the
--         page immediately after generation, and it cannot be recovered after
--         that - only replaced.
--
--      b) `pin_reference` is a NON SECRET, human readable reference such as
--         PIN-2026-000123. It is quoted on the phone to identify a PIN, printed
--         on the admin list, and stored in the access log, so an audit can be
--         traced without ever revealing the secret. It is never derived from the
--         PIN, so reading the reference tells you nothing about it.
--
--      c) `failed_attempts` and `max_attempts` live with the PIN rather than in
--         the session, because the counter has to survive the visitor closing
--         the browser. A PIN that reaches max_attempts is set to 'blocked' and
--         stops working until an administrator clears it.
--
--    `single_use` implements the school's choice. 0 (the default) means the PIN
--    keeps working until it expires, so a parent can come back as often as they
--    like; 1 means the first successful check retires it and the school has to
--    issue a new one. Only an administrator can change it, at issue time.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `result_pins` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- The PIN belongs to exactly one student in exactly one academic session.
    -- A PIN issued for one student can never open another student's result.
    `student_id`      INT UNSIGNED NOT NULL,
    `session_id`      INT UNSIGNED NOT NULL,

    -- password_hash() output. VARCHAR(255) is the widest a future algorithm
    -- could need. The column is named `pin_hash` so its purpose is obvious to
    -- anyone reading the table, and it is never selected for a public page.
    `pin_hash`        VARCHAR(255) NOT NULL COMMENT 'password_hash() output. The PIN is never stored.',

    -- Non secret, safe to display to staff and safe to write to the access log.
    `pin_reference`   VARCHAR(40)  NOT NULL COMMENT 'Public reference, e.g. PIN-2026-000123',

    `status`          ENUM('active', 'used', 'expired', 'blocked', 'revoked')
                      NOT NULL DEFAULT 'active',
    `single_use`      TINYINT UNSIGNED NOT NULL DEFAULT 0
                      COMMENT '0 = reusable until expiry, 1 = retired after one successful check',

    `expires_at`      DATETIME     NOT NULL,
    `max_attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `failed_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `last_used_at`    DATETIME     DEFAULT NULL,

    `created_by`      INT UNSIGNED DEFAULT NULL COMMENT 'users.id of the issuing administrator',

    `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    -- The reference is what the school reads out and what the log joins on, so
    -- it has to be unique. It is the backstop for the reference generator.
    UNIQUE KEY `uq_result_pin_reference` (`pin_reference`),

    -- The public checker looks a PIN up by student and session, and the admin
    -- list filters on the same pair, so the pair is the main access path.
    KEY `idx_result_pin_student_session` (`student_id`, `session_id`),
    KEY `idx_result_pin_session`  (`session_id`),
    KEY `idx_result_pin_status`   (`status`),
    KEY `idx_result_pin_expires`   (`expires_at`),

    CONSTRAINT `fk_result_pin_student` FOREIGN KEY (`student_id`)
        REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_result_pin_session` FOREIGN KEY (`session_id`)
        REFERENCES `academic_sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_result_pin_created_by` FOREIGN KEY (`created_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,

    -- A failed attempt counter can never pass its own ceiling.
    CONSTRAINT `chk_result_pin_attempts` CHECK (`failed_attempts` <= `max_attempts`),
    -- 0 = reusable until expiry, 1 = single use. Nothing else is meaningful.
    CONSTRAINT `chk_result_pin_single_use` CHECK (`single_use` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2. RESULT PUBLICATION
--
--    The switch between "the school has finished this class's result" and "the
--    public may read it". A report card being approved or locked is NOT on its
--    own enough: the administrator publishes a class and term deliberately, and
--    can withdraw it again in the same way.
--
--    One row per Class + Term + Session. Publishing is therefore idempotent:
--    pressing it twice leaves one row, not two.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `result_publication` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id`     INT UNSIGNED NOT NULL,
    `term_id`        INT UNSIGNED NOT NULL,
    `class_id`       INT UNSIGNED NOT NULL,

    `status`         ENUM('unpublished', 'published') NOT NULL DEFAULT 'unpublished',

    `published_at`   DATETIME     DEFAULT NULL,
    `published_by`   INT UNSIGNED DEFAULT NULL COMMENT 'users.id',
    `unpublished_at` DATETIME     DEFAULT NULL,
    `unpublished_by` INT UNSIGNED DEFAULT NULL COMMENT 'users.id',

    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    -- The public checker asks "is this class, term and session published?" once
    -- per request, and the admin screen lists by session and term.
    UNIQUE KEY `uq_result_publication_scope` (`session_id`, `term_id`, `class_id`),
    KEY `idx_result_publication_status` (`status`),
    KEY `idx_result_publication_session` (`session_id`),
    KEY `idx_result_publication_term` (`term_id`),
    KEY `idx_result_publication_class` (`class_id`),

    CONSTRAINT `fk_result_publication_session` FOREIGN KEY (`session_id`)
        REFERENCES `academic_sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_result_publication_term` FOREIGN KEY (`term_id`)
        REFERENCES `terms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_result_publication_class` FOREIGN KEY (`class_id`)
        REFERENCES `classes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_result_publication_published_by` FOREIGN KEY (`published_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_result_publication_unpublished_by` FOREIGN KEY (`unpublished_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3. RESULT ACCESS LOG
--
--    Every single attempt at the public result checker, successful or not, and
--    whether it reached a PIN or never got that far.
--
--    What is deliberately NOT here: the PIN itself, not even hashed. A visitor
--    who forgets their PIN has to be issued a new one, because a log that could
--    confirm a guess would be a list of every PIN the school has ever handed
--    out. `pin_id` points at `result_pins`, and `failure_reason` is a fixed set
--    of machine codes rather than free text, so nothing a visitor typed can end
--    up stored here.
--
--    pin_id, student_id, session_id and term_id are all nullable on purpose: a
--    request for a student who does not exist has no PIN and no student to
--    point at, and that attempt still has to be counted.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `result_access_logs` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,

    `pin_id`         INT UNSIGNED DEFAULT NULL,
    `student_id`     INT UNSIGNED DEFAULT NULL,
    `session_id`     INT UNSIGNED DEFAULT NULL,
    `term_id`        INT UNSIGNED DEFAULT NULL,

    `ip_address`     VARCHAR(45)  NOT NULL COMMENT 'clientIpAddress(), truncated to 45',
    `user_agent`     VARCHAR(255) DEFAULT NULL,
    `success`        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `failure_reason` VARCHAR(40)  DEFAULT NULL
                     COMMENT 'Fixed machine code. Never visitor input.',

    `accessed_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),

    -- The admin dashboard counts the recent attempts by IP, and the throttling
    -- rule counts failures per IP inside a time window, so this index is the
    -- one that matters on a public page.
    KEY `idx_raccess_ip_time` (`ip_address`, `accessed_at`),
    KEY `idx_raccess_success` (`success`, `accessed_at`),
    KEY `idx_raccess_pin`   (`pin_id`),
    KEY `idx_raccess_student` (`student_id`),
    KEY `idx_raccess_session` (`session_id`),
    KEY `idx_raccess_term`  (`term_id`),
    KEY `idx_raccess_time`  (`accessed_at`),

    CONSTRAINT `fk_raccess_pin` FOREIGN KEY (`pin_id`)
        REFERENCES `result_pins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_raccess_student` FOREIGN KEY (`student_id`)
        REFERENCES `students` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_raccess_session` FOREIGN KEY (`session_id`)
        REFERENCES `academic_sessions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_raccess_term` FOREIGN KEY (`term_id`)
        REFERENCES `terms` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,

    CONSTRAINT `chk_raccess_success` CHECK (`success` IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 4. VERIFICATION CODE ON REPORT CARDS
--
--    `verification_code` is the public identity of a published report, in the
--    style ORM/RES/2026/000123. It is NOT a secret and NOT the result PIN: it
--    only ever appears on a result the visitor has already been allowed to see,
--    so it can be printed on the card and quoted by the school without granting
--    anybody anything.
--
--    It is nullable and UNIQUE. Nullable so that the column can be added to a
--    table that already holds thousands of rows without a default being invented
--    for them, and unique so one code can only ever identify one report card.
--    MariaDB allows any number of NULLs in a unique index, which is what makes
--    the backfill loop safe to run repeatedly.
-- ---------------------------------------------------------------------------
ALTER TABLE `report_cards`
    ADD COLUMN IF NOT EXISTS `verification_code` VARCHAR(40) DEFAULT NULL
        COMMENT 'Public verification reference, e.g. ORM/RES/2026/000123. Not a secret.';

ALTER TABLE `report_cards`
    ADD UNIQUE INDEX IF NOT EXISTS `uq_report_verification_code` (`verification_code`);

-- ---------------------------------------------------------------------------
-- 5. MIGRATION MARKER
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('phase6');

-- ---------------------------------------------------------------------------
--  End of Phase 6 migration.
--
--  Added: `result_pins`, `result_publication`, `result_access_logs`, and one
--         nullable `verification_code` column on `report_cards`.
--  Untouched: every Phase 1 to Phase 5 table and all of its data.
--
--  Not part of this migration: any PIN, publication or access log record. Those
--  are real data with real consequences, so they are created through the admin
--  screens. `database/sample_data_phase6.sql` holds a clearly marked test set
--  for the automated suites and is never imported automatically.
-- ---------------------------------------------------------------------------

-- ============================================================================
-- PHASE 7 — School settings
-- Source: database/phase7.sql
-- ============================================================================

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

-- Insert initial school settings; re-imports preserve administrator edits.
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
) ON DUPLICATE KEY UPDATE `id` = `id`;

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
