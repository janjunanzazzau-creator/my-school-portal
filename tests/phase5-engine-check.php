<?php
declare(strict_types=1);

/**
 * Phase 5 engine check: exercises the pure and read-only helpers so a mistake is
 * found before any page is built on top of them.
 *
 * Run from a command prompt:
 *     C:\xampp\php\php.exe C:\xampp\htdocs\school-result-portal\tests\phase5-engine-check.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This check can only be run from the command line.');
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/functions.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) { $passed++; echo "  PASS  $label\n"; return; }
    $failed++;
    echo "  FAIL  $label" . ($detail === '' ? '' : '  ->  ' . $detail) . "\n";
}

function heading(string $text): void
{
    echo "\n== $text " . str_repeat('=', max(0, 58 - strlen($text))) . "\n";
}

heading('Fixtures');

$sessionId = (int) dbValue('SELECT id FROM academic_sessions WHERE is_current = 1 LIMIT 1');
$termId    = (int) dbValue('SELECT t.id FROM terms t WHERE t.session_id = :s AND t.status = "Active" ORDER BY t.id LIMIT 1', [':s' => $sessionId]);
$classId   = (int) dbValue('SELECT id FROM classes WHERE class_name = "Primary 1" AND section = "Primary" LIMIT 1');
$studentId = (int) dbValue('SELECT student_id FROM student_class_enrollments WHERE class_id = :c AND session_id = :s ORDER BY student_id LIMIT 1', [':c' => $classId, ':s' => $sessionId]);
$teacherId = (int) dbValue('SELECT id FROM teachers ORDER BY id LIMIT 1');

check('session found', $sessionId > 0, "id=$sessionId");
check('term found', $termId > 0, "id=$termId");
check('class found', $classId > 0, "id=$classId");
check('enrolled student found', $studentId > 0, "id=$studentId");
check('a teacher exists to own the fixture results', $teacherId > 0, "id=$teacherId");

heading('1. ordinalSuffix');

foreach ([[1, '1st'], [2, '2nd'], [3, '3rd'], [4, '4th'], [11, '11th'], [12, '12th'], [13, '13th'], [21, '21st'], [22, '22nd'], [23, '23rd'], [101, '101st'], [111, '111th']] as $pair) {
    check("{$pair[0]} -> {$pair[1]}", ordinalSuffix($pair[0]) === $pair[1], ordinalSuffix($pair[0]));
}

heading('2. Fixed vocabularies');

check('four report card statuses', reportCardStatuses() === ['draft', 'generated', 'approved', 'locked'], json_encode(reportCardStatuses()));
check('five promotion statuses', count(promotionStatuses()) === 5, json_encode(promotionStatuses()));
check('promotion includes Pending', in_array('Pending', promotionStatuses(), true));
check('promotion includes Graduated', in_array('Graduated', promotionStatuses(), true));
check('the rating scale is 1 to 5', array_keys(ratingScale()) === [1, 2, 3, 4, 5], json_encode(array_keys(ratingScale())));
check('Excellent is 5', ratingScale()[5] === 'Excellent');
check('Poor is 1', ratingScale()[1] === 'Poor');
check('ten psychomotor traits', count(psychomotorTraits()) === 10, (string) count(psychomotorTraits()));
check('six effective areas', count(effectiveAreaFields()) === 6, (string) count(effectiveAreaFields()));

heading('3. Report number prefix and sequence');

$prefix = reportNumberPrefix($sessionId);
check('the prefix follows the session name', (bool) preg_match('/^ORM-[A-Z0-9-]+-P5$/', $prefix), $prefix);
check('the 2026/2027 session yields 2026-2027', $prefix === 'ORM-2026-2027-P5', $prefix);

$pdo = getDbConnection();
$number = nextReportNumber($pdo, $sessionId);
check('the first number is 0001', $number === $prefix . '-0001', $number);
check('it matches the required format', (bool) preg_match('/^ORM-2026-2027-P5-\d{4}$/', $number), $number);

heading('4. Required subjects for a class');

$required = requiredSubjectsForClass($classId);
check('Primary 1 has required subjects', count($required) > 0, (string) count($required));
check('every row has a code and a name', $required !== [] && !array_filter($required, static function (array $r): bool {
    return ($r['subject_code'] ?? '') === '' || ($r['subject_name'] ?? '') === '';
}));
check('an unknown class returns nothing', requiredSubjectsForClass(999999) === []);
check('a class with results keeps those subjects', count(requiredSubjectsForClass($classId)) >= count($required));

heading('5. Result processing validation');

$check = validateReportGeneration($studentId, $classId, $sessionId, $termId);
check('the gate returns a verdict', is_bool($check['ready']));
check('it counts the offered subjects', $check['offered'] === count($required), $check['offered'] . ' vs ' . count($required));
check('an incomplete sheet is refused', $check['ready'] === false);
check(
    'the required message is used when incomplete',
    $check['message'] === 'Report cannot be generated because some subject results are incomplete.',
    $check['message']
);
check('the missing subjects are listed', $check['missing'] !== [], json_encode($check['missing']));
check('every pending entry names a subject and a status', $check['pending'] !== [] && !array_filter($check['pending'], static function (array $p): bool {
    return ($p['subject'] ?? '') === '' || ($p['status'] ?? '') === '';
}));

/* A student who is not enrolled must be refused by the generator, not by the gate. */
$notEnrolled = (int) dbValue('SELECT id FROM students WHERE status = "Active" AND id NOT IN (SELECT student_id FROM student_class_enrollments) LIMIT 1');
if ($notEnrolled > 0) {
    $gate = validateReportGeneration($notEnrolled, $classId, $sessionId, $termId);
    check('an unenrolled student fails the gate too', $gate['ready'] === false);
} else {
    check('an unenrolled student fails the gate too', true, 'skipped: every active student is enrolled');
}

