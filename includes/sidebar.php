<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/helpers.php';

/* Load dynamic school settings */
$settings = getSchoolSettings();
$logoPath = $settings['logo_path'] ?? 'assets/images/logo.jpeg';

/**
 * Sidebar navigation.
 *
 * Functional: Dashboard, Students, Teachers, Classes, Subjects (Phase 2),
 * Academic Sessions, Terms, Teacher Assignments, Student Enrollments (Phase 3),
 * Result administration and review (Phase 4), Report Cards with Attendance
 * and Assessments (Phase 5), and Result Publication with Result PINs and the
 * public Result Checker (Phase 6).
 * Settings stays disabled and clearly marked as a later phase - no fake page is
 * created for it.
 */

$currentPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php'));
$activeMenu  = $activeMenu ?? ($currentPage === 'dashboard.php' ? 'dashboard' : '');

$adminLinks = [
    ['key' => 'dashboard',    'url' => 'admin/dashboard.php', 'icon' => 'bi-speedometer2', 'label' => 'Dashboard'],
    ['key' => 'students',     'url' => 'admin/students.php',   'icon' => 'bi-people-fill',           'label' => 'Students'],
    ['key' => 'teachers',     'url' => 'admin/teachers.php',   'icon' => 'bi-person-badge-fill',     'label' => 'Teachers'],
    ['key' => 'classes',      'url' => 'admin/classes.php',    'icon' => 'bi-door-open-fill',        'label' => 'Classes'],
    ['key' => 'subjects',     'url' => 'admin/subjects.php',   'icon' => 'bi-journal-bookmark-fill', 'label' => 'Subjects'],
    ['key' => 'sessions',     'url' => 'admin/sessions.php',   'icon' => 'bi-calendar-range-fill',   'label' => 'Academic Sessions'],
    ['key' => 'terms',        'url' => 'admin/terms.php',      'icon' => 'bi-layers-fill',           'label' => 'Terms'],
    ['key' => 'tclassassign', 'url' => 'admin/teacher-class-assignments.php', 'icon' => 'bi-person-check-fill', 'label' => 'Teacher Assignments'],
    ['key' => 'enrollments',  'url' => 'admin/student-enrollments.php',      'icon' => 'bi-clipboard-check-fill', 'label' => 'Student Enrollments'],
    ['key' => 'results',      'url' => 'admin/results.php',    'icon' => 'bi-clipboard-data-fill',   'label' => 'Results'],
    ['key' => 'reportcards',  'url' => 'admin/report-cards.php', 'icon' => 'bi-file-earmark-text-fill', 'label' => 'Report Cards'],
    ['key' => 'publication',  'url' => 'admin/result-publication.php', 'icon' => 'bi-megaphone-fill',  'label' => 'Result Publication'],
    ['key' => 'resultpins',   'url' => 'admin/result-pins.php', 'icon' => 'bi-key-fill',             'label' => 'Result PINs'],
    ['key' => 'attendance',   'url' => 'admin/attendance.php', 'icon' => 'bi-calendar-check-fill',   'label' => 'Attendance'],
    ['key' => 'assessments',  'url' => 'admin/assessments.php', 'icon' => 'bi-stars',                'label' => 'Assessments'],
    ['key' => 'school-settings', 'url' => 'admin/school-settings.php', 'icon' => 'bi-gear-fill', 'label' => 'School Settings'],
];

/** Modules that belong to later phases. */
$laterPhaseModules = [
];

/**
 * The public result checker is the one page here that has no login behind it, so
 * it is labelled rather than hidden: a member of staff opening it should know
 * immediately that they are leaving the admin portal.
 */
$publicLinks = [
    ['url' => 'result-checker.php', 'icon' => 'bi-patch-check-fill', 'label' => 'Check Result'],
];
?>
<aside class="app-sidebar" id="appSidebar" aria-label="Main navigation">
    <div class="app-sidebar__brand">
        <img src="<?= e(assetUrl($logoPath)) ?>" alt="<?= e($settings['school_short_name'] ?? SCHOOL_SHORT_NAME) ?> logo">
        <span class="app-sidebar__brand-text">
            <?= e($settings['school_name'] ?? SCHOOL_NAME) ?>
            <small><?= e($settings['school_campaign'] ?? SCHOOL_CAMPAIGN) ?></small>
        </span>
    </div>

    <nav class="app-sidebar__nav">
        <div class="nav-section">Main</div>

        <a class="side-link<?= $activeMenu === 'dashboard' ? ' is-active' : '' ?>"
           href="<?= e(assetUrl('dashboard.php')) ?>">
            <span class="side-link__icon"><i class="bi bi-speedometer2"></i></span>
            <span>Dashboard</span>
        </a>

        <div class="nav-section">Records</div>

        <?php foreach ($adminLinks as $link): ?>
            <a class="side-link<?= $activeMenu === $link['key'] ? ' is-active' : '' ?>"
               href="<?= e(assetUrl($link['url'])) ?>">
                <span class="side-link__icon"><i class="bi <?= e($link['icon']) ?>"></i></span>
                <span><?= e($link['label']) ?></span>
            </a>
        <?php endforeach; ?>

        <div class="nav-section">Public</div>

        <?php foreach ($publicLinks as $link): ?>
            <a class="side-link" href="<?= e(assetUrl($link['url'])) ?>" target="_blank" rel="noopener">
                <span class="side-link__icon"><i class="bi <?= e($link['icon']) ?>"></i></span>
                <span><?= e($link['label']) ?></span>
            </a>
        <?php endforeach; ?>

        <div class="nav-section">Coming Later</div>

        <?php foreach ($laterPhaseModules as $module): ?>
            <div class="side-link" aria-disabled="true"
                 title="<?= e($module['label']) ?> is not available yet">
                <span class="side-link__icon"><i class="bi <?= e($module['icon']) ?>"></i></span>
                <span><?= e($module['label']) ?></span>
                <span class="side-link__badge">Soon</span>
            </div>
        <?php endforeach; ?>

        <div class="nav-section">Account</div>

        <a class="side-link" href="<?= e(assetUrl('logout.php')) ?>">
            <span class="side-link__icon"><i class="bi bi-box-arrow-right"></i></span>
            <span>Logout</span>
        </a>
    </nav>

    <div class="app-sidebar__footer">
        <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> &middot; Phase 6
    </div>
</aside>
<div class="sidebar-backdrop"></div>
