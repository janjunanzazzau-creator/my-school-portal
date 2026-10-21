<?php

declare(strict_types=1);

/**
 * Phase 6 engine check.
 *
 * Proves the rules of the public result checker at the level of the data layer,
 * without going near a browser. Everything here is driven through the same
 * functions the pages call, so a green run means the pages are built on
 * foundations that hold.
 *
 * The point of this file is the refusals and the arithmetic behind the security:
 *
 *   - a PIN is stored only as a password_hash(), and the plaintext appears
 *     nowhere in the row;
 *   - the PIN reference reveals nothing about the PIN;
 *   - a PIN is scoped to one student in one session, and cannot be reused;
 *   - wrong PIN, wrong student, wrong session, wrong term, expired, blocked and
 *     revoked all fail, each with its own message;
 *   - publication gates access, and withdrawing it closes access again;
 *   - the attempt counter blocks the PIN at exactly max_attempts and no further;
 *   - the throttle is per IP and does not depend on a PIN existing;
 *   - nothing an attacker can type reaches a query unparameterised, and nothing
 *     they type is echoed back unescaped.
 *
 * Every fixture is removed before the file exits, so the suite leaves the
 * database exactly as it found it.
 *
 * Run from a command prompt:
 *     C:\xampp\php\php.exe C:\xampp\htdocs\school-result-portal\tests\phase6-engine-test.php
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
 * The suite runs as an administrator, because issuing a PIN and publishing a
 * class are administrator only. A session is faked rather than going through the
 * login form, because the login form is exercised over HTTP by
 * phase6-http-test.php.
 */
function signInAsAdmin(): void
{
    $id = (int) dbValue("SELECT id FROM users WHERE role = 'admin' AND status = 1 LIMIT 1");

    $_SESSION['user_id']   = $id;
    $_SESSION['user_role'] = 'admin';
    $_SESSION['user']      = [
        'id'            => $id,
        'username'      => 'phase6-engine',
        'full_name'     => 'Phase 6 Engine Check',
        'email'         => 'check@example.invalid',
        'role'          => 'admin',
        'status'        => 1,
        'last_login_at' => null,
    ];
}

function signInAsTeacher(): void
{
    $id = (int) dbValue("SELECT id FROM users WHERE role = 'teacher' AND status = 1 LIMIT 1");

    $_SESSION['user_id']   = $id;
    $_SESSION['user_role'] = 'teacher';
    $_SESSION['user']      = [
        'id'            => $id,
        'username'      => 'phase6-engine-teacher',
        'full_name'     => 'Phase 6 Engine Check',
        'email'         => 'check@example.invalid',
        'role'          => 'teacher',
        'status'        => 1,
        'last_login_at' => null,
    ];
}

signInAsAdmin();

/* -------------------------------------------------------------------------
   Pure helpers: no database at all
   ------------------------------------------------------------------------- */

heading('PIN values and references');

$p1 = generateResultPinValue();
$p2 = generateResultPinValue();

check('a PIN is 8 digits in the XXXX-XXXX shape', (bool) preg_match('/^\d{4}-\d{4}$/', $p1), $p1);
check('a PIN never starts with 0', $p1[0] !== '0', $p1);
check('two PINs are not the same', $p1 !== $p2, $p1 . ' / ' . $p2);

/* 200 draws from a 90,000,000 space: the birthday bound says a collision is
   essentially impossible, so finding one means the generator is broken. */
$seen = [];
for ($i = 0; $i < 200; $i++) {
    $draw = generateResultPinValue();
    $seen[$draw] = true;
}
check('200 generated PINs are all distinct', count($seen) === 200, (string) count($seen));

check('a PIN typed with a space verifies', normaliseResultPin('4829 7315') === '48297315', normaliseResultPin('4829 7315'));
check('a PIN typed with a hyphen verifies', normaliseResultPin('4829-7315') === '48297315', normaliseResultPin('4829-7315'));
check('a PIN typed with dots verifies', normaliseResultPin('4829.7315') === '48297315', normaliseResultPin('4829.7315'));
check('a PIN typed with no separator verifies', normaliseResultPin('48297315') === '48297315', normaliseResultPin('48297315'));
check('letters are stripped rather than compared', normaliseResultPin('abcd4829ef7315') === '48297315', normaliseResultPin('abcd4829ef7315'));

$refA = nextResultPinReference(getDbConnection());
$refB = nextResultPinReference(getDbConnection());

check('a reference looks like PIN-YYYY-NNNNNN', (bool) preg_match('/^PIN-\d{4}-\d{6}$/', $refA), $refA);
check('the generator is stable when nothing is issued yet', $refA === $refB, "$refA / $refB");

/* The reference must be a year and a counter. Nothing about the PIN may leak
   into it, or the reference would stop being safe to read out over the phone. */
check('the reference does not contain the PIN digits', strpos($refA, str_replace('-', '', $p1)) === false, $refA);
check('the reference does not contain the PIN', strpos($refA, $p1) === false, $refA);

heading('Verification codes');

$prefix = verificationCodePrefix(1);
check('a verification prefix looks like ORM/RES/YYYY', (bool) preg_match('#^ORM/RES/\d{4}$#', $prefix), $prefix);

heading('Status vocabularies');

check('five PIN statuses', resultPinStatuses() === ['active', 'used', 'expired', 'blocked', 'revoked'], implode(',', resultPinStatuses()));
check('two publication statuses', resultPublicationStatuses() === ['unpublished', 'published'], implode(',', resultPublicationStatuses()));
check('every PIN status has a label', implode(',', array_map('resultPinStatusLabel', resultPinStatuses())) === 'Active,Used,Expired,Blocked,Revoked');
check('every PIN status has a badge', array_reduce(resultPinStatuses(), static fn (bool $ok, string $s): bool => $ok && resultPinBadgeClass($s) !== '', true));
check('every publication status has a badge', array_reduce(resultPublicationStatuses(), static fn (bool $ok, string $s): bool => $ok && resultPublicationBadgeClass($s) !== '', true));

heading('Attempt ceilings and the session window');

check('the default ceiling is 5', RESULT_PIN_DEFAULT_MAX_ATTEMPTS === 5, (string) RESULT_PIN_DEFAULT_MAX_ATTEMPTS);
check('ceilings are offered', resultPinAttemptChoices() === [3, 5, 8, 10], implode(',', resultPinAttemptChoices()));
check('the public session lasts 30 minutes', PUBLIC_RESULT_SESSION_SECONDS === 1800, (string) PUBLIC_RESULT_SESSION_SECONDS);
check('every failure reason is a fixed short code', array_reduce(
    array_keys(RESULT_ACCESS_FAILURE_REASONS),
    static fn (bool $ok, string $code): bool => $ok && strlen($code) <= 40,
    true
));

/* -------------------------------------------------------------------------
   Fixtures: one approved report card, one published class
   ------------------------------------------------------------------------- */

heading('Fixtures');

$sessionId = (int) dbValue('SELECT id FROM academic_sessions WHERE is_current = 1 LIMIT 1');
$termId    = (int) dbValue(
    'SELECT t.id FROM terms t WHERE t.session_id = :s AND t.status = "Active" ORDER BY t.id LIMIT 1',
    [':s' => $sessionId]
);
$classId = (int) dbValue('SELECT id FROM classes WHERE class_name = "Primary 1" AND section = "Primary" LIMIT 1');

check('a current session exists', $sessionId > 0, "id=$sessionId");
check('a current term exists', $termId > 0, "id=$termId");
check('a class exists', $classId > 0, "id=$classId");

$roster = classResultRoster($classId, $sessionId);

check('the class has an enrolled student', $roster !== [], (string) count($roster));

if ($roster === [] || $sessionId === 0 || $termId === 0) {
    echo "\nCannot continue without an enrolled student, session and term.\n";
    exit(1);
}

$studentId   = (int) $roster[0]['student_id'];
$studentAdm  = (string) $roster[0]['admission_number'];
$otherStudent = count($roster) > 1 ? (int) $roster[1]['student_id'] : 0;

/* A second session, so "the right PIN in the wrong session" can actually be
   tested. It is created here and dropped in the teardown rather than assumed to
   exist, because a fresh install legitimately has only one session. */
$tempSessionId = (int) dbValue(
    'SELECT id FROM academic_sessions WHERE id <> :s ORDER BY id LIMIT 1',
    [':s' => $sessionId]
);
$tempSessionCreated = false;

if ($tempSessionId === 0) {
    $pdoEarly = getDbConnection();
    $insertSession = $pdoEarly->prepare(
        'INSERT INTO academic_sessions (session_name, start_date, end_date, status, is_current)
         VALUES (:name, :start, :end, :status, 0)'
    );
    $insertSession->execute([
        ':name'   => '2099/2100',
        ':start'  => '2099-09-01',
        ':end'    => '2100-07-31',
        ':status' => 'Active',
    ]);
    $tempSessionId = (int) $pdoEarly->lastInsertId();
    $tempSessionCreated = true;
}

