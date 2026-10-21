<?php

declare(strict_types=1);

/**
 * Printable Teacher My Report Cards
 *
 * Prints the current filtered report card list for the signed-in teacher's classes.
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

if (!inList($status, reportCardStatuses())) {
    $status = '';
}

/* The teacher's own scope, rebuilt on every request */
$scope        = teacherReportCardScope($teacherId);
$scopeClass   = [];
$scopeSession = [];
$scopeTerm    = [];

foreach ($scope as $row) {
    $scopeClass[(int) $row['class_id']]    = (string) $row['class_name'] . ' (' . $row['section'] . ')';
    $scopeSession[(int) $row['session_id']] = (string) $row['session_name'];
}

if ($sessionId !== null) {
    foreach (sessionTermOptions($sessionId) as $option) {
        $scopeTerm[(int) $option['id']] = (string) $option['term_name'];
    }
}

$allowedClassIds = array_keys($scopeClass);

$filters = [
    'search'     => $search,
    'session_id' => $sessionId,
    'term_id'    => $termId,
    'class_id'   => $classId,
    'status'     => $status,
    'class_ids'  => $allowedClassIds,
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
if ($sessionId !== null && isset($scopeSession[$sessionId])) { $filterParts[] = 'Session: ' . $scopeSession[$sessionId]; }
if ($termId !== null && isset($scopeTerm[$termId])) { $filterParts[] = 'Term: ' . $scopeTerm[$termId]; }
if ($classId !== null && isset($scopeClass[$classId])) { $filterParts[] = 'Class: ' . $scopeClass[$classId]; }
if ($status !== '') { $filterParts[] = 'Status: ' . ucfirst($status); }
$filterDesc = $filterParts ? 'Filtered by: ' . implode(' | ', $filterParts) : 'All my report cards';

$printTitle = 'My Report Cards';
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
            <th>Session / Term</th>
            <th class="text-center">Average</th>
            <th class="text-center">Position</th>
            <th class="text-center">Status</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <tr>
                <td colspan="8" class="text-center text-muted py-4">
                    <i class="bi bi-inbox me-1"></i>No report cards found matching the current filters.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($rows as $index => $row): ?>
            <tr>
                <td class="text-center mono"><?= $index + 1 ?></td>
                <td class="cell-strong"><?= e(trim($row['first_name'] . ' ' . ($row['middle_name'] ?? '') . ' ' . $row['last_name'])) ?></td>
                <td class="mono"><?= e($row['admission_number']) ?></td>
                <td><?= e($row['class_name']) ?></td>
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