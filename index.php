<?php

declare(strict_types=1);

/**
 * Entry point. Sends signed-in users to the dashboard that matches their role
 * and everyone else to the login page.
 */

require_once __DIR__ . '/config/auth.php';

if (isLoggedIn()) {
    redirect(hasRole('teacher') ? 'teacher/dashboard.php' : 'dashboard.php');
}

redirect('login.php');