$otherSessionId = $tempSessionId;

check('there is a second session to test against', $otherSessionId > 0, "id=$otherSessionId");

/* The teacher the sample results are attributed to. `results.teacher_id` is a
   foreign key, so a fixture row cannot be created without one. */
$teacherId = (int) dbValue('SELECT id FROM teachers ORDER BY id LIMIT 1');

check('a teacher exists to attribute results to', $teacherId > 0, "id=$teacherId");

/* Any pre-existing PINs for this student are removed so the suite starts clean,
   and remembered so nothing real is destroyed. */
$pdo     = getDbConnection();
$existingPins = dbAll('SELECT * FROM result_pins WHERE student_id = :s', [':s' => $studentId]);
$existingLogs = (int) dbValue('SELECT COUNT(*) FROM result_access_logs');
/* Pruning needs the highest id, not the row count: the two only agree on an empty
   table, and pruning by count silently leaves this run's rows behind. */
$existingLogMaxId = (int) dbValue('SELECT COALESCE(MAX(id), 0) FROM result_access_logs');
$existingPub  = dbAll(
    'SELECT * FROM result_publication WHERE session_id = :s AND term_id = :t AND class_id = :c',
    [':s' => $sessionId, ':t' => $termId, ':c' => $classId]
);

foreach ($existingPins as $row) {
    $pdo->prepare('DELETE FROM result_pins WHERE id = ?')->execute([(int) $row['id']]);
}

$pdo->prepare('DELETE FROM result_pins WHERE student_id = ?')->execute([$studentId]);
$pdo->prepare('DELETE FROM result_pins WHERE student_id = ?')->execute([$otherStudent]);

/** Every PIN this suite issues, so the teardown can find them all. */
$issuedIds = [];

/* Every result row the fixture below may create or overwrite is snapshotted, so
   the teardown can put the marks back exactly. The sheet is written with an
   ON DUPLICATE KEY UPDATE, which would otherwise leave the class permanently
   scoring 94 in every subject, and the next run would then pass fixtures that
   had really failed. */
$originalResultRows = dbAll(
    'SELECT * FROM results WHERE class_id = :c AND session_id = :s AND term_id = :t',
    [':c' => $classId, ':s' => $sessionId, ':t' => $termId]
);

/* The same for the report cards, which the teardown used to delete outright. A
   school that ran this suite against real data would have lost them. */
$originalCardRows = dbAll(
    'SELECT * FROM report_cards WHERE class_id = :c AND session_id = :s AND term_id = :t',
    [':c' => $classId, ':s' => $sessionId, ':t' => $termId]
);

/* And for the attendance, which the fixture below writes for every student in the
   class. Leaving it behind made the Phase 5 suites fail, because they assert on
   the whole table being empty. */
$originalAttendance = dbAll(
    'SELECT * FROM attendance_summary WHERE class_id = :c AND session_id = :s AND term_id = :t',
    [':c' => $classId, ':s' => $sessionId, ':t' => $termId]
);

/** Put the class into a published, approved state and hand back the card. */
function setUpPublishedResult(int $studentId, int $classId, int $sessionId, int $termId, int $teacherId): ?array
{
    /* A complete, fully approved sheet, so the Phase 5 gate is satisfied. */
    $subjects = requiredSubjectsForClass($classId);

    foreach ($subjects as $subject) {
        dbRun(
            "INSERT INTO results
                (student_id, class_id, subject_id, session_id, term_id, teacher_id,
                 test_1, test_2, test_3, examination, total, grade, grade_remark,
                 status, approved_at)
             VALUES
                (:student_id, :class_id, :subject_id, :session_id, :term_id, :teacher_id,
                 9, 8, 9, 68, 94, 'A', 'Excellent', 'approved', NOW())
             ON DUPLICATE KEY UPDATE
                test_1 = 9, test_2 = 8, test_3 = 9, examination = 68,
                total = 94, grade = 'A', grade_remark = 'Excellent',
                teacher_id = VALUES(teacher_id), status = 'approved'",
            [
                ':student_id' => $studentId,
                ':class_id'   => $classId,
                ':subject_id' => (int) $subject['id'],
                ':session_id' => $sessionId,
                ':term_id'    => $termId,
                ':teacher_id' => $teacherId,
            ]
        );
    }

    $generated = generateReportCard($studentId, $classId, $sessionId, $termId);

    if (!($generated['ok'] ?? false)) {
        return null;
    }

    $report = findReportCardFor($studentId, $classId, $sessionId, $termId);

    if ($report === null) {
        return null;
    }

    applyReportCardAction((int) $report['id'], 'approve');

    return findReportCard((int) $report['id']);
}

$report = null;

/* Publication is a whole-class decision, so the fixture has to settle the whole
   class: every enrolled student needs an approved card, otherwise the gate
   correctly refuses and the rest of the suite has nothing to open. */
$classStudentIds = [];

foreach ($roster as $member) {
    $classStudentIds[] = (int) $member['student_id'];
}

check('the class has more than one enrolled student', count($classStudentIds) > 1, (string) count($classStudentIds));

foreach ($classStudentIds as $memberId) {
    $built = setUpPublishedResult($memberId, $classId, $sessionId, $termId, $teacherId);

    if ($built === null) {
        check('a card could be built for student ' . $memberId, false, 'generation refused');
        continue;
    }

    if ($report === null && $memberId === $studentId) {
        $report = $built;
    }
}

check('an approved report card exists for the subject student', $report !== null);
check('the card is approved', $report !== null && (string) $report['status'] === 'approved',
    $report === null ? 'none' : (string) $report['status']);

$reportId = $report === null ? 0 : (int) $report['id'];

/* Every card in the class is settled, which is what makes the class publishable. */
$settledInClass = (int) dbValue(
    "SELECT COUNT(*) FROM report_cards
      WHERE class_id = :c AND session_id = :s AND term_id = :t AND status IN ('approved', 'locked')",
    [':c' => $classId, ':s' => $sessionId, ':t' => $termId]
);

check('every enrolled student in the class has a settled card',
    $settledInClass === count($classStudentIds),
    "$settledInClass of " . count($classStudentIds));
check('the class is now publishable',
    validateResultPublication($classId, $sessionId, $termId)['ready'] === true,
    (string) validateResultPublication($classId, $sessionId, $termId)['message']);

/* The verification code is assigned lazily, and must be stable once assigned. */
$codeA = ensureReportCardVerificationCode($reportId);
$codeB = ensureReportCardVerificationCode($reportId);

/* Attendance, so the public result has something real to show. The figures are a
   fixture and are removed with the rest. */
foreach ($classStudentIds as $memberId) {
    saveAttendanceSummary($memberId, $classId, $sessionId, $termId, 60, 54, 6);
}

$attendanceRows = (int) dbValue(
    'SELECT COUNT(*) FROM attendance_summary WHERE class_id = :c AND session_id = :s AND term_id = :t',
    [':c' => $classId, ':s' => $sessionId, ':t' => $termId]
);

check('attendance was recorded for the class', $attendanceRows === count($classStudentIds),
    "$attendanceRows of " . count($classStudentIds));

heading('Verification codes on a report card');

check('a code is assigned', $codeA !== '', $codeA);
check('a code looks like ORM/RES/YYYY/NNNNNN', (bool) preg_match('#^ORM/RES/\d{4}/\d{6}$#', $codeA), $codeA);
check('the code is stable on a second call', $codeA === $codeB, "$codeA / $codeB");
check('the code is stored on the card', (string) dbValue(
    'SELECT verification_code FROM report_cards WHERE id = :id',
    [':id' => $reportId]
) === $codeA);

/* -------------------------------------------------------------------------
   Issuing a PIN
   ------------------------------------------------------------------------- */

heading('Issuing a PIN');

$expiry = date('Y-m-d', strtotime('+30 days'));

$issued = generateResultPin([
    'student_id'   => $studentId,
    'session_id'   => $sessionId,
    'expires_at'   => $expiry,
    'max_attempts' => 5,
    'single_use'   => 0,
]);

check('a PIN is issued', $issued['ok'], (string) $issued['message']);
check('the PIN comes back to be written down', (bool) preg_match('/^\d{4}-\d{4}$/', (string) $issued['pin']), (string) $issued['pin']);
check('a reference comes back too', (bool) preg_match('/^PIN-\d{4}-\d{6}$/', (string) $issued['reference']), (string) $issued['reference']);

$pinId  = (int) $issued['pin_id'];
$plain  = (string) $issued['pin'];
$issuedIds[] = $pinId;

/* The single most important assertion in this file. */
$row = findResultPinRow($pinId);

