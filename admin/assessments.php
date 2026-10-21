<?php

declare(strict_types=1);

/**
 * Psychomotor and effective area assessments.
 *
 * The sixteen qualities a report card carries alongside the subject scores: ten
 * psychomotor traits and six effective areas, each rated from 1 to 5.
 *
 * The scale is shown as a radio group per row rather than a number box, because a
 * 0 or a 6 would be meaningless to the person filling it in. The server still
 * checks every value against the 1 to 5 scale before anything is written, so a
 * hand edited form cannot push an out of range rating into the database.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

$studentId = queryInt('student_id');
$sessionId = queryInt('session_id');
$termId    = queryInt('term_id');
$classId   = queryInt('class_id');

/**
 * The class a student sat in for a session.
 *
 * Recovered from the enrollment, which is the authority anyway, rather than
 * being trusted from the query string. The same lookup runs again for a POST,
 * because a POST body carries no query string to read it from.
 */
function enrolledClassId(int $studentId, int $sessionId): ?int
{
    $row = dbOne(
        "SELECT e.class_id
           FROM student_class_enrollments e
           JOIN students s ON s.id = e.student_id
          WHERE e.student_id = :student_id
            AND e.session_id = :session_id
            AND e.status = 'Active'
            AND s.status = 'Active'
          ORDER BY e.id DESC
          LIMIT 1",
        [':student_id' => $studentId, ':session_id' => $sessionId]
    );

    return $row === null ? null : (int) $row['class_id'];
}

if ($studentId !== null && $sessionId !== null) {
    $classId = enrolledClassId($studentId, $sessionId);
}

$sessions = sessionOptions();
$terms    = $sessionId === null ? [] : sessionTermOptions($sessionId);

/* A picker of eligible students, so the screen is usable without a report card. */
$students = [];

if ($sessionId !== null && $classId !== null) {
    foreach (classResultRoster($classId, $sessionId) as $row) {
        $students[] = [
            'id'    => (int) $row['student_id'],
            'label' => $row['admission_number'] . ' - ' . studentFullName($row),
        ];
    }
}

/* --- Saving ---------------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_FIELD_NAME] ?? null;

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/assessments.php');
    }

    $postStudent = queryPostId('student_id');
    $postSession = queryPostId('session_id');
    $postTerm    = queryPostId('term_id');
    $backQuery   = http_build_query(array_filter([
        'student_id' => $postStudent,
        'session_id' => $postSession,
        'term_id'    => $postTerm,
    ], static function ($value): bool {
        return $value !== null;
    }));

    if ($postStudent === null || $postSession === null || $postTerm === null) {
        setFlash('error', 'Choose a student, a session and a term first.');
        redirect('admin/assessments.php');
    }

    $rawPsychomotor = $_POST['psychomotor'] ?? null;
    $rawEffective   = $_POST['effective'] ?? null;

    /* The class is resolved from the posted identifiers, because the query
       string this page was reached on is not part of a POST. */
    $postClassId = enrolledClassId($postStudent, $postSession);

    if ($postClassId === null) {
        setFlash('error', 'That student was not enrolled in a class for the selected session.');
        redirect('admin/assessments.php?' . $backQuery);
    }

    $outcome = saveAssessments(
        $postStudent,
        $postClassId,
        $postSession,
        $postTerm,
        is_array($rawPsychomotor) ? $rawPsychomotor : [],
        is_array($rawEffective) ? $rawEffective : []
    );

    /* saveAssessments() names every trait that was out of range, and it saves
       nothing at all when one is, so the stored ratings are still the last good
       ones. The message carries that detail; the form reloads from the database. */
    setFlash($outcome['ok'] ? 'success' : 'error', $outcome['message']);

    redirect('admin/assessments.php?' . $backQuery);
}

/* --- Current state ---------------------------------------------------------- */

$student      = $studentId === null ? null : findStudent($studentId);
$psychomotor  = null;
$effective    = null;
$reportCard   = null;
$previousTerm = null;

if ($studentId !== null && $sessionId !== null && $termId !== null && $classId !== null) {
    $psychomotor = reportCardPsychomotor($studentId, $classId, $sessionId, $termId);
    $effective   = reportCardEffectiveAreas($studentId, $classId, $sessionId, $termId);
    $reportCard  = findReportCardFor($studentId, $classId, $sessionId, $termId);
    $previousTerm = dbOne(
        'SELECT * FROM terms WHERE id = :id LIMIT 1',
        [':id' => $termId]
    );
}

$isLocked = $reportCard !== null && (string) $reportCard['status'] === 'locked';
$scale    = ratingScale();

$pageTitle    = 'Assessments';
$pageHeading  = 'Psychomotor And Effective Areas';
$pageSubtitle = 'The qualities rated from 1 to 5 that print alongside the subject scores.';
$activeMenu   = 'assessments';

require __DIR__ . '/../includes/admin_top.php';

