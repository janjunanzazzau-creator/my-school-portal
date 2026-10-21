<?php

declare(strict_types=1);

/**
 * Teacher dashboard: the signed-in teacher's own classes, subjects, current
 * session and term, and how much of this term's marking is still outstanding.
 *
 * Nothing here is filtered by an id taken from the request. Every record is
 * looked up through currentTeacherProfile(), so one teacher can never see
 * another teacher's allocation or another teacher's results.
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
$subjects   = teacherSubjectAssignments($teacherId);

/* Count only the allocations that belong to the current session. */
$currentSessionId = $session === null ? 0 : (int) $session['id'];
$classCount       = 0;
$currentClasses   = [];

foreach ($classRoles as $row) {
    if ((string) $row['status'] === 'Active' && (int) $row['session_id'] === $currentSessionId) {
        $classCount++;
        $currentClasses[] = $row;
    }
}

$subjectCount     = 0;
$currentSubjects  = [];

foreach ($subjects as $row) {
    if ((string) $row['status'] === 'Active' && (int) $row['session_id'] === $currentSessionId) {
        $subjectCount++;
        $currentSubjects[] = $row;
    }
}

/* Result progress for this teacher in the current session and term. The teacher
   id is the signed-in teacher's own, so these counts cannot include anyone
   else's marking. */
$resultScope  = [
    'teacher_id' => $teacherId,
    'session_id' => $session === null ? null : (int) $session['id'],
    'term_id'    => $term === null ? null : (int) $term['id'],
];
$resultCounts = resultStatusCounts($resultScope);
$resultAll    = resultStatusCounts(['teacher_id' => $teacherId]);

/* The class and subject pairs this teacher may open, used for the count of
   sheets they still have to mark. */
$scope = teacherResultScope($teacherId, $currentSessionId);
$outstanding = 0;

foreach ($scope as $row) {
    $readiness = resultSheetReadiness(
        (int) $row['class_id'],
        (int) $row['subject_id'],
        $currentSessionId,
        $term === null ? 0 : (int) $term['id']
    );

    if (!$readiness['ready'] && $readiness['total'] > 0) {
        $outstanding++;
    }
}

/* Report cards for the classes allocated to this teacher, in the current
   session and term. The class list comes from teacher_class_assignments, so a
   teacher with no allocation is shown nothing rather than everybody's cards. */
$reportScope    = [
    'session_id' => $session === null ? null : (int) $session['id'],
    'term_id'    => $term === null ? null : (int) $term['id'],
    'class_ids'  => teacherReportCardClassIds($teacherId, $currentSessionId),
];
$reportCounts   = reportCardStatusCounts($reportScope);
$reportAll      = reportCardStatusCounts(['class_ids' => teacherReportCardClassIds($teacherId)]);
$commentableNow = (int) $reportCounts['total'] - (int) $reportCounts['locked'];

$pageTitle    = 'My Dashboard';
$pageHeading  = 'My Dashboard';
$pageSubtitle = 'Your classes and subjects for the current academic session, and your marking progress.';
$activeMenu   = 'dashboard';

require __DIR__ . '/../includes/teacher_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if ($scope !== []): ?>
            <a class="btn btn-sm btn-green" href="<?= e(assetUrl('teacher/enter-result.php')) ?>">
                <i class="bi bi-pencil-square"></i> Enter Result
            </a>
        <?php endif; ?>
        <a class="btn btn-sm btn-outline-primary no-print" href="<?= e(assetUrl('teacher/print-dashboard.php' . '?' . currentQueryString())) ?>" target="_blank" rel="noopener">
            <i class="bi bi-printer-fill"></i> Print
        </a>
    </div>
</div>

