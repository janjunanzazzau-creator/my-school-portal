<?php

declare(strict_types=1);

/**
 * Report card verification.
 *
 * Builds one complete report card for a sample student, then checks the printed
 * sheet for all the figures a real card has to carry. Runs only the report-card
 * pages; it does not touch the rest of the portal.
 *
 * Leaves the card in place when KEEP=1 so the layout can be inspected in a
 * browser. Otherwise it removes everything it created.
 *
 * Usage:  php tests/report-card-check.php
 */

require_once __DIR__ . '/../config/functions.php';

$BASE        = 'http://localhost/school-result-portal';
$jar         = __DIR__ . '/report-card-cookies.txt';
$keep        = getenv('KEEP') === '1';
$adminPass   = getenv('ADMIN_PASSWORD') ?: 'Admin@12345';

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

function isErrorPage(string $html): bool
{
    return stripos($html, 'Development error') !== false
        || stripos($html, 'Fatal error') !== false
        || stripos($html, 'Warning:') !== false
        || stripos($html, 'Notice:') !== false
        || stripos($html, 'SQLSTATE') !== false;
}

function request(string $method, string $url, array $fields = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $GLOBALS['jar'],
        CURLOPT_COOKIEFILE     => $GLOBALS['jar'],
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HEADER         => true,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }

    $raw     = (string) curl_exec($ch);
    $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headers = substr($raw, 0, (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE));
    curl_close($ch);

    preg_match('/^Location:\s*(.+)$/mi', $headers, $m);

    return [
        'status'   => $status,
        'body'     => substr($raw, strlen($headers)),
        'headers'  => $headers,
        'location' => isset($m[1]) ? trim($m[1]) : '',
    ];
}

function absolute(string $location): string
{
    return preg_match('#^https?://#i', $location) === 1
        ? $location
        : 'http://localhost/' . ltrim($location, '/');
}

function follow(array $res): array
{
    $guard = 0;

    while ($res['status'] >= 300 && $res['status'] < 400 && $res['location'] !== '' && $guard < 6) {
        $res = request('GET', absolute($res['location']));
        $guard++;
    }

    return $res;
}

function get(string $url): array
{
    return follow(request('GET', $url));
}

/* ====================================================================== */

heading('0. Fixture');

$pdo       = getDbConnection();
$sessionId = (int) dbValue('SELECT id FROM academic_sessions WHERE is_current = 1 LIMIT 1');
$termId    = (int) dbValue(
    'SELECT t.id FROM terms t WHERE t.session_id = :s AND t.status = "Active" ORDER BY t.id LIMIT 1',
    [':s' => $sessionId]
);
$classId   = (int) dbValue('SELECT id FROM classes WHERE class_name = "Primary 1" AND section = "Primary" LIMIT 1');

$roster    = classResultRoster($classId, $sessionId);
$studentId = $roster === [] ? 0 : (int) $roster[0]['student_id'];
$student   = findStudent($studentId);

check('session, term and class resolved', $sessionId > 0 && $termId > 0 && $classId > 0);
check('the class has a student', $studentId > 0 && $student !== null);

/* Clear anything an earlier run left behind so the card we build is the card
   we measure. */
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

/* Give the sample student a date of birth and a guardian, so the identity panel
   has something real to print rather than a column of "Not recorded". */
