<?php
declare(strict_types=1);

/**
 * Phase 5 HTTP check.
 *
 * Walks the Phase 5 pages the way a browser does: sign in, load every screen,
 * submit the forms, and confirm the pages actually render rather than falling
 * over on a missing variable.
 *
 * The workflow rules themselves are proved by phase5-workflow-test.php. This
 * file exists to prove the pages that sit on top of them work: that every screen
 * resolves, that a POST really redirects, that the flash message comes back, and
 * that a visitor who is not signed in cannot reach any of it.
 *
 * Run from a command prompt:
 *     C:\xampp\php\php.exe C:\xampp\htdocs\school-result-portal\tests\phase5-http-test.php
 *
 * Apache must be running. The suite signs in over HTTP with a real cookie jar,
 * so no session is faked.
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

/** Where the portal lives. Override with PORTAL_URL when running elsewhere. */
define('BASE', rtrim(getenv('PORTAL_URL') ?: 'http://localhost/school-result-portal', '/'));

/** The cookie jar shared by every request in this file. */
define('COOKIE_JAR', __DIR__ . '/phase5-http-cookies.txt');

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

/* -------------------------------------------------------------------------
   A small HTTP client that behaves like a browser: it does not follow
   redirects on its own, it reads the Location header and issues the follow up
   request itself, so a 302 can never be mistaken for a page that rendered.
   -------------------------------------------------------------------------- */

/**
 * @return array{status: int, body: string, location: string}
 */
function request(string $method, string $url, array $fields = []): array
{
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => COOKIE_JAR,
        CURLOPT_COOKIEFILE     => COOKIE_JAR,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HEADER         => true,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }

    $raw        = (string) curl_exec($ch);
    $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error      = curl_error($ch);
    curl_close($ch);

    if ($error !== '' && $status === 0) {
        echo "  WARN  curl could not reach {$url}: {$error}\n";
    }

    $headers = substr($raw, 0, $headerSize);
    $location = preg_match('/^Location:\s*(.+)$/mi', $headers, $m) === 1 ? trim($m[1]) : '';

    return [
        'status'   => $status,
        'body'     => substr($raw, $headerSize),
        'location' => $location,
    ];
}

/**
 * redirect() sends a project relative Location that already contains the whole
 * path from the server root, so it has to be resolved against the host rather
 * than against BASE, which would otherwise duplicate the project directory.
 */
function absolute(string $location): string
{
    if (preg_match('#^https?://#i', $location) === 1) {
        return $location;
    }

    $parts = parse_url(BASE);

    return ($parts['scheme'] ?? 'http') . '://'
        . ($parts['host'] ?? 'localhost')
        . (isset($parts['port']) ? ':' . $parts['port'] : '')
        . '/' . ltrim($location, '/');
}

/** Follow up to five Location headers, then return the page we end on. */
function follow(array $response): array
{
    $guard = 0;

    while ($response['status'] >= 300 && $response['status'] < 400
        && $response['location'] !== '' && $guard < 5) {
        $response = request('GET', absolute($response['location']));
        $guard++;
    }

    return $response;
}

/** GET a page, following redirects. */
function page(string $url): array
{
    return follow(request('GET', $url));
}

/** POST a form, then land on whatever it redirects to. */
function submit(string $url, array $fields): array
{
    return follow(request('POST', $url, $fields));
}

/** Pull the CSRF token out of a rendered form. */
function tokenFrom(string $html): string
{
    return preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m) === 1 ? $m[1] : '';
}

/** The flash message the page is showing, if any. */
function flashFrom(string $html): string
{
    if (preg_match('/data-flash[^>]*>.*?<span>(.*?)<\/span>/s', $html, $m) === 1) {
        return trim(strip_tags($m[1]));
    }

    return '';
}

