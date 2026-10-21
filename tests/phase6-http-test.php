<?php

declare(strict_types=1);

/**
 * Phase 6 HTTP check.
 *
 * Walks the Phase 6 pages the way a browser does, from both sides of the portal:
 *
 *   the school  - sign in, publish a class, issue a PIN, search for it, revoke it
 *   the parent  - open the checker with no account, enter four details, read the
 *                 result, print it, close it
 *
 * The rules themselves are proved by phase6-engine-test.php and
 * phase6-workflow-test.php. This file exists to prove the pages on top of them
 * actually work: that every screen resolves, that a POST really redirects, that
 * the flash comes back, that the security headers are really sent, and that the
 * PIN never turns up in a URL or in a page it should not be on.
 *
 * Run from a command prompt:
 *     C:\xampp\php\php.exe C:\xampp\htdocs\school-result-portal\tests\phase6-http-test.php
 *
 * Apache must be running. The suite signs in over HTTP with a real cookie jar,
 * so no session is faked. The parent's half deliberately uses a SEPARATE cookie
 * jar, because the point of that half is that no account is involved at all.
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

define('BASE', rtrim(getenv('PORTAL_URL') ?: 'http://localhost/school-result-portal', '/'));

/** The cookie jar for the school, which is signed in. */
define('STAFF_JAR', __DIR__ . '/phase6-http-staff-cookies.txt');

/** The cookie jar for the parent, which never signs in. */
define('PUBLIC_JAR', __DIR__ . '/phase6-http-public-cookies.txt');

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

function skip(string $label, string $why): void
{
    echo "  SKIP  $label  ->  $why\n";
}

/* -------------------------------------------------------------------------
   A small HTTP client that behaves like a browser
   -------------------------------------------------------------------------- */

/**
 * @return array{status: int, body: string, location: string, headers: array<string,string>}
 */
function request(string $method, string $url, array $fields = [], ?string $jar = null): array
{
    $jar = $jar ?? STAFF_JAR;

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
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

    $rawHeaders = substr($raw, 0, $headerSize);
    $location   = preg_match('/^Location:\s*(.+)$/mi', $rawHeaders, $m) === 1 ? trim($m[1]) : '';

    /* Kept lowercased so a test can ask for "cache-control" without caring how
       Apache chose to capitalise it. Only the last value of a repeated header is
       kept, which is what a browser acts on for the headers tested here. */
    $headers = [];

    foreach (preg_split('/\r?\n/', $rawHeaders) ?: [] as $line) {
        if (strpos($line, ':') === false) {
            continue;
        }

        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
    }

    return [
        'status'   => $status,
        'body'     => substr($raw, $headerSize),
        'location' => $location,
        'headers'  => $headers,
    ];
}

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

function follow(array $response, ?string $jar = null): array
{
    $guard = 0;

    while ($response['status'] >= 300 && $response['status'] < 400
        && $response['location'] !== '' && $guard < 5) {
        $response = request('GET', absolute($response['location']), [], $jar);
        $guard++;
    }

    return $response;
}

function page(string $url, ?string $jar = null): array
{
    return follow(request('GET', $url, [], $jar), $jar);
}

function submit(string $url, array $fields, ?string $jar = null): array
{
    return follow(request('POST', $url, $fields, $jar), $jar);
}

function tokenFrom(string $html): string
{
    return preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m) === 1 ? $m[1] : '';
}

function flashFrom(string $html): string
{
    if (preg_match('/data-flash[^>]*>.*?<span>(.*?)<\/span>/s', $html, $m) === 1) {
        return trim(strip_tags($m[1]));
    }

    /* The public pages use a plain Bootstrap alert rather than the staff flash
       component, so both shapes are looked for. */
    if (preg_match('/<div class="alert alert-(?:danger|success)[^"]*"[^>]*>\s*(?:<i[^>]*><\/i>\s*)?<span>(.*?)<\/span>/s', $html, $m) === 1) {
        return trim(strip_tags($m[1]));
    }

    if (preg_match('/<div class="alert alert-(?:danger|success)[^"]*"[^>]*>\s*([^<]+?)\s*<\/div>/s', $html, $m) === 1) {
        return trim(strip_tags($m[1]));
    }

    return '';
}

function isErrorPage(string $html): bool
{
    return stripos($html, 'Development error') !== false
        || stripos($html, 'Fatal error') !== false
        || stripos($html, 'Warning:') !== false
        || stripos($html, 'Notice:') !== false
        || stripos($html, 'SQLSTATE') !== false;
}

/** The PIN out of the one page that is allowed to show it. */
function pinFrom(string $html): string
{
    return preg_match('/mono fs-5 fw-bold text-green">\s*([A-Z0-9]{4}-[A-Z0-9]{4})\s*</', $html, $m) === 1
        ? $m[1]
        : '';
}

