<?php

declare(strict_types=1);

/**
 * Student details, with the academic enrolment history from Phase 3.
 * Result records arrive in a later phase.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

$studentId = queryInt('id');
$student   = $studentId === null ? null : findStudent($studentId);

if ($student === null) {
    setFlash('error', 'The selected student could not be found.');
    redirect('admin/students.php');
}

$class      = $student['class_id'] === null ? null : findClass((int) $student['class_id']);
$enrollments = listEnrollments([
    'student_id' => $studentId,
    'per_page'   => 100,
    'offset'     => 0,
])['rows'];

$pageTitle    = 'Student Details';
$pageHeading  = studentFullName($student);
$pageSubtitle = 'Admission number ' . $student['admission_number'];
$activeMenu   = 'students';
$pageActions  = '<a class="btn btn-sm btn-outline-primary" href="'
    . e(assetUrl('admin/student-edit.php?id=' . (int) $studentId)) . '">'
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
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('admin/students.php')) ?>">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="info-card">
            <div class="info-card__head"><i class="bi bi-person-square text-green"></i> Passport</div>
            <div class="info-card__body text-center">
                <img class="photo-frame" src="<?= e(studentPhotoUrl($student['passport_photo'])) ?>"
                     alt="Passport photograph of <?= e(studentFullName($student)) ?>">
                <div class="mt-2"><?= statusBadge((string) $student['status']) ?></div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="info-card">
            <div class="info-card__head"><i class="bi bi-card-list text-green"></i> Personal Details</div>
            <div class="info-card__body">
                <?= detailRow('Full Name', e(studentFullName($student))) ?>
                <?= detailRow('Admission Number', '<span class="mono">' . e($student['admission_number']) . '</span>') ?>
                <?= detailRow('Gender', e($student['gender'])) ?>
                <?= detailRow('Date of Birth', e($student['date_of_birth'] ?: '&mdash;')) ?>
                <?= detailRow('Section', sectionBadge((string) $student['section'])) ?>
                <?= detailRow('Current Class', e($class['class_name'] ?? 'Not set')) ?>
                <?= detailRow('Admission Date', e($student['admission_date'] ?: '&mdash;')) ?>
                <?= detailRow('Parent / Guardian Name', e($student['parent_name'] ?: '&mdash;')) ?>
                <?= detailRow('Parent / Guardian Phone', '<span class="mono">' . e($student['parent_phone'] ?: '&mdash;') . '</span>') ?>
                <?= detailRow('Parent / Guardian E-mail', e($student['parent_email'] ?: '&mdash;')) ?>
                <?= detailRow('Home Address', e($student['address'] ?: '&mdash;')) ?>
            </div>
        </div>
    </div>
</div>

<div class="table-card">
    <div class="table-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-clock-history text-green"></i> Academic History
            <small>(<?= count($enrollments) ?> record(s))</small>
        </h2>
        <a class="btn btn-sm btn-outline-secondary"
           href="<?= e(assetUrl('admin/student-enrollments.php?student_id=' . (int) $studentId)) ?>">
            Manage Enrolments
        </a>
    </div>

    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
            <tr>
                <th>Academic Session</th>
                <th>Class</th>
                <th>Section</th>
                <th>Enrolment Date</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($enrollments === []): ?>
                <?= emptyRow(5, 'No class enrolment recorded yet.') ?>
            <?php endif; ?>

            <?php foreach ($enrollments as $enrolment): ?>
                <tr>
                    <td class="cell-strong"><?= e($enrolment['session_name']) ?></td>
                    <td><?= e($enrolment['class_name']) ?></td>
                    <td><?= sectionBadge((string) $enrolment['section']) ?></td>
                    <td class="mono"><?= e($enrolment['enrollment_date']) ?></td>
                    <td><?= statusBadge((string) $enrolment['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="info-card">
    <div class="info-card__head"><i class="bi bi-clipboard-data text-green"></i> Result Records</div>
    <div class="info-card__body">
        <div class="alert-soft-info mb-0">
            <i class="bi bi-info-circle"></i>
            No result records available yet. Result entry and report cards are delivered in a later phase.
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