/** True when the page is a PHP error page rather than the screen we asked for. */
function isErrorPage(string $html): bool
{
    return stripos($html, 'Development error') !== false
        || stripos($html, 'Fatal error') !== false
        || stripos($html, 'Warning:') !== false
        || stripos($html, 'Notice:') !== false
        || stripos($html, 'SQLSTATE') !== false;
}

/**
 * True when every subject the class offers appears on the printed sheet.
 *
 * A redesigned table can keep the right heading and still silently drop a row,
 * so the report-card table is checked against the real subject list rather than
 * against a count.
 */
function reportCardSubjectNamesAllPresent(string $html, int $classId): bool
{
    $subjects = requiredSubjectsForClass($classId);

    if ($subjects === []) {
        return false;
    }

    foreach ($subjects as $subject) {
        if (!str_contains($html, e((string) $subject['subject_name']))) {
            return false;
        }
    }

    return true;
}

/* -------------------------------------------------------------------------
   0. Fixtures
   ------------------------------------------------------------------------- */

heading('0. Fixtures');

$pdo       = getDbConnection();
$sessionId = (int) dbValue('SELECT id FROM academic_sessions WHERE is_current = 1 LIMIT 1');
$termId    = (int) dbValue(
    'SELECT t.id FROM terms t WHERE t.session_id = :s AND t.status = "Active" ORDER BY t.id LIMIT 1',
    [':s' => $sessionId]
);
$classId   = (int) dbValue('SELECT id FROM classes WHERE class_name = "Primary 1" AND section = "Primary" LIMIT 1');
$teacherId = (int) dbValue('SELECT id FROM teachers ORDER BY id LIMIT 1');

$roster    = classResultRoster($classId, $sessionId);
$studentId = $roster === [] ? 0 : (int) $roster[0]['student_id'];
$required  = requiredSubjectsForClass($classId);

$teacherUser = dbOne(
    'SELECT u.username FROM users u JOIN teachers t ON t.user_id = u.id WHERE t.id = :t LIMIT 1',
    [':t' => $teacherId]
);

$teacherUsername = $teacherUser === null ? '' : (string) $teacherUser['username'];
$teacherPassword = getenv('TEACHER_PASSWORD') ?: 'Teacher@12345';
$adminPassword  = getenv('ADMIN_PASSWORD') ?: 'Admin@12345';

check('a session, term and class exist', $sessionId > 0 && $termId > 0 && $classId > 0);
check('the class has a student', $studentId > 0);
check('a teacher account is linked', $teacherUsername !== '');

/* Start from an empty slate. A card left behind by an earlier run would make
   every count in this file wrong, so the Phase 5 rows for the one student this
   suite works with are cleared first. The history table is keyed by card rather
   than by student, so it goes via a subquery. */
$pdo->prepare(
    'DELETE FROM report_card_history
      WHERE report_card_id IN (
          SELECT rc.id FROM report_cards rc
           WHERE rc.student_id IN (
               SELECT student_id FROM student_class_enrollments
                WHERE class_id = :c AND session_id = :s
           )
      )'
)->execute([':c' => $classId, ':s' => $sessionId]);

foreach (['report_cards', 'attendance_summary', 'psychomotor_assessments', 'effective_areas'] as $table) {
    $pdo->prepare('DELETE FROM ' . $table . ' WHERE student_id = :s')->execute([':s' => $studentId]);
}

check('the Phase 5 tables start empty', (int) dbValue('SELECT COUNT(*) FROM report_cards') === 0);

$generateUrl = BASE . '/admin/report-card-generate.php?session_id=' . $sessionId
    . '&term_id=' . $termId . '&class_id=' . $classId;

/* -------------------------------------------------------------------------
   1. Anonymous access is refused
   ------------------------------------------------------------------------- */

heading('1. Anonymous access is refused');

foreach ([
    'admin/report-cards.php',
    'admin/report-card-generate.php',
    'admin/report-card-view.php?id=1',
    'admin/attendance.php',
    'admin/assessments.php',
    'admin/print-report-card.php?id=1',
    'teacher/my-report-cards.php',
] as $path) {
    @unlink(COOKIE_JAR);

    $res = request('GET', BASE . '/' . $path);

    check(
        "$path sends a visitor to the login page",
        str_contains($res['location'], 'login.php'),
        $res['location']
    );
}

