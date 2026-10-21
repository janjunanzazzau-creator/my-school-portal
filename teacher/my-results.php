<?php

declare(strict_types=1);

/**
 * My Results.
 *
 * A read only view of every result this teacher has entered, whatever its
 * workflow state. The list is always scoped to the signed-in teacher's own id
 * from currentTeacherProfile(), so a teacher_id in the query string has no
 * effect and one teacher can never read another's scores.
 */

require_once __DIR__ . '/../includes/teacher_bootstrap.php';

$profile = currentTeacherProfile();

if ($profile === null) {
    setFlash('error', 'No teacher record is linked to your account. Please contact the administrator.');
    redirect('login.php');
}

$teacherId = (int) $profile['id'];

/* --- Filters --------------------------------------------------------------- */
$search    = trim((string) ($_GET['search'] ?? ''));
$sessionId = queryInt('session_id');
$termId    = queryInt('term_id');
$classId   = queryInt('class_id');
$status    = (string) ($_GET['status'] ?? '');
$perPage   = resolvePerPage();
$page      = queryInt('page') ?? 1;

if (!inList($status, resultStatuses())) {
    $status = '';
}

/* A teacher may only filter by their own classes, so the dropdowns are built
   from the scope rather than from every class on the portal. */
$scope      = teacherResultScope($teacherId);
$scopeClass = [];
$scopeTerm  = [];
$scopeSession = [];

foreach ($scope as $row) {
    $scopeClass[(int) $row['class_id']]   = (string) $row['class_name'] . ' (' . $row['section'] . ')';
    $scopeSession[(int) $row['session_id']] = (string) $row['session_name'];
}

if ($sessionId !== null) {
    foreach (sessionTermOptions($sessionId) as $option) {
        $scopeTerm[(int) $option['id']] = (string) $option['term_name'];
    }
}

/* Teacher screen search: the teacher column is deliberately not searched here,
   because a teacher is only ever looking at their own sheets. */
$listing = listResults([
    'search'     => $search,
    'session_id' => $sessionId,
    'term_id'    => $termId,
    'class_id'   => $classId,
    'status'     => $status,
    'teacher_id' => $teacherId,
    'per_page'   => $perPage,
    'page'       => $page,
]);

$rows   = $listing['rows'];
$paging = $listing['paging'];

$counts = resultStatusCounts([
    'teacher_id' => $teacherId,
    'session_id' => $sessionId,
    'term_id'    => $termId,
    'class_id'   => $classId,
]);

$pageTitle    = 'My Results';
$pageHeading  = 'My Results';
$pageSubtitle = 'Every result you have entered, and where it is in the approval process.';
$activeMenu   = 'myresults';

require __DIR__ . '/../includes/teacher_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn-sm btn-green" href="<?= e(assetUrl('teacher/enter-result.php')) ?>">
            <i class="bi bi-pencil-square"></i> Enter Results
        </a>
    </div>
</div>

<div class="row g-3 mb-3">
    <?php foreach ([
        ['label' => 'Draft', 'value' => $counts['draft'], 'colour' => 'var(--green)', 'icon' => 'bi-pencil'],
        ['label' => 'Submitted', 'value' => $counts['submitted'], 'colour' => 'var(--gold)', 'icon' => 'bi-send'],
        ['label' => 'Approved', 'value' => $counts['approved'], 'colour' => '#1f6f8b', 'icon' => 'bi-check-circle'],
        ['label' => 'Locked', 'value' => $counts['locked'], 'colour' => '#3a3f58', 'icon' => 'bi-lock'],
    ] as $card): ?>
        <div class="col-6 col-lg-3">
            <div class="card stat-card">
                <span class="stat-card__icon" style="background:<?= e($card['colour']) ?>;">
                    <i class="bi <?= e($card['icon']) ?>"></i>
                </span>
                <div>
                    <div class="stat-card__value"><?= (int) $card['value'] ?></div>
                    <div class="stat-card__label"><?= e($card['label']) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<form method="get" class="filter-bar" autocomplete="off">
    <div class="filter-bar__row">
        <div class="input-group filter-bar__search">
            <span class="input-group-text bg-white"><i class="bi bi-search text-green"></i></span>
            <input type="search" class="form-control" name="search" value="<?= e($search) ?>"
                   placeholder="Student name or admission number">
        </div>

        <select class="form-select w-auto" name="session_id" aria-label="Filter by academic session">
            <option value="">All my sessions</option>
            <?php foreach ($scopeSession as $id => $name): ?>
                <option value="<?= $id ?>"<?= isSelected((string) $sessionId, (string) $id) ?>>
                    <?= e($name) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select class="form-select w-auto" name="term_id" aria-label="Filter by term">
            <option value="">All terms</option>
            <?php foreach ($scopeTerm as $id => $name): ?>
                <option value="<?= $id ?>"<?= isSelected((string) $termId, (string) $id) ?>>
                    <?= e($name) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select class="form-select w-auto" name="class_id" aria-label="Filter by class">
            <option value="">All my classes</option>
            <?php foreach ($scopeClass as $id => $name): ?>
                <option value="<?= $id ?>"<?= isSelected((string) $classId, (string) $id) ?>>
                    <?= e($name) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select class="form-select w-auto" name="status" aria-label="Filter by status">
            <option value="">All statuses</option>
            <?php foreach (resultStatuses() as $option): ?>
                <option value="<?= e($option) ?>"<?= isSelected($status, $option) ?>>
                    <?= e(resultStatusLabel($option)) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button class="btn btn-green" type="submit"><i class="bi bi-funnel-fill"></i> Filter</button>
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('teacher/my-results.php')) ?>">Reset</a>
        <a class="btn btn-sm btn-outline-primary no-print" href="<?= e(assetUrl('teacher/print-my-results.php' . '?' . currentQueryString())) ?>" target="_blank" rel="noopener">
            <i class="bi bi-printer-fill"></i> Print
        </a>
    </div>
