<?php

declare(strict_types=1);

/**
 * Class details with the student list for a selected academic session.
 *
 * The roster comes from `student_class_enrollments`, so a historical session
 * shows the students who were in this class at that time rather than whatever
 * `students.class_id` says today.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

$classId = queryInt('id');
$class   = $classId === null ? null : findClass($classId);

if ($class === null) {
    setFlash('error', 'The selected class could not be found.');
    redirect('admin/classes.php');
}

$sessions = sessionOptions();
$current  = currentSession();

/* Default to the current session so the page is useful on first load. */
$sessionId = queryInt('session_id')
    ?? ($current === null ? null : (int) $current['id']);

if ($sessionId === null && $sessions !== []) {
    $sessionId = (int) $sessions[0]['id'];
}

$selectedSession = null;

foreach ($sessions as $option) {
    if ((int) $option['id'] === (int) $sessionId) {
        $selectedSession = $option;
        break;
    }
}

if ($selectedSession === null) {
    $sessionId       = null;
    $selectedSession = null;
}

$enrolled = $sessionId === null
    ? []
    : listClassEnrollments($classId, $sessionId);

$enrolledActive = 0;
foreach ($enrolled as $row) {
    if ((string) $row['status'] === 'Active') {
        $enrolledActive++;
    }
}

$capacity        = (int) $class['capacity'];
$available       = max(0, $capacity - $enrolledActive);
$linkedStudents  = (int) dbValue(
    'SELECT COUNT(*) FROM students WHERE class_id = :id AND status = "Active"',
    [':id' => $classId]
);

$pageTitle    = 'Class Details';
$pageHeading  = $class['class_name'];
$pageSubtitle = 'Section ' . $class['section'] . ' - class details and student roster.';
$activeMenu   = 'classes';
$pageActions  = '<a class="btn btn-sm btn-outline-primary" href="'
    . e(assetUrl('admin/class-edit.php?id=' . (int) $classId)) . '">'
    . '<i class="bi bi-pencil"></i> Edit</a>';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions"><?= $pageActions ?></div>
</div>

<?php if ($sessions === []): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span>
            No academic session has been created yet, so no session-based roster can be shown.
            <a href="<?= e(assetUrl('admin/session-add.php')) ?>">Create the first academic session</a>.
        </span>
    </div>
<?php endif; ?>

<div class="info-card">
    <div class="info-card__head"><i class="bi bi-card-list text-green"></i> Class Details</div>
    <div class="info-card__body">
        <?= detailRow('Class Name', e($class['class_name'])) ?>
        <?= detailRow('Section', sectionBadge((string) $class['section'])) ?>
        <?= detailRow('Class Teacher', $class['class_teacher_id'] === null
            ? '<span class="text-muted">Not assigned</span>'
            : e(joinName($class['teacher_first_name'] ?? '', $class['teacher_middle_name'] ?? '', $class['teacher_last_name'] ?? ''))) ?>
        <?= detailRow('Capacity', (string) $capacity) ?>
        <?= detailRow('Enrolled This Session', (string) $enrolledActive) ?>
        <?= detailRow('Available Capacity', (string) $available) ?>
        <?= detailRow('Students Linked To This Class', (string) $linkedStudents) ?>
        <?= detailRow('Status', statusBadge((string) $class['status'])) ?>
    </div>
</div>

<?php if ($selectedSession !== null): ?>
    <form method="get" class="filter-bar" autocomplete="off">
        <input type="hidden" name="id" value="<?= (int) $classId ?>">
        <div class="filter-bar__row">
            <label class="form-label mb-0" for="session_id">Academic Session</label>
            <select class="form-select w-auto" name="session_id" id="session_id" onchange="this.form.submit()">
                <?php foreach ($sessions as $option): ?>
                    <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $sessionId, (string) $option['id']) ?>>
                        <?= e($option['session_name']) ?>
                        <?= (int) $option['is_current'] === 1 ? ' (current)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-green" type="submit">Show</button></noscript>
        </div>
    </form>

    <div class="table-card">
        <div class="table-card__head">
            <h2 class="table-card__title">
                <i class="bi bi-people-fill text-green"></i> Students Enrolled
                <small>(<?= count($enrolled) ?> in <?= e($selectedSession['session_name']) ?>)</small>
            </h2>
            <a class="btn btn-sm btn-outline-secondary"
               href="<?= e(assetUrl('admin/student-enrollments.php?class_id=' . (int) $classId . '&session_id=' . (int) $sessionId)) ?>">
                Manage Enrolments
            </a>
        </div>

        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                <tr>
                    <th>Admission Number</th>
                    <th>Student Name</th>
                    <th>Gender</th>
                    <th>Enrolment Date</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($enrolled === []): ?>
                    <?= emptyRow(6, 'No student is enrolled in this class for the selected academic session.') ?>
                <?php endif; ?>

                <?php foreach ($enrolled as $row): ?>
                    <tr>
                        <td class="mono cell-strong"><?= e($row['admission_number']) ?></td>
                        <td class="cell-strong"><?= e($row['student_name']) ?></td>
                        <td><?= e($row['gender']) ?></td>
                        <td class="mono"><?= e($row['enrollment_date']) ?></td>
                        <td><?= statusBadge((string) $row['status']) ?></td>
                        <td>
                            <div class="table-actions justify-content-end">
                                <a class="btn btn-sm btn-outline-secondary" title="View student"
                                   href="<?= e(assetUrl('admin/student-view.php?id=' . (int) $row['student_id'])) ?>">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