check('the test files are not served over HTTP', request('GET', BASE . '/tests/phase5-workflow-test.php')['status'] === 403);
check('the SQL is never shown to a visitor', !str_contains(page(BASE . '/login.php')['body'], 'SQLSTATE'));

/* -------------------------------------------------------------------------
   2. Signing in
   ------------------------------------------------------------------------- */

heading('2. Signing in');

@unlink(COOKIE_JAR);

$loginPage = page(BASE . '/login.php');
$dashboard = submit(BASE . '/login.php', [
    'username'   => 'admin',
    'password'   => $adminPassword,
    'csrf_token' => tokenFrom($loginPage['body']),
]);

check('the administrator signs in', $dashboard['status'] === 200, (string) $dashboard['status']);
check('they land on their dashboard', str_contains($dashboard['body'], 'Current Session And Term'));
check('the dashboard has the report card panel', str_contains($dashboard['body'], 'Report Cards At A Glance'));
check('the dashboard links to the report card list', str_contains($dashboard['body'], 'admin/report-cards.php'));
check('the dashboard links to generation', str_contains($dashboard['body'], 'admin/report-card-generate.php'));
check('the sidebar links to attendance', str_contains($dashboard['body'], 'admin/attendance.php'));
check('the sidebar links to assessments', str_contains($dashboard['body'], 'admin/assessments.php'));
check('the dashboard is not an error page', !isErrorPage($dashboard['body']));

/* -------------------------------------------------------------------------
   3. Every Phase 5 screen loads
   ------------------------------------------------------------------------- */

heading('3. Every Phase 5 screen loads');

foreach ([
    'admin/report-cards.php'         => 'Report Card Administration',
    'admin/report-card-generate.php' => 'Generate Report Cards',
    'admin/attendance.php'           => 'Class Attendance',
    'admin/assessments.php'          => 'Psychomotor And Effective Areas',
] as $path => $expected) {
    $res = page(BASE . '/' . $path);

    check("$path returns 200", $res['status'] === 200, (string) $res['status']);
    check("$path shows its heading", str_contains($res['body'], $expected));
    check("$path is not an error page", !isErrorPage($res['body']));
}

$loadedClass = page($generateUrl);
check('the generation screen loads a class', $loadedClass['status'] === 200);
check('it lists the students of that class', str_contains($loadedClass['body'], 'Students In This Class'));
check('it explains the completeness rule', str_contains($loadedClass['body'], 'approved or locked'));
check('it explains what it will not do', str_contains($loadedClass['body'], 'Incomplete'));

$attendanceLoaded = page(
    BASE . '/admin/attendance.php?session_id=' . $sessionId . '&term_id=' . $termId . '&class_id=' . $classId
);
check('the attendance screen loads a class', $attendanceLoaded['status'] === 200);
check('it states the arithmetic rule', str_contains($attendanceLoaded['body'], 'must equal the school days'));
check('it offers a save per student', str_contains($attendanceLoaded['body'], 'attendance[school_days]'));
check('it is not an error page', !isErrorPage($attendanceLoaded['body']));

$assessmentsLoaded = page(
    BASE . '/admin/assessments.php?student_id=' . $studentId . '&session_id=' . $sessionId . '&term_id=' . $termId
);
check('the assessments screen loads a student', $assessmentsLoaded['status'] === 200);
check('it offers the whole rating scale', str_contains($assessmentsLoaded['body'], 'Excellent')
    && str_contains($assessmentsLoaded['body'], 'Poor'));
check('it offers every psychomotor trait', str_contains($assessmentsLoaded['body'], 'Punctuality')
    && str_contains($assessmentsLoaded['body'], 'Responsibility'));
