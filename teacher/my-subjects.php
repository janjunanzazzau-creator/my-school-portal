<?php

declare(strict_types=1);

/**
 * My Subjects.
 *
 * A subject allocation is the pairing that gives a teacher the right to enter
 * results, so this page lists exactly the class and subject pairs the teacher
 * may open. The id always comes from currentTeacherProfile() and the list is
 * rebuilt from the database on every request, so setting an allocation to
 * Inactive removes access immediately.
 */

require_once __DIR__ . '/../includes/teacher_bootstrap.php';

$profile = currentTeacherProfile();

if ($profile === null) {
    setFlash('error', 'No teacher record is linked to your account. Please contact the administrator.');
    redirect('login.php');
}

$teacherId = (int) $profile['id'];
$session   = currentSession();
$scope     = teacherResultScope($teacherId);

/* Split the allocations into the current session and everything else. */
$currentSessionId = $session === null ? 0 : (int) $session['id'];
$currentScope     = [];
$pastScope        = [];

foreach ($scope as $row) {
    $row['is_current_session'] = (int) $row['session_id'] === $currentSessionId;

    if ($row['is_current_session']) {
        $currentScope[] = $row;
    } else {
        $pastScope[] = $row;
    }
}

/* Group the current session by subject so the teacher sees subjects, not a
   flat list of repeated subject names. */
$bySubject = [];

foreach ($currentScope as $row) {
    $key = (int) $row['subject_id'];

    if (!isset($bySubject[$key])) {
        $bySubject[$key] = [
            'subject_code' => (string) $row['subject_code'],
            'subject_name' => (string) $row['subject_name'],
            'classes'      => [],
        ];
    }

    $bySubject[$key]['classes'][] = $row;
}

$pageTitle    = 'My Subjects';
$pageHeading  = 'My Subjects';
$pageSubtitle = 'The subjects you teach, and the classes you may enter results for.';
$activeMenu   = 'mysubjects';

require __DIR__ . '/../includes/teacher_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn-sm btn-green" href="<?= e(assetUrl('teacher/enter-result.php')) ?>">
            <i class="bi bi-pencil-square"></i> Enter Results
        </a>
    </div>
</div>

<?php if ($currentScope === []): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span>
            You have no active subject allocation
            <?= $session === null ? 'in the current session' : 'in ' . e($session['session_name']) ?>.
            Ask the administrator to assign you a subject before entering results.
        </span>
    </div>
<?php endif; ?>

<?php if ($bySubject !== []): ?>
    <div class="row g-3">
        <?php foreach ($bySubject as $subject): ?>
            <div class="col-12 col-xl-6">
                <div class="card table-card h-100">
                    <div class="table-card__head">
                        <h2 class="table-card__title">
                            <i class="bi bi-journal-bookmark-fill text-green"></i>
                            <?= e($subject['subject_name']) ?>
                            <small class="mono"><?= e($subject['subject_code']) ?></small>
                        </h2>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                            <tr>
                                <th>Class</th>
                                <th>Section</th>
                                <th class="text-end">Results</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($subject['classes'] as $row): ?>
                                <tr>
                                    <td class="cell-strong"><?= e($row['class_name']) ?></td>
                                    <td><?= sectionBadge((string) $row['section']) ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-success"
                                           href="<?= e(assetUrl('teacher/enter-result.php?class_id=' . (int) $row['class_id'] . '&amp;subject_id=' . (int) $row['subject_id'])) ?>">
                                            <i class="bi bi-pencil-square"></i> Enter
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($pastScope !== []): ?>
    <div class="table-card mt-3">
        <div class="table-card__head">
            <h2 class="table-card__title">
                <i class="bi bi-clock-history text-green"></i> Allocations In Other Sessions
                <small>(<?= count($pastScope) ?> record<?= count($pastScope) === 1 ? '' : 's' ?>)</small>
            </h2>
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                <tr>
                    <th>Subject</th>
                    <th>Class</th>
                    <th>Section</th>
                    <th>Academic Session</th>
                    <th class="text-end">Results</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($pastScope as $row): ?>
                    <tr>
                        <td>
                            <span class="mono"><?= e($row['subject_code']) ?></span>
                            <span class="cell-strong"><?= e($row['subject_name']) ?></span>
                        </td>
                        <td><?= e($row['class_name']) ?></td>
                        <td><?= sectionBadge((string) $row['section']) ?></td>
                        <td><?= e($row['session_name']) ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-secondary"
                               href="<?= e(assetUrl('teacher/enter-result.php?class_id=' . (int) $row['class_id'] . '&amp;subject_id=' . (int) $row['subject_id'] . '&amp;session_id=' . (int) $row['session_id'])) ?>">
                                Open
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<p class="mt-3 mb-4 text-muted small">
    <i class="bi bi-shield-lock me-1"></i>
    These pairs are the only class and subject combinations the portal will let you
    open. Changing the addresses in the browser does not widen your access.
</p>

<?php require __DIR__ . '/../includes/teacher_bottom.php'; ?>
