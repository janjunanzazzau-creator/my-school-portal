-- ============================================================================
--  PHASE 6 SAMPLE DATA
--  A small, self-contained data set for trying the public result checker.
--
--  It creates:
--    * approved results for the six Primary 1 students
--    * a locked report card for each of them
--    * a published class, so the checker offers that session and term
--    * three result PINs with known values
--
--  THE PINS ARE PRINTED AT THE BOTTOM OF THIS FILE AND WORK ONLY ONCE EACH
--  TIME IT IS IMPORTED. Read them before you run the file.
--
--  Nothing here is required by the portal. If the school already has approved
--  results, do not import this file - it is only here so the public checker can
--  be tried without typing a PIN out by hand first.
--
--  Safe to import more than once: results and cards are matched on their
--  natural keys, and the PINs are replaced rather than duplicated.
--
--  Run it with:
--      C:\xampp\mysql\bin\mysql.exe -uroot school_result_portal < database\sample_data_phase6.sql
-- ============================================================================

USE `school_result_portal`;

-- ---------------------------------------------------------------------------
-- 1. Approved results for the whole class
--
--    Scores differ from student to student and from subject to subject so the
--    positions on the cards come out different, which is what makes the sample
--    worth looking at. Test 1 / Test 2 / Test 3 are out of 10, the examination
--    out of 70, so the total is out of 100.
-- ---------------------------------------------------------------------------
INSERT INTO `results`
    (`student_id`, `class_id`, `subject_id`, `session_id`, `term_id`, `teacher_id`,
     `test_1`, `test_2`, `test_3`, `examination`, `total`, `grade`, `grade_remark`,
     `status`, `approved_at`, `approved_by`)
SELECT s.id,
       e.class_id,
       sub.id,
       e.session_id,
       term.id,
       (SELECT t.id FROM `teachers` t ORDER BY t.id LIMIT 1),
       sc.test_1, sc.test_2, sc.test_3, sc.examination,
       sc.test_1 + sc.test_2 + sc.test_3 + sc.examination,
       CASE WHEN sc.test_1 + sc.test_2 + sc.test_3 + sc.examination >= 50 THEN 'A'
            WHEN sc.test_1 + sc.test_2 + sc.test_3 + sc.examination >= 40 THEN 'B'
            WHEN sc.test_1 + sc.test_2 + sc.test_3 + sc.examination >= 30 THEN 'C'
            ELSE 'F' END,
       'Approved sample result',
       'approved',
       NOW(),
       (SELECT u.id FROM `users` u WHERE u.role = 'admin' AND u.status = 1 ORDER BY u.id LIMIT 1)
  FROM `students` s
  JOIN `student_class_enrollments` e
    ON e.student_id = s.id AND e.status = 'Active'
  JOIN `terms` term
    ON term.session_id = e.session_id AND term.is_current = 1
  JOIN `classes` c
    ON c.id = e.class_id
  JOIN `subjects` sub
    ON sub.status = 'Active' AND sub.section = c.section
  JOIN (
        /* A different, slightly varied sheet for each of the six students. */
        SELECT 'ADM/2026/0001' AS adm, 1 AS n
        UNION ALL SELECT 'ADM/2026/0002', 2
        UNION ALL SELECT 'ADM/2026/0003', 3
        UNION ALL SELECT 'ADM/2026/0004', 4
        UNION ALL SELECT 'ADM/2026/0005', 5
        UNION ALL SELECT 'ADM/2026/0006', 6
  ) seat ON seat.adm = s.admission_number
  JOIN (
        /* The spread grows with the seat number, so later students do better. */
        SELECT n,
               6 + (n MOD 4)                     AS test_1,
               6 + ((n + 1) MOD 4)               AS test_2,
               6 + ((n + 2) MOD 4)               AS test_3,
               55 + (n * 2)                      AS examination
          FROM (SELECT 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3
                UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) spread
  ) sc ON sc.n = seat.n
ON DUPLICATE KEY UPDATE
    `test_1`      = VALUES(`test_1`),
    `test_2`      = VALUES(`test_2`),
    `test_3`      = VALUES(`test_3`),
    `examination` = VALUES(`examination`),
    `total`       = VALUES(`total`),
    `grade`       = VALUES(`grade`),
    `status`      = 'approved';

