<?php

declare(strict_types=1);

/**
 * Administrator dashboard: current session and term, setup status, the record
 * counts from Phases 2 and 3, the Phase 4 result review queue, and the Phase 5
 * report card state.
 *
 * Every panel is built from the real records, so an administrator can see
 * immediately how much of the term's marking has been submitted, approved and
 * locked, how many results are waiting for a decision, and how far the report
 * cards have got.
 */

require_once __DIR__ . '/includes/admin_bootstrap.php';

$stats        = dashboardStatistics();
$session      = currentSession();
$term         = currentTerm();
$sessions     = sessionOptions();
$classes      = classOptions();
$enrollments  = $session === null ? [] : enrollmentCountsByClass((int) $session['id']);

/* Result counts for the current session and term, so the tiles below reflect
   the term being worked on rather than every result ever entered. */
$resultScope = [
    'session_id' => $session === null ? null : (int) $session['id'],
    'term_id'    => $term === null ? null : (int) $term['id'],
];
$resultCounts = resultStatusCounts($resultScope);
$resultAll    = resultStatusCounts();

/* The number the administrator actually acts on: results sitting in the queue. */
$pendingReviews = (int) $resultCounts['submitted'];

/* Report cards for the term in progress, plus every card on the portal. The gap
   between the enrolled students and the generated cards is the work still to do.
   Enrollments are per session, so the count is for the session; the cards are
   counted for the session and the term. */
$reportCounts    = reportCardStatusCounts($resultScope);
$reportAll       = reportCardStatusCounts();
$enrolledForTerm = $session === null
    ? 0
    : (int) dbValue(
        "SELECT COUNT(*)
           FROM student_class_enrollments e
           JOIN students s ON s.id = e.student_id
          WHERE e.session_id = :session_id
            AND e.status = 'Active'
            AND s.status = 'Active'",
        [':session_id' => (int) $session['id']]
    );
$cardsStillToDo = max(0, $enrolledForTerm - (int) $reportCounts['total']);

/* Phase 6: what the public can actually reach. The PIN numbers come from the real
   rows, with a PIN whose expiry date has passed counted as expired rather than
   active, so the tiles match what the PIN list shows. */
$pinCounts       = resultPinStatusCounts();
$publicationCounts = resultPublicationCounts();
$accessCounts    = resultAccessLogCounts();

$setupSteps = [
    [
        'label' => 'Academic session created',
        'done'  => $session !== null,
        'url'   => 'admin/sessions.php',
        'note'  => $session === null
            ? 'No academic session exists yet.'
            : $session['session_name'] . ' (' . $session['start_date'] . ' to ' . $session['end_date'] . ')',
    ],
    [
        'label' => 'Current session selected',
        'done'  => $session !== null,
        'url'   => 'admin/sessions.php',
        'note'  => $session === null
            ? 'Mark one session as current to make it the default everywhere.'
            : 'Current session: ' . $session['session_name'],
    ],
    [
        'label' => 'Current term selected',
        'done'  => $term !== null,
        'url'   => 'admin/terms.php',
        'note'  => $term === null
            ? 'Create a term and mark it as current.'
            : 'Current term: ' . $term['term_name'],
    ],
    [
        'label' => 'Class teacher assigned',
        'done'  => $stats['classes'] > 0
            && (int) dbValue(
                'SELECT COUNT(*) FROM teacher_class_assignments WHERE status = "Active"'
                . ($session === null ? '' : ' AND session_id = ' . (int) $session['id'])
            ) > 0,
        'url'   => 'admin/teacher-class-assignments.php',
        'note'  => 'Assign each class a class teacher.',
    ],
    [
        'label' => 'Subjects assigned to teachers',
        'done'  => $stats['subjects'] > 0
            && (int) dbValue(
                'SELECT COUNT(*) FROM teacher_subject_assignments WHERE status = "Active"'
                . ($session === null ? '' : ' AND session_id = ' . (int) $session['id'])
            ) > 0,
        'url'   => 'admin/teacher-subject-assignments.php',
        'note'  => 'Assign every subject a teacher for each class.',
    ],
    [
        'label' => 'Students enrolled in a class',
        'done'  => $stats['enrolled'] > 0,
        'url'   => 'admin/bulk-enrolment.php',
        'note'  => $stats['enrolled'] > 0
            ? $stats['enrolled'] . ' student(s) enrolled this session.'
            : 'Enrol students so each one belongs to a class.',
    ],
];

