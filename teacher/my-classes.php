<?php

declare(strict_types=1);

/**
 * My Classes.
 *
 * The list of classes the signed-in teacher is responsible for, grouped by
 * academic session with the current session first. Nothing here is filtered by a
 * teacher id taken from the request: the id always comes from
 * currentTeacherProfile(), so one teacher can never read another teacher's list
 * by editing the address bar.
 */

require_once __DIR__ . '/../includes/teacher_bootstrap.php';

$profile = currentTeacherProfile();

if ($profile === null) {
    setFlash('error', 'No teacher record is linked to your account. Please contact the administrator.');
    redirect('login.php');
}

$teacherId  = (int) $profile['id'];
$session    = currentSession();
$term       = currentTerm();
$classRoles = teacherClassAssignments($teacherId);

/* The enrollment count tells the teacher how many result sheets they are
   responsible for, which is what they actually care about. */
$counts = $session === null ? [] : enrollmentCountsByClass((int) $session['id']);

$currentSessionId = $session === null ? 0 : (int) $session['id'];
$activeNow        = [];
$otherSessions    = [];

foreach ($classRoles as $row) {
    $row['section'] = (string) $row['section'];
    $row['is_current_session'] = (int) $row['session_id'] === $currentSessionId;

    if ($row['is_current_session'] && (string) $row['status'] === 'Active') {
        $activeNow[] = $row;
    } else {
        $otherSessions[] = $row;
    }
}

$pageTitle    = 'My Classes';
$pageHeading  = 'My Classes';
$pageSubtitle = 'The classes assigned to you, and how many students are in each one.';
$activeMenu   = 'myclasses';

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

<?php if ($classRoles === []): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span>
            You have not been assigned to any class yet. The administrator assigns
            class and subject allocations from the Teacher Assignments page.
        </span>
    </div>
<?php endif; ?>

<div class="table-card">
    <div class="table-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-door-open-fill text-green"></i> Classes In The Current Session
            <small>
                <?= $session === null ? 'no current session' : e($session['session_name']) ?>
            </small>
        </h2>
    </div>

    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
            <tr>
                <th>Class</th>
                <th>Section</th>
                <th>Students Enrolled</th>
                <th>Assigned On</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($activeNow === []): ?>
                <?= emptyRow(5, 'You are not the class teacher of any class in this session.') ?>
            <?php endif; ?>

            <?php foreach ($activeNow as $row): ?>
                <tr>
                    <td class="cell-strong"><?= e($row['class_name']) ?></td>
                    <td><?= sectionBadge($row['section']) ?></td>
                    <td>
                        <?php $enrolled = (int) ($counts[(int) $row['class_id']] ?? 0); ?>
                        <span class="cell-strong"><?= $enrolled ?></span>
                        <a class="small ms-2"
                           href="<?= e(assetUrl('teacher/enter-result.php?class_id=' . (int) $row['class_id'])) ?>">
                            View results
                        </a>
                    </td>
                    <td class="mono"><?= e($row['assigned_date']) ?></td>
                    <td><?= statusBadge((string) $row['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($otherSessions !== []): ?>
    <div class="table-card mt-3">
        <div class="table-card__head">
            <h2 class="table-card__title">
                <i class="bi bi-clock-history text-green"></i> Earlier Allocations
                <small>(<?= count($otherSessions) ?> record<?= count($otherSessions) === 1 ? '' : 's' ?>)</small>
            </h2>
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                <tr>
                    <th>Class</th>
                    <th>Section</th>
                    <th>Academic Session</th>
                    <th>Assigned On</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($otherSessions as $row): ?>
                    <tr class="<?= $row['is_current_session'] ? '' : 'text-muted' ?>">
                        <td class="cell-strong"><?= e($row['class_name']) ?></td>
                        <td><?= sectionBadge($row['section']) ?></td>
                        <td><?= e($row['session_name']) ?></td>
                        <td class="mono"><?= e($row['assigned_date']) ?></td>
                        <td><?= statusBadge((string) $row['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<p class="mt-3 mb-4 text-muted small">
    <i class="bi bi-info-circle me-1"></i>
    Only a class you are actively assigned to can be selected on the
    <a href="<?= e(assetUrl('teacher/enter-result.php')) ?>">Enter Results</a> page.
</p>

<?php require __DIR__ . '/../includes/teacher_bottom.php'; ?>
