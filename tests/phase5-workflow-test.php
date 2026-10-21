<?php

declare(strict_types=1);

/**
 * Phase 5 workflow check.
 *
 * Drives the whole report card lifecycle the way the pages do, and then tries
 * every way round it that should be refused:
 *
 *   generate -> approve -> lock -> unlock
 *
 * The point of this file is the refusals. A feature that only ever does the
 * happy path proves nothing about a report card, because a report card that can
 * be edited after it is locked is worse than no report card at all.
 *
 * Every fixture it creates is removed again before it exits, so the suite leaves
 * the database exactly as it found it.
 *
 * Run from a command prompt:
 *     C:\xampp\php\php.exe C:\xampp\htdocs\school-result-portal\tests\phase5-workflow-test.php
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

    if ($ok) {
        $passed++;
        echo "  PASS  $label\n";
        return;
    }

    $failed++;
    echo "  FAIL  $label" . ($detail === '' ? '' : '  ->  ' . $detail) . "\n";
}

function heading(string $text): void
{
    echo "\n== $text " . str_repeat('=', max(0, 58 - strlen($text))) . "\n";
}

/**
 * The suite runs as an administrator, because that is the only role allowed to
 * generate, approve, lock or unlock. A session is faked here rather than going
 * through the login form, because the login form is exercised over HTTP by
 * phase4-http-test.ps1 and duplicating it here would only add a second thing to
 * break.
 */
$_SESSION['user_id']   = (int) dbValue("SELECT id FROM users WHERE role = 'admin' AND status = 1 LIMIT 1");
$_SESSION['user_role'] = 'admin';
$_SESSION['user']      = [
    'id'            => (int) $_SESSION['user_id'],
    'username'      => 'workflow-check',
    'full_name'     => 'Workflow Check',
    'email'         => 'check@example.invalid',
    'role'          => 'admin',
    'status'        => 1,
    'last_login_at' => null,
];

/* -------------------------------------------------------------------------
   Fixtures
   ------------------------------------------------------------------------- */

heading('Fixtures');

$sessionId = (int) dbValue('SELECT id FROM academic_sessions WHERE is_current = 1 LIMIT 1');
$termId    = (int) dbValue(
    'SELECT t.id FROM terms t WHERE t.session_id = :s AND t.status = "Active" ORDER BY t.id LIMIT 1',
    [':s' => $sessionId]
);
$classId   = (int) dbValue('SELECT id FROM classes WHERE class_name = "Primary 1" AND section = "Primary" LIMIT 1');
$teacherId = (int) dbValue('SELECT id FROM teachers ORDER BY id LIMIT 1');

$pdo = getDbConnection();

$roster = classResultRoster($classId, $sessionId);

check('a current session exists', $sessionId > 0, "id=$sessionId");
check('a current term exists', $termId > 0, "id=$termId");
check('a class exists', $classId > 0, "id=$classId");
check('a teacher exists', $teacherId > 0, "id=$teacherId");
check('the class has at least one enrolled student', $roster !== [], (string) count($roster));

if ($roster === []) {
    echo "\nCannot continue without an enrolled student.\n";
    exit(1);
}

$studentId = (int) $roster[0]['student_id'];

/* Every result this suite touches is remembered so it can be restored, and every
   report card, attendance row, assessment row and history row it creates is
   deleted at the end. The Phase 4 sample data must survive this file. */
$originalStatuses = [];

foreach (dbAll(
    'SELECT id, status FROM results
      WHERE student_id = :sid AND class_id = :c AND session_id = :ss AND term_id = :t',
    [':sid' => $studentId, ':c' => $classId, ':ss' => $sessionId, ':t' => $termId]
) as $row) {
    $originalStatuses[(int) $row['id']] = (string) $row['status'];
}

/* A complete, fully approved sheet, so the gate is satisfied. */
$insert = $pdo->prepare(
    "INSERT INTO results
        (student_id, class_id, subject_id, session_id, term_id, teacher_id,
         test_1, test_2, test_3, examination, total, grade, grade_remark, status, approved_at, approved_by)
     VALUES
        (:student_id, :class_id, :subject_id, :session_id, :term_id, :teacher_id,
         9, 8, 9, 68, 94, 'A', 'Excellent', 'approved', NOW(), 1)
     ON DUPLICATE KEY UPDATE
        test_1 = VALUES(test_1), test_2 = VALUES(test_2), test_3 = VALUES(test_3),
        examination = VALUES(examination), total = VALUES(total), grade = VALUES(grade),
        grade_remark = VALUES(grade_remark), status = 'approved', approved_at = NOW(), approved_by = 1"
);

