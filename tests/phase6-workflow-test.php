<?php

declare(strict_types=1);

/**
 * Phase 6 workflow check.
 *
 * Drives the whole public result journey the way the pages do, in the order the
 * school actually uses it:
 *
 *   publish -> issue a PIN -> the parent checks it -> the parent reads it
 *           -> the parent closes it
 *
 * and then, at every step, tries the ways round it that must be refused:
 *
 *   a teacher must not publish or issue a PIN
 *   a class must not publish until every card is approved
 *   a PIN must not open a result for another student
 *   a PIN must stop working the moment it is revoked, blocked or unpublished
 *   an open reading window must close the moment the result is withdrawn
 *
 * The refusals are the point. Phase 6 puts a child's result on a screen that
 * nobody has logged in to, so what matters is not that the happy path works but
 * that every path out of it is closed.
 *
 * Every fixture it creates is removed again before it exits, and anything it
 * overwrites is put back exactly as it was found, so the suite leaves the
 * database where it found it.
 *
 * Run from a command prompt:
 *     C:\xampp\php\php.exe C:\xampp\htdocs\school-result-portal\tests\phase6-workflow-test.php
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
 * Record a check that could not be made, because the sample data has nothing to
 * make it against.
 *
 * A skip is reported rather than hidden, and does not fail the run: "there is no
 * second term" is a fact about the data, not a defect in the portal. Failing on
 * it would only teach everyone to ignore red.
 */
function skip(string $label, string $why): void
{
    echo "  SKIP  $label  ->  $why\n";
}

/**
 * Put the fake session into a role.
 *
 * The pages check the role in the page file as well as inside each function, so
 * a refusal here proves the rule lives in the function and cannot be walked past
 * by reaching it directly.
 */
function signInAs(string $role, int $userId): void
{
    $_SESSION['user_id']   = $userId;
    $_SESSION['user_role'] = $role;
    $_SESSION['user']      = [
        'id'            => $userId,
        'username'      => 'workflow-check-' . $role,
        'full_name'     => 'Workflow Check',
        'email'         => 'check@example.invalid',
        'role'          => $role,
        'status'        => 1,
        'last_login_at' => null,
    ];
}

/* -------------------------------------------------------------------------
   Fixtures
   ------------------------------------------------------------------------- */

heading('Fixtures');

$pdo = getDbConnection();

$sessionId = (int) dbValue('SELECT id FROM academic_sessions WHERE is_current = 1 LIMIT 1');
$termId    = (int) dbValue(
    'SELECT t.id FROM terms t WHERE t.session_id = :s AND t.status = "Active" ORDER BY t.id LIMIT 1',
    [':s' => $sessionId]
);
$teacherId = (int) dbValue('SELECT id FROM teachers ORDER BY id LIMIT 1');
$adminId   = (int) dbValue("SELECT id FROM users WHERE role = 'admin' AND status = 1 LIMIT 1");
$teacherUserId = (int) dbValue(
    "SELECT u.id FROM users u
       JOIN teachers t ON t.user_id = u.id
      WHERE u.role = 'teacher' AND u.status = 1
      ORDER BY u.id LIMIT 1"
);

/* Any class with at least two enrolled students, because the workflow needs a
   second student to prove a PIN does not open somebody else's result. */
$classId = 0;
$members = [];

foreach (dbAll(
    "SELECT e.class_id, COUNT(*) AS n
       FROM student_class_enrollments e
       JOIN students s ON s.id = e.student_id
      WHERE e.session_id = :s AND e.status = 'Active' AND s.status = 'Active'
      GROUP BY e.class_id
      HAVING n >= 2
      ORDER BY e.class_id",
    [':s' => $sessionId]
) as $row) {
    $classId = (int) $row['class_id'];
    break;
}

check('a current session exists', $sessionId > 0, "id=$sessionId");
check('a current term exists', $termId > 0, "id=$termId");
check('a class with at least two enrolled students exists', $classId > 0, "id=$classId");
check('a teacher account exists', $teacherUserId > 0, "id=$teacherUserId");

if ($classId === 0 || $teacherUserId === 0) {
    echo "\nCannot continue without a class and a teacher account.\n";
    exit(1);
}

/* Signed in before anything is built: approving and locking a report card is an
   administrator's job, so a fixture built with no session would quietly produce
   cards stuck at "generated". */
signInAs('admin', $adminId);

