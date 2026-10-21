<?php

declare(strict_types=1);

/**
 * Create a subject.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

$errors = [];
$values = ['subject_code' => '', 'subject_name' => '', 'section' => '', 'status' => 'Active'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_FIELD_NAME] ?? null;

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/subject-add.php');
    }

    [$data, $errors] = validateSubjectInput();
    $values = mergeFormValues($values, $data);

    if ($errors === []) {
        try {
            $subjectId = createSubject($data);
            setFlash('success', 'Subject ' . $data['subject_name'] . ' was added successfully.');
            redirect('admin/subjects.php');
        } catch (Throwable $exception) {
            error_log('[PHASE2] Unable to save subject: ' . $exception->getMessage());
            $errors['form'] = 'The subject could not be saved. Please try again.';
        }
    }
}

$pageTitle    = 'Add Subject';
$pageHeading  = 'Add Subject';
$pageSubtitle = 'Create a subject offered in a section.';
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
                           placeholder="e.g. ENG" value="<?= e($values['subject_code']) ?>">
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
        <button class="btn btn-green" type="submit"><i class="bi bi-check-circle-fill"></i> Save Subject</button>
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/subjects.php')) ?>">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
