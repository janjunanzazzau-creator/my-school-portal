<?php

declare(strict_types=1);

/**
 * Create a term inside an academic session.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

$sessions = sessionOptions();
$current  = currentSession();

$errors = [];
$values = [
    'session_id' => (string) (queryInt('session_id') ?? ($current === null ? '' : (int) $current['id'])),
    'term_name'  => '',
    'start_date' => '',
    'end_date'   => '',
    'status'     => 'Active',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_FIELD_NAME] ?? null;

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/term-add.php');
    }

    [$data, $errors] = validateTermInput();
    $values = mergeFormValues($values, $data);

    if ($errors === []) {
        try {
            $termId = createTerm($data);
            setFlash('success', $data['term_name'] . ' was added successfully.');
            redirect('admin/terms.php?session_id=' . (int) $data['session_id']);
        } catch (Throwable $exception) {
            error_log('[PHASE3] Unable to save term: ' . $exception->getMessage());
            $errors['form'] = 'The term could not be saved. Please try again.';
        }
    }
}

$pageTitle    = 'Add Term';
$pageHeading  = 'Add Term';
$pageSubtitle = 'Create a term inside an academic session.';
$activeMenu   = 'terms';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('admin/terms.php')) ?>">
            <i class="bi bi-arrow-left"></i> Back to Terms
        </a>
    </div>
</div>

<?php if (isset($errors['form'])): ?>
    <div class="alert alert-danger"><?= e($errors['form']) ?></div>
<?php endif; ?>

<?php if ($sessions === []): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span>
            No academic session exists yet. A term must belong to a session, so
            <a href="<?= e(assetUrl('admin/session-add.php')) ?>">create a session first</a>.
        </span>
    </div>
<?php endif; ?>

<form method="post" data-validate novalidate>
    <?= csrfField() ?>

    <div class="form-card">
        <div class="form-card__head"><i class="bi bi-layers text-green"></i> Term Details</div>
        <div class="form-card__body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label required" for="session_id">Academic Session</label>
                    <select class="<?= inputClass($errors, 'session_id') ?>" id="session_id" name="session_id" required>
                        <option value="">Select academic session</option>
                        <?php foreach ($sessions as $option): ?>
                            <option value="<?= (int) $option['id'] ?>"<?= isSelected($values['session_id'], (string) $option['id']) ?>>
                                <?= e($option['session_name']) ?><?= (int) $option['is_current'] === 1 ? ' (current)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= inlineError($errors, 'session_id') ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label required" for="term_name">Term</label>
                    <select class="<?= inputClass($errors, 'term_name') ?>" id="term_name" name="term_name" required>
                        <option value="">Select term</option>
                        <?php foreach (TERM_NAMES as $option): ?>
                            <option value="<?= e($option) ?>"<?= isSelected($values['term_name'], $option) ?>><?= e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= inlineError($errors, 'term_name') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label required" for="start_date">Start Date</label>
                    <input class="<?= inputClass($errors, 'start_date') ?>" type="date"
                           id="start_date" name="start_date" required
                           value="<?= e($values['start_date']) ?>">
                    <?= inlineError($errors, 'start_date') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label required" for="end_date">End Date</label>
                    <input class="<?= inputClass($errors, 'end_date') ?>" type="date"
                           id="end_date" name="end_date" required
                           value="<?= e($values['end_date']) ?>">
                    <?= inlineError($errors, 'end_date') ?>
                </div>

                <div class="col-md-4">
                    <label class="form-label required" for="status">Status</label>
                    <select class="<?= inputClass($errors, 'status') ?>" id="status" name="status" required>
                        <?php foreach (TERM_STATUSES as $option): ?>
                            <option value="<?= e($option) ?>"<?= isSelected($values['status'], $option) ?>><?= e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= inlineError($errors, 'status') ?>
                </div>
            </div>

            <div class="form-text mt-3">
                A session can hold First Term, Second Term and Third Term once each.
                Use <strong>Set as current</strong> on the Terms page afterwards.
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mb-4">
        <button class="btn btn-green" type="submit"><i class="bi bi-check-circle-fill"></i> Save Term</button>
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/terms.php')) ?>">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