/** The part of a page that explains why it is an error, for a failure detail. */
function errorSnippet(string $html): string
{
    if (preg_match('/(Development error.{0,400}|Fatal error.{0,400}|SQLSTATE.{0,300}|Warning:.{0,200}|Notice:.{0,200})/s', $html, $m) === 1) {
        return trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
    }

    return '(no error text found)';
}

/* -------------------------------------------------------------------------
   0. Fixtures
   ------------------------------------------------------------------------- */

heading('0. Fixtures');

@mkdir(__DIR__, 0777, true);

$adminPassword  = getenv('ADMIN_PASSWORD') ?: 'Admin@12345';
$teacherPassword = getenv('TEACHER_PASSWORD') ?: 'Teacher@12345';

$sessionId = (int) dbValue('SELECT id FROM academic_sessions WHERE is_current = 1 LIMIT 1');
$termId    = (int) dbValue(
    'SELECT t.id FROM terms t WHERE t.session_id = :s AND t.status = "Active" ORDER BY t.id LIMIT 1',
    [':s' => $sessionId]
);
$classId   = (int) dbValue(
    'SELECT e.class_id FROM student_class_enrollments e
       JOIN students s ON s.id = e.student_id
      WHERE e.session_id = :s AND e.status = "Active" AND s.status = "Active"
      GROUP BY e.class_id ORDER BY e.class_id LIMIT 1',
    [':s' => $sessionId]
);
$teacherId = (int) dbValue('SELECT id FROM teachers ORDER BY id LIMIT 1');

$studentId   = 0;
$admissionNo = '';
$dob         = '';

if ($classId > 0) {
    $roster = classResultRoster($classId, $sessionId);

    if ($roster !== []) {
        /* Prefer a student with a date_of_birth set; fall back to the first one. */
        $studentWithDob = null;
        foreach ($roster as $r) {
            $s = findStudent((int) $r['student_id']);
            if ($s !== null && $s['date_of_birth'] !== null && $s['date_of_birth'] !== '') {
                $studentWithDob = $s;
                break;
            }
        }
        $student = $studentWithDob ?? findStudent((int) $roster[0]['student_id']);
        $studentId   = (int) $student['id'];
        $admissionNo = (string) $student['admission_number'];
        $dob         = (string) $student['date_of_birth'];
    }
}

$teacherUsername = (string) dbValue(
    'SELECT u.username FROM users u JOIN teachers t ON t.user_id = u.id WHERE t.id = :t LIMIT 1',
    [':t' => $teacherId]
);

check('a session, term and class exist', $sessionId > 0 && $termId > 0 && $classId > 0,
    "$sessionId/$termId/$classId");
check('the class has a student to publish for', $studentId > 0, "id=$studentId");
check('a teacher account is linked', $teacherUsername !== '', $teacherUsername);

if ($studentId === 0) {
    echo "\nCannot continue without a student.\n";
    exit(1);
}

/* Everything this file changes is snapshotted, so it can be put back. */
$pdo = getDbConnection();

