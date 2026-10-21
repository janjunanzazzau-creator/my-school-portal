<?php

declare(strict_types=1);

/**
 * Class attendance.
 *
 * One screen for a whole class: the school days, and how many of them each
 * student was present for. The arithmetic rule is simple and enforced in three
 * places - here, in saveAttendanceSummary(), and by a CHECK constraint in the
 * table - so a term can never be shown as a hundred and ten days out of a
 * hundred.
 *
 * Attendance is entered per student, per term. It is not a daily register, because
 * the report card only ever prints the three totals.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

$sessionId = queryInt('session_id');
$termId    = queryInt('term_id');
$classId   = queryInt('class_id');

$sessions = sessionOptions();
$terms    = $sessionId === null ? [] : sessionTermOptions($sessionId);
$classes  = classOptions();

/* --- Saving ---------------------------------------------------------------- */

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_FIELD_NAME] ?? null;

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/attendance.php');
    }

    $postSession = queryPostId('session_id');
    $postTerm    = queryPostId('term_id');
    $postClass   = queryPostId('class_id');
    $backQuery   = http_build_query(array_filter([
        'session_id' => $postSession,
        'term_id'    => $postTerm,
        'class_id'   => $postClass,
    ], static function ($value): bool {
        return $value !== null;
    }));

    if ($postSession === null || $postTerm === null || $postClass === null) {
        setFlash('error', 'Choose the academic session, term and class first.');
        redirect('admin/attendance.php');
    }

    /* Read the three numbers for the student being saved. A blank box is refused
       rather than treated as zero, because a missing figure is not the same as
       "was absent every single day". */
    $studentId = queryPostId('student_id');
    $raw       = $_POST['attendance'] ?? null;
    $figures   = is_array($raw) ? $raw : [];

    $read = static function (array $source, string $key): ?int {
        $value = $source[$key] ?? null;

        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || !ctype_digit($value) ? null : (int) $value;
    };

    $schoolDays  = $read($figures, 'school_days');
    $daysPresent = $read($figures, 'days_present');
    $daysAbsent  = $read($figures, 'days_absent');

    if ($studentId === null) {
        setFlash('error', 'No student was selected, so nothing was saved.');
        redirect('admin/attendance.php?' . $backQuery);
    }

    if ($schoolDays === null || $daysPresent === null || $daysAbsent === null) {
        setFlash('error', 'Fill in the school days, days present and days absent with whole numbers.');
        redirect('admin/attendance.php?' . $backQuery);
    }

    $outcome = saveAttendanceSummary(
        $studentId,
        $postClass,
        $postSession,
        $postTerm,
        $schoolDays,
        $daysPresent,
        $daysAbsent
    );

    setFlash($outcome['ok'] ? 'success' : 'error', $outcome['message']);

    redirect('admin/attendance.php?' . $backQuery);
}

/* --- The class -------------------------------------------------------------- */

$rows = [];

if ($sessionId !== null && $termId !== null && $classId !== null) {
    $rows = classAttendanceSummary($classId, $sessionId, $termId);
}

/* Which of these students already has a locked report card, so the form can say
   so before the save is attempted. */
$lockedStudents = [];

if ($rows !== []) {
    foreach (dbAll(
        "SELECT student_id
           FROM report_cards
          WHERE class_id = :class_id
            AND session_id = :session_id
            AND term_id = :term_id
            AND status = 'locked'",
        [':class_id' => $classId, ':session_id' => $sessionId, ':term_id' => $termId]
    ) as $entry) {
        $lockedStudents[(int) $entry['student_id']] = true;
    }
}

$class = $classId === null
    ? null
    : dbOne('SELECT * FROM classes WHERE id = :id LIMIT 1', [':id' => $classId]);

/* The most common number of school days already entered, offered as a starting
   point. It is only a convenience: the administrator still confirms it. */
$commonDays = null;

if ($rows !== [] && $classId !== null && $sessionId !== null && $termId !== null) {
    $commonDays = dbValue(
        'SELECT school_days
           FROM attendance_summary
          WHERE class_id = :class_id AND session_id = :session_id AND term_id = :term_id
          GROUP BY school_days
          ORDER BY COUNT(*) DESC
          LIMIT 1',
        [':class_id' => $classId, ':session_id' => $sessionId, ':term_id' => $termId]
    );

    $commonDays = $commonDays === null ? null : (int) $commonDays;
}

$pageTitle    = 'Attendance';
$pageHeading  = 'Class Attendance';
$pageSubtitle = 'Days present and days absent for each student, entered once per term.';
$activeMenu   = 'attendance';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('admin/report-cards.php')) ?>">
            <i class="bi bi-file-earmark-text-fill"></i> Report Cards
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
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/attendance.php')) ?>">Reset</a>
    </div>
</form>

<?php if ($sessionId === null || $termId === null || $classId === null): ?>
    <div class="card table-card">
        <div class="table-card__body">
            <div class="empty-state">
                <i class="bi bi-calendar-check"></i>
                <p>Choose an academic session, a term and a class to record attendance.</p>
            </div>
        </div>
    </div>