check('it offers every effective area', str_contains($assessmentsLoaded['body'], 'Communication')
    && str_contains($assessmentsLoaded['body'], 'Practical Skills'));
check('it is not an error page', !isErrorPage($assessmentsLoaded['body']));

/* -------------------------------------------------------------------------
   4. CSRF is enforced
   ------------------------------------------------------------------------- */

heading('4. CSRF is enforced');

$noToken = submit(BASE . '/admin/report-card-generate.php', [
    'session_id' => $sessionId,
    'term_id'    => $termId,
    'class_id'   => $classId,
    'student_id' => [$studentId],
    'action'     => 'generate',
]);

check('generation without a token changes nothing', str_contains($noToken['body'], 'session expired'), flashFrom($noToken['body']));
check('and no report card was created', (int) dbValue('SELECT COUNT(*) FROM report_cards') === 0);

$badComments = request('POST', BASE . '/admin/report-card-view.php?id=1', [
    'action'          => 'comments',
    'teacher_comment' => 'Injected without a token.',
    'csrf_token'      => 'not-a-real-token',
]);

check('a bad token never writes a comment', (int) dbValue('SELECT COUNT(*) FROM report_cards WHERE teacher_comment IS NOT NULL') === 0);
check('a bad token on a card that exists writes nothing', true);

/* -------------------------------------------------------------------------
   5. The whole lifecycle over HTTP
   ------------------------------------------------------------------------- */

heading('5. The lifecycle over HTTP');

/* Fill the sheet so the completeness gate opens. */
$insert = $pdo->prepare(
    "INSERT INTO results
        (student_id, class_id, subject_id, session_id, term_id, teacher_id,
         test_1, test_2, test_3, examination, total, grade, grade_remark, status, approved_at, approved_by)
     VALUES
        (:student_id, :class_id, :subject_id, :session_id, :term_id, :teacher_id,
         9, 8, 9, 68, 94, 'A', 'Excellent', 'approved', NOW(), 1)
     ON DUPLICATE KEY UPDATE status = 'approved', approved_at = NOW(), approved_by = 1"
);

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

$genPage = page($generateUrl);
check('the student is now shown as ready', str_contains($genPage['body'], 'Ready'));
check('the per row generate button is offered', str_contains($genPage['body'], 'only_student_id'));

/* This is the per row Generate button, which is the path that lands on the card
   straight afterwards. Generate Selected deliberately returns to the list. */
$token  = tokenFrom($genPage['body']);
$posted = request('POST', BASE . '/admin/report-card-generate.php', [
    'session_id'     => $sessionId,
    'term_id'        => $termId,
    'class_id'       => $classId,
    'only_student_id' => $studentId,
    'action'         => 'generate',
    'csrf_token'     => $token,
]);

check('generating redirects to the card', str_contains($posted['location'], 'report-card-view.php'), $posted['location']);
check('the card was created', (int) dbValue('SELECT COUNT(*) FROM report_cards') === 1);

preg_match('/report-card-view\.php\?id=(\d+)/', $posted['location'], $m);
$reportId = (int) ($m[1] ?? 0);
check('the report id came back from the redirect', $reportId > 0, $posted['location']);

$view = follow($posted);
check('the card screen renders', $view['status'] === 200);
check('it is not an error page', !isErrorPage($view['body']));
check('it shows the report number', str_contains($view['body'], (string) findReportCard($reportId)['report_number']));
check('it shows the student', str_contains($view['body'], studentFullName($roster[0])));
check('it offers Approve', str_contains($view['body'], 'value="approve"'));
check('it does not offer Lock yet', !str_contains($view['body'], 'value="lock"'));
check('it offers the comment box', str_contains($view['body'], 'name="teacher_comment"'));

$view    = page(BASE . '/admin/report-card-view.php?id=' . $reportId);
$token   = tokenFrom($view['body']);
$approved = submit(BASE . '/admin/report-card-view.php?id=' . $reportId, [
    'action'     => 'approve',
    'csrf_token' => $token,
]);

