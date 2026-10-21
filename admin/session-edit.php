<?php

declare(strict_types=1);

/**
 * Edit an academic session.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

$sessionId = queryInt('id');
$session   = $sessionId === null ? null : findSession($sessionId);

if ($session === null) {
    setFlash('error', 'The selected academic session could not be found.');
    redirect('admin/sessions.php');
}

$errors = [];
$values = [
    'session_name' => (string) $session['session_name'],
    'start_date'   => (string) $session['start_date'],
    'end_date'     => (string) $session['end_date'],
    'status'       => (string) $session['status'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token  = $_POST[CSRF_FIELD_NAME] ?? null;
    $action = field('action', 30);

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/session-edit.php?id=' . (int) $sessionId);
    }

    if ($action === 'set_current') {
        try {
            setCurrentSession($sessionId);
            setFlash('success', 'Academic session ' . $session['session_name'] . ' is now the current session.');
            redirect('admin/session-edit.php?id=' . (int) $sessionId);
        } catch (Throwable $exception) {
            error_log('[PHASE3] Unable to set current session: ' . $exception->getMessage());
            setFlash('error', 'The current session could not be changed. Please try again.');
            redirect('admin/session-edit.php?id=' . (int) $sessionId);
        }
    }

    [$data, $errors] = validateSessionInput($sessionId);
    $values = mergeFormValues($values, $data);

    if ($errors === []) {
        try {
            updateSession($sessionId, $data);

            if ($data['status'] !== 'Active' && (int) $session['is_current'] === 1) {
                setSessionStatus($sessionId, $data['status']);
            }

            setFlash('success', 'Academic session updated successfully.');
            redirect('admin/session-edit.php?id=' . (int) $sessionId);
        } catch (Throwable $exception) {
            error_log('[PHASE3] Unable to update session: ' . $exception->getMessage());
            $errors['form'] = 'The academic session could not be saved. Please try again.';
        }
    }
}

$terms  = listTerms([
    'session_id' => $sessionId,
    'per_page'   => 10,
    'offset'     => 0,
])['rows'];

$pageTitle    = 'Edit Session';
$pageHeading  = 'Edit Academic Session';
$pageSubtitle = $session['session_name'];
$activeMenu   = 'sessions';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('admin/terms.php?session_id=' . (int) $sessionId)) ?>">
            <i class="bi bi-layers"></i> Manage Terms
        </a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('admin/sessions.php')) ?>">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>
</div>

<?php if (isset($errors['form'])): ?>
    <div class="alert alert-danger"><?= e($errors['form']) ?></div>
<?php endif; ?>

<?php if ((int) $session['is_current'] === 1): ?>
    <div class="alert alert-success d-flex align-items-center gap-2">
        <i class="bi bi-star-fill"></i>
        <span>This is the current academic session.</span>
    </div>
<?php else: ?>
    <form method="post" class="mb-3">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="set_current">
        <button class="btn btn-gold" data-confirm="Set <?= e($session['session_name']) ?> as the current academic session?">
            <i class="bi bi-star-fill"></i> Set as Current Session
        </button>
    </form>
<?php endif; ?>

<form method="post" data-validate novalidate>
    <?= csrfField() ?>
    <input type="hidden" name="action" value="update">

    <div class="form-card">
        <div class="form-card__head"><i class="bi bi-calendar-range text-green"></i> Session Details</div>
        <div class="form-card__body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label required" for="session_name">Session Name</label>
                    <input class="<?= inputClass($errors, 'session_name') ?>" type="text"
                           id="session_name" name="session_name" maxlength="20" required
                           value="<?= e($values['session_name']) ?>">
                    <?= inlineError($errors, 'session_name') ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label required" for="status">Status</label>
                    <select class="<?= inputClass($errors, 'status') ?>" id="status" name="status" required>
                        <?php foreach (SESSION_STATUSES as $option): ?>
                            <option value="<?= e($option) ?>"<?= isSelected($values['status'], $option) ?>><?= e($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= inlineError($errors, 'status') ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label required" for="start_date">Start Date</label>
                    <input class="<?= inputClass($errors, 'start_date') ?>" type="date"
                           id="start_date" name="start_date" required
                           value="<?= e($values['start_date']) ?>">
                    <?= inlineError($errors, 'start_date') ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label required" for="end_date">End Date</label>
                    <input class="<?= inputClass($errors, 'end_date') ?>" type="date"
                           id="end_date" name="end_date" required
                           value="<?= e($values['end_date']) ?>">
                    <?= inlineError($errors, 'end_date') ?>
                </div>
            </div>

            <div class="form-text mt-3">
                Closing a session keeps every term, assignment and enrolment. Sessions are never deleted.
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 mb-4">
        <button class="btn btn-green" type="submit"><i class="bi bi-check-circle-fill"></i> Save Changes</button>
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/sessions.php')) ?>">Cancel</a>
    </div>
</form>

<div class="table-card">
    <div class="table-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-layers-fill text-green"></i> Terms In This Session
            <small>(<?= count($terms) ?>)</small>
        </h2>
        <a class="btn btn-sm btn-outline-secondary"
           href="<?= e(assetUrl('admin/term-add.php?session_id=' . (int) $sessionId)) ?>">
            Add Term
        </a>
    </div>

    <div class="table-responsive">
        <table class="table table-hover">
            <thead>
            <tr>
                <th>Term</th>
                <th>Start Date</th>
                <th>End Date</th>
                <th>Status</th>
                <th>Current</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($terms === []): ?>
                <?= emptyRow(5, 'No term has been created for this academic session.') ?>
            <?php endif; ?>

            <?php foreach ($terms as $term): ?>
                <tr>
                    <td class="cell-strong"><?= e($term['term_name']) ?></td>
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
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
