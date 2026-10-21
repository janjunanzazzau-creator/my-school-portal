<?php

declare(strict_types=1);

/**
 * Edit a subject.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

$subjectId = queryInt('id');
$subject   = $subjectId === null ? null : findSubject($subjectId);

if ($subject === null) {
    setFlash('error', 'The selected subject could not be found.');
    redirect('admin/subjects.php');
}

$errors = [];
$values = [
    'subject_code' => (string) $subject['subject_code'],
    'subject_name' => (string) $subject['subject_name'],
    'section'      => (string) $subject['section'],
    'status'       => (string) $subject['status'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_FIELD_NAME] ?? null;

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/subject-edit.php?id=' . (int) $subjectId);
    }

    [$data, $errors] = validateSubjectInput($subjectId);
    $values = mergeFormValues($values, $data);

    if ($errors === []) {
        try {
            updateSubject($subjectId, $data);
            setFlash('success', 'Subject record updated successfully.');
            redirect('admin/subjects.php');
        } catch (Throwable $exception) {
            error_log('[PHASE2] Unable to update subject: ' . $exception->getMessage());
            $errors['form'] = 'The subject could not be saved. Please try again.';
        }
    }
}

$pageTitle    = 'Edit Subject';
$pageHeading  = 'Edit Subject';
$pageSubtitle = $subject['subject_name'] . ' (' . $subject['subject_code'] . ')';
$activeMenu   = 'subjects';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('admin/subjects.php')) ?>">
            <i class="bi bi-arrow-left"></i> Back to Subjects
        </a>
    </div>
</div>

<?php if (isset($errors['form'])): ?>
    <div class="alert alert-danger"><?= e($errors['form']) ?></div>
<?php endif; ?>

<form method="post" data-validate novalidate>
    <?= csrfField() ?>

    <div class="form-card">
        <div class="form-card__head"><i class="bi bi-journal-bookmark text-green"></i> Subject Details</div>
        <div class="form-card__body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label required" for="subject_code">Subject Code</label>
                    <input class="<?= inputClass($errors, 'subject_code') ?>" type="text"
                           id="subject_code" name="subject_code" maxlength="20" required
                           value="<?= e($values['subject_code']) ?>">
                    <?= inlineError($errors, 'subject_code') ?>
                </div>

                <div class="col-md-8">
                    <label class="form-label required" for="subject_name">Subject Name</label>
                    <input class="<?= inputClass($errors, 'subject_name') ?>" type="text"
                           id="subject_name" name="subject_name" maxlength="120" required
                           value="<?= e($values['subject_name']) ?>">
                    <?= inlineError($errors, 'subject_name') ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label required" for="section">Section</label>
                    <select class="<?= inputClass($errors, 'section') ?>" id="section" name="section" required
                            data-class-section>
                        <option value="">Select section</option>
                        <?php foreach (SECTIONS as $option): ?>
                            <option value="<?= e($option) ?>"<?= isSelected($values['section'], $option) ?>><?= e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= inlineError($errors, 'section') ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label required" for="status">Status</label>
                    <select class="<?= inputClass($errors, 'status') ?>" id="status" name="status" required>
                        <?php foreach (RECORD_STATUSES as $option): ?>
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
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/subjects.php')) ?>">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
