<?php

declare(strict_types=1);

/**
 * Enter Results.
 *
 * The teacher's result sheet for one class, one subject, one term of one
 * academic session.
 *
 * Two rules shape this page:
 *
 *  1. The class, subject and session arrive in the query string, so they are
 *     never trusted. Every request is checked against teacherResultScope(), which
 *     is rebuilt from teacher_subject_assignments, and a combination that is not
 *     an active allocation is refused before any student data is read.
 *
 *  2. The total, the grade and the remark shown while typing are produced by
 *     JavaScript for speed only. save-result.php recalculates every number with
 *     the PHP calculateGrade() and stores that, so a value edited in the browser
 *     never reaches the database.
 */

require_once __DIR__ . '/../includes/teacher_bootstrap.php';

$profile = currentTeacherProfile();

if ($profile === null) {
    setFlash('error', 'No teacher record is linked to your account. Please contact the administrator.');
    redirect('login.php');
}

$teacherId = (int) $profile['id'];
$current   = currentSession();

/* --- Which class, subject, session and term? -------------------------------- */
$requestedSessionId = queryInt('session_id');
$requestedClassId   = queryInt('class_id');
$requestedSubjectId = queryInt('subject_id');
$requestedTermId    = queryInt('term_id');

/* Default to the current session and the current term, because that is what a
   teacher almost always wants. An explicit request always wins. */
$sessionId = $requestedSessionId ?? ($current === null ? 0 : (int) $current['id']);

$allScope   = teacherResultScope($teacherId);
$scope      = teacherResultScope($teacherId, $sessionId);
$termOptions = sessionTermOptions($sessionId);

$currentTerm = currentTerm();
$termId = $requestedTermId
    ?? ($currentTerm !== null && (int) $currentTerm['session_id'] === $sessionId ? (int) $currentTerm['id'] : 0);

if ($termId === 0 && $termOptions !== []) {
    $termId = (int) $termOptions[0]['id'];
}

$classId   = $requestedClassId;
$subjectId = $requestedSubjectId;

/* --- Authorisation: the requested combination must be an active allocation --- */
$scopeError  = '';
$scopeDenied = false;
$hasSelection = false;

if ($classId !== null && $subjectId !== null) {
    $hasSelection = teacherCanManageResult($teacherId, (int) $classId, (int) $subjectId, $sessionId);

    if (!$hasSelection) {
        $scopeDenied = true;
        $scopeError  = 'You are not actively assigned to that class and subject in the selected '
            . 'academic session, so the result sheet cannot be opened.';
    }
}

if ($termId === 0 && $hasSelection) {
    $scopeError  = 'No term has been created for the selected academic session yet.';
    $hasSelection = false;
}

$termRow = null;

foreach ($termOptions as $option) {
    if ((int) $option['id'] === (int) $termId) {
        $termRow = $option;
        break;
    }
}

if ($hasSelection && $termRow === null) {
    $scopeError    = 'The selected term does not belong to the selected academic session.';
    $hasSelection = false;
}

/* --- Build the sheet -------------------------------------------------------- */
$sheet     = [];
$structure = ['components' => [], 'max_total' => 100.0];
$selectedClass   = null;
$selectedSubject = null;

/* The live calculator in the page footer needs these whether or not a sheet is
   open, so they carry a safe default rather than existing only inside the
   conditional block below. */
$maxTotal = 100.0;

foreach ($scope as $row) {
    if ((int) $row['class_id'] === (int) $classId) {
        $selectedClass = $row;
    }

    if ((int) $row['subject_id'] === (int) $subjectId) {
        $selectedSubject = $row;
    }
}

if ($hasSelection && $selectedClass !== null && $selectedSubject !== null) {
    $section    = resultClassSection((int) $classId);
    $structure  = $section === null
        ? ['components' => [], 'max_total' => 100.0]
        : assessmentStructure($section);
    $maxTotal   = (float) $structure['max_total'];
    $sheet      = resultSheet((int) $classId, (int) $subjectId, $sessionId, (int) $termId);
}

/* Progress for the status chips above the sheet. */
$draftCount = 0;
$lockedCount = 0;
$completeCount = 0;

