<?php

declare(strict_types=1);

/**
 * Close a public result session.
 *
 * Separate from logout.php on purpose. logout.php ends an administrator or
 * teacher login and destroys the whole session; this only drops the public result
 * grant, so a member of staff checking a result on a machine that is also signed
 * in to the admin portal keeps their admin session.
 *
 * Works as a link and as a POST, because a parent reads "Close Result" as a link.
 * Closing a session cannot damage anything - it only takes away access that the
 * visitor already proved they had - so it needs no CSRF token.
 *
 * The session is not merely emptied: the PHP session id is rotated as well, so a
 * copy of the old cookie cannot be replayed to pick the grant back up.
 */

require_once __DIR__ . '/config/functions.php';

sendPublicResultSecurityHeaders();

revokePublicResultAccess();

if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
    session_regenerate_id(true);
}

setFlash('success', 'Your result has been closed. You can check another result whenever you are ready.');

redirect('result-checker.php');
