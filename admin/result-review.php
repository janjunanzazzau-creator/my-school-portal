<?php

declare(strict_types=1);

/**
 * Result review.
 *
 * One result, shown the way the administrator needs to judge it: the four
 * component scores, the calculated total and grade, the grade band table with the
 * result highlighted, the teacher's remark, the derived position, and the full
 * history of who did what and when.
 *
 * The screen is read only. Approving, returning, locking and unlocking are POSTs
 * to admin/result-action.php, so the state machine is enforced in one function
 * and every transition is written to result_history.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

$resultId = queryInt('id');
$result   = $resultId === null ? null : findResult($resultId);

if ($result === null) {
    setFlash('error', 'That result could not be found. It may have been removed.');
    redirect('admin/results.php');
}

/* Assessment structure for this result's class section, so the review screen
   always matches what the teacher actually typed into. */
$section   = (string) $result['section'];
$structure = assessmentStructure($section);
$maxTotal  = (float) $structure['max_total'];
$status    = (string) $result['status'];
$grade     = $result['grade'] === null ? null : (string) $result['grade'];

/* Position and the rest of the student's standing come from the counted results
   only, so an unfinished sheet cannot shift anybody. */
$summary   = studentResultSummary((int) $result['student_id'], (int) $result['session_id'], (int) $result['term_id']);
$position  = studentPosition(
    (int) $result['student_id'],
    (int) $result['class_id'],
    (int) $result['session_id'],
    (int) $result['term_id']
);
$history   = resultHistory((int) $result['id']);

/* Which buttons the state machine allows from here. Mirrored on the server in
   applyResultAction(); this only controls what is offered. */
$canApprove = $status === 'submitted';
$canReturn  = in_array($status, ['submitted', 'approved'], true);
$canLock    = $status === 'approved';
$canUnlock  = $status === 'locked';

$returnQuery = currentQueryString() !== '' ? '?' . currentQueryString() : '';

$pageTitle    = 'Result Review';
$pageHeading  = 'Result Review';
$pageSubtitle = (string) $result['admission_number'] . ' - ' . (string) $result['subject_name']
    . ' - ' . (string) $result['term_name'];
$activeMenu   = 'results';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn-sm btn-outline-secondary"
           href="<?= e(assetUrl('admin/results.php' . $returnQuery)) ?>">
            <i class="bi bi-arrow-left"></i> Back to Results
        </a>
    </div>
</div>

