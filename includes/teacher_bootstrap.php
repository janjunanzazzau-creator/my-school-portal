<?php

declare(strict_types=1);

/**
 * Mandatory bootstrap for every page in teacher/.
 *
 * Same guarantee as includes/admin_bootstrap.php: authorisation runs before any
 * record is read, so a teacher cannot probe for the existence of another
 * teacher's class or student list.
 */

require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/ui.php';

requireTeacher();