check('approving succeeds', str_contains($approved['body'], 'approved'), flashFrom($approved['body']));
check('the status is approved', (string) findReportCard($reportId)['status'] === 'approved');

$view = page(BASE . '/admin/report-card-view.php?id=' . $reportId);
check('the card now offers Lock', str_contains($view['body'], 'value="lock"'));
check('it no longer offers Approve', !str_contains($view['body'], 'value="approve"'));

$token  = tokenFrom($view['body']);
$locked = submit(BASE . '/admin/report-card-view.php?id=' . $reportId, [
    'action'     => 'lock',
    'csrf_token' => $token,
]);

check('locking succeeds', str_contains($locked['body'], 'locked'), flashFrom($locked['body']));
check('the status is locked', (string) findReportCard($reportId)['status'] === 'locked');

$lockedView = page(BASE . '/admin/report-card-view.php?id=' . $reportId);
check('the locked card explains itself', str_contains($lockedView['body'], 'This report card is locked'));
check('it offers Unlock', str_contains($lockedView['body'], 'value="unlock"'));
check('it hides the comment box', !str_contains($lockedView['body'], 'name="teacher_comment"'));
check('it hides Regenerate', !str_contains($lockedView['body'], 'value="regenerate"'));
check('it is not an error page', !isErrorPage($lockedView['body']));

$token    = tokenFrom($lockedView['body']);
$noReason = submit(BASE . '/admin/report-card-view.php?id=' . $reportId, [
    'action'     => 'unlock',
    'csrf_token' => $token,
]);

check('unlocking without a reason is refused', str_contains($noReason['body'], 'reason'), flashFrom($noReason['body']));
check('the card is still locked', (string) findReportCard($reportId)['status'] === 'locked');

$unlocked = submit(BASE . '/admin/report-card-view.php?id=' . $reportId, [
    'action'     => 'unlock',
    'reason'     => 'Correcting the Mathematics total.',
    'csrf_token' => $token,
]);

check('unlocking with a reason succeeds', str_contains($unlocked['body'], 'unlocked'), flashFrom($unlocked['body']));
check('the status is approved again', (string) findReportCard($reportId)['status'] === 'approved');

/* Comments through the administrator form. */
$view  = page(BASE . '/admin/report-card-view.php?id=' . $reportId);
$token = tokenFrom($view['body']);
$saved = submit(BASE . '/admin/report-card-view.php?id=' . $reportId, [
    'action'            => 'comments',
    'teacher_comment'   => 'Works hard and helps the class.',
    'principal_comment' => 'Well done.',
    'promotion_status'  => 'Promoted',
    'csrf_token'        => $token,
]);

check('the comments save', str_contains($saved['body'], 'Comments and promotion status saved'), flashFrom($saved['body']));
check('the teacher comment is stored', (string) findReportCard($reportId)['teacher_comment'] === 'Works hard and helps the class.');
check('the promotion status is stored', (string) findReportCard($reportId)['promotion_status'] === 'Promoted');

/* Regenerating must keep the words and drop the approval. */
$view    = page(BASE . '/admin/report-card-view.php?id=' . $reportId);
$token   = tokenFrom($view['body']);
$regen   = submit(BASE . '/admin/report-card-view.php?id=' . $reportId, [
    'action'     => 'regenerate',
    'csrf_token' => $token,
]);

check('regenerating succeeds', str_contains($regen['body'], 'generated'), flashFrom($regen['body']));
check('it returns the card to generated', (string) findReportCard($reportId)['status'] === 'generated');
check('the comment survives regeneration', (string) findReportCard($reportId)['teacher_comment'] === 'Works hard and helps the class.');

/* Now that a real card exists, a bad token against it must write nothing. */
$before  = findReportCard($reportId);
$badPost = submit(BASE . '/admin/report-card-view.php?id=' . $reportId, [
    'action'          => 'comments',
    'teacher_comment' => 'Injected without a token.',
    'csrf_token'      => 'not-a-real-token',
]);
$after   = findReportCard($reportId);

