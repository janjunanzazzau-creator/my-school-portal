<?php

declare(strict_types=1);

/**
 * Assign a teacher as the class teacher of a class for an academic session.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

$teachers = teacherOptions();
$classes  = classOptions();
$sessions = sessionOptions();
$current  = currentSession();

$errors = [];
$values = [
    'teacher_id'    => (string) (queryInt('teacher_id') ?? ''),
    'class_id'      => (string) (queryInt('class_id') ?? ''),
    'session_id'    => (string) (queryInt('session_id') ?? ($current === null ? '' : (int) $current['id'])),
    'assigned_date' => date('Y-m-d'),
    'status'        => 'Active',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_FIELD_NAME] ?? null;

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/teacher-class-assign.php');
    }

    [$data, $errors] = validateTeacherClassAssignment();
    $values = mergeFormValues($values, $data);

    if ($errors === []) {
        try {
            createTeacherClassAssignment($data);
            setFlash('success', 'The class teacher assignment was created successfully.');
            redirect('admin/teacher-class-assignments.php?session_id=' . (int) $data['session_id']);
        } catch (Throwable $exception) {
            error_log('[PHASE3] Unable to save class assignment: ' . $exception->getMessage());
            $errors['form'] = 'The assignment could not be saved. Please try again.';
        }
    }
}

$pageTitle    = 'Assign Class Teacher';
$pageHeading  = 'Assign Class Teacher';
$pageSubtitle = 'Make a teacher the class teacher of one class for one academic session.';
$activeMenu   = 'tclassassign';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('admin/teacher-class-assignments.php')) ?>">
            <i class="bi bi-arrow-left"></i> Back to Assignments
        </a>
    </div>
</div>

<?php if (isset($errors['form'])): ?>
    <div class="alert alert-danger"><?= e($errors['form']) ?></div>
<?php endif; ?>

<?php if ($teachers === [] || $classes === [] || $sessions === []): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span>An assignment needs at least one active teacher, one active class and one academic session.</span>
    </div>
<?php endif; ?>

<form method="post" data-validate novalidate>
    <?= csrfField() ?>

    <div class="form-card">
        <div class="form-card__head"><i class="bi bi-person-check text-green"></i> Assignment Details</div>
        <div class="form-card__body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label required" for="teacher_id">Teacher</label>
                    <select class="<?= inputClass($errors, 'teacher_id') ?>" id="teacher_id" name="teacher_id" required>
                        <option value="">Select teacher</option>
                        <?php foreach ($teachers as $option): ?>
                            <option value="<?= (int) $option['id'] ?>"<?= isSelected($values['teacher_id'], (string) $option['id']) ?>>
                                <?= e(teacherFullName($option)) ?>
                                (<?= e($option['staff_number']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= inlineError($errors, 'teacher_id') ?>
                </div>

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
                    <label class="form-label required" for="class_id">Class</label>
                    <select class="<?= inputClass($errors, 'class_id') ?>" id="class_id" name="class_id" required
                            data-class-section>
                        <option value="">Select class</option>
                        <?php foreach ($classes as $option): ?>
                            <option value="<?= (int) $option['id'] ?>"<?= isSelected($values['class_id'], (string) $option['id']) ?>>
                                <?= e($option['class_name']) ?> (<?= e($option['section']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= inlineError($errors, 'class_id') ?>
                    <div class="form-text">Choose the section first, then the class list reloads automatically.</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label required" for="assigned_date">Assigned Date</label>
                    <input class="<?= inputClass($errors, 'assigned_date') ?>" type="date"
                           id="assigned_date" name="assigned_date" required
                           value="<?= e($values['assigned_date']) ?>">
                    <?= inlineError($errors, 'assigned_date') ?>
                </div>

                <div class="col-md-3">
                    <label class="form-label required" for="status">Status</label>
                    <select class="<?= inputClass($errors, 'status') ?>" id="status" name="status" required>
                        <?php foreach (ASSIGNMENT_STATUSES as $option): ?>
                            <option value="<?= e($option) ?>"<?= isSelected($values['status'], $option) ?>><?= e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= inlineError($errors, 'status') ?>
                </div>
            </div>

            <div class="form-text mt-3">
                Only an active teacher, class and session can be assigned. A duplicate active
                assignment for the same teacher, class and session is refused.
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mb-4">
        <button class="btn btn-green" type="submit"><i class="bi bi-check-circle-fill"></i> Save Assignment</button>
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/teacher-class-assignments.php')) ?>">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
