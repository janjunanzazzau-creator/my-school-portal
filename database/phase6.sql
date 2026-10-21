-- ============================================================================
--  ORPHANS MODEL NURSERY AND PRIMARY SCHOOL (MAKARANTAR MARAYU)
--  Zazzau Orphans Initiative Foundation
--  School Result Management Portal - PHASE 6 MIGRATION
--  Public Result Checker and Result PIN
--
--  This migration ADDS to the Phase 1 to Phase 5 schema. It never drops,
--  truncates or renames an existing table, and the single column it adds to
--  `report_cards` is nullable, so every student, teacher, class, subject,
--  session, term, assignment, enrolment, result, report card, attendance row,
--  assessment and history row created earlier is preserved exactly as it was.
--
--  Import through phpMyAdmin:
--    1. Open  http://localhost/phpmyadmin
--    2. Select the database `school_result_portal` in the left panel
--    3. Click the "Import" tab
--    4. Choose this file, keep Character set of the file = utf-8, press "Go"
--
--  Or from a command prompt:
--    C:\xampp\mysql\bin\mysql.exe -u root --default-character-set=utf8mb4 ^
--        -e "source C:/xampp/htdocs/school-result-portal/database/phase6.sql"
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