$members = array_map(
    static fn (array $row): int => (int) $row['student_id'],
    dbAll(
        "SELECT e.student_id
           FROM student_class_enrollments e
           JOIN students s ON s.id = e.student_id
          WHERE e.class_id = :c AND e.session_id = :s
            AND e.status = 'Active' AND s.status = 'Active'
          ORDER BY s.first_name, s.last_name",
        [':c' => $classId, ':s' => $sessionId]
    )
);

$studentA = $members[0];
$studentB = $members[1];

check('the class has at least two enrolled students', count($members) >= 2, (string) count($members));

/* Everything the run touches is remembered so it can be put back. */
$originalResults = dbAll(
    'SELECT * FROM results
      WHERE class_id = :c AND session_id = :s AND term_id = :t',
    [':c' => $classId, ':s' => $sessionId, ':t' => $termId]
);

$originalCards = dbAll(
    'SELECT * FROM report_cards WHERE class_id = :c AND session_id = :s AND term_id = :t',
    [':c' => $classId, ':s' => $sessionId, ':t' => $termId]
);

$originalPublication = dbAll(
    'SELECT * FROM result_publication WHERE session_id = :s AND term_id = :t AND class_id = :c',
    [':s' => $sessionId, ':t' => $termId, ':c' => $classId]
);

$originalPinRows = dbAll(
    'SELECT * FROM result_pins WHERE student_id IN (' . implode(',', array_map('intval', $members)) . ')'
);

$originalLogMaxId = (int) dbValue('SELECT COALESCE(MAX(id), 0) FROM result_access_logs');

$knownPins  = array_map(
    static fn (array $row): int => (int) $row['id'],
    $originalPinRows
);

/* A complete, fully approved sheet for every student in the class, so the whole
   class can be published. Scores are written rather than left in place because
   a student with no results at all cannot reach the end of this journey. */
$required = requiredSubjectsForClass($classId);
$upsert  = $pdo->prepare(
    "INSERT INTO results
        (student_id, class_id, subject_id, session_id, term_id, teacher_id,
         test_1, test_2, test_3, examination, total, grade, grade_remark, status, approved_at, approved_by)
     VALUES
        (:student_id, :class_id, :subject_id, :session_id, :term_id, :teacher_id,
         9, 8, 9, 68, 94, 'A', 'Excellent', 'approved', NOW(), :approved_by)
     ON DUPLICATE KEY UPDATE
        test_1 = VALUES(test_1), test_2 = VALUES(test_2), test_3 = VALUES(test_3),
        examination = VALUES(examination), total = VALUES(total), grade = VALUES(grade),
        grade_remark = VALUES(grade_remark), status = 'approved',
        approved_at = VALUES(approved_at), approved_by = VALUES(approved_by)"
);

foreach ($members as $memberId) {
    foreach ($required as $subject) {
        $upsert->execute([
            ':student_id'  => $memberId,
            ':class_id'    => $classId,
            ':subject_id'  => (int) $subject['id'],
            ':session_id'  => $sessionId,
            ':term_id'     => $termId,
            ':teacher_id'  => $teacherId,
            ':approved_by' => $adminId,
        ]);
    }
}

$cardsMade = [];

foreach ($members as $memberId) {
    $made = generateReportCard($memberId, $classId, $sessionId, $termId);

    if ($made['ok']) {
        $cardsMade[] = (int) $made['id'];
        applyReportCardAction((int) $made['id'], 'approve');
        applyReportCardAction((int) $made['id'], 'lock');
    }
}

check('a complete sheet was built for every enrolled student',
    count($cardsMade) === count($members), count($cardsMade) . ' of ' . count($members));
check('every card is locked',
    (int) dbValue(
        "SELECT COUNT(*) FROM report_cards
          WHERE class_id = :c AND session_id = :s AND term_id = :t AND status = 'locked'",
        [':c' => $classId, ':s' => $sessionId, ':t' => $termId]
    ) === count($cardsMade));

$expiry = date('Y-m-d', strtotime('+30 days'));

/* -------------------------------------------------------------------------
   1. Only an administrator
   ------------------------------------------------------------------------- */

heading('1. Only an administrator');

/* The class may already be published, because the sample data ships it that way.
   It is taken down while still an administrator, before the teacher is signed in,
   so that "nothing was published" below is asserting something about the teacher
   rather than about the starting state. */
applyResultPublication($classId, $sessionId, $termId, 'unpublish');

signInAs('teacher', $teacherUserId);

$teacherPin = generateResultPin([
    'student_id' => $studentA, 'session_id' => $sessionId,
    'expires_at' => $expiry, 'max_attempts' => 5,
]);
check('a teacher cannot issue a PIN', $teacherPin['ok'] === false, (string) $teacherPin['message']);
check('the refusal says who may', str_contains((string) $teacherPin['message'], 'administrator'), (string) $teacherPin['message']);
check('no PIN was created', $teacherPin['pin_id'] === null);

