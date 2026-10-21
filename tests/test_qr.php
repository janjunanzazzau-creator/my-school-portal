<?php
require 'C:/xampp/htdocs/school-result-portal/config/school-settings.php';
$qr = generateVerificationQRCodeDataUri('TEST123', 80);
echo substr($qr, 0, 100) . '...';
echo "\nLength: " . strlen($qr) . "\n";