<?php

declare(strict_types=1);

/**
 * Teacher management - list, search, filter, paginate, activate/deactivate.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

/* --- Actions (POST only, CSRF protected) -------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = field('action', 20);
    $token      = $_POST[CSRF_FIELD_NAME] ?? null;
    $teacherId  = queryPostId('teacher_id');
    $backQuery  = currentQueryString() !== '' ? '?' . currentQueryString() : '';

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/teachers.php');
    }

    $teacher = $teacherId === null ? null : findTeacher($teacherId);

    if ($teacher === null) {
        setFlash('error', 'The selected teacher could not be found.');
    } elseif ($action === 'deactivate') {
        setTeacherStatus($teacherId, 'Inactive');
        setFlash('success', 'Teacher deactivated. The portal login was blocked as well.');
    } elseif ($action === 'activate') {
        setTeacherStatus($teacherId, 'Active');
        setFlash('success', 'Teacher reactivated successfully.');
    }

    redirect('admin/teachers.php' . $backQuery);
}

/* --- Filters ------------------------------------------------------------- */
$search  = trim((string) ($_GET['search'] ?? ''));
$status  = (string) ($_GET['status'] ?? '');
$perPage = resolvePerPage();
$page    = queryInt('page') ?? 1;

if (!inList($status, RECORD_STATUSES)) {
    $status = '';
}

$filters = [
    'search'   => $search,
    'status'   => $status,
    'per_page' => $perPage,
];

$paging   = paginate(listTeachers($filters + ['offset' => 0])['total'], $perPage, $page);
$teachers = listTeachers($filters + ['offset' => $paging['offset']])['rows'];

$pageTitle    = 'Teachers';
$pageHeading  = 'Teacher Management';
$pageSubtitle = 'Staff records and portal login accounts.';
$activeMenu   = 'teachers';
$pageActions  = '<a class="btn btn-sm btn-green" href="' . e(assetUrl('admin/teacher-add.php')) . '">'
    . '<i class="bi bi-plus-circle-fill"></i> Add Teacher</a>';

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
                   placeholder="Staff number, name, phone or e-mail">
        </div>

        <select class="form-select w-auto" name="status" aria-label="Filter by status">
            <option value="">All statuses</option>
            <?php foreach (RECORD_STATUSES as $option): ?>
                <option value="<?= e($option) ?>"<?= isSelected($status, $option) ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>

        <button class="btn btn-green" type="submit"><i class="bi bi-funnel-fill"></i> Filter</button>
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/teachers.php')) ?>">Reset</a>
        <a class="btn btn-sm btn-outline-primary no-print" href="<?= e(assetUrl('admin/print-teachers.php' . '?' . currentQueryString())) ?>" target="_blank" rel="noopener">
            <i class="bi bi-printer-fill"></i> Print
        </a>
    </div>
</form>

<div class="table-card">
    <div class="table-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-person-badge-fill text-green"></i> Teacher Records
            <small>(<?= (int) $paging['total'] ?> total)</small>
        </h2>
        <form method="get" class="d-flex align-items-center gap-2 m-0">
            <?php foreach (['search' => $search, 'status' => $status] as $key => $value): ?>
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
                <th>Staff Number</th>
                <th>Teacher Name</th>
                <th>Gender</th>
                <th>Phone</th>
                <th>E-mail</th>
                <th>Qualification</th>
                <th>Department</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($teachers === []): ?>
                <?= emptyRow(9, 'No teachers found. Adjust the filters or add a teacher.') ?>
            <?php endif; ?>

            <?php foreach ($teachers as $teacher): ?>
                <tr>
                    <td class="mono cell-strong"><?= e($teacher['staff_number']) ?></td>
                    <td class="cell-strong"><?= e(teacherFullName($teacher)) ?></td>
                    <td><?= e($teacher['gender']) ?></td>
                    <td class="mono"><?= e($teacher['phone'] ?: '&mdash;') ?></td>
                    <td><?= $teacher['email'] ? e($teacher['email']) : '<span class="text-muted">&mdash;</span>' ?></td>
                    <td><?= e($teacher['qualification'] ?: '&mdash;') ?></td>
                    <td><?= e($teacher['department'] ?: '&mdash;') ?></td>
                    <td><?= statusBadge((string) $teacher['status']) ?></td>
                    <td>
                        <div class="table-actions justify-content-end">
                            <a class="btn btn-sm btn-outline-secondary" title="View"
                               href="<?= e(assetUrl('admin/teacher-view.php?id=' . (int) $teacher['id'])) ?>">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a class="btn btn-sm btn-outline-primary" title="Edit"
                               href="<?= e(assetUrl('admin/teacher-edit.php?id=' . (int) $teacher['id'])) ?>">
                                <i class="bi bi-pencil"></i>
                            </a>

                            <form method="post" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action"
                                       value="<?= $teacher['status'] === 'Inactive' ? 'activate' : 'deactivate' ?>">
                                <input type="hidden" name="teacher_id" value="<?= (int) $teacher['id'] ?>">
                                <button class="btn btn-sm <?= $teacher['status'] === 'Inactive' ? 'btn-success' : 'btn-outline-danger' ?>"
                                        data-confirm="<?= $teacher['status'] === 'Inactive'
                                            ? 'Reactivate ' . e(teacherFullName($teacher)) . '?'
                                            : 'Deactivate ' . e(teacherFullName($teacher)) . '? The portal login is blocked and they are removed from class assignments.' ?>"
                                        title="<?= $teacher['status'] === 'Inactive' ? 'Reactivate' : 'Deactivate' ?>">
                                    <i class="bi bi-<?= $teacher['status'] === 'Inactive' ? 'arrow-counterclockwise' : 'slash-circle' ?>"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= renderPagination($paging, 'teachers') ?>
</div>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