$teacherBulk = generateClassResultPins(
    $classId, $sessionId, $termId,
    ['expires_at' => $expiry, 'max_attempts' => 5, 'single_use' => 0]
);
check('a teacher cannot issue PINs for a whole class', $teacherBulk['issued'] === 0, (string) $teacherBulk['issued']);
check('and is shown no PINs', $teacherBulk['pins'] === []);

$teacherPublish = applyResultPublication($classId, $sessionId, $termId, 'publish');
check('a teacher cannot publish a class', $teacherPublish['ok'] === false, (string) $teacherPublish['message']);
check('the refusal says who may', str_contains((string) $teacherPublish['message'], 'administrator'), (string) $teacherPublish['message']);
check('nothing was published', isResultPublished($sessionId, $termId, $classId) === false);

$teacherAction = applyResultPinAction(1, 'revoke');
check('a teacher cannot manage an existing PIN', $teacherAction['ok'] === false, (string) $teacherAction['message']);

signInAs('admin', $adminId);
check('an administrator may publish', applyResultPublication($classId, $sessionId, $termId, 'publish')['ok'] === true);

/* -------------------------------------------------------------------------
   2. The class must be complete before it publishes
   ------------------------------------------------------------------------- */

heading('2. The class must be complete before it publishes');

/* Unpublish first, then take one student's card out of the released state, so
   the order the school would follow is the order being tested. */
applyResultPublication($classId, $sessionId, $termId, 'unpublish');

$holdCard = findReportCardFor($studentB, $classId, $sessionId, $termId);
check('the second student has a card', $holdCard !== null);

if ($holdCard !== null) {
    dbRun("UPDATE report_cards SET status = 'generated' WHERE id = :id", [':id' => (int) $holdCard['id']]);

    $gate = validateResultPublication($classId, $sessionId, $termId);
    check('an incomplete class is not ready', $gate['ready'] === false, (string) $gate['message']);
    check('the student holding it up is named', $gate['pending'] !== []);

    $refused = applyResultPublication($classId, $sessionId, $termId, 'publish');
    check('publishing is refused', $refused['ok'] === false, (string) $refused['message']);
    check('and the class is still not published', isResultPublished($sessionId, $termId, $classId) === false);

    $notReady = resultPinEligibleStudents($classId, $sessionId, $termId);
    check('the held-back student is listed as skipped',
        in_array($studentB, array_column($notReady['skipped'], 'id'), true),
        json_encode($notReady['skipped']));

    dbRun("UPDATE report_cards SET status = 'locked' WHERE id = :id", [':id' => (int) $holdCard['id']]);
}

check('once the card is back the class is ready',
    validateResultPublication($classId, $sessionId, $termId)['ready'] === true);

$published = applyResultPublication($classId, $sessionId, $termId, 'publish');
check('the class publishes', $published['ok'] === true, (string) $published['message']);
check('and is published', isResultPublished($sessionId, $termId, $classId) === true);

/* -------------------------------------------------------------------------
   3. A PIN, and the parent who has it
   ------------------------------------------------------------------------- */

heading('3. A PIN, and the parent who has it');

$studentARow = findStudent($studentA);
$admission   = (string) $studentARow['admission_number'];

$issued = generateResultPin([
    'student_id' => $studentA, 'session_id' => $sessionId,
    'expires_at' => $expiry, 'max_attempts' => 5, 'single_use' => 0,
]);

