<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/helpers.php';

/* Load dynamic school settings */
$settings = getSchoolSettings();
$logoPath = $settings['logo_path'] ?? 'assets/images/logo.jpeg';

/**
 * Teacher sidebar.
 *
 * Functional: My Dashboard (Phase 3), result entry with My Results (Phase 4), and
 * My Report Cards for the class teacher's comment (Phase 5). The subject and
 * class lists show the teacher's own allocations. Every management module
 * belongs to the administrator, so those are listed as disabled here rather than
 * linked.
 */

$activeMenu = $activeMenu ?? 'dashboard';

$teacherLinks = [
    ['key' => 'dashboard',      'url' => 'teacher/dashboard.php',       'icon' => 'bi-speedometer2',         'label' => 'My Dashboard'],
    ['key' => 'myclasses',      'url' => 'teacher/my-classes.php',      'icon' => 'bi-door-open-fill',       'label' => 'My Classes'],
    ['key' => 'mysubjects',     'url' => 'teacher/my-subjects.php',     'icon' => 'bi-journal-bookmark-fill', 'label' => 'My Subjects'],
    ['key' => 'enterresult',    'url' => 'teacher/enter-result.php',    'icon' => 'bi-pencil-square',        'label' => 'Enter Results'],
    ['key' => 'myresults',      'url' => 'teacher/my-results.php',      'icon' => 'bi-clipboard-data-fill',  'label' => 'My Results'],
    ['key' => 'myreportcards',  'url' => 'teacher/my-report-cards.php', 'icon' => 'bi-file-earmark-text-fill', 'label' => 'My Report Cards'],
];
?>
<aside class="app-sidebar" id="appSidebar" aria-label="Teacher navigation">
    <div class="app-sidebar__brand">
        <img src="<?= e(assetUrl($logoPath)) ?>" alt="<?= e($settings['school_short_name'] ?? SCHOOL_SHORT_NAME) ?> logo">
        <span class="app-sidebar__brand-text">
            <?= e($settings['school_name'] ?? SCHOOL_NAME) ?>
            <small><?= e($settings['school_campaign'] ?? SCHOOL_CAMPAIGN) ?></small>
        </span>
    </div>

    <nav class="app-sidebar__nav">
        <div class="nav-section">Main</div>

        <?php foreach ($teacherLinks as $link): ?>
            <a class="side-link<?= $activeMenu === $link['key'] ? ' is-active' : '' ?>"
               href="<?= e(assetUrl($link['url'])) ?>">
                <span class="side-link__icon"><i class="bi <?= e($link['icon']) ?>"></i></span>
                <span><?= e($link['label']) ?></span>
            </a>
        <?php endforeach; ?>

        <div class="nav-section">Account</div>

        <a class="side-link" href="<?= e(assetUrl('logout.php')) ?>">
            <span class="side-link__icon"><i class="bi bi-box-arrow-right"></i></span>
            <span>Logout</span>
        </a>
    </nav>

    <div class="app-sidebar__footer">
        <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> &middot; Phase 5
    </div>
</aside>
<div class="sidebar-backdrop"></div>