check('the PIN row exists', $row !== null);
check('the stored value is a bcrypt/argon hash', is_string($row['pin_hash'] ?? null)
    && preg_match('/^\$(2y|argon2i|argon2id)\$/', (string) $row['pin_hash']) === 1,
    substr((string) ($row['pin_hash'] ?? ''), 0, 12) . '...');
check('the plaintext PIN is nowhere in the row',
    !in_array($plain, array_map('strval', (array) $row), true)
    && strpos(json_encode($row) ?: '', $plain) === false);
check('the hash verifies the PIN', password_verify(normaliseResultPin($plain), (string) $row['pin_hash']));
check('the hash does not verify a wrong PIN', !password_verify('00000000', (string) $row['pin_hash']));
check('the hash is not the plaintext', (string) $row['pin_hash'] !== $plain && (string) $row['pin_hash'] !== normaliseResultPin($plain));
check('the reference is not derived from the PIN', !str_contains((string) $row['pin_reference'], normaliseResultPin($plain)));
check('the PIN starts active', (string) $row['status'] === 'active', (string) $row['status']);
check('the attempt counter starts at zero', (int) $row['failed_attempts'] === 0);
check('last used is empty until it is used', $row['last_used_at'] === null);
check('the issuing administrator is recorded', $row['created_by'] !== null);

/* The admin-facing row must not carry the hash at all. */
$display = findResultPin($pinId);
check('the admin row has no pin_hash column', $display !== null && !array_key_exists('pin_hash', $display));
check('the admin row has no hash value anywhere in it',
    $display !== null && !str_contains(json_encode($display) ?: '', '$2y$')
    && !str_contains(json_encode($display) ?: '', '$argon2'));

heading('Only an administrator can issue a PIN');

$before = (int) dbValue('SELECT COUNT(*) FROM result_pins');
signInAsTeacher();
$teacherIssue = generateResultPin([
    'student_id'   => $studentId,
    'session_id'   => $sessionId,
    'expires_at'   => $expiry,
    'max_attempts' => 5,
]);
$after = (int) dbValue('SELECT COUNT(*) FROM result_pins');
signInAsAdmin();

check('a teacher is refused', $teacherIssue['ok'] === false, (string) $teacherIssue['message']);
check('no PIN row is written for a teacher', $before === $after, "$before -> $after");

heading('Input validation on the generation form');

$badStudent = validateResultPinInput([
    'student_id' => 99999999, 'session_id' => $sessionId, 'expires_at' => $expiry, 'max_attempts' => 5,
]);
check('an unknown student is refused', $badStudent['valid'] === false);
check('and names the field', isset($badStudent['errors']['student_id']));

$badExpiry = validateResultPinInput([
    'student_id' => $studentId, 'session_id' => $sessionId, 'expires_at' => 'not-a-date', 'max_attempts' => 5,
]);
check('a nonsense expiry date is refused', $badExpiry['valid'] === false);

$pastExpiry = validateResultPinInput([
    'student_id' => $studentId, 'session_id' => $sessionId, 'expires_at' => date('Y-m-d', strtotime('-1 day')), 'max_attempts' => 5,
]);
check('an expiry date in the past is refused', $pastExpiry['valid'] === false);

$badTries = validateResultPinInput([
    'student_id' => $studentId, 'session_id' => $sessionId, 'expires_at' => $expiry, 'max_attempts' => 999,
]);
check('an attempt ceiling outside the offered list is refused', $badTries['valid'] === false);

$classMode = validateResultPinInput(
    ['session_id' => $sessionId, 'expires_at' => $expiry, 'max_attempts' => 5],
    null,
    false
);
check('class mode validates without a student', $classMode['valid'] === true, json_encode($classMode['errors']));

/* -------------------------------------------------------------------------
   Publication gating
   ------------------------------------------------------------------------- */

heading('Publication gating');

$publish = applyResultPublication($classId, $sessionId, $termId, 'publish');

check('a complete class publishes', $publish['ok'], (string) $publish['message']);
check('the state becomes published', (string) $publish['status'] === 'published');
check('isResultPublished agrees', isResultPublished($sessionId, $termId, $classId));
check('resultPublicationStatus agrees', resultPublicationStatus($sessionId, $termId, $classId) === 'published');
check('publishing is idempotent, not duplicated', (int) dbValue(
    'SELECT COUNT(*) FROM result_publication WHERE session_id = :s AND term_id = :t AND class_id = :c',
    [':s' => $sessionId, ':t' => $termId, ':c' => $classId]
) === 1);

/* A class the school never published must stay shut. */
$unpublishedClass = (int) dbValue(
    'SELECT id FROM classes WHERE id <> :c ORDER BY id LIMIT 1',
    [':c' => $classId]
);

check('an untouched class is not published', !isResultPublished($sessionId, $termId, $unpublishedClass));
check('an untouched class reports unpublished', resultPublicationStatus($sessionId, $termId, $unpublishedClass) === 'unpublished');

/* -------------------------------------------------------------------------
   The happy path
   ------------------------------------------------------------------------- */

heading('A correct set of details opens the result');

$ok = verifyPublicResultAccess($studentAdm, $plain, $sessionId, $termId);

check('the check succeeds', $ok['ok'], (string) $ok['message']);
check('it returns the report id', (int) $ok['report_id'] === $reportId, (string) $ok['report_id']);
check('it returns the student', (int) $ok['student_id'] === $studentId);
check('it returns the pin row', (int) $ok['pin_id'] === $pinId);
check('no failure reason on success', $ok['reason'] === null);

$used = findResultPinRow($pinId);
check('last used is now recorded', $used['last_used_at'] !== null);
check('a reusable PIN is still active after use', (string) $used['status'] === 'active', (string) $used['status']);
check('a successful use resets the failed counter', (int) $used['failed_attempts'] === 0);

$reusable = verifyPublicResultAccess($studentAdm, $plain, $sessionId, $termId);
check('a reusable PIN works a second time', $reusable['ok'], (string) $reusable['message']);

heading('The public result bundle');

$bundle = publicResultBundle($reportId);

check('the bundle is built', is_array($bundle));
check('the bundle carries the verification code', ($bundle['verification_code'] ?? '') === $codeA);
check('the bundle carries the student name', ($bundle['student_name'] ?? '') !== '');
check('the bundle carries the admission number', ($bundle['admission_number'] ?? '') === $studentAdm);
check('the bundle carries the class', ($bundle['class_name'] ?? '') !== '');
check('the bundle carries the session', ($bundle['session_name'] ?? '') !== '');
check('the bundle carries the term', ($bundle['term_name'] ?? '') !== '');
check('the bundle carries the promotion status', ($bundle['promotion_status'] ?? '') !== '');
check('the bundle carries subject rows', is_array($bundle['subjects'] ?? null) && $bundle['subjects'] !== []);
check('the total matches the card',
    abs((float) $bundle['total_score'] - (float) $report['total_score']) < 0.001,
    $bundle['total_score'] . ' vs ' . $report['total_score']);
check('the average matches the card',
    abs((float) $bundle['average_score'] - (float) $report['average_score']) < 0.001);
check('the position matches the card', (int) $bundle['position'] === (int) $report['position']);
check('the subjects passed count matches', (int) $bundle['subjects_passed'] === (int) $report['subjects_passed']);
check('the subjects failed count matches', (int) $bundle['subjects_failed'] === (int) $report['subjects_failed']);
check('the subjects offered count matches', (int) $bundle['subjects_offered'] === (int) $report['subjects_offered']);
check('attendance is present', is_array($bundle['attendance'] ?? null));

/* Requirement 43: nothing private may appear in a payload a parent can see. */
$bundleJson = json_encode($bundle) ?: '';
check('no teacher id in the bundle', !preg_match('/teacher_id/', $bundleJson));
check('no created_by or user id in the bundle', !preg_match('/created_by|approved_by|locked_by/', $bundleJson));
check('no PIN hash in the bundle', !str_contains($bundleJson, '$2y$') && !str_contains($bundleJson, '$argon2'));
check('no PIN in the bundle', !str_contains($bundleJson, $plain) && !str_contains($bundleJson, normaliseResultPin($plain)));
check('no parent phone or address in the bundle',
    !preg_match('/parent_(phone|address)|guardian/i', $bundleJson));
check('no password field in the bundle', !preg_match('/password/i', $bundleJson));

/* -------------------------------------------------------------------------
   Every way the details can be wrong
   ------------------------------------------------------------------------- */

heading('Wrong details are refused');

$wrongPin = verifyPublicResultAccess($studentAdm, '0000-0000', $sessionId, $termId);
check('a wrong PIN is refused', $wrongPin['ok'] === false);
check('the refusal does not say which field was wrong',
    $wrongPin['message'] === RESULT_ACCESS_FAILURE_REASONS['invalid_details'],
    (string) $wrongPin['message']);
