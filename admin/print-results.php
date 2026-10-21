<?php

declare(strict_types=1);

/**
 * Printable Result Records
 *
 * Prints the current filtered result list.
 * Respects all current filters: search, session, term, section, class, subject, teacher, status.
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
$subjectId = queryInt('subject_id');
$teacherId = queryInt('teacher_id');
$status    = (string) ($_GET['status'] ?? '');

if (!inList($status, resultStatuses())) {
    $status = '';
}

if (!inList($section, ['Nursery', 'Primary', 'Secondary'])) {
    $section = '';
}

$filters = [
    'search'     => $search,
    'session_id' => $sessionId,
    'term_id'    => $termId,
    'class_id'   => $classId,
    'section'    => $section,
    'subject_id' => $subjectId,
    'teacher_id' => $teacherId,
    'status'     => $status,
    'per_page'   => 10000,
    'page'       => 1,
];

/* Get all matching records (no pagination for print) */
$listing = listResults($filters);
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
if ($subjectId !== null) {
    $subject = findSubject($subjectId);
    if ($subject) { $filterParts[] = 'Subject: ' . $subject['subject_name']; }
}
if ($teacherId !== null) {
    $teacher = findTeacher($teacherId);
    if ($teacher) { $filterParts[] = 'Teacher: ' . teacherFullName($teacher); }
}
if ($status !== '') { $filterParts[] = 'Status: ' . resultStatusLabel($status); }
$filterDesc = $filterParts ? 'Filtered by: ' . implode(' | ', $filterParts) : 'All results';

$printTitle = 'Result Records';
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
            <th>Class</th>
            <th>Subject</th>
            <th>Session / Term</th>
            <th>Teacher</th>
            <th class="text-center">Total</th>
            <th class="text-center">Grade</th>
            <th class="text-center">Status</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <tr>
                <td colspan="10" class="text-center text-muted py-4">
                    <i class="bi bi-inbox me-1"></i>No results found matching the current filters.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($rows as $index => $row): ?>
            <tr>
                <td class="text-center mono"><?= $index + 1 ?></td>
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
                <td class="text-center"><?= e(resultStatusLabel((string) $row['status'])) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/print-footer.php'; ?>