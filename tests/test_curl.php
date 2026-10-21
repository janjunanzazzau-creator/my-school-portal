<?php
$ch = curl_init('http://localhost/school-result-portal/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, 'C:/xampp/htdocs/school-result-portal/tests/phase5-http-cookies.txt');
$result = curl_exec($ch);
echo curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "\n\n";
echo $result;
if (curl_errno($ch)) {
    echo ' Error: ' . curl_error($ch);
}
curl_close($ch);