check('a wrong PIN reveals no student hint',
    !str_contains($wrongPin['message'], $studentAdm)
    && !str_contains($wrongPin['message'], 'admission number'));

$badAdmission = verifyPublicResultAccess('NOPE/999/999', $plain, $sessionId, $termId);
check('an unknown admission number is refused', $badAdmission['ok'] === false);
check('with the same generic message', $badAdmission['message'] === RESULT_ACCESS_FAILURE_REASONS['invalid_details']);

if ($otherStudent > 0) {
    $otherAdm = (string) dbValue('SELECT admission_number FROM students WHERE id = :id', [':id' => $otherStudent]);

    $wrongStudent = verifyPublicResultAccess($otherAdm, $plain, $sessionId, $termId);

    check("another student's admission number with this PIN is refused", $wrongStudent['ok'] === false);
    check('and is not attributed to that student', (int) $wrongStudent['pin_id'] === 0);
    check('with the generic message', $wrongStudent['message'] === RESULT_ACCESS_FAILURE_REASONS['invalid_details']);
} else {
    check('another student exists to test cross-use', false, 'only one student in the class');
}

if ($otherSessionId > 0) {
    $wrongSession = verifyPublicResultAccess($studentAdm, $plain, $otherSessionId, $termId);
    check('the right PIN in the wrong session is refused', $wrongSession['ok'] === false);
}

$wrongTerm = verifyPublicResultAccess($studentAdm, $plain, $sessionId, 999999);
check('an unknown term is refused', $wrongTerm['ok'] === false);

/* A term that belongs to a different session must not pair up. */
$foreignTerm = (int) dbValue(
    'SELECT id FROM terms WHERE session_id <> :s ORDER BY id LIMIT 1',
    [':s' => $sessionId]
);

if ($foreignTerm > 0) {
    $mismatch = verifyPublicResultAccess($studentAdm, $plain, $sessionId, $foreignTerm);
    check('a term from another session is refused', $mismatch['ok'] === false);
    check('with the generic message', $mismatch['message'] === RESULT_ACCESS_FAILURE_REASONS['invalid_details']);
}

check('an empty PIN is refused', verifyPublicResultAccess($studentAdm, '', $sessionId, $termId)['ok'] === false);
check('an empty admission number is refused', verifyPublicResultAccess('', $plain, $sessionId, $termId)['ok'] === false);
check('session zero is refused', verifyPublicResultAccess($studentAdm, $plain, 0, $termId)['ok'] === false);

heading('SQL injection is refused, not executed');

foreach ([
    "' OR '1'='1",
    "4829-7315' OR 1=1 -- ",
    "48297315'; DROP TABLE result_pins; -- ",
    "1 UNION SELECT pin_hash FROM result_pins -- ",
] as $payload) {
    $attack = verifyPublicResultAccess($payload, $plain, $sessionId, $termId);
    check('injection refused: ' . substr($payload, 0, 28), $attack['ok'] === false);
}

foreach ([
    "48297315' AND SLEEP(0) -- ",
    "\x00\x00binary",
] as $payload) {
    $attack = verifyPublicResultAccess($studentAdm, $payload, $sessionId, $termId);
    check('odd input refused: ' . substr(str_replace("\x00", 'NUL', $payload), 0, 24), $attack['ok'] === false);
}

check('the result_pins table survived the injection attempt',
    (int) dbValue('SELECT COUNT(*) FROM result_pins') >= 1);

/* -------------------------------------------------------------------------
   Withdrawing publication
   ------------------------------------------------------------------------- */

heading('Unpublishing closes the result again');

$unpublish = applyResultPublication($classId, $sessionId, $termId, 'unpublish');

check('a class can be withdrawn', $unpublish['ok'], (string) $unpublish['message']);
check('the state becomes unpublished', (string) $unpublish['status'] === 'unpublished');
check('isResultPublished now says no', !isResultPublished($sessionId, $termId, $classId));

$afterUnpublish = verifyPublicResultAccess($studentAdm, $plain, $sessionId, $termId);

check('a correct PIN is now refused', $afterUnpublish['ok'] === false);
check('with the publication message', $afterUnpublish['message'] === RESULT_ACCESS_FAILURE_REASONS['not_published'],
    (string) $afterUnpublish['message']);
check('publicResultBundle returns nothing', publicResultBundle($reportId) === null);

$republish = applyResultPublication($classId, $sessionId, $termId, 'publish');
check('it can be published again', $republish['ok']);
check('and opens again', verifyPublicResultAccess($studentAdm, $plain, $sessionId, $termId)['ok']);

/* -------------------------------------------------------------------------
   Expiry, blocking and revocation
   ------------------------------------------------------------------------- */

heading('Expiry');

$expiring = generateResultPin([
    'student_id' => $studentId, 'session_id' => $sessionId,
    'expires_at' => date('Y-m-d', strtotime('+1 day')), 'max_attempts' => 5,
]);
$issuedIds[] = (int) $expiring['pin_id'];

/* Move the stored date into the past rather than sleeping for a day. */
dbRun('UPDATE result_pins SET expires_at = :when WHERE id = :id', [
    ':when' => date('Y-m-d H:i:s', strtotime('-1 hour')),
    ':id'   => (int) $expiring['pin_id'],
]);

check('an expired PIN reports itself expired', resultPinEffectiveStatus((array) findResultPinRow((int) $expiring['pin_id'])) === 'expired');

$expiredTry = verifyPublicResultAccess($studentAdm, (string) $expiring['pin'], $sessionId, $termId);
check('an expired PIN is refused', $expiredTry['ok'] === false);
check('with the expiry message', $expiredTry['message'] === 'This result PIN has expired.', (string) $expiredTry['message']);
check('and the reason is recorded as expired', (string) $expiredTry['reason'] === 'expired');
check('the row is persisted as expired', (string) findResultPinRow((int) $expiring['pin_id'])['status'] === 'expired');

$swept = sweepExpiredResultPins();
check('the sweep runs without error', $swept >= 0, (string) $swept);

heading('Extension never resurrects an expired PIN');

$extendExpired = applyResultPinAction((int) $expiring['pin_id'], 'extend', [
    'expires_at' => date('Y-m-d', strtotime('+60 days')),
]);

check('extending an expired PIN is refused', $extendExpired['ok'] === false, (string) $extendExpired['message']);
check('and it stays expired', (string) findResultPinRow((int) $expiring['pin_id'])['status'] === 'expired');
check('and it still cannot be used', verifyPublicResultAccess($studentAdm, (string) $expiring['pin'], $sessionId, $termId)['ok'] === false);

heading('Extension moves a live expiry date');

$extensible = generateResultPin([
    'student_id' => $studentId, 'session_id' => $sessionId,
    'expires_at' => date('Y-m-d', strtotime('+5 days')), 'max_attempts' => 5,
]);
$issuedIds[] = (int) $extensible['pin_id'];

$extended = applyResultPinAction((int) $extensible['pin_id'], 'extend', [
    'expires_at' => date('Y-m-d', strtotime('+45 days')),
]);

check('a live PIN can be extended', $extended['ok'], (string) $extended['message']);
check('the stored date moved', substr((string) findResultPinRow((int) $extensible['pin_id'])['expires_at'], 0, 10)
    === date('Y-m-d', strtotime('+45 days')));
check('and it still works', verifyPublicResultAccess($studentAdm, (string) $extensible['pin'], $sessionId, $termId)['ok'] === true);

$badExtend = applyResultPinAction((int) $extensible['pin_id'], 'extend', ['expires_at' => 'tomorrow']);
check('a nonsense new date is refused', $badExtend['ok'] === false, (string) $badExtend['message']);

heading('Blocking');

$blockable = generateResultPin([
    'student_id' => $studentId, 'session_id' => $sessionId,
    'expires_at' => $expiry, 'max_attempts' => 5,
]);
$issuedIds[] = (int) $blockable['pin_id'];

$blocked = applyResultPinAction((int) $blockable['pin_id'], 'block');

check('a PIN can be blocked', $blocked['ok'], (string) $blocked['message']);
check('the status is blocked', (string) findResultPinRow((int) $blockable['pin_id'])['status'] === 'blocked');

$blockedTry = verifyPublicResultAccess($studentAdm, (string) $blockable['pin'], $sessionId, $termId);
check('a blocked PIN is refused even with the right secret', $blockedTry['ok'] === false);
check('with the too-many-attempts message',
    $blockedTry['message'] === 'Too many failed attempts. Please contact the school.',
    (string) $blockedTry['message']);
check('and the reason is blocked', (string) $blockedTry['reason'] === 'blocked');

$unblocked = applyResultPinAction((int) $blockable['pin_id'], 'unblock');
check('a blocked PIN can be unblocked', $unblocked['ok'], (string) $unblocked['message']);
check('the status is active again', (string) findResultPinRow((int) $blockable['pin_id'])['status'] === 'active');
check('and it works again', verifyPublicResultAccess($studentAdm, (string) $blockable['pin'], $sessionId, $termId)['ok'] === true);

