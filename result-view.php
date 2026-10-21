<?php

declare(strict_types=1);

/**
 * The public result page.
 *
 * Everything here is read only, and that is not a promise in the documentation -
 * it is a structural fact. This file contains no form, no POST handler and no
 * UPDATE or INSERT. The only way data reaches it is publicResultBundle(), which
 * resolves the report card from the server side session that result-checker.php
 * opened after verifying the PIN.
 *
 * There is no id, student id, class id, teacher id or PIN in any link on this
 * page. A visitor cannot edit their way to another result by changing the address
 * bar, because there is nothing in the address bar to change: the page is reached
 * from result-checker.php with no parameters at all, and the session is the only
 * thing that says which card is on screen.
 *
 * The same gate runs again on every load, inside publicResultReport(). If the
 * school unpublishes the class, unlocks and re-approves the card, or the 30 minute
 * window runs out while the page is open, the next refresh closes the window
 * instead of showing a stale official document.
 */

require_once __DIR__ . '/config/functions.php';

sendPublicResultSecurityHeaders();

/* Load dynamic school settings */
$settings = getSchoolSettings();
$logoPath = $settings['logo_path'] ?? 'assets/images/logo.jpeg';

$report = publicResultReport();

if ($report === null) {
    setFlash('error', 'Your result session has ended. Please enter your details again.');
    redirect('result-checker.php');
}

$bundle = publicResultBundle((int) $report['id']);

if ($bundle === null) {
    revokePublicResultAccess();
    setFlash('error', 'That result is no longer available. Please contact the school office.');
    redirect('result-checker.php');
}

$structure    = $bundle['structure'];
$scale        = ratingScale();
$subjectRows  = $bundle['subjects'];
$attendance   = $bundle['attendance'];
$psychomotor  = $bundle['psychomotor'];
$effective    = $bundle['effective'];
$previous     = $bundle['previous'];
$promotion    = $bundle['promotion_status'] !== '' ? $bundle['promotion_status'] : 'Pending';
$remaining    = publicResultSecondsRemaining();
$status       = $bundle['status'];

/* The view model for the shared sheet. Built from the report card id the bundle
   already authorised, with the guardian's phone number withheld. */
$rc = reportCardViewData((int) $report['id'], false);

if ($rc === []) {
    revokePublicResultAccess();
    setFlash('error', 'That result is no longer available. Please contact the school office.');
    redirect('result-checker.php');
}

$pageTitle    = 'Result - ' . $bundle['student_name'];

/* Trim a float to what the school actually records: 94 rather than 94.00, and 68.5
   rather than 68.50. Used everywhere a score is printed on this page and on the
   print sheet, so the two never disagree. */
$score = static function ($value): string {
    if ($value === null) {
        return '&ndash;';
    }

    return e(rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.'));
};

$flashes = pullFlashes();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> | <?= e($settings['school_short_name'] ?? SCHOOL_SHORT_NAME) ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= e(assetUrl('assets/css/style.css')) ?>" rel="stylesheet">
    <link href="<?= e(assetUrl('assets/css/report-card.css')) ?>" rel="stylesheet">
</head>
<body class="print-body">

<div class="no-print" style="max-width:800px;margin:0 auto;padding:1rem .5rem 0">
    <?php foreach ($flashes as $flash): ?>
        <div class="alert alert-<?= e($flash['type'] === 'error' ? 'danger' : 'success') ?>" role="alert">
            <?= e($flash['message']) ?>
        </div>
    <?php endforeach; ?>

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div>
            <h1 class="h5 mb-0" style="color:var(--green)">Official Result</h1>
            <small class="text-muted">
                Released by the school
                <?= $status === 'locked' ? 'and locked' : 'and approved' ?>.
                This page closes by itself in
                <strong id="countdown" data-seconds="<?= (int) $remaining ?>">
                    <?= (int) ($remaining / 60) ?> minute<?= $remaining >= 120 || $remaining < 60 ? 's' : '' ?>
                </strong>.
            </small>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-green" href="<?= e(assetUrl('public-result-print.php')) ?>" target="_blank" rel="noopener">
                <i class="bi bi-printer-fill"></i> Print Result
            </a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('result-logout.php')) ?>">
                <i class="bi bi-box-arrow-right"></i> Close Result
            </a>
        </div>
    </div>
</div>

<?php /* The report card itself is the shared sheet, identical to the office
        print and the parent print. This page only adds the countdown and the
        print/close controls above it; it draws no part of the card itself. */ ?>
<?php require __DIR__ . '/includes/report-card-body.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* A visible countdown, because a result that silently disappears from an open tab
   is worse than one that tells you when it is going to. The server re-checks the
   window on every load regardless; this only keeps the visitor informed. */
(function () {
    var element = document.getElementById('countdown');

    if (!element) {
        return;
    }

    var remaining = parseInt(element.getAttribute('data-seconds'), 10);

    if (!remaining || isNaN(remaining)) {
        return;
    }

    setInterval(function () {
        remaining -= 5;

        if (remaining <= 0) {
            window.location.href = <?= json_encode(assetUrl('result-checker.php')) ?>;
            return;
        }

        var minutes = Math.floor(remaining / 60);
        var seconds = remaining % 60;

        element.textContent = minutes + ' minute' + (minutes === 1 ? '' : 's')
            + ' ' + (seconds < 10 ? '0' : '') + seconds + ' second' + (seconds === 1 ? '' : 's');
    }, 5000);
}());
</script>
</body>
</html>
