<?php

declare(strict_types=1);

/**
 * Report card administration.
 *
 * Every report card on the portal in one list, filtered by academic session,
 * term, class, section, student and workflow status, with a free text search over
 * admission number, student name, class name and report number.
 *
 * The page is read only. Generation lives in admin/report-card-generate.php, the
 * figures live in admin/report-card-view.php, and every state change is made
 * through the workflow in config/functions.php, so the rules exist in one place.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

/* --- Filters --------------------------------------------------------------- */
$search    = trim((string) ($_GET['search'] ?? ''));
$sessionId = queryInt('session_id');
$termId    = queryInt('term_id');
$classId   = queryInt('class_id');
$section   = (string) ($_GET['section'] ?? '');
$status    = (string) ($_GET['status'] ?? '');
$perPage   = resolvePerPage();
$page      = queryInt('page') ?? 1;

if (!in_array($section, ['Nursery', 'Primary', 'Secondary'], true)) {
    $section = '';
}

if (!inList($status, reportCardStatuses())) {
    $status = '';
}

$sessions = sessionOptions();
$terms    = $sessionId === null ? [] : sessionTermOptions($sessionId);
$classes  = classOptions($section);

$listing = listReportCards([
    'search'     => $search,
    'session_id' => $sessionId,
    'term_id'    => $termId,
    'class_id'   => $classId,
    'section'    => $section,
    'status'     => $status,
    'per_page'   => $perPage,
    'page'       => $page,
]);

$rows   = $listing['rows'];
$paging = $listing['paging'];

$counts = reportCardStatusCounts([
    'session_id' => $sessionId,
    'term_id'    => $termId,
    'class_id'   => $classId,
    'section'    => $section,
]);

$pageTitle    = 'Report Cards';
$pageHeading  = 'Report Card Administration';
$pageSubtitle = 'Generate, review, approve, lock and print every report card on the portal.';
$activeMenu   = 'reportcards';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn-sm btn-green" href="<?= e(assetUrl('admin/report-card-generate.php')) ?>">
            <i class="bi bi-magic"></i> Generate Report Cards
        </a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('admin/attendance.php')) ?>">
            <i class="bi bi-calendar-check-fill"></i> Attendance
        </a>
    </div>
</div>

<div class="row g-3 mb-3">
    <?php foreach ([
        ['label' => 'Total Cards',   'value' => $counts['total'],     'colour' => 'var(--green)', 'icon' => 'bi-files'],
        ['label' => 'Awaiting Approval', 'value' => $counts['generated'], 'colour' => 'var(--gold)',  'icon' => 'bi-hourglass-split'],
        ['label' => 'Approved',      'value' => $counts['approved'],  'colour' => '#1f6f8b',       'icon' => 'bi-check-circle'],
        ['label' => 'Locked',        'value' => $counts['locked'],    'colour' => '#3a3f58',       'icon' => 'bi-lock-fill'],
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
                   placeholder="Admission number, student, class or report number">
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

        <select class="form-select w-auto" name="status" aria-label="Filter by status">
            <option value="">All statuses</option>
            <?php foreach (reportCardStatuses() as $option): ?>
                <option value="<?= e($option) ?>"<?= isSelected($status, $option) ?>>
                    <?= e(ucfirst($option)) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button class="btn btn-green" type="submit"><i class="bi bi-funnel-fill"></i> Filter</button>
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/report-cards.php')) ?>">Reset</a>
        <a class="btn btn-sm btn-outline-primary no-print" href="<?= e(assetUrl('admin/print-report-cards.php' . '?' . currentQueryString())) ?>" target="_blank" rel="noopener">
            <i class="bi bi-printer-fill"></i> Print
        </a>
    </div>
</form>

<div class="table-card">
    <div class="table-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-file-earmark-text-fill text-green"></i> Report Cards
            <small>(<?= (int) $paging['total'] ?> total)</small>
        </h2>
        <form method="get" class="d-flex align-items-center gap-2 m-0">
            <?php foreach ([
                'search' => $search, 'session_id' => (string) $sessionId, 'term_id' => (string) $termId,
                'class_id' => (string) $classId, 'section' => $section, 'status' => $status,
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
                <th>Report No.</th>
                <th>Student</th>
                <th>Admission No.</th>
                <th>Class</th>
                <th>Session / Term</th>
                <th class="text-center">Average</th>
                <th class="text-center">Position</th>
                <th class="text-center">Status</th>
                <th class="text-end">Action</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <?= emptyRow(9, 'No report card found. Generate one from a class where every subject result is approved.') ?>
            <?php endif; ?>

            <?php foreach ($rows as $row): ?>
                <?php $rowStatus = (string) $row['status']; ?>
                <tr>
                    <td class="mono small"><?= e($row['report_number']) ?></td>
                    <td class="cell-strong"><?= e(trim($row['first_name'] . ' ' . ($row['middle_name'] ?? '') . ' ' . $row['last_name'])) ?></td>
                    <td class="mono"><?= e($row['admission_number']) ?></td>
                    <td>
                        <?= e($row['class_name']) ?>
                        <?= sectionBadge((string) $row['section']) ?>
                    </td>
                    <td>
                        <?= e($row['session_name']) ?>
                        <br><small class="text-muted"><?= e($row['term_name']) ?></small>
                    </td>
                    <td class="text-center mono">
                        <?= e(rtrim(rtrim(number_format((float) $row['average_score'], 2, '.', ''), '0'), '.')) ?>
                    </td>
                    <td class="text-center">
                        <?php if ($row['position'] === null): ?>
                            <span class="text-muted">&ndash;</span>
                        <?php else: ?>
                            <span class="badge bg-gold text-dark"><?= e(ordinalSuffix((int) $row['position'])) ?></span>
                            <br><small class="text-muted">of <?= (int) $row['total_students'] ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge <?= e(reportCardBadgeClass($rowStatus)) ?>">
                            <?= e(ucfirst($rowStatus)) ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-success"
                           href="<?= e(assetUrl('admin/report-card-view.php?id=' . (int) $row['id'])) ?>">
                            <i class="bi bi-eye-fill"></i> Open
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= renderPagination($paging, 'report cards') ?>
</div>

<p class="mt-3 mb-4 text-muted small">
    <i class="bi bi-shield-lock me-1"></i>
    Averages, positions and subject counts on a report card are calculated from
    approved and locked results at the moment it is generated. They are never
    copied from a form, and a result still waiting for review never moves a
    position.
</p>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