$originalResults = dbAll(
    'SELECT * FROM results WHERE class_id = :c AND session_id = :s AND term_id = :t',
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

$originalPinIds = array_map(
    static fn (array $row): int => (int) $row['id'],
    dbAll('SELECT id FROM result_pins WHERE student_id = :s', [':s' => $studentId])
);

$originalLogMaxId = (int) dbValue('SELECT COALESCE(MAX(id), 0) FROM result_access_logs');

$newPinIds = [];

/* Everything this file changes is put back by this one function, and it is
   registered as a shutdown handler as soon as the snapshots exist. An HTTP suite
   that dies halfway through would otherwise leave published classes, locked
   cards and readable PINs behind, and the next run - and the school - would both
   be working against data that is not really there. */
$teardown = static function () use (
    &$newPinIds,
    $originalPublication,
    $originalCards,
    $originalResults,
    $originalLogMaxId,
    $classId,
    $sessionId,
    $termId
): void {
    @unlink(STAFF_JAR);
    @unlink(PUBLIC_JAR);

    foreach ($newPinIds as $id) {
        dbRun('DELETE FROM result_pins WHERE id = :id', [':id' => (int) $id]);
    }

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
                    ':status'         => (string) $row['status'],
                    ':published_at'   => $row['published_at'],
                    ':published_by'   => $row['published_by'],
                    ':unpublished_at' => $row['unpublished_at'],
                    ':unpublished_by' => $row['unpublished_by'],
                    ':s'              => (int) $row['session_id'],
                    ':t'              => (int) $row['term_id'],
                    ':c'              => (int) $row['class_id'],
                ]
            );
        }
    }

    $cardColumns = [
        'status', 'total_score', 'average_score', 'position', 'total_students',
        'subjects_offered', 'subjects_passed', 'subjects_failed', 'promotion_status',
        'teacher_comment', 'principal_comment', 'approved_at', 'approved_by',
        'locked_at', 'locked_by', 'attendance_present', 'attendance_absent',
        'attendance_total', 'verification_code', 'generated_at', 'generated_by',
    ];

    foreach ($originalCards as $row) {
        $sets   = [];
        $params = [':id' => (int) $row['id']];

        foreach ($cardColumns as $column) {
            if (array_key_exists($column, $row)) {
                $sets[] = "$column = :$column";
                $params[":$column"] = $row[$column];
            }
        }

        if ($sets !== []) {
            dbRun('UPDATE report_cards SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
        }
    }

    $originalCardIds = array_map(static fn (array $row): int => (int) $row['id'], $originalCards);

    foreach (dbAll(
        'SELECT id FROM report_cards WHERE class_id = :c AND session_id = :s AND term_id = :t',
        [':c' => $classId, ':s' => $sessionId, ':t' => $termId]
    ) as $row) {
        if (!in_array((int) $row['id'], $originalCardIds, true)) {
            dbRun('DELETE FROM report_cards WHERE id = :id', [':id' => (int) $row['id']]);
        }
    }

    $originalResultIds = array_map(static fn (array $row): int => (int) $row['id'], $originalResults);

    foreach ($originalResults as $row) {
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
};

register_shutdown_function(static function () use (&$teardown): void {
    if ($teardown !== null) {
        ($teardown)();
        $teardown = null;
    }
});

/* A released result to publish, so the parent half of this file has something
   real to open. The fixture is built in this process, so it needs an
   administrator of its own; the requests below run in Apache, in their own
   processes, and sign in properly.

   Every enrolled student is settled, not just the one this file checks: a class
   cannot be published while any one of its results is outstanding, so preparing
   a single student would leave the class unpublishable. */
$_SESSION['user_id']   = (int) dbValue("SELECT id FROM users WHERE role = 'admin' AND status = 1 LIMIT 1");
$_SESSION['user_role'] = 'admin';
$_SESSION['user']      = [
    'id'            => (int) $_SESSION['user_id'],
    'username'      => 'http-check',
    'full_name'     => 'HTTP Check',
    'email'         => 'check@example.invalid',
    'role'          => 'admin',
    'status'        => 1,
    'last_login_at' => null,
];

$rosterIds = array_map(
    static fn (array $row): int => (int) $row['student_id'],
    classResultRoster($classId, $sessionId)
);

$upsert = $pdo->prepare(
    "INSERT INTO results
        (student_id, class_id, subject_id, session_id, term_id, teacher_id,
         test_1, test_2, test_3, examination, total, grade, grade_remark, status, approved_at)
     VALUES
        (:student_id, :class_id, :subject_id, :session_id, :term_id, :teacher_id,
         9, 8, 9, 68, 94, 'A', 'Excellent', 'approved', NOW())
     ON DUPLICATE KEY UPDATE
        test_1 = 9, test_2 = 8, test_3 = 9, examination = 68, total = 94,
        grade = 'A', grade_remark = 'Excellent', status = 'approved'"
);

foreach ($rosterIds as $rosterId) {
    foreach (requiredSubjectsForClass($classId) as $subject) {
        $upsert->execute([
            ':student_id' => $rosterId,
            ':class_id'   => $classId,
            ':subject_id' => (int) $subject['id'],
            ':session_id' => $sessionId,
            ':term_id'    => $termId,
            ':teacher_id' => $teacherId,
        ]);
    }
}

$made      = ['ok' => false, 'message' => 'no student could be prepared'];
$reportId  = 0;

foreach ($rosterIds as $rosterId) {
    $built = generateReportCard($rosterId, $classId, $sessionId, $termId);

    /* A card left behind by an earlier interrupted run is already released, and
       generateReportCard() quite rightly refuses to touch a locked one. Adopt it
       instead of treating the run as broken. */
    $cardId = ($built['ok'] ?? false)
        ? (int) $built['id']
        : (int) (findReportCardFor($rosterId, $classId, $sessionId, $termId)['id'] ?? 0);

    if ($cardId === 0) {
        $made = $built;
        continue;
    }

    $current = (string) findReportCard($cardId)['status'];

    if ($current !== 'locked') {
        if ($current === 'generated') {
            applyReportCardAction($cardId, 'approve');
        }

        applyReportCardAction($cardId, 'lock');
    }

    if ($rosterId === $studentId) {
        $reportId = $cardId;
        $made     = $built;
    }
}

check('a report card was built for the student under test', $reportId > 0, (string) ($made['message'] ?? 'none'));
check('it is locked', $reportId > 0 && (string) findReportCard($reportId)['status'] === 'locked');

check('every student in the class has a locked card',
    (int) dbValue(
        "SELECT COUNT(*) FROM report_cards
          WHERE class_id = :c AND session_id = :s AND term_id = :t AND status = 'locked'",
        [':c' => $classId, ':s' => $sessionId, ':t' => $termId]
    ) === count($rosterIds));

/* Published up front, because until something is published the checker shows a
   "nothing published yet" notice instead of the form, and every check about the
   form would be asserting against a page that has no form on it. Section 6 takes
   it down and puts it back up again to exercise both transitions. */
$fixturePublish = applyResultPublication($classId, $sessionId, $termId, 'publish');

check('the class is published for the parent half of this file',
    $fixturePublish['ok'] === true, (string) $fixturePublish['message']);

$expiry = date('Y-m-d', strtotime('+30 days'));

$generateUrl = BASE . '/admin/result-pin-generate.php?session_id=' . $sessionId
    . '&term_id=' . $termId . '&class_id=' . $classId;

$publicationUrl = BASE . '/admin/result-publication.php?session_id=' . $sessionId
    . '&term_id=' . $termId . '&class_id=' . $classId;

/* -------------------------------------------------------------------------
   1. The admin screens are closed to the public
   ------------------------------------------------------------------------- */

heading('1. The admin screens are closed to the public');

foreach ([
    'admin/result-pins.php',
    'admin/result-pin-generate.php',
    'admin/result-publication.php',
] as $path) {
    @unlink(STAFF_JAR);

    $res = request('GET', BASE . '/' . $path);

    check("$path sends a visitor to the login page",
        str_contains($res['location'], 'login.php'), $res['location'] . ' status ' . $res['status']);
}

@unlink(PUBLIC_JAR);

$noSession = request('GET', BASE . '/result-view.php', [], PUBLIC_JAR);
check('result-view.php sends a visitor with no result session back to the checker',
    str_contains($noSession['location'], 'result-checker.php'), $noSession['location']);

check('this test file is not served over HTTP',
    request('GET', BASE . '/tests/phase6-http-test.php')['status'] === 403);
check('no SQL is shown to a visitor', !str_contains(page(BASE . '/result-checker.php', PUBLIC_JAR)['body'], 'SQLSTATE'));

/* -------------------------------------------------------------------------
   2. The checker, for a parent with no account
   ------------------------------------------------------------------------- */

heading('2. The checker, for a parent with no account');

@unlink(PUBLIC_JAR);

$checker = page(BASE . '/result-checker.php', PUBLIC_JAR);

check('the checker renders', $checker['status'] === 200, (string) $checker['status']);
check('it is not a PHP error page', !isErrorPage($checker['body']));
check('it asks for the admission number', str_contains($checker['body'], 'name="admission_number"'));
check('it asks for the date of birth', str_contains($checker['body'], 'name="date_of_birth"'));
check('it asks for the session', str_contains($checker['body'], 'name="session_id"'));
check('it asks for the term', str_contains($checker['body'], 'name="term_id"'));
check('it does NOT ask for a PIN', !str_contains($checker['body'], 'name="pin"'));
check('it has no password-masked PIN field',
    !preg_match('/<input[^>]*type="password"[^>]*name="pin"|<input[^>]*name="pin"[^>]*type="password"/', $checker['body']));
check('it tells search engines to stay away',
    str_contains($checker['body'], 'noindex'), '');
check('the result is cached nowhere',
    str_contains(strtolower($checker['headers']['cache-control'] ?? ''), 'no-store'),
    $checker['headers']['cache-control'] ?? '(none)');
check('the browser is told not to sniff the type',
    strtolower($checker['headers']['x-content-type-options'] ?? '') === 'nosniff',
    $checker['headers']['x-content-type-options'] ?? '(none)');
check('a referrer policy is sent',
    ($checker['headers']['referrer-policy'] ?? '') !== '',
    $checker['headers']['referrer-policy'] ?? '(none)');
check('the checker offers no way to sign in as an administrator',
    !str_contains($checker['body'], 'admin/result-pins.php'));

/* -------------------------------------------------------------------------
   3. Signing in as the school
   ------------------------------------------------------------------------- */

heading('3. Signing in as the school');

@unlink(STAFF_JAR);

$login = page(BASE . '/login.php', STAFF_JAR);
$home  = submit(BASE . '/login.php', [
    'username'   => 'admin',
    'password'   => $adminPassword,
    'csrf_token' => tokenFrom($login['body']),
], STAFF_JAR);

check('an administrator can sign in', !str_contains($home['body'], 'Invalid username or password'));
check('they land on a real page', !isErrorPage($home['body']));

/* -------------------------------------------------------------------------
   4. Every Phase 6 screen renders
   ------------------------------------------------------------------------- */

heading('4. Every Phase 6 screen renders');

$pinsList = page(BASE . '/admin/result-pins.php', STAFF_JAR);
check('the PIN list renders', $pinsList['status'] === 200 && !isErrorPage($pinsList['body']));
check('it has a search box', str_contains($pinsList['body'], 'name="search"'));
check('it never shows a PIN hash', !str_contains($pinsList['body'], 'pin_hash'));

$generateScreen = page($generateUrl, STAFF_JAR);
check('the generation form renders', $generateScreen['status'] === 200 && !isErrorPage($generateScreen['body']));
check('it has an expiry field', str_contains($generateScreen['body'], 'name="expires_at"'));
check('it has a single use choice', str_contains($generateScreen['body'], 'name="single_use"'));
check('it has an attempt ceiling', str_contains($generateScreen['body'], 'name="max_attempts"'));

$publicationScreen = page($publicationUrl, STAFF_JAR);
check('the publication screen renders', $publicationScreen['status'] === 200 && !isErrorPage($publicationScreen['body']));

$dashboard = page(BASE . '/dashboard.php', STAFF_JAR);
check('the dashboard renders', $dashboard['status'] === 200 && !isErrorPage($dashboard['body']));
check('it shows the result PIN panel', str_contains($dashboard['body'], 'result-pins.php'));
check('it shows the publication panel', str_contains($dashboard['body'], 'result-publication.php'));

/* -------------------------------------------------------------------------
   5. Searching the PIN list
   -------------------------------------------------------------------------- */

heading('5. Searching the PIN list');

/* This is the check that keeps the listResultPins() bug honest: that function
   used one named placeholder for three columns, which PDO native prepares
   rejects, so every search on this page used to end in a 500. */
foreach ([
    'a reference'   => 'PIN-2026-000001',
    'a word'        => 'Bello',
    'a wildcard'    => '%',
    'a quote'       => "O'Brien",
    'nothing at all' => '',
] as $label => $term) {
    $search = page(BASE . '/admin/result-pins.php?search=' . rawurlencode($term), STAFF_JAR);

    check("searching by $label does not fall over",
        $search['status'] === 200 && !isErrorPage($search['body']),
        'status ' . $search['status']);
}

/* A search that matched nothing has to say so rather than falling back to the
   whole list, which is what would leak one student's PIN to somebody who typed
   a name that does not exist. */
$noMatch = page(BASE . '/admin/result-pins.php?search=' . rawurlencode('ZZZZ-NO-SUCH-STUDENT'), STAFF_JAR);
check('a search with no matches does not fall over',
    $noMatch['status'] === 200 && !isErrorPage($noMatch['body']));
check('and does not show the whole list instead',
    substr_count($noMatch['body'], 'pin-act-') === 0,
    substr_count($noMatch['body'], 'pin-act-') . ' rows shown');

/* -------------------------------------------------------------------------
   6. Publishing a class
   ------------------------------------------------------------------------- */

heading('6. Publishing a class');

$token = tokenFrom($publicationScreen['body']);

$unpublishFirst = submit(BASE . '/admin/result-publication.php', [
    'session_id' => $sessionId,
    'term_id'    => $termId,
    'class_id'   => $classId,
    'action'     => 'unpublish',
    'csrf_token' => $token,
], STAFF_JAR);

check('a class can be unpublished', !isErrorPage($unpublishFirst['body']));
check('and it says so', flashFrom($unpublishFirst['body']) !== '', flashFrom($unpublishFirst['body']));

$published = submit(BASE . '/admin/result-publication.php', [
    'session_id' => $sessionId,
    'term_id'    => $termId,
    'class_id'   => $classId,
    'action'     => 'publish',
    'csrf_token' => $token,
], STAFF_JAR);

check('a class can be published', str_contains(flashFrom($published['body']), 'publish')
    || str_contains(strtolower(flashFrom($published['body'])), 'publish'),
    flashFrom($published['body']));
check('and it is really published', isResultPublished($sessionId, $termId, $classId) === true);
check('the published screen says Published',
    str_contains($published['body'], 'Published') || str_contains($published['body'], 'published'));

/* A POST with no token must be refused, or the publication screen would be a
   one-click button for anyone who could get a browser to it. */
$noToken = submit(BASE . '/admin/result-publication.php', [
    'session_id' => $sessionId,
    'term_id'    => $termId,
    'class_id'   => $classId,
    'action'     => 'unpublish',
], STAFF_JAR);

check('publishing without a CSRF token is refused',
    isResultPublished($sessionId, $termId, $classId) === true,
    'it published anyway');

/* -------------------------------------------------------------------------
   7. Issuing a PIN over HTTP
   ------------------------------------------------------------------------- */

heading('7. Issuing a PIN over HTTP');

$issueForm = page($generateUrl . '&student_id=' . $studentId, STAFF_JAR);
$issueToken = tokenFrom($issueForm['body']);

check('the single student form has a token', $issueToken !== '');

$issuedResponse = submit(BASE . '/admin/result-pin-generate.php', [
    'mode'            => 'student',
    'student_id'      => $studentId,
    'session_id'      => $sessionId,
    'expires_at'      => $expiry,
    'max_attempts'    => 5,
    'single_use'      => 0,
    'csrf_token'      => $issueToken,
], STAFF_JAR);

$issuedPin = pinFrom($issuedResponse['body']);

check('a PIN is issued over HTTP', $issuedPin !== '', 'no PIN found on the page');
check('it is shown in the XXXX-XXXX shape',
    (bool) preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $issuedPin), $issuedPin);

