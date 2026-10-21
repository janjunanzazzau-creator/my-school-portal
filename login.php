<?php

declare(strict_types=1);

/**
 * Administrator and teacher login.
 *
 * A successful login always regenerates the session id. Failures return the
 * identical generic message so the form never reveals whether an account exists.
 */

require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/school-settings.php';

/* Load dynamic school settings */
$settings = getSchoolSettings();
$logoPath = $settings['logo_path'] ?? 'assets/images/logo.jpeg';

if (isLoggedIn()) {
    redirect(hasRole('teacher') ? 'teacher/dashboard.php' : 'dashboard.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token  = $_POST[CSRF_FIELD_NAME] ?? null;
    $user   = postString('username', 50);
    $pass   = is_string($_POST['password'] ?? null) ? (string) $_POST['password'] : '';

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        $errors['form'] = 'Your session expired. Please try again.';
    } elseif (attemptLogin($user, $pass)['success']) {
        redirect(hasRole('teacher') ? 'teacher/dashboard.php' : 'dashboard.php');
    } else {
        $errors['form'] = 'Invalid username or password.';
    }
}

$flashes    = pullFlashes();
$pageTitle  = 'Sign In';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In | <?= e($settings['school_short_name'] ?? SCHOOL_SHORT_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= e(assetUrl('assets/css/style.css')) ?>" rel="stylesheet">
</head>
<body class="auth-body">

<div class="auth-card">
    <div class="auth-card__head">
        <img src="<?= e(assetUrl($logoPath)) ?>" alt="<?= e($settings['school_short_name'] ?? SCHOOL_SHORT_NAME) ?> logo">
        <h1><?= e($settings['school_name'] ?? SCHOOL_NAME) ?></h1>
        <p><?= e($settings['school_campaign'] ?? SCHOOL_CAMPAIGN) ?> &middot; <?= e($settings['school_foundation'] ?? SCHOOL_FOUNDATION) ?></p>
    </div>

    <div class="auth-card__body">
        <?php foreach ($flashes as $flash): ?>
            <div class="alert alert-<?= e($flash['type'] === 'error' ? 'danger' : 'success') ?>" role="alert">
                <?= e($flash['message']) ?>
            </div>
        <?php endforeach; ?>

        <?php if (isset($errors['form'])): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2" role="alert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span><?= e($errors['form']) ?></span>
            </div>
        <?php endif; ?>

        <p class="text-center text-muted small mb-3">
            Sign in to manage students, teachers, classes and results.
        </p>

        <form method="post" action="<?= e(assetUrl('login.php')) ?>" data-validate novalidate>
            <?= csrfField() ?>

            <div class="mb-3">
                <label class="form-label required" for="username">Username</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-person"></i></span>
                    <input class="form-control" type="text" id="username" name="username"
                           maxlength="50" required autocomplete="username" autofocus>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label required" for="password">Password</label>
                <div class="input-group" style="position: relative">
                    <span class="input-group-text"><i class="bi bi-key"></i></span>
                    <input class="form-control" type="password" id="password" name="password"
                           required autocomplete="current-password">
                    <button class="password-toggle" type="button" data-password-toggle
                            aria-label="Show password"><i class="bi bi-eye"></i></button>
                </div>
            </div>

            <button class="btn btn-green w-100" type="submit">
                <i class="bi bi-box-arrow-in-right"></i> Sign In
            </button>
        </form>

        <p class="text-center mt-3 mb-0" style="font-size:.74rem;color:var(--muted)">
            <?= e($settings['school_motto'] ?? SCHOOL_MOTTO) ?>
        </p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(assetUrl('assets/js/app.js')) ?>"></script>
</body>
</html>