$pageTitle    = 'Dashboard';
$pageHeading  = 'Dashboard';
$pageSubtitle = 'Overview of the portal for the current academic session and term.';
$activeMenu   = 'dashboard';

require __DIR__ . '/includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn-sm btn-green" href="<?= e(assetUrl('admin/student-add.php')) ?>">
            <i class="bi bi-plus-circle-fill"></i> New Student
        </a>
        <a class="btn btn-sm btn-gold" href="<?= e(assetUrl('admin/bulk-enrolment.php')) ?>">
            <i class="bi bi-people-fill"></i> Bulk Enrol
        </a>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:var(--green);"><i class="bi bi-people-fill"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $stats['students'] ?></div>
                <div class="stat-card__label">Active Students</div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:var(--gold);"><i class="bi bi-person-badge-fill"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $stats['teachers'] ?></div>
                <div class="stat-card__label">Active Teachers</div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:#1f6f8b;"><i class="bi bi-door-open-fill"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $stats['classes'] ?></div>
                <div class="stat-card__label">Active Classes</div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:#3a3f58;"><i class="bi bi-journal-bookmark-fill"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $stats['subjects'] ?></div>
                <div class="stat-card__label">Active Subjects</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:var(--green);"><i class="bi bi-calendar-range-fill"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $stats['sessions'] ?></div>
                <div class="stat-card__label">Academic Sessions</div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:var(--gold);"><i class="bi bi-layers-fill"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $stats['terms'] ?></div>
                <div class="stat-card__label">Terms</div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:#1f6f8b;"><i class="bi bi-clipboard-check-fill"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $stats['enrolled'] ?></div>
                <div class="stat-card__label">Enrolled This Session</div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:#3a3f58;"><i class="bi bi-person-check-fill"></i></span>
            <div>
                <div class="stat-card__value"><?= count($sessions) ?></div>
                <div class="stat-card__label">Sessions On Record</div>
            </div>
        </div>
    </div>
</div>

<!-- Phase 4: the result review queue and the state of this term's marking. -->
<div class="row g-3 mb-3">
    <div class="col-12">
        <div class="nav-section mb-0">Results - <?= $session === null ? 'no current session' : e($session['session_name']) ?><?= $term === null ? '' : ' &middot; ' . e($term['term_name']) ?></div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:var(--green);"><i class="bi bi-pencil"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $resultCounts['draft'] ?></div>
                <div class="stat-card__label">Draft Results</div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <a class="card stat-card text-decoration-none"
               href="<?= e(assetUrl('admin/results.php?status=submitted' . ($session === null || $term === null ? '' : '&amp;session_id=' . (int) $session['id'] . '&amp;term_id=' . (int) $term['id']))) ?>">
            <span class="stat-card__icon" style="background:var(--gold);"><i class="bi bi-inbox-fill"></i></span>
            <div>
                <div class="stat-card__value"><?= $pendingReviews ?></div>
                <div class="stat-card__label">Pending Result Reviews</div>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:#1f6f8b;"><i class="bi bi-check-circle-fill"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $resultCounts['approved'] ?></div>
                <div class="stat-card__label">Approved Results</div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:#3a3f58;"><i class="bi bi-lock-fill"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $resultCounts['locked'] ?></div>
                <div class="stat-card__label">Locked Results</div>
            </div>
        </div>
    </div>
</div>

<?php if ($pendingReviews > 0): ?>
    <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <strong><?= $pendingReviews ?></strong> result<?= $pendingReviews === 1 ? '' : 's' ?>
            <?= $pendingReviews === 1 ? 'is' : 'are' ?> waiting for review.
        </span>
        <a class="btn btn-sm btn-green"
           href="<?= e(assetUrl('admin/results.php?status=submitted' . ($session === null || $term === null ? '' : '&session_id=' . (int) $session['id'] . '&term_id=' . (int) $term['id']))) ?>">
            <i class="bi bi-eye-fill"></i> Review Now
        </a>
    </div>
<?php endif; ?>

