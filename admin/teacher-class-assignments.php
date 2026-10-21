<?php

declare(strict_types=1);

/**
 * Teacher-to-Class Assignments (class teacher assignments).
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

/* --- Actions (POST only, CSRF protected) -------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = field('action', 30);
    $token    = $_POST[CSRF_FIELD_NAME] ?? null;
    $rowId    = queryPostId('assignment_id');
    $backQuery = currentQueryString() !== '' ? '?' . currentQueryString() : '';

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/teacher-class-assignments.php');
    }

    $assignment = $rowId === null ? null : findTeacherClassAssignment($rowId);

    if ($assignment === null) {
        setFlash('error', 'The selected assignment could not be found.');
    } elseif ($action === 'activate' || $action === 'deactivate') {
        $status = $action === 'activate' ? 'Active' : 'Inactive';

        try {
            setTeacherClassAssignmentStatus($rowId, $status);
            setFlash('success', 'Assignment marked ' . strtolower($status) . '.');
        } catch (Throwable $exception) {
            error_log('[PHASE3] Unable to change assignment status: ' . $exception->getMessage());
            setFlash('error', 'The assignment status could not be changed. Please try again.');
        }
    }

    redirect('admin/teacher-class-assignments.php' . $backQuery);
}

/* --- Filters ------------------------------------------------------------- */
$search    = trim((string) ($_GET['search'] ?? ''));
$teacherId = queryInt('teacher_id');
$classId   = queryInt('class_id');
$sessionId = queryInt('session_id');
$status    = (string) ($_GET['status'] ?? '');
$perPage   = resolvePerPage();
$page      = queryInt('page') ?? 1;

if (!inList($status, ASSIGNMENT_STATUSES)) {
    $status = '';
}

$filters = [
    'search'     => $search,
    'teacher_id' => $teacherId,
    'class_id'   => $classId,
    'session_id' => $sessionId,
    'status'     => $status,
    'per_page'   => $perPage,
];

$paging      = paginate(listTeacherClassAssignments($filters + ['offset' => 0])['total'], $perPage, $page);
$assignments = listTeacherClassAssignments($filters + ['offset' => $paging['offset']])['rows'];

$teachers = teacherOptions();
$classes  = classOptions();
$sessions = sessionOptions();

$pageTitle    = 'Teacher Class Assignments';
$pageHeading  = 'Teacher-to-Class Assignment';
$pageSubtitle = 'Assign a teacher as the class teacher of a class for an academic session.';
$activeMenu   = 'tclassassign';
$pageActions  = '<a class="btn btn-sm btn-green" href="' . e(assetUrl('admin/teacher-class-assign.php')) . '">'
    . '<i class="bi bi-plus-circle-fill"></i> New Assignment</a>';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions"><?= $pageActions ?></div>
</div>

<form method="get" class="filter-bar" autocomplete="off">
    <div class="filter-bar__row">
        <div class="input-group filter-bar__search">
            <span class="input-group-text bg-white"><i class="bi bi-search text-green"></i></span>
            <input type="search" class="form-control" name="search" value="<?= e($search) ?>"
                   placeholder="Teacher, class or session">
        </div>

        <select class="form-select w-auto" name="teacher_id" aria-label="Filter by teacher">
            <option value="">All teachers</option>
            <?php foreach ($teachers as $option): ?>
                <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $teacherId, (string) $option['id']) ?>>
                    <?= e(teacherFullName($option)) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select class="form-select w-auto" name="class_id" aria-label="Filter by class">
            <option value="">All classes</option>
            <?php foreach ($classes as $option): ?>
                <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $classId, (string) $option['id']) ?>>
                    <?= e($option['class_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select class="form-select w-auto" name="session_id" aria-label="Filter by academic session">
            <option value="">All sessions</option>
            <?php foreach ($sessions as $option): ?>
                <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $sessionId, (string) $option['id']) ?>>
                    <?= e($option['session_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select class="form-select w-auto" name="status" aria-label="Filter by status">
            <option value="">All statuses</option>
            <?php foreach (ASSIGNMENT_STATUSES as $option): ?>
                <option value="<?= e($option) ?>"<?= isSelected($status, $option) ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>

        <button class="btn btn-green" type="submit"><i class="bi bi-funnel-fill"></i> Filter</button>
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/teacher-class-assignments.php')) ?>">Reset</a>
        <a class="btn btn-sm btn-outline-primary no-print" href="<?= e(assetUrl('admin/print-teacher-class-assignments.php' . '?' . currentQueryString())) ?>" target="_blank" rel="noopener">
            <i class="bi bi-printer-fill"></i> Print
        </a>
    </div>
</form>

<div class="table-card">
    <div class="table-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-person-check-fill text-green"></i> Class Teacher Assignments
            <small>(<?= (int) $paging['total'] ?> total)</small>
        </h2>
        <form method="get" class="d-flex align-items-center gap-2 m-0">
            <?php foreach ([
                'search' => $search, 'teacher_id' => (string) $teacherId, 'class_id' => (string) $classId,
                'session_id' => (string) $sessionId, 'status' => $status,
            ] as $key => $value): ?>
                <?php if ($value !== ''): ?>
                    <input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <?= renderPerPageSelector($perPage) ?>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
            <tr>
                <th>Teacher</th>
                <th>Class</th>
                <th>Section</th>
                <th>Academic Session</th>
                <th>Assigned Date</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($assignments === []): ?>
                <?= emptyRow(7, 'No class teacher assignment found. Add an assignment to begin.') ?>
            <?php endif; ?>

            <?php foreach ($assignments as $row): ?>
                <tr>
                    <td>
                        <span class="cell-strong"><?= e($row['teacher_name']) ?></span>
                        <br><small class="mono text-muted"><?= e($row['staff_number']) ?></small>
                    </td>
                    <td><?= e($row['class_name']) ?></td>
                    <td><?= sectionBadge((string) $row['section']) ?></td>
                    <td><?= e($row['session_name']) ?></td>
                    <td class="mono"><?= e($row['assigned_date']) ?></td>
                    <td><?= statusBadge((string) $row['status']) ?></td>
                    <td>
                        <div class="table-actions justify-content-end">
                            <form method="post" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action"
                                       value="<?= $row['status'] === 'Inactive' ? 'activate' : 'deactivate' ?>">
                                <input type="hidden" name="assignment_id" value="<?= (int) $row['id'] ?>">
                                <button class="btn btn-sm <?= $row['status'] === 'Inactive' ? 'btn-success' : 'btn-outline-danger' ?>"
                                        data-confirm="<?= $row['status'] === 'Inactive'
                                            ? 'Reactivate this assignment of ' . e($row['teacher_name']) . ' to ' . e($row['class_name']) . '?'
                                            : 'Deactivate this assignment of ' . e($row['teacher_name']) . ' to ' . e($row['class_name']) . '? The teacher will no longer be its class teacher.' ?>"
                                        title="<?= $row['status'] === 'Inactive' ? 'Reactivate' : 'Deactivate' ?>">
                                    <i class="bi bi-<?= $row['status'] === 'Inactive' ? 'arrow-counterclockwise' : 'slash-circle' ?>"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= renderPagination($paging, 'assignments') ?>
</div>

<p class="mb-4">
    <a href="<?= e(assetUrl('admin/teacher-subject-assignments.php')) ?>">
        Go to Teacher-to-Subject assignments
    </a>
</p>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
