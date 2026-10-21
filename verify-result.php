<?php

declare(strict_types=1);

/**
 * Public result verification endpoint.
 *
 * Anyone can verify a result by entering the verification code.
 * This page shows the result details without requiring login.
 */

require_once __DIR__ . '/config/functions.php';

$code = trim((string) ($_GET['code'] ?? ''));

$pageTitle = 'Verify Result';
$report = null;
$error = '';

if ($code !== '') {
    $report = verifyResultByCode($code);
    
    if ($report === null) {
        $error = 'Invalid verification code. The result could not be found.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle) ?> | <?= e(SCHOOL_SHORT_NAME) ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= e(assetUrl('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body class="auth-body">

<div class="auth-card" style="max-width:700px">

    <div class="auth-card__head">
        <img src="<?= e(assetUrl('assets/images/logo.jpeg')) ?>" alt="<?= e(SCHOOL_SHORT_NAME) ?> logo">
        <h1><?= e(SCHOOL_NAME) ?></h1>
        <p><?= e(SCHOOL_CAMPAIGN) ?></p>
    </div>

    <div class="auth-card__body">

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-triangle-fill"></i> <?= e($error) ?>
            </div>
        <?php elseif ($report !== null): ?>
            <div class="alert alert-success" role="alert">
                <i class="bi bi-check-circle-fill"></i> Result verified successfully!
            </div>

            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="mb-0">Verified Result</h5>
                </div>
                <div class="card-body">
                    <div class="row mb-2">
                        <div class="col-sm-3 fw-bold">Student:</div>
                        <div class="col-sm-9"><?= e($report['student_name']) ?></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-sm-3 fw-bold">Admission No.:</div>
                        <div class="col-sm-9 monospace"><?= e($report['admission_number']) ?></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-sm-3 fw-bold">Class:</div>
                        <div class="col-sm-9"><?= e($report['class_name']) ?></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-sm-3 fw-bold">Session:</div>
                        <div class="col-sm-9"><?= e($report['session_name']) ?></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-sm-3 fw-bold">Term:</div>
                        <div class="col-sm-9"><?= e($report['term_name']) ?></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-sm-3 fw-bold">Verification Code:</div>
                        <div class="col-sm-9 monospace"><?= e($report['verification_code']) ?></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-sm-3 fw-bold">Report Number:</div>
                        <div class="col-sm-9 monospace"><?= e($report['report_number']) ?></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-sm-3 fw-bold">Status:</div>
                        <div class="col-sm-9">
                            <span class="badge bg-<?= e($report['status'] === 'locked' ? 'dark' : ($report['status'] === 'approved' ? 'success' : 'warning')) ?>">
                                <?= e(ucfirst($report['status'])) ?>
                            </span>
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-sm-3 fw-bold">Total Score:</div>
                        <div class="col-sm-9"><?= e($report['total_score'] !== null ? number_format((float)$report['total_score'], 2) : 'N/A') ?></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-sm-3 fw-bold">Average:</div>
                        <div class="col-sm-9"><?= e($report['average_score'] !== null ? number_format((float)$report['average_score'], 2) : 'N/A') ?></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-sm-3 fw-bold">Position:</div>
                        <div class="col-sm-9"><?= e($report['position'] !== null ? ordinalSuffix((int)$report['position']) : 'N/A') ?></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-sm-3 fw-bold">Promotion:</div>
                        <div class="col-sm-9"><?= e($report['promotion_status'] !== '' ? $report['promotion_status'] : 'Pending') ?></div>
                    </div>
                </div>
            </div>

            <div class="text-center">
                <a class="btn btn-outline-secondary" href="<?= e(assetUrl('verify-result.php')) ?>">
                    <i class="bi bi-arrow-left"></i> Verify Another
                </a>
            </div>
        <?php else: ?>
            <h2 class="h5 text-center mb-3" style="color:var(--green)">Verify Result</h2>
            <p class="text-center text-muted small mb-3">
                Enter the verification code from the report card to verify its authenticity.
            </p>

            <form method="get" action="<?= e(assetUrl('verify-result.php')) ?>">
                <div class="mb-3">
                    <label class="form-label required" for="code">Verification Code</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-shield-check"></i></span>
                        <input class="form-control" type="text" id="code" name="code" maxlength="40" required
                               autocomplete="off" autocapitalize="characters"
                               placeholder="e.g. OMNPS-VER-2026-001">
                    </div>
                </div>

                <button class="btn btn-green w-100" type="submit">
                    <i class="bi bi-search"></i> Verify
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(assetUrl('assets/js/app.js')) ?>"></script>
</body>
</html>