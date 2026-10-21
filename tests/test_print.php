<?php
$adminPassword = 'Admin@12345';

function request(string $method, string $url, array $fields = [], string $jar = 'C:/xampp/htdocs/school-result-portal/tests/phase5-http-cookies.txt'): array
{
    $ch = curl_init($url);
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

    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headers = substr($raw, 0, $headerSize);
    $location = preg_match('/^Location:\s*(.+)$/mi', $headers, $m) === 1 ? trim($m[1]) : '';

    return [
        'status'   => $status,
        'body'     => substr($raw, $headerSize),
        'location' => $location,
    ];
}

function absolute(string $location): string
{
    if (preg_match('#^https?://#i', $location) === 1) {
        return $location;
    }
    return 'http://localhost' . '/' . ltrim($location, '/');
}

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

// First login page
$loginPage = request('GET', 'http://localhost/school-result-portal/login.php');
preg_match('/name="csrf_token" value="([^"]+)"/', $loginPage['body'], $matches);
$token = $matches[1] ?? '';

echo "CSRF Token: $token\n";

// Login
$dashboard = request('POST', 'http://localhost/school-result-portal/login.php', [
    'username'   => 'admin',
    'password'   => $adminPassword,
    'csrf_token' => $token,
]);

echo "Login status: " . $dashboard['status'] . "\n";
echo "Login location: " . $dashboard['location'] . "\n";

// Follow redirect
$dashboard = follow($dashboard);
echo "After follow - status: " . $dashboard['status'] . "\n";
echo "Dashboard URL: " . $dashboard['location'] ?? 'none' . "\n";

// Check cookie jar
echo "\nCookie jar after login:\n";
echo file_get_contents('C:/xampp/htdocs/school-result-portal/tests/phase5-http-cookies.txt');

// Now access the print page
$print = request('GET', 'http://localhost/school-result-portal/admin/print-report-card.php?id=1');
echo "\nPrint status: " . $print['status'] . "\n";
echo "Print location: " . $print['location'] . "\n";

// Check cookie jar before follow
echo "\nCookie jar before print follow:\n";
echo file_get_contents('C:/xampp/htdocs/school-result-portal/tests/phase5-http-cookies.txt');

// Follow redirect if any
$print = follow($print);
echo "After follow - status: " . $print['status'] . "\n";
echo "Final URL: " . $print['location'] ?? 'none' . "\n";

// Check cookie jar after follow
echo "\nCookie jar after print follow:\n";
echo file_get_contents('C:/xampp/htdocs/school-result-portal/tests/phase5-http-cookies.txt');

if ($print['status'] === 200) {
    // Check for specific content
    echo "\nContains 'Subject Results': " . (str_contains($print['body'], 'Subject Results') ? 'YES' : 'NO') . "\n";
    echo "Contains 'COGNITIVE ABILITY': " . (str_contains($print['body'], 'COGNITIVE ABILITY') ? 'YES' : 'NO') . "\n";
    echo "Contains 'Days present': " . (str_contains($print['body'], 'Days present') ? 'YES' : 'NO') . "\n";
    echo "Contains 'Attendance': " . (str_contains($print['body'], 'Attendance') ? 'YES' : 'NO') . "\n";
    echo "Contains 'Previous term': " . (str_contains($print['body'], 'Previous term') ? 'YES' : 'NO') . "\n";
    echo "Contains 'Promotion status': " . (str_contains($print['body'], 'Promotion status') ? 'YES' : 'NO') . "\n";
    echo "Contains 'Principal's Signature': " . (str_contains($print['body'], "Principal's Signature") ? 'YES' : 'NO') . "\n";
    echo "Contains 'Signature': " . (str_contains($print['body'], 'Signature') ? 'YES' : 'NO') . "\n";

    // Save to file for inspection
    file_put_contents('C:/xampp/htdocs/school-result-portal/tests/print_output.html', $print['body']);
    echo "\nOutput saved to print_output.html\n";
} else {
    echo "\nResponse body:\n";
    echo substr($print['body'], 0, 2000);
}