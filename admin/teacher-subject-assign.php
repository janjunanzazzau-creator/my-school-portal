<?php

declare(strict_types=1);

/**
 * Assign a subject to a teacher for a class and an academic session.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

$teachers = teacherOptions();
$subjects = subjectOptions();
$classes  = classOptions();
$sessions = sessionOptions();
$current  = currentSession();

$errors = [];
$values = [
    'teacher_id' => (string) (queryInt('teacher_id') ?? ''),
    'subject_id' => (string) (queryInt('subject_id') ?? ''),
    'class_id'   => (string) (queryInt('class_id') ?? ''),
    'session_id' => (string) (queryInt('session_id') ?? ($current === null ? '' : (int) $current['id'])),
    'status'     => 'Active',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_FIELD_NAME] ?? null;

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/teacher-subject-assign.php');
    }

    [$data, $errors] = validateTeacherSubjectAssignment();
    $values = mergeFormValues($values, $data);

    if ($errors === []) {
        try {
            createTeacherSubjectAssignment($data);
            setFlash('success', 'The subject assignment was created successfully.');
            redirect('admin/teacher-subject-assignments.php?session_id=' . (int) $data['session_id']);
        } catch (Throwable $exception) {
            error_log('[PHASE3] Unable to save subject assignment: ' . $exception->getMessage());
            $errors['form'] = 'The assignment could not be saved. Please try again.';
        }
    }
}

$pageTitle    = 'Assign Subject';
$pageHeading  = 'Assign Subject To Teacher';
$pageSubtitle = 'Teacher to subject to class to academic session.';
$activeMenu   = 'tclassassign';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('admin/teacher-subject-assignments.php')) ?>">
            <i class="bi bi-arrow-left"></i> Back to Assignments
        </a>
    </div>
</div>

<?php if (isset($errors['form'])): ?>
    <div class="alert alert-danger"><?= e($errors['form']) ?></div>
<?php endif; ?>

<?php if ($teachers === [] || $subjects === [] || $classes === [] || $sessions === []): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span>An assignment needs an active teacher, subject, class and at least one academic session.</span>
    </div>
<?php endif; ?>

<form method="post" data-validate novalidate>
    <?= csrfField() ?>

    <div class="form-card">
        <div class="form-card__head"><i class="bi bi-journal-bookmark text-green"></i> Assignment Details</div>
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
                    <label class="form-label required" for="subject_id">Subject</label>
                    <select class="<?= inputClass($errors, 'subject_id') ?>" id="subject_id" name="subject_id" required>
                        <option value="">Select subject</option>
                        <?php foreach ($subjects as $option): ?>
                            <option value="<?= (int) $option['id'] ?>"<?= isSelected($values['subject_id'], (string) $option['id']) ?>>
                                <span class="mono"><?= e($option['subject_code']) ?></span>
                                <?= e($option['subject_name']) ?> (<?= e($option['section']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?= inlineError($errors, 'subject_id') ?>
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
                Only active records can be assigned. A duplicate active assignment for the same
                teacher, subject, class and session is refused.
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mb-4">
        <button class="btn btn-green" type="submit"><i class="bi bi-check-circle-fill"></i> Save Assignment</button>
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/teacher-subject-assignments.php')) ?>">Cancel</a>
    </div>
</form>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
