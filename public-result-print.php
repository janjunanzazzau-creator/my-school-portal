<?php

declare(strict_types=1);

/**
 * Printable public result - the same official report card the office prints.
 *
 * Authorisation is unchanged: sendPublicResultSecurityHeaders(), then
 * publicResultReport() for the server-side session and publicResultBundle() for
 * the release gate. Both must pass before anything is drawn. No PIN is involved
 * and no date of birth is ever printed.
 *
 * The document itself is the shared sheet in includes/report-card-body.php,
 * exactly as the admin print view uses it. The only difference in $rc is that the
 * parent's copy never carries the guardian's phone number, so the office contact
 * details are not published along with the result.
 */

require_once __DIR__ . '/config/functions.php';

sendPublicResultSecurityHeaders();

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

/* Built from the same report card id the bundle already authorised, with
   $includeGuardianPhone = false. */
$rc = reportCardViewData((int) $report['id'], false);

if ($rc === []) {
    revokePublicResultAccess();
    setFlash('error', 'That result is no longer available. Please contact the school office.');
    redirect('result-checker.php');
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
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('result-view.php')) ?>">
        <i class="bi bi-arrow-left"></i> Back to result
    </a>
    <span class="text-muted small">
        A4, Portrait, Margins: Minimum or None. The report card is the only thing that prints.
    </span>
</div>

<?php require __DIR__ . '/includes/report-card-body.php'; ?>

</body>
</html>