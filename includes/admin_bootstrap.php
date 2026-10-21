<?php

declare(strict_types=1);

/**
 * Mandatory bootstrap for every page in admin/.
 *
 * It loads the data layer, the UI helpers and - most importantly - the
 * authorisation middleware. Requiring this file first guarantees that no
 * admin page can read or change a record before the user is authenticated,
 * which would otherwise reveal whether a record id exists.
 *
 * Including it more than once is harmless.
 */

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/ui.php';

requireAdmin();