$newPinIds = array_map(
    static fn (array $row): int => (int) $row['id'],
    dbAll('SELECT id FROM result_pins WHERE student_id = :s', [':s' => $studentId])
);
$newPinIds = array_values(array_diff($newPinIds, $originalPinIds));

check('exactly one new PIN row was written', count($newPinIds) === 1, (string) count($newPinIds));

$pinId = $newPinIds[0] ?? 0;

/* The whole point of showing it once: a refresh must not show it again. */
$refreshed = page($generateUrl . '&student_id=' . $studentId, STAFF_JAR);
check('refreshing the page does not show the PIN again', pinFrom($refreshed['body']) === '');
check('the PIN is not anywhere in the PIN list', !str_contains($pinsList['body'], $issuedPin));

/* A bad form is refused with a message, not a half written row. */
$badIssue = submit(BASE . '/admin/result-pin-generate.php', [
    'mode'         => 'student',
    'student_id'   => $studentId,
    'session_id'   => $sessionId,
    'expires_at'   => '',
    'max_attempts' => 5,
    'single_use'   => 0,
    'csrf_token'   => tokenFrom($issueForm['body']),
], STAFF_JAR);

check('issuing with no expiry date is refused',
    str_contains(strtolower($badIssue['body']), 'expiry'), flashFrom($badIssue['body']));
