<?php

declare(strict_types=1);

/**
 * Printable Student Enrollments
 *
 * Prints the current filtered enrollment list.
 * Respects all current filters: search, session, class, status.
 */

require_once __DIR__ . '/../config/functions.php';

requireAnyRole();

/* Rebuild the exact same filter state as the list page */
$search    = trim((string) ($_GET['search'] ?? ''));
$sessionId = queryInt('session_id');
$classId   = queryInt('class_id');
$status    = (string) ($_GET['status'] ?? '');

if (!inList($status, ASSIGNMENT_STATUSES)) {
    $status = '';
}

$filters = [
    'search'     => $search,
    'session_id' => $sessionId,
    'class_id'   => $classId,
    'status'     => $status,
];

/* Get all matching records (no pagination for print) */
$listing = listEnrollments($filters + ['per_page' => 10000, 'offset' => 0]);
$enrollments = $listing['rows'];
$total = $listing['total'];

/* Build filter description for subtitle */
$filterParts = [];
if ($search !== '') { $filterParts[] = 'Search: "' . $search . '"'; }
if ($sessionId !== null) {
    $session = findSession($sessionId);
    if ($session) { $filterParts[] = 'Session: ' . $session['session_name']; }
}
if ($classId !== null) {
    $class = findClass($classId);
    if ($class) { $filterParts[] = 'Class: ' . $class['class_name'] . ' (' . $class['section'] . ')'; }
}
if ($status !== '') { $filterParts[] = 'Status: ' . $status; }
$filterDesc = $filterParts ? 'Filtered by: ' . implode(' | ', $filterParts) : 'All enrollments';

$printTitle = 'Student Enrollments';
$printSubtitle = $filterDesc . ' (' . (int) $total . ' records)';

require __DIR__ . '/../includes/print-header.php';
?>

<div class="print-table">
    <table class="table">
        <thead>
        <tr>
            <th style="width: 5%;">#</th>
            <th>Student</th>
            <th>Admission No.</th>
            <th>Gender</th>
            <th>Class</th>
            <th>Section</th>
            <th>Academic Session</th>
            <th>Enrolled On</th>
            <th>Status</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($enrollments === []): ?>
            <tr>
                <td colspan="9" class="text-center text-muted py-4">
                    <i class="bi bi-inbox me-1"></i>No enrollments found matching the current filters.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($enrollments as $index => $row): ?>
            <tr>
                <td class="text-center mono"><?= $index + 1 ?></td>
                <td class="cell-strong"><?= e($row['student_name']) ?></td>
                <td class="mono"><?= e($row['admission_number']) ?></td>
                <td><?= e($row['gender']) ?></td>
                <td><?= e($row['class_name']) ?></td>
                <td><?= e($row['section']) ?></td>
                <td><?= e($row['session_name']) ?></td>
                <td class="mono"><?= e($row['enrollment_date']) ?></td>
                <td><?= e($row['status']) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/print-footer.php'; ?>