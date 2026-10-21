<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/helpers.php';

/* Load dynamic school settings */
$settings = getSchoolSettings();
$logoPath = $settings['logo_path'] ?? 'assets/images/logo.jpeg';

/**
 * Opening HTML document, <head> and the shared asset links.
 * The page files set $pageTitle before including this file.
 */

$pageTitle = $pageTitle ?? APP_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= e($settings['school_name'] ?? SCHOOL_NAME) ?> <?= e($settings['school_campaign'] ?? SCHOOL_CAMPAIGN) ?> result management portal.">
    <title><?= e($pageTitle) ?> | <?= e($settings['school_short_name'] ?? SCHOOL_SHORT_NAME) ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= e(assetUrl('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body class="app-body">
