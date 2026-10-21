<?php

declare(strict_types=1);

/**
 * Report card generation.
 *
 * The screen where a report card is created. It does the deciding itself in no
 * way at all: for each enrolled student it asks validateReportGeneration()
 * whether the result sheet is complete, and generateReportCard() re-checks
 * everything again before it writes. Nothing on this page can produce a report
 * card from an unfinished sheet, because the gate lives in the data layer.
 *
 * Generation works one student at a time or for the whole class at once. A
 * student whose sheet is not ready is skipped and reported, never blocked, so one
 * unfinished teacher does not hold up a class of finished ones.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

$sessionId = queryInt('session_id');
$termId    = queryInt('term_id');
$classId   = queryInt('class_id');

$sessions = sessionOptions();
$terms    = $sessionId === null ? [] : sessionTermOptions($sessionId);
$classes  = classOptions();

/* --- Actions ---------------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_FIELD_NAME] ?? null;

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/report-card-generate.php');
    }

    $action       = field('action', 20);
    $postSession  = queryPostId('session_id');
    $postTerm     = queryPostId('term_id');
    $postClass    = queryPostId('class_id');
    $backQuery    = http_build_query(array_filter([
        'session_id' => $postSession,
        'term_id'    => $postTerm,
        'class_id'   => $postClass,
    ], static function ($value): bool {
        return $value !== null;
    }));

    if ($postSession === null || $postTerm === null || $postClass === null) {
        setFlash('error', 'Choose the academic session, term and class first.');
        redirect('admin/report-card-generate.php');
    }

    /* Only students actually enrolled in this class are processed. A hand edited
       form cannot pull in somebody else's report card. */
    $roster   = classResultRoster($postClass, $postSession);
    $enrolled = array_map(static function (array $row): int {
        return (int) $row['student_id'];
    }, $roster);

    /* The per row Generate button carries its own student id, so ticking a box
       elsewhere on the form must not quietly add those students to this one. */
    $onlyStudent = queryPostId('only_student_id');
    $single      = $onlyStudent !== null;

    if ($action === 'generate_all') {
        /* The whole roster is offered; generateReportCard() refuses anybody whose
           results are not complete, which is how the ready ones get separated. */
        $targets = $enrolled;
    } else {
        $studentIds = $single ? [$onlyStudent] : postIdList('student_id');
        $targets    = array_values(array_intersect($studentIds, $enrolled));
    }

    if ($targets === []) {
        setFlash('error', $action === 'generate_all'
            ? 'No student is enrolled in that class for that session.'
            : 'Select at least one student.');
        redirect('admin/report-card-generate.php?' . $backQuery);
    }

    $done      = 0;
    $refused   = [];
    $generated = [];

    foreach ($targets as $studentId) {
        $outcome = generateReportCard($studentId, $postClass, $postSession, $postTerm);

        if ($outcome['ok']) {
            $done++;
            $generated[] = (int) $outcome['id'];

            continue;
        }

        $refused[$studentId] = $outcome['message'];
    }

    if ($done > 0) {
        setFlash('success', $done === 1
            ? '1 report card generated.'
            : $done . ' report cards generated.');
    }

    if ($refused !== []) {
        setFlash('error', count($refused) . ' report card'
            . (count($refused) === 1 ? '' : 's')
            . ' could not be generated. ' . reset($refused));
    }

    /* A single card, straight to it, so the administrator can see the result of
       the work without going back to the list first. */
    if ($single && count($generated) === 1) {
        redirect('admin/report-card-view.php?id=' . $generated[0]);
    }

    redirect('admin/report-card-generate.php?' . $backQuery);
}

/* --- Readiness -------------------------------------------------------------- */

$roster   = [];
$readiness = [];

if ($sessionId !== null && $termId !== null && $classId !== null) {
    $roster = classResultRoster($classId, $sessionId);

    foreach ($roster as $row) {
        $studentId = (int) $row['student_id'];
        $check     = validateReportGeneration($studentId, $classId, $sessionId, $termId);
        $existing  = findReportCardFor($studentId, $classId, $sessionId, $termId);

        $readiness[] = [
            'student'  => $row,
            'check'    => $check,
            'existing' => $existing,
        ];
    }
}

