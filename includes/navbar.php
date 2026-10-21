<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/helpers.php';

/* Load dynamic school settings */
$settings = getSchoolSettings();

/**
 * Top bar: sidebar toggle, school name and the signed-in account menu.
 */

$navUser    = currentUser();
$navUser    = is_array($navUser) ? $navUser : ['full_name' => '', 'role' => ''];
$navInitial = strtoupper(substr((string) $navUser['full_name'], 0, 1));
$navInitial = $navInitial === '' ? '?' : $navInitial;
?>
<header class="app-navbar">
    <button class="sidebar-toggle" type="button" id="sidebarToggle" aria-label="Toggle navigation">
        <i class="bi bi-list"></i>
    </button>

    <div class="app-navbar__title">
        <strong><?= e($settings['school_short_name'] ?? SCHOOL_SHORT_NAME) ?></strong>
        <span class="d-none d-md-inline">Result Management Portal</span>
    </div>

    <div class="app-navbar__account">
        <div class="account-chip">
            <span class="account-chip__avatar"><?= e($navInitial) ?></span>
            <span class="account-chip__text">
                <strong><?= e($navUser['full_name']) ?></strong>
                <small class="text-capitalize"><?= e($navUser['role']) ?></small>
            </span>
        </div>
        <a class="btn btn-sm btn-outline-light" href="<?= e(assetUrl('logout.php')) ?>">
            <i class="bi bi-box-arrow-right"></i>
            <span class="d-none d-sm-inline">Logout</span>
        </a>
    </div>
</header>
