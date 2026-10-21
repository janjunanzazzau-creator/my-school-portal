<?php

declare(strict_types=1);

/**
 * JSON endpoint that returns the active classes of a section.
 * Used by the student and assignment forms to populate the class dropdown.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$section = (string) ($_GET['section'] ?? '');
$status  = (string) ($_GET['status'] ?? 'Active');

if (!inList($status, RECORD_STATUSES)) {
    $status = 'Active';
}

/* An empty section is only valid when every active class is requested. */
if ($section === '' && $status !== 'Active') {
    echo json_encode(['ok' => false, 'classes' => [], 'message' => 'Invalid request.']);
    exit;
}

if (!inList($section, SECTIONS)) {
    echo json_encode(['ok' => false, 'classes' => [], 'message' => 'Invalid section.']);
    exit;
}

$rows = dbAll(
    'SELECT id, class_name, section
     FROM classes
     WHERE section = :section AND status = :status
     ORDER BY class_name',
    [':section' => $section, ':status' => $status]
);

echo json_encode([
    'ok'      => true,
    'classes' => array_map(static function (array $row): array {
        return [
            'id'         => (int) $row['id'],
            'class_name' => (string) $row['class_name'],
            'section'    => (string) $row['section'],
        ];
    }, $rows),
]);
