<?php

declare(strict_types=1);

/**
 * Result administration.
 *
 * The administrator's single screen for every result on the portal, with the
 * full set of filters the school needs: academic session, term, class, section,
 * subject, teacher and workflow status, plus a free text search over admission
 * number, student name, subject, class and teacher.
 *
 * The page is read only. Every change goes through admin/result-action.php so
 * that the state machine and the audit trail live in exactly one place.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

/* --- Filters --------------------------------------------------------------- */
$search    = trim((string) ($_GET['search'] ?? ''));
$sessionId = queryInt('session_id');
$termId    = queryInt('term_id');
$classId   = queryInt('class_id');
$section   = (string) ($_GET['section'] ?? '');
$subjectId = queryInt('subject_id');
$teacherId = queryInt('teacher_id');
$status    = (string) ($_GET['status'] ?? '');
$perPage   = resolvePerPage();
$page      = queryInt('page') ?? 1;

if (!in_array($section, ['Nursery', 'Primary', 'Secondary'], true)) {
    $section = '';
}

if (!inList($status, resultStatuses())) {
    $status = '';
}

/* Options ------------------------------------------------------------------ */
$sessions = sessionOptions();
$terms    = $sessionId === null ? [] : sessionTermOptions($sessionId);
$classes  = classOptions($section);
$subjects = subjectOptions($section === '' ? null : $section);
$teachers = teacherOptions();

$listing = listResults([
    'search'     => $search,
    'session_id' => $sessionId,
    'term_id'    => $termId,
    'class_id'   => $classId,
    'section'    => $section,
    'subject_id' => $subjectId,
    'teacher_id' => $teacherId,
    'status'     => $status,
    'per_page'   => $perPage,
    'page'       => $page,
]);

$rows   = $listing['rows'];
$paging = $listing['paging'];

$counts = resultStatusCounts([
    'session_id' => $sessionId,
    'term_id'    => $termId,
    'class_id'   => $classId,
    'section'    => $section,
    'subject_id' => $subjectId,
    'teacher_id' => $teacherId,
]);

$current = currentSession();

/* Positions are calculated for the filter in view, so the administrator can see
   who is leading a class without opening every student's record. */
$positions = [];

if ($classId !== null && $termId !== null) {
    $positions = classResultPositions($classId, $sessionId ?? (int) ($current['id'] ?? 0), $termId);
}

$pending = (int) $counts['submitted'];

$pageTitle    = 'Results';
$pageHeading  = 'Result Administration';
$pageSubtitle = 'Search, review, approve, return and lock every result on the portal.';
$activeMenu   = 'results';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn-sm btn-gold" href="<?= e(currentUrl(['status' => 'submitted', 'page' => 1])) ?>">
            <i class="bi bi-inbox-fill"></i> Pending Review (<?= $pending ?>)
        </a>
    </div>
</div>

