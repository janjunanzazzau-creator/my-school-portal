<?php

declare(strict_types=1);

/**
 * Printable Teacher My Results
 *
 * Prints the current filtered result list for the signed-in teacher.
 * Respects all current filters and teacher scope.
 */

require_once __DIR__ . '/../includes/teacher_bootstrap.php';

$profile = currentTeacherProfile();

if ($profile === null) {
    setFlash('error', 'No teacher record is linked to your account. Please contact the administrator.');
    redirect('login.php');
}

$teacherId = (int) $profile['id'];

/* Rebuild the exact same filter state as the list page */
$search    = trim((string) ($_GET['search'] ?? ''));
$sessionId = queryInt('session_id');
$termId    = queryInt('term_id');
$classId   = queryInt('class_id');
$status    = (string) ($_GET['status'] ?? '');

if (!inList($status, resultStatuses())) {
    $status = '';
}

/* Build scope from teacher's own allocations */
$scope      = teacherResultScope($teacherId);
$scopeClass = [];
$scopeTerm  = [];
$scopeSession = [];

foreach ($scope as $row) {
    $scopeClass[(int) $row['class_id']]   = (string) $row['class_name'] . ' (' . $row['section'] . ')';
    $scopeSession[(int) $row['session_id']] = (string) $row['session_name'];
}

if ($sessionId !== null) {
    foreach (sessionTermOptions($sessionId) as $option) {
        $scopeTerm[(int) $option['id']] = (string) $option['term_name'];
    }
}

$filters = [
    'search'     => $search,
    'session_id' => $sessionId,
    'term_id'    => $termId,
    'class_id'   => $classId,
    'status'     => $status,
    'teacher_id' => $teacherId,
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
if ($sessionId !== null && isset($scopeSession[$sessionId])) { $filterParts[] = 'Session: ' . $scopeSession[$sessionId]; }
if ($termId !== null && isset($scopeTerm[$termId])) { $filterParts[] = 'Term: ' . $scopeTerm[$termId]; }
if ($classId !== null && isset($scopeClass[$classId])) { $filterParts[] = 'Class: ' . $scopeClass[$classId]; }
if ($status !== '') { $filterParts[] = 'Status: ' . resultStatusLabel($status); }
$filterDesc = $filterParts ? 'Filtered by: ' . implode(' | ', $filterParts) : 'All my results';

$printTitle = 'My Results';
$printSubtitle = $filterDesc . ' (' . (int) $total . ' records) | ' . e(teacherFullName($profile));

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
            <th>Session</th>
            <th>Term</th>
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
                <td><?= e($row['class_name']) ?></td>
                <td>
                    <span class="mono"><?= e($row['subject_code']) ?></span>
                    <span class="cell-strong"><?= e($row['subject_name']) ?></span>
                </td>
                <td><?= e($row['session_name']) ?></td>
                <td><?= e($row['term_name']) ?></td>
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