foreach ($sheet as $row) {
    if ($row['complete']) {
        $completeCount++;
    }

    if ($row['locked']) {
        $lockedCount++;
    } else {
        $draftCount++;
    }
}

/* The confirmation text on the Submit button, so a teacher cannot submit a
   half filled sheet by accident. */
$readiness = $hasSelection
    ? resultSheetReadiness((int) $classId, (int) $subjectId, $sessionId, (int) $termId)
    : ['ready' => false, 'message' => '', 'missing' => [], 'total' => 0];

$backQuery = 'class_id=' . (int) $classId . '&subject_id=' . (int) $subjectId
    . '&session_id=' . (int) $sessionId . '&term_id=' . (int) $termId;

$pageTitle    = 'Enter Results';
$pageHeading  = 'Enter Results';
$pageSubtitle = 'Record the scores for one subject in one class, then submit them for review.';
$activeMenu   = 'enterresult';

require __DIR__ . '/../includes/teacher_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('teacher/my-results.php')) ?>">
            <i class="bi bi-clipboard-data"></i> My Results
        </a>
    </div>
</div>

<?php if ($allScope === []): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span>
            You have no active class and subject allocation, so there is no result sheet
            to open. Ask the administrator to assign you a class and subject first.
        </span>
    </div>
<?php endif; ?>

<?php if ($scopeDenied): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2">
        <i class="bi bi-shield-x"></i>
        <span><?= e($scopeError) ?></span>
    </div>
<?php elseif ($scopeError !== ''): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span><?= e($scopeError) ?></span>
    </div>
<?php endif; ?>

<?php if ($allScope !== []): ?>
    <!-- Selection form. Every option comes from the teacher's own active
         allocations, so the list cannot offer a combination that would be
         refused a moment later. -->
    <form method="get" class="filter-bar" autocomplete="off">
        <div class="filter-bar__row">
            <select class="form-select w-auto" name="session_id" aria-label="Academic session"
                    onchange="this.form.submit()">
                <?php
                $sessionLinks = [];

                foreach ($allScope as $row) {
                    $sessionLinks[(int) $row['session_id']] = (string) $row['session_name'];
                }
                ?>
                <?php foreach ($sessionLinks as $id => $name): ?>
                    <option value="<?= $id ?>"<?= isSelected((string) $sessionId, (string) $id) ?>>
                        <?= e($name) ?><?= $current !== null && (int) $current['id'] === $id ? ' (current)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select class="form-select w-auto" name="term_id" aria-label="Term">
                <option value="">Select a term</option>
                <?php foreach ($termOptions as $option): ?>
                    <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $termId, (string) $option['id']) ?>>
                        <?= e($option['term_name']) ?><?= (int) $option['is_current'] === 1 ? ' (current)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select class="form-select w-auto" name="class_id" aria-label="Class">
                <option value="">Select a class</option>
                <?php
                $classLinks = [];

                foreach ($scope as $row) {
                    $classLinks[(int) $row['class_id']] = (string) $row['class_name'] . ' (' . $row['section'] . ')';
                }
                ?>
                <?php foreach ($classLinks as $id => $name): ?>
                    <option value="<?= $id ?>"<?= isSelected((string) $classId, (string) $id) ?>>
                        <?= e($name) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select class="form-select w-auto" name="subject_id" aria-label="Subject">
                <option value="">Select a subject</option>
                <?php
                $subjectLinks = [];

                foreach ($scope as $row) {
                    $subjectLinks[(int) $row['subject_id']] = (string) $row['subject_name'] . ' (' . $row['subject_code'] . ')';
                }
                ?>
                <?php foreach ($subjectLinks as $id => $name): ?>
                    <option value="<?= $id ?>"<?= isSelected((string) $subjectId, (string) $id) ?>>
                        <?= e($name) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button class="btn btn-green" type="submit"><i class="bi bi-arrow-right-circle-fill"></i> Open Sheet</button>
            <a class="btn btn-outline-secondary" href="<?= e(assetUrl('teacher/enter-result.php')) ?>">Reset</a>
        </div>
    </form>
