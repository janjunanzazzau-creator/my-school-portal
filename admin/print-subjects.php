<?php

declare(strict_types=1);

/**
 * Printable Subject List
 *
 * Prints the current filtered subject list.
 * Respects all current filters: search, section, status.
 */

require_once __DIR__ . '/../config/functions.php';

requireAnyRole();

/* Rebuild the exact same filter state as the list page */
$search  = trim((string) ($_GET['search'] ?? ''));
$section = (string) ($_GET['section'] ?? '');
$status  = (string) ($_GET['status'] ?? '');

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
];

/* Get all matching records (no pagination for print) */
$listing = listSubjects($filters + ['per_page' => 10000, 'offset' => 0]);
$subjects = $listing['rows'];
$total = $listing['total'];

/* Build filter description for subtitle */
$filterParts = [];
if ($search !== '') { $filterParts[] = 'Search: "' . $search . '"'; }
if ($section !== '') { $filterParts[] = 'Section: ' . $section; }
if ($status !== '') { $filterParts[] = 'Status: ' . $status; }
$filterDesc = $filterParts ? 'Filtered by: ' . implode(' | ', $filterParts) : 'All subjects';

$printTitle = 'Subject List';
$printSubtitle = $filterDesc . ' (' . (int) $total . ' records)';

require __DIR__ . '/../includes/print-header.php';
?>

<div class="print-table">
    <table class="table">
        <thead>
        <tr>
            <th style="width: 5%;">#</th>
            <th>Subject Code</th>
            <th>Subject Name</th>
            <th>Section</th>
            <th>Assignments</th>
            <th>Status</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($subjects === []): ?>
            <tr>
                <td colspan="6" class="text-center text-muted py-4">
                    <i class="bi bi-inbox me-1"></i>No subjects found matching the current filters.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($subjects as $index => $subject): ?>
            <tr>
                <td class="text-center mono"><?= $index + 1 ?></td>
                <td class="mono cell-strong"><?= e($subject['subject_code']) ?></td>
                <td class="cell-strong"><?= e($subject['subject_name']) ?></td>
                <td><?= e($subject['section']) ?></td>
                <td class="text-center"><?= (int) $subject['class_count'] ?></td>
                <td><?= e($subject['status']) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/print-footer.php'; ?>