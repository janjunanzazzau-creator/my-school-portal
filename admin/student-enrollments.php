<?php

declare(strict_types=1);

/**
 * Student class enrollments.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

/* --- Actions (POST only, CSRF protected) -------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = field('action', 30);
    $token     = $_POST[CSRF_FIELD_NAME] ?? null;
    $rowId     = queryPostId('enrollment_id');
    $backQuery = currentQueryString() !== '' ? '?' . currentQueryString() : '';

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/student-enrollments.php');
    }

    $enrollment = $rowId === null ? null : findEnrollment($rowId);

    if ($enrollment === null) {
        setFlash('error', 'The selected enrollment could not be found.');
    } elseif ($action === 'activate' || $action === 'deactivate') {
        $status = $action === 'activate' ? 'Active' : 'Inactive';

        /* A student may hold only one active enrollment per session. Check it
           up front so the administrator gets a clear reason instead of the
           generic message that a rejected unique-index insert would produce. */
        $clash = null;

        if ($status === 'Active') {
            $clash = dbOne(
                'SELECT e.id FROM student_class_enrollments e
                 WHERE e.student_id = :student_id
                   AND e.session_id = :session_id
                   AND e.status = "Active"
                   AND e.id <> :id
                 LIMIT 1',
                [
                    ':student_id' => (int) $enrollment['student_id'],
                    ':session_id' => (int) $enrollment['session_id'],
                    ':id'         => (int) $enrollment['id'],
                ]
            );
        }

        if ($clash !== null) {
            setFlash(
                'error',
                $enrollment['student_name'] . ' already has an active enrollment in '
                . $enrollment['session_name'] . '. Deactivate that record first.'
            );
        } else {
            try {
                setEnrollmentStatus($rowId, $status);
                setFlash('success', 'Enrollment marked ' . strtolower($status) . '.');
            } catch (Throwable $exception) {
                error_log('[PHASE3] Unable to change enrollment status: ' . $exception->getMessage());
                setFlash('error', 'The enrollment status could not be changed. Please try again.');
            }
        }
    }

    redirect('admin/student-enrollments.php' . $backQuery);
}

/* --- Filters ------------------------------------------------------------- */
$search    = trim((string) ($_GET['search'] ?? ''));
$classId   = queryInt('class_id');
$sessionId = queryInt('session_id');
$studentId = queryInt('student_id');
$status    = (string) ($_GET['status'] ?? '');
$perPage   = resolvePerPage();
$page      = queryInt('page') ?? 1;

if (!inList($status, ASSIGNMENT_STATUSES)) {
    $status = '';
}

$current  = currentSession();
$filters  = [
    'search'     => $search,
    'class_id'   => $classId,
    'session_id' => $sessionId,
    'student_id' => $studentId,
    'status'     => $status,
    'per_page'   => $perPage,
];

$paging      = paginate(listEnrollments($filters + ['offset' => 0])['total'], $perPage, $page);
$enrollments = listEnrollments($filters + ['offset' => $paging['offset']])['rows'];

$classes  = classOptions();
$sessions = sessionOptions();

$pageTitle    = 'Student Enrollments';
$pageHeading  = 'Student Enrollments';
$pageSubtitle = 'Place every student in a class for an academic session.';
$activeMenu   = 'enrollments';
$pageActions  = '<a class="btn btn-sm btn-gold" href="' . e(assetUrl('admin/bulk-enrolment.php')) . '">'
    . '<i class="bi bi-people-fill"></i> Bulk Enrol</a> '
    . '<a class="btn btn-sm btn-green" href="' . e(assetUrl('admin/promote-students.php')) . '">'
    . '<i class="bi bi-arrow-up-circle-fill"></i> Promote</a> '
    . '<a class="btn btn-sm btn-green" href="' . e(assetUrl('admin/student-enroll.php')) . '">'
    . '<i class="bi bi-plus-circle-fill"></i> New Enrollment</a>';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions"><?= $pageActions ?></div>
</div>

