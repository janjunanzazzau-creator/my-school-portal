<?php

declare(strict_types=1);

/**
 * Printable Teacher-to-Class Assignments
 *
 * Prints the current filtered assignment list.
 * Respects all current filters: search, teacher, class, session, status.
 */

require_once __DIR__ . '/../config/functions.php';

requireAnyRole();

/* Rebuild the exact same filter state as the list page */
$search    = trim((string) ($_GET['search'] ?? ''));
$teacherId = queryInt('teacher_id');
$classId   = queryInt('class_id');
$sessionId = queryInt('session_id');
$status    = (string) ($_GET['status'] ?? '');

if (!inList($status, ASSIGNMENT_STATUSES)) {
    $status = '';
}

$filters = [
    'search'     => $search,
    'teacher_id' => $teacherId,
    'class_id'   => $classId,
    'session_id' => $sessionId,
    'status'     => $status,
];

/* Get all matching records (no pagination for print) */
$listing = listTeacherClassAssignments($filters + ['per_page' => 10000, 'offset' => 0]);
$assignments = $listing['rows'];
$total = $listing['total'];

/* Build filter description for subtitle */
$filterParts = [];
if ($search !== '') { $filterParts[] = 'Search: "' . $search . '"'; }
if ($teacherId !== null) {
    $teacher = findTeacher($teacherId);
    if ($teacher) { $filterParts[] = 'Teacher: ' . teacherFullName($teacher); }
}
if ($classId !== null) {
    $class = findClass($classId);
    if ($class) { $filterParts[] = 'Class: ' . $class['class_name'] . ' (' . $class['section'] . ')'; }
}
if ($sessionId !== null) {
    $session = findSession($sessionId);
    if ($session) { $filterParts[] = 'Session: ' . $session['session_name']; }
}
if ($status !== '') { $filterParts[] = 'Status: ' . $status; }
$filterDesc = $filterParts ? 'Filtered by: ' . implode(' | ', $filterParts) : 'All assignments';

$printTitle = 'Teacher-to-Class Assignments';
$printSubtitle = $filterDesc . ' (' . (int) $total . ' records)';

require __DIR__ . '/../includes/print-header.php';
?>

<div class="print-table">
    <table class="table">
        <thead>
        <tr>
            <th style="width: 5%;">#</th>
            <th>Teacher</th>
            <th>Staff No.</th>
            <th>Class</th>
            <th>Section</th>
            <th>Academic Session</th>
            <th>Assigned Date</th>
            <th>Status</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($assignments === []): ?>
            <tr>
                <td colspan="8" class="text-center text-muted py-4">
                    <i class="bi bi-inbox me-1"></i>No class teacher assignments found matching the current filters.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($assignments as $index => $row): ?>
            <tr>
                <td class="text-center mono"><?= $index + 1 ?></td>
                <td class="cell-strong"><?= e($row['teacher_name']) ?></td>
                <td class="mono"><?= e($row['staff_number']) ?></td>
                <td><?= e($row['class_name']) ?></td>
                <td><?= e($row['section']) ?></td>
                <td><?= e($row['session_name']) ?></td>
                <td class="mono"><?= e($row['assigned_date']) ?></td>
                <td><?= e($row['status']) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/print-footer.php'; ?>