<?php

declare(strict_types=1);

/**
 * My Report Cards.
 *
 * The class teacher's view of the report cards for the students in the classes
 * allocated to them.
 *
 * The list is built from teacher_class_assignments on every request rather than
 * from a teacher id in the query string, so one teacher can never see another's
 * cards and removing an allocation removes access straight away.
 *
 * The only thing a teacher can change is the class teacher's comment, and only
 * while the card is not locked. The scores, the position, the promotion status
 * and the approval state are all outside their reach, which is why this page
 * posts through saveTeacherReportComment() rather than the administrator's
 * comment form.
 */

require_once __DIR__ . '/../includes/teacher_bootstrap.php';

$profile = currentTeacherProfile();

if ($profile === null) {
    setFlash('error', 'No teacher record is linked to your account. Please contact the administrator.');
    redirect('login.php');
}

$teacherId = (int) $profile['id'];

/* --- Saving the comment ------------------------------------------------------ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_FIELD_NAME] ?? null;

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('teacher/my-report-cards.php');
    }

    $reportId = queryPostId('report_id');

    if ($reportId === null) {
        setFlash('error', 'No report card was selected, so nothing was saved.');
        redirect('teacher/my-report-cards.php');
    }

    $comment = $_POST['teacher_comment'] ?? '';
    $comment = is_string($comment) ? $comment : '';

    /* The length limit is applied here as well as in the function, so an
       over-long comment is trimmed before it is ever sent to the database. */
    $outcome = saveTeacherReportComment($reportId, substr(trim($comment), 0, 500));

    setFlash($outcome['ok'] ? 'success' : 'error', $outcome['message']);

    redirect('teacher/my-report-cards.php');
}

/* --- Filters ----------------------------------------------------------------- */

$search    = trim((string) ($_GET['search'] ?? ''));
$sessionId = queryInt('session_id');
$termId    = queryInt('term_id');
$classId   = queryInt('class_id');
$status    = (string) ($_GET['status'] ?? '');
$perPage   = resolvePerPage();
$page      = queryInt('page') ?? 1;

if (!inList($status, reportCardStatuses())) {
    $status = '';
}

/* The teacher's own scope, rebuilt on every request. */
$scope        = teacherReportCardScope($teacherId);
$scopeClass   = [];
$scopeSession = [];
$scopeTerm    = [];

foreach ($scope as $row) {
    $scopeClass[(int) $row['class_id']]    = (string) $row['class_name'] . ' (' . $row['section'] . ')';
    $scopeSession[(int) $row['session_id']] = (string) $row['session_name'];
}

if ($sessionId !== null) {
    foreach (sessionTermOptions($sessionId) as $option) {
        $scopeTerm[(int) $option['id']] = (string) $option['term_name'];
    }
}

/* A class filter the teacher is not allocated to would show nothing, which is the
   correct answer, but an empty dropdown entry is friendlier than a silent
   nothing. The scope itself is the only source of the class list. */
$allowedClassIds = array_keys($scopeClass);

$listing = listReportCards([
    'search'     => $search,
    'session_id' => $sessionId,
    'term_id'    => $termId,
    'class_id'   => $classId,
    'status'     => $status,
    'class_ids'  => $allowedClassIds,
    'per_page'   => $perPage,
    'page'       => $page,
]);

$rows   = $listing['rows'];
$paging = $listing['paging'];

$counts = reportCardStatusCounts([
    'session_id' => $sessionId,
    'term_id'    => $termId,
    'class_id'   => $classId,
    'class_ids'  => $allowedClassIds,
]);

$pageTitle    = 'My Report Cards';
$pageHeading  = 'My Report Cards';
$pageSubtitle = 'The report cards for the students in the classes allocated to you.';
$activeMenu   = 'myreportcards';

require __DIR__ . '/../includes/teacher_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('teacher/my-results.php')) ?>">
            <i class="bi bi-clipboard-data-fill"></i> My Results
        </a>
    </div>
</div>

