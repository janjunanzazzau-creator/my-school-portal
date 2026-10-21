<?php

declare(strict_types=1);

/**
 * Result PIN administration.
 *
 * Every PIN the school has ever issued, with the reference, who it belongs to,
 * what state it is in and when it was last used - and never the PIN or its hash.
 * The hash is not even selected by listResultPins(), so no filter or column
 * choice on this screen can put it into a response.
 *
 * The screen is read only apart from four state changes, which are POSTs handled
 * here and carried out in applyResultPinAction():
 *
 *   revoke   - terminal. The number is dead and cannot be revived.
 *   block    - stops the PIN now; an administrator can clear it again.
 *   unblock  - clears the failed attempt counter and makes the PIN work again.
 *   extend   - moves the expiry date forward.
 *
 * A fifth action, issuing a replacement PIN, is a page of its own because it has
 * to show the new secret exactly once: admin/result-pin-generate.php.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

/* Bring the list and the tiles in line with the clock before anything is read. */
sweepExpiredResultPins();

/* --- Filters ---------------------------------------------------------------- */
$search    = trim((string) ($_GET['search'] ?? ''));
$sessionId = queryInt('session_id');
$classId   = queryInt('class_id');
$status    = (string) ($_GET['status'] ?? '');
$page      = queryInt('page') ?? 1;

if (!inList($status, resultPinStatuses())) {
    $status = '';
}

$returnQuery = currentQueryString() !== '' ? '?' . currentQueryString() : '';

/* --- Actions ---------------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST[CSRF_FIELD_NAME] ?? null;

    if (!verifyCsrfToken(is_string($token) ? $token : null)) {
        setFlash('error', 'Your session expired. Please try again.');
        redirect('admin/result-pins.php' . $returnQuery);
    }

    $pinId  = queryPostId('pin_id');
    $action = field('action', 20);

    $outcome = ['ok' => false, 'message' => 'That action is not available.'];

    if ($pinId !== null && in_array($action, ['revoke', 'block', 'unblock', 'extend'], true)) {
        $outcome = applyResultPinAction($pinId, $action, [
            'reason'     => $_POST['reason'] ?? '',
            'expires_at' => field('expires_at', 10),
        ]);
    } elseif ($pinId !== null && $action === 'regenerate') {
        /* Reissuing is the same code path as issuing, so the old PIN is retired
           and the new one is hashed the same way. */
        $existing = findResultPin($pinId);

        if ($existing === null) {
            $outcome = ['ok' => false, 'message' => 'That result PIN no longer exists.'];
        } else {
            $issued = generateResultPin([
                'student_id'   => (int) $existing['student_id'],
                'session_id'   => (int) $existing['session_id'],
                'expires_at'   => date('Y-m-d', strtotime('+30 days')),
                'max_attempts' => (int) $existing['max_attempts'],
                'single_use'   => (int) $existing['single_use'],
            ]);

            $outcome = $issued['ok']
                ? [
                    'ok' => true,
                    'message' => 'A new PIN has been issued and the previous one retired. '
                        . 'The new PIN is ' . (string) $issued['pin'] . ' - write it down now, '
                        . 'it cannot be shown again. Reference ' . (string) $issued['reference'] . '.',
                ]
                : ['ok' => false, 'message' => $issued['message']];
        }
    }

    setFlash($outcome['ok'] ? 'success' : 'error', $outcome['message']);

    redirect('admin/result-pins.php' . $returnQuery);
}

/* --- The list ---------------------------------------------------------------- */

$listing = listResultPins([
    'search'     => $search,
    'session_id' => $sessionId,
    'class_id'   => $classId,
    'status'     => $status,
    'page'       => $page,
]);

$rows     = $listing['rows'];
$paging   = $listing['paging'];
$counts   = resultPinStatusCounts(['session_id' => $sessionId]);
$access   = resultAccessLogCounts();
$sessions = sessionOptions();
$classes  = classOptions();

$pageTitle    = 'Result PINs';
$pageHeading  = 'Result PINs';
$pageSubtitle = 'Issue, search and control the PINs parents use on the public result checker.';
$activeMenu   = 'resultpins';