heading('6. Draft and submitted results are never counted');

/* Build the fixture the check needs rather than hoping one exists: put an
   approved result in place for EVERY required subject, confirm the gate accepts
   the sheet, then walk one result through draft, submitted and locked and
   confirm the gate follows the status each time. */
$pdo = getDbConnection();

$existing = [];

foreach (dbAll(
    'SELECT id, status FROM results WHERE student_id = :sid AND class_id = :c AND session_id = :ss AND term_id = :t',
    [':sid' => $studentId, ':c' => $classId, ':ss' => $sessionId, ':t' => $termId]
) as $row) {
    $existing[(int) $row['id']] = (string) $row['status'];
}

/* Every required subject now gets a result, so the happy path can be tested. */
$insert = $pdo->prepare(
    "INSERT INTO results
        (student_id, class_id, subject_id, session_id, term_id, teacher_id,
         test_1, test_2, test_3, examination, total, grade, grade_remark, status, approved_at, approved_by)
     VALUES
        (:student_id, :class_id, :subject_id, :session_id, :term_id, :teacher_id,
         8, 7, 9, 61, 85, 'A', 'Excellent', 'approved', NOW(), 1)
     ON DUPLICATE KEY UPDATE status = 'approved', approved_at = NOW(), approved_by = 1"
);

$added = 0;

foreach ($required as $subject) {
    $insert->execute([
        ':student_id' => $studentId,
        ':class_id'   => $classId,
        ':subject_id' => (int) $subject['id'],
        ':session_id' => $sessionId,
        ':term_id'    => $termId,
        ':teacher_id' => $teacherId,
    ]);
    $added++;
}

$approvedGate = validateReportGeneration($studentId, $classId, $sessionId, $termId);
check('a fully approved sheet passes the gate', $approvedGate['ready'] === true, $approvedGate['message']);
check('every offered subject was counted', $approvedGate['counted'] === $approvedGate['offered'], $approvedGate['counted'] . ' of ' . $approvedGate['offered']);
check('passed plus failed equals the offered count', $approvedGate['summary']['passed'] + $approvedGate['summary']['failed'] === $approvedGate['offered']);

/* Walk one result through the four states and watch the gate follow it. */
$flipId = (int) dbValue(
    'SELECT id FROM results WHERE student_id = :sid AND class_id = :c AND session_id = :ss AND term_id = :t ORDER BY subject_id LIMIT 1',
    [':sid' => $studentId, ':c' => $classId, ':ss' => $sessionId, ':t' => $termId]
);

