-- ============================================================================
--  PHASE 4 SAMPLE DATA
--  A small, self-contained data set for trying the result workflow.
--
--  It creates:
--    * a First Term for the current academic session
--    * six students in Primary 1
--    * a teacher with a portal login
--    * the class and subject allocation that lets that teacher enter results
--    * the enrollments that put the students in Primary 1
--
--  Nothing here is required by the portal. If the school already has a term,
--  students, teachers and assignments, simply do not import this file.
--
--  Safe to import more than once: the students, the teacher and the term are
--  matched on their natural keys, and the allocation and the enrollments use
--  INSERT IGNORE against their own unique indexes.
-- ============================================================================

USE `school_result_portal`;

-- ---------------------------------------------------------------------------
-- 1. A term to enter results into
-- ---------------------------------------------------------------------------
INSERT INTO `terms` (`session_id`, `term_name`, `start_date`, `end_date`, `status`, `is_current`)
SELECT a.id, 'First Term', '2026-09-01', '2026-12-18', 'Active', 1
  FROM `academic_sessions` a
 WHERE a.is_current = 1
   AND NOT EXISTS (
       SELECT 1 FROM `terms` t
        WHERE t.session_id = a.id AND t.term_name = 'First Term'
   )
LIMIT 1;

-- ---------------------------------------------------------------------------
-- 2. Six students in Primary 1
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `students`
    (`admission_number`, `first_name`, `middle_name`, `last_name`, `gender`,
     `section`, `class_id`, `admission_date`, `status`)
VALUES
    ('ADM/2026/0001', 'Aisha',    NULL, 'Bello',      'Female', 'Primary', (SELECT id FROM `classes` WHERE class_name = 'Primary 1' AND section = 'Primary'), '2026-09-01', 'Active'),
    ('ADM/2026/0002', 'Ibrahim',  NULL, 'Musa',       'Male',   'Primary', (SELECT id FROM `classes` WHERE class_name = 'Primary 1' AND section = 'Primary'), '2026-09-01', 'Active'),
    ('ADM/2026/0003', 'Chioma',   NULL, 'Okafor',     'Female', 'Primary', (SELECT id FROM `classes` WHERE class_name = 'Primary 1' AND section = 'Primary'), '2026-09-01', 'Active'),
    ('ADM/2026/0004', 'Yusuf',    NULL, 'Abdullahi',  'Male',   'Primary', (SELECT id FROM `classes` WHERE class_name = 'Primary 1' AND section = 'Primary'), '2026-09-01', 'Active'),
    ('ADM/2026/0005', 'Fatima',   NULL, 'Yusuf',      'Female', 'Primary', (SELECT id FROM `classes` WHERE class_name = 'Primary 1' AND section = 'Primary'), '2026-09-01', 'Active'),
    ('ADM/2026/0006', 'Musa',     NULL, 'Ibrahim',    'Male',   'Primary', (SELECT id FROM `classes` WHERE class_name = 'Primary 1' AND section = 'Primary'), '2026-09-01', 'Active');

-- ---------------------------------------------------------------------------
-- 3. A teacher with a portal login
--
--    username : p4teacher
--    password : Teacher@12345
--
--    This is a SAMPLE account for trying the workflow. The password hash below
--    was produced by password_hash() and is the only place a hash should ever
--    come from. Delete or reset this account before the portal is used by a real
--    school.
-- ---------------------------------------------------------------------------
INSERT INTO `users` (`username`, `password`, `full_name`, `email`, `role`, `status`)
SELECT 'p4teacher',
       '$2y$10$w1SOkhbMy7rhnSIDOrMGe.CZ8D4SFjy.xMHnN8.ghykJn86tBg2b.',
       'Amina Sani',
       'p4teacher@example.com',
       'teacher',
       1
 WHERE NOT EXISTS (SELECT 1 FROM `users` WHERE username = 'p4teacher');

INSERT IGNORE INTO `teachers`
    (`staff_number`, `first_name`, `middle_name`, `last_name`, `gender`,
     `phone`, `email`, `qualification`, `department`, `user_id`, `status`)
VALUES
    ('STF/P4/0001', 'Amina', NULL, 'Sani', 'Female', '08030000001',
     'p4teacher@example.com', 'B.Sc Education', 'Primary', NULL, 'Active');

-- Link the teacher record to the login account that was just created.
UPDATE `teachers` t
  JOIN `users` u ON u.username = 'p4teacher'
   SET t.user_id = u.id
 WHERE t.staff_number = 'STF/P4/0001'
   AND (t.user_id IS NULL OR t.user_id <> u.id);

-- ---------------------------------------------------------------------------
-- 4. The allocation that gives the teacher the right to enter results
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `teacher_class_assignments` (`teacher_id`, `class_id`, `session_id`, `assigned_date`, `status`)
SELECT t.id, c.id, a.id, '2026-09-01', 'Active'
  FROM `teachers` t
  JOIN `classes` c ON c.class_name = 'Primary 1' AND c.section = 'Primary'
  JOIN `academic_sessions` a ON a.is_current = 1
 WHERE t.staff_number = 'STF/P4/0001';

INSERT IGNORE INTO `teacher_subject_assignments` (`teacher_id`, `subject_id`, `class_id`, `session_id`, `status`)
SELECT t.id, s.id, c.id, a.id, 'Active'
  FROM `teachers` t
  JOIN `subjects` s ON s.subject_code = 'ENG'
  JOIN `classes` c ON c.class_name = 'Primary 1' AND c.section = 'Primary'
  JOIN `academic_sessions` a ON a.is_current = 1
 WHERE t.staff_number = 'STF/P4/0001';

-- ---------------------------------------------------------------------------
-- 5. Enrollments, which are what the result sheet roster is built from
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `student_class_enrollments` (`student_id`, `class_id`, `session_id`, `enrollment_date`, `status`)
SELECT s.id, c.id, a.id, '2026-09-01', 'Active'
  FROM `students` s
  JOIN `classes` c ON c.class_name = 'Primary 1' AND c.section = 'Primary'
  JOIN `academic_sessions` a ON a.is_current = 1
 WHERE s.admission_number IN ('ADM/2026/0001', 'ADM/2026/0002', 'ADM/2026/0003',
                              'ADM/2026/0004', 'ADM/2026/0005', 'ADM/2026/0006');

-- Keep the convenience link on students.class_id in step with the enrollment.
UPDATE `students` s
  JOIN `student_class_enrollments` e ON e.student_id = s.id AND e.status = 'Active'
  JOIN `classes` c ON c.id = e.class_id
   SET s.class_id = c.id
 WHERE s.admission_number LIKE 'ADM/2026/%';

-- ---------------------------------------------------------------------------
-- 6. How to try the workflow
--
--    1. Sign in as the administrator and open Results.
--       The page should be empty until a teacher submits something.
--
--    2. Sign in as the teacher, open Enter Results, choose
--       Primary 1 + English + First Term, and type four scores per student.
--       Test 1 / Test 2 / Test 3 are out of 10, the Examination out of 70.
--
--    3. Press Save Draft. Open My Results: the results are now Draft and the
--       total and grade are already filled in.
--
--    4. Clear one score box and press Submit for Review. The submission is
--       refused with "Result is incomplete. Please enter all required scores
--       before submission." and the missing students are listed.
--
--    5. Fill the box again and submit. The results become Submitted.
--
--    6. As the administrator, open Results, filter by Submitted, and open one
--       result. Approve it, then Lock it, then Unlock it with a reason. Every
--       step is listed in the Result History panel.
-- ---------------------------------------------------------------------------