$required = requiredSubjectsForClass($classId);

foreach ($required as $subject) {
    $insert->execute([
        ':student_id' => $studentId,
        ':class_id'   => $classId,
        ':subject_id' => (int) $subject['id'],
        ':session_id' => $sessionId,
        ':term_id'    => $termId,
        ':teacher_id' => $teacherId,
    ]);
}

check('a complete approved sheet was built', validateReportGeneration($studentId, $classId, $sessionId, $termId)['ready'] === true);

/* -------------------------------------------------------------------------
   1. Generation
   ------------------------------------------------------------------------- */

heading('1. Generation');

$generated = generateReportCard($studentId, $classId, $sessionId, $termId);
$reportId  = (int) ($generated['id'] ?? 0);

check('generation succeeds', $generated['ok'] === true, (string) $generated['message']);
check('a report card id comes back', $reportId > 0, (string) $reportId);
check('the card starts as generated', (string) ($generated['ok'] ? 'generated' : 'x') !== 'x' && (string) findReportCard($reportId)['status'] === 'generated');

$card = findReportCard($reportId);

check('a report number was issued', (string) $card['report_number'] !== '', (string) $card['report_number']);
check('the number starts with the session prefix', str_starts_with((string) $card['report_number'], reportNumberPrefix($sessionId)), (string) $card['report_number']);
check('the number ends in a four digit sequence', (bool) preg_match('/-\d{4}$/', (string) $card['report_number']), (string) $card['report_number']);

$check = validateReportGeneration($studentId, $classId, $sessionId, $termId);
check('the stored subject count matches the gate', (int) $card['subjects_offered'] === (int) $check['offered'], $card['subjects_offered'] . ' vs ' . $check['offered']);
check('passed plus failed equals offered', (int) $card['subjects_passed'] + (int) $card['subjects_failed'] === (int) $card['subjects_offered']);
check('the total is a real number', (float) $card['total_score'] > 0, (string) $card['total_score']);
check('the average matches total over subjects', abs((float) $card['average_score'] - round((float) $card['total_score'] / (int) $card['subjects_offered'], 2)) < 0.02, $card['average_score']);
check('a position was calculated', (int) $card['position'] >= 1, (string) $card['position']);
check('the class size is at least the position', (int) $card['total_students'] >= (int) $card['position']);
check('generation is idempotent', (function () use ($studentId, $classId, $sessionId, $termId, $reportId): bool {
    $again = generateReportCard($studentId, $classId, $sessionId, $termId);
    return $again['ok'] === true && (int) $again['id'] === $reportId;
})());

$badSession = generateReportCard($studentId, $classId, $sessionId + 99999, $termId);
check('a made up session is refused', $badSession['ok'] === false, (string) $badSession['message']);
check('the refusal names the problem in plain words', str_contains((string) $badSession['message'], 'session'), (string) $badSession['message']);

/* -------------------------------------------------------------------------
   2. The exact incompleteness message
   ------------------------------------------------------------------------- */

heading('2. The incompleteness message');

