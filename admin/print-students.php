<?php

declare(strict_types=1);

/**
 * Printable Student List
 *
 * Prints the current filtered student list.
 * Respects all current filters: search, section, class, status, gender.
 */

require_once __DIR__ . '/../config/functions.php';

requireAnyRole();

/* Rebuild the exact same filter state as the list page */
$search    = trim((string) ($_GET['search'] ?? ''));
$section   = (string) ($_GET['section'] ?? '');
$classId   = queryInt('class_id');
$status    = (string) ($_GET['status'] ?? '');
$gender    = (string) ($_GET['gender'] ?? '');

if (!inList($section, SECTIONS)) {
    $section = '';
}
if (!inList($status, STUDENT_STATUSES)) {
    $status = '';
}
if (!inList($gender, GENDERS)) {
    $gender = '';
}

$filters = [
    'search'   => $search,
    'section'  => $section,
    'class_id' => $classId,
    'status'   => $status,
    'gender'   => $gender,
];

/* Get all matching records (no pagination for print) */
$listing = listStudents($filters + ['per_page' => 10000, 'offset' => 0]);
$students = $listing['rows'];
$total = $listing['total'];

/* Build filter description for subtitle */
$filterParts = [];
if ($search !== '') { $filterParts[] = 'Search: "' . $search . '"'; }
if ($section !== '') { $filterParts[] = 'Section: ' . $section; }
if ($classId !== null) {
    $class = findClass($classId);
    if ($class) { $filterParts[] = 'Class: ' . $class['class_name'] . ' (' . $class['section'] . ')'; }
}
if ($status !== '') { $filterParts[] = 'Status: ' . $status; }
if ($gender !== '') { $filterParts[] = 'Gender: ' . $gender; }
$filterDesc = $filterParts ? 'Filtered by: ' . implode(' | ', $filterParts) : 'All students';

$printTitle = 'Student List';
$printSubtitle = $filterDesc . ' (' . (int) $total . ' records)';

require __DIR__ . '/../includes/print-header.php';
?>

<div class="print-table">
    <table class="table">
        <thead>
        <tr>
            <th style="width: 5%;">#</th>
            <th>Admission No.</th>
            <th>Student Name</th>
            <th>Gender</th>
            <th>Section</th>
            <th>Class</th>
            <th>Parent / Guardian</th>
            <th>Phone</th>
            <th>Status</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($students === []): ?>
            <tr>
                <td colspan="9" class="text-center text-muted py-4">
                    <i class="bi bi-inbox me-1"></i>No students found matching the current filters.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($students as $index => $student): ?>
            <tr>
                <td class="text-center mono"><?= $index + 1 ?></td>
                <td class="mono cell-strong"><?= e($student['admission_number']) ?></td>
                <td class="cell-strong"><?= e(studentFullName($student)) ?></td>
                <td><?= e($student['gender']) ?></td>
                <td><?= e($student['section']) ?></td>
                <td><?= e($student['class_name'] ?? 'Not set') ?></td>
                <td><?= $student['parent_name'] !== null && $student['parent_name'] !== ''
                    ? e($student['parent_name']) : '<span class="text-muted">&mdash;</span>' ?></td>
                <td class="mono"><?= $student['parent_phone'] !== null && $student['parent_phone'] !== ''
                    ? e($student['parent_phone']) : '&mdash;' ?></td>
                <td><?= e($student['status']) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/print-footer.php'; ?>