<?php if ($current === null): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span>
            No current academic session is set. Choose one on the
            <a href="<?= e(assetUrl('admin/sessions.php')) ?>">Academic Sessions</a> page.
        </span>
    </div>
<?php endif; ?>

<form method="get" class="filter-bar" autocomplete="off">
    <div class="filter-bar__row">
        <div class="input-group filter-bar__search">
            <span class="input-group-text bg-white"><i class="bi bi-search text-green"></i></span>
            <input type="search" class="form-control" name="search" value="<?= e($search) ?>"
                   placeholder="Student, admission number, class or session">
        </div>

        <select class="form-select w-auto" name="session_id" aria-label="Filter by academic session">
            <option value="">All sessions</option>
            <?php foreach ($sessions as $option): ?>
                <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $sessionId, (string) $option['id']) ?>>
                    <?= e($option['session_name']) ?><?= (int) $option['is_current'] === 1 ? ' (current)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select class="form-select w-auto" name="class_id" aria-label="Filter by class">
            <option value="">All classes</option>
            <?php foreach ($classes as $option): ?>
                <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $classId, (string) $option['id']) ?>>
                    <?= e($option['class_name']) ?> (<?= e($option['section']) ?>)
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
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/student-enrollments.php')) ?>">Reset</a>
        <a class="btn btn-sm btn-outline-primary no-print" href="<?= e(assetUrl('admin/print-student-enrollments.php' . '?' . currentQueryString())) ?>" target="_blank" rel="noopener">
            <i class="bi bi-printer-fill"></i> Print
        </a>
    </div>
</form>

<div class="table-card">
    <div class="table-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-clipboard-check-fill text-green"></i> Enrollment Records
            <small>(<?= (int) $paging['total'] ?> total)</small>
        </h2>
        <form method="get" class="d-flex align-items-center gap-2 m-0">
            <?php foreach ([
                'search' => $search, 'session_id' => (string) $sessionId,
                'class_id' => (string) $classId, 'status' => $status,
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
                <th>Student</th>
                <th>Admission No.</th>
                <th>Class</th>
                <th>Section</th>
                <th>Academic Session</th>
                <th>Enrolled On</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($enrollments === []): ?>
                <?= emptyRow(8, 'No enrollment found. Enroll a student to begin.') ?>
            <?php endif; ?>

            <?php foreach ($enrollments as $row): ?>
                <tr>
                    <td>
                        <span class="cell-strong"><?= e($row['student_name']) ?></span>
                        <br><small class="text-muted"><?= e($row['gender']) ?></small>
                    </td>
                    <td class="mono"><?= e($row['admission_number']) ?></td>
                    <td><?= e($row['class_name']) ?></td>
                    <td><?= sectionBadge((string) $row['section']) ?></td>
                    <td><?= e($row['session_name']) ?></td>
                    <td class="mono"><?= e($row['enrollment_date']) ?></td>
                    <td><?= statusBadge((string) $row['status']) ?></td>
                    <td>
                        <div class="table-actions justify-content-end">
                            <a class="btn btn-sm btn-outline-secondary"
                               href="<?= e(assetUrl('admin/student-enroll.php?student_id=' . (int) $row['student_id'] . '&session_id=' . (int) $row['session_id'])) ?>"
                               title="Enroll this student in another class"><i class="bi bi-pencil"></i></a>
                            <form method="post" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action"
                                       value="<?= $row['status'] === 'Inactive' ? 'activate' : 'deactivate' ?>">
                                <input type="hidden" name="enrollment_id" value="<?= (int) $row['id'] ?>">
                                <button class="btn btn-sm <?= $row['status'] === 'Inactive' ? 'btn-success' : 'btn-outline-danger' ?>"
                                        data-confirm="<?= $row['status'] === 'Inactive'
                                            ? 'Reactivate this enrollment of ' . e($row['student_name']) . ' in ' . e($row['class_name']) . '?'
                                            : 'Deactivate this enrollment of ' . e($row['student_name']) . ' in ' . e($row['class_name']) . '?' ?>"
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

    <?= renderPagination($paging, 'enrollments') ?>
</div>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