<?php endif; ?>

<?php if ($hasSelection && $selectedClass !== null && $selectedSubject !== null): ?>
    <?php
    $fieldBase = 'scores[' . (int) $classId . '][';
    $canEdit   = $draftCount > 0;
    ?>

    <div class="table-card">
        <div class="table-card__head">
            <h2 class="table-card__title">
                <i class="bi bi-clipboard-data-fill text-green"></i>
                <?= e($selectedClass['class_name']) ?>
                <small>
                    <?= e($selectedSubject['subject_name']) ?>
                    &middot; <?= e($termRow['term_name']) ?>
                    &middot; out of <?= e(rtrim(rtrim(number_format($maxTotal, 2, '.', ''), '0'), '.')) ?>
                </small>
            </h2>

            <div class="d-flex flex-wrap gap-2">
                <span class="badge bg-light text-dark border">
                    <?= count($sheet) ?> student<?= count($sheet) === 1 ? '' : 's' ?>
                </span>
                <span class="badge bg-success"><?= $completeCount ?> complete</span>
                <?php if ($lockedCount > 0): ?>
                    <span class="badge bg-secondary"><?= $lockedCount ?> not editable</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($sheet === []): ?>
            <div class="table-card__body">
                <div class="alert alert-info mb-0 d-flex align-items-center gap-2">
                    <i class="bi bi-info-circle-fill"></i>
                    <span>
                        No student is enrolled in <?= e($selectedClass['class_name']) ?> for this
                        academic session, so there is nothing to enter. Ask the administrator to
                        enrol the class first.
                    </span>
                </div>
            </div>
        <?php else: ?>
            <form method="post" id="resultForm" autocomplete="off">
                <?= csrfField() ?>
                <input type="hidden" name="class_id" value="<?= (int) $classId ?>">
                <input type="hidden" name="subject_id" value="<?= (int) $subjectId ?>">
                <input type="hidden" name="session_id" value="<?= (int) $sessionId ?>">
                <input type="hidden" name="term_id" value="<?= (int) $termId ?>">

                <div class="table-responsive result-sheet-wrap">
                    <table class="table table-hover table-sticky align-middle" id="resultSheet">
                        <thead>
                        <tr>
                            <th style="width:60px;">#</th>
                            <th>Student</th>
                            <th>Admission No.</th>
                            <?php foreach ($structure['components'] as $code => $component): ?>
                                <th class="text-center" style="min-width:92px;">
                                    <?= e($component['label']) ?>
                                    <br><small class="text-muted fw-normal">out of <?= e(rtrim(rtrim(number_format($component['max'], 2, '.', ''), '0'), '.')) ?></small>
                                </th>
                            <?php endforeach; ?>
                            <th class="text-center" style="min-width:80px;">Total</th>
                            <th class="text-center" style="min-width:70px;">Grade</th>
                            <th style="min-width:180px;">Remark</th>
                            <th class="text-center" style="min-width:110px;">Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($sheet as $index => $student): ?>
                            <?php
                            $rowResult = $student['result'];
                            $studentId = (int) $student['student_id'];
                            $editable  = !$student['locked'];
                            ?>
                            <tr data-row="<?= $studentId ?>">
                                <td class="text-muted"><?= $index + 1 ?></td>
                                <td class="cell-strong"><?= e(studentFullName($student)) ?></td>
                                <td class="mono"><?= e($student['admission_number']) ?></td>

                                <?php foreach ($structure['components'] as $code => $component): ?>
                                    <td class="text-center">
                                        <input type="number"
                                               class="form-control form-control-sm text-center js-score"
                                               name="<?= e($fieldBase . $studentId . '][' . $code) ?>"
                                               value="<?= $rowResult !== null && $rowResult[$code] !== null ? e((string) $rowResult[$code]) : '' ?>"
                                               min="0"
                                               max="<?= e((string) $component['max']) ?>"
                                               step="0.01"
                                               inputmode="decimal"
                                               aria-label="<?= e($component['label']) ?> for <?= e(studentFullName($student)) ?>"
                                               <?= $editable ? '' : 'disabled' ?>>
                                    </td>
                                <?php endforeach; ?>

                                <td class="text-center">
                                    <strong class="js-total"><?= e(rtrim(rtrim(number_format((float) $student['total'], 2, '.', ''), '0'), '.')) ?></strong>
                                </td>
                                <td class="text-center">
                                    <?php if ($student['grade'] === null): ?>
                                        <span class="text-muted js-grade">&ndash;</span>
                                    <?php else: ?>
                                        <span class="badge bg-success js-grade"><?= e($student['grade']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small class="text-muted js-remark">
                                        <?= e($student['grade_remark'] ?? 'Not graded yet') ?>
                                    </small>
                                </td>
                                <td class="text-center">
                                    <?php if ($rowResult === null): ?>
                                        <span class="badge bg-light text-dark border">Not started</span>
                                    <?php else: ?>
                                        <span class="badge <?= e(resultStatusBadge($student['status'])) ?>">
                                            <?= e(resultStatusLabel($student['status'])) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>

                            <?php if ($editable): ?>
                                <tr class="remark-row">
                                    <td colspan="<?= 3 + count($structure['components']) + 3 ?>" class="ps-4">
                                        <label class="form-label small mb-0 text-muted" for="remark_<?= $studentId ?>">
                                            Teacher's remark
                                        </label>
                                        <input type="text"
                                               class="form-control form-control-sm"
                                               id="remark_<?= $studentId ?>"
                                               name="<?= e($fieldBase . $studentId . '][teacher_remark]') ?>"
                                               value="<?= $rowResult !== null ? e((string) ($rowResult['teacher_remark'] ?? '')) : '' ?>"
                                               maxlength="255"
                                               placeholder="Optional comment for <?= e($student['admission_number']) ?>"
                                               <?= $editable ? '' : 'disabled' ?>>
                                    </td>
                                    <td colspan="2"></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($canEdit): ?>
                    <div class="table-card__foot d-flex flex-wrap gap-2 justify-content-end">
                        <a class="btn btn-outline-secondary"
                           href="<?= e(assetUrl('teacher/enter-result.php?' . $backQuery)) ?>">Discard changes</a>

                        <button class="btn btn-gold" type="submit" formaction="<?= e(assetUrl('teacher/save-result.php')) ?>">
                            <i class="bi bi-save-fill"></i> Save Draft
                        </button>

                        <button class="btn btn-green" type="submit"
                                formaction="<?= e(assetUrl('teacher/submit-result.php')) ?>"
                                data-confirm="Submit these results to the administrator for review? You will not be able to edit them until the administrator returns them.">
                            <i class="bi bi-send-fill"></i> Submit for Review
                        </button>
                    </div>
                <?php else: ?>
                    <div class="table-card__foot">
                        <div class="alert alert-secondary mb-0">
                            <i class="bi bi-lock-fill"></i>
                            Every result on this sheet has already been submitted, so it can no
                            longer be edited. Wait for the administrator to approve or return it.
                        </div>
                    </div>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($hasSelection && $readiness['ready'] === false && $readiness['missing'] !== [] && $canEdit): ?>
        <div class="card table-card mt-3">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-exclamation-triangle-fill text-danger"></i> Why This Sheet Cannot Be Submitted
                </h2>
            </div>
            <div class="table-card__body">
                <p class="mb-2"><?= e($readiness['message']) ?></p>
                <ul class="mb-0 small">
                    <?php foreach (array_slice($readiness['missing'], 0, 25) as $item): ?>
                        <li><?= e($item) ?></li>
                    <?php endforeach; ?>
                    <?php if (count($readiness['missing']) > 25): ?>
                        <li class="text-muted">and <?= count($readiness['missing']) - 25 ?> more.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <div class="card table-card mt-3 mb-4">
        <div class="table-card__head">
            <h2 class="table-card__title">
                <i class="bi bi-calculator-fill text-green"></i> How Grades Are Calculated
            </h2>
        </div>
        <div class="table-card__body">
            <p class="small text-muted">
                The total is the sum of every component. The grade is a share of the
                total of <?= e(rtrim(rtrim(number_format($maxTotal, 2, '.', ''), '0'), '.')) ?>,
                so the bands below stay correct if the assessment structure is changed later.
            </p>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                    <tr>
                        <th>Grade</th>
                        <th>Remark</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Result</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (resultGradeBands() as $band): ?>
                        <tr>
                            <td><span class="badge <?= $band['grade'] === 'F' ? 'bg-danger' : 'bg-success' ?>"><?= e($band['grade']) ?></span></td>
                            <td><?= e($band['remark']) ?></td>
                            <td class="mono"><?= e(rtrim(rtrim(number_format($band['min'], 2, '.', ''), '0'), '.')) ?>%</td>
                            <td class="mono">
                                <?= $band['min'] === 0.0
                                    ? '0'
                                    : e(rtrim(rtrim(number_format($band['min'] - 0.01, 2, '.', ''), '0'), '.')) . '%' ?>
                            </td>
                            <td>
                                <?php if ((float) $band['min'] >= resultPassPercentage()): ?>
                                    <span class="text-success">Pass</span>
                                <?php else: ?>
                                    <span class="text-danger">Fail</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
/*
 * Live total and grade while the teacher types.
 *
 * This is a convenience only. save-result.php recalculates every value on the
 * server with calculateGrade() and stores that, so editing these numbers in the
 * browser has no effect on the saved result.
 */
(function () {
    'use strict';

    var sheet = document.getElementById('resultSheet');

    if (!sheet) {
        return;
    }

    var maxTotal = <?= e(rtrim(rtrim(number_format($maxTotal, 2, '.', ''), '0'), '.')) ?>;

    var bands = <?= json_encode(array_map(
        static function (array $band): array {
            return ['min' => (float) $band['min'], 'grade' => $band['grade'], 'remark' => $band['remark']];
        },
        resultGradeBands()
    )) ?>;

    var passAt = <?= e(rtrim(rtrim(number_format(resultPassPercentage(), 2, '.', ''), '0'), '.')) ?>;

    function format(value) {
        var rounded = Math.round(value * 100) / 100;
        return Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(2);
    }

    function gradeFor(percentage) {
        for (var i = 0; i < bands.length; i++) {
            if (percentage >= bands[i].min) {
                return bands[i];
            }
        }

        return null;
    }

    function recalculate(row) {
        var inputs = row.querySelectorAll('.js-score');
        var total = 0;
        var filled = 0;

        inputs.forEach(function (input) {
            if (input.value === '') {
                return;
            }

            var value = parseFloat(input.value);

            if (isNaN(value)) {
                return;
            }

            total += value;
            filled++;
        });

        var totalCell = row.querySelector('.js-total');
        var gradeCell = row.querySelector('.js-grade');
        var remarkCell = row.querySelector('.js-remark');

        if (totalCell) {
            totalCell.textContent = format(total);
        }

        if (gradeCell) {
            if (filled < inputs.length) {
                gradeCell.textContent = '\u2013';
                gradeCell.className = 'text-muted js-grade';

                if (remarkCell) {
                    remarkCell.textContent = 'Not graded yet';
                }

                return;
            }

            var band = gradeFor((total / maxTotal) * 100);

            if (band === null) {
                gradeCell.textContent = '\u2013';
                gradeCell.className = 'text-muted js-grade';

                if (remarkCell) {
                    remarkCell.textContent = 'Not graded yet';
                }

                return;
            }

            gradeCell.textContent = band.grade;
            gradeCell.className = 'badge js-grade ' + (band.min >= passAt ? 'bg-success' : 'bg-danger');

            if (remarkCell) {
                remarkCell.textContent = band.remark;
            }
        }
    }

    sheet.addEventListener('input', function (event) {
        var input = event.target;

        if (!input.classList.contains('js-score')) {
            return;
        }

        var row = input.closest('tr[data-row]');

        if (row) {
            recalculate(row);
        }
    });

    /* Recalculate on load too, so a sheet restored from the database is
       consistent with the bands even if the structure was changed since. */
    sheet.querySelectorAll('tr[data-row]').forEach(recalculate);
})();
</script>

<?php require __DIR__ . '/../includes/teacher_bottom.php'; ?>
