<?php

declare(strict_types=1);

/**
 * Create an academic session.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

$errors = [];
$values = [
    'session_name' => '',
    'start_date'   => '',
    'end_date'     => '',
    'status'       => 'Active',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_FIELD_NAME] ?? null;

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/session-add.php');
    }

    [$data, $errors] = validateSessionInput();
    $values = mergeFormValues($values, $data);

    if ($errors === []) {
        try {
            $sessionId = createSession($data);
            setFlash('success', 'Academic session ' . $data['session_name'] . ' was added successfully.');
            redirect('admin/sessions.php');
        } catch (Throwable $exception) {
            error_log('[PHASE3] Unable to save session: ' . $exception->getMessage());
            $errors['form'] = 'The academic session could not be saved. Please try again.';
        }
    }
}

$pageTitle    = 'Add Session';
$pageHeading  = 'Add Academic Session';
$pageSubtitle = 'Create a new academic session, for example 2027/2028.';
$activeMenu   = 'sessions';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('admin/sessions.php')) ?>">
            <i class="bi bi-arrow-left"></i> Back to Sessions
        </a>
    </div>
</div>

<?php if (isset($errors['form'])): ?>
    <div class="alert alert-danger"><?= e($errors['form']) ?></div>
<?php endif; ?>

<form method="post" data-validate novalidate>
    <?= csrfField() ?>

    <div class="form-card">
        <div class="form-card__head"><i class="bi bi-calendar-range text-green"></i> Session Details</div>
        <div class="form-card__body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label required" for="session_name">Session Name</label>
                    <input class="<?= inputClass($errors, 'session_name') ?>" type="text"
                           id="session_name" name="session_name" maxlength="20" required
                           placeholder="e.g. 2026/2027" value="<?= e($values['session_name']) ?>">
                    <?= inlineError($errors, 'session_name') ?>
                    <div class="form-text">Use the YYYY/YYYY format. The name must be unique.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label required" for="status">Status</label>
                    <select class="<?= inputClass($errors, 'status') ?>" id="status" name="status" required>
                        <?php foreach (SESSION_STATUSES as $option): ?>
                            <option value="<?= e($option) ?>"<?= isSelected($values['status'], $option) ?>><?= e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= inlineError($errors, 'status') ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label required" for="start_date">Start Date</label>
                    <input class="<?= inputClass($errors, 'start_date') ?>" type="date"
                           id="start_date" name="start_date" required
                           value="<?= e($values['start_date']) ?>">
                    <?= inlineError($errors, 'start_date') ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label required" for="end_date">End Date</label>
                    <input class="<?= inputClass($errors, 'end_date') ?>" type="date"
                           id="end_date" name="end_date" required
                           value="<?= e($values['end_date']) ?>">
                    <?= inlineError($errors, 'end_date') ?>
                </div>
            </div>

            <div class="alert-soft-info mt-3">
                <i class="bi bi-info-circle"></i>
                A new session is never made current automatically. Use
                <strong>Set as current</strong> on the Academic Sessions page once the session is created.
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mb-4">
        <button class="btn btn-green" type="submit"><i class="bi bi-check-circle-fill"></i> Save Session</button>
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/sessions.php')) ?>">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