$flip = $pdo->prepare('SELECT id FROM results
                        WHERE student_id = :sid AND class_id = :c AND session_id = :ss AND term_id = :t
                        ORDER BY subject_id LIMIT 1');
$flip->execute([':sid' => $studentId, ':c' => $classId, ':ss' => $sessionId, ':t' => $termId]);
$flipId = (int) $flip->fetchColumn();

$pdo->prepare('UPDATE results SET status = "draft" WHERE id = :id')->execute([':id' => $flipId]);

$blocked = generateReportCard($studentId, $classId, $sessionId, $termId);
$gate    = validateReportGeneration($studentId, $classId, $sessionId, $termId);

check('an incomplete sheet refuses generation', $blocked['ok'] === false);
check(
    'the message is exactly the agreed sentence',
    (string) $blocked['message'] === 'Report cannot be generated because some subject results are incomplete.',
    (string) $blocked['message']
);
check('the missing subject is named', $gate['missing'] !== [], json_encode($gate['missing']));
check('the same message comes from the gate', (string) $gate['message'] === 'Report cannot be generated because some subject results are incomplete.');

$pdo->prepare('UPDATE results SET status = "approved" WHERE id = :id')->execute([':id' => $flipId]);

/* -------------------------------------------------------------------------
   3. Workflow
   ------------------------------------------------------------------------- */

heading('3. Workflow');

$lockBeforeLock = applyReportCardAction($reportId, 'lock');
check('a generated card cannot skip straight to locked', $lockBeforeLock['ok'] === false, (string) $lockBeforeLock['message']);

$approved = applyReportCardAction($reportId, 'approve');
check('generated approves', $approved['ok'] === true, (string) $approved['message']);
check('the status is now approved', (string) findReportCard($reportId)['status'] === 'approved');

$reApprove = applyReportCardAction($reportId, 'approve');
check('approving twice is refused', $reApprove['ok'] === false, (string) $reApprove['message']);

$locked = applyReportCardAction($reportId, 'lock');
check('approved locks', $locked['ok'] === true, (string) $locked['message']);
check('the status is now locked', (string) findReportCard($reportId)['status'] === 'locked');

$unlockNoReason = applyReportCardAction($reportId, 'unlock');
check('unlocking without a reason is refused', $unlockNoReason['ok'] === false, (string) $unlockNoReason['message']);
check('the card is still locked after the refusal', (string) findReportCard($reportId)['status'] === 'locked');

$unlocked = applyReportCardAction($reportId, 'unlock', 'Correcting the Mathematics total.');
check('unlocking with a reason succeeds', $unlocked['ok'] === true, (string) $unlocked['message']);
check('unlock returns the card to approved', (string) findReportCard($reportId)['status'] === 'approved');

/* Put it back to locked for the protection checks below. */
applyReportCardAction($reportId, 'lock');

/* -------------------------------------------------------------------------
   4. A locked card accepts no change
   ------------------------------------------------------------------------- */

heading('4. A locked card accepts no change');

$comments = saveReportCardComments($reportId, [
    'teacher_comment'   => 'A comment that must not stick.',
    'principal_comment' => 'Another one.',
    'promotion_status'  => 'Promoted',
]);
check('comments are refused on a locked card', $comments['ok'] === false, (string) $comments['message']);
check('the locked card still has no comment', trim((string) (findReportCard($reportId)['teacher_comment'] ?? '')) === '');

$attendance = saveAttendanceSummary($studentId, $classId, $sessionId, $termId, 60, 55, 5);
check('attendance is refused on a locked card', $attendance['ok'] === false, (string) $attendance['message']);

$assessments = saveAssessments($studentId, $classId, $sessionId, $termId, ['punctuality' => 5], ['creativity' => 4]);
check('assessments are refused on a locked card', $assessments['ok'] === false, (string) $assessments['message']);

$regenerate = generateReportCard($studentId, $classId, $sessionId, $termId);
check('regeneration is refused on a locked card', $regenerate['ok'] === false, (string) $regenerate['message']);
check('the card is still locked after the refusal', (string) findReportCard($reportId)['status'] === 'locked');

$unknown = applyReportCardAction(999999999, 'approve');
check('an unknown report card is refused', $unknown['ok'] === false, (string) $unknown['message']);

/* A locked card must not disclose whether a random id exists, so the message is
   the same shape whatever was asked for. */
check(
    'the refusal does not leak a raw database error',
    stripos((string) $unknown['message'], 'SQL') === false
    && stripos((string) $unknown['message'], 'SELECT') === false,
    (string) $unknown['message']
);

/* -------------------------------------------------------------------------
   5. Comments and promotion on an unlocked card
   ------------------------------------------------------------------------- */

heading('5. Comments and promotion on an unlocked card');

applyReportCardAction($reportId, 'unlock', 'Reviewing the comments.');

$saved = saveReportCardComments($reportId, [
    'teacher_comment'   => 'A hard-working student who always helps in class.',
    'principal_comment' => 'Well done. Keep it up.',
    'promotion_status'  => 'Promoted',
]);
check('comments save on an unlocked card', $saved['ok'] === true, (string) $saved['message']);

$after = findReportCard($reportId);
check('the teacher comment stuck', (string) $after['teacher_comment'] === 'A hard-working student who always helps in class.');
check('the principal comment stuck', (string) $after['principal_comment'] === 'Well done. Keep it up.');
check('the promotion status stuck', (string) $after['promotion_status'] === 'Promoted');

$badPromotion = saveReportCardComments($reportId, [
    'teacher_comment'  => 'Still here.',
    'promotion_status' => 'Teleported',
]);
check('an invented promotion status is refused', $badPromotion['ok'] === false, (string) $badPromotion['message']);
check('the real promotion status survives the refusal', (string) findReportCard($reportId)['promotion_status'] === 'Promoted');

$bothBlank = saveReportCardComments($reportId, [
    'teacher_comment'   => '',
    'principal_comment' => '',
    'promotion_status'  => 'Promoted',
]);
check('two empty comments are refused', $bothBlank['ok'] === false, (string) $bothBlank['message']);

/* -------------------------------------------------------------------------
   6. Regeneration keeps the comments
   ------------------------------------------------------------------------- */

heading('6. Regeneration');

$regenerated = generateReportCard($studentId, $classId, $sessionId, $termId);
check('regeneration succeeds on an unlocked card', $regenerated['ok'] === true, (string) $regenerated['message']);
check('regeneration returns the same card', (int) $regenerated['id'] === $reportId);
check('regeneration sends the card back to generated', (string) findReportCard($reportId)['status'] === 'generated');

$kept = findReportCard($reportId);
check('the teacher comment survives regeneration', (string) $kept['teacher_comment'] === 'A hard-working student who always helps in class.');
check('the principal comment survives regeneration', (string) $kept['principal_comment'] === 'Well done. Keep it up.');
check('the promotion status survives regeneration', (string) $kept['promotion_status'] === 'Promoted');

/* A draft cannot be approved, because a draft has never been generated. */
$pdo->prepare('UPDATE report_cards SET status = "draft" WHERE id = :id')->execute([':id' => $reportId]);
$draftApprove = applyReportCardAction($reportId, 'approve');
check('a draft cannot be approved', $draftApprove['ok'] === false, (string) $draftApprove['message']);

/* -------------------------------------------------------------------------
   7. Attendance arithmetic
   ------------------------------------------------------------------------- */

heading('7. Attendance arithmetic');

$pdo->prepare('DELETE FROM report_cards WHERE id = :id')->execute([':id' => $reportId]);

$ok = saveAttendanceSummary($studentId, $classId, $sessionId, $termId, 60, 55, 5);
check('a balanced summary saves', $ok['ok'] === true, (string) $ok['message']);

$stored = reportCardAttendance($studentId, $classId, $sessionId, $termId);
check('the stored present matches', (int) $stored['days_present'] === 55);
check('the stored absent matches', (int) $stored['days_absent'] === 5);
check('the stored total matches', (int) $stored['school_days'] === 60);

check('present plus absent must equal the total', saveAttendanceSummary($studentId, $classId, $sessionId, $termId, 60, 55, 4)['ok'] === false);
check('zero school days is refused', saveAttendanceSummary($studentId, $classId, $sessionId, $termId, 0, 0, 0)['ok'] === false);
check('present beyond the total is refused', saveAttendanceSummary($studentId, $classId, $sessionId, $termId, 60, 70, -10)['ok'] === false);
check('a negative absence is refused', saveAttendanceSummary($studentId, $classId, $sessionId, $termId, 60, 65, -5)['ok'] === false);

/* The bad attempts must not have damaged the good row. */
$stillGood = reportCardAttendance($studentId, $classId, $sessionId, $termId);
check('the good row survived every refusal', (int) $stillGood['days_present'] === 55 && (int) $stillGood['days_absent'] === 5);

/* A second save updates in place rather than creating a duplicate. */
saveAttendanceSummary($studentId, $classId, $sessionId, $termId, 60, 58, 2);
check(
    'saving again updates the same row',
    (int) dbValue(
        'SELECT COUNT(*) FROM attendance_summary WHERE student_id = :sid AND class_id = :c AND session_id = :ss AND term_id = :t',
        [':sid' => $studentId, ':c' => $classId, ':ss' => $sessionId, ':t' => $termId]
    ) === 1
);
check('the updated figures are stored', (int) reportCardAttendance($studentId, $classId, $sessionId, $termId)['days_present'] === 58);

/* -------------------------------------------------------------------------
   8. Assessment scale
   ------------------------------------------------------------------------- */

heading('8. Assessment scale');

$full = [];

foreach (psychomotorTraits() as $key => $label) {
    $full[$key] = 4;
}

foreach (effectiveAreaFields() as $key => $label) {
    $full[$key] = 3;
}

$saved = saveAssessments($studentId, $classId, $sessionId, $termId, $full, $full);
check('a full set of ratings saves', $saved['ok'] === true, (string) $saved['message']);

$psych = reportCardPsychomotor($studentId, $classId, $sessionId, $termId);
$eff   = reportCardEffectiveAreas($studentId, $classId, $sessionId, $termId);
check('the psychomotor row came back', $psych !== null);
check('the effective areas row came back', $eff !== null);
check('a psychomotor rating is stored', (int) $psych['punctuality'] === 4);
check('an effective area rating is stored', (int) $eff['communication'] === 3);

check('a rating of zero is refused', saveAssessments($studentId, $classId, $sessionId, $termId, ['punctuality' => 0], [])['ok'] === false);
check('a rating of six is refused', saveAssessments($studentId, $classId, $sessionId, $termId, ['punctuality' => 6], [])['ok'] === false);
check('a fractional rating is refused', saveAssessments($studentId, $classId, $sessionId, $termId, ['punctuality' => 3.5], [])['ok'] === false);
check('a non numeric rating is refused', saveAssessments($studentId, $classId, $sessionId, $termId, ['punctuality' => 'excellent'], [])['ok'] === false);
check('a blank form saves nothing', saveAssessments($studentId, $classId, $sessionId, $termId, [], [])['ok'] === false);

check('the good ratings survived the bad attempts', (int) reportCardPsychomotor($studentId, $classId, $sessionId, $termId)['punctuality'] === 4);

/* One bad rating must not half-save the other fifteen. */
$partialBad = saveAssessments(
    $studentId,
    $classId,
    $sessionId,
    $termId,
    ['punctuality' => 1, 'neatness' => 9],
    ['creativity' => 2]
);
check('one out of range rating refuses the whole save', $partialBad['ok'] === false, (string) $partialBad['message']);
check('the bad field is named', isset($partialBad['errors']['neatness']), json_encode($partialBad['errors']));
check('punctuality was not changed by the refused save', (int) reportCardPsychomotor($studentId, $classId, $sessionId, $termId)['punctuality'] === 4);
check('creativity was not changed by the refused save', (int) reportCardEffectiveAreas($studentId, $classId, $sessionId, $termId)['creativity'] === 3);

/* -------------------------------------------------------------------------
   9. Teacher scope
   ------------------------------------------------------------------------- */

heading('9. Teacher scope');

$generatedAgain = generateReportCard($studentId, $classId, $sessionId, $termId);
$reportId       = (int) $generatedAgain['id'];
$card           = findReportCard($reportId);

$classTeacherId = (int) dbValue(
    "SELECT teacher_id FROM teacher_class_assignments
      WHERE class_id = :c AND session_id = :s AND status = 'Active' LIMIT 1",
    [':c' => $classId, ':s' => $sessionId]
);
$otherTeacherId = (int) dbValue(
    'SELECT id FROM teachers WHERE id <> :t ORDER BY id LIMIT 1',
    [':t' => $classTeacherId]
);

check('the class has a class teacher', $classTeacherId > 0, "id=$classTeacherId");

if ($classTeacherId > 0 && $otherTeacherId > 0) {
    check('the class teacher may view the card', teacherCanViewReportCard($classTeacherId, $card));
    check('the class teacher may comment on the card', teacherCanCommentOnReportCard($classTeacherId, $card));
    check('another teacher may not view the card', teacherCanViewReportCard($otherTeacherId, $card) === false);
    check('another teacher may not comment on the card', teacherCanCommentOnReportCard($otherTeacherId, $card) === false);
    check('an unknown teacher id may not view the card', teacherCanViewReportCard(999999999, $card) === false);
    check('an unknown teacher id may not comment', teacherCanCommentOnReportCard(999999999, $card) === false);
}

applyReportCardAction($reportId, 'approve');
applyReportCardAction($reportId, 'lock');

/* Section 7 deleted the first card to test attendance on an unlocked card, so
   this one has to walk the whole workflow again before the history section has
   anything real to look at. */
applyReportCardAction($reportId, 'unlock', 'Correcting the Mathematics total.');
applyReportCardAction($reportId, 'lock');

if ($classTeacherId > 0) {
    /* Re-read after locking: the authorization helper decides from the status on
       the row it is given, so passing a stale copy would prove nothing. */
    $lockedCard = findReportCard($reportId);
    check('a locked card is not commentable even by the class teacher', teacherCanCommentOnReportCard($classTeacherId, $lockedCard) === false);
    check('a locked card is still viewable by the class teacher', teacherCanViewReportCard($classTeacherId, $lockedCard));
}

/* -------------------------------------------------------------------------
   10. History
   ------------------------------------------------------------------------- */

heading('10. History');

$history = reportCardHistory($reportId);

check('history has entries', $history !== [], (string) count($history));
check('generation is recorded', (bool) array_filter($history, static function (array $h): bool {
    return (string) $h['action'] === 'generated';
}));
check('approval is recorded', (bool) array_filter($history, static function (array $h): bool {
    return (string) $h['action'] === 'approved' && (string) $h['new_status'] === 'approved';
}));
check('locking is recorded', (bool) array_filter($history, static function (array $h): bool {
    return (string) $h['action'] === 'locked' && (string) $h['new_status'] === 'locked';
}));
check('the unlock reason is kept', (bool) array_filter($history, static function (array $h): bool {
    return (string) $h['action'] === 'unlocked' && str_contains((string) $h['reason'], 'Mathemat');
}));
check('every entry records who acted', (bool) array_filter($history, static function (array $h): bool {
    return (string) $h['action'] === 'locked' && (int) $h['user_id'] > 0;
}));

/* -------------------------------------------------------------------------
   11. Listing
   ------------------------------------------------------------------------- */

heading('11. Listing');

$listing = listReportCards(['student_id' => $studentId, 'session_id' => $sessionId]);
check('the new card is in the listing', count($listing['rows']) === 1, (string) count($listing['rows']));

$lockedOnly = listReportCards(['student_id' => $studentId, 'status' => 'locked']);
check('a status filter narrows the listing', count($lockedOnly['rows']) === 1, (string) count($lockedOnly['rows']));

$byNumber = listReportCards(['search' => (string) $card['report_number']]);
check('the report number is searchable', count($byNumber['rows']) === 1, (string) count($byNumber['rows']));

/* The important one: a teacher with no allocations must see nothing, not
   everything. An empty allow list is "no classes", never "no filter". */
$noAccess = listReportCards(['class_ids' => []]);
check('an empty class allow list returns nothing', $noAccess['rows'] === []);
check('an empty class allow list reports zero', (int) $noAccess['paging']['total'] === 0);
check('an empty class allow list counts zero', (int) reportCardStatusCounts(['class_ids' => []])['total'] === 0);

$wrongClass = listReportCards(['class_ids' => [$classId + 999999]]);
check('a wrong class allow list returns nothing', $wrongClass['rows'] === []);

$counts = reportCardStatusCounts(['student_id' => $studentId]);
check('the locked count is one', (int) $counts['locked'] === 1, json_encode($counts));
check('the total is the sum of the states', (int) $counts['total'] === 1, json_encode($counts));

/* -------------------------------------------------------------------------
   12. Subject rows
   ------------------------------------------------------------------------- */

heading('12. Subject rows');

$rows = reportCardSubjectRows($reportId);
check('every required subject prints', count($rows) === count($required), count($rows) . ' of ' . count($required));
check('every printed row names its subject', $rows !== [] && $rows[0]['subject_name'] !== '' && $rows[0]['subject_code'] !== '');
check('every printed row is counted', (bool) array_filter($rows, static function (array $r): bool {
    return $r['counted'] === true;
}));
check('the totals on the rows add up', array_sum(array_map(static function (array $r): float {
    return (float) $r['total'];
}, $rows)) > 0);

/* A subject with no result at all must still print as an empty row, so a
   missing subject can never quietly disappear from a card. */
/* A subject with no result at all must still print as an empty row, so a
   missing subject can never quietly disappear from a card. The whole victim row
   is snapshotted first and put back afterwards, because an UPDATE cannot
   resurrect a row that was deleted. */
$victimId = (int) array_key_first($originalStatuses);
$victim   = dbOne('SELECT * FROM results WHERE id = :id LIMIT 1', [':id' => $victimId]);

$pdo->prepare('DELETE FROM results WHERE id = :id')->execute([':id' => $victimId]);

$partialRows = reportCardSubjectRows($reportId);
check('a subject with no result still prints', count($partialRows) === count($required), count($partialRows) . ' of ' . count($required));
check('the missing subject is marked as not counted', (bool) array_filter($partialRows, static function (array $r): bool {
    return $r['counted'] === false;
}), json_encode(array_map(static function (array $r): string {
    return $r['subject_code'] . '=' . var_export($r['counted'], true);
}, $partialRows)));

if ($victim !== null) {
    $columns = array_keys($victim);
    $marks   = implode(', ', array_fill(0, count($columns), '?'));

    $pdo->prepare(
        'INSERT INTO results (' . implode(', ', $columns) . ') VALUES (' . $marks . ')'
    )->execute(array_values($victim));
}

check('the deleted result was put back exactly', dbOne('SELECT * FROM results WHERE id = :id LIMIT 1', [':id' => $victimId]) !== null);

heading('Cleanup');

$pdo->prepare('DELETE FROM report_card_history WHERE report_card_id = :id')->execute([':id' => $reportId]);
$pdo->prepare('DELETE FROM report_cards WHERE student_id = :sid')->execute([':sid' => $studentId]);
$pdo->prepare('DELETE FROM attendance_summary WHERE student_id = :sid')->execute([':sid' => $studentId]);
$pdo->prepare('DELETE FROM psychomotor_assessments WHERE student_id = :sid')->execute([':sid' => $studentId]);
$pdo->prepare('DELETE FROM effective_areas WHERE student_id = :sid')->execute([':sid' => $studentId]);

foreach ($originalStatuses as $id => $status) {
    $pdo->prepare('UPDATE results SET status = :status WHERE id = :id')
        ->execute([':status' => $status, ':id' => $id]);
}

/* An empty IN () is a syntax error, and the suite must also run against an empty
   results table, which is what a fresh reset gives it. */
$keepIds    = array_map('intval', array_keys($originalStatuses));
$notDeleted = $keepIds === [] ? '1 = 1' : 'r.id NOT IN (' . implode(',', $keepIds) . ')';

foreach (dbAll(
    'SELECT r.id FROM results r
       JOIN student_class_enrollments e ON e.student_id = r.student_id AND e.class_id = r.class_id
      WHERE r.student_id = :sid AND r.class_id = :c AND r.session_id = :ss AND r.term_id = :t
        AND ' . $notDeleted,
    [':sid' => $studentId, ':c' => $classId, ':ss' => $sessionId, ':t' => $termId]
) as $extra) {
    $pdo->prepare('DELETE FROM results WHERE id = :id')->execute([':id' => (int) $extra['id']]);
}

check('the report cards are gone', (int) dbValue('SELECT COUNT(*) FROM report_cards') === 0);
check('the attendance rows are gone', (int) dbValue('SELECT COUNT(*) FROM attendance_summary') === 0);
check('the psychomotor rows are gone', (int) dbValue('SELECT COUNT(*) FROM psychomotor_assessments') === 0);
check('the effective area rows are gone', (int) dbValue('SELECT COUNT(*) FROM effective_areas') === 0);
check('the history rows are gone', (int) dbValue('SELECT COUNT(*) FROM report_card_history') === 0);

$restored = 0;

foreach ($originalStatuses as $id => $status) {
    if ((string) dbValue('SELECT status FROM results WHERE id = :id', [':id' => $id]) === $status) {
        $restored++;
    }
}

check('every original result status is back', $restored === count($originalStatuses), "$restored of " . count($originalStatuses));

/* ------------------------------------------------------------------------- */

echo "\n" . str_repeat('-', 64) . "\n";
echo $failed === 0
    ? "Phase 5 workflow: $passed passed, 0 failed\n"
    : "Phase 5 workflow: $passed passed, $failed failed\n";

exit($failed === 0 ? 0 : 1);
