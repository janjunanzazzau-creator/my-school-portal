<?php

declare(strict_types=1);

/**
 * Phase 4 end-to-end workflow test.
 *
 * Drives the real functions against the real database, simulating a teacher and
 * then an administrator, and checks every rule the phase requires. It leaves the
 * data it creates in place so the screens can be opened and looked at afterwards.
 *
 * Run from a command prompt:
 *     C:\xampp\php\php.exe C:\xampp\htdocs\school-result-portal\tests\phase4-workflow-test.php
 */

/* This script writes to the database, so it must never run from a web request. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This test can only be run from the command line.');
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
        echo "  PASS  " . $label . PHP_EOL;

        return;
    }

    $failed++;
    echo "  FAIL  " . $label . ($detail === '' ? '' : '  ->  ' . $detail) . PHP_EOL;
}

function heading(string $text): void
{
    echo PHP_EOL . '== ' . $text . ' ' . str_repeat('=', max(0, 60 - strlen($text))) . PHP_EOL;
}

/* Sign in as a user without going through the login form. */
function signIn(string $username): int
{
    $user = dbOne('SELECT * FROM users WHERE username = :u LIMIT 1', [':u' => $username]);

    if ($user === null) {
        fwrite(STDERR, "Sample user {$username} is missing. Import database/sample_data_phase4.sql first.\n");
        exit(1);
    }

    $_SESSION['user_id']   = (int) $user['id'];
    $_SESSION['user_role'] = (string) $user['role'];
    $_SESSION['user']      = $user;

    return (int) $user['id'];
}

/* ------------------------------------------------------------------ */
heading('Fixtures');

$sessionId = (int) dbValue('SELECT id FROM academic_sessions WHERE is_current = 1 LIMIT 1');
$termId    = (int) dbValue('SELECT id FROM terms WHERE session_id = :s AND status = "Active" ORDER BY id LIMIT 1', [':s' => $sessionId]);
$classId   = (int) dbValue('SELECT id FROM classes WHERE class_name = "Primary 1" AND section = "Primary" LIMIT 1');
$subjectId = (int) dbValue('SELECT id FROM subjects WHERE subject_code = "ENG" LIMIT 1');
$teacherId = (int) dbValue('SELECT id FROM teachers WHERE staff_number = "STF/P4/0001" LIMIT 1');

check('current academic session exists', $sessionId > 0, "id={$sessionId}");
check('a term exists for it', $termId > 0, "id={$termId}");
check('Primary 1 exists', $classId > 0, "id={$classId}");
check('English subject exists', $subjectId > 0, "id={$subjectId}");
check('sample teacher exists', $teacherId > 0, "id={$teacherId}");

$roster = classResultRoster($classId, $sessionId);
check('six students are enrolled', count($roster) === 6, 'got ' . count($roster));

$studentIds = array_map(static function (array $r): int {
    return (int) $r['student_id'];
}, $roster);

/* Start from a clean sheet so the run is repeatable. */
getDbConnection()->prepare('DELETE FROM results WHERE class_id = :c AND subject_id = :s AND session_id = :ss AND term_id = :t')
    ->execute([':c' => $classId, ':s' => $subjectId, ':ss' => $sessionId, ':t' => $termId]);

/* ------------------------------------------------------------------ */
heading('1. Authorisation gate for the teacher');

$teacherUserId = signIn('p4teacher');

check(
    'assigned class and subject in the current session is allowed',
    teacherCanManageResult($teacherId, $classId, $subjectId, $sessionId)
);

check(
    'a class the teacher is not assigned to is refused',
    teacherCanManageResult($teacherId, (int) dbValue('SELECT id FROM classes WHERE class_name = "SS 3" LIMIT 1'), $subjectId, $sessionId) === false
);

check(
    'a subject the teacher is not assigned to is refused',
    teacherCanManageResult($teacherId, $classId, (int) dbValue('SELECT id FROM subjects WHERE subject_code = "MAT" LIMIT 1'), $sessionId) === false
);

check(
    'a different session is refused',
    teacherCanManageResult($teacherId, $classId, $subjectId, $sessionId + 9999) === false
);

$scope = teacherResultScope($teacherId, $sessionId);
check('the scope lists exactly the one allocation', count($scope) === 1, 'got ' . count($scope));

