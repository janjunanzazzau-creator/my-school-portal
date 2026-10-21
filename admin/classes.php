<?php

declare(strict_types=1);

/**
 * Class management - list, search, filter, paginate, activate/deactivate.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

/* --- Actions (POST only, CSRF protected) -------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = field('action', 20);
    $token    = $_POST[CSRF_FIELD_NAME] ?? null;
    $classId  = queryPostId('class_id');
    $backQuery = currentQueryString() !== '' ? '?' . currentQueryString() : '';

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/classes.php');
    }

    $class = $classId === null ? null : findClass($classId);

    if ($class === null) {
        setFlash('error', 'The selected class could not be found.');
    } elseif ($action === 'deactivate') {
        setClassStatus($classId, 'Inactive');
        setFlash('success', 'Class deactivated. The record was kept, not deleted.');
    } elseif ($action === 'activate') {
        setClassStatus($classId, 'Active');
        setFlash('success', 'Class reactivated successfully.');
    }

    redirect('admin/classes.php' . $backQuery);
}

/* --- Filters ------------------------------------------------------------- */
$search  = trim((string) ($_GET['search'] ?? ''));
$section = (string) ($_GET['section'] ?? '');
$status  = (string) ($_GET['status'] ?? '');
$perPage = resolvePerPage();
$page    = queryInt('page') ?? 1;

if (!inList($section, SECTIONS)) {
    $section = '';
}
if (!inList($status, RECORD_STATUSES)) {
    $status = '';
}

$filters = [
    'search'   => $search,
    'section'  => $section,
    'status'   => $status,
    'per_page' => $perPage,
];

$paging  = paginate(listClasses($filters + ['offset' => 0])['total'], $perPage, $page);
$classes = listClasses($filters + ['offset' => $paging['offset']])['rows'];

$pageTitle    = 'Classes';
$pageHeading  = 'Class Management';
$pageSubtitle = 'Classes, capacity and class teachers.';
$activeMenu   = 'classes';
$pageActions  = '<a class="btn btn-sm btn-green" href="' . e(assetUrl('admin/class-add.php')) . '">'
    . '<i class="bi bi-plus-circle-fill"></i> Add Class</a>';

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
                   placeholder="Class name or class teacher staff number">
        </div>

        <select class="form-select w-auto" name="section" aria-label="Filter by section">
            <option value="">All sections</option>
            <?php foreach (SECTIONS as $option): ?>
                <option value="<?= e($option) ?>"<?= isSelected($section, $option) ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>

        <select class="form-select w-auto" name="status" aria-label="Filter by status">
            <option value="">All statuses</option>
            <?php foreach (RECORD_STATUSES as $option): ?>
                <option value="<?= e($option) ?>"<?= isSelected($status, $option) ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>

        <button class="btn btn-green" type="submit"><i class="bi bi-funnel-fill"></i> Filter</button>
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/classes.php')) ?>">Reset</a>
        <a class="btn btn-sm btn-outline-primary no-print" href="<?= e(assetUrl('admin/print-classes.php' . '?' . currentQueryString())) ?>" target="_blank" rel="noopener">
            <i class="bi bi-printer-fill"></i> Print
        </a>
    </div>
</form>

<div class="table-card">
    <div class="table-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-door-open-fill text-green"></i> Class Records
            <small>(<?= (int) $paging['total'] ?> total)</small>
        </h2>
        <form method="get" class="d-flex align-items-center gap-2 m-0">
            <?php foreach (['search' => $search, 'section' => $section, 'status' => $status] as $key => $value): ?>
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
                <th>Class Name</th>
                <th>Section</th>
                <th>Class Teacher</th>
                <th>Capacity</th>
                <th>Student Count</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($classes === []): ?>
                <?= emptyRow(7, 'No classes found. Adjust the filters or add a class.') ?>
            <?php endif; ?>

            <?php foreach ($classes as $class): ?>
                <tr>
                    <td class="cell-strong"><?= e($class['class_name']) ?></td>
                    <td><?= sectionBadge($class['section']) ?></td>
                    <td><?= $class['class_teacher_id'] === null
                        ? '<span class="text-muted">Not assigned</span>'
                        : e(joinName($class['teacher_first_name'] ?? '', $class['teacher_middle_name'] ?? '', $class['teacher_last_name'] ?? '')) ?></td>
                    <td><?= (int) $class['capacity'] ?></td>
                    <td><?= (int) $class['student_count'] ?></td>
                    <td><?= statusBadge((string) $class['status']) ?></td>
                    <td>
                        <div class="table-actions justify-content-end">
                            <a class="btn btn-sm btn-outline-secondary" title="View"
                               href="<?= e(assetUrl('admin/class-view.php?id=' . (int) $class['id'])) ?>">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a class="btn btn-sm btn-outline-primary" title="Edit"
                               href="<?= e(assetUrl('admin/class-edit.php?id=' . (int) $class['id'])) ?>">
                                <i class="bi bi-pencil"></i>
                            </a>

                            <form method="post" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action"
                                       value="<?= $class['status'] === 'Inactive' ? 'activate' : 'deactivate' ?>">
                                <input type="hidden" name="class_id" value="<?= (int) $class['id'] ?>">
                                <button class="btn btn-sm <?= $class['status'] === 'Inactive' ? 'btn-success' : 'btn-outline-danger' ?>"
                                        data-confirm="<?= $class['status'] === 'Inactive'
                                            ? 'Reactivate ' . e($class['class_name'] . ' (' . $class['section'] . ')') . '?'
                                            : 'Deactivate ' . e($class['class_name'] . ' (' . $class['section'] . ')') . '? The class disappears from the class dropdowns but enrolments are kept.' ?>"
                                        title="<?= $class['status'] === 'Inactive' ? 'Reactivate' : 'Deactivate' ?>">
                                    <i class="bi bi-<?= $class['status'] === 'Inactive' ? 'arrow-counterclockwise' : 'slash-circle' ?>"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= renderPagination($paging, 'classes') ?>
</div>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
