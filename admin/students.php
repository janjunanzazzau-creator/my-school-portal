<?php

declare(strict_types=1);

/**
 * Student management - list, search, filter, paginate, activate/deactivate.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

/* --- Actions (POST only, CSRF protected) -------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = field('action', 20);
    $token     = $_POST[CSRF_FIELD_NAME] ?? null;
    $studentId = queryPostId('student_id');
    $backQuery = currentQueryString() !== '' ? '?' . currentQueryString() : '';

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/students.php');
    }

    $student = $studentId === null ? null : findStudent($studentId);

    if ($student === null) {
        setFlash('error', 'The selected student could not be found.');
    } elseif ($action === 'deactivate') {
        setStudentStatus($studentId, 'Inactive');
        setFlash('success', 'Student deactivated. The record was kept, not deleted.');
    } elseif ($action === 'activate') {
        setStudentStatus($studentId, 'Active');
        setFlash('success', 'Student reactivated successfully.');
    }

    redirect('admin/students.php' . $backQuery);
}

/* --- Filters ------------------------------------------------------------- */
$search    = trim((string) ($_GET['search'] ?? ''));
$section   = (string) ($_GET['section'] ?? '');
$classId   = queryInt('class_id');
$status    = (string) ($_GET['status'] ?? '');
$gender    = (string) ($_GET['gender'] ?? '');
$perPage   = resolvePerPage();
$page      = queryInt('page') ?? 1;

if (!inList($section, SECTIONS)) {
    $section = '';
}
if (!inList($status, STUDENT_STATUSES)) {
    $status = '';
}
if (!inList($gender, GENDERS)) {
    $gender = '';
}

$filters = [
    'search'   => $search,
    'section'  => $section,
    'class_id' => $classId,
    'status'   => $status,
    'gender'   => $gender,
    'per_page' => $perPage,
];

$paging   = paginate(listStudents($filters + ['offset' => 0])['total'], $perPage, $page);
$students = listStudents($filters + ['offset' => $paging['offset']])['rows'];
$classes  = classOptions();

$pageTitle    = 'Students';
$pageHeading  = 'Student Management';
$pageSubtitle = 'Admission records, class placement and enrolment history.';
$activeMenu   = 'students';
$pageActions  = '<a class="btn btn-sm btn-green" href="' . e(assetUrl('admin/student-add.php')) . '">'
    . '<i class="bi bi-plus-circle-fill"></i> Add Student</a>';

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
                   placeholder="Admission no., name or parent">
        </div>

        <select class="form-select w-auto" name="section" aria-label="Filter by section">
            <option value="">All sections</option>
            <?php foreach (SECTIONS as $option): ?>
                <option value="<?= e($option) ?>"<?= isSelected($section, $option) ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>

        <select class="form-select w-auto" name="class_id" aria-label="Filter by class">
            <option value="">All classes</option>
            <?php foreach ($classes as $class): ?>
                <option value="<?= (int) $class['id'] ?>"<?= isSelected((string) $classId, (string) $class['id']) ?>>
                    <?= e($class['class_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select class="form-select w-auto" name="status" aria-label="Filter by status">
            <option value="">All statuses</option>
            <?php foreach (STUDENT_STATUSES as $option): ?>
                <option value="<?= e($option) ?>"<?= isSelected($status, $option) ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>

        <select class="form-select w-auto" name="gender" aria-label="Filter by gender">
            <option value="">All genders</option>
            <?php foreach (GENDERS as $option): ?>
                <option value="<?= e($option) ?>"<?= isSelected($gender, $option) ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>

        <button class="btn btn-green" type="submit"><i class="bi bi-funnel-fill"></i> Filter</button>
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/students.php')) ?>">Reset</a>
        <a class="btn btn-sm btn-outline-primary no-print" href="<?= e(assetUrl('admin/print-students.php' . '?' . currentQueryString())) ?>" target="_blank" rel="noopener">
            <i class="bi bi-printer-fill"></i> Print
        </a>
    </div>
</form>

<div class="table-card">
    <div class="table-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-people-fill text-green"></i> Student Records
            <small>(<?= (int) $paging['total'] ?> total)</small>
        </h2>
        <form method="get" class="d-flex align-items-center gap-2 m-0">
            <?php foreach ([
                'search' => $search, 'section' => $section, 'class_id' => (string) $classId,
                'status' => $status, 'gender' => $gender,
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
                <th>Passport</th>
                <th>Admission Number</th>
                <th>Student Name</th>
                <th>Gender</th>
                <th>Section</th>
                <th>Class</th>
                <th>Parent / Guardian</th>
                <th>Phone</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($students === []): ?>
                <?= emptyRow(10, 'No students found. Adjust the filters or add a student.') ?>
            <?php endif; ?>

            <?php foreach ($students as $student): ?>
                <tr>
                    <td>
                        <img src="<?= e(studentPhotoUrl($student['passport_photo'])) ?>" alt=""
                             style="width:38px;height:44px;object-fit:cover;border-radius:6px;border:1px solid var(--line)">
                    </td>
                    <td class="mono cell-strong"><?= e($student['admission_number']) ?></td>
                    <td class="cell-strong"><?= e(studentFullName($student)) ?></td>
                    <td><?= e($student['gender']) ?></td>
                    <td><?= sectionBadge($student['section']) ?></td>
                    <td><?= e($student['class_name'] ?? 'Not set') ?></td>
                    <td><?= $student['parent_name'] !== null && $student['parent_name'] !== ''
                        ? e($student['parent_name']) : '<span class="text-muted">&mdash;</span>' ?></td>
                    <td class="mono"><?= $student['parent_phone'] !== null && $student['parent_phone'] !== ''
                        ? e($student['parent_phone']) : '&mdash;' ?></td>
                    <td><?= statusBadge($student['status']) ?></td>
                    <td>
                        <div class="table-actions justify-content-end">
                            <a class="btn btn-sm btn-outline-secondary" title="View"
                               href="<?= e(assetUrl('admin/student-view.php?id=' . (int) $student['id'])) ?>">
                                <i class="bi bi-eye"></i>
                            </a>
                            <a class="btn btn-sm btn-outline-primary" title="Edit"
                               href="<?= e(assetUrl('admin/student-edit.php?id=' . (int) $student['id'])) ?>">
                                <i class="bi bi-pencil"></i>
                            </a>

                            <form method="post" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action"
                                       value="<?= $student['status'] === 'Inactive' ? 'activate' : 'deactivate' ?>">
                                <input type="hidden" name="student_id" value="<?= (int) $student['id'] ?>">
                                <button class="btn btn-sm <?= $student['status'] === 'Inactive' ? 'btn-success' : 'btn-outline-danger' ?>"
                                        data-confirm="<?= $student['status'] === 'Inactive'
                                            ? 'Reactivate ' . e(studentFullName($student)) . '?'
                                            : 'Deactivate ' . e(studentFullName($student)) . '? Deactivated students are hidden from class lists and result entry.' ?>"
                                        title="<?= $student['status'] === 'Inactive' ? 'Reactivate' : 'Deactivate' ?>">
                                    <i class="bi bi-<?= $student['status'] === 'Inactive' ? 'arrow-counterclockwise' : 'slash-circle' ?>"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= renderPagination($paging, 'students') ?>
</div>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