<!-- Phase 5: how far the report cards have got for the term in progress. -->
<div class="row g-3 mb-3">
    <div class="col-12">
        <div class="nav-section mb-0">Report Cards - <?= $session === null ? 'no current session' : e($session['session_name']) ?><?= $term === null ? '' : ' &middot; ' . e($term['term_name']) ?></div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:var(--green);"><i class="bi bi-files"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $reportCounts['total'] ?></div>
                <div class="stat-card__label">Cards Generated</div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <a class="card stat-card text-decoration-none"
               href="<?= e(assetUrl('admin/report-cards.php?status=generated' . ($session === null || $term === null ? '' : '&amp;session_id=' . (int) $session['id'] . '&amp;term_id=' . (int) $term['id']))) ?>">
            <span class="stat-card__icon" style="background:var(--gold);"><i class="bi bi-hourglass-split"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $reportCounts['generated'] ?></div>
                <div class="stat-card__label">Awaiting Approval</div>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:#1f6f8b;"><i class="bi bi-check-circle-fill"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $reportCounts['approved'] ?></div>
                <div class="stat-card__label">Approved Cards</div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-3">
        <div class="card stat-card">
            <span class="stat-card__icon" style="background:#3a3f58;"><i class="bi bi-lock-fill"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $reportCounts['locked'] ?></div>
                <div class="stat-card__label">Locked Cards</div>
            </div>
        </div>
    </div>
</div>

<?php if ($cardsStillToDo > 0 && $session !== null && $term !== null): ?>
    <div class="alert alert-info d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-info-circle-fill"></i>
            <strong><?= $cardsStillToDo ?></strong> of <?= (int) $enrolledForTerm ?> enrolled
            student<?= $enrolledForTerm === 1 ? '' : 's' ?> <?= $cardsStillToDo === 1 ? 'has' : 'have' ?>
            no report card for this term yet.
        </span>
        <a class="btn btn-sm btn-green"
           href="<?= e(assetUrl('admin/report-card-generate.php?session_id=' . (int) $session['id'] . '&amp;term_id=' . (int) $term['id'])) ?>">
            <i class="bi bi-magic"></i> Generate Cards
        </a>
    </div>
<?php endif; ?>

<div class="card table-card mb-3">
    <div class="table-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-file-earmark-text-fill text-green"></i> Report Cards At A Glance
            <small>every card on the portal, all sessions</small>
        </h2>
        <a class="btn btn-sm btn-green" href="<?= e(assetUrl('admin/report-cards.php')) ?>">
            <i class="bi bi-arrow-right-circle"></i> Manage Report Cards
        </a>
    </div>

    <div class="table-card__body">
        <div class="row g-3">
            <?php foreach (reportCardStatuses() as $statusOption): ?>
                <div class="col-6 col-md-3">
                    <a class="d-block text-decoration-none"
                       href="<?= e(assetUrl('admin/report-cards.php?status=' . $statusOption)) ?>">
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
            A card is only generated once every subject result for that term is
            approved or locked. Its average, position and subject counts are worked
            out at that moment and refreshed only by regenerating it.
        </p>
    </div>
</div>

<!-- Phase 6: what the public result checker can currently reach. -->
<div class="row g-3 mb-3">
    <div class="col-12">
        <div class="nav-section mb-0">Public Result Checker</div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <a class="card stat-card text-decoration-none"
           href="<?= e(assetUrl('admin/result-publication.php?status=published')) ?>">
            <span class="stat-card__icon" style="background:var(--green);"><i class="bi bi-megaphone-fill"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $publicationCounts['published'] ?></div>
                <div class="stat-card__label">Published Results</div>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-3">
        <a class="card stat-card text-decoration-none"
           href="<?= e(assetUrl('admin/result-pins.php?status=active')) ?>">
            <span class="stat-card__icon" style="background:var(--gold);"><i class="bi bi-key-fill"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $pinCounts['active'] ?></div>
                <div class="stat-card__label">Active Result PINs</div>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-3">
        <a class="card stat-card text-decoration-none"
           href="<?= e(assetUrl('admin/result-pins.php?status=blocked')) ?>">
            <span class="stat-card__icon" style="background:#b02a37;"><i class="bi bi-slash-circle"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $pinCounts['blocked'] ?></div>
                <div class="stat-card__label">Blocked PINs</div>
            </div>
        </a>
    </div>

    <div class="col-6 col-lg-3">
        <a class="card stat-card text-decoration-none"
           href="<?= e(assetUrl('admin/result-pins.php?status=expired')) ?>">
            <span class="stat-card__icon" style="background:#5b6b60;"><i class="bi bi-hourglass-bottom"></i></span>
            <div>
                <div class="stat-card__value"><?= (int) $pinCounts['expired'] ?></div>
                <div class="stat-card__label">Expired PINs</div>
            </div>
        </a>
    </div>
