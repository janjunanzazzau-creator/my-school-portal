<?php

declare(strict_types=1);

/**
 * Term Management. A term always belongs to exactly one academic session.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

/* --- Actions (POST only, CSRF protected) -------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = field('action', 30);
    $token    = $_POST[CSRF_FIELD_NAME] ?? null;
    $termId   = queryPostId('term_id');
    $backQuery = currentQueryString() !== '' ? '?' . currentQueryString() : '';

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/terms.php');
    }

    $term = $termId === null ? null : findTerm($termId);

    if ($term === null) {
        setFlash('error', 'The selected term could not be found.');
        redirect('admin/terms.php' . $backQuery);
    }

    if ($action === 'set_current') {
        try {
            $error = setCurrentTerm($termId);

            if ($error === '') {
                setFlash('success', $term['term_name'] . ' is now the current term.');
            } else {
                setFlash('error', $error);
            }
        } catch (Throwable $exception) {
            error_log('[PHASE3] Unable to set current term: ' . $exception->getMessage());
            setFlash('error', 'The current term could not be changed. Please try again.');
        }
    } elseif (inList($action, TERM_STATUSES)) {
        try {
            setTermStatus($termId, $action);
            setFlash('success', $term['term_name'] . ' was marked ' . strtolower($action) . '.');
        } catch (Throwable $exception) {
            error_log('[PHASE3] Unable to change term status: ' . $exception->getMessage());
            setFlash('error', 'The term status could not be changed. Please try again.');
        }
    }

    redirect('admin/terms.php' . $backQuery);
}

/* --- Filters ------------------------------------------------------------- */
$search    = trim((string) ($_GET['search'] ?? ''));
$sessionId = queryInt('session_id');
$termName  = (string) ($_GET['term_name'] ?? '');
$status    = (string) ($_GET['status'] ?? '');
$perPage   = resolvePerPage();
$page      = queryInt('page') ?? 1;

if (!inList($termName, TERM_NAMES)) {
    $termName = '';
}
if (!inList($status, TERM_STATUSES)) {
    $status = '';
}

$filters = [
    'search'     => $search,
    'session_id' => $sessionId,
    'term_name'  => $termName,
    'status'     => $status,
    'per_page'   => $perPage,
];

$paging = paginate(listTerms($filters + ['offset' => 0])['total'], $perPage, $page);
$terms  = listTerms($filters + ['offset' => $paging['offset']])['rows'];

$sessions      = sessionOptions();
$currentSession = currentSession();

$pageTitle    = 'Terms';
$pageHeading  = 'Term Management';
$pageSubtitle = 'First Term, Second Term and Third Term for each academic session.';
$activeMenu   = 'terms';
$pageActions  = '<a class="btn btn-sm btn-green" href="' . e(assetUrl('admin/term-add.php')) . '">'
    . '<i class="bi bi-plus-circle-fill"></i> Add Term</a>';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions"><?= $pageActions ?></div>
</div>

<?php if ($sessions === []): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span>
            No academic session exists yet. A term must belong to a session, so
            <a href="<?= e(assetUrl('admin/session-add.php')) ?>">create a session first</a>.
        </span>
    </div>
<?php endif; ?>

<form method="get" class="filter-bar" autocomplete="off">
    <div class="filter-bar__row">
        <div class="input-group filter-bar__search">
            <span class="input-group-text bg-white"><i class="bi bi-search text-green"></i></span>
            <input type="search" class="form-control" name="search" value="<?= e($search) ?>"
                   placeholder="Term name or session">
        </div>

        <select class="form-select w-auto" name="session_id" aria-label="Filter by academic session">
            <option value="">All sessions</option>
            <?php foreach ($sessions as $option): ?>
                <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $sessionId, (string) $option['id']) ?>>
                    <?= e($option['session_name']) ?><?= (int) $option['is_current'] === 1 ? ' (current)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select class="form-select w-auto" name="term_name" aria-label="Filter by term">
            <option value="">All terms</option>
            <?php foreach (TERM_NAMES as $option): ?>
                <option value="<?= e($option) ?>"<?= isSelected($termName, $option) ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>

        <select class="form-select w-auto" name="status" aria-label="Filter by status">
            <option value="">All statuses</option>
            <?php foreach (TERM_STATUSES as $option): ?>
                <option value="<?= e($option) ?>"<?= isSelected($status, $option) ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>

        <button class="btn btn-green" type="submit"><i class="bi bi-funnel-fill"></i> Filter</button>
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/terms.php')) ?>">Reset</a>
    </div>