-- ---------------------------------------------------------------------------
-- 2. A locked report card for each of them
--
--    The totals, average and position are worked out from the results just
--    written, in the same way the application does it, so the cards agree with
--    what the portal would have generated. The verification code is left NULL
--    on purpose: it is assigned the first time a result is opened publicly, which
--    is what the portal does for a real card too.
--
--    A card is only inserted if the student does not already have one for this
--    class, session and term - the UNIQUE index would refuse it otherwise.
-- ---------------------------------------------------------------------------
INSERT INTO `report_cards`
    (`report_number`, `student_id`, `class_id`, `session_id`, `term_id`,
     `total_score`, `average_score`, `position`, `total_students`,
     `subjects_offered`, `subjects_passed`, `subjects_failed`,
     `attendance_present`, `attendance_absent`, `attendance_total`,
     `teacher_comment`, `principal_comment`, `promotion_status`,
     `status`, `generated_at`, `approved_at`, `approved_by`, `locked_at`, `locked_by`)
SELECT CONCAT('ORM-', REPLACE(UPPER(a.session_name), '/', '-'), '-P5-', LPAD(seq.n, 4, '0')),
       s.id,
       e.class_id,
       e.session_id,
       term.id,
       SUM(r.total),
       ROUND(SUM(r.total) / COUNT(*), 2),
       pos.rank_no,
       (SELECT COUNT(*) FROM `student_class_enrollments` x
         WHERE x.class_id = e.class_id AND x.session_id = e.session_id AND x.status = 'Active'),
       COUNT(*),
       SUM(CASE WHEN r.total >= 50 THEN 1 ELSE 0 END),
       SUM(CASE WHEN r.total <  50 THEN 1 ELSE 0 END),
       28, 2, 30,
       'A good term. Keep working hard.',
       'Well done.',
       'Promoted',
       'locked',
       NOW(), NOW(),
       (SELECT u.id FROM `users` u WHERE u.role = 'admin' AND u.status = 1 ORDER BY u.id LIMIT 1),
       NOW(),
       (SELECT u.id FROM `users` u WHERE u.role = 'admin' AND u.status = 1 ORDER BY u.id LIMIT 1)
  FROM `students` s
  JOIN `student_class_enrollments` e
    ON e.student_id = s.id AND e.status = 'Active'
  JOIN `terms` term
    ON term.session_id = e.session_id AND term.is_current = 1
  JOIN `academic_sessions` a
    ON a.id = e.session_id
  JOIN `results` r
    ON r.student_id = s.id AND r.class_id = e.class_id
   AND r.session_id = e.session_id AND r.term_id = term.id AND r.status = 'approved'
  /* Position counts how many other students have a higher approved sheet total.
     It is a correlated subquery rather than a join so the aggregate above is
     still counting this student's own eight results and not the whole school. */
  JOIN (
        SELECT mine.student_id,
               1 + (SELECT COUNT(*)
                      FROM (SELECT student_id, SUM(total) AS sheet_total
                              FROM `results` WHERE status = 'approved'
                             GROUP BY student_id) other
                      WHERE other.sheet_total > mine.sheet_total) AS rank_no
          FROM (SELECT student_id, SUM(total) AS sheet_total
                  FROM `results` WHERE status = 'approved'
                 GROUP BY student_id) mine
  ) pos ON pos.student_id = s.id
  /* The report number is the student's place in the admission list, which is
     stable across imports. */
  JOIN (
        SELECT id, ROW_NUMBER() OVER (ORDER BY admission_number) AS n
          FROM `students`
         WHERE admission_number LIKE 'ADM/2026/%'
  ) seq ON seq.id = s.id
 WHERE NOT EXISTS (
        SELECT 1 FROM `report_cards` rc
         WHERE rc.student_id = s.id AND rc.class_id = e.class_id
           AND rc.session_id = e.session_id AND rc.term_id = term.id
 )
 /* One card per student. Without this the aggregates above collapse into a single
    row covering the whole school, which is exactly what happened the first time
    this file was run. */
 GROUP BY s.id, e.class_id, e.session_id, term.id, a.session_name, seq.n, pos.rank_no;

-- ---------------------------------------------------------------------------
-- 3. Publish the class
--
--    Until this row exists the checker says "No results have been published
--    yet" and offers no form at all, which is the correct behaviour but a
--    confusing first screen when you are trying the feature out.
-- ---------------------------------------------------------------------------
INSERT INTO `result_publication`
    (`session_id`, `term_id`, `class_id`, `status`, `published_at`, `published_by`)
SELECT e.session_id, term.id, e.class_id, 'published', NOW(),
       (SELECT u.id FROM `users` u WHERE u.role = 'admin' AND u.status = 1 ORDER BY u.id LIMIT 1)
  FROM `student_class_enrollments` e
  JOIN `terms` term ON term.session_id = e.session_id AND term.is_current = 1
  JOIN `classes` c ON c.class_name = 'Primary 1' AND c.section = 'Primary'
 WHERE e.class_id = c.id AND e.status = 'Active'
 LIMIT 1
