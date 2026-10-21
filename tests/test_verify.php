<?php
$ch = curl_init('http://localhost/school-result-portal/verify-result.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, 'C:/xampp/htdocs/school-result-portal/tests/phase5-http-cookies.txt');
curl_setopt($ch, CURLOPT_COOKIEFILE, 'C:/xampp/htdocs/school-result-portal/tests/phase5-http-cookies.txt');
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$result = curl_exec($ch);
curl_close($ch);
echo substr($result, 0, 3000);