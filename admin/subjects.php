<?php

declare(strict_types=1);

/**
 * Subject management - list, search, filter, paginate, activate/deactivate.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

/* --- Actions (POST only, CSRF protected) -------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = field('action', 20);
    $token     = $_POST[CSRF_FIELD_NAME] ?? null;
    $subjectId = queryPostId('subject_id');
    $backQuery = currentQueryString() !== '' ? '?' . currentQueryString() : '';

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/subjects.php');
    }

    $subject = $subjectId === null ? null : findSubject($subjectId);

    if ($subject === null) {
        setFlash('error', 'The selected subject could not be found.');
    } elseif ($action === 'deactivate') {
        setSubjectStatus($subjectId, 'Inactive');
        setFlash('success', 'Subject deactivated. The record was kept, not deleted.');
    } elseif ($action === 'activate') {
        setSubjectStatus($subjectId, 'Active');
        setFlash('success', 'Subject reactivated successfully.');
    }

    redirect('admin/subjects.php' . $backQuery);
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

$paging   = paginate(listSubjects($filters + ['offset' => 0])['total'], $perPage, $page);
$subjects = listSubjects($filters + ['offset' => $paging['offset']])['rows'];

$pageTitle    = 'Subjects';
$pageHeading  = 'Subject Management';
$pageSubtitle = 'Subjects offered in each section.';
$activeMenu   = 'subjects';
$pageActions  = '<a class="btn btn-sm btn-green" href="' . e(assetUrl('admin/subject-add.php')) . '">'
    . '<i class="bi bi-plus-circle-fill"></i> Add Subject</a>';

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
                   placeholder="Subject code or name">
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
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/subjects.php')) ?>">Reset</a>
        <a class="btn btn-sm btn-outline-primary no-print" href="<?= e(assetUrl('admin/print-subjects.php' . '?' . currentQueryString())) ?>" target="_blank" rel="noopener">
            <i class="bi bi-printer-fill"></i> Print
        </a>
    </div>
</form>

<div class="table-card">
    <div class="table-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-journal-bookmark-fill text-green"></i> Subject Records
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
                <th>Subject Code</th>
                <th>Subject Name</th>
                <th>Section</th>
                <th>Assignments</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($subjects === []): ?>
                <?= emptyRow(6, 'No subjects found. Add a subject to begin.') ?>
            <?php endif; ?>

            <?php foreach ($subjects as $subject): ?>
                <tr>
                    <td class="mono cell-strong"><?= e($subject['subject_code']) ?></td>
                    <td class="cell-strong"><?= e($subject['subject_name']) ?></td>
                    <td><?= sectionBadge($subject['section']) ?></td>
                    <td><?= (int) $subject['class_count'] ?></td>
                    <td><?= statusBadge($subject['status']) ?></td>
                    <td>
                        <div class="table-actions justify-content-end">
                            <a class="btn btn-sm btn-outline-primary" title="Edit"
                               href="<?= e(assetUrl('admin/subject-edit.php?id=' . (int) $subject['id'])) ?>">
                                <i class="bi bi-pencil"></i>
                            </a>

                            <form method="post" class="d-inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="action"
                                       value="<?= $subject['status'] === 'Inactive' ? 'activate' : 'deactivate' ?>">
                                <input type="hidden" name="subject_id" value="<?= (int) $subject['id'] ?>">
                                <button class="btn btn-sm <?= $subject['status'] === 'Inactive' ? 'btn-success' : 'btn-outline-danger' ?>"
                                        data-confirm="<?= $subject['status'] === 'Inactive'
                                            ? 'Reactivate ' . e($subject['subject_name']) . '?'
                                            : 'Deactivate ' . e($subject['subject_name']) . '? It is removed from the subject dropdowns but existing records are kept.' ?>"
                                        title="<?= $subject['status'] === 'Inactive' ? 'Reactivate' : 'Deactivate' ?>">
                                    <i class="bi bi-<?= $subject['status'] === 'Inactive' ? 'arrow-counterclockwise' : 'slash-circle' ?>"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= renderPagination($paging, 'subjects') ?>
</div>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