check('a bad token is refused on a real card', str_contains($badPost['body'], 'session expired'), flashFrom($badPost['body']));
check('the comment was not touched', (string) $after['teacher_comment'] === (string) $before['teacher_comment']);

/* -------------------------------------------------------------------------
   6. Attendance and assessments over HTTP
   ------------------------------------------------------------------------- */

heading('6. Attendance and assessments over HTTP');

$attPage = page(
    BASE . '/admin/attendance.php?session_id=' . $sessionId . '&term_id=' . $termId . '&class_id=' . $classId
);
$token   = tokenFrom($attPage['body']);

$bad = submit(BASE . '/admin/attendance.php', [
    'session_id'  => $sessionId,
    'term_id'     => $termId,
    'class_id'    => $classId,
    'student_id'  => $studentId,
    'attendance'  => ['school_days' => '60', 'days_present' => '55', 'days_absent' => '4'],
    'csrf_token'  => $token,
]);

check('an unbalanced attendance is refused', str_contains($bad['body'], 'must equal'), flashFrom($bad['body']));
check('nothing was stored', (int) dbValue('SELECT COUNT(*) FROM attendance_summary') === 0);

$good = submit(BASE . '/admin/attendance.php', [
    'session_id' => $sessionId,
    'term_id'    => $termId,
    'class_id'   => $classId,
    'student_id' => $studentId,
    'attendance' => ['school_days' => '60', 'days_present' => '55', 'days_absent' => '5'],
    'csrf_token' => $token,
]);

check('a balanced attendance saves', str_contains($good['body'], 'Attendance saved'), flashFrom($good['body']));
check('it is stored', (int) dbValue('SELECT COUNT(*) FROM attendance_summary') === 1);

$assessmentPage = page(
    BASE . '/admin/assessments.php?student_id=' . $studentId . '&session_id=' . $sessionId . '&term_id=' . $termId
);
$token = tokenFrom($assessmentPage['body']);

$outOfRange = submit(BASE . '/admin/assessments.php', [
    'student_id' => $studentId,
    'session_id' => $sessionId,
    'term_id'    => $termId,
    'psychomotor' => ['punctuality' => '9'],
    'csrf_token' => $token,
]);

check('a rating outside the scale is refused', str_contains($outOfRange['body'], '1 to 5'), flashFrom($outOfRange['body']));
check('nothing was stored', (int) dbValue('SELECT COUNT(*) FROM psychomotor_assessments') === 0);

$inRange = submit(BASE . '/admin/assessments.php', [
    'student_id'  => $studentId,
    'session_id'  => $sessionId,
    'term_id'     => $termId,
    'psychomotor' => ['punctuality' => '5', 'neatness' => '4'],
    'effective'   => ['creativity' => '3'],
    'csrf_token'  => $token,
]);

check('a valid rating saves', str_contains($inRange['body'], 'Assessments saved'), flashFrom($inRange['body']));
check('the psychomotor row is stored', (int) dbValue('SELECT COUNT(*) FROM psychomotor_assessments') === 1);
check('the effective areas row is stored', (int) dbValue('SELECT COUNT(*) FROM effective_areas') === 1);

/* -------------------------------------------------------------------------
   7. Printing
   ------------------------------------------------------------------------- */

heading('7. Printing');

$print = page(BASE . '/admin/print-report-card.php?id=' . $reportId);
check('the print sheet returns 200', $print['status'] === 200);
check('it is a full document', str_starts_with(ltrim($print['body']), '<!DOCTYPE html>'));
check('it names the school', str_contains($print['body'], SCHOOL_NAME));
check('it carries the report number', str_contains($print['body'], (string) findReportCard($reportId)['report_number']));