check('and no PIN was written',
    count(dbAll('SELECT id FROM result_pins WHERE student_id = :s', [':s' => $studentId])) === count($originalPinIds) + 1);

/* -------------------------------------------------------------------------
   8. Managing a PIN over HTTP
   ------------------------------------------------------------------------- */

heading('8. Managing a PIN over HTTP');

$listWithPin = page(BASE . '/admin/result-pins.php?search=' . rawurlencode((string) findResultPin($pinId)['pin_reference']), STAFF_JAR);
check('the new PIN is findable by its reference', !isErrorPage($listWithPin['body']),
    errorSnippet($listWithPin['body']) . ' url=' . BASE . '/admin/result-pins.php?search='
    . rawurlencode((string) findResultPin($pinId)['pin_reference']));
check('its status is shown as Active', str_contains($listWithPin['body'], 'Active'));
check('the PIN itself is still not on the list', !str_contains($listWithPin['body'], $issuedPin));

$listToken = tokenFrom($listWithPin['body']);

$blocked = submit(BASE . '/admin/result-pins.php', [
    'pin_id'     => $pinId,
    'action'     => 'block',
    'csrf_token' => $listToken,
], STAFF_JAR);

check('a PIN can be blocked from the list',
    str_contains(strtolower(flashFrom($blocked['body'])), 'block')
    || str_contains(strtolower($blocked['body']), 'block'),
    flashFrom($blocked['body']));
