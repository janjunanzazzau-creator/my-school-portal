<?php

declare(strict_types=1);

/**
 * One report card, the way the administrator needs to judge it.
 *
 * Everything on the card is shown on screen in the same arrangement it prints in:
 * the student's identity, every subject with its component scores and grade, the
 * attendance summary, the psychomotor traits, the effective areas, the previous
 * term and previous session standing, the comments and the promotion status.
 *
 * Three things can be done from here, each of them re-checked on the server:
 * save the comments, move the card through the workflow, and regenerate it when
 * a result changed. Nothing is saved by a hidden field or a query string; every
 * decision goes through the functions in config/functions.php.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

$reportId = queryInt('id');
$report   = $reportId === null ? null : findReportCard($reportId);

if ($report === null) {
    setFlash('error', 'That report card could not be found. It may have been removed.');
    redirect('admin/report-cards.php');
}

$status = (string) $report['status'];

/* --- Actions ---------------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_FIELD_NAME] ?? null;

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/report-card-view.php?id=' . $reportId);
    }

    $action    = field('action', 20);
    $rawReason = isset($_POST['reason']) && is_string($_POST['reason']) ? $_POST['reason'] : '';
    $reason    = trim(preg_replace('/\s+/u', ' ', $rawReason) ?? $rawReason);

    $outcome = ['ok' => false, 'message' => 'That action is not available.'];

    if ($action === 'comments') {
        $outcome = saveReportCardComments($reportId, [
            'teacher_comment'   => $_POST['teacher_comment'] ?? '',
            'principal_comment' => $_POST['principal_comment'] ?? '',
            'promotion_status'  => $_POST['promotion_status'] ?? '',
        ]);
    } elseif (in_array($action, ['approve', 'lock', 'unlock'], true)) {
        $outcome = applyReportCardAction($reportId, $action, $reason);
    } elseif ($action === 'regenerate') {
        $outcome = generateReportCard(
            (int) $report['student_id'],
            (int) $report['class_id'],
            (int) $report['session_id'],
            (int) $report['term_id']
        );
    }

    setFlash($outcome['ok'] ? 'success' : 'error', $outcome['message']);

    redirect('admin/report-card-view.php?id=' . $reportId);
}

/* --- The card --------------------------------------------------------------- */

/* Re-read after nothing has changed on this request, so the rest of the page
   always works from the same row. */
$report      = findReportCard($reportId);
$studentId   = (int) $report['student_id'];
$classId     = (int) $report['class_id'];
$sessionId   = (int) $report['session_id'];
$termId      = (int) $report['term_id'];
$isLocked    = $status === 'locked';

$subjectRows = reportCardSubjectRows($reportId);
$attendance  = reportCardAttendance($studentId, $classId, $sessionId, $termId);
$psychomotor = reportCardPsychomotor($studentId, $classId, $sessionId, $termId);
$effective   = reportCardEffectiveAreas($studentId, $classId, $sessionId, $termId);
$history     = reportCardHistory($reportId);
$previous    = previousPerformanceFor($reportId);

$structure = assessmentStructure((string) $report['section']);
$scale     = ratingScale();

/* The promotion status currently stored, defaulting the form to the safe value. */
$promotion = trim((string) ($report['promotion_status'] ?? ''));

if ($promotion === '') {
    $promotion = 'Pending';
}

/* Which buttons the state machine allows from here. Mirrored on the server in
   applyReportCardAction() and generateReportCard(); this only controls what is
   offered, so a stale page cannot force an illegal transition. */
$canApprove = $status === 'generated';
$canLock    = $status === 'approved';
$canUnlock  = $status === 'locked';
$canComment = !in_array($status, ['locked'], true);
/* Regenerating is the same act as generating, so a locked card is off limits. */
$canRegenerate = !in_array($status, ['locked'], true);

$pageTitle    = 'Report Card';
$pageHeading  = 'Report Card';
$pageSubtitle = (string) $report['report_number'] . ' - '
    . studentFullName($report) . ' - ' . (string) $report['term_name'];