</form>

<div class="table-card">
    <div class="table-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-layers-fill text-green"></i> Term Records
            <small>(<?= (int) $paging['total'] ?> total)</small>
        </h2>
        <form method="get" class="d-flex align-items-center gap-2 m-0">
            <?php foreach ([
                'search' => $search, 'session_id' => (string) $sessionId,
                'term_name' => $termName, 'status' => $status,
            ] as $key => $value): ?>
                <?php if ($value !== ''): ?>
                    <input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>">
                <?php endif; ?>
            <?php endforeach; ?>
            <?= renderPerPageSelector($perPage) ?>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
            <tr>
                <th>Academic Session</th>
                <th>Term</th>
                <th>Start Date</th>
                <th>End Date</th>
                <th>Status</th>
                <th>Current</th>
                <th class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($terms === []): ?>
                <?= emptyRow(7, 'No terms found. Adjust the filters or add a term.') ?>
            <?php endif; ?>

            <?php foreach ($terms as $term): ?>
                <tr>
                    <td class="cell-strong">
                        <?= e($term['session_name']) ?>
                        <?php if ((int) $term['session_is_current'] === 1): ?>
                            <span class="badge bg-gold">Current session</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($term['term_name']) ?></td>
                    <td class="mono"><?= e($term['start_date']) ?></td>
                    <td class="mono"><?= e($term['end_date']) ?></td>
                    <td><?= statusBadge((string) $term['status']) ?></td>
                    <td>
                        <?php if ((int) $term['is_current'] === 1): ?>
                            <span class="badge bg-gold">Current</span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="table-actions justify-content-end">
                            <?php if ((int) $term['is_current'] !== 1 && (int) $term['session_is_current'] === 1): ?>
                                <form method="post" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="set_current">
                                    <input type="hidden" name="term_id" value="<?= (int) $term['id'] ?>">
                                    <button class="btn btn-sm btn-gold" data-confirm="Set <?= e($term['term_name']) ?> of <?= e($term['session_name']) ?> as the current term?"
                                            title="Set as current term">
                                        <i class="bi bi-star-fill"></i>
                                    </button>
                                </form>
                            <?php elseif ((int) $term['is_current'] !== 1): ?>
                                <button class="btn btn-sm btn-outline-secondary" disabled
                                        title="Only a term of the current academic session can become the current term">
                                    <i class="bi bi-star"></i>
                                </button>
                            <?php endif; ?>

                            <a class="btn btn-sm btn-outline-primary" title="Edit"
                               href="<?= e(assetUrl('admin/term-edit.php?id=' . (int) $term['id'])) ?>">
                                <i class="bi bi-pencil"></i>
                            </a>

                            <?php if ((string) $term['status'] === 'Active'): ?>
                                <form method="post" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="Closed">
                                    <input type="hidden" name="term_id" value="<?= (int) $term['id'] ?>">
                                    <button class="btn btn-sm btn-outline-dark" data-confirm="Close <?= e($term['term_name']) ?>?"
                                            title="Close term">
                                        <i class="bi bi-lock-fill"></i>
                                    </button>
                                </form>
                            <?php else: ?>
                                <form method="post" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="Active">
                                    <input type="hidden" name="term_id" value="<?= (int) $term['id'] ?>">
                                    <button class="btn btn-sm btn-success" data-confirm="Reactivate <?= e($term['term_name']) ?>?"
                                            title="Reactivate term">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= renderPagination($paging, 'terms') ?>
</div>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