<?php elseif ($rows === []): ?>
    <div class="card table-card">
        <div class="table-card__body">
            <div class="empty-state">
                <i class="bi bi-people"></i>
                <p>No student is actively enrolled in
                    <?= e($class === null ? 'that class' : (string) $class['class_name']) ?>
                    for that session.</p>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="alert alert-info d-flex align-items-start gap-2">
        <i class="bi bi-info-circle-fill mt-1"></i>
        <div>
            Days present plus days absent must equal the school days.
            <?php if ($commonDays !== null): ?>
                This class has used <strong><?= $commonDays ?></strong> school days for this term so far.
            <?php endif; ?>
        </div>
    </div>

    <div class="table-card">
        <div class="table-card__head">
            <h2 class="table-card__title">
                <i class="bi bi-calendar-check-fill text-green"></i> Attendance Records
                <small>(<?= count($rows) ?> student<?= count($rows) === 1 ? '' : 's' ?>)</small>
            </h2>
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                <tr>
                    <th>Admission No.</th>
                    <th>Student</th>
                    <th style="width:8rem;">School Days</th>
                    <th style="width:8rem;">Present</th>
                    <th style="width:8rem;">Absent</th>
                    <th class="text-center">Status</th>
                    <th class="text-end">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $studentId = (int) $row['student_id'];
                    $isLocked  = isset($lockedStudents[$studentId]);
                    $hasRow    = $row['id'] !== null;
                    ?>
                    <tr>
                        <td class="mono"><?= e((string) $row['admission_number']) ?></td>
                        <td class="cell-strong"><?= e(studentFullName($row)) ?></td>

                        <?php if ($isLocked): ?>
                            <td colspan="3" class="text-center">
                                <span class="text-muted small">
                                    <?= $hasRow
                                        ? e((int) $row['days_present'] . ' present, ' . (int) $row['days_absent'] . ' absent of ' . (int) $row['school_days'])
                                        : 'Not recorded' ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-success"><i class="bi bi-lock-fill"></i> Locked</span>
                            </td>
                            <td class="text-end">
                                <span class="text-muted small">
                                    <i class="bi bi-lock"></i> Card locked
                                </span>
                            </td>
                        <?php else: ?>
                            <?php $formId = 'attendance-' . $studentId; ?>
                            <td>
                                <input class="form-control form-control-sm js-days" type="number"
                                       form="<?= e($formId) ?>"
                                       name="attendance[school_days]" min="1" max="400" step="1"
                                       inputmode="numeric"
                                       value="<?= $hasRow ? (int) $row['school_days'] : '' ?>"
                                       aria-label="School days for <?= e(studentFullName($row)) ?>"
                                       required>
                            </td>
                            <td>
                                <input class="form-control form-control-sm js-present" type="number"
                                       form="<?= e($formId) ?>"
                                       name="attendance[days_present]" min="0" max="400" step="1"
                                       inputmode="numeric"
                                       value="<?= $hasRow ? (int) $row['days_present'] : '' ?>"
                                       aria-label="Days present for <?= e(studentFullName($row)) ?>"
                                       required>
                            </td>
                            <td>
                                <input class="form-control form-control-sm js-absent" type="number"
                                       form="<?= e($formId) ?>"
                                       name="attendance[days_absent]" min="0" max="400" step="1"
                                       inputmode="numeric"
                                       value="<?= $hasRow ? (int) $row['days_absent'] : '' ?>"
                                       aria-label="Days absent for <?= e(studentFullName($row)) ?>"
                                       required>
                            </td>
                            <td class="text-center">
                                <?php if ($hasRow): ?>
                                    <span class="badge bg-light text-dark border">Recorded</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Not recorded</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-green" type="submit"
                                        form="<?= e($formId) ?>"
                                        data-confirm="Save this attendance record?">
                                    <i class="bi bi-save-fill"></i> Save
                                </button>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php /* A form cannot sit inside a table row, so each student's form lives
                 out here and its fields point back at it with the form attribute. */ ?>
        <div class="visually-hidden" aria-hidden="true">
            <?php foreach ($rows as $row): ?>
                <?php
                $studentId = (int) $row['student_id'];

                if (isset($lockedStudents[$studentId])) {
                    continue;
                }
                ?>
                <form id="attendance-<?= $studentId ?>" method="post" autocomplete="off">
                    <?= csrfField() ?>
                    <input type="hidden" name="session_id" value="<?= (int) $sessionId ?>">
                    <input type="hidden" name="term_id" value="<?= (int) $termId ?>">
                    <input type="hidden" name="class_id" value="<?= (int) $classId ?>">
                    <input type="hidden" name="student_id" value="<?= $studentId ?>">
                </form>
            <?php endforeach; ?>
        </div>
    </div>

    <p class="mt-3 mb-4 text-muted small">
        <i class="bi bi-shield-lock me-1"></i>
        A student whose report card is already locked cannot have their attendance
        changed. Unlock the card first, giving a reason, if the figures are wrong.
    </p>
<?php endif; ?>

<script>
    /* Type the school days once and the absent box fills itself in, because the
       rule is present + absent = school days. The server checks it regardless,
       so this is a convenience and not the enforcement. */
    (function () {
        var rows = document.querySelectorAll('.js-days');

        Array.prototype.forEach.call(rows, function (daysBox) {
            var row = daysBox.closest('tr');
            var presentBox = row.querySelector('.js-present');
            var absentBox = row.querySelector('.js-absent');

            daysBox.addEventListener('input', function () {
                var total = parseInt(daysBox.value, 10);

                if (isNaN(total) || total <= 0) {
                    return;
                }

                var present = parseInt(presentBox.value, 10);

                if (isNaN(present) || present < 0) {
                    present = 0;
                }

                if (present > total) {
                    present = total;
                }

                presentBox.value = present;
                absentBox.value = total - present;
            });
        });
    })();
</script>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