</div>

<div class="card table-card mb-3">
    <div class="table-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-patch-check-fill text-green"></i> Public Result Access
            <small>every attempt at the public result checker</small>
        </h2>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('result-checker.php')) ?>" target="_blank" rel="noopener">
            <i class="bi bi-box-arrow-up-right"></i> Open Checker
        </a>
    </div>

    <div class="table-card__body">
        <div class="row g-3">
            <?php foreach ([
                ['label' => 'Result Checks',  'value' => $accessCounts['total'],   'icon' => 'bi-activity'],
                ['label' => 'Opened Results', 'value' => $accessCounts['success'], 'icon' => 'bi-check-circle'],
                ['label' => 'Refused',        'value' => $accessCounts['failed'],  'icon' => 'bi-x-circle'],
                ['label' => 'PINs Issued',    'value' => $pinCounts['total'],     'icon' => 'bi-key'],
            ] as $tile): ?>
                <div class="col-6 col-md-3">
                    <div class="d-flex align-items-center gap-2 py-2">
                        <i class="bi <?= e($tile['icon']) ?> text-green fs-5"></i>
                        <div>
                            <div class="fw-bold fs-5"><?= (int) $tile['value'] ?></div>
                            <div class="stat-card__label"><?= e($tile['label']) ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($accessCounts['reasons'] !== []): ?>
            <p class="mt-3 mb-0 small text-muted">
                <i class="bi bi-info-circle me-1"></i>
                Refused attempts in the last 30 days:
                <?php foreach ($accessCounts['reasons'] as $index => $reason): ?>
                    <?= $index === 0 ? '' : ', ' ?>
                    <span class="mono"><?= e((string) $reason['failure_reason']) ?></span>
                    (<?= (int) $reason['total'] ?>)
                <?php endforeach; ?>.
                A run of <span class="mono">invalid_details</span> from one address is
                usually a family who cannot read the PIN, not an attack.
            </p>
        <?php endif; ?>
    </div>
</div>

