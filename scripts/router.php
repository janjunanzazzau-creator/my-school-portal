<?php

declare(strict_types=1);

// PHP's development server does not enforce Apache .htaccess rules.
$root = realpath(__DIR__ . '/..');
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$file = realpath($root . $path);
$relative = $file !== false ? substr($file, strlen($root) + 1) : '';
$publicPage = preg_match('~^(?:[^/]+\.php|(?:admin|teacher)/[^/]+\.php)$~D', $relative);
$publicAsset = preg_match('~^(?:assets|uploads)/.+\.(?:css|js|png|jpe?g|gif|webp|svg|ico|woff2?|ttf)$~iD', $relative);

if ($path === '/') {
    require $root . '/index.php';
    return true;
}

if ($file === false || !str_starts_with($file, $root . '/') || !is_file($file)
    || preg_match('~(?:^|/)\.~', $relative)
    || (!$publicPage && !$publicAsset)) {
    http_response_code(404);
    echo 'Not found';
    return true;
}

return false;