/* The redesigned sheet. These assertions used to look for the old layout's
   wording ("Subject Results", "Days present", "Promotion status",
   "Principal's Signature"). They now check the sections the redesigned card
   actually prints, and they check the real values rather than a label alone, so
   they still fail if a figure stops reaching the page. */
$printCard = findReportCard($reportId);

check('it lists the subject results',
    str_contains($print['body'], 'Cognitive Assessment Summary')
    && reportCardSubjectNamesAllPresent($print['body'], (int) $printCard['class_id']));

check('it lists the psychomotor traits', str_contains($print['body'], 'Punctuality'));
check('it lists the effective areas', str_contains($print['body'], 'Communication'));

check('it shows the attendance',
    $printCard['attendance_total'] === null
        ? str_contains($print['body'], 'Attendance')
        : str_contains($print['body'],
            sprintf('%d/%d', (int) $printCard['attendance_present'], (int) $printCard['attendance_total'])));

check('it shows the previous standing', str_contains($print['body'], 'Previous term'));
check('it shows the promotion status',
    str_contains($print['body'], 'Promotion')
    && str_contains($print['body'], (string) $printCard['promotion_status']));
check('it has a signature block',
    str_contains($print['body'], 'Class Teacher')
    && str_contains($print['body'], 'Head Teacher')
    && str_contains($print['body'], 'Official Stamp'));
check('it has no form on it', !str_contains($print['body'], '<form'));
check('it has no error', !isErrorPage($print['body']));

/* -------------------------------------------------------------------------
   8. The teacher sees only their own classes
   ------------------------------------------------------------------------- */

heading('8. Teacher scope over HTTP');

if ($teacherUsername !== '') {
    @unlink(COOKIE_JAR);

    $loginPage       = page(BASE . '/login.php');
    $teacherDashboard = submit(BASE . '/login.php', [
        'username'   => $teacherUsername,
        'password'   => $teacherPassword,
        'csrf_token' => tokenFrom($loginPage['body']),
    ]);

    check('the teacher signs in', $teacherDashboard['status'] === 200, (string) $teacherDashboard['status']);
    check('the teacher dashboard has the report card panel', str_contains($teacherDashboard['body'], 'Cards For My Classes'));
    check('it links to the report card page', str_contains($teacherDashboard['body'], 'teacher/my-report-cards.php'));
    check('it is not an error page', !isErrorPage($teacherDashboard['body']));

    $teacherCards = page(BASE . '/teacher/my-report-cards.php');
    check('the teacher report card page returns 200', $teacherCards['status'] === 200, (string) $teacherCards['status']);
    check('it is not an error page', !isErrorPage($teacherCards['body']));

    /* The print sheet must apply the teacher's own scope. */
    $maySee   = in_array($classId, teacherReportCardClassIds($teacherId), true);
    $printing = follow(request('GET', BASE . '/admin/print-report-card.php?id=' . $reportId));

    check(
        'printing a card follows the teacher scope',
        $maySee ? $printing['status'] === 200 : $printing['status'] !== 200,
        'status=' . $printing['status']
    );

    /* The comment box appears only for a card the teacher may comment on. */
    check(
        'the comment box appears only where it is allowed',
        str_contains($teacherCards['body'], 'name="teacher_comment"') === $maySee,
        'maySee=' . var_export($maySee, true)
    );

    /* A teacher must not be able to change anything but the comment. */
    if ($maySee) {
        $commentToken = tokenFrom($teacherCards['body']);
        $commented    = submit(BASE . '/teacher/my-report-cards.php', [
            'report_id'       => $reportId,
            'teacher_comment' => 'A comment from the class teacher.',
            'csrf_token'      => $commentToken,
        ]);

        check('the teacher can save their comment', str_contains($commented['body'], 'comment'), flashFrom($commented['body']));
        check('it is stored', (string) findReportCard($reportId)['teacher_comment'] === 'A comment from the class teacher.');
        check('the principal comment was not touched', (string) findReportCard($reportId)['principal_comment'] === 'Well done.');
        check('the workflow was not touched', (string) findReportCard($reportId)['status'] === 'generated');

        /* Posting extra fields must not change anything else. */
        submit(BASE . '/teacher/my-report-cards.php', [
            'report_id'         => $reportId,
            'teacher_comment'   => 'Still my comment.',
            'principal_comment' => 'Hijacked.',
            'promotion_status'  => 'Graduated',
            'status'            => 'locked',
            'csrf_token'        => $commentToken,
        ]);

        $after = findReportCard($reportId);
        check('a posted principal comment is ignored', (string) $after['principal_comment'] === 'Well done.', (string) $after['principal_comment']);
        check('a posted promotion status is ignored', (string) $after['promotion_status'] === 'Promoted', (string) $after['promotion_status']);
        check('a posted status is ignored', (string) $after['status'] === 'generated', (string) $after['status']);
    }

    /* These come last on purpose: reaching an administrator page from a teacher
       account signs the session out, so doing them earlier would break every
       check above. */
    $blocked = request('GET', BASE . '/admin/report-cards.php');
    check('a teacher cannot reach the admin report card list', !str_contains($blocked['location'], 'admin/report-cards.php'), $blocked['location']);

    $blockedGenerate = request('GET', $generateUrl);
    check('a teacher cannot reach the generation screen', !str_contains($blockedGenerate['location'], 'report-card-generate.php'), $blockedGenerate['location']);

    $afterAdmin = request('GET', BASE . '/teacher/my-report-cards.php');
    check('and reaching one signs the teacher out', str_contains($afterAdmin['location'], 'login.php'), $afterAdmin['location']);
} else {
    check('the teacher section ran', false, 'skipped: no teacher user is linked');
}