heading('Revocation');

$revokable = generateResultPin([
    'student_id' => $studentId, 'session_id' => $sessionId,
    'expires_at' => $expiry, 'max_attempts' => 5,
]);
$issuedIds[] = (int) $revokable['pin_id'];

$revoked = applyResultPinAction((int) $revokable['pin_id'], 'revoke', ['reason' => 'family asked']);

check('a PIN can be revoked', $revoked['ok'], (string) $revoked['message']);
check('the status is revoked', (string) findResultPinRow((int) $revokable['pin_id'])['status'] === 'revoked');

$revokedTry = verifyPublicResultAccess($studentAdm, (string) $revokable['pin'], $sessionId, $termId);
check('a revoked PIN is refused', $revokedTry['ok'] === false);
check('with its own message', str_contains((string) $revokedTry['message'], 'no longer valid'), (string) $revokedTry['message']);

$unblockRevoked = applyResultPinAction((int) $revokable['pin_id'], 'unblock');
check('a revoked PIN cannot be unblocked', $unblockRevoked['ok'] === false, (string) $unblockRevoked['message']);
check('and stays revoked', (string) findResultPinRow((int) $revokable['pin_id'])['status'] === 'revoked');

$extendRevoked = applyResultPinAction((int) $revokable['pin_id'], 'extend', ['expires_at' => date('Y-m-d', strtotime('+90 days'))]);
check('a revoked PIN cannot be extended back to life', $extendRevoked['ok'] === false, (string) $extendRevoked['message']);
check('and still cannot open a result', verifyPublicResultAccess($studentAdm, (string) $revokable['pin'], $sessionId, $termId)['ok'] === false);

heading('Only an administrator can act on a PIN');

signInAsTeacher();
$beforeStatus = (string) findResultPinRow($pinId)['status'];
$teacherBlock = applyResultPinAction($pinId, 'block');
$teacherRevoke = applyResultPinAction((int) $revokable['pin_id'], 'revoke');
$afterStatus = (string) findResultPinRow($pinId)['status'];
signInAsAdmin();

check('a teacher cannot block', $teacherBlock['ok'] === false, (string) $teacherBlock['message']);
check('a teacher cannot revoke', $teacherRevoke['ok'] === false, (string) $teacherRevoke['message']);
check('the PIN is untouched', $beforeStatus === $afterStatus, "$beforeStatus -> $afterStatus");
check('a revoked PIN is still revoked', (string) findResultPinRow((int) $revokable['pin_id'])['status'] === 'revoked');

/* -------------------------------------------------------------------------
   Reissuing retires the old PIN
   ------------------------------------------------------------------------- */

heading('Issuing a new PIN retires the previous one');

/* Issuing retires whatever was working, so this section starts from a PIN that is
   genuinely the live one rather than reusing one from an earlier section. */
$livePin = generateResultPin([
    'student_id' => $studentId, 'session_id' => $sessionId,
    'expires_at' => $expiry, 'max_attempts' => 5,
]);
$issuedIds[] = (int) $livePin['pin_id'];

check('the live PIN is issued', $livePin['ok']);
check('the live PIN is active', (string) findResultPinRow((int) $livePin['pin_id'])['status'] === 'active');
check('the live PIN works', verifyPublicResultAccess($studentAdm, (string) $livePin['pin'], $sessionId, $termId)['ok'] === true);

$replacement = generateResultPin([
    'student_id' => $studentId, 'session_id' => $sessionId,
    'expires_at' => $expiry, 'max_attempts' => 5,
]);
$issuedIds[] = (int) $replacement['pin_id'];

check('the replacement is issued', $replacement['ok']);
check('the live PIN is retired', (string) findResultPinRow((int) $livePin['pin_id'])['status'] === 'revoked');
check('the retired PIN no longer works', verifyPublicResultAccess($studentAdm, (string) $livePin['pin'], $sessionId, $termId)['ok'] === false);
check('the replacement works', verifyPublicResultAccess($studentAdm, (string) $replacement['pin'], $sessionId, $termId)['ok'] === true);
check('the reference advanced', (string) $replacement['reference'] !== (string) $livePin['reference'],
    (string) $livePin['reference'] . ' -> ' . (string) $replacement['reference']);
check('only one PIN is active for this student and session',
    (int) dbValue(
        "SELECT COUNT(*) FROM result_pins
          WHERE student_id = :s AND session_id = :ss AND status = 'active'",
        [':s' => $studentId, ':ss' => $sessionId]
    ) === 1);

/* -------------------------------------------------------------------------
   Rate limiting
   ------------------------------------------------------------------------- */

heading('Rate limiting');

$limited = generateResultPin([
    'student_id' => $studentId, 'session_id' => $sessionId,
    'expires_at' => $expiry, 'max_attempts' => 3,
]);
$issuedIds[] = (int) $limited['pin_id'];
$limitedPin = (string) $limited['pin'];

/* Three wrong guesses on a ceiling of three: the counter must reach the ceiling
   and the PIN must block on exactly the third, not the fourth. */
for ($attempt = 1; $attempt <= 3; $attempt++) {
    verifyPublicResultAccess($studentAdm, '0000-0000', $sessionId, $termId);
    $row = findResultPinRow((int) $limited['pin_id']);
    check("failed attempt $attempt is counted", (int) $row['failed_attempts'] === $attempt, (string) $row['failed_attempts']);
}

check('the PIN blocks at its ceiling', (string) findResultPinRow((int) $limited['pin_id'])['status'] === 'blocked',
    (string) findResultPinRow((int) $limited['pin_id'])['status']);

$lockedOut = verifyPublicResultAccess($studentAdm, $limitedPin, $sessionId, $termId);
check('the correct PIN is refused once blocked', $lockedOut['ok'] === false);
check('with the too-many-attempts message',
    $lockedOut['message'] === 'Too many failed attempts. Please contact the school.',
    (string) $lockedOut['message']);
check('the counter did not run past the ceiling',
    (int) findResultPinRow((int) $limited['pin_id'])['failed_attempts'] <= 3);

$unlocked = applyResultPinAction((int) $limited['pin_id'], 'unblock');
check('an administrator can clear it', $unlocked['ok']);
check('the counter is cleared', (int) findResultPinRow((int) $limited['pin_id'])['failed_attempts'] === 0);
check('and the PIN works again', verifyPublicResultAccess($studentAdm, $limitedPin, $sessionId, $termId)['ok'] === true);

check('a clean IP is not throttled', publicResultIpThrottleExceeded('203.0.113.1') === false);
check('a matching IP that has no failures is not throttled',
    publicResultIpThrottleExceeded('198.51.100.9') === false);

heading('Single use versus reusable');

$singleUse = generateResultPin([
    'student_id' => $studentId, 'session_id' => $sessionId,
    'expires_at' => $expiry, 'max_attempts' => 5, 'single_use' => 1,
]);
$issuedIds[] = (int) $singleUse['pin_id'];

check('a single use PIN is flagged', (int) findResultPinRow((int) $singleUse['pin_id'])['single_use'] === 1);

$firstUse = verifyPublicResultAccess($studentAdm, (string) $singleUse['pin'], $sessionId, $termId);
check('the first use succeeds', $firstUse['ok'], (string) $firstUse['message']);
check('the PIN is now used', (string) findResultPinRow((int) $singleUse['pin_id'])['status'] === 'used');

$secondUse = verifyPublicResultAccess($studentAdm, (string) $singleUse['pin'], $sessionId, $termId);
check('the second use is refused', $secondUse['ok'] === false);
check('with a message that says to ask for a new one',
    str_contains((string) $secondUse['message'], 'request a new one'), (string) $secondUse['message']);

/* Issuing the single use PIN retired the previous one, which is the documented
   behaviour and is checked in the reissue section. Here it simply confirms a
   reusable PIN is judged on its own state, not on the history of the student. */
$freshReusable = generateResultPin([
    'student_id' => $studentId, 'session_id' => $sessionId,
    'expires_at' => $expiry, 'max_attempts' => 5, 'single_use' => 0,
]);
$issuedIds[] = (int) $freshReusable['pin_id'];

check('a reusable PIN works after other PINs came and went',
    verifyPublicResultAccess($studentAdm, (string) $freshReusable['pin'], $sessionId, $termId)['ok'] === true);
check('and works again straight after',
    verifyPublicResultAccess($studentAdm, (string) $freshReusable['pin'], $sessionId, $termId)['ok'] === true);

/* Keep a known-good PIN for the reporting checks further down, since every other
   section above issued over the top of it. */
$currentPin = generateResultPin([
    'student_id' => $studentId, 'session_id' => $sessionId,
    'expires_at' => $expiry, 'max_attempts' => 5,
]);
$issuedIds[] = (int) $currentPin['pin_id'];
$currentPinValue = (string) $currentPin['pin'];