<?php if ($session === null): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span>
            No current academic session has been set by the administrator, so no allocation
            can be shown yet.
        </span>
    </div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:var(--green);"><i class="bi bi-door-open-fill"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $classCount ?></div>
                <div class="stat-card__label">My Classes This Session</div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:var(--gold);"><i class="bi bi-journal-bookmark-fill"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $subjectCount ?></div>
                <div class="stat-card__label">My Subject Allocations</div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:#1f6f8b;"><i class="bi bi-person-lines-fill"></i></span>
            <div>
                <div class="stat-card__value"><?= count($classRoles) ?></div>
                <div class="stat-card__label">Class Records (All Sessions)</div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:#3a3f58;"><i class="bi bi-journals-fill"></i></span>
            <div>
                <div class="stat-card__value"><?= count($subjects) ?></div>
                <div class="stat-card__label">Subject Records (All Sessions)</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card table-card">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-person-check-fill text-green"></i> My Class Teacher Role
                    <small><?= $session === null ? 'no current session' : e($session['session_name']) ?></small>
                </h2>
            </div>

            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Class</th>
                        <th>Section</th>
                        <th>Session</th>
                        <th>Assigned On</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($currentClasses === []): ?>
                        <?= emptyRow(5, 'You are not the class teacher of any class in this session.') ?>
                    <?php endif; ?>

                    <?php foreach ($currentClasses as $row): ?>
                        <tr>
                            <td class="cell-strong"><?= e($row['class_name']) ?></td>
                            <td><?= sectionBadge((string) $row['section']) ?></td>
                            <td><?= e($row['session_name']) ?></td>
                            <td class="mono"><?= e($row['assigned_date']) ?></td>
                            <td><?= statusBadge((string) $row['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card table-card">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-journal-bookmark-fill text-green"></i> My Subject Allocation
                    <small><?= $session === null ? 'no current session' : e($session['session_name']) ?></small>
                </h2>
            </div>

            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Subject</th>
                        <th>Class</th>
                        <th>Section</th>
                        <th>Session</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($currentSubjects === []): ?>
                        <?= emptyRow(5, 'No subject has been assigned to you in this session.') ?>
                    <?php endif; ?>

                    <?php foreach ($currentSubjects as $row): ?>
                        <tr>
                            <td>
                                <span class="mono"><?= e($row['subject_code']) ?></span>
                                <span class="cell-strong"><?= e($row['subject_name']) ?></span>
                            </td>
                            <td><?= e($row['class_name']) ?></td>
                            <td><?= sectionBadge((string) $row['section']) ?></td>
                            <td><?= e($row['session_name']) ?></td>
                            <td><?= statusBadge((string) $row['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card table-card mt-3 mb-4">
    <div class="table-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-calendar-check-fill text-green"></i> Current Session And Term
        </h2>
    </div>

    <div class="table-card__body">
        <?php if ($session === null): ?>
            <span class="text-muted">No current academic session.</span>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr>
                            <th scope="row" class="w-50">Academic Session</th>
                            <td>
                                <span class="cell-strong"><?= e($session['session_name']) ?></span>
                                <?= statusBadge((string) $session['status']) ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Runs From</th>
                            <td class="mono"><?= e($session['start_date']) ?> to <?= e($session['end_date']) ?></td>
                        </tr>
                        <tr>
                            <th scope="row">Current Term</th>
                            <td>
                                <?php if ($term === null): ?>
                                    <span class="text-muted">Not set by the administrator</span>
                                <?php else: ?>
                                    <span class="cell-strong"><?= e($term['term_name']) ?></span>
                                    <?= statusBadge((string) $term['status']) ?>
                                    <br><small class="mono text-muted">
                                        <?= e($term['start_date']) ?> to <?= e($term['end_date']) ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Staff Number</th>
                            <td class="mono"><?= e($profile['staff_number']) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Phase 4: this teacher's marking progress for the current term. -->
<div class="row g-3 mb-3">
    <div class="col-12">
        <div class="nav-section mb-0">
            Results - <?= $session === null ? 'no current session' : e($session['session_name']) ?><?= $term === null ? '' : ' &middot; ' . e($term['term_name']) ?>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:var(--green);"><i class="bi bi-pencil"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $resultCounts['draft'] ?></div>
                <div class="stat-card__label">My Draft Results</div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:var(--gold);"><i class="bi bi-send-fill"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $resultCounts['submitted'] ?></div>
                <div class="stat-card__label">Awaiting Review</div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:#1f6f8b;"><i class="bi bi-check-circle-fill"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $resultCounts['approved'] ?></div>
                <div class="stat-card__label">Approved</div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:#3a3f58;"><i class="bi bi-lock-fill"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $resultCounts['locked'] ?></div>
                <div class="stat-card__label">Locked</div>
            </div>
        </div>
    </div>
</div>

<?php if ($outstanding > 0): ?>
    <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <strong><?= $outstanding ?></strong> of your result sheet<?= $outstanding === 1 ? ' is' : 's are' ?>
            still missing scores for this term.
        </span>
        <a class="btn btn-sm btn-green" href="<?= e(assetUrl('teacher/enter-result.php')) ?>">
            <i class="bi bi-pencil-square"></i> Enter Result
        </a>
    </div>
<?php endif; ?>

<div class="table-card mb-4">
    <div class="table-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-clipboard-data-fill text-green"></i> My Result Sheets
            <small>every result you have entered, all sessions</small>
        </h2>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('teacher/my-results.php')) ?>">
            View All
        </a>
    </div>

    <div class="table-card__body">
        <div class="row g-3">
            <?php foreach (resultStatuses() as $statusOption): ?>
                <div class="col-6 col-md-3">
                    <a class="d-block text-decoration-none"
                       href="<?= e(assetUrl('teacher/my-results.php?status=' . $statusOption)) ?>">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge <?= e(resultStatusBadge($statusOption)) ?>">
                                <?= e(resultStatusLabel($statusOption)) ?>
                            </span>
                            <strong class="ms-auto"><?= (int) $resultAll[$statusOption] ?></strong>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>

        <p class="mt-3 mb-0 small text-muted">
            <i class="bi bi-info-circle me-1"></i>
            A result is worked out from the four component scores every time it is
            saved. Once you submit a sheet it stays read only until the
            administrator approves, locks or returns it with a reason.
        </p>
    </div>
</div>

<!-- Phase 5: the report cards for the classes allocated to this teacher. -->
<div class="row g-3 mb-3">
    <div class="col-12">
        <div class="nav-section mb-0">
            Report Cards - <?= $session === null ? 'no current session' : e($session['session_name']) ?><?= $term === null ? '' : ' &middot; ' . e($term['term_name']) ?>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:var(--green);"><i class="bi bi-files"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $reportCounts['total'] ?></div>
                <div class="stat-card__label">Cards For My Classes</div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:var(--gold);"><i class="bi bi-hourglass-split"></i></span>
            <div>
                <div class="stat-card__value"><?= $commentableNow ?></div>
                <div class="stat-card__label">I Can Comment On</div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:#1f6f8b;"><i class="bi bi-check-circle-fill"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $reportCounts['approved'] ?></div>
                <div class="stat-card__label">Approved</div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:#3a3f58;"><i class="bi bi-lock-fill"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $reportCounts['locked'] ?></div>
                <div class="stat-card__label">Locked</div>
            </div>
        </div>
    </div>
</div>

<?php if ($commentableNow > 0): ?>
    <div class="alert alert-info d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-chat-left-text-fill"></i>
            <strong><?= $commentableNow ?></strong> report card<?= $commentableNow === 1 ? '' : 's' ?>
            for your classes <?= $commentableNow === 1 ? 'is' : 'are' ?> still waiting for your class
            teacher's comment.
        </span>
        <a class="btn btn-sm btn-green" href="<?= e(assetUrl('teacher/my-report-cards.php')) ?>">
            <i class="bi bi-pencil-square"></i> Add Comments
        </a>
    </div>
<?php endif; ?>

<div class="card table-card mt-3 mb-4">
    <div class="table-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-file-earmark-text-fill text-green"></i> My Report Cards
            <small>every card for your classes, all sessions</small>
        </h2>
        <a class="btn btn-sm btn-green" href="<?= e(assetUrl('teacher/my-report-cards.php')) ?>">
            View All
        </a>
    </div>

    <div class="table-card__body">
        <div class="row g-3">
            <?php foreach (reportCardStatuses() as $statusOption): ?>
                <div class="col-6 col-md-3">
                    <a class="d-block text-decoration-none"
                       href="<?= e(assetUrl('teacher/my-report-cards.php?status=' . $statusOption)) ?>">
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge <?= e(reportCardBadgeClass($statusOption)) ?>">
                                <?= e(ucfirst($statusOption)) ?>
                            </span>
                            <strong class="ms-auto"><?= (int) $reportAll[$statusOption] ?></strong>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>

        <p class="mt-3 mb-0 small text-muted">
            <i class="bi bi-info-circle me-1"></i>
            The class teacher's comment is the only field you can change. Scores,
            positions, promotion status and the approval state belong to the
            administrator, and a locked card accepts no change from anyone.
        </p>
    </div>
</div>

<p class="mb-4 text-muted small">
    <i class="bi bi-info-circle me-1"></i>
    Your classes and subjects are maintained by the administrator, so nothing on
    this page can be changed by you beyond the class teacher's comment on a
    report card.
</p>

<?php require __DIR__ . '/../includes/teacher_bottom.php'; ?>
