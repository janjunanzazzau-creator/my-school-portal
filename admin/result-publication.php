<?php

declare(strict_types=1);

/**
 * Result publication control.
 *
 * Approving a report card is a school decision. Publishing it is a separate,
 * deliberate one: it is the switch that makes that result reachable by anybody
 * holding the right PIN, with no login at all.
 *
 * The two are kept apart on purpose. A school approves a term's marking while
 * the marking is still being checked over, and only publishes when it is willing
 * to stand behind it in front of parents. Publishing can be withdrawn just as
 * quickly, which matters when something is discovered the morning after.
 *
 * Before a class is published the screen refuses unless every enrolled student's
 * card for that term exists and is approved or locked, and it names the students
 * that are holding it back. The same check runs again inside
 * applyResultPublication() at the moment of the change, so a card taken back to
 * 'generated' in another browser cannot slip through on a stale page.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

/* --- Inputs ----------------------------------------------------------------- */
$sessionId = queryInt('session_id');
$termId    = queryInt('term_id');
$classId   = queryInt('class_id');
$status    = (string) ($_GET['status'] ?? '');
$page      = queryInt('page') ?? 1;

if (!inList($status, resultPublicationStatuses())) {
    $status = '';
}

$sessions = sessionOptions();

if ($sessionId === null && $sessions !== []) {
    $sessionId = (int) $sessions[0]['id'];
}

$terms   = $sessionId === null ? [] : sessionTermOptions($sessionId);
$classes = classOptions();

/* The class under inspection, and whether it is ready to publish. */
$focusClass = $classId;
$readyCheck = $focusClass !== null && $sessionId !== null && $termId !== null && $termId > 0
    ? validateResultPublication($focusClass, $sessionId, $termId)
    : null;

$currentStatus = $focusClass !== null && $sessionId !== null && $termId !== null && $termId > 0
    ? resultPublicationStatus($sessionId, $termId, $focusClass)
    : 'unpublished';

/* --- Actions ---------------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_FIELD_NAME] ?? null;

    /* Read once, at the top, so the CSRF refusal and the action below cannot
       drift apart about which class was being acted on. */
    $postSession = queryPostId('session_id') ?? 0;
    $postTerm    = queryPostId('term_id') ?? 0;
    $postClass   = queryPostId('class_id') ?? 0;

    $backTo = 'admin/result-publication.php?session_id=' . $postSession
        . '&term_id=' . $postTerm . '&class_id=' . $postClass;

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect($backTo);
    }

    $outcome = applyResultPublication($postClass, $postSession, $postTerm, field('action', 20));

    setFlash($outcome['ok'] ? 'success' : 'error', $outcome['message']);

    redirect($backTo);
}

/* --- The list ---------------------------------------------------------------- */

$listing = listResultPublications([
    'session_id' => $sessionId,
    'term_id'    => $termId,
    'class_id'   => $classId,
    'status'     => $status,
    'page'       => $page,
]);

$rows   = $listing['rows'];
$paging = $listing['paging'];
$counts = resultPublicationCounts(['session_id' => $sessionId]);

$pageTitle    = 'Result Publication';
$pageHeading  = 'Result Publication';
$pageSubtitle = 'Decide which classes and terms the public result checker may show.';
$activeMenu   = 'publication';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn-sm btn-green" href="<?= e(assetUrl('admin/result-pins.php')) ?>">
            <i class="bi bi-key-fill"></i> Result PINs
        </a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('result-checker.php')) ?>" target="_blank" rel="noopener">
            <i class="bi bi-box-arrow-up-right"></i> Public Checker
        </a>
    </div>
</div>

<div class="row g-3 mb-3">
    <?php foreach ([
        ['label' => 'Published Classes',   'value' => $counts['published'],   'colour' => 'var(--green)', 'icon' => 'bi-megaphone-fill'],
        ['label' => 'Not Published',       'value' => $counts['unpublished'], 'colour' => '#5b6b60',       'icon' => 'bi-megaphone'],
        ['label' => 'Class/Term Records',  'value' => $counts['total'],       'colour' => '#1f6f8b',       'icon' => 'bi-list-check'],
    ] as $card): ?>
        <div class="col-6 col-md-4">
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