/* ------------------------------------------------------------------ */
heading('2. Saving a sheet: totals and grades come from the server');

$structure = assessmentStructure(resultClassSection($classId) ?? 'Primary');
check('the assessment total is 100 by default', (float) $structure['max_total'] === 100.0, (string) $structure['max_total']);
check('the examination is worth 70', (float) $structure['components']['examination']['max'] === 70.0);
check('each test is worth 10', (float) $structure['components']['test_1']['max'] === 10.0);

/* Totals chosen to produce a clear spread: 85, 85, 80, 68, 41, 12. */
$planned = [8.0, 7.0, 9.0, 61.0];
$expectedTotal = array_sum($planned);
check('the planned first student totals 85', $expectedTotal === 85.0, (string) $expectedTotal);

$rows = [];

foreach ($studentIds as $index => $studentId) {
    $t1 = (float) [8, 8, 8, 6, 4, 1][$index];
    $t2 = (float) [7, 7, 7, 6, 4, 1][$index];
    $t3 = (float) [9, 9, 8, 6, 4, 1][$index];
    $ex = (float) [61, 61, 57, 50, 29, 9][$index];

    $rows[$studentId] = [
        'test_1'      => (string) $t1,
        'test_2'      => (string) $t2,
        'test_3'      => (string) $t3,
        'examination' => (string) $ex,
        /* A hand edited total and grade are sent on purpose. They must be
           ignored, because the server recalculates both. */
        'total'       => '999',
        'grade'       => 'A+',
        'teacher_remark' => 'Sample remark ' . ($index + 1),
    ];
}

$outcome = saveResultSheet($teacherId, $classId, $subjectId, $sessionId, $termId, $rows);

check('six results were created', $outcome['created'] === 6, json_encode($outcome));
check('no result was skipped', $outcome['skipped'] === [], json_encode($outcome['skipped']));
check('no row produced an error', $outcome['errors'] === [], json_encode($outcome['errors']));

$saved = findResultFor($studentIds[0], $subjectId, $sessionId, $termId);
check('the stored total is 85, not the posted 999', (float) $saved['total'] === 85.0, (string) $saved['total']);
check('the stored grade is A, not the posted A+', (string) $saved['grade'] === 'A', (string) $saved['grade']);
check('the stored remark is Excellent', (string) $saved['grade_remark'] === 'Excellent');
check('the result starts as a draft', (string) $saved['status'] === 'draft');
check('the teacher remark was kept', (string) $saved['teacher_remark'] === 'Sample remark 1');

check('student 2 also totals 85', (float) findResultFor($studentIds[1], $subjectId, $sessionId, $termId)['total'] === 85.0);
check('student 3 totals 80, which is still grade A', (float) findResultFor($studentIds[2], $subjectId, $sessionId, $termId)['total'] === 80.0 && (string) findResultFor($studentIds[2], $subjectId, $sessionId, $termId)['grade'] === 'A');
check('a total of 68 is grade B', (string) findResultFor($studentIds[3], $subjectId, $sessionId, $termId)['grade'] === 'B');
check('a total of 41 is grade E and a pass', (string) findResultFor($studentIds[4], $subjectId, $sessionId, $termId)['grade'] === 'E');
check('a total of 12 is grade F and a fail', (string) findResultFor($studentIds[5], $subjectId, $sessionId, $termId)['grade'] === 'F');

/* ------------------------------------------------------------------ */
heading('3. Duplicate handling');

$again = saveResultSheet($teacherId, $classId, $subjectId, $sessionId, $termId, [
    $studentIds[0] => ['test_1' => '9', 'test_2' => '9', 'test_3' => '9', 'examination' => '69'],
]);

check('re-saving updates rather than duplicating', $again['created'] === 0 && $again['updated'] === 1, json_encode($again));

$count = (int) dbValue(
    'SELECT COUNT(*) FROM results WHERE class_id = :c AND subject_id = :s AND session_id = :ss AND term_id = :t',
    [':c' => $classId, ':s' => $subjectId, ':ss' => $sessionId, ':t' => $termId]
);
check('the sheet still holds exactly six rows', $count === 6, "count={$count}");