require __DIR__ . '/../includes/admin_top.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($pageHeading) ?></h1>
        <p><?= e($pageSubtitle) ?></p>
    </div>
    <div class="page-head__actions">
        <a class="btn btn-sm btn-green" href="<?= e(assetUrl('admin/result-pin-generate.php')) ?>">
            <i class="bi bi-key-fill"></i> Generate PIN
        </a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('admin/result-publication.php')) ?>">
            <i class="bi bi-megaphone-fill"></i> Publication
        </a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(assetUrl('result-checker.php')) ?>" target="_blank" rel="noopener">
            <i class="bi bi-box-arrow-up-right"></i> Public Checker
        </a>
    </div>
</div>

<div class="row g-3 mb-3">
    <?php foreach ([
        ['label' => 'Total PINs',   'value' => $counts['total'],   'colour' => 'var(--green)', 'icon' => 'bi-key'],
        ['label' => 'Active',       'value' => $counts['active'],  'colour' => 'var(--gold)',  'icon' => 'bi-check-circle'],
        ['label' => 'Blocked',      'value' => $counts['blocked'], 'colour' => '#b02a37',       'icon' => 'bi-slash-circle'],
        ['label' => 'Expired',      'value' => $counts['expired'], 'colour' => '#5b6b60',       'icon' => 'bi-hourglass'],
        ['label' => 'Revoked',      'value' => $counts['revoked'], 'colour' => '#3a3f58',       'icon' => 'bi-x-octagon'],
        ['label' => 'Access Checks', 'value' => $access['total'],  'colour' => '#1f6f8b',       'icon' => 'bi-people'],
    ] as $card): ?>
        <div class="col-6 col-md-4 col-xl-2">
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

<?php if ($counts['expired'] > 0 || $counts['blocked'] > 0): ?>
    <div class="alert alert-warning d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span class="d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <strong><?= (int) ($counts['expired'] + $counts['blocked']) ?></strong>
            PIN<?= ($counts['expired'] + $counts['blocked']) === 1 ? '' : 's' ?>
            cannot open a result: <?= (int) $counts['expired'] ?> expired,
            <?= (int) $counts['blocked'] ?> blocked.
        </span>
        <a class="btn btn-sm btn-green" href="<?= e(assetUrl('admin/result-pin-generate.php')) ?>">
            <i class="bi bi-arrow-repeat"></i> Issue Replacements
        </a>
    </div>
<?php endif; ?>

