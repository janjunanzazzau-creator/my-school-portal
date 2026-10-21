<?php

declare(strict_types=1);

/**
 * Enroll one student into one class for one academic session.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

$students = studentOptions();
$classes  = classOptions();
$sessions = sessionOptions();
$current  = currentSession();

$errors = [];
$values = [
    'student_id'      => (string) (queryInt('student_id') ?? ''),
    'session_id'      => (string) (queryInt('session_id') ?? ($current === null ? '' : (int) $current['id'])),
    'class_id'        => (string) (queryInt('class_id') ?? ''),
    'enrollment_date' => date('Y-m-d'),
    'status'          => 'Active',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_FIELD_NAME] ?? null;

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/student-enroll.php');
    }

    [$data, $errors] = validateEnrollmentInput();
    $values = mergeFormValues($values, $data);

    if ($errors === []) {
        try {
            createEnrollment($data);
            setFlash('success', 'The student was enrolled successfully.');
            redirect('admin/student-enrollments.php?session_id=' . (int) $data['session_id']);
        } catch (Throwable $exception) {
            error_log('[PHASE3] Unable to save enrollment: ' . $exception->getMessage());
            $errors['form'] = 'The enrollment could not be saved. Please try again.';
        }
    }
}

$pageTitle    = 'Enroll Student';
$pageHeading  = 'Enroll Student';
$pageSubtitle = 'Place one student in a class for an academic session.';
$activeMenu   = 'enrollments';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('admin/student-enrollments.php')) ?>">
            <i class="bi bi-arrow-left"></i> Back to Enrollments
        </a>
    </div>
</div>

<?php if (isset($errors['form'])): ?>
    <div class="alert alert-danger"><?= e($errors['form']) ?></div>
<?php endif; ?>

<?php if ($students === []): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span>
            No active student is available to enroll.
            <a href="<?= e(assetUrl('admin/student-add.php')) ?>">Add a student first</a>.
        </span>
    </div>
<?php endif; ?>

<form method="post" data-validate novalidate>
    <?= csrfField() ?>

    <div class="form-card">
        <div class="form-card__head"><i class="bi bi-clipboard-check text-green"></i> Enrollment Details</div>
        <div class="form-card__body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label required" for="student_id">Student</label>
                    <select class="<?= inputClass($errors, 'student_id') ?>" id="student_id" name="student_id" required>
                        <option value="">Select student</option>
                        <?php foreach ($students as $option): ?>
                            <option value="<?= (int) $option['id'] ?>"<?= isSelected($values['student_id'], (string) $option['id']) ?>>
                                <?= e($option['student_name']) ?> (<?= e($option['admission_number']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= inlineError($errors, 'student_id') ?>
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
                    <label class="form-label required" for="section">Section</label>
                    <select class="form-select" id="section" data-class-section>
                        <option value="">Select section</option>
                        <?php foreach (SECTIONS as $option): ?>
                            <option value="<?= e($option) ?>"><?= e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Used only to filter the class list below.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label required" for="class_id">Class</label>
                    <select class="<?= inputClass($errors, 'class_id') ?>" id="class_id" name="class_id" required
                            data-class-target data-selected="<?= e($values['class_id']) ?>">
                        <option value="">Select class</option>
                        <?php foreach ($classes as $option): ?>
                            <option value="<?= (int) $option['id'] ?>"<?= isSelected($values['class_id'], (string) $option['id']) ?>>
                                <?= e($option['class_name']) ?> (<?= e($option['section']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= inlineError($errors, 'class_id') ?>
                </div>

                <div class="col-md-8">
                    <label class="form-label required" for="enrollment_date">Enrollment Date</label>
                    <input class="<?= inputClass($errors, 'enrollment_date') ?>" type="date"
                           id="enrollment_date" name="enrollment_date" required
                           value="<?= e($values['enrollment_date']) ?>">
                    <?= inlineError($errors, 'enrollment_date') ?>
                </div>

                <div class="col-md-4">
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
                A student may hold only one active enrollment per academic session. Promote or
                deactivate the earlier record before enrolling the student again.
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mb-4">
        <button class="btn btn-green" type="submit"><i class="bi bi-check-circle-fill"></i> Save Enrollment</button>
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/student-enrollments.php')) ?>">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