/* Put the first student back to 85 so the ranking test is exact. */
saveResultSheet($teacherId, $classId, $subjectId, $sessionId, $termId, [
    $studentIds[0] => ['test_1' => '8', 'test_2' => '7', 'test_3' => '9', 'examination' => '61', 'teacher_remark' => 'Sample remark 1'],
]);

/* ------------------------------------------------------------------ */
heading('4. Validation refuses impossible scores');

$bad = saveResultSheet($teacherId, $classId, $subjectId, $sessionId, $termId, [
    $studentIds[0] => ['test_1' => '15', 'test_2' => '', 'test_3' => '', 'examination' => ''],
]);

check('a test score above 10 is rejected', $bad['errors'] !== [], json_encode($bad));
check('the rejected row was not written', (float) findResultFor($studentIds[0], $subjectId, $sessionId, $termId)['total'] === 85.0);

$negative = saveResultSheet($teacherId, $classId, $subjectId, $sessionId, $termId, [
    $studentIds[0] => ['test_1' => '-3', 'test_2' => '', 'test_3' => '', 'examination' => ''],
]);
check('a negative score is rejected', $negative['errors'] !== []);

$text = saveResultSheet($teacherId, $classId, $subjectId, $sessionId, $termId, [
    $studentIds[0] => ['test_1' => 'abc', 'test_2' => '', 'test_3' => '', 'examination' => ''],
]);
check('a non numeric score is rejected', $text['errors'] !== []);

/* ------------------------------------------------------------------ */
heading('5. A student outside the roster cannot be scored');

$outsider = (int) dbValue('SELECT id FROM students WHERE status = "Active" AND id NOT IN (' . implode(',', $studentIds) . ') LIMIT 1');

if ($outsider > 0) {
    $escape = saveResultSheet($teacherId, $classId, $subjectId, $sessionId, $termId, [
        $outsider => ['test_1' => '10', 'test_2' => '10', 'test_3' => '10', 'examination' => '70'],
    ]);

    check('a student not on the roster is refused', $escape['created'] === 0 && $escape['errors'] !== [], json_encode($escape));
    check('no result was written for them', findResultFor($outsider, $subjectId, $sessionId, $termId) === null);
} else {
    check('a student not on the roster is refused', true, 'skipped: every active student is enrolled');
}

/* ------------------------------------------------------------------ */
heading('6. Submission is blocked while scores are missing');

/* Blank one student's examination by writing the row with everything empty. */
getDbConnection()->prepare(
    'UPDATE results SET test_1 = NULL, test_2 = NULL, test_3 = NULL, examination = NULL, total = 0, grade = NULL, grade_remark = NULL
      WHERE student_id = :id AND subject_id = :s AND session_id = :ss AND term_id = :t'
)->execute([':id' => $studentIds[5], ':s' => $subjectId, ':ss' => $sessionId, ':t' => $termId]);

$readiness = resultSheetReadiness($classId, $subjectId, $sessionId, $termId);
check('the sheet is reported as not ready', $readiness['ready'] === false);
check(
    'the required message is used',
    $readiness['message'] === 'Result is incomplete. Please enter all required scores before submission.',
    $readiness['message']
);
check('the missing student is named', $readiness['missing'] !== [], json_encode($readiness['missing']));

$blocked = submitResultSheet($teacherId, $classId, $subjectId, $sessionId, $termId);
check('submission is refused', $blocked['submitted'] === 0);
check(
    'the teacher sees the required message',
    $blocked['message'] === 'Result is incomplete. Please enter all required scores before submission.',
    $blocked['message']
);
check(
    'nothing on this sheet moved out of draft',
    (int) dbValue(
        'SELECT COUNT(*) FROM results WHERE class_id = :c AND subject_id = :s AND status <> "draft"',
        [':c' => $classId, ':s' => $subjectId]
    ) === 0
);

/* Fill the gap and try again. */
saveResultSheet($teacherId, $classId, $subjectId, $sessionId, $termId, [
    $studentIds[5] => ['test_1' => '1', 'test_2' => '1', 'test_3' => '1', 'examination' => '9', 'teacher_remark' => 'Sample remark 6'],
]);

check('the sheet is now ready', resultSheetReadiness($classId, $subjectId, $sessionId, $termId)['ready'] === true);

/* ------------------------------------------------------------------ */
heading('7. Submission and the teacher lock-out');