<form method="get" class="filter-bar" autocomplete="off">
    <div class="filter-bar__row">
        <div class="input-group filter-bar__search">
            <span class="input-group-text bg-white"><i class="bi bi-search text-green"></i></span>
            <input type="search" class="form-control" name="search" value="<?= e($search) ?>"
                   placeholder="PIN reference, admission number or student name">
        </div>

        <select class="form-select w-auto" name="session_id" aria-label="Filter by academic session">
            <option value="">All sessions</option>
            <?php foreach ($sessions as $option): ?>
                <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $sessionId, (string) $option['id']) ?>>
                    <?= e($option['session_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select class="form-select w-auto" name="class_id" aria-label="Filter by class">
            <option value="">All classes</option>
            <?php foreach ($classes as $option): ?>
                <option value="<?= (int) $option['id'] ?>"<?= isSelected((string) $classId, (string) $option['id']) ?>>
                    <?= e($option['class_name']) ?> (<?= e($option['section']) ?>)
                </option>
            <?php endforeach; ?>
        </select>

        <select class="form-select w-auto" name="status" aria-label="Filter by PIN status">
            <option value="">All statuses</option>
            <?php foreach (resultPinStatuses() as $option): ?>
                <option value="<?= e($option) ?>"<?= isSelected($status, $option) ?>>
                    <?= e(resultPinStatusLabel($option)) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button class="btn btn-green" type="submit"><i class="bi bi-funnel-fill"></i> Filter</button>
        <a class="btn btn-outline-secondary" href="<?= e(assetUrl('admin/result-pins.php')) ?>">Reset</a>
    </div>
</form>

<div class="table-card">
    <div class="table-card__head">
        <h2 class="table-card__title">
            <i class="bi bi-key-fill text-green"></i> Issued Result PINs
            <small>(<?= (int) $paging['total'] ?> total)</small>
        </h2>
        <form method="get" class="d-flex align-items-center gap-2 m-0">
            <?php foreach ([
                'search' => $search, 'session_id' => (string) $sessionId,
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
                <th>PIN Reference</th>
                <th>Student</th>
                <th>Admission No.</th>
                <th>Session</th>
                <th class="text-center">Status</th>
                <th>Expiry</th>
                <th class="text-center">Attempts</th>
                <th>Last Used</th>
                <th>Created</th>
                <th class="text-end">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <?= emptyRow(10, 'No result PIN found. Generate one from a class whose reports are approved.') ?>
            <?php endif; ?>

            <?php foreach ($rows as $row): ?>
                <?php
                $pinStatus  = (string) $row['status'];
                $failed     = (int) $row['failed_attempts'];
                $maxTries   = (int) $row['max_attempts'];
                $isRevoked  = $pinStatus === 'revoked';
                $isBlocked  = $pinStatus === 'blocked';
                $isExpired  = $pinStatus === 'expired';
                ?>
                <tr>
                    <td class="mono cell-strong"><?= e($row['pin_reference']) ?></td>
                    <td>
                        <a class="cell-strong d-block"
                           href="<?= e(assetUrl('admin/student-view.php?id=' . (int) $row['student_id'])) ?>">
                            <?= e(trim($row['first_name'] . ' ' . ($row['middle_name'] ?? '') . ' ' . $row['last_name'])) ?>
                        </a>
                        <?php if ((int) $row['single_use'] === 1): ?>
                            <small class="text-muted">Single use</small>
                        <?php else: ?>
                            <small class="text-muted">Reusable until expiry</small>
                        <?php endif; ?>
                    </td>
                    <td class="mono"><?= e($row['admission_number']) ?></td>
                    <td><?= e($row['session_name']) ?></td>
                    <td class="text-center">
                        <span class="badge <?= e(resultPinBadgeClass($pinStatus)) ?>">
                            <?= e(resultPinStatusLabel($pinStatus)) ?>
                        </span>
                    </td>
                    <td class="mono small">
                        <?= e(substr((string) $row['expires_at'], 0, 10)) ?>
                    </td>
                    <td class="text-center">
                        <?php if ($failed >= $maxTries): ?>
                            <span class="badge bg-danger"><?= $failed ?> / <?= $maxTries ?></span>
                        <?php else: ?>
                            <span class="mono"><?= $failed ?> / <?= $maxTries ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="mono small">
                        <?= $row['last_used_at'] === null
                            ? '<span class="text-muted">Never</span>'
                            : e(substr((string) $row['last_used_at'], 0, 10)) ?>
                    </td>
                    <td class="mono small"><?= e(substr((string) $row['created_at'], 0, 10)) ?></td>
                    <td class="text-end">
                        <div class="table-actions justify-content-end">
                            <a class="btn btn-sm btn-outline-secondary"
                               href="<?= e(assetUrl('admin/result-pin-generate.php?student_id=' . (int) $row['student_id'] . '&amp;session_id=' . (int) $row['session_id'])) ?>"
                               title="Issue a new PIN for this student">
                                <i class="bi bi-arrow-repeat"></i>
                            </a>

                            <?php if (!$isRevoked && !$isBlocked && !$isExpired): ?>
                                <button class="btn btn-sm btn-outline-warning" type="submit"
                                        form="pin-act-<?= (int) $row['id'] ?>" name="action" value="block"
                                        title="Block this PIN"
                                        data-confirm="Block PIN <?= e($row['pin_reference']) ?>? Nobody will be able to open this result with it until you unblock it.">
                                    <i class="bi bi-slash-circle"></i>
                                </button>
                            <?php endif; ?>

                            <?php if ($isBlocked || $isExpired): ?>
                                <button class="btn btn-sm btn-outline-success" type="submit"
                                        form="pin-act-<?= (int) $row['id'] ?>" name="action" value="unblock"
                                        title="Make this PIN work again and clear its failed attempts"
                                        data-confirm="Unblock PIN <?= e($row['pin_reference']) ?>? This also clears its failed attempt counter.">
                                    <i class="bi bi-unlock"></i>
                                </button>
                            <?php endif; ?>

                            <button class="btn btn-sm btn-outline-primary" type="button"
                                    data-extend-toggle="<?= (int) $row['id'] ?>"
                                    title="Extend the expiry date">
                                <i class="bi bi-calendar-plus"></i>
                            </button>

                            <?php if (!$isRevoked): ?>
                                <button class="btn btn-sm btn-outline-danger" type="submit"
                                        form="pin-act-<?= (int) $row['id'] ?>" name="action" value="revoke"
                                        title="Revoke this PIN for good"
                                        data-confirm="Revoke PIN <?= e($row['pin_reference']) ?>? This cannot be undone - a revoked PIN can never work again. Issue a new PIN if the family needs one.">
                                    <i class="bi bi-x-octagon"></i>
                                </button>
                            <?php else: ?>
                                <span class="badge bg-dark">Dead</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>

                <?php /* The extend form carries its own date input, so the row stays
                        closed until somebody actually wants to change a date. */ ?>
                <tr class="d-none" data-extend-row="<?= (int) $row['id'] ?>">
                    <td colspan="10" class="bg-light">
                        <form method="post" class="d-flex flex-wrap align-items-end gap-2 py-2"
                              action="<?= e(assetUrl('admin/result-pins.php' . $returnQuery)) ?>">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="extend">
                            <input type="hidden" name="pin_id" value="<?= (int) $row['id'] ?>">

                            <div>
                                <label class="form-label mb-0 small" for="extend_date_<?= (int) $row['id'] ?>">
                                    New expiry date for <?= e($row['pin_reference']) ?>
                                    <span class="text-muted">(currently <?= e(substr((string) $row['expires_at'], 0, 10)) ?>)</span>
                                </label>
                                <input class="form-control form-control-sm" type="date"
                                       id="extend_date_<?= (int) $row['id'] ?>"
                                       name="expires_at" required
                                       value="<?= e(date('Y-m-d', strtotime((string) $row['expires_at'] . ' +30 days'))) ?>">
                            </div>

                            <button class="btn btn-sm btn-green" type="submit">
                                <i class="bi bi-check-lg"></i> Save new expiry
                            </button>
                        </form>
                    </td>
                </tr>

                <?php /* One hidden form per row: the action buttons above submit
                        into it through the HTML form attribute, which keeps the
                        table free of a form in every cell. */ ?>
                <form method="post" id="pin-act-<?= (int) $row['id'] ?>" class="d-none"
                      action="<?= e(assetUrl('admin/result-pins.php' . $returnQuery)) ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="pin_id" value="<?= (int) $row['id'] ?>">
                </form>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= renderPagination($paging, 'result PINs') ?>
</div>

<div class="row g-3 mt-1 mb-4">
    <div class="col-lg-6">
        <div class="card table-card">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-shield-lock text-green"></i> How A Result PIN Behaves
                </h2>
            </div>
            <div class="table-card__body">
                <p class="mb-2">
                    Only the <strong>hash</strong> of a PIN is stored, so the PIN itself
                    cannot be read back out of the database. The one time it is shown is
                    the page immediately after it is generated.
                </p>
                <p class="mb-0 small text-muted">
                    If a family loses their PIN, issue a new one. Doing so retires the old
                    PIN immediately, so there is never more than one PIN working for a
                    student in a session. The
                    <span class="mono">PIN-<?= e(resultPinReferenceYear()) ?>-000000</span>
                    reference is the safe thing to read out on the phone: it identifies the
                    PIN without revealing it.
                </p>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card table-card">
            <div class="table-card__head">
                <h2 class="table-card__title">
                    <i class="bi bi-activity text-green"></i> Recent Public Access Attempts
                    <small>last 30 days</small>
                </h2>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                    <tr>
                        <th>When</th>
                        <th>Student</th>
                        <th>Outcome</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $recent = recentResultAccessLogs(8); ?>
                    <?php if ($recent === []): ?>
                        <?= emptyRow(3, 'No one has used the public result checker yet.') ?>
                    <?php endif; ?>

                    <?php foreach ($recent as $entry): ?>
                        <tr>
                            <td class="mono small"><?= e(substr((string) $entry['accessed_at'], 0, 16)) ?></td>
                            <td class="small">
                                <?= $entry['admission_number'] === null
                                    ? '<span class="text-muted">Unknown student</span>'
                                    : e((string) $entry['admission_number']) ?>
                            </td>
                            <td>
                                <?php if ((int) $entry['success'] === 1): ?>
                                    <span class="badge bg-success">Result opened</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-dark border">
                                        <?= e((string) ($entry['failure_reason'] ?? 'refused')) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
/* The extend row stays closed until the calendar button is pressed, so the list
   does not carry a date input for every PIN on the portal. */
document.querySelectorAll('[data-extend-toggle]').forEach(function (button) {
    button.addEventListener('click', function () {
        var row = document.querySelector('tr[data-extend-row="' + button.getAttribute('data-extend-toggle') + '"]');

        if (!row) {
            return;
        }

        row.classList.toggle('d-none');
        var input = row.querySelector('input[type="date"]');

        if (input && !row.classList.contains('d-none')) {
            input.focus();
        }
    });
});
</script>

<?php require __DIR__ . '/../includes/admin_bottom.php'; ?>