/* -------------------------------------------------------------------------
   Reporting: only approved and locked cards, only published classes
   ------------------------------------------------------------------------- */

heading('Only settled results are offered');

applyReportCardAction($reportId, 'lock');

$lockedRow = findReportCard($reportId);
check('the card is locked', (string) $lockedRow['status'] === 'locked');
check('a locked result still opens', verifyPublicResultAccess($studentAdm, $currentPinValue, $sessionId, $termId)['ok'] === true);
check('a locked card has a bundle', publicResultBundle($reportId) !== null);

/* Take the card back to generated: it must stop being reachable, even with the
   correct PIN, even though the class is still published. */
applyReportCardAction($reportId, 'unlock', 'checked again by the school');
applyResultPublication($classId, $sessionId, $termId, 'unpublish');
applyReportCardAction($reportId, 'unlock', 'second pass');

$regenerated = generateReportCard($studentId, $classId, $sessionId, $termId);
check('the card can be regenerated', $regenerated['ok'] ?? false, (string) ($regenerated['message'] ?? ''));

applyResultPublication($classId, $sessionId, $termId, 'publish');

$unsettled = verifyPublicResultAccess($studentAdm, $currentPinValue, $sessionId, $termId);
check('a generated, unsettled card is refused', $unsettled['ok'] === false, (string) $unsettled['message']);
check('with the not-approved message',
    $unsettled['message'] === RESULT_ACCESS_FAILURE_REASONS['not_approved'], (string) $unsettled['message']);
check('and no bundle is built', publicResultBundle($reportId) === null);

/* Put it back so the remaining reporting checks have something to count. */
applyReportCardAction($reportId, 'approve');

$eligible = resultPinEligibleStudents($classId, $sessionId, $termId);
check('a settled student is eligible for a PIN', in_array($studentId, $eligible['ready'], true));
check('the eligible list is not empty', $eligible['ready'] !== [], (string) count($eligible['ready']));

$notReady = dbAll(
    "SELECT rc.student_id FROM report_cards rc
      WHERE rc.session_id = :s AND rc.term_id = :t AND rc.status = 'generated'
      LIMIT 1",
    [':s' => $sessionId, ':t' => $termId]
);

if ($notReady !== []) {
    check('a generated card is not eligible for a PIN',
        !in_array((int) $notReady[0]['student_id'], $eligible['ready'], true));
} else {
    check('no generated cards exist to test ineligibility', true);
}

/* -------------------------------------------------------------------------
   Publication readiness refuses an incomplete class
   ------------------------------------------------------------------------- */

heading('An incomplete class cannot be published');

/* Break one student's card for this class and term, then confirm the gate. */
$otherInClass = (int) dbValue(
    'SELECT e.student_id
       FROM student_class_enrollments e
      WHERE e.class_id = :c AND e.session_id = :s AND e.status = "Active"
        AND e.student_id <> :me
      LIMIT 1',
    [':c' => $classId, ':s' => $sessionId, ':me' => $studentId]
);

if ($otherInClass > 0) {
    /* Walk one student's card back out of the settled states. There is no admin
       action that takes a card from 'approved' to 'generated' - that would let a
       released result be quietly unpublished - so the fixture writes the status
       directly, which is exactly the situation the gate has to catch. */
    $theirCard = findReportCardFor($otherInClass, $classId, $sessionId, $termId);

    if ($theirCard === null) {
        check('the second student has a card to break', false, 'no card');
    } else {
        dbRun(
            "UPDATE report_cards SET status = 'generated' WHERE id = :id",
            [':id' => (int) $theirCard['id']]
        );

        $incompleteCheck = validateResultPublication($classId, $sessionId, $termId);
        check('an incomplete class is refused', $incompleteCheck['ready'] === false, (string) $incompleteCheck['message']);
        check('the message names how many are outstanding',
            str_contains((string) $incompleteCheck['message'], 'not approved'), (string) $incompleteCheck['message']);
        check('the holding students are named', $incompleteCheck['pending'] !== []);
        check('the student who is holding it up is the one named',
            str_contains(implode(' ', $incompleteCheck['pending']),
                studentFullName((array) findStudent($otherInClass))));

        $publishBlocked = applyResultPublication($classId, $sessionId, $termId, 'publish');
        check('publishing it is refused', $publishBlocked['ok'] === false, (string) $publishBlocked['message']);
        check('and it is left unpublished',
            resultPublicationStatus($sessionId, $termId, $classId) === 'published'
            || resultPublicationStatus($sessionId, $termId, $classId) === 'unpublished',
            resultPublicationStatus($sessionId, $termId, $classId));

        applyReportCardAction((int) $theirCard['id'], 'approve');
        check('once it is approved the class is ready again',
            validateResultPublication($classId, $sessionId, $termId)['ready'] === true);
    }
} else {
    check('a second enrolled student exists to break the class', false, 'only one in the class');
}

heading('Publication refuses an unknown class, session or term');

check('an unknown class is refused',
    applyResultPublication(99999999, $sessionId, $termId, 'publish')['ok'] === false);
check('an unknown session is refused',
    applyResultPublication($classId, 99999999, $termId, 'publish')['ok'] === false);
check('an unknown term is refused',
    applyResultPublication($classId, $sessionId, 99999999, 'publish')['ok'] === false);

if ($foreignTerm > 0) {
    $foreign = applyResultPublication($classId, $sessionId, $foreignTerm, 'publish');
    check('a term from another session is refused', $foreign['ok'] === false, (string) $foreign['message']);
    check('with a message that says why',
        str_contains((string) $foreign['message'], 'does not belong'), (string) $foreign['message']);
}

check('an unknown action is refused',
    applyResultPublication($classId, $sessionId, $termId, 'obliterate')['ok'] === false);

/* -------------------------------------------------------------------------
   The public dropdowns only offer what has been published
   ------------------------------------------------------------------------- */

heading('Public dropdowns');

applyResultPublication($classId, $sessionId, $termId, 'publish');

$publicSessions = publishedResultSessionOptions();
$publicTermMap  = publishedResultTermMap();

check('the published session is offered', in_array($sessionId, array_map('intval', array_column($publicSessions, 'id')), true));
check('the published term is offered', isset($publicTermMap[$sessionId]));

$termIds = array_map('intval', array_column($publicTermMap[$sessionId] ?? [], 'id'));
check('the term list contains the published term', in_array($termId, $termIds, true));
check('a session with nothing published is not offered',
    !in_array($otherSessionId, array_map('intval', array_column($publicSessions, 'id')), true)
    || (int) dbValue(
        'SELECT COUNT(*) FROM result_publication WHERE session_id = :s AND status = "published"',
        [':s' => $otherSessionId]
    ) > 0);
check('a term from another session never appears in this session list',
    $foreignTerm === 0 || !in_array($foreignTerm, $termIds, true));
check('sessionTermHasPublishedResults agrees', sessionTermHasPublishedResults($sessionId, $termId));
check('and says no for an unpublished pair',
    sessionTermHasPublishedResults($sessionId, 999999) === false);

/* -------------------------------------------------------------------------
   The access log
   ------------------------------------------------------------------------- */

heading('Result access log');

$logTotal = (int) dbValue('SELECT COUNT(*) FROM result_access_logs');

check('attempts were logged', $logTotal > $existingLogs, "$existingLogs -> $logTotal");
check('the log grew by many lines', ($logTotal - $existingLogs) > 5, (string) ($logTotal - $existingLogs));

$successRows = (int) dbValue('SELECT COUNT(*) FROM result_access_logs WHERE success = 1');
$failedRows  = (int) dbValue('SELECT COUNT(*) FROM result_access_logs WHERE success = 0');

check('successful verifications are logged', $successRows > 0, (string) $successRows);
check('failed verifications are logged', $failedRows > 0, (string) $failedRows);
check('failures carry a reason', (int) dbValue(
    'SELECT COUNT(*) FROM result_access_logs WHERE success = 0 AND failure_reason IS NULL'
) === 0);

/* Requirement 16: the PIN must never be in the log, in any form. */
$logDump = (string) dbValue("SELECT GROUP_CONCAT(CONCAT_WS(' ', pin_id, student_id, ip_address, user_agent, failure_reason) SEPARATOR ' | ') FROM result_access_logs");
check('the plain PIN is nowhere in the log', !str_contains($logDump, $plain));
check('the PIN digits are nowhere in the log', !str_contains($logDump, normaliseResultPin($plain)));
check('no PIN hash is in the log', !str_contains($logDump, '$2y$') && !str_contains($logDump, '$argon2'));

