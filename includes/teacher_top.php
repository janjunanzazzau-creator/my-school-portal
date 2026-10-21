<?php

declare(strict_types=1);

/**
 * Teacher page bootstrap: shared variables and the opening page frame.
 *
 * Authorization is done by includes/teacher_bootstrap.php, which every file in
 * teacher/ requires before it reads a record.
 */

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/navbar.php';
require_once __DIR__ . '/teacher_sidebar.php';

$pageTitle    = $pageTitle ?? 'Teacher';
$pageHeading  = $pageHeading ?? $pageTitle;
$pageSubtitle = $pageSubtitle ?? '';
$pageActions  = $pageActions ?? '';
$flashes      = pullFlashes();
?>
<div class="app-shell">
    <main class="app-main">
        <div class="app-content">