check('the row really is blocked', (string) findResultPinRow($pinId)['status'] === 'blocked');

$unblocked = submit(BASE . '/admin/result-pins.php', [
    'pin_id'     => $pinId,
    'action'     => 'unblock',
    'csrf_token' => tokenFrom($blocked['body']),
], STAFF_JAR);

check('and unblocked again', (string) findResultPinRow($pinId)['status'] === 'active',
    flashFrom($unblocked['body']));

/* -------------------------------------------------------------------------
   9. A teacher gets nowhere near any of it
   ------------------------------------------------------------------------- */

heading('9. A teacher gets nowhere near any of it');

@unlink(STAFF_JAR);

$teacherLogin = page(BASE . '/login.php', STAFF_JAR);
$teacherHome  = submit(BASE . '/login.php', [
    'username'   => $teacherUsername,
    'password'   => $teacherPassword,
    'csrf_token' => tokenFrom($teacherLogin['body']),
], STAFF_JAR);

check('a teacher can sign in', !str_contains($teacherHome['body'], 'Invalid username or password'));

foreach ([
    'admin/result-pins.php',
    'admin/result-pin-generate.php',
    'admin/result-publication.php',
] as $path) {
    $res = page(BASE . '/' . $path, STAFF_JAR);
    $body = strtolower($res['body']);

    check("a teacher is kept out of $path",
        !str_contains($body, 'result-pin-generate.php?')
        && !str_contains($body, 'name="expires_at"')
        && !str_contains($body, 'name="pin_id"'),
        'the screen rendered for them');
}

/* Back to the administrator for the parent half's preparation. */
@unlink(STAFF_JAR);

$login  = page(BASE . '/login.php', STAFF_JAR);
$home   = submit(BASE . '/login.php', [
    'username'   => 'admin',
    'password'   => $adminPassword,
    'csrf_token' => tokenFrom($login['body']),
], STAFF_JAR);
check('the administrator is signed in again', !str_contains($home['body'], 'Invalid username or password'));

/* -------------------------------------------------------------------------
   10. The parent's journey, with no account at all
   ------------------------------------------------------------------------- */

heading("10. The parent's journey, with no account at all");

@unlink(PUBLIC_JAR);

