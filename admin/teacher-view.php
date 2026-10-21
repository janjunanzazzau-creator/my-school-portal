<?php

declare(strict_types=1);

/**
 * Teacher details, portal account and Phase 3 assignments.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

$teacherId = queryInt('id');
$teacher   = $teacherId === null ? null : findTeacher($teacherId);

if ($teacher === null) {
    setFlash('error', 'The selected teacher could not be found.');
    redirect('admin/teachers.php');
}

$classAssignments    = teacherClassAssignments($teacherId);
$subjectAssignments  = teacherSubjectAssignments($teacherId);

$pageTitle    = 'Teacher Details';
$pageHeading  = teacherFullName($teacher);
$pageSubtitle = 'Staff number ' . $teacher['staff_number'];
$activeMenu   = 'teachers';
$pageActions  = '<a class="btn btn-sm btn-outline-primary" href="'
    . e(assetUrl('admin/teacher-edit.php?id=' . (int) $teacherId)) . '">'
    . '<i class="bi bi-pencil"></i> Edit</a>';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <?= $pageActions ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('admin/teachers.php')) ?>">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>
</div>

<div class="info-card">
    <div class="info-card__head"><i class="bi bi-card-list text-green"></i> Staff Details</div>
    <div class="info-card__body">
        <?= detailRow('Full Name', e(teacherFullName($teacher))) ?>
        <?= detailRow('Staff Number', '<span class="mono">' . e($teacher['staff_number']) . '</span>') ?>
        <?= detailRow('Gender', e($teacher['gender'])) ?>
        <?= detailRow('Phone', '<span class="mono">' . e($teacher['phone'] ?: '&mdash;') . '</span>') ?>
        <?= detailRow('E-mail', e($teacher['email'] ?: '&mdash;')) ?>
        <?= detailRow('Qualification', e($teacher['qualification'] ?: '&mdash;')) ?>
        <?= detailRow('Department', e($teacher['department'] ?: '&mdash;')) ?>
        <?= detailRow('Address', e($teacher['address'] ?: '&mdash;')) ?>
        <?= detailRow('Status', statusBadge((string) $teacher['status'])) ?>
    </div>
</div>

<div class="info-card">
    <div class="info-card__head"><i class="bi bi-key text-green"></i> Portal Account</div>
    <div class="info-card__body">
        <?php if ($teacher['user_id'] === null): ?>
            <div class="alert-soft-danger mb-0">This teacher has no portal account.</div>
        <?php else: ?>
            <?= detailRow('Username', '<span class="mono">' . e($teacher['username']) . '</span>') ?>
            <?= detailRow('Role', e((string) $teacher['role'])) ?>
            <?= detailRow('Account Status', statusBadge((string) $teacher['account_status'] === '1' ? 'Active' : 'Inactive')) ?>
        <?php endif; ?>
    </div>
</div>

<div class="table-card">
    <div class="table-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-door-open text-green"></i> Class Teacher Assignments
            <small>(<?= count($classAssignments) ?> record(s))</small>
        </h2>
        <a class="btn btn-sm btn-outline-secondary"
           href="<?= e(assetUrl('admin/teacher-class-assign.php?teacher_id=' . (int) $teacherId)) ?>">
            Assign Class
        </a>
    </div>

    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
            <tr>
                <th>Class</th>
                <th>Section</th>
                <th>Academic Session</th>
                <th>Assigned Date</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($classAssignments === []): ?>
                <?= emptyRow(5, 'This teacher has no class assignment yet.') ?>
            <?php endif; ?>

            <?php foreach ($classAssignments as $assignment): ?>
                <tr>
                    <td class="cell-strong"><?= e($assignment['class_name']) ?></td>
                    <td><?= sectionBadge((string) $assignment['section']) ?></td>
                    <td>
                        <?= e($assignment['session_name']) ?>
                        <?php if ((int) $assignment['session_is_current'] === 1): ?>
                            <span class="badge bg-gold">Current</span>
                        <?php endif; ?>
                    </td>
                    <td class="mono"><?= e($assignment['assigned_date']) ?></td>
                    <td><?= statusBadge((string) $assignment['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="table-card">
    <div class="table-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-journal-bookmark text-green"></i> Subject Assignments
            <small>(<?= count($subjectAssignments) ?> record(s))</small>
        </h2>
        <a class="btn btn-sm btn-outline-secondary"
           href="<?= e(assetUrl('admin/teacher-subject-assign.php?teacher_id=' . (int) $teacherId)) ?>">
            Assign Subject
        </a>
    </div>

    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
            <tr>
                <th>Subject</th>
                <th>Class</th>
                <th>Section</th>
                <th>Academic Session</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($subjectAssignments === []): ?>
                <?= emptyRow(5, 'This teacher has no subject assignment yet.') ?>
            <?php endif; ?>

            <?php foreach ($subjectAssignments as $assignment): ?>
                <tr>
                    <td class="cell-strong">
                        <span class="mono"><?= e($assignment['subject_code']) ?></span>
                        <?= e($assignment['subject_name']) ?>
                    </td>
                    <td><?= e($assignment['class_name']) ?></td>
                    <td><?= sectionBadge((string) $assignment['section']) ?></td>
                    <td>
                        <?= e($assignment['session_name']) ?>
                        <?php if ((int) $assignment['session_is_current'] === 1): ?>
                            <span class="badge bg-gold">Current</span>
                        <?php endif; ?>
                    </td>
                    <td><?= statusBadge((string) $assignment['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
