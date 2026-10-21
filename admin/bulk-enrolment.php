<?php

declare(strict_types=1);

/**
 * Bulk enrolment: move many students into one class for one academic session.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

$classes  = classOptions();
$sessions = sessionOptions();
$current  = currentSession();

$sessionId = queryInt('session_id') ?? ($current === null ? 0 : (int) $current['id']);
$classId   = queryInt('class_id') ?? 0;
$errors    = [];
$posted    = false;

$candidates = $sessionId > 0 ? studentsNotEnrolledInSession($sessionId) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = true;
    $token  = $_POST[CSRF_FIELD_NAME] ?? null;

    $classId = queryPostId('class_id') ?? 0;
    $sessionId = queryPostId('session_id') ?? 0;
    $date = postString('enrollment_date', 10);
    $studentIds = postIdList('student_ids');

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/bulk-enrolment.php?session_id=' . (int) $sessionId);
    }

    if ($classId <= 0) {
        $errors['class_id'] = 'Please select a class.';
    } elseif (!dbValue('SELECT COUNT(*) FROM classes WHERE id = :id AND status = "Active"', [':id' => $classId])) {
        $errors['class_id'] = 'The selected class does not exist or is not active.';
    }

    if ($sessionId <= 0) {
        $errors['session_id'] = 'Please select an academic session.';
    } elseif (!dbValue('SELECT COUNT(*) FROM academic_sessions WHERE id = :id', [':id' => $sessionId])) {
        $errors['session_id'] = 'The selected academic session does not exist.';
    }

    if ($date === '') {
        $date = date('Y-m-d');
    } elseif (!isValidDate($date)) {
        $errors['enrollment_date'] = 'Enter a valid enrollment date.';
    }

    if ($studentIds === []) {
        $errors['student_ids'] = 'Please select at least one student.';
    }

    if ($errors === []) {
        try {
            $result = bulkEnrollStudents($studentIds, $classId, $sessionId, $date);

            setFlash(
                'success',
                $result['inserted'] . ' student(s) enrolled'
                . ($result['skipped'] > 0 ? ', ' . $result['skipped'] . ' skipped (already enrolled).' : '.')
            );

            redirect('admin/student-enrollments.php?session_id=' . $sessionId);
        } catch (Throwable $exception) {
            error_log('[PHASE3] Bulk enrolment failed: ' . $exception->getMessage());
            $errors['form'] = 'The bulk enrolment was rolled back. Please try again.';
        }
    }

    $candidates = $sessionId > 0 ? studentsNotEnrolledInSession($sessionId) : [];
}

$pageTitle    = 'Bulk Enrolment';
$pageHeading  = 'Bulk Enrolment';
$pageSubtitle = 'Enroll many students into one class at once.';
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

<form method="get" class="filter-bar" autocomplete="off">
    <div class="filter-bar__row">
        <label class="form-label mb-0 fw-semibold" for="session_id">Academic Session</label>
        <select class="form-select w-auto" id="session_id" name="session_id" onchange="this.form.submit()">
            <option value="">Select session</option>
            <?php foreach ($sessions as $option): ?>
                <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $sessionId, (string) $option['id']) ?>>
                    <?= e($option['session_name']) ?><?= (int) $option['is_current'] === 1 ? ' (current)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button class="btn btn-green" type="submit"><i class="bi bi-arrow-repeat"></i> Load Students</button>

        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/bulk-enrolment.php')) ?>">Reset</a>
    </div>
</form>

<?php if ($sessionId <= 0): ?>
    <div class="alert alert-info d-flex align-items-center gap-2">
        <i class="bi bi-info-circle-fill"></i>
        <span>Choose an academic session above to load the students that still need a class.</span>
    </div>
<?php elseif ($candidates === []): ?>
    <div class="alert alert-success d-flex align-items-center gap-2">
        <i class="bi bi-check-circle-fill"></i>
        <span>Every active student already has a class in this academic session.</span>
    </div>
<?php else: ?>
    <form method="post" data-validate novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="session_id" value="<?= (int) $sessionId ?>">

        <div class="form-card">
            <div class="form-card__head">
                <i class="bi bi-people-fill text-green"></i>
                Target Class and Date
            </div>
            <div class="form-card__body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label required" for="bulk_class">Class</label>
                        <select class="<?= inputClass($errors, 'class_id') ?>" id="bulk_class" name="class_id"
                                required data-class-target data-selected="<?= (int) $classId ?>">
                            <option value="">Select class</option>
                            <?php foreach ($classes as $option): ?>
                                <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $classId, (string) $option['id']) ?>>
                                    <?= e($option['class_name']) ?> (<?= e($option['section']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?= inlineError($errors, 'class_id') ?>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label required" for="enrollment_date">Enrollment Date</label>
                        <input class="<?= inputClass($errors, 'enrollment_date') ?>" type="date"
                               id="enrollment_date" name="enrollment_date" required
                               value="<?= e($posted && postString('enrollment_date', 10) !== '' ? postString('enrollment_date', 10) : date('Y-m-d')) ?>">
                        <?= inlineError($errors, 'enrollment_date') ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="table-card">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-person-lines-fill text-green"></i> Students Without A Class
                    <small>(<?= count($candidates) ?> available)</small>
                </h2>
                <div class="d-flex gap-2 m-0">
                    <span class="form-text">Use the header checkbox to select every student.</span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                    <tr>
                        <th style="width:42px;">
                            <input class="form-check-input" type="checkbox" data-check-all
                                   aria-label="Select all students">
                        </th>
                        <th>Student</th>
                        <th>Admission No.</th>
                        <th>Gender</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($candidates as $option): ?>
                        <tr>
                            <td>
                                <input class="form-check-input" type="checkbox" name="student_ids[]"
                                       value="<?= (int) $option['id'] ?>" data-check-item
                                       aria-label="Select <?= e($option['student_name']) ?>">
                            </td>
                            <td><?= e($option['student_name']) ?></td>
                            <td class="mono"><?= e($option['admission_number']) ?></td>
                            <td><?= e($option['gender']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (isset($errors['student_ids'])): ?>
                <div class="px-3 pb-3"><div class="invalid-feedback d-block"><?= e($errors['student_ids']) ?></div></div>
            <?php endif; ?>
        </div>

        <div class="d-flex gap-2 mb-4">
            <button class="btn btn-green" type="submit"
                    data-confirm="Enroll all selected students into this class?">
                <i class="bi bi-check-circle-fill"></i> Enroll Selected Students
            </button>
            <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/student-enrollments.php')) ?>">Cancel</a>
        </div>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