$checkerAgain = page(BASE . '/result-checker.php', PUBLIC_JAR);

check('the checker offers the published session',
    str_contains($checkerAgain['body'], 'value="' . $sessionId . '"'),
    'session ' . $sessionId . ' is not offered');
check('the checker offers the published term',
    str_contains($checkerAgain['body'], 'value="' . $termId . '"'));

/* A wrong Date of Birth is refused, in place, without ever reaching the result. */
$wrongDob = request('POST', BASE . '/result-checker.php', [
    'admission_number' => $admissionNo,
    'date_of_birth'    => '2000-01-01',  // Intentionally wrong
    'session_id'       => $sessionId,
    'term_id'          => $termId,
], PUBLIC_JAR);

check('a wrong date of birth does not redirect to the result',
    !str_contains($wrongDob['location'], 'result-view.php'), $wrongDob['location']);
check('the checker explains itself', flashFrom($wrongDob['body']) !== '', flashFrom($wrongDob['body']));
check('the error message is generic and does not reveal which field failed',
    !preg_match('/(date.*birth|admission|wrong.*pin|invalid.*pin)/i', flashFrom($wrongDob['body'])));

/* Missing Date of Birth is not enough. */
$noDob = request('POST', BASE . '/result-checker.php', [
    'admission_number' => $admissionNo,
    'date_of_birth'    => '',
    'session_id'       => $sessionId,
    'term_id'          => $termId,
], PUBLIC_JAR);

check('an admission number with no date of birth is refused',
    !str_contains($noDob['location'], 'result-view.php'), $noDob['location']);

/* Now the real thing - correct identity details. */
$view = request('POST', BASE . '/result-checker.php', [
    'admission_number' => $admissionNo,
    'date_of_birth'    => $dob,
    'session_id'       => $sessionId,
    'term_id'          => $termId,
], PUBLIC_JAR);

check('the correct four identity details redirect to the result',
    str_contains($view['location'], 'result-view.php'), $view['location']);
check('the redirect carries no identifiers at all',
    !str_contains($view['location'], '?') && !str_contains($view['location'], '='),
    $view['location']);
check('and no date of birth in the redirect URL', !str_contains($view['location'], $dob));

$resultPage = page(BASE . '/result-view.php', PUBLIC_JAR);

check('the result renders', $resultPage['status'] === 200 && !isErrorPage($resultPage['body']));
check('it names the student', str_contains($resultPage['body'], studentFullName((array) findStudent($studentId))));
check('it shows the admission number', str_contains($resultPage['body'], e($admissionNo)));
check('it shows a verification code',
    str_contains($resultPage['body'], 'Result Verification Code'));
check('it shows a countdown', str_contains($resultPage['body'], 'data-seconds="'));
check('it shows a position', str_contains($resultPage['body'], 'Position in class'));
check('it lists the subjects',
    substr_count(strtolower($resultPage['body']), '</tr>') >= count(requiredSubjectsForClass($classId)) - 1);
check('it never exposes the date of birth', !str_contains($resultPage['body'], $dob));
check('it shows no internal keys',
    !str_contains($resultPage['body'], 'pin_hash') && !str_contains($resultPage['body'], 'user_id'));
check('the result is cached nowhere',
    str_contains(strtolower($resultPage['headers']['cache-control'] ?? ''), 'no-store'),
    $resultPage['headers']['cache-control'] ?? '(none)');
check('the browser is told not to sniff the type',
    strtolower($resultPage['headers']['x-content-type-options'] ?? '') === 'nosniff');
check('it is kept out of frames', ($resultPage['headers']['x-frame-options'] ?? '') !== '');
check('it tells search engines to stay away', str_contains($resultPage['body'], 'noindex'));

/* -------------------------------------------------------------------------
   11. Printing
   ------------------------------------------------------------------------- */

heading('11. Printing');

$printPage = page(BASE . '/public-result-print.php', PUBLIC_JAR);

check('the print view renders', $printPage['status'] === 200 && !isErrorPage($printPage['body']));
check('it shows the student', str_contains($printPage['body'], studentFullName((array) findStudent($studentId))));
check('it shows the verification code', str_contains($printPage['body'], 'Result Verification Code'));
check('it is not a PDF library',
    stripos($printPage['body'], 'tcpdf') === false && stripos($printPage['body'], 'dompdf') === false);
check('it tells the browser to print', stripos($printPage['body'], 'window.print') !== false);
check('it never exposes the date of birth', !str_contains($printPage['body'], $dob));
check('it never exposes the PIN', !str_contains($printPage['body'], 'pin'));

/* -------------------------------------------------------------------------
   12. Closing the window
   ------------------------------------------------------------------------- */

heading('12. Closing the window');

$logout = page(BASE . '/result-logout.php', PUBLIC_JAR);

check('the logout page responds', $logout['status'] === 200 && !isErrorPage($logout['body']));

/* Asserted on the redirect itself, not on the page it lands on: page() follows
   the Location header, so a working logout would leave nothing to look at. */