<?php if (!empty($result['admin_feedback'])): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2">
        <i class="bi bi-chat-left-text-fill mt-1"></i>
        <div>
            <strong>Administrator feedback on this result</strong>
            <div><?= e($result['admin_feedback']) ?></div>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <!-- Score breakdown -->
        <div class="card table-card">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-clipboard-data-fill text-green"></i> Score Breakdown
                    <small><?= e($result['session_name']) ?> &middot; <?= e($result['term_name']) ?></small>
                </h2>
                <span class="badge <?= e(resultStatusBadge($status)) ?> fs-6">
                    <?= e(resultStatusLabel($status)) ?>
                </span>
            </div>

            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                    <tr>
                        <?php foreach ($structure['components'] as $component): ?>
                            <th class="text-center">
                                <?= e($component['label']) ?>
                                <br><small class="text-muted fw-normal">
                                    out of <?= e(rtrim(rtrim(number_format($component['max'], 2, '.', ''), '0'), '.')) ?>
                                </small>
                            </th>
                        <?php endforeach; ?>
                        <th class="text-center">
                            Total
                            <br><small class="text-muted fw-normal">
                                out of <?= e(rtrim(rtrim(number_format($maxTotal, 2, '.', ''), '0'), '.')) ?>
                            </small>
                        </th>
                        <th class="text-center">Grade</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <?php foreach (resultAssessmentCodes() as $code): ?>
                            <td class="text-center mono fs-6">
                                <?= $result[$code] === null
                                    ? '<span class="text-muted">&ndash;</span>'
                                    : e(rtrim(rtrim(number_format((float) $result[$code], 2, '.', ''), '0'), '.')) ?>
                            </td>
                        <?php endforeach; ?>
                        <td class="text-center mono fs-6 cell-strong">
                            <?= $result['total'] === null
                                ? '<span class="text-muted">&ndash;</span>'
                                : e(rtrim(rtrim(number_format((float) $result['total'], 2, '.', ''), '0'), '.')) ?>
                        </td>
                        <td class="text-center">
                            <?php if ($grade === null): ?>
                                <span class="text-muted">Not graded</span>
                            <?php else: ?>
                                <span class="badge fs-6 <?= $grade === 'F' ? 'bg-danger' : 'bg-success' ?>">
                                    <?= e($grade) ?>
                                </span>
                                <br><small class="text-muted"><?= e((string) $result['grade_remark']) ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <div class="table-card__foot">
                <div class="meta-row">
                    <span class="meta-row__label">Teacher's remark</span>
                    <span class="meta-row__value">
                        <?= $result['teacher_remark'] === null || $result['teacher_remark'] === ''
                            ? '<span class="text-muted">No remark was added.</span>'
                            : e($result['teacher_remark']) ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="card table-card mt-3">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-check2-square text-green"></i> Review Decision
                </h2>
            </div>

            <div class="table-card__body">
                <?php if (!$canApprove && !$canReturn && !$canLock && !$canUnlock): ?>
                    <div class="alert alert-secondary mb-0">
                        <i class="bi bi-hourglass-split"></i>
                        This result is a <strong>draft</strong>. It has not been submitted yet,
                        so there is nothing to review. The teacher can still change it.
                    </div>
                <?php else: ?>
                    <form method="post" action="<?= e(assetUrl('admin/result-action.php')) ?>" autocomplete="off">
                        <?= csrfField() ?>
                        <input type="hidden" name="result_id" value="<?= (int) $result['id'] ?>">

                        <div class="mb-3">
                            <label class="form-label" for="reason">
                                Reason
                                <span class="text-muted small fw-normal">
                                    (required to return or unlock, optional otherwise)
                                </span>
                            </label>
                            <textarea class="form-control" id="reason" name="reason" rows="2" maxlength="255"
                                      placeholder="For example: the Examination score looks too high, please check."><?= e((string) ($_POST['reason'] ?? '')) ?></textarea>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <?php if ($canApprove): ?>
                                <button class="btn btn-green" type="submit" name="action" value="approve"
                                        data-confirm="Approve this result?">
                                    <i class="bi bi-check-circle-fill"></i> Approve
                                </button>
                            <?php endif; ?>

                            <?php if ($canReturn): ?>
                                <button class="btn btn-outline-danger" type="submit" name="action" value="return"
                                        data-requires-reason="Returning a result sends it back to the teacher as a draft. Please give a reason."
                                        data-confirm="Return this result to the teacher?">
                                    <i class="bi bi-arrow-counterclockwise"></i> Return to Teacher
                                </button>
                            <?php endif; ?>

                            <?php if ($canLock): ?>
                                <button class="btn btn-gold" type="submit" name="action" value="lock"
                                        data-confirm="Lock this result? A locked result cannot be edited without being unlocked first.">
                                    <i class="bi bi-lock-fill"></i> Lock
                                </button>
                            <?php endif; ?>

                            <?php if ($canUnlock): ?>
                                <button class="btn btn-outline-warning" type="submit" name="action" value="unlock"
                                        data-requires-reason="Unlocking a result moves it back to approved and records your reason. Please give one."
                                        data-confirm="Unlock this result?">
                                    <i class="bi bi-unlock-fill"></i> Unlock
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <!-- Student and context -->
        <div class="card table-card">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-person-vcard text-green"></i> Student And Context
                </h2>
            </div>
            <div class="table-card__body">
                <a class="btn btn-sm btn-outline-secondary w-100 mb-3"
                   href="<?= e(assetUrl('admin/student-view.php?id=' . (int) $result['student_id'])) ?>">
                    <i class="bi bi-box-arrow-up-right"></i> Open full student record
                </a>

                <?= detailRow('Student', e(trim($result['first_name'] . ' ' . ($result['middle_name'] ?? '') . ' ' . $result['last_name']))) ?>
                <?= detailRow('Admission number', '<span class="mono">' . e($result['admission_number']) . '</span>') ?>
                <?= detailRow('Class', e($result['class_name']) . ' ' . sectionBadge((string) $result['section'])) ?>
                <?= detailRow('Subject', '<span class="mono">' . e($result['subject_code']) . '</span> ' . e($result['subject_name'])) ?>
                <?= detailRow('Academic session', e($result['session_name'])) ?>
                <?= detailRow('Term', e($result['term_name'])) ?>
                <?= detailRow(
                    'Teacher',
                    e(trim($result['teacher_first_name'] . ' ' . ($result['teacher_middle_name'] ?? '') . ' ' . $result['teacher_last_name']))
                ) ?>
            </div>
        </div>

        <!-- Derived standing -->
        <div class="card table-card mt-3">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-trophy-fill text-green"></i> Student Standing
                    <small>approved and locked only</small>
                </h2>
            </div>
            <div class="table-card__body">
                <?= detailRow('Subjects graded', '<strong>' . (int) $summary['subjects'] . '</strong>') ?>
                <?= detailRow('Overall total', '<strong class="mono">' . e(rtrim(rtrim(number_format((float) $summary['overall_total'], 2, '.', ''), '0'), '.')) . '</strong>') ?>
                <?= detailRow('Average', '<strong class="mono">' . e(rtrim(rtrim(number_format((float) $summary['average'], 2, '.', ''), '0'), '.')) . '</strong>') ?>
                <?= detailRow('Subjects passed', '<span class="text-success">' . (int) $summary['passed'] . '</span>') ?>
                <?= detailRow('Subjects failed', '<span class="text-danger">' . (int) $summary['failed'] . '</span>') ?>
                <?= detailRow(
                    'Position in class',
                    $position === null
                        ? '<span class="text-muted">Not available yet</span>'
                        : '<strong class="badge bg-gold text-dark">' . (int) $position['position'] . '</strong>'
                ) ?>
            </div>
        </div>

        <!-- Grade bands -->
        <div class="card table-card mt-3">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-calculator-fill text-green"></i> Grade Bands
                </h2>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                    <tr>
                        <th>Grade</th>
                        <th>Remark</th>
                        <th>Range</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (resultGradeBands() as $band): ?>
                        <tr class="<?= $band['grade'] === $grade ? 'table-active fw-semibold' : '' ?>">
                            <td>
                                <span class="badge <?= $band['grade'] === 'F' ? 'bg-danger' : 'bg-success' ?>">
                                    <?= e($band['grade']) ?>
                                </span>
                            </td>
                            <td><?= e($band['remark']) ?></td>
                            <td class="mono small">
                                <?= e(rtrim(rtrim(number_format($band['min'], 2, '.', ''), '0'), '.')) ?>
                                &ndash;
                                <?= $band['min'] === 0.0
                                    ? '0'
                                    : e(rtrim(rtrim(number_format($band['min'] - 0.01, 2, '.', ''), '0'), '.')) ?>%
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- History -->
        <div class="card table-card mt-3">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-clock-history text-green"></i> Result History
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
                            <td class="mono small"><?= e($entry['created_at']) ?></td>
                            <td>
                                <span class="badge bg-light text-dark border"><?= e(ucfirst((string) $entry['action'])) ?></span>
                                <?php if (!empty($entry['reason'])): ?>
                                    <br><small class="text-muted"><?= e($entry['reason']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <?= $entry['full_name'] === null || $entry['full_name'] === ''
                                    ? '<span class="text-muted">System</span>'
                                    : e($entry['full_name']) ?>
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
    <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/results.php')) ?>">
        <i class="bi bi-arrow-left"></i> Back to Results
    </a>
</div>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
