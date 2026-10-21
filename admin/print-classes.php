<?php

declare(strict_types=1);

/**
 * Printable Class List
 *
 * Prints the current filtered class list.
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
$listing = listClasses($filters + ['per_page' => 10000, 'offset' => 0]);
$classes = $listing['rows'];
$total = $listing['total'];

/* Build filter description for subtitle */
$filterParts = [];
if ($search !== '') { $filterParts[] = 'Search: "' . $search . '"'; }
if ($section !== '') { $filterParts[] = 'Section: ' . $section; }
if ($status !== '') { $filterParts[] = 'Status: ' . $status; }
$filterDesc = $filterParts ? 'Filtered by: ' . implode(' | ', $filterParts) : 'All classes';

$printTitle = 'Class List';
$printSubtitle = $filterDesc . ' (' . (int) $total . ' records)';

require __DIR__ . '/../includes/print-header.php';
?>

<div class="print-table">
    <table class="table">
        <thead>
        <tr>
            <th style="width: 5%;">#</th>
            <th>Class Name</th>
            <th>Section</th>
            <th>Class Teacher</th>
            <th>Capacity</th>
            <th>Student Count</th>
            <th>Status</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($classes === []): ?>
            <tr>
                <td colspan="7" class="text-center text-muted py-4">
                    <i class="bi bi-inbox me-1"></i>No classes found matching the current filters.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($classes as $index => $class): ?>
            <tr>
                <td class="text-center mono"><?= $index + 1 ?></td>
                <td class="cell-strong"><?= e($class['class_name']) ?></td>
                <td><?= e($class['section']) ?></td>
                <td><?= $class['class_teacher_id'] === null
                    ? '<span class="text-muted">Not assigned</span>'
                    : e(joinName($class['teacher_first_name'] ?? '', $class['teacher_middle_name'] ?? '', $class['teacher_last_name'] ?? '')) ?></td>
                <td class="text-center"><?= (int) $class['capacity'] ?></td>
                <td class="text-center"><?= (int) $class['student_count'] ?></td>
                <td><?= e($class['status']) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/print-footer.php'; ?>