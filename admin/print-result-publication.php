<?php

declare(strict_types=1);

/**
 * Printable Result Publication List
 *
 * Prints the current filtered publication state list.
 * Respects all current filters: session, term, class, status.
 */

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../includes/ui.php';

requireAnyRole();

/* Rebuild the exact same filter state as the list page */
$sessionId = queryInt('session_id');
$termId    = queryInt('term_id');
$classId   = queryInt('class_id');
$status    = (string) ($_GET['status'] ?? '');

if (!inList($status, resultPublicationStatuses())) {
    $status = '';
}

$filters = [
    'session_id' => $sessionId,
    'term_id'    => $termId,
    'class_id'   => $classId,
    'status'     => $status,
    'page'       => 1,
];

/* Get all matching records (no pagination for print) */
$listing = listResultPublications($filters);
$rows = $listing['rows'];
$total = $listing['paging']['total'];

/* Build filter description for subtitle */
$filterParts = [];
if ($sessionId !== null) {
    $session = findSession($sessionId);
    if ($session) { $filterParts[] = 'Session: ' . $session['session_name']; }
}
if ($termId !== null) {
    $term = dbOne('SELECT term_name FROM terms WHERE id = :id', [':id' => $termId]);
    if ($term) { $filterParts[] = 'Term: ' . $term['term_name']; }
}
if ($classId !== null) {
    $class = findClass($classId);
    if ($class) { $filterParts[] = 'Class: ' . $class['class_name'] . ' (' . $class['section'] . ')'; }
}
if ($status !== '') { $filterParts[] = 'Status: ' . ucfirst($status); }
$filterDesc = $filterParts ? 'Filtered by: ' . implode(' | ', $filterParts) : 'All publication records';

$printTitle = 'Result Publication State';
$printSubtitle = $filterDesc . ' (' . (int) $total . ' records)';

require __DIR__ . '/../includes/print-header.php';
?>

<div class="print-table">
    <table class="table">
        <thead>
        <tr>
            <th style="width: 5%;">#</th>
            <th>Session</th>
            <th>Term</th>
            <th>Class</th>
            <th class="text-center">Cards</th>
            <th class="text-center">State</th>
            <th>Published</th>
            <th>Last Change</th>
        </tr>
        </thead>
        <tbody>
        <?php if ($rows === []): ?>
            <tr>
                <td colspan="8" class="text-center text-muted py-4">
                    <i class="bi bi-inbox me-1"></i>No publication records found matching the current filters.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($rows as $index => $row): ?>
            <tr>
                <td class="text-center mono"><?= $index + 1 ?></td>
                <td><?= e($row['session_name']) ?></td>
                <td><?= e($row['term_name']) ?></td>
                <td>
                    <?= e($row['class_name']) ?>
                    <?= sectionBadge((string) $row['section']) ?>
                </td>
                <td class="text-center mono"><?= (int) $row['card_count'] ?></td>
                <td class="text-center"><?= e(ucfirst((string) $row['status'])) ?></td>
                <td class="mono small">
                    <?= $row['published_at'] === null
                        ? '<span class="text-muted">Never</span>'
                        : e(substr((string) $row['published_at'], 0, 16)) ?>
                </td>
                <td class="mono small"><?= e(substr((string) $row['updated_at'], 0, 16)) ?></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/../includes/print-footer.php'; ?>