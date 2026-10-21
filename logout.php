<?php

declare(strict_types=1);

/**
 * Logout: clear the session, delete the cookie, destroy the session and send
 * the visitor back to the login page.
 */

require_once __DIR__ . '/config/auth.php';

logoutUser();

startSecureSession();
setFlash('success', 'You have been signed out successfully.');

redirect('login.php');
