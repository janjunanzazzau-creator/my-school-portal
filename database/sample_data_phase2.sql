-- ============================================================================
--  ORPHANS MODEL NURSERY AND PRIMARY SCHOOL (MAKARANTAR MARAYU)
--  Zazzau Orphans Initiative Foundation
--  School Result Management Portal - PHASE 2 SAMPLE DATA (OPTIONAL)
--
--  Import this file AFTER database/phase2.sql.
--
--    1. Open  http://localhost/phpmyadmin
--    2. Select the database `school_result_portal`
--    3. Click "Import" -> choose this file -> "Go"
--
--  It only adds reference data: classes and subjects.
--  NO students and NO teachers are inserted, so no personal records are
--  created. Admin can add more subjects and classes from the admin pages.
--
--  The file is safe to import more than once (existing rows are left as they
--  are instead of being duplicated).
-- ============================================================================

USE `school_result_portal`;

-- ---------------------------------------------------------------------------
-- 1. CLASSES
-- ---------------------------------------------------------------------------
INSERT INTO `classes` (`class_name`, `section`, `capacity`, `status`) VALUES
    ('Nursery 1', 'Nursery',  25, 'Active'),
    ('Nursery 2', 'Nursery',  25, 'Active'),
    ('Nursery 3', 'Nursery',  25, 'Active'),
    ('Primary 1', 'Primary',  30, 'Active'),
    ('Primary 2', 'Primary',  30, 'Active'),
    ('Primary 3', 'Primary',  30, 'Active'),
    ('Primary 4', 'Primary',  30, 'Active'),
    ('Primary 5', 'Primary',  30, 'Active'),
    ('Primary 6', 'Primary',  35, 'Active'),
    ('JSS 1',     'Secondary', 35, 'Active'),
    ('JSS 2',     'Secondary', 35, 'Active'),
    ('JSS 3',     'Secondary', 35, 'Active'),
    ('SS 1',      'Secondary', 35, 'Active'),
    ('SS 2',      'Secondary', 35, 'Active'),
    ('SS 3',      'Secondary', 35, 'Active')
ON DUPLICATE KEY UPDATE
    `capacity` = VALUES(`capacity`);

-- ---------------------------------------------------------------------------
-- 2. SUBJECTS
-- ---------------------------------------------------------------------------
INSERT INTO `subjects` (`subject_code`, `subject_name`, `section`, `status`) VALUES
    ('ENG',  'English',                        'Primary',   'Active'),
    ('MAT',  'Mathematics',                    'Primary',   'Active'),
    ('BST',  'Basic Science/Technology',       'Primary',   'Active'),
    ('BSS',  'Basic Social Studies',           'Primary',   'Active'),
    ('HAU',  'Hausa',                          'Primary',   'Active'),
    ('COM',  'Computer Education',             'Primary',   'Active'),
    ('QUR',  'Al-Qur''an',                     'Primary',   'Active'),
    ('IRK',  'Islamic Religious Knowledge',    'Primary',   'Active'),
    ('JIG',  'Jigdatis',                       'Nursery',   'Active'),
    ('WDR',  'Writing/Drawing',                'Nursery',   'Active'),
    ('QRE',  'Quantitative Reasoning',         'Secondary', 'Active'),
    ('VRE',  'Verbal Reasoning',               'Secondary', 'Active')
ON DUPLICATE KEY UPDATE
    `subject_name` = VALUES(`subject_name`),
    `section`      = VALUES(`section`);

-- ---------------------------------------------------------------------------
--  End of sample data. No students and no teachers are created here.
-- ---------------------------------------------------------------------------
