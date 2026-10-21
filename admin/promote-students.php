<?php

declare(strict_types=1);

/**
 * Promote students from a source class and session into a new class and session.
 *
 * The old enrollment rows are kept, so the history of every student stays intact.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

$classes  = classOptions();
$sessions = sessionOptions();
$current  = currentSession();

$sourceSessionId = queryInt('from_session_id') ?? ($current === null ? 0 : (int) $current['id']);
$sourceClassId   = queryInt('from_class_id') ?? 0;

$targetSessionId = queryInt('to_session_id') ?? 0;
$targetClassId   = queryInt('to_class_id') ?? 0;

$errors   = [];
$selected = [];

$sourceEnrollments = [];

if ($sourceClassId > 0 && $sourceSessionId > 0) {
    $sourceEnrollments = listClassEnrollments($sourceClassId, $sourceSessionId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = field('action', 30);
    $token  = $_POST[CSRF_FIELD_NAME] ?? null;

    $sourceSessionId = queryPostId('from_session_id') ?? 0;
    $sourceClassId   = queryPostId('from_class_id') ?? 0;
    $targetSessionId = queryPostId('to_session_id') ?? 0;
    $targetClassId   = queryPostId('to_class_id') ?? 0;
    $date            = postString('enrollment_date', 10);
    $enrollmentIds   = postIdList('enrollment_ids');

    $backUrl = 'admin/promote-students.php?from_session_id=' . $sourceSessionId
        . '&from_class_id=' . $sourceClassId
        . '&to_session_id=' . $targetSessionId
        . '&to_class_id=' . $targetClassId;

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/promote-students.php');
    }

    if ($action !== 'promote') {
        setFlash('error', 'Unknown action.');
        redirect('admin/promote-students.php');
    }

    if ($sourceClassId <= 0) {
        $errors['from_class_id'] = 'Please select the source class.';
    }

    if ($targetClassId <= 0) {
        $errors['to_class_id'] = 'Please select the destination class.';
    } elseif ($targetClassId === $sourceClassId) {
        $errors['to_class_id'] = 'The destination class must be different from the source class.';
    }

    if ($targetSessionId <= 0) {
        $errors['to_session_id'] = 'Please select the destination academic session.';
    } elseif ($targetSessionId === $sourceSessionId && $targetClassId === $sourceClassId) {
        $errors['to_session_id'] = 'The destination academic session must be different from the source session.';
    }

    if ($targetClassId > 0 && !dbValue('SELECT COUNT(*) FROM classes WHERE id = :id AND status = "Active"', [':id' => $targetClassId])) {
        $errors['to_class_id'] = 'The destination class does not exist or is not active.';
    }

    if ($targetSessionId > 0 && !dbValue('SELECT COUNT(*) FROM academic_sessions WHERE id = :id', [':id' => $targetSessionId])) {
        $errors['to_session_id'] = 'The destination academic session does not exist.';
    }

    if ($date === '') {
        $date = date('Y-m-d');
    } elseif (!isValidDate($date)) {
        $errors['enrollment_date'] = 'Enter a valid promotion date.';
    }

    if ($enrollmentIds === []) {
        $errors['enrollment_ids'] = 'Please select at least one student to promote.';
    }

    $selected = $enrollmentIds;

    if ($errors === []) {
        try {
            $result = promoteStudents($enrollmentIds, $targetClassId, $targetSessionId, $date);

            setFlash(
                'success',
                $result['inserted'] . ' student(s) promoted'
                . ($result['skipped'] > 0 ? ', ' . $result['skipped'] . ' skipped (already enrolled in the new session).' : '.')
            );

            redirect('admin/student-enrollments.php?session_id=' . $targetSessionId);
        } catch (Throwable $exception) {
            error_log('[PHASE3] Promotion failed: ' . $exception->getMessage());
            $errors['form'] = 'The promotion was rolled back. Please try again.';
        }
    }

    $sourceEnrollments = ($sourceClassId > 0 && $sourceSessionId > 0)
        ? listClassEnrollments($sourceClassId, $sourceSessionId)
        : [];
}

$pageTitle    = 'Promote Students';
$pageHeading  = 'Promote Students';
$pageSubtitle = 'Move students into a new class for a new academic session.';
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
        <label class="form-label mb-0 fw-semibold" for="from_session_id">From Session</label>
        <select class="form-select w-auto" id="from_session_id" name="from_session_id">
            <option value="">Select session</option>
            <?php foreach ($sessions as $option): ?>
                <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $sourceSessionId, (string) $option['id']) ?>>
                    <?= e($option['session_name']) ?><?= (int) $option['is_current'] === 1 ? ' (current)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label class="form-label mb-0 fw-semibold" for="from_class_id">From Class</label>
        <select class="form-select w-auto" id="from_class_id" name="from_class_id">
            <option value="">Select class</option>
            <?php foreach ($classes as $option): ?>
                <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $sourceClassId, (string) $option['id']) ?>>
                    <?= e($option['class_name']) ?> (<?= e($option['section']) ?>)
                </option>
            <?php endforeach; ?>
        </select>

        <button class="btn btn-green" type="submit"><i class="bi bi-arrow-repeat"></i> Load Students</button>
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/promote-students.php')) ?>">Reset</a>
    </div>
</form>

<?php if ($sourceClassId <= 0 || $sourceSessionId <= 0): ?>
    <div class="alert alert-info d-flex align-items-center gap-2">
        <i class="bi bi-info-circle-fill"></i>
        <span>Choose the source session and class above to load the students to promote.</span>
    </div>
<?php elseif ($sourceEnrollments === []): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span>No student is enrolled in this class for this academic session.</span>
    </div>
<?php else: ?>
    <form method="post" data-validate novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="promote">
        <input type="hidden" name="from_session_id" value="<?= (int) $sourceSessionId ?>">
        <input type="hidden" name="from_class_id" value="<?= (int) $sourceClassId ?>">

        <div class="form-card">
            <div class="form-card__head"><i class="bi bi-arrow-up-circle text-green"></i> Promote To</div>
            <div class="form-card__body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label required" for="to_session_id">Destination Academic Session</label>
                        <select class="<?= inputClass($errors, 'to_session_id') ?>" id="to_session_id" name="to_session_id" required>
                            <option value="">Select session</option>
                            <?php foreach ($sessions as $option): ?>
                                <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $targetSessionId, (string) $option['id']) ?>>
                                    <?= e($option['session_name']) ?><?= (int) $option['is_current'] === 1 ? ' (current)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?= inlineError($errors, 'to_session_id') ?>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label required" for="to_class_id">Destination Class</label>
                        <select class="<?= inputClass($errors, 'to_class_id') ?>" id="to_class_id" name="to_class_id" required
                                data-class-target data-selected="<?= (int) $targetClassId ?>">
                            <option value="">Select class</option>
                            <?php foreach ($classes as $option): ?>
                                <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $targetClassId, (string) $option['id']) ?>>
                                    <?= e($option['class_name']) ?> (<?= e($option['section']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?= inlineError($errors, 'to_class_id') ?>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label required" for="enrollment_date">Promotion Date</label>
                        <input class="<?= inputClass($errors, 'enrollment_date') ?>" type="date"
                               id="enrollment_date" name="enrollment_date" required value="<?= e(date('Y-m-d')) ?>">
                        <?= inlineError($errors, 'enrollment_date') ?>
                    </div>
                </div>

                <div class="form-text mt-3">
                    A new enrollment row is created for each selected student. The previous rows stay in
                    place, so the enrollment history is never overwritten.
                </div>
            </div>
        </div>

        <div class="table-card">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-person-lines-fill text-green"></i> Students In Source Class
                    <small>(<?= count($sourceEnrollments) ?> total)</small>
                </h2>
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
                        <th>Enrolled On</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sourceEnrollments as $row): ?>
                        <tr>
                            <td>
                                <input class="form-check-input" type="checkbox" name="enrollment_ids[]"
                                       value="<?= (int) $row['enrollment_id'] ?>" data-check-item
                                       <?= in_array((int) $row['enrollment_id'], $selected, true) ? 'checked' : '' ?>
                                       aria-label="Select <?= e($row['student_name']) ?>">
                            </td>
                            <td><?= e($row['student_name']) ?></td>
                            <td class="mono"><?= e($row['admission_number']) ?></td>
                            <td><?= e($row['gender']) ?></td>
                            <td class="mono"><?= e($row['enrollment_date']) ?></td>
                            <td><?= statusBadge((string) $row['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (isset($errors['enrollment_ids'])): ?>
                <div class="px-3 pb-3"><div class="invalid-feedback d-block"><?= e($errors['enrollment_ids']) ?></div></div>
            <?php endif; ?>
        </div>

        <div class="d-flex gap-2 mb-4">
            <button class="btn btn-green" type="submit"
                    data-confirm="Promote all selected students into the destination class?">
                <i class="bi bi-check-circle-fill"></i> Promote Selected Students
            </button>
            <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/student-enrollments.php')) ?>">Cancel</a>
        </div>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