<?php if ($allowedClassIds === []): ?>
    <div class="card table-card">
        <div class="table-card__body">
            <div class="empty-state">
                <i class="bi bi-door-closed"></i>
                <p>
                    You are not currently allocated to any class, so there are no
                    report cards to show. An administrator allocates classes from
                    Teacher Assignments.
                </p>
                <a class="btn btn-sm btn-outline-secondary"
                   href="<?= e(assetUrl('teacher/my-classes.php')) ?>">My Classes</a>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="row g-3 mb-3">
        <?php foreach ([
            ['label' => 'Cards',          'value' => $counts['total'],     'colour' => 'var(--green)', 'icon' => 'bi-files'],
            ['label' => 'Awaiting Approval', 'value' => $counts['generated'], 'colour' => 'var(--gold)',  'icon' => 'bi-hourglass-split'],
            ['label' => 'Approved',       'value' => $counts['approved'],  'colour' => '#1f6f8b',       'icon' => 'bi-check-circle'],
            ['label' => 'Locked',         'value' => $counts['locked'],    'colour' => '#3a3f58',       'icon' => 'bi-lock-fill'],
        ] as $card): ?>
            <div class="col-6 col-lg-3">
                <div class="card stat-card">
                    <span class="stat-card__icon" style="background:<?= e($card['colour']) ?>;">
                        <i class="bi <?= e($card['icon']) ?>"></i>
                    </span>
                    <div>
                        <div class="stat-card__value"><?= (int) $card['value'] ?></div>
                        <div class="stat-card__label"><?= e($card['label']) ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <form method="get" class="filter-bar" autocomplete="off">
        <div class="filter-bar__row">
            <div class="input-group filter-bar__search">
                <span class="input-group-text bg-white"><i class="bi bi-search text-green"></i></span>
                <input type="search" class="form-control" name="search" value="<?= e($search) ?>"
                       placeholder="Student name or admission number">
            </div>

            <select class="form-select w-auto" name="session_id" aria-label="Filter by academic session">
                <option value="">All my sessions</option>
                <?php foreach ($scopeSession as $id => $name): ?>
                    <option value="<?= $id ?>"<?= isSelected((string) $sessionId, (string) $id) ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>

            <select class="form-select w-auto" name="term_id" aria-label="Filter by term">
                <option value="">All terms</option>
                <?php foreach ($scopeTerm as $id => $name): ?>
                    <option value="<?= $id ?>"<?= isSelected((string) $termId, (string) $id) ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>

            <select class="form-select w-auto" name="class_id" aria-label="Filter by class">
                <option value="">All my classes</option>
                <?php foreach ($scopeClass as $id => $name): ?>
                    <option value="<?= $id ?>"<?= isSelected((string) $classId, (string) $id) ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>

            <select class="form-select w-auto" name="status" aria-label="Filter by status">
                <option value="">All statuses</option>
                <?php foreach (reportCardStatuses() as $option): ?>
                    <option value="<?= e($option) ?>"<?= isSelected($status, $option) ?>><?= e(ucfirst($option)) ?></option>
                <?php endforeach; ?>
            </select>

            <button class="btn btn-green" type="submit"><i class="bi bi-funnel-fill"></i> Filter</button>
            <a class="btn btn-outline-secondary" href="<?= e(assetUrl('teacher/my-report-cards.php')) ?>">Reset</a>
            <a class="btn btn-sm btn-outline-primary no-print" href="<?= e(assetUrl('teacher/print-my-report-cards.php' . '?' . currentQueryString())) ?>" target="_blank" rel="noopener">
                <i class="bi bi-printer-fill"></i> Print
            </a>
        </div>
    </form>

    <div class="table-card">
        <div class="table-card__head">
            <h2 class="table-card__title">
                <i class="bi bi-file-earmark-text-fill text-green"></i> Report Cards
                <small>(<?= (int) $paging['total'] ?> total)</small>
            </h2>
            <form method="get" class="d-flex align-items-center gap-2 m-0">
                <?php foreach ([
                    'search' => $search, 'session_id' => (string) $sessionId,
                    'term_id' => (string) $termId, 'class_id' => (string) $classId, 'status' => $status,
                ] as $key => $value): ?>
                    <?php if ($value !== ''): ?>
                        <input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <?= renderPerPageSelector($perPage) ?>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table table-hover table-sticky">
                <thead>
                <tr>
                    <th>Student</th>
                    <th>Admission No.</th>
                    <th>Class</th>
                    <th>Session / Term</th>
                    <th class="text-center">Average</th>
                    <th class="text-center">Position</th>
                    <th class="text-center">Status</th>
                    <th class="text-end">Action</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <?= emptyRow(8, 'No report card found for your classes yet. An administrator generates them once every result is approved.') ?>
                <?php endif; ?>

                <?php foreach ($rows as $row): ?>
                    <?php
                    $rowStatus  = (string) $row['status'];
                    $reportId   = (int) $row['id'];
                    $mayComment = teacherCanCommentOnReportCard($teacherId, $row);
                    ?>
                    <tr>
                        <td class="cell-strong"><?= e(trim($row['first_name'] . ' ' . ($row['middle_name'] ?? '') . ' ' . $row['last_name'])) ?></td>
                        <td class="mono"><?= e($row['admission_number']) ?></td>
                        <td><?= e($row['class_name']) ?></td>
                        <td>
                            <?= e($row['session_name']) ?>
                            <br><small class="text-muted"><?= e($row['term_name']) ?></small>
                        </td>
                        <td class="text-center mono">
                            <?= e(rtrim(rtrim(number_format((float) $row['average_score'], 2, '.', ''), '0'), '.')) ?>
                        </td>
                        <td class="text-center">
                            <?php if ($row['position'] === null): ?>
                                <span class="text-muted">&ndash;</span>
                            <?php else: ?>
                                <span class="badge bg-gold text-dark"><?= e(ordinalSuffix((int) $row['position'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge <?= e(reportCardBadgeClass($rowStatus)) ?>"><?= e(ucfirst($rowStatus)) ?></span>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-success"
                               target="_blank" rel="noopener"
                               href="<?= e(assetUrl('admin/print-report-card.php?id=' . $reportId)) ?>">
                                <i class="bi bi-printer"></i>
                            </a>
                        </td>
                    </tr>

                    <tr>
                        <td colspan="8" class="bg-light">
                            <?php if (!$mayComment): ?>
                                <div class="d-flex align-items-center gap-2 small text-muted">
                                    <i class="bi bi-lock-fill"></i>
                                    <?php if ($rowStatus === 'locked'): ?>
                                        This card is locked, so the class teacher's comment
                                        can no longer be changed.
                                    <?php else: ?>
                                        You are not currently allocated to this class, so the
                                        comment cannot be changed from your account.
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <form method="post" class="row g-2 align-items-end" autocomplete="off">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="report_id" value="<?= $reportId ?>">

                                    <div class="col-lg-9">
                                        <label class="form-label small mb-1" for="comment-<?= $reportId ?>">
                                            Class teacher's comment
                                        </label>
                                        <textarea class="form-control form-control-sm" rows="2" maxlength="500"
                                                  id="comment-<?= $reportId ?>" name="teacher_comment"
                                                  placeholder="What this student did well, and what to work on."><?= e((string) ($row['teacher_comment'] ?? '')) ?></textarea>
                                    </div>

                                    <div class="col-lg-3">
                                        <button class="btn btn-sm btn-green w-100" type="submit">
                                            <i class="bi bi-save-fill"></i> Save Comment
                                        </button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= renderPagination($paging, 'report cards') ?>
    </div>

    <p class="mt-3 mb-4 text-muted small">
        <i class="bi bi-shield-lock me-1"></i>
        The comment box is the only thing on this page that writes anything. Scores,
        positions, promotion status and the approval state belong to the
        administrator, and a locked card accepts no change from anyone.
    </p>
<?php endif; ?>

<?php require __DIR__ . '/../includes/teacher_bottom.php'; ?>