$readyCount    = 0;
$existingCount = 0;

foreach ($readiness as $entry) {
    if ($entry['existing'] !== null) {
        $existingCount++;
    } elseif ($entry['check']['ready']) {
        $readyCount++;
    }
}

$pageTitle    = 'Generate Report Cards';
$pageHeading  = 'Generate Report Cards';
$pageSubtitle = 'A report card can only be created once every subject result for that term is approved or locked.';
$activeMenu   = 'reportcards';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('admin/report-cards.php')) ?>">
            <i class="bi bi-files"></i> All Report Cards
        </a>
    </div>
</div>

<form method="get" class="filter-bar" autocomplete="off">
    <div class="filter-bar__row">
        <select class="form-select w-auto" name="session_id" aria-label="Academic session">
            <option value="">Choose a session</option>
            <?php foreach ($sessions as $option): ?>
                <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $sessionId, (string) $option['id']) ?>>
                    <?= e($option['session_name']) ?><?= (int) $option['is_current'] === 1 ? ' (current)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select class="form-select w-auto" name="term_id" aria-label="Term">
            <option value="">Choose a term</option>
            <?php foreach ($terms as $option): ?>
                <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $termId, (string) $option['id']) ?>>
                    <?= e($option['term_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select class="form-select w-auto" name="class_id" aria-label="Class">
            <option value="">Choose a class</option>
            <?php foreach ($classes as $option): ?>
                <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $classId, (string) $option['id']) ?>>
                    <?= e($option['class_name']) ?> (<?= e($option['section']) ?>)
                </option>
            <?php endforeach; ?>
        </select>

        <button class="btn btn-green" type="submit"><i class="bi bi-search"></i> Load Class</button>
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/report-card-generate.php')) ?>">Reset</a>
    </div>
</form>

<?php if ($sessionId === null || $termId === null || $classId === null): ?>
    <div class="card table-card">
        <div class="table-card__body">
            <div class="empty-state">
                <i class="bi bi-file-earmark-text"></i>
                <p>Choose an academic session, a term and a class to see which students are ready.</p>
            </div>
        </div>
    </div>
<?php elseif ($roster === []): ?>
    <div class="card table-card">
        <div class="table-card__body">
            <div class="empty-state">
                <i class="bi bi-people"></i>
                <p>No student is actively enrolled in that class for that session, so there is nothing to generate.</p>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('admin/student-enrollments.php')) ?>">
                    Manage enrollments
                </a>
            </div>
        </div>
    </div>