<div class="row g-3 mb-3">
    <?php foreach ([
        ['label' => 'Draft', 'value' => $counts['draft'], 'colour' => 'var(--green)', 'icon' => 'bi-pencil'],
        ['label' => 'Awaiting Review', 'value' => $counts['submitted'], 'colour' => 'var(--gold)', 'icon' => 'bi-inbox'],
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
                   placeholder="Admission number, student, subject, class or teacher">
        </div>

        <select class="form-select w-auto" name="session_id" aria-label="Filter by academic session">
            <option value="">All sessions</option>
            <?php foreach ($sessions as $option): ?>
                <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $sessionId, (string) $option['id']) ?>>
                    <?= e($option['session_name']) ?><?= (int) $option['is_current'] === 1 ? ' (current)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select class="form-select w-auto" name="term_id" aria-label="Filter by term">
            <option value="">All terms</option>
            <?php foreach ($terms as $option): ?>
                <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $termId, (string) $option['id']) ?>>
                    <?= e($option['term_name']) ?><?= (int) $option['is_current'] === 1 ? ' (current)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select class="form-select w-auto" name="section" aria-label="Filter by section">
            <option value="">All sections</option>
            <?php foreach (['Nursery', 'Primary', 'Secondary'] as $option): ?>
                <option value="<?= e($option) ?>"<?= isSelected($section, $option) ?>><?= e($option) ?></option>
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

        <select class="form-select w-auto" name="subject_id" aria-label="Filter by subject">
            <option value="">All subjects</option>
            <?php foreach ($subjects as $option): ?>
                <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $subjectId, (string) $option['id']) ?>>
                    <?= e($option['subject_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select class="form-select w-auto" name="teacher_id" aria-label="Filter by teacher">
            <option value="">All teachers</option>
            <?php foreach ($teachers as $option): ?>
                <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $teacherId, (string) $option['id']) ?>>
                    <?= e(teacherFullName($option)) ?>
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
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/results.php')) ?>">Reset</a>
        <a class="btn btn-sm btn-outline-primary no-print" href="<?= e(assetUrl('admin/print-results.php' . '?' . currentQueryString())) ?>" target="_blank" rel="noopener">
            <i class="bi bi-printer-fill"></i> Print
        </a>
    </div>
</form>

<?php if ($positions !== []): ?>
    <div class="card table-card mb-3">
        <div class="table-card__head">
            <h2 class="table-card__title">
                <i class="bi bi-trophy-fill text-green"></i> Class Positions
                <small>
                    approved and locked results only &middot; equal totals share a position
                </small>
            </h2>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                <tr>
                    <th>Position</th>
                    <th>Student ID</th>
                    <th class="text-center">Subjects</th>
                    <th class="text-center">Overall Total</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($positions as $studentId => $entry): ?>
                    <tr>
                        <td>
                            <span class="badge bg-gold text-dark"><?= (int) $entry['position'] ?></span>
                        </td>
                        <td class="mono">#<?= (int) $studentId ?></td>
                        <td class="text-center"><?= (int) $entry['subjects'] ?></td>
                        <td class="text-center mono">
                            <?= e(rtrim(rtrim(number_format((float) $entry['overall_total'], 2, '.', ''), '0'), '.')) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="table-card">
    <div class="table-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-clipboard-data-fill text-green"></i> Result Records
            <small>(<?= (int) $paging['total'] ?> total)</small>
        </h2>
        <form method="get" class="d-flex align-items-center gap-2 m-0">
            <?php foreach ([
                'search' => $search, 'session_id' => (string) $sessionId, 'term_id' => (string) $termId,
                'class_id' => (string) $classId, 'section' => $section, 'subject_id' => (string) $subjectId,
                'teacher_id' => (string) $teacherId, 'status' => $status,
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
                <th>Session / Term</th>
                <th>Teacher</th>
                <th class="text-center">Total</th>
                <th class="text-center">Grade</th>
                <th class="text-center">Status</th>
                <th class="text-end">Action</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <?= emptyRow(10, 'No result found. Adjust the filters, or wait for a teacher to submit a result sheet.') ?>
            <?php endif; ?>

            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="cell-strong"><?= e($row['first_name'] . ' ' . $row['last_name']) ?></td>
                    <td class="mono"><?= e($row['admission_number']) ?></td>
                    <td>
                        <?= e($row['class_name']) ?>
                        <?= sectionBadge((string) $row['section']) ?>
                    </td>
                    <td>
                        <span class="mono"><?= e($row['subject_code']) ?></span>
                        <span class="cell-strong"><?= e($row['subject_name']) ?></span>
                    </td>
                    <td>
                        <?= e($row['session_name']) ?>
                        <br><small class="text-muted"><?= e($row['term_name']) ?></small>
                    </td>
                    <td><?= e($row['teacher_first_name'] . ' ' . $row['teacher_last_name']) ?></td>
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
                        <?php if (!empty($row['admin_feedback'])): ?>
                            <br><small class="text-danger" title="<?= e($row['admin_feedback']) ?>">
                                <i class="bi bi-chat-left-text-fill"></i> Returned
                            </small>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-success"
                           href="<?= e(assetUrl('admin/result-review.php?id=' . (int) $row['id'])) ?>">
                            <i class="bi bi-eye-fill"></i> Review
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
    <i class="bi bi-shield-lock me-1"></i>
    Totals, averages and positions are calculated from approved and locked
    results every time this page loads. A draft or a result still waiting for
    review never changes a position.
</p>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
