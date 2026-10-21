<?php

declare(strict_types=1);

/**
 * Academic Session Management.
 *
 * Sessions are never deleted: a session that carries terms, assignments or
 * enrolments is part of the academic record, so it is closed or deactivated
 * instead.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

/* --- Actions (POST only, CSRF protected) -------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = field('action', 30);
    $token     = $_POST[CSRF_FIELD_NAME] ?? null;
    $sessionId = queryPostId('session_id');
    $backQuery = currentQueryString() !== '' ? '?' . currentQueryString() : '';

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/sessions.php');
    }

    $session = $sessionId === null ? null : findSession($sessionId);

    if ($session === null) {
        setFlash('error', 'The selected academic session could not be found.');
        redirect('admin/sessions.php' . $backQuery);
    }

    if ($action === 'set_current') {
        try {
            setCurrentSession($sessionId);
            setFlash('success', 'Academic session ' . $session['session_name'] . ' is now the current session.');
        } catch (Throwable $exception) {
            error_log('[PHASE3] Unable to set current session: ' . $exception->getMessage());
            setFlash('error', 'The current session could not be changed. Please try again.');
        }
    } elseif (inList($action, SESSION_STATUSES)) {
        try {
            setSessionStatus($sessionId, $action);
            setFlash('success', 'Academic session ' . $session['session_name'] . ' was marked ' . strtolower($action) . '.');
        } catch (Throwable $exception) {
            error_log('[PHASE3] Unable to change session status: ' . $exception->getMessage());
            setFlash('error', 'The session status could not be changed. Please try again.');
        }
    }

    redirect('admin/sessions.php' . $backQuery);
}

/* --- Filters ------------------------------------------------------------- */
$search  = trim((string) ($_GET['search'] ?? ''));
$status  = (string) ($_GET['status'] ?? '');
$perPage = resolvePerPage();
$page    = queryInt('page') ?? 1;

if (!inList($status, SESSION_STATUSES)) {
    $status = '';
}

$filters = [
    'search'   => $search,
    'status'   => $status,
    'per_page' => $perPage,
];

$paging   = paginate(listSessions($filters + ['offset' => 0])['total'], $perPage, $page);
$sessions = listSessions($filters + ['offset' => $paging['offset']])['rows'];

$pageTitle    = 'Academic Sessions';
$pageHeading  = 'Academic Session Management';
$pageSubtitle = 'Add, edit, close and choose the current academic session.';
$activeMenu   = 'sessions';
$pageActions  = '<a class="btn btn-sm btn-green" href="' . e(assetUrl('admin/session-add.php')) . '">'
    . '<i class="bi bi-plus-circle-fill"></i> Add Session</a>';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions"><?= $pageActions ?></div>
</div>

<form method="get" class="filter-bar" autocomplete="off">
    <div class="filter-bar__row">
        <div class="input-group filter-bar__search">
            <span class="input-group-text bg-white"><i class="bi bi-search text-green"></i></span>
            <input type="search" class="form-control" name="search" value="<?= e($search) ?>"
                   placeholder="Session name or year">
        </div>

        <select class="form-select w-auto" name="status" aria-label="Filter by status">
            <option value="">All statuses</option>
            <?php foreach (SESSION_STATUSES as $option): ?>
                <option value="<?= e($option) ?>"<?= isSelected($status, $option) ?>><?= e($option) ?></option>
            <?php endforeach; ?>
        </select>

        <button class="btn btn-green" type="submit"><i class="bi bi-funnel-fill"></i> Filter</button>
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/sessions.php')) ?>">Reset</a>
    </div>
</form>

<div class="table-card">
    <div class="table-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-calendar-range-fill text-green"></i> Academic Sessions
            <small>(<?= (int) $paging['total'] ?> total)</small>
        </h2>
        <form method="get" class="d-flex align-items-center gap-2 m-0">
            <?php foreach (['search' => $search, 'status' => $status] as $key => $value): ?>
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
                <th>Session</th>
                <th>Start Date</th>
                <th>End Date</th>
                <th>Terms</th>
                <th>Enrolments</th>
                <th>Status</th>
                <th>Current</th>
                <th class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($sessions === []): ?>
                <?= emptyRow(8, 'No academic sessions found. Add a session to begin.') ?>
            <?php endif; ?>

            <?php foreach ($sessions as $session): ?>
                <tr>
                    <td class="cell-strong"><?= e($session['session_name']) ?></td>
                    <td class="mono"><?= e($session['start_date']) ?></td>
                    <td class="mono"><?= e($session['end_date']) ?></td>
                    <td><?= (int) $session['term_count'] ?></td>
                    <td><?= (int) $session['enrollment_count'] ?></td>
                    <td><?= statusBadge((string) $session['status']) ?></td>
                    <td>
                        <?php if ((int) $session['is_current'] === 1): ?>
                            <span class="badge bg-gold">Current</span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="table-actions justify-content-end">
                            <?php if ((int) $session['is_current'] !== 1): ?>
                                <form method="post" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="set_current">
                                    <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
                                    <button class="btn btn-sm btn-gold" data-confirm="Set <?= e($session['session_name']) ?> as the current academic session? Any other current session will lose the flag."
                                            title="Set as current session">
                                        <i class="bi bi-star-fill"></i>
                                    </button>
                                </form>
                            <?php endif; ?>

                            <a class="btn btn-sm btn-outline-primary" title="Edit"
                               href="<?= e(assetUrl('admin/session-edit.php?id=' . (int) $session['id'])) ?>">
                                <i class="bi bi-pencil"></i>
                            </a>

                            <a class="btn btn-sm btn-outline-secondary" title="Manage terms"
                               href="<?= e(assetUrl('admin/terms.php?session_id=' . (int) $session['id'])) ?>">
                                <i class="bi bi-layers"></i>
                            </a>

                            <?php if ((string) $session['status'] === 'Active'): ?>
                                <form method="post" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="Closed">
                                    <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
                                    <button class="btn btn-sm btn-outline-dark" data-confirm="Close <?= e($session['session_name']) ?>? The session can no longer be the current session."
                                            title="Close session">
                                        <i class="bi bi-lock-fill"></i>
                                    </button>
                                </form>
                            <?php else: ?>
                                <form method="post" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="Active">
                                    <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
                                    <button class="btn btn-sm btn-success" data-confirm="Reactivate <?= e($session['session_name']) ?>?"
                                            title="Reactivate session">
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

    <?= renderPagination($paging, 'sessions') ?>
</div>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
