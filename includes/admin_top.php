<?php

declare(strict_types=1);

/**
 * Admin page bootstrap: shared variables and the opening page frame.
 *
 * Authorization is NOT done here. Every file in admin/ requires
 * includes/admin_bootstrap.php as its very first statement, which calls
 * requireAdmin() before any record is read. That ordering is what stops an
 * anonymous visitor from learning whether a record id exists.
 */

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/navbar.php';
require_once __DIR__ . '/sidebar.php';

/* Page metadata, set by the including file before requiring this file. */
$pageTitle    = $pageTitle ?? 'Admin';
$pageHeading  = $pageHeading ?? $pageTitle;
$pageSubtitle = $pageSubtitle ?? '';
$activeMenu   = $activeMenu ?? '';
$pageActions  = $pageActions ?? '';
$flashes      = pullFlashes();
?>
<div class="app-shell">
    <main class="app-main">
        <div class="app-content">
