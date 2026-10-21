<?php

declare(strict_types=1);

/**
 * Apply an administrator decision to a result.
 *
 * Approve, return, lock and unlock all arrive here. The page never renders: it
 * validates, calls applyResultAction() and redirects, so the browser cannot end
 * up showing a stale status and every transition is recorded once.
 *
 * applyResultAction() is the only place the workflow is decided, which is why
 * this file does not re-implement the rules. It only gathers the input safely.
 */

require_once __DIR__ . '/../includes/admin_bootstrap.php';

$returnTo = 'admin/results.php';

/* The review screen is the natural place to land, so keep its id when we have
   one; otherwise go back to the list, filters and all. */
$resultId = postIdList('result_id')[0] ?? null;

if ($resultId !== null) {
    $returnTo = 'admin/result-review.php?id=' . $resultId;
}

$back = isset($_POST['return_to']) ? (string) $_POST['return_to'] : '';

if ($back !== '' && preg_match('#^admin/result-review\.php\?id=\d+$#', $back) === 1) {
    $returnTo = $back;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    setFlash('error', 'A result decision can only be made from the review screen.');
    redirect($returnTo);
}

$token = $_POST[CSRF_FIELD_NAME] ?? null;

if (!verifyCsrfToken(is_string($token) ? $token : null)) {
    setFlash('error', 'Your session expired. Please try again.');
    redirect($returnTo);
}

if ($resultId === null) {
    setFlash('error', 'No result was selected, so nothing was changed.');
    redirect('admin/results.php');
}

$action = field('action', 20);

/* Keep the reason to a single line: it is stored in a VARCHAR(255) and is shown
   in the teacher's list, so a paragraph would be truncated anyway. */
$rawReason = isset($_POST['reason']) && is_string($_POST['reason']) ? $_POST['reason'] : '';
$reason    = trim(preg_replace('/\s+/u', ' ', $rawReason) ?? $rawReason);

$outcome = applyResultAction($resultId, $action, $reason);

if ($outcome['ok']) {
    setFlash('success', $outcome['message']);
} else {
    setFlash('error', $outcome['message']);
}

redirect($returnTo);
