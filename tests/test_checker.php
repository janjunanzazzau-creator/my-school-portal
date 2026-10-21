<?php
// Quick test of result-checker.php
$ch = curl_init('http://localhost/school-result-portal/result-checker.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, 'C:/xampp/htdocs/school-result-portal/tests/phase6-http-cookies.txt');
curl_setopt($ch, CURLOPT_COOKIEFILE, 'C:/xampp/htdocs/school-result-portal/tests/phase6-http-cookies.txt');
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$result = curl_exec($ch);
curl_close($ch);

// Check for form fields
echo "Contains 'Admission Number': " . (str_contains($result, 'Admission Number') ? 'YES' : 'NO') . "\n";
echo "Contains 'Date of Birth': " . (str_contains($result, 'Date of Birth') ? 'YES' : 'NO') . "\n";
echo "Contains 'Session': " . (str_contains($result, 'Session') ? 'YES' : 'NO') . "\n";
echo "Contains 'Term': " . (str_contains($result, 'Term') ? 'YES' : 'NO') . "\n";
echo "Contains 'noindex': " . (str_contains($result, 'noindex') ? 'YES' : 'NO') . "\n";

// Save for inspection
file_put_contents('C:/xampp/htdocs/school-result-portal/tests/checker_output.html', $result);
echo "\nSaved to checker_output.html\n";