$activeMenu   = 'reportcards';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn-sm btn-gold" target="_blank" rel="noopener"
           href="<?= e(assetUrl('admin/print-report-card.php?id=' . $reportId)) ?>">
            <i class="bi bi-printer-fill"></i> Print
        </a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('admin/report-cards.php')) ?>">
            <i class="bi bi-arrow-left"></i> Back to Report Cards
        </a>
    </div>
</div>

<?php if ($isLocked): ?>
    <div class="alert alert-success d-flex align-items-start gap-2">
        <i class="bi bi-lock-fill mt-1"></i>
        <div>
            <strong>This report card is locked.</strong>
            Its scores, attendance, assessments and comments are now fixed. Unlock it
            first, giving a reason, if anything has to change.
        </div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <!-- Subject results -->
        <div class="card table-card">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-clipboard-data-fill text-green"></i> Subject Results
                    <small><?= e($report['session_name']) ?> &middot; <?= e($report['term_name']) ?></small>
                </h2>
                <span class="badge <?= e(reportCardBadgeClass($status)) ?> fs-6">
                    <?= e(ucfirst($status)) ?>
                </span>
            </div>

            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                    <tr>
                        <th>Subject</th>
                        <?php foreach ($structure['components'] as $component): ?>
                            <th class="text-center">
                                <?= e($component['label']) ?>
                                <br><small class="text-muted fw-normal">
                                    /<?= e(rtrim(rtrim(number_format($component['max'], 2, '.', ''), '0'), '.')) ?>
                                </small>
                            </th>
                        <?php endforeach; ?>
                        <th class="text-center">
                            Total
                            <br><small class="text-muted fw-normal">
                                /<?= e(rtrim(rtrim(number_format((float) $structure['max_total'], 2, '.', ''), '0'), '.')) ?>
                            </small>
                        </th>
                        <th class="text-center">Grade</th>
                        <th class="text-center">Remark</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($subjectRows === []): ?>
                        <?= emptyRow(5 + count($structure['components']), 'This class has no subjects set up yet.') ?>
                    <?php endif; ?>

                    <?php foreach ($subjectRows as $row): ?>
                        <?php
                        /* A result that exists but is not approved or locked is
                           printed greyed, so an unfinished sheet is obvious rather
                           than silently missing. */
                        $pendingHere = $row['counted'] === false;
                        ?>
                        <tr class="<?= $pendingHere ? 'table-muted' : '' ?>">
                            <td>
                                <span class="mono small text-muted"><?= e($row['subject_code']) ?></span>
                                <span class="cell-strong"><?= e($row['subject_name']) ?></span>
                                <?php if ($pendingHere): ?>
                                    <br><small class="text-danger">
                                        <i class="bi bi-hourglass-split"></i>
                                        <?= $row['status'] === null ? 'No result entered' : e(ucfirst((string) $row['status'])) ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <?php foreach (resultAssessmentCodes() as $code): ?>
                                <td class="text-center mono">
                                    <?= $row['scores'][$code] === null
                                        ? '<span class="text-muted">&ndash;</span>'
                                        : e(rtrim(rtrim(number_format((float) $row['scores'][$code], 2, '.', ''), '0'), '.')) ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="text-center mono cell-strong">
                                <?= $row['total'] === null
                                    ? '<span class="text-muted">&ndash;</span>'
                                    : e(rtrim(rtrim(number_format((float) $row['total'], 2, '.', ''), '0'), '.')) ?>
                            </td>
                            <td class="text-center">
                                <?php if ($row['grade'] === null): ?>
                                    <span class="text-muted">&ndash;</span>
                                <?php else: ?>
                                    <span class="badge <?= (string) $row['grade'] === 'F' ? 'bg-danger' : 'bg-success' ?>">
                                        <?= e((string) $row['grade']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center small">
                                <?= $row['grade_remark'] === null || $row['grade_remark'] === ''
                                    ? '<span class="text-muted">&ndash;</span>'
                                    : e((string) $row['grade_remark']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if ($subjectRows !== []): ?>
                        <tfoot>
                        <tr>
                            <th colspan="<?= count(resultAssessmentCodes()) + 1 ?>">
                                Total
                            </th>
                            <th class="text-center mono fs-6">
                                <?= e(rtrim(rtrim(number_format((float) $report['total_score'], 2, '.', ''), '0'), '.')) ?>
                            </th>
                            <th colspan="2"></th>
                        </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- Attendance -->
        <div class="card table-card mt-3">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-calendar-check-fill text-green"></i> Attendance
                </h2>
                <a class="btn btn-sm btn-outline-secondary"
                   href="<?= e(assetUrl('admin/attendance.php?session_id=' . $sessionId . '&amp;term_id=' . $termId . '&amp;class_id=' . $classId)) ?>">
                    <i class="bi bi-pencil-square"></i> Edit
                </a>
            </div>
            <div class="table-card__body">
                <?php if ($attendance === null): ?>
                    <p class="text-muted mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        No attendance has been entered for this term yet. The card can still
                        be generated; attendance simply prints as not recorded.
                    </p>
                <?php else: ?>
                    <div class="row g-3 text-center">
                        <div class="col-4">
                            <div class="fs-4 mono"><?= (int) $attendance['days_present'] ?></div>
                            <div class="text-muted small">Days Present</div>
                        </div>
                        <div class="col-4">
                            <div class="fs-4 mono text-danger"><?= (int) $attendance['days_absent'] ?></div>
                            <div class="text-muted small">Days Absent</div>
                        </div>
                        <div class="col-4">
                            <div class="fs-4 mono"><?= (int) $attendance['school_days'] ?></div>
                            <div class="text-muted small">School Days</div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Psychomotor and effective areas -->
        <div class="row g-3 mt-0">
            <div class="col-md-6">
                <div class="card table-card h-100">
                    <div class="table-card__head">
                        <h2 class="table-card__title">
                            <i class="bi bi-person-arms-up text-green"></i> Psychomotor
                        </h2>
                        <a class="btn btn-sm btn-outline-secondary"
                           href="<?= e(assetUrl('admin/assessments.php?student_id=' . $studentId . '&amp;session_id=' . $sessionId . '&amp;term_id=' . $termId)) ?>">
                            <i class="bi bi-pencil-square"></i> Edit
                        </a>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <tbody>
                            <?php foreach (psychomotorTraits() as $key => $label): ?>
                                <tr>
                                    <td><?= e($label) ?></td>
                                    <td class="text-end">
                                        <?php if ($psychomotor === null || $psychomotor[$key] === null): ?>
                                            <span class="text-muted">&ndash;</span>
                                        <?php else: ?>
                                            <span class="badge bg-gold text-dark">
                                                <?= e($scale[(int) $psychomotor[$key]] ?? (string) $psychomotor[$key]) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card table-card h-100">
                    <div class="table-card__head">
                        <h2 class="table-card__title">
                            <i class="bi bi-stars text-green"></i> Effective Areas
                        </h2>
                        <a class="btn btn-sm btn-outline-secondary"
                           href="<?= e(assetUrl('admin/assessments.php?student_id=' . $studentId . '&amp;session_id=' . $sessionId . '&amp;term_id=' . $termId)) ?>">
                            <i class="bi bi-pencil-square"></i> Edit
                        </a>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <tbody>
                            <?php foreach (effectiveAreaFields() as $key => $label): ?>
                                <tr>
                                    <td><?= e($label) ?></td>
                                    <td class="text-end">
                                        <?php if ($effective === null || $effective[$key] === null): ?>
                                            <span class="text-muted">&ndash;</span>
                                        <?php else: ?>
                                            <span class="badge bg-gold text-dark">
                                                <?= e($scale[(int) $effective[$key]] ?? (string) $effective[$key]) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Comments -->
        <div class="card table-card mt-3">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-chat-left-text-fill text-green"></i> Comments And Promotion
                </h2>
            </div>

            <div class="table-card__body">
                <?php if (!$canComment): ?>
                    <div class="alert alert-secondary mb-0">
                        <i class="bi bi-lock"></i>
                        This card is locked, so its comments and promotion status cannot be
                        changed. Unlock it first if something must be corrected.
                    </div>
                <?php else: ?>
                    <form method="post" autocomplete="off">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="comments">

                        <div class="mb-3">
                            <label class="form-label" for="teacher_comment">Class teacher's comment</label>
                            <textarea class="form-control" id="teacher_comment" name="teacher_comment"
                                      rows="2" maxlength="500"
                                      placeholder="What this student did well, and what to work on."><?= e((string) ($report['teacher_comment'] ?? '')) ?></textarea>
                            <div class="form-text">Up to 500 characters.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="principal_comment">Principal's comment</label>
                            <textarea class="form-control" id="principal_comment" name="principal_comment"
                                      rows="2" maxlength="500"
                                      placeholder="The school's comment for the parents."><?= e((string) ($report['principal_comment'] ?? '')) ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="promotion_status">Promotion status</label>
                            <select class="form-select" id="promotion_status" name="promotion_status">
                                <?php foreach (promotionStatuses() as $option): ?>
                                    <option value="<?= e($option) ?>"<?= isSelected($promotion, $option) ?>><?= e($option) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button class="btn btn-green" type="submit">
                            <i class="bi bi-save-fill"></i> Save Comments
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <!-- Identity -->
        <div class="card table-card">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-person-vcard text-green"></i> Student
                </h2>
            </div>
            <div class="table-card__body">
                <a class="btn btn-sm btn-outline-secondary w-100 mb-3"
                   href="<?= e(assetUrl('admin/student-view.php?id=' . $studentId)) ?>">
                    <i class="bi bi-box-arrow-up-right"></i> Open full student record
                </a>

                <?= detailRow('Report number', '<span class="mono">' . e($report['report_number']) . '</span>') ?>
                <?= detailRow('Name', e(studentFullName($report))) ?>
                <?= detailRow('Admission number', '<span class="mono">' . e($report['admission_number']) . '</span>') ?>
                <?= detailRow('Class', e($report['class_name']) . ' ' . sectionBadge((string) $report['section'])) ?>
                <?= detailRow('Academic session', e($report['session_name'])) ?>
                <?= detailRow('Term', e($report['term_name'])) ?>
                <?= detailRow('Promotion status', '<span class="badge bg-gold text-dark">' . e($promotion) . '</span>') ?>
            </div>
        </div>

        <!-- Standing -->
        <div class="card table-card mt-3">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-trophy-fill text-green"></i> Standing
                </h2>
            </div>
            <div class="table-card__body">
                <?= detailRow('Overall total', '<strong class="mono">' . e(rtrim(rtrim(number_format((float) $report['total_score'], 2, '.', ''), '0'), '.')) . '</strong>') ?>
                <?= detailRow('Average', '<strong class="mono">' . e(rtrim(rtrim(number_format((float) $report['average_score'], 2, '.', ''), '0'), '.')) . '</strong>') ?>
                <?= detailRow(
                    'Position in class',
                    $report['position'] === null
                        ? '<span class="text-muted">Not available</span>'
                        : '<strong class="badge bg-gold text-dark">' . e(ordinalSuffix((int) $report['position'])) . '</strong>'
                          . ' <span class="text-muted">of ' . (int) $report['total_students'] . '</span>'
                ) ?>
                <?= detailRow('Subjects offered', (string) (int) $report['subjects_offered']) ?>
                <?= detailRow('Subjects passed', '<span class="text-success">' . (int) $report['subjects_passed'] . '</span>') ?>
                <?= detailRow('Subjects failed', '<span class="text-danger">' . (int) $report['subjects_failed'] . '</span>') ?>
                <?= detailRow('Generated', '<span class="mono small">' . e((string) ($report['generated_at'] ?? 'Not yet')) . '</span>') ?>
            </div>
        </div>

        <!-- Previous performance -->
        <div class="card table-card mt-3">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-graph-up-arrow text-green"></i> Previous Standing
                </h2>
            </div>
            <div class="table-card__body">
                <?php foreach ([
                    'Previous term'    => $previous['previous_term'],
                    'Previous session' => $previous['previous_session'],
                ] as $label => $entry): ?>
                    <p class="mb-2 fw-semibold small text-muted"><?= e($label) ?></p>
                    <?php if ($entry === null): ?>
                        <p class="text-muted small mb-3">
                            <i class="bi bi-dash-circle"></i> No approved results to compare with.
                        </p>
                    <?php else: ?>
                        <div class="mb-3">
                            <?= detailRow('Average', '<span class="mono">' . e(rtrim(rtrim(number_format((float) $entry['average'], 2, '.', ''), '0'), '.')) . '</span>') ?>
                            <?= detailRow('Position', $entry['position'] === null
                                ? '<span class="text-muted">Not available</span>'
                                : e(ordinalSuffix((int) $entry['position'])) . ' <span class="text-muted">of ' . (int) $entry['total_students'] . '</span>') ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Workflow -->
        <div class="card table-card mt-3">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-diagram-3-fill text-green"></i> Workflow
                </h2>
            </div>

            <div class="table-card__body">
                <?php if (!$canApprove && !$canLock && !$canUnlock && !$canRegenerate): ?>
                    <div class="alert alert-secondary mb-0">
                        <i class="bi bi-hourglass-split"></i>
                        This card is locked. There is nothing left to decide.
                    </div>
                <?php else: ?>
                    <form method="post" autocomplete="off">
                        <?= csrfField() ?>
                        <input type="hidden" name="report_id" value="<?= (int) $reportId ?>">

                        <div class="mb-3">
                            <label class="form-label" for="reason">
                                Reason
                                <span class="text-muted small fw-normal">
                                    (required to unlock, optional otherwise)
                                </span>
                            </label>
                            <textarea class="form-control" id="reason" name="reason" rows="2" maxlength="255"
                                      placeholder="Recorded in the report card history."><?= e((string) ($_POST['reason'] ?? '')) ?></textarea>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <?php if ($canApprove): ?>
                                <button class="btn btn-green" type="submit" name="action" value="approve"
                                        data-confirm="Approve this report card?">
                                    <i class="bi bi-check-circle-fill"></i> Approve
                                </button>
                            <?php endif; ?>

                            <?php if ($canLock): ?>
                                <button class="btn btn-gold" type="submit" name="action" value="lock"
                                        data-confirm="Lock this report card? Nothing on it, including its comments and attendance, can be changed afterwards.">
                                    <i class="bi bi-lock-fill"></i> Lock
                                </button>
                            <?php endif; ?>

                            <?php if ($canUnlock): ?>
                                <button class="btn btn-outline-warning" type="submit" name="action" value="unlock"
                                        data-requires-reason="Unlocking a report card returns it to approved and records your reason. Please give one."
                                        data-confirm="Unlock this report card?">
                                    <i class="bi bi-unlock-fill"></i> Unlock
                                </button>
                            <?php endif; ?>

                            <?php if ($canRegenerate): ?>
                                <button class="btn btn-outline-secondary" type="submit" name="action" value="regenerate"
                                        data-confirm="Regenerate this report card? Its figures will be recalculated and it will return to the generated state.">
                                    <i class="bi bi-arrow-clockwise"></i> Regenerate
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- History -->
        <div class="card table-card mt-3">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-clock-history text-green"></i> Report Card History
                    <small><?= count($history) ?> entr<?= count($history) === 1 ? 'y' : 'ies' ?></small>
                </h2>
            </div>

            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                    <tr>
                        <th>When</th>
                        <th>Action</th>
                        <th>By</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($history === []): ?>
                        <?= emptyRow(3, 'Nothing recorded yet.') ?>
                    <?php endif; ?>

                    <?php foreach ($history as $entry): ?>
                        <tr>
                            <td class="mono small"><?= e((string) $entry['created_at']) ?></td>
                            <td>
                                <span class="badge bg-light text-dark border"><?= e(ucfirst((string) $entry['action'])) ?></span>
                                <?php if (!empty($entry['reason'])): ?>
                                    <br><small class="text-muted"><?= e((string) $entry['reason']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <?= $entry['full_name'] === null || $entry['full_name'] === ''
                                    ? '<span class="text-muted">System</span>'
                                    : e((string) $entry['full_name']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="mt-3 mb-4">
    <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/report-cards.php')) ?>">
        <i class="bi bi-arrow-left"></i> Back to Report Cards
    </a>
</div>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