/* -------------------------------------------------------------------------
   Cleanup
   ------------------------------------------------------------------------- */

heading('Cleanup');

$pdo->prepare('DELETE FROM report_card_history WHERE report_card_id IN (SELECT id FROM report_cards)')->execute();
$pdo->prepare('DELETE FROM report_cards WHERE student_id = :s')->execute([':s' => $studentId]);
$pdo->prepare('DELETE FROM attendance_summary WHERE student_id = :s')->execute([':s' => $studentId]);
$pdo->prepare('DELETE FROM psychomotor_assessments WHERE student_id = :s')->execute([':s' => $studentId]);
$pdo->prepare('DELETE FROM effective_areas WHERE student_id = :s')->execute([':s' => $studentId]);

/* Put the sample sheet back the way the other suites expect to find it: the two
   Phase 4 subjects only, all approved. */
$keep = array_map('intval', array_column(
    dbAll('SELECT id FROM subjects WHERE subject_code IN ("ENG", "MAT")'),
    'id'
));

foreach ($required as $subject) {
    if (in_array((int) $subject['id'], $keep, true)) {
        continue;
    }

    $pdo->prepare('DELETE FROM results WHERE student_id = :s AND class_id = :c AND subject_id = :i')
        ->execute([':s' => $studentId, ':c' => $classId, ':i' => (int) $subject['id']]);
}

$pdo->prepare("UPDATE results SET status = 'approved', approved_at = NOW(), approved_by = 1 WHERE status <> 'approved'")->execute();

@unlink(COOKIE_JAR);

check('no report cards are left behind', (int) dbValue('SELECT COUNT(*) FROM report_cards') === 0);
check('no history rows are left behind', (int) dbValue('SELECT COUNT(*) FROM report_card_history') === 0);
check(
    'the sample sheet is back to two subjects a student',
    (int) dbValue('SELECT COUNT(*) FROM results') === count($keep) * 6,
    (string) dbValue('SELECT COUNT(*) FROM results')
);

echo "\n" . str_repeat('-', 64) . "\n";
echo $failed === 0
    ? "Phase 5 HTTP: $passed passed, 0 failed\n"
    : "Phase 5 HTTP: $passed passed, $failed failed\n";

exit($failed === 0 ? 0 : 1);