<?php if ($publicationCounts['published'] === 0 && $reportAll['approved'] + $reportAll['locked'] > 0): ?>
    <div class="alert alert-info d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-info-circle-fill"></i>
            <?= (int) ($reportAll['approved'] + $reportAll['locked']) ?>
            card<?= ($reportAll['approved'] + $reportAll['locked']) === 1 ? ' is' : 's are' ?>
            approved but nothing is published, so no parent can open a result yet.
        </span>
        <a class="btn btn-sm btn-green" href="<?= e(assetUrl('admin/result-publication.php')) ?>">
            <i class="bi bi-megaphone-fill"></i> Publish Results
        </a>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card table-card">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-calendar-check-fill text-green"></i> Current Session And Term
                </h2>
                <a class="btn btn-sm btn-outline-secondary"
                   href="<?= e(assetUrl('admin/terms.php' . ($session === null ? '' : '?session_id=' . (int) $session['id']))) ?>">
                    Manage Terms
                </a>
            </div>

            <div class="table-card__body">
                <?php if ($session === null): ?>
                    <div class="alert alert-warning d-flex align-items-center gap-2 mb-0">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <span>
                            No current academic session. Create one and mark it current before enrolling students.
                        </span>
                    </div>
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
                                            <span class="text-muted">Not set</span>
                                            <a class="ms-2" href="<?= e(assetUrl('admin/terms.php?session_id=' . (int) $session['id'])) ?>">
                                                Choose a term
                                            </a>
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
                                    <th scope="row">Other Sessions</th>
                                    <td>
                                        <?php $others = array_values(array_filter($sessions, static function (array $row) use ($session): bool {
                                            return (int) $row['id'] !== (int) $session['id'];
                                        })); ?>
                                        <?php if ($others === []): ?>
                                            <span class="text-muted">None</span>
                                        <?php else: ?>
                                            <?php foreach ($others as $row): ?>
                                                <a class="badge text-decoration-none <?= e(statusBadgeClass((string) $row['status'])) ?> me-1"
                                                   href="<?= e(assetUrl('admin/terms.php?session_id=' . (int) $row['id'])) ?>">
                                                    <?= e($row['session_name']) ?>
                                                </a>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card table-card">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-check2-square text-green"></i> Phase 3 Setup
                </h2>
            </div>

            <div class="table-card__body">
                <?php foreach ($setupSteps as $step): ?>
                    <div class="d-flex gap-2 align-items-start py-2 border-bottom">
                        <i class="bi bi-<?= $step['done'] ? 'check-circle-fill text-green' : 'circle text-muted' ?> fs-5"></i>
                        <div>
                            <a class="cell-strong d-block" href="<?= e(assetUrl($step['url'])) ?>"><?= e($step['label']) ?></a>
                            <small class="text-muted"><?= e($step['note']) ?></small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div class="card table-card mt-3 mb-4">
    <div class="table-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-clipboard-data-fill text-green"></i> Results At A Glance
            <small>every result on the portal, all sessions</small>
        </h2>
        <a class="btn btn-sm btn-green" href="<?= e(assetUrl('admin/results.php')) ?>">
            <i class="bi bi-arrow-right-circle"></i> Manage Results
        </a>
    </div>

    <div class="table-card__body">
        <div class="row g-3">
            <?php foreach (resultStatuses() as $statusOption): ?>
                <div class="col-6 col-md-3">
                    <a class="d-block text-decoration-none"
                       href="<?= e(assetUrl('admin/results.php?status=' . $statusOption)) ?>">
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
            Totals, averages, positions and pass or fail summaries are worked out
            from approved and locked results every time a page loads. Nothing in
            this group is stored, so a returned or edited result can never leave a
            stale number behind.
        </p>
    </div>
</div>

<div class="row g-3 mt-0">
    <div class="col-lg-7">
        <div class="card table-card">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-door-open-fill text-green"></i> Enrolment Per Class
                    <small><?= $session === null ? 'no current session' : e($session['session_name']) ?></small>
                </h2>
                <a class="btn btn-sm btn-outline-secondary"
                   href="<?= e(assetUrl('admin/student-enrollments.php' . ($session === null ? '' : '?session_id=' . (int) $session['id']))) ?>">
                    View All
                </a>
            </div>

            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th>Class</th>
                        <th>Section</th>
                        <th class="text-end">Enrolled</th>
                        <th class="text-end">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($classes === []): ?>
                        <?= emptyRow(4, 'No class has been created yet.') ?>
                    <?php endif; ?>

                    <?php foreach ($classes as $row): ?>
                        <?php $classId = (int) $row['id']; ?>
                        <tr>
                            <td class="cell-strong"><?= e($row['class_name']) ?></td>
                            <td><?= sectionBadge((string) $row['section']) ?></td>
                            <td class="text-end">
                                <span class="cell-strong"><?= (int) ($enrollments[$classId] ?? 0) ?></span>
                                <span class="text-muted">/ <?= (int) $row['capacity'] ?></span>
                            </td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="<?= e(assetUrl('admin/class-view.php?id=' . $classId . ($session === null ? '' : '&session_id=' . (int) $session['id']))) ?>">
                                    <i class="bi bi-eye"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card table-card">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-pie-chart-fill text-green"></i> Students By Section
                </h2>
            </div>

            <div class="table-card__body">
                <?php $sectionTotal = 0; ?>
                <?php foreach ($stats['by_section'] as $section => $total): ?>
                    <?php $sectionTotal += (int) $total; ?>
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <?= sectionBadge((string) $section) ?>
                        <span class="cell-strong"><?= (int) $total ?></span>
                    </div>
                <?php endforeach; ?>

                <div class="d-flex justify-content-between align-items-center py-2">
                    <span class="fw-semibold">Total</span>
                    <span class="cell-strong"><?= (int) $sectionTotal ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/admin_bottom.php'; ?>
