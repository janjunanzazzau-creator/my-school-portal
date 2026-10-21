<?php

declare(strict_types=1);

/**
 * Printable report card - the official school report, A4 portrait.
 *
 * Authorisation happens first: requireAnyRole() before any record is read, then
 * a teacher's own scope check. Only after both does the shared sheet in
 * includes/report-card-body.php get to print.
 *
 * The sheet itself, and every figure on it, come from reportCardViewData(),
 * which calls the existing Phase 4/5 readers. The result engine is not
 * re-implemented here.
 */

require_once __DIR__ . '/../config/functions.php';

requireAnyRole();

$reportId = queryInt('id');
$report   = $reportId === null ? null : findReportCard($reportId);

if ($report === null) {
    setFlash('error', 'That report card could not be found.');
    redirect('login.php');
}

/* Teacher permission check */
if (hasRole('teacher')) {
    $profile = currentTeacherProfile();

    if ($profile === null || !teacherCanViewReportCard((int) $profile['id'], $report)) {
        setFlash('error', 'You do not have permission to view that report card.');
        redirect('teacher/my-report-cards.php');
    }
}

/* One view model, shared with the public sheet and the on-screen result so the
   three views can never disagree about a number. The office copy carries the
   guardian's phone number; the parent's copy does not. */
$rc = reportCardViewData((int) $reportId, true);

if ($rc === []) {
    setFlash('error', 'That report card could not be prepared.');
    redirect('login.php');
}

$pageTitle = 'Report Card - ' . $rc['student_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> | <?= e($rc['school']['school_short_name'] ?? '') ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= e(assetUrl('assets/css/report-card.css')) ?>" rel="stylesheet">
</head>
<body class="print-body">

<div class="print-toolbar no-print">
    <button class="btn btn-green" type="button" onclick="window.print()">
        <i class="bi bi-printer-fill"></i> Print / Save as PDF
    </button>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('admin/report-card-view.php?id=' . (int) $reportId)) ?>">
        <i class="bi bi-arrow-left"></i> Back to report card
    </a>
    <span class="text-muted small">
        A4, Portrait, Margins: Minimum or None. The report card is the only thing that prints.
    </span>
</div>

<?php require __DIR__ . '/../includes/report-card-body.php'; ?>

</body>
</html>