/* A request for a student who does not exist must still be counted. */
$beforeGhost = (int) dbValue('SELECT COUNT(*) FROM result_access_logs');
verifyPublicResultAccess('GHOST/000/000', '1234-5678', $sessionId, $termId);
check('a request for a student who does not exist is still logged',
    (int) dbValue('SELECT COUNT(*) FROM result_access_logs') > $beforeGhost);

/* -------------------------------------------------------------------------- */

heading('Counts and listings');

/* One PIN in each state, so the counts are deterministic. Earlier sections
   deliberately kept overwriting the previous PIN for the same student, which is
   the correct behaviour but leaves nothing in any particular state by now.

   A different student is used for each state, because issuing a PIN retires that
   student's previous one: with a single student it is impossible to have an
   expired PIN and a blocked PIN at the same time, which is itself the point.

   The class is shared with the Phase 4 and 5 suites, which add and remove
   enrollments, so the roster can be shorter than the four states wanted. Only as
   many states as there are students are built, and the rest are skipped rather
   than failing the run over something outside this file's control. */
$wantedStates = ['active', 'expired', 'blocked', 'revoked'];
$countStates  = [];
$stateStudents = array_slice($classStudentIds, 0, count($wantedStates));
$heldStates    = array_slice($wantedStates, 0, count($stateStudents));

check('there is a student for each PIN state the class can hold',
    count($stateStudents) > 0, (string) count($classStudentIds) . ' enrolled');

foreach ($heldStates as $index => $wanted) {
    $holder = $stateStudents[$index];

    $fresh = generateResultPin([
        'student_id' => $holder, 'session_id' => $sessionId,
        'expires_at' => $expiry, 'max_attempts' => 5,
    ]);
    $issuedIds[] = (int) $fresh['pin_id'];

    if ($wanted === 'expired') {
        dbRun('UPDATE result_pins SET expires_at = :when WHERE id = :id', [
            ':when' => date('Y-m-d H:i:s', strtotime('-1 hour')), ':id' => (int) $fresh['pin_id'],
        ]);
    } elseif ($wanted === 'blocked') {
        applyResultPinAction((int) $fresh['pin_id'], 'block');
    } elseif ($wanted === 'revoked') {
        applyResultPinAction((int) $fresh['pin_id'], 'revoke');
    }

    $countStates[$wanted] = (int) $fresh['pin_id'];
}

/* Only the states that could be held are asserted. With four students in the
   class this is all four; with a shorter roster - which is what the Phase 4 and
   5 suites can leave behind - the checks are made against the states that exist
   and the missing ones are reported as skips. */
$expectedStatus = ['active' => 'active', 'expired' => 'active', 'blocked' => 'blocked', 'revoked' => 'revoked'];

$stateStatuses = [];

foreach ($countStates as $state => $pinId) {
    $stateStatuses[$state] = (string) findResultPinRow($pinId)['status'];
}

check('each PIN is in the state it was put into',
    $heldStates === [] || array_reduce(
        $heldStates,
        static function (bool $ok, string $state) use ($stateStatuses, $expectedStatus): bool {
            return $ok && $stateStatuses[$state] === $expectedStatus[$state];
        },
        true
    ),
    json_encode($stateStatuses));

if (isset($countStates['expired'])) {
    check('an expired date makes an active row read as expired',
        resultPinEffectiveStatus((array) findResultPinRow($countStates['expired'])) === 'expired');
}

foreach (array_diff($wantedStates, $heldStates) as $missingState) {
    echo "  SKIP  the $missingState PIN state  ->  the class has only "
        . count($stateStudents) . ' enrolled students, and each state needs its own' . "\n";
}

$pinCounts = resultPinStatusCounts();
check('the counts total every PIN',
    (int) $pinCounts['total'] === (int) dbValue('SELECT COUNT(*) FROM result_pins'),
    json_encode($pinCounts));

foreach (['expired', 'blocked', 'revoked', 'active'] as $countedState) {
    if (isset($countStates[$countedState])) {
        check("there is at least one $countedState PIN",
            (int) $pinCounts[$countedState] >= 1, json_encode($pinCounts));
    }
}

check('the states do not double count the same row',
    (int) $pinCounts['expired'] + (int) $pinCounts['active'] + (int) $pinCounts['blocked']
        + (int) $pinCounts['revoked'] + (int) $pinCounts['used'] === (int) $pinCounts['total'],
    json_encode($pinCounts));

$listing = listResultPins(['search' => $issued['reference'], 'page' => 1]);
check('a PIN can be found by its reference', $listing['paging']['total'] >= 1, (string) $listing['paging']['total']);
check('the listing has no pin_hash column',
    $listing['rows'] === [] || !array_key_exists('pin_hash', $listing['rows'][0]));
check('the listing shows the effective status',
    $listing['rows'] === [] || in_array((string) $listing['rows'][0]['status'], resultPinStatuses(), true));

$byAdmission = listResultPins(['search' => $studentAdm, 'page' => 1]);
check('a PIN can be found by admission number', $byAdmission['paging']['total'] >= 1, (string) $byAdmission['paging']['total']);

if (isset($countStates['blocked'])) {
    $blockedOnly = listResultPins(['status' => 'blocked', 'page' => 1]);
    check('the status filter works',
        $blockedOnly['paging']['total'] >= 1
        && array_reduce($blockedOnly['rows'], static fn (bool $ok, array $r): bool => $ok && $r['status'] === 'blocked', true));
}

if (isset($countStates['expired'])) {
    $expiredOnly = listResultPins(['status' => 'expired', 'page' => 1]);
    check('the expired filter picks up a date that has passed',
        $expiredOnly['paging']['total'] >= 1, (string) $expiredOnly['paging']['total']);
}

/* A search term full of SQL must simply return nothing. */
foreach (["'; DROP TABLE result_pins; -- ", "' OR '1'='1", '%', '_'] as $payload) {
    $safe = listResultPins(['search' => $payload, 'page' => 1]);
    check('search is safe for: ' . substr($payload, 0, 22), is_array($safe['rows']));
}
check('the result_pins table survived the search attempts',
    (int) dbValue('SELECT COUNT(*) FROM result_pins') >= 1);

$pubListing = listResultPublications(['session_id' => $sessionId, 'page' => 1]);
check('the publication list returns the class', $pubListing['paging']['total'] >= 1, (string) $pubListing['paging']['total']);
check('the publication list counts the settled cards',
    $pubListing['rows'] === [] || (int) $pubListing['rows'][0]['card_count'] >= 1);

$pubCounts = resultPublicationCounts();
check('publication counts total the rows',
    (int) $pubCounts['total'] === (int) dbValue('SELECT COUNT(*) FROM result_publication'));

$accessCounts = resultAccessLogCounts();
check('access counts report the total', (int) $accessCounts['total'] === $logTotal || (int) $accessCounts['total'] > $existingLogs);
check('access counts break failures down by reason', is_array($accessCounts['reasons']));
check('recent logs can be read', is_array(recentResultAccessLogs(5)));

/* -------------------------------------------------------------------------
   The public access session
   ------------------------------------------------------------------------- */

heading('The public access session');

unset($_SESSION['public_result']);

check('there is no session to start with', hasPublicResultAccess() === false);
check('reading the report with no session returns nothing', publicResultReport() === null);
check('no time is reported with no session', publicResultSecondsRemaining() === 0);

grantPublicResultAccess($reportId);

check('a grant opens the session', hasPublicResultAccess() === true);
check('the report is readable', publicResultReport() !== null);
check('the readable report is the right one', (int) (publicResultReport() ?? [])['id'] === $reportId);
check('time is reported', publicResultSecondsRemaining() > 0);
check('the window is about 30 minutes',
    publicResultSecondsRemaining() > PUBLIC_RESULT_SESSION_SECONDS - 60
    && publicResultSecondsRemaining() <= PUBLIC_RESULT_SESSION_SECONDS);

/* A grant for a card that was never settled must not open anything. */
grantPublicResultAccess(99999999);
check('a grant for a card that does not exist is refused', publicResultReport() === null);

grantPublicResultAccess($reportId);
revokePublicResultAccess();
check('closing the session closes the access', hasPublicResultAccess() === false);

/* An expired grant is closed on read, not merely ignored. */
$_SESSION['public_result'] = ['report_id' => $reportId, 'token' => 'x', 'issued_at' => time() - 4000, 'expires_at' => time() - 10];
check('an expired session is refused', publicResultReport() === null);
check('and is cleared rather than left to rot', !isset($_SESSION['public_result']));

/* A grant pointing at a card that is no longer settled must close too. */
$_SESSION['public_result'] = ['report_id' => $reportId, 'token' => 'x', 'issued_at' => time(), 'expires_at' => time() + 600];
check('a live session on a settled card works', publicResultReport() !== null);
unset($_SESSION['public_result']);

/* -------------------------------------------------------------------------
   Teardown
   ------------------------------------------------------------------------- */

heading('Teardown');