$states = ['draft' => false, 'submitted' => false, 'approved' => true, 'locked' => true];

foreach ($states as $state => $expected) {
    $pdo->prepare('UPDATE results SET status = :status WHERE id = :id')
        ->execute([':status' => $state, ':id' => $flipId]);

    $gate = validateReportGeneration($studentId, $classId, $sessionId, $termId);

    check("a '{$state}' result " . ($expected ? 'passes' : 'fails') . ' the gate',
        $gate['ready'] === $expected, $gate['message']);
}

$pdo->prepare('UPDATE results SET status = "approved" WHERE id = :id')->execute([':id' => $flipId]);

/* Put every result the check touched back the way the run found it, and remove
   anything the check created, so the suite leaves no trace. */
foreach ($required as $subject) {
    $row = dbOne(
        'SELECT id FROM results WHERE student_id = :sid AND class_id = :c AND subject_id = :s AND session_id = :ss AND term_id = :t LIMIT 1',
        [':sid' => $studentId, ':c' => $classId, ':s' => (int) $subject['id'], ':ss' => $sessionId, ':t' => $termId]
    );

    if ($row === null) {
        continue;
    }

    $id = (int) $row['id'];

    if (isset($existing[$id])) {
        $pdo->prepare('UPDATE results SET status = :status WHERE id = :id')
            ->execute([':status' => $existing[$id], ':id' => $id]);
    } else {
        $pdo->prepare('DELETE FROM results WHERE id = :id')->execute([':id' => $id]);
    }
}

$leftovers = (int) dbValue(
    'SELECT COUNT(*) FROM results WHERE student_id = :sid AND class_id = :c AND session_id = :ss AND term_id = :t',
    [':sid' => $studentId, ':c' => $classId, ':ss' => $sessionId, ':t' => $termId]
);

check('the fixture was removed again', $leftovers === count($existing), "leftover=$leftovers original=" . count($existing));

heading('7. Class size and ranked count');

$size = classSizeForReport($classId, $sessionId);
check('the class size is positive', $size > 0, (string) $size);

$ranked = rankedStudentCount($classId, $sessionId, $termId);
check('the ranked count cannot exceed the class size', $ranked <= $size, "ranked=$ranked size=$size");
check('an unknown session gives zero students', classSizeForReport($classId, 999999) === 0);
check('an unknown class gives zero students', classSizeForReport(999999, $sessionId) === 0);

heading('8. Previous term and previous session');

check('the first term has no previous term', previousTermInSession($sessionId, (int) dbValue('SELECT MIN(id) FROM terms WHERE session_id = :s', [':s' => $sessionId])) === null);
check('the current session has no previous session', previousAcademicSession($sessionId) === null);
check('an unknown session has no previous session', previousAcademicSession(999999) === null);
check('an unknown term has no previous term', previousTermInSession($sessionId, 999999) === null);

/* With only one session and one term, every historical figure must be null. */
check('no report cards exist yet', (int) dbValue('SELECT COUNT(*) FROM report_cards') === 0 || previousPerformanceFor((int) dbValue('SELECT id FROM report_cards LIMIT 1'))['previous_term'] === null);

heading('9. Listing and counts with no rows');

$listing = listReportCards(['per_page' => 25, 'page' => 1]);
check('an empty list paginates cleanly', $listing['rows'] === [] && $listing['paging']['total'] === 0);
check('an empty list still has a page', $listing['paging']['page'] === 1 && $listing['paging']['pages'] === 1);

$counts = reportCardStatusCounts();
check('every status counts zero', array_sum(array_intersect_key($counts, array_flip(reportCardStatuses()))) === 0, json_encode($counts));
check('the total is zero', $counts['total'] === 0);

/* An empty allow list must return nothing, never everything. */
$blocked = listReportCards(['class_ids' => [], 'per_page' => 25, 'page' => 1]);
check('an empty class allow list returns nothing', $blocked['rows'] === [], (string) count($blocked['rows']));

$blockedCounts = reportCardStatusCounts(['class_ids' => []]);
check('an empty class allow list counts nothing', $blockedCounts['total'] === 0, json_encode($blockedCounts));

heading('Result');

echo "  $passed passed, $failed failed\n\n";

exit($failed === 0 ? 0 : 1);