</form>

<div class="table-card">
    <div class="table-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-clipboard-data-fill text-green"></i> Result Records
            <small>(<?= (int) $paging['total'] ?> total)</small>
        </h2>
        <form method="get" class="d-flex align-items-center gap-2 m-0">
            <?php foreach ([
                'search' => $search, 'session_id' => (string) $sessionId,
                'term_id' => (string) $termId, 'class_id' => (string) $classId, 'status' => $status,
            ] as $key => $value): ?>
                <?php if ($value !== ''): ?>
                    <input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <?= renderPerPageSelector($perPage) ?>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover table-sticky">
            <thead>
            <tr>
                <th>Student</th>
                <th>Admission No.</th>
                <th>Class</th>
                <th>Subject</th>
                <th>Session</th>
                <th>Term</th>
                <th class="text-center">Total</th>
                <th class="text-center">Grade</th>
                <th class="text-center">Status</th>
                <th class="text-end">Action</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <?= emptyRow(10, 'No result found. Enter a score to create your first result.') ?>
            <?php endif; ?>

            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="cell-strong"><?= e($row['first_name'] . ' ' . $row['last_name']) ?></td>
                    <td class="mono"><?= e($row['admission_number']) ?></td>
                    <td><?= e($row['class_name']) ?></td>
                    <td>
                        <span class="mono"><?= e($row['subject_code']) ?></span>
                        <span class="cell-strong"><?= e($row['subject_name']) ?></span>
                    </td>
                    <td><?= e($row['session_name']) ?></td>
                    <td><?= e($row['term_name']) ?></td>
                    <td class="text-center mono">
                        <?= $row['total'] === null
                            ? '<span class="text-muted">&ndash;</span>'
                            : e(rtrim(rtrim(number_format((float) $row['total'], 2, '.', ''), '0'), '.')) ?>
                    </td>
                    <td class="text-center">
                        <?php if ($row['grade'] === null): ?>
                            <span class="text-muted">&ndash;</span>
                        <?php else: ?>
                            <span class="badge <?= (string) $row['grade'] === 'F' ? 'bg-danger' : 'bg-success' ?>">
                                <?= e($row['grade']) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge <?= e(resultStatusBadge((string) $row['status'])) ?>">
                            <?= e(resultStatusLabel((string) $row['status'])) ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-success"
                           href="<?= e(assetUrl('teacher/enter-result.php?class_id=' . (int) $row['class_id'] . '&amp;subject_id=' . (int) $row['subject_id'] . '&amp;session_id=' . (int) $row['session_id'] . '&amp;term_id=' . (int) $row['term_id'])) ?>">
                            <i class="bi bi-pencil-square"></i> Sheet
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= renderPagination($paging, 'results') ?>
</div>

<p class="mt-3 mb-4 text-muted small">
    <i class="bi bi-info-circle me-1"></i>
    A result you have submitted stays read only until an administrator approves,
    returns or locks it. A returned result comes back as a draft with the
    administrator's reason attached.
</p>

<?php require __DIR__ . '/../includes/teacher_bottom.php'; ?>