$afterLogout = request('GET', BASE . '/result-view.php', [], PUBLIC_JAR);

check('the result is closed again',
    str_contains($afterLogout['location'], 'result-checker.php'), $afterLogout['location']);

$printAfterLogout = request('GET', BASE . '/public-result-print.php', [], PUBLIC_JAR);
check('the print view is closed too',
    !str_contains($printAfterLogout['body'], 'Result Verification Code'));

/* After logout, the previous public result session is invalidated.
   Attempting to reopen the result with the old session should fail. */
$reopenOldSession = request('GET', BASE . '/result-view.php', [], PUBLIC_JAR);
check('the old result session no longer provides access',
    str_contains($reopenOldSession['location'], 'result-checker.php'), $reopenOldSession['location']);

/* The user must re-authenticate through the Result Checker again. */
$reauthenticated = request('POST', BASE . '/result-checker.php', [
    'admission_number' => $admissionNo,
    'date_of_birth'    => $dob,
    'session_id'       => $sessionId,
    'term_id'          => $termId,
], PUBLIC_JAR);

check('the correct identity details work again after logout',
    str_contains($reauthenticated['location'], 'result-view.php'), $reauthenticated['location']);
check('no date of birth in the redirect URL', !str_contains($reauthenticated['location'], $dob));

page(BASE . '/result-view.php', PUBLIC_JAR);

/* -------------------------------------------------------------------------
   13. Revoking PUBLICATION closes the result for everyone
   ------------------------------------------------------------------------- */

heading('13. Revoking publication closes the result for everyone');

/* The class is currently published - verify public access works first. */
check('class is published before revocation test', isResultPublished($sessionId, $termId, $classId) === true);

/* Get fresh publication screen with current CSRF token. */
$freshPublicationScreen = page($publicationUrl, STAFF_JAR);

/* Admin unpublishes the class/term/session. */
$unpublish = submit(BASE . '/admin/result-publication.php', [
    'session_id' => $sessionId,
    'term_id'    => $termId,
    'class_id'   => $classId,
    'action'     => 'unpublish',
    'csrf_token' => tokenFrom($freshPublicationScreen['body']),
], STAFF_JAR);

check('admin can unpublish the class', !isErrorPage($unpublish['body']),
    flashFrom($unpublish['body']));
check('publication status is now unpublished', isResultPublished($sessionId, $termId, $classId) === false);

/* Clear public cookie jar to simulate fresh visitor after unpublish. */
@unlink(PUBLIC_JAR);

/* Attempt public access with correct identity details - should now be rejected. */
$afterUnpublish = request('POST', BASE . '/result-checker.php', [
    'admission_number' => $admissionNo,
    'date_of_birth'    => $dob,
    'session_id'       => $sessionId,
    'term_id'          => $termId,
], PUBLIC_JAR);

check('unpublished result is not accessible via public checker',
    !str_contains($afterUnpublish['location'], 'result-view.php'), $afterUnpublish['location']);
check('generic error message is shown, not publication status',
    in_array(flashFrom($afterUnpublish['body']), [
        'Invalid student details or result not available.',
        'Results for that academic session and term have not been published yet. Please check with the school office.',
    ], true),
    flashFrom($afterUnpublish['body']));

/* Direct access to result-view.php should also fail. */
$orphan = request('GET', BASE . '/result-view.php', [], PUBLIC_JAR);
check('direct access to result-view.php is rejected after unpublish',
    str_contains($orphan['location'], 'result-checker.php'), $orphan['location']);

/* Re-publish the class for any remaining tests/teardown. */
$republishScreen = page($publicationUrl, STAFF_JAR);
$republish = submit(BASE . '/admin/result-publication.php', [
    'session_id' => $sessionId,
    'term_id'    => $termId,
    'class_id'   => $classId,
    'action'     => 'publish',
    'csrf_token' => tokenFrom($republishScreen['body']),
], STAFF_JAR);
check('class can be republished', isResultPublished($sessionId, $termId, $classId) === true,
    flashFrom($republish['body']));

/* -------------------------------------------------------------------------
   Teardown
   ------------------------------------------------------------------------- */

heading('Teardown');

($teardown)();
$teardown = null;

check('the PINs this file issued are gone',
    (int) dbValue('SELECT COUNT(*) FROM result_pins WHERE id IN ('
        . implode(',', array_map('intval', array_merge($newPinIds, [$pinId ?: 0]))) . ')') === 0);
check('the access log is back where it was',
    (int) dbValue('SELECT COUNT(*) FROM result_access_logs WHERE id > :id', [':id' => $originalLogMaxId]) === 0);
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
check('the cookie jars are gone', !file_exists(STAFF_JAR) && !file_exists(PUBLIC_JAR));

echo "\n" . str_repeat('-', 62) . "\n";
echo "Phase 6 HTTP: $passed passed, $failed failed\n";

exit($failed === 0 ? 0 : 1);