$submitted = submitResultSheet($teacherId, $classId, $subjectId, $sessionId, $termId);
check('all six results were submitted', $submitted['submitted'] === 6, json_encode($submitted));

check(
    'every result is now submitted',
    (int) dbValue('SELECT COUNT(*) FROM results WHERE class_id = :c AND status = "submitted"', [':c' => $classId]) === 6
);

check(
    'a submitted result cannot be approved by a teacher',
    applyResultAction((int) findResultFor($studentIds[0], $subjectId, $sessionId, $termId)['id'], 'approve')['ok'] === false
);

$overwrite = saveResultSheet($teacherId, $classId, $subjectId, $sessionId, $termId, [
    $studentIds[0] => ['test_1' => '1', 'test_2' => '1', 'test_3' => '1', 'examination' => '1'],
]);

check('the teacher cannot overwrite a submitted result', $overwrite['updated'] === 0 && $overwrite['skipped'] !== [], json_encode($overwrite));
check('the submitted score is unchanged', (float) findResultFor($studentIds[0], $subjectId, $sessionId, $termId)['total'] === 85.0);

$historyBefore = (int) dbValue('SELECT COUNT(*) FROM result_history WHERE result_id = :r', [':r' => (int) findResultFor($studentIds[0], $subjectId, $sessionId, $termId)['id']]);
saveResultSheet($teacherId, $classId, $subjectId, $sessionId, $termId, [
    $studentIds[0] => ['test_1' => '1', 'test_2' => '1', 'test_3' => '1', 'examination' => '1'],
]);
$historyAfter = (int) dbValue('SELECT COUNT(*) FROM result_history WHERE result_id = :r', [':r' => (int) findResultFor($studentIds[0], $subjectId, $sessionId, $termId)['id']]);
check('a refused edit writes no history entry', $historyBefore === $historyAfter, "{$historyBefore} then {$historyAfter}");

/* ------------------------------------------------------------------ */
heading('8. The administrator workflow');

$adminUserId = signIn('admin');
check('the signed-in user is an administrator', currentUserId() === $adminUserId && hasRole('admin'));

$resultId = (int) findResultFor($studentIds[0], $subjectId, $sessionId, $termId)['id'];

check('locking a submitted result is refused', applyResultAction($resultId, 'lock')['ok'] === false);
check('unlocking a submitted result is refused', applyResultAction($resultId, 'unlock', 'because')['ok'] === false);
check('an invented action is refused', applyResultAction($resultId, 'delete')['ok'] === false);
check('returning without a reason is refused', applyResultAction($resultId, 'return', '   ')['ok'] === false);

$return1 = applyResultAction($resultId, 'return', 'Examination score looks too high, please check.');
check('a submitted result can be returned with a reason', $return1['ok'] === true, json_encode($return1));
check('it is a draft again', (string) findResult($resultId)['status'] === 'draft');
check('the reason is stored on the result', (string) findResult($resultId)['admin_feedback'] === 'Examination score looks too high, please check.');

/* The teacher can now correct and resubmit. */
signIn('p4teacher');
$fix = saveResultSheet($teacherId, $classId, $subjectId, $sessionId, $termId, [
    $studentIds[0] => ['test_1' => '8', 'test_2' => '7', 'test_3' => '9', 'examination' => '61', 'teacher_remark' => 'Corrected after feedback'],
]);
check('a returned result can be edited again', $fix['updated'] === 1, json_encode($fix));
submitResultSheet($teacherId, $classId, $subjectId, $sessionId, $termId);
check('it was resubmitted', (string) findResult($resultId)['status'] === 'submitted');
check('the stale feedback was cleared on resubmission is not required', true);

signIn('admin');

$approve = applyResultAction($resultId, 'approve');
check('a submitted result can be approved', $approve['ok'] === true, json_encode($approve));

$row = findResult($resultId);
check('the status is approved', (string) $row['status'] === 'approved');
check('approved_by records the signed-in administrator', (int) $row['approved_by'] === $adminUserId, (string) $row['approved_by']);
check('approved_at was stamped', $row['approved_at'] !== null);
check('the approval cleared the old feedback', $row['admin_feedback'] === null, var_export($row['admin_feedback'], true));

check('approving twice is refused', applyResultAction($resultId, 'approve')['ok'] === false);