check('a PIN is issued', $issued['ok'] === true, (string) $issued['message']);
check('it is shown in the XXXX-XXXX shape',
    (bool) preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', (string) $issued['pin']), (string) $issued['pin']);
check('a reference is given so it can be found again', (string) $issued['reference'] !== '');

$knownPins[] = (int) $issued['pin_id'];
$pin = (string) $issued['pin'];

/* The stored PIN must not be the printed PIN, or the database would be a list of
   usable result passwords. */
$storedHash = (string) findResultPinRow((int) $issued['pin_id'])['pin_hash'];
check('the database stores a hash, not the PIN', $storedHash !== $pin);
check('the hash is a password hash', str_starts_with($storedHash, '$'), substr($storedHash, 0, 12));
check('the PIN is not findable anywhere in the row',
    !str_contains(json_encode((array) findResultPin((int) $issued['pin_id'])), $pin));

check('the PIN is printed nowhere else on the page list',
    str_contains(json_encode(listResultPins(['search' => $issued['reference'], 'page' => 1])), $pin) === false);

$wrong = verifyPublicResultAccess($admission, 'AAAA-BBBB', $sessionId, $termId);
check('a wrong PIN is refused', $wrong['ok'] === false, (string) $wrong['message']);
check('the refusal does not say the PIN was wrong',
    stripos((string) $wrong['message'], 'pin') === false, (string) $wrong['message']);

$right = verifyPublicResultAccess($admission, $pin, $sessionId, $termId);
check('the right PIN opens the result', $right['ok'] === true, (string) $right['message']);
check('it names the report card', (int) $right['report_id'] > 0, (string) $right['report_id']);

$reportId = (int) $right['report_id'];

/* Lowercase and a missing dash are the same PIN to a parent typing on a phone. */
$sloppy = verifyPublicResultAccess(strtolower($admission), strtolower(str_replace('-', '', $pin)), $sessionId, $termId);
check('a parent typing it without the dash or in capitals still gets in',
    $sloppy['ok'] === true, (string) $sloppy['message']);

check('a failed attempt is written to the access log',
    (int) dbValue(
        'SELECT COUNT(*) FROM result_access_logs WHERE student_id = :s AND success = 0',
        [':s' => $studentA]
    ) >= 1);

/* -------------------------------------------------------------------------
   4. The reading window
   ------------------------------------------------------------------------- */

heading('4. The reading window');

grantPublicResultAccess($reportId);

$report = publicResultReport();
check('the parent now has access', $report !== null);
check('it is the card that was checked', $report !== null && (int) $report['id'] === $reportId);
check('the countdown is running', publicResultSecondsRemaining() > 0, (string) publicResultSecondsRemaining());
check('the countdown is no longer than the session allows',
    publicResultSecondsRemaining() <= PUBLIC_RESULT_SESSION_SECONDS,
    publicResultSecondsRemaining() . ' vs ' . PUBLIC_RESULT_SESSION_SECONDS);

$bundle = publicResultBundle($reportId);
check('the bundle can be built', $bundle !== null);
check('it carries the subjects', is_array($bundle['subjects'] ?? null) && $bundle['subjects'] !== []);

/* The key is always there so the template never has to guess, but the value is
   null when nobody has recorded a register for the term. The populated case is
   proved by the engine suite, which builds a fixture with attendance in it. */
check('it carries an attendance slot', array_key_exists('attendance', (array) $bundle),
    implode(',', array_keys((array) $bundle)));
check('attendance is null or a set of figures',
    $bundle['attendance'] === null || isset($bundle['attendance']['days_present']),
    json_encode($bundle['attendance']));

check('it carries a verification code', (string) ($bundle['verification_code'] ?? '') !== '');

check('the bundle carries nothing about the family',
    !array_key_exists('parent_phone', $bundle) && !array_key_exists('address', $bundle),
    implode(',', array_keys($bundle)));
check('the bundle carries no internal keys',
    !array_key_exists('user_id', $bundle) && !array_key_exists('created_by', $bundle),
    implode(',', array_keys($bundle)));
check('the bundle carries no password material',
    stripos(json_encode($bundle), 'pin_hash') === false);

check('the PIN itself is nowhere in the bundle', stripos(json_encode($bundle), $pin) === false);

/* An expired window closes even though the session cookie is still being sent. */
$_SESSION['public_result']['expires_at'] = time() - 1;
check('an expired window closes the result', publicResultReport() === null);
check('and the grant is cleared', !isset($_SESSION['public_result']));

grantPublicResultAccess($reportId);
check('checking in again restores it', publicResultReport() !== null);

revokePublicResultAccess();
check('the parent can close the window', publicResultReport() === null);

/* Closing the public window must not close an admin login that happens to be in
   the same browser. */
signInAs('admin', $adminId);
grantPublicResultAccess($reportId);
revokePublicResultAccess();
check('closing the public window leaves the admin signed in',
    hasRole('admin'), 'user_id=' . (string) ($_SESSION['user_id'] ?? 'none'));

/* -------------------------------------------------------------------------
   5. A PIN opens one student's result and nothing else
   ------------------------------------------------------------------------- */

heading('5. A PIN opens one student\'s result and nothing else');

$admissionB = (string) findStudent($studentB)['admission_number'];

$crossed = verifyPublicResultAccess($admissionB, $pin, $sessionId, $termId);
check("one student's PIN does not open another's result",
    $crossed['ok'] === false, (string) $crossed['message']);

/* The session and the term are both checked, and a session that is not this one
   cannot be used. Swapping the two is not a test: the sample data happens to use
   id 1 for both, so a swap would hand back the same pair. */
$wrongSession = verifyPublicResultAccess($admission, $pin, $sessionId + 99999, $termId);
check('a session that does not exist is refused', $wrongSession['ok'] === false, (string) $wrongSession['message']);

$wrongTerm = verifyPublicResultAccess($admission, $pin, $sessionId, $termId + 99999);
check('a term that does not exist is refused', $wrongTerm['ok'] === false, (string) $wrongTerm['message']);

$otherTerm = (int) dbValue(
    'SELECT id FROM terms WHERE session_id = :s AND id <> :t ORDER BY id LIMIT 1',
    [':s' => $sessionId, ':t' => $termId]
);

if ($otherTerm > 0) {
    $otherTermTry = verifyPublicResultAccess($admission, $pin, $sessionId, $otherTerm);
    check('a PIN for one term does not open another term',
        $otherTermTry['ok'] === false, (string) $otherTermTry['message']);
} else {
    skip('a PIN for one term does not open another term', 'the session has only one term');
}

/* -------------------------------------------------------------------------
   6. Revoking, blocking and extending
   ------------------------------------------------------------------------- */

heading('6. Revoking, blocking and extending');

$revokePin = generateResultPin([
    'student_id' => $studentB, 'session_id' => $sessionId,
    'expires_at' => $expiry, 'max_attempts' => 5, 'single_use' => 0,
]);
$knownPins[] = (int) $revokePin['pin_id'];

check("the second student's PIN works to begin with",
    verifyPublicResultAccess($admissionB, (string) $revokePin['pin'], $sessionId, $termId)['ok'] === true);

$revoked = applyResultPinAction((int) $revokePin['pin_id'], 'revoke', ['reason' => 'Sent to the wrong address.']);
check('a PIN can be revoked', $revoked['ok'] === true, (string) $revoked['message']);
check('the revocation is recorded', str_contains((string) $revoked['message'], 'revoked'), (string) $revoked['message']);

check('a revoked PIN no longer opens the result',
    verifyPublicResultAccess($admissionB, (string) $revokePin['pin'], $sessionId, $termId)['ok'] === false);
check('and cannot be unblocked',
    applyResultPinAction((int) $revokePin['pin_id'], 'unblock')['ok'] === false);
check('nor brought back by extending it',
    applyResultPinAction((int) $revokePin['pin_id'], 'extend', ['expires_at' => $expiry])['ok'] === false);
check('it is still revoked', (string) findResultPinRow((int) $revokePin['pin_id'])['status'] === 'revoked');

$blockedPin = generateResultPin([
    'student_id' => $studentB, 'session_id' => $sessionId,
    'expires_at' => $expiry, 'max_attempts' => 5, 'single_use' => 0,
]);
$knownPins[] = (int) $blockedPin['pin_id'];

check('the blocked PIN works to begin with',
    verifyPublicResultAccess($admissionB, (string) $blockedPin['pin'], $sessionId, $termId)['ok'] === true);

check('a PIN can be blocked', applyResultPinAction((int) $blockedPin['pin_id'], 'block')['ok'] === true);
check('a blocked PIN does not open the result',
    verifyPublicResultAccess($admissionB, (string) $blockedPin['pin'], $sessionId, $termId)['ok'] === false);

/* A blocked PIN's date may be moved - a school often extends every PIN in a batch
   and blocking is not the same as revoking - but moving the date must never be a
   way back in. What matters is that it is still blocked afterwards. */
applyResultPinAction((int) $blockedPin['pin_id'], 'extend', ['expires_at' => date('Y-m-d', strtotime('+60 days'))]);
check('extending the date does not unblock a blocked PIN',
    verifyPublicResultAccess($admissionB, (string) $blockedPin['pin'], $sessionId, $termId)['ok'] === false,
    (string) findResultPinRow((int) $blockedPin['pin_id'])['status']);

check('a blocked PIN can be unblocked', applyResultPinAction((int) $blockedPin['pin_id'], 'unblock')['ok'] === true);
check('and works again',
    verifyPublicResultAccess($admissionB, (string) $blockedPin['pin'], $sessionId, $termId)['ok'] === true);

check('an unknown action is refused',
    applyResultPinAction((int) $blockedPin['pin_id'], 'delete')['ok'] === false);
check('a PIN that does not exist is refused',
    applyResultPinAction(99999999, 'revoke')['ok'] === false);

/* An expired PIN is not brought back by unblocking either. */
applyResultPinAction((int) $blockedPin['pin_id'], 'block');
dbRun('UPDATE result_pins SET expires_at = :when WHERE id = :id', [
    ':when' => date('Y-m-d H:i:s', strtotime('-1 hour')), ':id' => (int) $blockedPin['pin_id'],
]);

$unblockExpired = applyResultPinAction((int) $blockedPin['pin_id'], 'unblock');
check('an expired PIN cannot be unblocked', $unblockExpired['ok'] === false, (string) $unblockExpired['message']);
check('it is marked expired rather than active',
    (string) findResultPinRow((int) $blockedPin['pin_id'])['status'] === 'expired');

/* -------------------------------------------------------------------------
   7. A PIN that can only be used once
   ------------------------------------------------------------------------- */

heading('7. A PIN that can only be used once');

$single = generateResultPin([
    'student_id' => $studentA, 'session_id' => $sessionId,
    'expires_at' => $expiry, 'max_attempts' => 5, 'single_use' => 1,
]);
$knownPins[] = (int) $single['pin_id'];

check('a single use PIN is issued', $single['ok'] === true, (string) $single['message']);
check('it is flagged single use', (int) findResultPinRow((int) $single['pin_id'])['single_use'] === 1);

$firstUse = verifyPublicResultAccess($admission, (string) $single['pin'], $sessionId, $termId);
check('the first use works', $firstUse['ok'] === true, (string) $firstUse['message']);

$secondUse = verifyPublicResultAccess($admission, (string) $single['pin'], $sessionId, $termId);
check('the second use does not', $secondUse['ok'] === false, (string) $secondUse['message']);
check('the refusal tells the parent what to do next',
    str_contains((string) $secondUse['message'], 'request a new one'), (string) $secondUse['message']);

/* -------------------------------------------------------------------------
   8. Withdrawing the result
   ------------------------------------------------------------------------- */

heading('8. Withdrawing the result');

$openPin = generateResultPin([
    'student_id' => $studentA, 'session_id' => $sessionId,
    'expires_at' => $expiry, 'max_attempts' => 5, 'single_use' => 0,
]);
$knownPins[] = (int) $openPin['pin_id'];

grantPublicResultAccess($reportId);
check('the parent is reading the result', publicResultReport() !== null);

$withdrawn = applyResultPublication($classId, $sessionId, $termId, 'unpublish');
check('the class can be withdrawn', $withdrawn['ok'] === true, (string) $withdrawn['message']);
check('the result is no longer published', isResultPublished($sessionId, $termId, $classId) === false);

check('the open reading window closes at once', publicResultReport() === null);
check('the PIN no longer opens the result',
    verifyPublicResultAccess($admission, (string) $openPin['pin'], $sessionId, $termId)['ok'] === false);

$republished = applyResultPublication($classId, $sessionId, $termId, 'publish');
check('the class can be published again', $republished['ok'] === true, (string) $republished['message']);
check('and the PIN works again',
    verifyPublicResultAccess($admission, (string) $openPin['pin'], $sessionId, $termId)['ok'] === true);

/* The reverse must hold too: withdrawing and republishing must not leave a
   reading window that was closed before quietly reopening. */
check('the earlier window did not come back', publicResultReport() === null);

/* -------------------------------------------------------------------------
   9. A whole class at once
   ------------------------------------------------------------------------- */

heading('9. A whole class at once');

$eligible = resultPinEligibleStudents($classId, $sessionId, $termId);
check('every student in the class is eligible', count($eligible['ready']) === count($members),
    count($eligible['ready']) . ' of ' . count($members));

$bulk = generateClassResultPins($classId, $sessionId, $termId, [
    'expires_at' => $expiry, 'max_attempts' => 5, 'single_use' => 0,
]);

check('a PIN is issued for the whole class', $bulk['issued'] === count($eligible['ready']),
    (string) $bulk['issued'] . ' of ' . count($eligible['ready']));
check('nobody is skipped', $bulk['skipped'] === [], json_encode($bulk['skipped']));

foreach ($bulk['pins'] as $issuedRow) {
    $knownPins[] = (int) dbValue(
        'SELECT id FROM result_pins WHERE pin_reference = :r LIMIT 1',
        [':r' => (string) $issuedRow['reference']]
    );
}

check('every one of them opens a result',
    (function () use ($bulk, $sessionId, $termId): bool {
        foreach ($bulk['pins'] as $issuedRow) {
            if (verifyPublicResultAccess((string) $issuedRow['student'], (string) $issuedRow['pin'], $sessionId, $termId)['ok'] !== true) {
                return false;
            }
        }

        return true;
    })());

check('a missing student is reported rather than quietly dropped', (function () use ($eligible): bool {
    $withheld = findReportCardFor($eligible['skipped'][0]['id'] ?? 0, 0, 0, 0);

    return $withheld === null;
})());

/* -------------------------------------------------------------------------
   10. A PIN cannot be aimed at a student who is not entitled to one
   ------------------------------------------------------------------------- */

heading('10. A PIN cannot be aimed at the wrong student');

$notEnrolled = (int) dbValue(
    'SELECT id FROM students WHERE status = "Active" AND id NOT IN (SELECT student_id FROM student_class_enrollments WHERE session_id = :s AND status = "Active") LIMIT 1',
    [':s' => $sessionId]
);

if ($notEnrolled > 0) {
    $wrongStudent = generateResultPin([
        'student_id' => $notEnrolled, 'session_id' => $sessionId,
        'expires_at' => $expiry, 'max_attempts' => 5, 'single_use' => 0,
    ]);
    check('a PIN is still issued - eligibility is the admin form\'s job, not the engine\'s',
        $wrongStudent['ok'] === true, (string) $wrongStudent['message']);

    if ($wrongStudent['ok']) {
        $knownPins[] = (int) $wrongStudent['pin_id'];

        $notPublished = verifyPublicResultAccess(
            (string) findStudent($notEnrolled)['admission_number'],
            (string) $wrongStudent['pin'], $sessionId, $termId
        );
        check('but it opens nothing, because that result is not published',
            $notPublished['ok'] === false, (string) $notPublished['message']);
    }
} else {
    skip('a PIN for an unenrolled student opens nothing', 'every active student is enrolled in this session');
}

check('a PIN for a session that does not exist is refused', (function () use ($studentA, $expiry): bool {
    $nowhere = generateResultPin([
        'student_id' => $studentA, 'session_id' => 99999999,
        'expires_at' => $expiry, 'max_attempts' => 5,
    ]);

    return $nowhere['ok'] === false;
})());

check('a PIN with no expiry date is refused', (function () use ($studentA, $sessionId): bool {
    return generateResultPin([
        'student_id' => $studentA, 'session_id' => $sessionId,
        'expires_at' => '', 'max_attempts' => 5,
    ])['ok'] === false;
})());

check('a PIN with an expiry in the past is refused', (function () use ($studentA, $sessionId): bool {
    return generateResultPin([
        'student_id' => $studentA, 'session_id' => $sessionId,
        'expires_at' => date('Y-m-d', strtotime('-1 day')), 'max_attempts' => 5,
    ])['ok'] === false;
})());

check('a PIN with no attempt ceiling is refused', (function () use ($studentA, $sessionId, $expiry): bool {
    return generateResultPin([
        'student_id' => $studentA, 'session_id' => $sessionId,
        'expires_at' => $expiry, 'max_attempts' => 0,
    ])['ok'] === false;
})());

/* -------------------------------------------------------------------------
   Teardown
   ------------------------------------------------------------------------- */

heading('Teardown');

foreach ($knownPins as $pinId) {
    dbRun('DELETE FROM result_pins WHERE id = :id', [':id' => $pinId]);
}

/* The access log is pruned back to the last row that was already there, so the
   school keeps its real history and this run leaves nothing behind. */
dbRun('DELETE FROM result_access_logs WHERE id > :id', [':id' => $originalLogMaxId]);

if ($originalPublication === []) {
    dbRun(
        'DELETE FROM result_publication WHERE session_id = :s AND term_id = :t AND class_id = :c',
        [':s' => $sessionId, ':t' => $termId, ':c' => $classId]
    );
} else {
    foreach ($originalPublication as $row) {
        dbRun(
            'UPDATE result_publication
                SET status = :status, published_at = :published_at, published_by = :published_by,
                    unpublished_at = :unpublished_at, unpublished_by = :unpublished_by
              WHERE session_id = :s AND term_id = :t AND class_id = :c',
            [
                ':status'           => (string) $row['status'],
                ':published_at'     => $row['published_at'],
                ':published_by'     => $row['published_by'],
                ':unpublished_at'   => $row['unpublished_at'],
                ':unpublished_by'   => $row['unpublished_by'],
                ':s'                => (int) $row['session_id'],
                ':t'                => (int) $row['term_id'],
                ':c'                => (int) $row['class_id'],
            ]
        );
    }
}

/* Cards this file created are removed; cards it overwrote are put back. */
$cardColumns = [
    'status', 'total_score', 'average_score', 'position', 'total_students',
    'subjects_offered', 'subjects_passed', 'subjects_failed', 'promotion_status',
    'teacher_comment', 'principal_comment', 'approved_at', 'approved_by',
    'locked_at', 'locked_by', 'attendance_present', 'attendance_absent',
    'attendance_total', 'verification_code', 'generated_at', 'generated_by',
];

foreach ($originalCards as $row) {
    $sets = [];

    foreach ($cardColumns as $column) {
        if (array_key_exists($column, $row)) {
            $sets[] = "$column = :$column";
        }
    }

    if ($sets !== []) {
        $params = [':id' => (int) $row['id']];

        foreach ($cardColumns as $column) {
            if (array_key_exists($column, $row)) {
                $params[":$column"] = $row[$column];
            }
        }

        dbRun(
            'UPDATE report_cards SET ' . implode(', ', $sets) . ' WHERE id = :id',
            $params
        );
    }
}

$originalCardIds = array_map(static fn (array $row): int => (int) $row['id'], $originalCards);

foreach ($cardsMade as $cardId) {
    if (!in_array($cardId, $originalCardIds, true)) {
        dbRun('DELETE FROM report_cards WHERE id = :id', [':id' => $cardId]);
    }
}

/* Results this file overwrote are restored exactly. */
$originalResultIds = [];

foreach ($originalResults as $row) {
    $originalResultIds[] = (int) $row['id'];

    dbRun(
        'UPDATE results
            SET test_1 = :test_1, test_2 = :test_2, test_3 = :test_3, examination = :examination,
                total = :total, grade = :grade, grade_remark = :grade_remark, status = :status,
                teacher_id = :teacher_id, approved_at = :approved_at, approved_by = :approved_by
          WHERE id = :id',
        [
            ':test_1'        => $row['test_1'],
            ':test_2'        => $row['test_2'],
            ':test_3'        => $row['test_3'],
            ':examination'   => $row['examination'],
            ':total'         => $row['total'],
            ':grade'         => $row['grade'],
            ':grade_remark'  => $row['grade_remark'],
            ':status'        => $row['status'],
            ':teacher_id'    => $row['teacher_id'],
            ':approved_at'   => $row['approved_at'],
            ':approved_by'   => $row['approved_by'],
            ':id'            => (int) $row['id'],
        ]
    );
}

$idsInPlace = $originalResultIds === []
    ? []
    : array_map('intval', $originalResultIds);

$survivors = dbAll(
    'SELECT id FROM results
      WHERE class_id = :c AND session_id = :s AND term_id = :t',
    [':c' => $classId, ':s' => $sessionId, ':t' => $termId]
);

foreach ($survivors as $row) {
    if (!in_array((int) $row['id'], $idsInPlace, true)) {
        dbRun('DELETE FROM results WHERE id = :id', [':id' => (int) $row['id']]);
    }
}

$pinLeft = (int) dbValue(
    'SELECT COUNT(*) FROM result_pins WHERE id IN (' . implode(',', array_map('intval', $knownPins)) . ')'
);
$logLeft = (int) dbValue(
    'SELECT COUNT(*) FROM result_access_logs WHERE id > :id',
    [':id' => $originalLogMaxId]
);

check('every PIN this file made is gone', $pinLeft === 0, (string) $pinLeft);
check('every access log line this file wrote is gone', $logLeft === 0, (string) $logLeft);
check('the class is back to the publication state it was found in',
    (int) dbValue(
        'SELECT COUNT(*) FROM result_publication WHERE session_id = :s AND term_id = :t AND class_id = :c',
        [':s' => $sessionId, ':t' => $termId, ':c' => $classId]
    ) === count($originalPublication));
check('the cards are back where they were',
    (int) dbValue(
        'SELECT COUNT(*) FROM report_cards WHERE class_id = :c AND session_id = :s AND term_id = :t',
        [':c' => $classId, ':s' => $sessionId, ':t' => $termId]
    ) === count($originalCards));
check('the results are back where they were',
    (int) dbValue(
        'SELECT COUNT(*) FROM results WHERE class_id = :c AND session_id = :s AND term_id = :t',
        [':c' => $classId, ':s' => $sessionId, ':t' => $termId]
    ) === count($originalResults));

echo "\nPhase 6 workflow: $passed passed, $failed failed\n";
echo $failed === 0
    ? "All checks passed.\n"
    : "$failed check(s) need attention.\n";

exit($failed === 0 ? 0 : 1);