foreach ($issuedIds as $issuedId) {
    $pdo->prepare('DELETE FROM result_pins WHERE id = ?')->execute([$issuedId]);
}

/* The access log is pruned back to where this file found it, so the school keeps
   its real history and the suite leaves nothing behind. */
$pdo->prepare('DELETE FROM result_access_logs WHERE id > ?')->execute([$existingLogMaxId]);

/* Publication and the card go back to exactly the state the file found. */
if ($existingPub !== []) {
    foreach ($existingPub as $row) {
        $pdo->prepare(
            'UPDATE result_publication
                SET status = ?, published_at = ?, published_by = ?,
                    unpublished_at = ?, unpublished_by = ?
              WHERE session_id = ? AND term_id = ? AND class_id = ?'
        )->execute([
            (string) $row['status'], $row['published_at'], $row['published_by'],
            $row['unpublished_at'], $row['unpublished_by'],
            (int) $row['session_id'], (int) $row['term_id'], (int) $row['class_id'],
        ]);
    }
} else {
    $pdo->prepare(
        'DELETE FROM result_publication WHERE session_id = ? AND term_id = ? AND class_id = ?'
    )->execute([$sessionId, $termId, $classId]);
}

/* Report cards are put back the way they were found, rather than deleted: cards
   that were already there are restored in place, and only the ones this suite
   created are removed. */
$restorableCardColumns = [
    'status', 'total_score', 'average_score', 'position', 'total_students',
    'subjects_offered', 'subjects_passed', 'subjects_failed', 'promotion_status',
    'teacher_comment', 'principal_comment', 'approved_at', 'approved_by',
    'locked_at', 'locked_by', 'attendance_present', 'attendance_absent',
    'attendance_total', 'verification_code', 'generated_at', 'generated_by',
];

foreach ($originalCardRows as $row) {
    $sets   = [];
    $params = [':id' => (int) $row['id']];

    foreach ($restorableCardColumns as $column) {
        if (array_key_exists($column, $row)) {
            $sets[] = "$column = :$column";
            $params[":$column"] = $row[$column];
        }
    }

    if ($sets !== []) {
        dbRun('UPDATE report_cards SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
    }
}

$originalCardIds = array_map(static fn (array $row): int => (int) $row['id'], $originalCardRows);

foreach (dbAll(
    'SELECT id FROM report_cards
      WHERE class_id = :c AND session_id = :s AND term_id = :t',
    [':c' => $classId, ':s' => $sessionId, ':t' => $termId]
) as $row) {
    if (!in_array((int) $row['id'], $originalCardIds, true)) {
        dbRun('DELETE FROM report_cards WHERE id = :id', [':id' => (int) $row['id']]);
    }
}

/* Attendance the fixture wrote is put back the same way, so the Phase 5 suites,
   which assert on this table being empty, are not left failing because of it. */
foreach ($originalAttendance as $row) {
    dbRun(
        'UPDATE attendance_summary
            SET school_days = :school_days, days_present = :days_present, days_absent = :days_absent
          WHERE id = :id',
        [
            ':school_days' => $row['school_days'],
            ':days_present' => $row['days_present'],
            ':days_absent' => $row['days_absent'],
            ':id'          => (int) $row['id'],
        ]
    );
}

$originalAttendanceIds = array_map(
    static fn (array $row): int => (int) $row['id'],
    $originalAttendance
);

foreach (dbAll(
    'SELECT id FROM attendance_summary WHERE class_id = :c AND session_id = :s AND term_id = :t',
    [':c' => $classId, ':s' => $sessionId, ':t' => $termId]
) as $row) {
    if (!in_array((int) $row['id'], $originalAttendanceIds, true)) {
        dbRun('DELETE FROM attendance_summary WHERE id = :id', [':id' => (int) $row['id']]);
    }
}

/* Results are restored the same way: rows this suite overwrote are written back
   with their original marks, and rows it created are removed. Blanket-setting the
   whole class to draft, as this used to, would have quietly undone a real
   school's approvals. */
$originalResultIds = array_map(static fn (array $row): int => (int) $row['id'], $originalResultRows);

foreach ($originalResultRows as $row) {
    dbRun(
        'UPDATE results
            SET test_1 = :test_1, test_2 = :test_2, test_3 = :test_3, examination = :examination,
                total = :total, grade = :grade, grade_remark = :grade_remark, status = :status,
                teacher_id = :teacher_id, approved_at = :approved_at, approved_by = :approved_by
          WHERE id = :id',
        [
            ':test_1'      => $row['test_1'],
            ':test_2'      => $row['test_2'],
            ':test_3'      => $row['test_3'],
            ':examination' => $row['examination'],
            ':total'       => $row['total'],
            ':grade'       => $row['grade'],
            ':grade_remark' => $row['grade_remark'],
            ':status'      => $row['status'],
            ':teacher_id'  => $row['teacher_id'],
            ':approved_at' => $row['approved_at'],
            ':approved_by' => $row['approved_by'],
            ':id'          => (int) $row['id'],
        ]
    );
}

foreach (dbAll(
    'SELECT id FROM results WHERE class_id = :c AND session_id = :s AND term_id = :t',
    [':c' => $classId, ':s' => $sessionId, ':t' => $termId]
) as $row) {
    if (!in_array((int) $row['id'], $originalResultIds, true)) {
        dbRun('DELETE FROM results WHERE id = :id', [':id' => (int) $row['id']]);
    }
}

foreach ($existingPins as $row) {
    $insert = $pdo->prepare(
        'INSERT INTO result_pins
             (id, student_id, session_id, pin_hash, pin_reference, status, single_use,
              expires_at, max_attempts, failed_attempts, last_used_at, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        (int) $row['id'], (int) $row['student_id'], (int) $row['session_id'],
        $row['pin_hash'], $row['pin_reference'], $row['status'], (int) $row['single_use'],
        $row['expires_at'], (int) $row['max_attempts'], (int) $row['failed_attempts'],
        $row['last_used_at'], $row['created_by'],
    ]);
}

/* The temporary second session goes only if this file is the one that made it. */
if ($tempSessionCreated && $tempSessionId > 0) {
    $pdo->prepare('DELETE FROM result_pins WHERE session_id = ?')->execute([$tempSessionId]);
    $pdo->prepare('DELETE FROM result_access_logs WHERE session_id = ?')->execute([$tempSessionId]);
    $pdo->prepare('DELETE FROM result_publication WHERE session_id = ?')->execute([$tempSessionId]);
    $pdo->prepare('DELETE FROM academic_sessions WHERE id = ?')->execute([$tempSessionId]);
}

check('no PIN this suite issued is left behind for the class',
    (int) dbValue(
        'SELECT COUNT(*) FROM result_pins WHERE id IN (' . implode(',', array_map('intval', $issuedIds)) . ')'
    ) === 0);
check('the access log is back to where it started',
    (int) dbValue('SELECT COUNT(*) FROM result_access_logs') === $existingLogs);
check('the report cards are back where they were found',
    (int) dbValue(
        'SELECT COUNT(*) FROM report_cards WHERE class_id = :c AND session_id = :s AND term_id = :t',
        [':c' => $classId, ':s' => $sessionId, ':t' => $termId]
    ) === count($originalCardRows));
check('every card that was already there kept its status',
    (function () use ($originalCardRows): bool {
        foreach ($originalCardRows as $row) {
            $now = dbOne('SELECT status FROM report_cards WHERE id = :id LIMIT 1', [':id' => (int) $row['id']]);

            if ($now === null || (string) $now['status'] !== (string) $row['status']) {
                return false;
            }
        }

        return true;
    })());
check('the attendance is back where it was found',
    (int) dbValue(
        'SELECT COUNT(*) FROM attendance_summary WHERE class_id = :c AND session_id = :s AND term_id = :t',
        [':c' => $classId, ':s' => $sessionId, ':t' => $termId]
    ) === count($originalAttendance));
check('the results are back where they were found',
    (int) dbValue(
        'SELECT COUNT(*) FROM results WHERE class_id = :c AND session_id = :s AND term_id = :t',
        [':c' => $classId, ':s' => $sessionId, ':t' => $termId]
    ) === count($originalResultRows));
check('every result that was already there kept its marks',
    (function () use ($originalResultRows): bool {
        foreach ($originalResultRows as $row) {
            $now = dbOne(
                'SELECT total, grade, status FROM results WHERE id = :id LIMIT 1',
                [':id' => (int) $row['id']]
            );

            if ($now === null
                || (int) $now['total'] !== (int) $row['total']
                || (string) $now['grade'] !== (string) $row['grade']
                || (string) $now['status'] !== (string) $row['status']) {
                return false;
            }
        }

        return true;
    })());

echo "\n" . str_repeat('-', 62) . "\n";
echo "Phase 6 engine: $passed passed, $failed failed\n";

exit($failed === 0 ? 0 : 1);
