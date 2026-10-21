<?php

declare(strict_types=1);

/**
 * Printable Report Card List
 *
 * Prints the current filtered report card list.
 * Respects all current filters: search, session, term, class, section, status.
 */

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../includes/ui.php';

requireAnyRole();

/* Rebuild the exact same filter state as the list page */
$search    = trim((string) ($_GET['search'] ?? ''));
$sessionId = queryInt('session_id');
$termId    = queryInt('term_id');
$classId   = queryInt('class_id');
$section   = (string) ($_GET['section'] ?? '');
$status    = (string) ($_GET['status'] ?? '');

if (!inList($section, ['Nursery', 'Primary', 'Secondary'])) {
    $section = '';
}
if (!inList($status, reportCardStatuses())) {
    $status = '';
}

$filters = [
    'search'     => $search,
    'session_id' => $sessionId,
    'term_id'    => $termId,
    'class_id'   => $classId,
    'section'    => $section,
    'status'     => $status,
    'per_page'   => 10000,
    'page'       => 1,
];

/* Get all matching records (no pagination for print) */
$listing = listReportCards($filters);
$rows = $listing['rows'];
$total = $listing['paging']['total'];

/* Build filter description for subtitle */
$filterParts = [];
if ($search !== '') { $filterParts[] = 'Search: "' . $search . '"'; }
if ($sessionId !== null) {
    $session = findSession($sessionId);
    if ($session) { $filterParts[] = 'Session: ' . $session['session_name']; }
}
if ($termId !== null) {
    $term = dbOne('SELECT term_name FROM terms WHERE id = :id', [':id' => $termId]);
    if ($term) { $filterParts[] = 'Term: ' . $term['term_name']; }
}
if ($section !== '') { $filterParts[] = 'Section: ' . $section; }
if ($classId !== null) {
    $class = findClass($classId);
    if ($class) { $filterParts[] = 'Class: ' . $class['class_name']; }
}
if ($status !== '') { $filterParts[] = 'Status: ' . ucfirst($status); }
$filterDesc = $filterParts ? 'Filtered by: ' . implode(' | ', $filterParts) : 'All report cards';

$printTitle = 'Report Card List';
$printSubtitle = $filterDesc . ' (' . (int) $total . ' records)';

require __DIR__ . '/../includes/print-header.php';
?>

<div class="print-table">
    <table class="table">
        <thead>
        <tr>
            <th style="width: 5%;">#</th>
            <th>Report No.</th>
            <th>Student</th>
            <th>Admission No.</th>
            <th>Class</th>
            <th>Session / Term</th>
            <th class="text-center">Average</th>
            <th class="text-center">Position</th>
            <th class="text-center">Status</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <tr>
                <td colspan="9" class="text-center text-muted py-4">
                    <i class="bi bi-inbox me-1"></i>No report cards found matching the current filters.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($rows as $index => $row): ?>
            <tr>
                <td class="text-center mono"><?= $index + 1 ?></td>
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
                <td class="text-center"><?= e(ucfirst((string) $row['status'])) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/print-footer.php'; ?>