<?php else: ?>
    <?php
    $subjectTotal = null;

    if ($readiness !== []) {
        $subjectTotal = $readiness[0]['check']['offered'];
    }
    ?>

    <div class="alert alert-info d-flex align-items-start gap-2">
        <i class="bi bi-info-circle-fill mt-1"></i>
        <div>
            <?= count($readiness) ?> student<?= count($readiness) === 1 ? '' : 's' ?> enrolled.
            <?php if ($subjectTotal !== null && $subjectTotal > 0): ?>
                Each card needs <?= (int) $subjectTotal ?> approved or locked subject result<?= $subjectTotal === 1 ? '' : 's' ?>.
            <?php endif; ?>
            <strong><?= (int) $readyCount ?></strong> ready to generate,
            <strong><?= (int) $existingCount ?></strong> already generated.
        </div>
    </div>

    <form method="post" autocomplete="off" id="generateForm">
        <?= csrfField() ?>
        <input type="hidden" name="session_id" value="<?= (int) $sessionId ?>">
        <input type="hidden" name="term_id" value="<?= (int) $termId ?>">
        <input type="hidden" name="class_id" value="<?= (int) $classId ?>">

        <div class="d-flex flex-wrap gap-2 mb-3">
            <button class="btn btn-green" type="submit" name="action" value="generate"
                    data-confirm="Generate a report card for every selected student?">
                <i class="bi bi-magic"></i> Generate Selected
            </button>
            <button class="btn btn-gold" type="submit" name="action" value="generate_all"
                    data-confirm="Generate a report card for every ready student in this class? Students whose results are not complete are skipped.">
                <i class="bi bi-files"></i> Generate All Ready (<?= (int) $readyCount ?>)
            </button>
            <a class="btn btn-outline-secondary"
               href="<?= e(assetUrl('admin/results.php?session_id=' . (int) $sessionId . '&amp;term_id=' . (int) $termId . '&amp;class_id=' . (int) $classId)) ?>">
                <i class="bi bi-clipboard-data-fill"></i> Review Results
            </a>
        </div>

        <div class="table-card">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-person-check-fill text-green"></i> Students In This Class
                </h2>
            </div>

            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                    <tr>
                        <th style="width:2.5rem;">
                            <input class="form-check-input" type="checkbox" id="checkAll" aria-label="Select every ready student">
                        </th>
                        <th>Student</th>
                        <th>Admission No.</th>
                        <th class="text-center">Subjects Done</th>
                        <th>Readiness</th>
                        <th>Existing Card</th>
                        <th class="text-end">Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($readiness as $entry): ?>
                        <?php
                        $student = $entry['student'];
                        $check   = $entry['check'];
                        $existing = $entry['existing'];
                        $isReady = $check['ready'];
                        ?>
                        <tr>
                            <td>
                                <input class="form-check-input js-student-check" type="checkbox"
                                       name="student_id[]" value="<?= (int) $student['student_id'] ?>"
                                       <?= $isReady ? '' : 'disabled' ?>
                                       aria-label="Select <?= e($student['first_name'] . ' ' . $student['last_name']) ?>">
                            </td>
                            <td class="cell-strong"><?= e(studentFullName($student)) ?></td>
                            <td class="mono"><?= e($student['admission_number']) ?></td>
                            <td class="text-center mono">
                                <?= (int) $check['counted'] ?> / <?= (int) $check['offered'] ?>
                            </td>
                            <td>
                                <?php if ($existing !== null): ?>
                                    <span class="badge <?= e(reportCardBadgeClass((string) $existing['status'])) ?>">
                                        <?= e(ucfirst((string) $existing['status'])) ?>
                                    </span>
                                <?php elseif ($isReady): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle-fill"></i> Ready</span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="bi bi-exclamation-triangle-fill"></i> Incomplete</span>
                                    <br><small class="text-muted">
                                        <?php foreach (array_slice($check['missing'], 0, 3) as $gap): ?>
                                            <?= e((string) ($gap['subject_name'] ?? $gap['label'] ?? 'A subject')) ?>
                                            (<?= e((string) ($gap['status'] ?? 'missing')) ?>)<?= $gap !== end($check['missing']) ? ', ' : '' ?>
                                        <?php endforeach; ?>
                                        <?php if (count($check['missing']) > 3): ?>
                                            and <?= count($check['missing']) - 3 ?> more
                                        <?php endif; ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($existing === null): ?>
                                    <span class="text-muted">&ndash;</span>
                                <?php else: ?>
                                    <span class="mono small"><?= e((string) $existing['report_number']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($existing !== null): ?>
                                    <a class="btn btn-sm btn-outline-success"
                                       href="<?= e(assetUrl('admin/report-card-view.php?id=' . (int) $existing['id'])) ?>">
                                        <i class="bi bi-eye-fill"></i> Open
                                    </a>
                                <?php elseif ($isReady): ?>
                                    <button class="btn btn-sm btn-green" type="submit"
                                            name="only_student_id" value="<?= (int) $student['student_id'] ?>">
                                        <i class="bi bi-magic"></i> Generate
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted small">
                                        <i class="bi bi-hourglass-split"></i> Not ready
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </form>

    <p class="mt-3 mb-4 text-muted small">
        <i class="bi bi-shield-lock me-1"></i>
        Generating a card that already exists refreshes its figures and returns it
        to <strong>generated</strong>, because the numbers just changed and an
        earlier approval no longer describes them. A locked card is never touched.
    </p>
<?php endif; ?>

<script>
    /* Ticking the header box ticks every student whose results are complete.
       The disabled boxes are left alone: they cannot be generated yet. */
    (function () {
        var selectAll = document.getElementById('checkAll');

        if (!selectAll) {
            return;
        }

        selectAll.addEventListener('change', function () {
            var boxes = document.querySelectorAll('.js-student-check:not([disabled])');

            Array.prototype.forEach.call(boxes, function (box) {
                box.checked = selectAll.checked;
            });
        });
    })();
</script>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