<div class="card form-card mb-3">
    <div class="form-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-sliders text-green"></i> Choose A Class And Term
        </h2>
    </div>

    <div class="form-card__body">
        <form method="get" class="row g-3 align-items-end" autocomplete="off"
              action="<?= e(assetUrl('admin/result-publication.php')) ?>">
            <div class="col-md-3">
                <label class="form-label" for="session_id">Academic Session</label>
                <select class="form-select" id="session_id" name="session_id">
                    <?php foreach ($sessions as $option): ?>
                        <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $sessionId, (string) $option['id']) ?>>
                            <?= e($option['session_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label" for="term_id">Term</label>
                <select class="form-select" id="term_id" name="term_id">
                    <option value="">All terms</option>
                    <?php foreach ($terms as $option): ?>
                        <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $termId, (string) $option['id']) ?>>
                            <?= e($option['term_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label" for="class_id">Class</label>
                <select class="form-select" id="class_id" name="class_id">
                    <option value="">All classes</option>
                    <?php foreach ($classes as $option): ?>
                        <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $classId, (string) $option['id']) ?>>
                            <?= e($option['class_name']) ?> (<?= e($option['section']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <div class="d-flex gap-2">
                    <button class="btn btn-green flex-grow-1" type="submit">
                        <i class="bi bi-funnel-fill"></i> Apply
                    </button>
                    <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/result-publication.php')) ?>">
                        Reset
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($readyCheck !== null): ?>
    <div class="card table-card mb-3">
        <div class="table-card__head">
            <h2 class="table-card__title">
                <i class="bi bi-<?= $currentStatus === 'published' ? 'megaphone-fill text-green' : 'megaphone' ?>"></i>
                This Class And Term
                <small>
                    <?= e($readyCheck['total']) ?> enrolled &middot;
                    <?= (int) $readyCheck['approved'] ?> approved &middot;
                    <?= (int) $readyCheck['locked'] ?> locked
                </small>
            </h2>
            <span class="badge fs-6 <?= e(resultPublicationBadgeClass($currentStatus)) ?>">
                <?= e(ucfirst($currentStatus)) ?>
            </span>
        </div>

        <div class="table-card__body">
            <div class="alert alert-<?= $readyCheck['ready'] ? ($currentStatus === 'published' ? 'success' : 'info') : 'warning' ?>">
                <i class="bi bi-info-circle-fill"></i>
                <?= e($readyCheck['ready']
                    ? $readyCheck['message'] . ' The public result checker will accept a valid PIN for these results.'
                    : $readyCheck['message'] . ' Nothing in this class can be published until that is fixed.') ?>
            </div>

            <?php if ($readyCheck['pending'] !== []): ?>
                <details class="mb-3">
                    <summary class="text-danger">
                        <?= count($readyCheck['pending']) ?> student(s) holding this class back
                    </summary>
                    <ul class="mt-2 mb-0 small">
                        <?php foreach ($readyCheck['pending'] as $name): ?>
                            <li><?= e($name) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>

            <div class="d-flex flex-wrap gap-2">
                <?php if ($currentStatus === 'published'): ?>
                    <form method="post" action="<?= e(assetUrl('admin/result-publication.php')) ?>">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="unpublish">
                        <input type="hidden" name="session_id" value="<?= (int) $sessionId ?>">
                        <input type="hidden" name="term_id" value="<?= (int) $termId ?>">
                        <input type="hidden" name="class_id" value="<?= (int) $focusClass ?>">
                        <button class="btn btn-outline-danger" type="submit"
                                data-confirm="Unpublish this class and term? Any parent holding a result PIN will stop being able to open the result straight away.">
                            <i class="bi bi-megaphone"></i> Unpublish Results
                        </button>
                    </form>
                <?php else: ?>
                    <form method="post" action="<?= e(assetUrl('admin/result-publication.php')) ?>">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="publish">
                        <input type="hidden" name="session_id" value="<?= (int) $sessionId ?>">
                        <input type="hidden" name="term_id" value="<?= (int) $termId ?>">
                        <input type="hidden" name="class_id" value="<?= (int) $focusClass ?>">
                        <button class="btn btn-green" type="submit"<?= $readyCheck['ready'] ? '' : ' disabled' ?>
                                data-confirm="Publish this class and term to the public result checker? From this moment any parent with a valid result PIN can open these results.">
                            <i class="bi bi-megaphone-fill"></i> Publish Results
                        </button>
                    </form>
                <?php endif; ?>

                <a class="btn btn-outline-secondary"
                   href="<?= e(assetUrl('admin/report-cards.php?session_id=' . (int) $sessionId . '&amp;term_id=' . (int) $termId . '&amp;class_id=' . (int) $focusClass)) ?>">
                    <i class="bi bi-file-earmark-text-fill"></i> View These Report Cards
                </a>
            </div>

            <?php if (!$readyCheck['ready']): ?>
                <p class="mt-3 mb-0 small text-muted">
                    <i class="bi bi-info-circle me-1"></i>
                    The button is disabled rather than hidden, so it is clear why this
                    class cannot be published yet.
                </p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<form method="get" class="filter-bar" autocomplete="off">
    <div class="filter-bar__row">
        <input type="hidden" name="session_id" value="<?= (int) $sessionId ?>">
        <input type="hidden" name="term_id" value="<?= (int) $termId ?>">

        <select class="form-select w-auto" name="status" aria-label="Filter by publication status">
            <option value="">All states</option>
            <?php foreach (resultPublicationStatuses() as $option): ?>
                <option value="<?= e($option) ?>"<?= isSelected($status, $option) ?>>
                    <?= e(ucfirst($option)) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button class="btn btn-green" type="submit"><i class="bi bi-funnel-fill"></i> Filter</button>
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/result-publication.php')) ?>">Reset</a>
        <a class="btn btn-sm btn-outline-primary no-print" href="<?= e(assetUrl('admin/print-result-publication.php' . '?' . currentQueryString())) ?>" target="_blank" rel="noopener">
            <i class="bi bi-printer-fill"></i> Print
        </a>
    </div>
</form>

<div class="table-card">
    <div class="table-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-megaphone-fill text-green"></i> Publication State
            <small>(<?= (int) $paging['total'] ?> class and term <?= (int) $paging['total'] === 1 ? 'record' : 'records' ?>)</small>
        </h2>
        <form method="get" class="d-flex align-items-center gap-2 m-0">
            <?php foreach ([
                'session_id' => (string) $sessionId, 'term_id' => (string) $termId,
                'class_id' => (string) $classId, 'status' => $status,
            ] as $key => $value): ?>
                <?php if ($value !== ''): ?>
                    <input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <?= renderPerPageSelector(resolvePerPage()) ?>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover table-sticky">
            <thead>
            <tr>
                <th>Session</th>
                <th>Term</th>
                <th>Class</th>
                <th class="text-center">Cards</th>
                <th class="text-center">State</th>
                <th>Published</th>
                <th>Last Change</th>
                <th class="text-end">Action</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <?= emptyRow(8, 'Nothing has been published yet. Choose a class and term above.') ?>
            <?php endif; ?>

            <?php foreach ($rows as $row): ?>
                <?php $rowStatus = (string) $row['status']; ?>
                <tr class="<?= $classId !== null && (int) $row['class_id'] === $classId ? 'table-active' : '' ?>">
                    <td><?= e($row['session_name']) ?></td>
                    <td><?= e($row['term_name']) ?></td>
                    <td>
                        <?= e($row['class_name']) ?>
                        <?= sectionBadge((string) $row['section']) ?>
                    </td>
                    <td class="text-center mono"><?= (int) $row['card_count'] ?></td>
                    <td class="text-center">
                        <span class="badge <?= e(resultPublicationBadgeClass($rowStatus)) ?>">
                            <?= e(ucfirst($rowStatus)) ?>
                        </span>
                    </td>
                    <td class="mono small">
                        <?= $row['published_at'] === null
                            ? '<span class="text-muted">Never</span>'
                            : e(substr((string) $row['published_at'], 0, 16)) ?>
                    </td>
                    <td class="mono small"><?= e(substr((string) $row['updated_at'], 0, 16)) ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-secondary"
                           href="<?= e(assetUrl('admin/result-publication.php?session_id=' . (int) $row['session_id'] . '&amp;term_id=' . (int) $row['term_id'] . '&amp;class_id=' . (int) $row['class_id'])) ?>">
                            <i class="bi bi-eye"></i> Open
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= renderPagination($paging, 'publication records') ?>
</div>

<div class="card info-card mt-3 mb-4">
    <div class="info-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-shield-lock text-green"></i> What Publishing Actually Does
        </h2>
    </div>
    <div class="info-card__body">
        <div class="row g-3">
            <div class="col-md-6">
                <h3 class="h6">Publishing is one of ten checks</h3>
                <p class="small mb-0">
                    Before a parent sees a result, the portal confirms the admission
                    number is real, the PIN belongs to that student in that session, the
                    PIN is active, not expired, not blocked and not revoked, the class and
                    term are published, and the report card is approved or locked. Failing
                    any one of them shows nothing at all.
                </p>
            </div>
            <div class="col-md-6">
                <h3 class="h6">Approving is not publishing</h3>
                <p class="small mb-0">
                    An approved report card is signed off but still private to staff.
                    Publishing is the extra, deliberate step that puts it in front of
                    parents, and it can be withdrawn again at any time without touching
                    the card itself.
                </p>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
