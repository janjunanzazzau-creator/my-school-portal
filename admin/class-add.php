<?php

declare(strict_types=1);

/**
 * Create a class.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

$errors = [];
$values = [
    'class_name'       => '',
    'section'          => '',
    'class_teacher_id' => '',
    'capacity'         => '30',
    'status'           => 'Active',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_FIELD_NAME] ?? null;

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/class-add.php');
    }

    [$data, $errors] = validateClassInput();
    $values = mergeFormValues($values, $data);

    if ($errors === []) {
        try {
            $classId = createClass($data);
            setFlash('success', 'Class ' . $data['class_name'] . ' was added successfully.');
            redirect('admin/class-view.php?id=' . $classId);
        } catch (Throwable $exception) {
            error_log('[PHASE2] Unable to save class: ' . $exception->getMessage());
            $errors['form'] = 'The class could not be saved. Please try again.';
        }
    }
}

$teachers = teacherOptions();

$pageTitle    = 'Add Class';
$pageHeading  = 'Add Class';
$pageSubtitle = 'Create a class for a section and set its capacity.';
$activeMenu   = 'classes';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('admin/classes.php')) ?>">
            <i class="bi bi-arrow-left"></i> Back to Classes
        </a>
    </div>
</div>

<?php if (isset($errors['form'])): ?>
    <div class="alert alert-danger"><?= e($errors['form']) ?></div>
<?php endif; ?>

<form method="post" data-validate novalidate>
    <?= csrfField() ?>

    <div class="form-card">
        <div class="form-card__head"><i class="bi bi-door-open text-green"></i> Class Details</div>
        <div class="form-card__body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label required" for="class_name">Class Name</label>
                    <input class="<?= inputClass($errors, 'class_name') ?>" type="text"
                           id="class_name" name="class_name" maxlength="50" required
                           placeholder="e.g. Primary 1" value="<?= e($values['class_name']) ?>">
                    <?= inlineError($errors, 'class_name') ?>
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
                    <label class="form-label" for="class_teacher_id">Class Teacher</label>
                    <select class="<?= inputClass($errors, 'class_teacher_id') ?>" id="class_teacher_id"
                            name="class_teacher_id">
                        <option value="">Not assigned</option>
                        <?php foreach ($teachers as $teacher): ?>
                            <option value="<?= (int) $teacher['id'] ?>"<?= isSelected((string) $values['class_teacher_id'], (string) $teacher['id']) ?>>
                                <?= e(teacherFullName($teacher)) ?>
                                (<?= e($teacher['staff_number']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= inlineError($errors, 'class_teacher_id') ?>
                </div>

                <div class="col-md-3">
                    <label class="form-label required" for="capacity">Capacity</label>
                    <input class="<?= inputClass($errors, 'capacity') ?>" type="number"
                           id="capacity" name="capacity" min="1" max="500" required
                           value="<?= e((string) $values['capacity']) ?>">
                    <?= inlineError($errors, 'capacity') ?>
                </div>

                <div class="col-md-3">
                    <label class="form-label required" for="status">Status</label>
                    <select class="<?= inputClass($errors, 'status') ?>" id="status" name="status" required>
                        <?php foreach (RECORD_STATUSES as $option): ?>
                            <option value="<?= e($option) ?>"<?= isSelected($values['status'], $option) ?>><?= e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= inlineError($errors, 'status') ?>
                </div>
            </div>

            <div class="form-text mt-3">
                A class name must be unique inside its section. A capacity of 0 is rejected.
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mb-4">
        <button class="btn btn-green" type="submit"><i class="bi bi-check-circle-fill"></i> Save Class</button>
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/classes.php')) ?>">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