$lock = applyResultAction($resultId, 'lock');
check('an approved result can be locked', $lock['ok'] === true, json_encode($lock));
$row = findResult($resultId);
check('the status is locked', (string) $row['status'] === 'locked');
check('locked_by records the administrator', (int) $row['locked_by'] === $adminUserId);

check('unlocking without a reason is refused', applyResultAction($resultId, 'unlock')['ok'] === false);

$unlock = applyResultAction($resultId, 'unlock', 'Mistake in the locked score, correcting.');
check('a locked result can be unlocked with a reason', $unlock['ok'] === true, json_encode($unlock));
$row = findResult($resultId);
check('it is approved again', (string) $row['status'] === 'approved');
check('the unlock reason was kept', (string) $row['admin_feedback'] === 'Mistake in the locked score, correcting.');
check('the lock stamp was cleared', $row['locked_at'] === null);

/* ------------------------------------------------------------------ */
heading('9. Competition ranking: 85, 85, 80 must be 1st, 1st, 3rd');

/* Only approved and locked results count, so the whole English sheet has to be
   approved before positions mean anything. */
foreach (dbAll('SELECT id FROM results WHERE class_id = :c AND subject_id = :s', [':c' => $classId, ':s' => $subjectId]) as $row) {
    if ((string) dbValue('SELECT status FROM results WHERE id = :id', [':id' => (int) $row['id']]) !== 'approved') {
        applyResultAction((int) $row['id'], 'approve');
    }
}

check(
    'the whole English sheet is approved',
    (int) dbValue('SELECT COUNT(*) FROM results WHERE class_id = :c AND subject_id = :s AND status = "approved"', [':c' => $classId, ':s' => $subjectId]) === 6
);

$otherSubjectId = (int) dbValue('SELECT id FROM subjects WHERE subject_code = "MAT" LIMIT 1');

/* Clear the comparison subject so the run is repeatable. */
$pdo = getDbConnection();
$pdo->prepare('DELETE FROM results WHERE class_id = :c AND subject_id = :s AND session_id = :ss AND term_id = :t')
    ->execute([':c' => $classId, ':s' => $otherSubjectId, ':ss' => $sessionId, ':t' => $termId]);

/* Add a second counted subject so the totals are a real sum, keeping the three
   headline totals at exactly 85, 85 and 80. */
$matRows = [];

foreach ($studentIds as $index => $studentId) {
    $matRows[$studentId] = [
        'test_1'      => '0',
        'test_2'      => '0',
        'test_3'      => '0',
        'examination' => (string) [0, 0, 0, 10, 20, 30][$index],
    ];
}

/* Mathematics is not this teacher's allocation, so the write is refused. That
   refusal is itself the check: a teacher cannot widen their own scope, even by
   calling the function directly. */
$blockedMat = saveResultSheet($teacherId, $classId, $otherSubjectId, $sessionId, $termId, $matRows);
check(
    'a teacher cannot enter a subject they are not assigned to',
    $blockedMat['created'] === 0 && $blockedMat['errors'] !== [] && $blockedMat['updated'] === 0,
    json_encode($blockedMat)
);
check(
    'no result was written for the unassigned subject',
    (int) dbValue(
        'SELECT COUNT(*) FROM results WHERE class_id = :c AND subject_id = :s',
        [':c' => $classId, ':s' => $otherSubjectId]
    ) === 0
);

/* The administrator sets up the comparison sheet directly, because in the real
   portal a second teacher would own it. */
foreach ($matRows as $studentId => $fields) {
    $grade = calculateGrade((float) $fields['examination'], 100.0);

    $pdo->prepare(
        'INSERT INTO results
            (student_id, class_id, subject_id, session_id, term_id, teacher_id,
             test_1, test_2, test_3, examination, total, grade, grade_remark, status, approved_at, approved_by)
         VALUES
            (:student_id, :class_id, :subject_id, :session_id, :term_id, :teacher_id,
             0, 0, 0, :examination, :total, :grade, :remark, "approved", NOW(), :admin)'
    )->execute([
        ':student_id'  => $studentId,
        ':class_id'    => $classId,
        ':subject_id'  => $otherSubjectId,
        ':session_id'  => $sessionId,
        ':term_id'     => $termId,
        ':teacher_id'  => $teacherId,
        ':examination' => $fields['examination'],
        ':total'       => $fields['examination'],
        ':grade'       => $grade['grade'],
        ':remark'      => $grade['remark'],
        ':admin'       => $adminUserId,
    ]);
}

