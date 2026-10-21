<?php

declare(strict_types=1);

/**
 * Edit a term. The academic session can be changed as long as the combination
 * stays valid.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

$termId = queryInt('id');
$term   = $termId === null ? null : findTerm($termId);

if ($term === null) {
    setFlash('error', 'The selected term could not be found.');
    redirect('admin/terms.php');
}

$sessions = sessionOptions();

$errors = [];
$values = [
    'session_id' => (string) $term['session_id'],
    'term_name'  => (string) $term['term_name'],
    'start_date' => (string) $term['start_date'],
    'end_date'   => (string) $term['end_date'],
    'status'     => (string) $term['status'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token  = $_POST[CSRF_FIELD_NAME] ?? null;
    $action = field('action', 30);

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/term-edit.php?id=' . (int) $termId);
    }

    if ($action === 'set_current') {
        try {
            $error = setCurrentTerm($termId);

            if ($error === '') {
                setFlash('success', $term['term_name'] . ' is now the current term.');
            } else {
                setFlash('error', $error);
            }
        } catch (Throwable $exception) {
            error_log('[PHASE3] Unable to set current term: ' . $exception->getMessage());
            setFlash('error', 'The current term could not be changed. Please try again.');
        }

        redirect('admin/term-edit.php?id=' . (int) $termId);
    }

    [$data, $errors] = validateTermInput($termId);
    $values = mergeFormValues($values, $data);

    if ($errors === []) {
        try {
            updateTerm($termId, $data);

            if ($data['status'] !== 'Active' && (int) $term['is_current'] === 1) {
                setTermStatus($termId, $data['status']);
            }

            setFlash('success', 'Term updated successfully.');
            redirect('admin/term-edit.php?id=' . (int) $termId);
        } catch (Throwable $exception) {
            error_log('[PHASE3] Unable to update term: ' . $exception->getMessage());
            $errors['form'] = 'The term could not be saved. Please try again.';
        }
    }
}

$pageTitle    = 'Edit Term';
$pageHeading  = 'Edit Term';
$pageSubtitle = $term['term_name'] . ' - ' . $term['session_name'];
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

<?php if ((int) $term['is_current'] === 1): ?>
    <div class="alert alert-success d-flex align-items-center gap-2">
        <i class="bi bi-star-fill"></i>
        <span>This is the current term of the current academic session.</span>
    </div>
<?php elseif ((int) $term['session_is_current'] === 1): ?>
    <form method="post" class="mb-3">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="set_current">
        <button class="btn btn-gold" data-confirm="Set <?= e($term['term_name']) ?> as the current term?">
            <i class="bi bi-star-fill"></i> Set as Current Term
        </button>
    </form>
<?php else: ?>
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span>
            This term belongs to <?= e($term['session_name']) ?>, which is not the current
            academic session. Set the current session on the
            <a href="<?= e(assetUrl('admin/sessions.php')) ?>">Academic Sessions</a> page first.
        </span>
    </div>
<?php endif; ?>

<form method="post" data-validate novalidate>
    <?= csrfField() ?>
    <input type="hidden" name="action" value="update">

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
        </div>
    </div>

    <div class="d-flex gap-2 mb-4">
        <button class="btn btn-green" type="submit"><i class="bi bi-check-circle-fill"></i> Save Changes</button>
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/terms.php')) ?>">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
