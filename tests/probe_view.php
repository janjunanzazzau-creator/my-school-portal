<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/functions.php';

$BASE = 'http://localhost/school-result-portal';
$jar  = __DIR__ . '/probe-public-cookies.txt';
@unlink($jar);

$pdo = getDbConnection();
$sessionId = (int) dbValue('SELECT id FROM academic_sessions WHERE is_current = 1 LIMIT 1');
$termId    = (int) dbValue('SELECT t.id FROM terms t WHERE t.session_id = :s ORDER BY t.id LIMIT 1', [':s' => $sessionId]);
$classId   = (int) dbValue('SELECT id FROM classes WHERE class_name = "Primary 1" AND section = "Primary" LIMIT 1');
$roster    = classResultRoster($classId, $sessionId);
$studentId = (int) ($roster[0]['student_id'] ?? 0);
$student   = findStudent($studentId);

$card = findReportCardFor($studentId, $classId, $sessionId, $termId);
if ($card === null) {
    echo "No card. Generate one first.\n";
    exit(1);
}
$full = findReportCard((int) $card['id']);

dbRun('UPDATE report_cards SET status = "locked" WHERE id = :id', [':id' => (int) $card['id']]);
dbRun(
    'INSERT INTO result_publication (session_id, term_id, class_id, status)
     VALUES (:s, :t, :c, "Published")
     ON DUPLICATE KEY UPDATE status = "Published"',
    [':s' => $sessionId, ':t' => $termId, ':c' => $classId]
);

function req(string $method, string $url, array $fields, string $jar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 25,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HEADER => true,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headers = substr($raw, 0, (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE));
    curl_close($ch);
    preg_match('/^Location:\s*(.+)$/mi', $headers, $m);
    return ['status' => $status, 'body' => substr($raw, strlen($headers)), 'location' => isset($m[1]) ? trim($m[1]) : ''];
}

$checker = req('GET', $BASE . '/result-checker.php', [], $jar);
preg_match('/name="csrf_token" value="([^"]+)"/', $checker['body'], $m);

$posted = req('POST', $BASE . '/result-checker.php', [
    'admission_number' => (string) $full['admission_number'],
    'date_of_birth'    => (string) $student['date_of_birth'],
    'session_id'       => (string) $sessionId,
    'term_id'          => (string) $termId,
    'csrf_token'       => $m[1] ?? '',
], $jar);

echo "POST status: {$posted['status']}  location: {$posted['location']}\n";

$view = req('GET', 'http://localhost' . $posted['location'], [], $jar);
echo "result-view status: {$view['status']}  location: {$view['location']}\n";
echo "---- body head ----\n";
echo substr($view['body'], 0, 2500) . "\n";