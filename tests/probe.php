<?php
$src = file_get_contents('C:/xampp/htdocs/school-result-portal/result-view.php');
$pos = strpos($src, '<article class="report-sheet">');
echo 'head bytes: ' . var_export($pos, true) . PHP_EOL;
$footPos = strpos($src, '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>');
echo 'footer bytes: ' . var_export($footPos, true) . PHP_EOL;
echo 'total: ' . strlen($src) . PHP_EOL;