$pdo->prepare('UPDATE students SET date_of_birth = :dob, parent_name = :pn, parent_phone = :pp
               WHERE id = :id AND date_of_birth IS NULL')
    ->execute([':dob' => '2016-03-14', ':pn' => 'Mr. Bello Musa', ':pp' => '+234 803 555 0110', ':id' => $studentId]);

/* Attendance, so the panel is populated. */
dbRun(
    'INSERT INTO attendance_summary
        (student_id, class_id, session_id, term_id, school_days, days_present, days_absent)
     VALUES (:student_id, :class_id, :session_id, :term_id, :days, :present, :absent)',
    [
        ':student_id' => $studentId,
        ':class_id'   => $classId,
        ':session_id' => $sessionId,
        ':term_id'    => $termId,
        ':present'    => 48,
        ':absent'     => 12,
        ':days'       => 60,
    ]
);

/* Both character assessments, so every trait and area prints a real rating. */
dbRun(
    'INSERT INTO psychomotor_assessments
        (student_id, class_id, session_id, term_id, punctuality, neatness, honesty, cooperation,
         leadership, attentiveness, perseverance, politeness, self_control, responsibility)
     VALUES (:student_id, :class_id, :session_id, :term_id, 5, 4, 5, 4, 3, 5, 4, 5, 4, 4)',
    [':student_id' => $studentId, ':class_id' => $classId, ':session_id' => $sessionId, ':term_id' => $termId]
);

dbRun(
    'INSERT INTO effective_areas
        (student_id, class_id, session_id, term_id, communication, creativity, problem_solving,
         teamwork, critical_thinking, practical_skills)
     VALUES (:student_id, :class_id, :session_id, :term_id, 4, 5, 4, 5, 3, 4)',
    [':student_id' => $studentId, ':class_id' => $classId, ':session_id' => $sessionId, ':term_id' => $termId]
);

/* Fill every subject the class offers with an approved result, so the card can
   be generated and the grade analysis has something to count. Scores are varied
   on purpose: one A, one B, one C and one D, so the distribution panel and the
   highest/lowest figures are exercised rather than all showing the same letter.
   The original rows are snapshotted first and put back at the end. */
$required = requiredSubjectsForClass($classId);
$subjects = array_values($required);
$scoreSet = [
    [9, 9, 9, 68],   // 95 - A
    [8, 8, 8, 58],   // 82 - B
    [7, 7, 6, 42],   // 62 - C
    [6, 6, 5, 20],   // 37 - F
    [8, 9, 8, 63],   // 88 - B
    [9, 8, 9, 66],   // 92 - A
    [7, 6, 7, 40],   // 60 - B
    [8, 8, 7, 55],   // 78 - B
    [6, 7, 6, 30],   // 49 - D
];

$originalResults = dbAll(
    'SELECT * FROM results WHERE student_id = :s AND class_id = :c AND session_id = :sess AND term_id = :t',
    [':s' => $studentId, ':c' => $classId, ':sess' => $sessionId, ':t' => $termId]
);

$teacherId = (int) dbValue('SELECT id FROM teachers ORDER BY id LIMIT 1');

$write = $pdo->prepare(
    "INSERT INTO results
        (student_id, class_id, subject_id, session_id, term_id, teacher_id,
         test_1, test_2, test_3, examination, total, grade, grade_remark, status, approved_at, approved_by)
     VALUES
        (:student_id, :class_id, :subject_id, :session_id, :term_id, :teacher_id,
         :test_1, :test_2, :test_3, :examination, :total, :grade, :grade_remark, 'approved', NOW(), 1)
     ON DUPLICATE KEY UPDATE
        test_1 = VALUES(test_1), test_2 = VALUES(test_2), test_3 = VALUES(test_3),
        examination = VALUES(examination), total = VALUES(total), grade = VALUES(grade),
        grade_remark = VALUES(grade_remark), status = 'approved', approved_at = NOW(), approved_by = 1"
);

foreach ($subjects as $index => $subject) {
    $s = $scoreSet[$index % count($scoreSet)];
    $total = $s[0] + $s[1] + $s[2] + $s[3];
    $grade = calculateGrade((float) $total, assessmentStructure('Primary')['max_total']);

    $write->execute([
        ':student_id' => $studentId,
        ':class_id'   => $classId,
        ':subject_id' => (int) $subject['id'],
        ':session_id' => $sessionId,
        ':term_id'    => $termId,
        ':teacher_id' => $teacherId,
        ':test_1'     => $s[0],
        ':test_2'     => $s[1],
        ':test_3'     => $s[2],
        ':examination' => $s[3],
        ':total'      => $total,
        ':grade'      => (string) $grade['grade'],
        ':grade_remark' => (string) $grade['remark'],
    ]);
}

$outcome = generateReportCard($studentId, $classId, $sessionId, $termId);
check('a report card was generated', $outcome['ok'] === true, (string) ($outcome['message'] ?? ''));

if ($outcome['ok'] === false) {
    echo "\nAborting: no card to check.\n";
    exit(1);
}

$reportId = (int) $outcome['id'];
$card     = findReportCard($reportId);

/* Comments and an outcome, so the remarks area is not empty boxes. */
dbRun(
    'UPDATE report_cards
        SET teacher_comment = :tc, principal_comment = :pc, promotion_status = :ps
      WHERE id = :id',
    [
        ':tc' => 'Aisha is attentive and hardworking. She should keep reading widely.',
        ':pc' => 'Very good progress this term. Maintain the discipline.',
        ':ps' => 'Promoted',
        ':id' => $reportId,
    ]
);

ensureReportCardVerificationCode($reportId);
$card = findReportCard($reportId);

echo "  report card id: $reportId\n";
echo "  report number:  {$card['report_number']}\n";
echo "  verify code:    {$card['verification_code']}\n";

/* ====================================================================== */

heading('1. Admin print renders');

@unlink($jar);
$login = get($BASE . '/login.php');
preg_match('/name="csrf_token" value="([^"]+)"/', $login['body'], $m);
$token = $m[1] ?? '';

$dash = follow(request('POST', $BASE . '/login.php', [
    'username'   => 'admin',
    'password'   => $adminPass,
    'csrf_token' => $token,
]));
check('administrator signed in', $dash['status'] === 200 && !isErrorPage($dash['body']));

$adminPrint = get($BASE . '/admin/print-report-card.php?id=' . $reportId);
check('admin print returns 200', $adminPrint['status'] === 200, (string) $adminPrint['status']);
check('admin print is not an error page', !isErrorPage($adminPrint['body']));

file_put_contents(__DIR__ . '/report-card-admin.html', $adminPrint['body']);

/* ====================================================================== */

heading('2. Data is correct');

$rc = reportCardViewData($reportId, true);
$body = $adminPrint['body'];

check('student name printed', str_contains($body, e((string) $rc['student_name'])));
check('admission number printed', str_contains($body, e((string) $rc['admission_number'])));
check('gender printed', str_contains($body, e((string) $rc['gender'])));
check('age printed', str_contains($body, e((string) $rc['age'] . ' yrs')));
check('guardian printed', str_contains($body, e((string) $rc['guardian'])));
check('class printed', str_contains($body, e((string) $rc['class_name'])));
check('term printed', str_contains($body, e((string) $rc['term_name'])));
check('session printed', str_contains($body, e((string) $rc['session_name'])));
check('report number printed', str_contains($body, e((string) $rc['report_number'])));
check('verification code printed', str_contains($body, 'Result Verification Code'));

check('every subject is printed', (function () use ($body, $classId): bool {
    foreach (requiredSubjectsForClass($classId) as $subject) {
        if (!str_contains($body, e((string) $subject['subject_name']))) {
            return false;
        }
    }

    return requiredSubjectsForClass($classId) !== [];
})());

check('a grade is printed', str_contains($body, 'class="rc-grade"'));
check('the aggregate total is printed', str_contains($body, e(rtrim(rtrim(number_format((float) $rc['total_obtained'], 2, '.', ''), '0'), '.'))));
check('class position is printed', str_contains($body, 'Position in class'));
check(
    'the position value is printed',
    $rc['position'] === null || str_contains($body, e(ordinalSuffix((int) $rc['position']))),
    'position ' . var_export($rc['position'], true)
);

check('affective domain ratings printed', str_contains($body, 'Affective Domain') && str_contains($body, 'Communication'));
check('psychomotor ratings printed', str_contains($body, 'Psychomotor Skills') && str_contains($body, 'Punctuality'));
check('grade legend printed', str_contains($body, 'Grading Legend') && str_contains($body, 'Excellent'));
check('grade analysis printed', str_contains($body, 'Grade') && str_contains($body, 'Share'));
check('performance summary printed', str_contains($body, 'Performance Summary'));
check('total obtainable printed', str_contains($body, 'Total obtainable'));
check('highest and lowest printed', str_contains($body, 'Highest score') && str_contains($body, 'Lowest score'));
check('teacher comment printed', str_contains($body, e((string) $rc['teacher_comment'])));
check('head teacher comment printed', str_contains($body, e((string) $rc['principal_comment'])));
check('parent comment box printed', str_contains($body, 'Parent / Guardian'));
check('promotion printed', str_contains($body, e((string) $rc['promotion'])));
check('attendance printed', str_contains($body, 'Attendance'));

/* ====================================================================== */

heading('3. Identity is dynamic');

$settings = getSchoolSettings();
check('school name comes from school_settings', str_contains($body, e((string) $settings['school_name'])));
check('logo path comes from school_settings', str_contains($body, e(assetUrl((string) ($settings['logo_path'] ?? '')))));

/* Prove it is genuinely dynamic: rename the school, re-render, restore. */
$original = (string) $settings['school_name'];
updateSchoolSettings(['school_name' => 'PROBE DYNAMIC NAME LTD']);
$probe = get($BASE . '/admin/print-report-card.php?id=' . $reportId);
updateSchoolSettings(['school_name' => $original]);

check('a changed school name reaches the sheet', str_contains($probe['body'], 'PROBE DYNAMIC NAME LTD'));
check('the original name is restored', getSchoolSettings()['school_name'] === $original);

/* ====================================================================== */

heading('4. Registration number');

$regNo = (string) $card['admission_number'];
check('the stored admission number is printed in full', str_contains($body, e($regNo)));
check('it is printed in the identity panel, not only in the footer', substr_count($body, e($regNo)) >= 1);

/* The Phase 7 generator writes ABBR/YY/SEQ. Checked against the generator
   itself rather than against whatever the sample rows happen to hold, because
   rows created before Phase 7 carry an older four-digit-year shape and that is
   historical data, not a bug in the card. */
$probeNumber = null;
$pdo->beginTransaction();

try {
    $probeNumber = generateRegistrationNumber();
    $pdo->rollBack();
} catch (Throwable $exception) {
    $pdo->rollBack();
}

check('the generator produces ABBR/YY/SEQ',
    is_string($probeNumber) && preg_match('#^[A-Z0-9]+/\d{2}/\d{3,}$#', (string) $probeNumber) === 1,
    var_export($probeNumber, true));
check('the generator uses the configured abbreviation',
    is_string($probeNumber) && str_starts_with($probeNumber, strtoupper((string) $settings['school_short_name']) . '/'),
    var_export($probeNumber, true));

/* ====================================================================== */

heading('5. Nothing sensitive leaks');

check('no PIN anywhere', stripos($body, 'pin') === false);
check('no date of birth printed', !str_contains($body, (string) ($student['date_of_birth'] ?? '')) || trim((string) ($student['date_of_birth'] ?? '')) === '');
check('no password field', stripos($body, 'password') === false);
check('no pin_hash or user_id', !str_contains($body, 'pin_hash') && !str_contains($body, 'user_id'));
check('no debug output', stripos($body, 'var_dump') === false && stripos($body, 'print_r') === false);

/* ====================================================================== */

heading('6. A4 print behaviour');

check('print stylesheet linked', str_contains($body, 'assets/css/report-card.css'));
check('A4 portrait declared', str_contains($body, 'print-body'));
check('print button offered', str_contains($body, 'window.print()'));
check('no browser chrome in the sheet', !str_contains($body, '<nav') && !str_contains($body, '<form'));
check('sheet is a single article', substr_count($body, '<article class="rc-sheet">') === 1);
check('no overflow-x scrollbar forced in print', !str_contains($body, 'min-width:900px'));
check('page-break-inside guards present', str_contains($body, 'rc-sheet') || str_contains($body, 'rc-table'));

/* ====================================================================== */

heading('7. Public print renders');

$publicJar = __DIR__ . '/report-card-public-cookies.txt';
@unlink($publicJar);

$admission = (string) $card['admission_number'];
$dob       = (string) $student['date_of_birth'];

dbRun(
    'INSERT INTO result_publication (session_id, term_id, class_id, status)
     VALUES (:s, :t, :c, "Published")
     ON DUPLICATE KEY UPDATE status = "Published"',
    [':s' => $sessionId, ':t' => $termId, ':c' => $classId]
);

dbRun('UPDATE report_cards SET status = "locked" WHERE id = :id', [':id' => $reportId]);

$checker = get2($BASE . '/result-checker.php', $publicJar);
preg_match('/name="csrf_token" value="([^"]+)"/', $checker['body'], $m);
$token2 = $m[1] ?? '';

$posted = request2('POST', $BASE . '/result-checker.php', [
    'admission_number' => $admission,
    'date_of_birth'    => $dob,
    'session_id'       => (string) $sessionId,
    'term_id'          => (string) $termId,
    'csrf_token'       => $token2,
], $publicJar);

$view = follow2($posted, $publicJar);
check('public result view renders', $view['status'] === 200 && !isErrorPage($view['body']));
check('public result never prints the date of birth', !str_contains($view['body'], $dob));

$publicPrint = follow2(request2('GET', $BASE . '/public-result-print.php', [], $publicJar), $publicJar);
check('public print returns 200', $publicPrint['status'] === 200, (string) $publicPrint['status']);
check('public print is not an error page', !isErrorPage($publicPrint['body']));
check('public print names the student', str_contains($publicPrint['body'], e((string) $rc['student_name'])));
check('public print carries the verification code', str_contains($publicPrint['body'], 'Result Verification Code'));
check('public print hides the guardian phone', !str_contains($publicPrint['body'], (string) $rc['guardian_phone']));
check('public print uses the same sheet', substr_count($publicPrint['body'], '<article class="rc-sheet">') === 1);

file_put_contents(__DIR__ . '/report-card-public.html', $publicPrint['body']);

/* ====================================================================== */

@unlink($jar);
@unlink($publicJar);

if ($keep) {
    echo "\nKEEP=1: report card $reportId left in place, HTML written to tests/report-card-admin.html and tests/report-card-public.html\n";
    echo "View it at: $BASE/admin/print-report-card.php?id=$reportId\n";
} else {
    $pdo->prepare('DELETE FROM report_card_history WHERE report_card_id = :id')->execute([':id' => $reportId]);
    $pdo->prepare('DELETE FROM report_cards WHERE id = :id')->execute([':id' => $reportId]);

    /* Put the result sheet, the attendance and the character assessments back the
       way they were found, so this file leaves no trace behind. */
    $pdo->prepare('DELETE FROM results
                    WHERE student_id = :s AND class_id = :c AND session_id = :sess AND term_id = :t')
        ->execute([':s' => $studentId, ':c' => $classId, ':sess' => $sessionId, ':t' => $termId]);

    $restore = $pdo->prepare(
        'INSERT INTO results
            (student_id, class_id, subject_id, session_id, term_id, teacher_id,
             test_1, test_2, test_3, examination, total, grade, grade_remark, status)
         VALUES
            (:student_id, :class_id, :subject_id, :session_id, :term_id, :teacher_id,
             :test_1, :test_2, :test_3, :examination, :total, :grade, :grade_remark, :status)'
    );

    foreach ($originalResults as $row) {
        $restore->execute([
            ':student_id' => $row['student_id'],
            ':class_id'   => $row['class_id'],
            ':subject_id' => $row['subject_id'],
            ':session_id' => $row['session_id'],
            ':term_id'    => $row['term_id'],
            ':teacher_id' => $row['teacher_id'],
            ':test_1'     => $row['test_1'],
            ':test_2'     => $row['test_2'],
            ':test_3'     => $row['test_3'],
            ':examination' => $row['examination'],
            ':total'      => $row['total'],
            ':grade'      => $row['grade'],
            ':grade_remark' => $row['grade_remark'],
            ':status'     => $row['status'],
        ]);
    }

    foreach (['attendance_summary', 'psychomotor_assessments', 'effective_areas'] as $table) {
        $pdo->prepare('DELETE FROM ' . $table . ' WHERE student_id = :s')->execute([':s' => $studentId]);
    }

    @unlink(__DIR__ . '/report-card-admin.html');
    @unlink(__DIR__ . '/report-card-public.html');
    echo "\nCleaned up.\n";
}

echo "\n----------------------------------------------------------------\n";
echo "Report card check: $passed passed, $failed failed\n";

exit($failed === 0 ? 0 : 1);

/* ====================================================================== */

function get2(string $url, string $jar): array
{
    return follow2(request2('GET', $url, [], $jar), $jar);
}

function request2(string $method, string $url, array $fields, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HEADER         => true,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }

    $raw     = (string) curl_exec($ch);
    $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headers = substr($raw, 0, (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE));
    curl_close($ch);

    preg_match('/^Location:\s*(.+)$/mi', $headers, $m);

    return [
        'status'   => $status,
        'body'     => substr($raw, strlen($headers)),
        'headers'  => $headers,
        'location' => isset($m[1]) ? trim($m[1]) : '',
    ];
}

function follow2(array $res, string $jar): array
{
    $guard = 0;

    while ($res['status'] >= 300 && $res['status'] < 400 && $res['location'] !== '' && $guard < 6) {
        $res = request2('GET', absolute($res['location']), [], $jar);
        $guard++;
    }

    return $res;
}