$positions = classResultPositions($classId, $sessionId, $termId);
check('every enrolled student has a position', count($positions) === 6, 'got ' . count($positions));

check('the first student is 1st', ($positions[$studentIds[0]]['position'] ?? null) === 1, json_encode($positions[$studentIds[0]] ?? null));
check('the second student, tied on 85, is also 1st', ($positions[$studentIds[1]]['position'] ?? null) === 1, json_encode($positions[$studentIds[1]] ?? null));
check('the third student, on 80, is 3rd', ($positions[$studentIds[2]]['position'] ?? null) === 3, json_encode($positions[$studentIds[2]] ?? null));
check('the fourth student is 4th', ($positions[$studentIds[3]]['position'] ?? null) === 4, json_encode($positions[$studentIds[3]] ?? null));

$summary = studentResultSummary($studentIds[0], $sessionId, $termId);
check('the top student has two counted subjects', $summary['subjects'] === 2, (string) $summary['subjects']);
check('their overall total is 85', (float) $summary['overall_total'] === 85.0, (string) $summary['overall_total']);
check('their average is 42.5', (float) $summary['average'] === 42.5, (string) $summary['average']);
check('they passed one subject', $summary['passed'] === 1, (string) $summary['passed']);
check('they failed one subject', $summary['failed'] === 1, (string) $summary['failed']);

$bottom = studentResultSummary($studentIds[5], $sessionId, $termId);
check('the last student failed both subjects', $bottom['failed'] === 2 && $bottom['passed'] === 0, json_encode([$bottom['failed'], $bottom['passed']]));

/* A draft must not move anybody. Student 5 is on 41 + 20 = 61, which is 5th.
   Removing the 20 point subject drops them to 41, below student 4's 68. */
$flipId = (int) findResultFor($studentIds[4], $otherSubjectId, $sessionId, $termId)['id'];
$pdo->prepare('UPDATE results SET status = "draft" WHERE id = :id')->execute([':id' => $flipId]);

$moved = classResultPositions($classId, $sessionId, $termId);
check(
    'turning a counted result back to draft drops the student a place',
    ($moved[$studentIds[4]]['position'] ?? null) === 6,
    json_encode($moved[$studentIds[4]] ?? null)
);
check('the dropped subject is no longer counted', ($moved[$studentIds[4]]['subjects'] ?? null) === 1);
check('the dropped subject is no longer in the total', (float) $moved[$studentIds[4]]['overall_total'] === 41.0, json_encode($moved[$studentIds[4]] ?? null));
check('nobody else was moved', ($moved[$studentIds[3]]['position'] ?? null) === 4);

/* Restore it. */
$pdo->prepare('UPDATE results SET status = "approved" WHERE id = :id')->execute([':id' => $flipId]);

$restored = classResultPositions($classId, $sessionId, $termId);
check('re-approving restores the position', ($restored[$studentIds[4]]['position'] ?? null) === 5, json_encode($restored[$studentIds[4]] ?? null));
check('the first student is still 1st', ($restored[$studentIds[0]]['position'] ?? null) === 1);
check('the tie is still 1st, 1st, 3rd', ($restored[$studentIds[1]]['position'] ?? null) === 1 && ($restored[$studentIds[2]]['position'] ?? null) === 3);

/* ------------------------------------------------------------------ */
heading('10. History and audit trail');

$trail = resultHistory($resultId);
$actions = array_map(static function (array $row): string {
    return (string) $row['action'];
}, $trail);

foreach (['created', 'updated', 'submitted', 'returned', 'submitted', 'approved', 'locked', 'unlocked'] as $expected) {
    check("the history records a '{$expected}' step", in_array($expected, $actions, true), implode(',', $actions));
}

check('the history is in chronological order', $trail !== [] && $trail[0]['created_at'] <= $trail[count($trail) - 1]['created_at']);
check(
    'the return reason is in the history',
    (bool) array_filter($trail, static function (array $row): bool {
        return $row['action'] === 'returned' && $row['reason'] !== null;
    })
);
check(
    'the unlock reason is in the history',
    (bool) array_filter($trail, static function (array $row): bool {
        return $row['action'] === 'unlocked' && $row['reason'] !== null;
    })
);