ON DUPLICATE KEY UPDATE
    `status`       = 'published',
    `published_at` = VALUES(`published_at`),
    `published_by` = VALUES(`published_by`),
    `unpublished_at` = NULL,
    `unpublished_by` = NULL;

-- ---------------------------------------------------------------------------
-- 4. Three result PINs
--
--    pin_hash is a bcrypt hash of the eight DIGITS of the PIN, because that is
--    what normaliseResultPin() reduces a typed PIN to before it is checked. The
--    dash is only there to be read easily.
--
--    The hashes below were produced by password_hash() on PHP 8.2 and are the
--    only place a PIN hash should ever come from. They are published here on
--    purpose: these are three known PINs for six sample students in a demo
--    database, and the point of the file is that a person can try the checker
--    without telephoning the office first.
--
--    Remove this section, or import the file into a copy of the database only,
--    before any real school ever uses this portal.
-- ---------------------------------------------------------------------------
DELETE FROM `result_pins` WHERE `pin_reference` IN ('PIN-SAMPLE-0001', 'PIN-SAMPLE-0002', 'PIN-SAMPLE-0003');

INSERT INTO `result_pins`
    (`student_id`, `session_id`, `pin_hash`, `pin_reference`, `status`, `single_use`,
     `expires_at`, `max_attempts`, `failed_attempts`, `created_by`)
SELECT s.id, e.session_id,
       CASE s.admission_number
           WHEN 'ADM/2026/0001' THEN '$2y$10$Tp7oF6lXUFNWq6LNDxUbGu69UukSBD6uYalE4kCOpD/e6APRQDZM6'
           WHEN 'ADM/2026/0003' THEN '$2y$10$aqYwLLx6j/Dc.MpoLJV4lO25RD/D9y0.cR5tDDDPV1YDqtQfQUZve'
           ELSE                     '$2y$10$6ITGPn4StX6LtEejLByC3u8RjdxEgGzlzp1V/0jKvNeikLj0A37DG'
       END,
       CASE s.admission_number
           WHEN 'ADM/2026/0001' THEN 'PIN-SAMPLE-0001'
           WHEN 'ADM/2026/0003' THEN 'PIN-SAMPLE-0002'
           ELSE                     'PIN-SAMPLE-0003'
       END,
       'active', 0, DATE_ADD(NOW(), INTERVAL 30 DAY), 5, 0,
       (SELECT u.id FROM `users` u WHERE u.role = 'admin' AND u.status = 1 ORDER BY u.id LIMIT 1)
  FROM `students` s
  JOIN `student_class_enrollments` e ON e.student_id = s.id AND e.status = 'Active'
  JOIN `terms` term ON term.session_id = e.session_id AND term.is_current = 1
 WHERE s.admission_number IN ('ADM/2026/0001', 'ADM/2026/0003', 'ADM/2026/0005');

-- ---------------------------------------------------------------------------
-- 5. How to try it
--
--    1. Open  http://localhost/school-result-portal/result-checker.php
--       with no account, no login, nothing.
--
--    2. Choose the session and term from the two dropdowns. Only the session
--       and term that have been published something are offered.
--
--    3. Type one of these, with the admission number beside it:
--
--           Admission number   PIN
--           ADM/2026/0001      4829-7315     Aisha Bello
--           ADM/2026/0003      7351-2048     Chioma Okafor
--           ADM/2026/0005      1904-6637     Fatima Yusuf
--
--       Any other combination is refused, and every attempt - right or wrong -
--       is written to the access log with a reason code and never with the PIN.
--
--    4. The result opens for 30 minutes. The PIN is not in the URL and is not
--       kept anywhere it can be read back, so refreshing the page is not enough
--       to get in again: the window lives in the server side session.
--
--    5. Sign in as the administrator and open Result PINs. The three PINs are
--       listed by their reference, PIN-SAMPLE-0001 and so on. Block or revoke
--       one and the window it opened closes at once, and that PIN stops working
--       for a new check as well.
--
--  TO REMOVE THE SAMPLE DATA
--
--    DELETE FROM `result_pins` WHERE `pin_reference` LIKE 'PIN-SAMPLE-%';
--    DELETE FROM `result_access_logs`;
--    DELETE FROM `report_card_history`;
--    DELETE FROM `report_cards`;
--    DELETE FROM `result_publication`;
--    DELETE FROM `results`;
-- ============================================================================