/**
 * One row of the rating scale, as a group of radio buttons.
 *
 * A radio group is used rather than a select so every option is visible at once,
 * which is what makes the form quick to fill in for a whole class. The input ids
 * are built from the array name and the trait key, so they are valid identifiers
 * and unique even though the field names are nested.
 */
function renderRatingRow(string $group, string $key, string $label, $current, array $scale): void
{
    $name = $group . '[' . $key . ']';
    $id   = $group . '-' . str_replace('_', '-', $key);
    ?>
    <tr>
        <td><?= e($label) ?></td>
        <?php foreach ($scale as $value => $word): ?>
            <td class="text-center">
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio"
                           name="<?= e($name) ?>" id="<?= e($id . '-' . $value) ?>"
                           value="<?= (int) $value ?>"
                           <?= $current !== null && (int) $current === (int) $value ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="<?= e($id . '-' . $value) ?>">
                        <?= e($word) ?>
                    </label>
                </div>
            </td>
        <?php endforeach; ?>
    </tr>
    <?php
}
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <?php if ($reportCard !== null): ?>
            <a class="btn btn-sm btn-outline-success"
               href="<?= e(assetUrl('admin/report-card-view.php?id=' . (int) $reportCard['id'])) ?>">
                <i class="bi bi-file-earmark-text-fill"></i> Open Report Card
            </a>
        <?php endif; ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('admin/report-cards.php')) ?>">
            <i class="bi bi-arrow-left"></i> Report Cards
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

        <select class="form-select w-auto" name="student_id" aria-label="Student">
            <option value="">Choose a student</option>
            <?php foreach ($students as $option): ?>
                <option value="<?= $option['id'] ?>"<?= isSelected((string) $studentId, (string) $option['id']) ?>>
                    <?= e($option['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button class="btn btn-green" type="submit"><i class="bi bi-search"></i> Load</button>
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/assessments.php')) ?>">Reset</a>
    </div>
</form>

<?php if ($studentId === null || $sessionId === null || $termId === null || $student === null): ?>
    <div class="card table-card">
        <div class="table-card__body">
            <div class="empty-state">
                <i class="bi bi-stars"></i>
                <p>Choose a session, a term and a student to rate their psychomotor traits and effective areas.</p>
            </div>
        </div>
    </div>
<?php elseif ($classId === null): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <?= e(studentFullName($student)) ?> was not actively enrolled in any class during
        that session, so there is no report card to attach these ratings to.
    </div>
<?php elseif ($isLocked): ?>
    <div class="card table-card">
        <div class="table-card__head">
            <h2 class="table-card__title">
                <i class="bi bi-person-arms-up text-green"></i> <?= e(studentFullName($student)) ?>
            </h2>
            <span class="badge bg-success"><i class="bi bi-lock-fill"></i> Report Card Locked</span>
        </div>
        <div class="table-card__body">
            <div class="alert alert-secondary mb-0">
                <i class="bi bi-lock"></i>
                These ratings are printed on a locked report card, so they cannot be
                changed. Unlock the card first, giving a reason, if they are wrong.
            </div>
        </div>
    </div>
<?php else: ?>
    <form method="post" autocomplete="off">
        <?= csrfField() ?>
        <input type="hidden" name="student_id" value="<?= (int) $studentId ?>">
        <input type="hidden" name="session_id" value="<?= (int) $sessionId ?>">
        <input type="hidden" name="term_id" value="<?= (int) $termId ?>">

        <div class="table-card mb-3">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-person-arms-up text-green"></i> Psychomotor Traits
                    <small><?= e(studentFullName($student)) ?> &middot; <?= e($previousTerm['term_name'] ?? '') ?></small>
                </h2>
            </div>

            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                    <tr>
                        <th>Trait</th>
                        <?php foreach ($scale as $word): ?>
                            <th class="text-center"><?= e($word) ?></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (psychomotorTraits() as $key => $label): ?>
                        <?= renderRatingRow('psychomotor', $key, $label, $psychomotor[$key] ?? null, $scale) ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="table-card">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-stars text-green"></i> Effective Areas
                </h2>
            </div>

            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                    <tr>
                        <th>Area</th>
                        <?php foreach ($scale as $word): ?>
                            <th class="text-center"><?= e($word) ?></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (effectiveAreaFields() as $key => $label): ?>
                        <?= renderRatingRow('effective', $key, $label, $effective[$key] ?? null, $scale) ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="table-card__foot">
                <button class="btn btn-green" type="submit">
                    <i class="bi bi-save-fill"></i> Save Assessments
                </button>
                <span class="text-muted small ms-2">
                    Leave a row blank to keep whatever is already stored.
                </span>
            </div>
        </div>
    </form>
<?php endif; ?>

<p class="mt-3 mb-4 text-muted small">
    <i class="bi bi-shield-lock me-1"></i>
    Every rating is checked against the 1 to 5 scale on the server before anything
    is written, and the psychomotor traits and the effective areas are saved
    together, so a card never shows one without the other.
</p>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