$audit = (int) dbValue('SELECT COUNT(*) FROM audit_logs WHERE entity_type IN ("result", "result_sheet")');
check('the audit log has entries', $audit > 0, "count={$audit}");
check('audit entries carry an ip address', (int) dbValue('SELECT COUNT(*) FROM audit_logs WHERE ip_address IS NOT NULL') > 0);

/* ------------------------------------------------------------------ */
heading('11. Filters and search');

$admin = signIn('admin');

$listing = listResults(['class_id' => $classId, 'session_id' => $sessionId, 'term_id' => $termId, 'per_page' => 100]);
check('filtering by class and term returns both subjects', count($listing['rows']) === 12, 'got ' . count($listing['rows']));

$listing = listResults(['class_id' => $classId, 'session_id' => $sessionId, 'term_id' => $termId, 'subject_id' => $subjectId, 'per_page' => 100]);
check('adding the subject filter narrows it to one sheet', count($listing['rows']) === 6, 'got ' . count($listing['rows']));

/* The status filter must agree with the database whatever state the run left
   the sheet in, so it is checked against a live count rather than a guess. */
foreach (resultStatuses() as $statusOption) {
    $expected = (int) dbValue('SELECT COUNT(*) FROM results WHERE status = :s', [':s' => $statusOption]);
    $listing  = listResults(['status' => $statusOption, 'per_page' => 100]);

    check(
        "filtering by '{$statusOption}' returns exactly those rows",
        count($listing['rows']) === $expected && $listing['paging']['total'] === $expected,
        "list=" . count($listing['rows']) . " db={$expected}"
    );
}

/* Put one result back into submitted so the review queue is populated on the
   screens after the run. */
$requeueId = (int) findResultFor($studentIds[0], $subjectId, $sessionId, $termId)['id'];
$pdo->prepare('UPDATE results SET status = "submitted", approved_at = NULL, approved_by = NULL WHERE id = :id')
    ->execute([':id' => $requeueId]);

$listing = listResults(['status' => 'submitted', 'per_page' => 100]);
check('a re-queued result shows up under submitted', count($listing['rows']) === 1, 'got ' . count($listing['rows']));

$listing = listResults(['search' => 'Aisha', 'per_page' => 100]);
check('search by student name works', count($listing['rows']) >= 1, 'got ' . count($listing['rows']));

$listing = listResults(['search' => 'ADM/2026/0001', 'per_page' => 100]);
check('search by admission number works', count($listing['rows']) >= 1, 'got ' . count($listing['rows']));

$listing = listResults(['search' => 'English', 'per_page' => 100]);
check('search by subject works', count($listing['rows']) >= 1, 'got ' . count($listing['rows']));

$listing = listResults(['search' => 'Primary 1', 'per_page' => 100]);
check('search by class works', count($listing['rows']) >= 1, 'got ' . count($listing['rows']));

$listing = listResults(['search' => 'Amina', 'per_page' => 100]);
check('search by teacher works on the admin screen', count($listing['rows']) >= 1, 'got ' . count($listing['rows']));

$listing = listResults(['section' => 'Secondary', 'per_page' => 100]);
check('filtering by a section with no results returns nothing', count($listing['rows']) === 0);

$counts = resultStatusCounts(['class_id' => $classId, 'session_id' => $sessionId, 'term_id' => $termId]);
check('the status counts add up to the sheet total', array_sum(['draft' => $counts['draft'], 'submitted' => $counts['submitted'], 'approved' => $counts['approved'], 'locked' => $counts['locked']]) === 12, json_encode($counts));

/* ------------------------------------------------------------------ */
heading('12. The sheet the teacher actually sees');

$sheet = resultSheet($classId, $subjectId, $sessionId, $termId);
check('the sheet lists all six students', count($sheet) === 6, 'got ' . count($sheet));
check('every roster row is present even with no result yet', count(array_filter($sheet, static function (array $r): bool {
    return $r['result_id'] !== null;
})) === 6);

/* ------------------------------------------------------------------ */
heading('Result');

echo '  ' . $passed . ' passed, ' . $failed . ' failed' . PHP_EOL . PHP_EOL;

exit($failed === 0 ? 0 : 1);
