<?php

declare(strict_types=1);

/**
 * Printable Teacher List
 *
 * Prints the current filtered teacher list.
 * Respects all current filters: search, status.
 */

require_once __DIR__ . '/../config/functions.php';

requireAnyRole();

/* Rebuild the exact same filter state as the list page */
$search  = trim((string) ($_GET['search'] ?? ''));
$status  = (string) ($_GET['status'] ?? '');

if (!inList($status, RECORD_STATUSES)) {
    $status = '';
}

$filters = [
    'search'   => $search,
    'status'   => $status,
];

/* Get all matching records (no pagination for print) */
$listing = listTeachers($filters + ['per_page' => 10000, 'offset' => 0]);
$teachers = $listing['rows'];
$total = $listing['total'];

/* Build filter description for subtitle */
$filterParts = [];
if ($search !== '') { $filterParts[] = 'Search: "' . $search . '"'; }
if ($status !== '') { $filterParts[] = 'Status: ' . $status; }
$filterDesc = $filterParts ? 'Filtered by: ' . implode(' | ', $filterParts) : 'All teachers';

$printTitle = 'Teacher List';
$printSubtitle = $filterDesc . ' (' . (int) $total . ' records)';

require __DIR__ . '/../includes/print-header.php';
?>

<div class="print-table">
    <table class="table">
        <thead>
        <tr>
            <th style="width: 5%;">#</th>
            <th>Staff Number</th>
            <th>Teacher Name</th>
            <th>Gender</th>
            <th>Phone</th>
            <th>E-mail</th>
            <th>Qualification</th>
            <th>Department</th>
            <th>Status</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($teachers === []): ?>
            <tr>
                <td colspan="9" class="text-center text-muted py-4">
                    <i class="bi bi-inbox me-1"></i>No teachers found matching the current filters.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($teachers as $index => $teacher): ?>
            <tr>
                <td class="text-center mono"><?= $index + 1 ?></td>
                <td class="mono cell-strong"><?= e($teacher['staff_number']) ?></td>
                <td class="cell-strong"><?= e(teacherFullName($teacher)) ?></td>
                <td><?= e($teacher['gender']) ?></td>
                <td class="mono"><?= e($teacher['phone'] ?: '&mdash;') ?></td>
                <td><?= $teacher['email'] ? e($teacher['email']) : '<span class="text-muted">&mdash;</span>' ?></td>
                <td><?= e($teacher['qualification'] ?: '&mdash;') ?></td>
                <td><?= e($teacher['department'] ?: '&mdash;') ?></td>
                <td><?= e($teacher['status']) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/print-footer.php'; ?>