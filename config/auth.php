<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/helpers.php';

/**
 * Authentication and authorisation layer.
 */

/** Roles supported by the portal. */
const AUTH_ROLES = ['admin', 'teacher'];

/** Roles allowed to sign in. Result entry arrives in Phase 4, so teachers join here. */
const AUTH_LOGIN_ROLES = ['admin', 'teacher'];

/* ------------------------------------------------------------------ */
/* Session                                                             */
/* ------------------------------------------------------------------ */

function authEnsureSession(): void
{
    startSecureSession();
}

/* ------------------------------------------------------------------ */
/* State                                                               */
/* ------------------------------------------------------------------ */

function isLoggedIn(): bool
{
    authEnsureSession();

    return isset($_SESSION['user_id'], $_SESSION['user_role']);
}

function currentUser(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }

    if (!isset($_SESSION['user'])) {
        try {
            $stmt = getDbConnection()->prepare(
                'SELECT id, username, full_name, email, role, status, last_login_at
                 FROM users
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmt->execute([':id' => (int) $_SESSION['user_id']]);
            $user = $stmt->fetch();
        } catch (Throwable $exception) {
            error_log('[AUTH] Unable to load current user: ' . $exception->getMessage());
            $user = false;
        }

        if (!$user || (int) $user['status'] !== 1) {
            logoutUser();
            return null;
        }

        $_SESSION['user'] = $user;
    }

    return $_SESSION['user'];
}

function currentUserId(): ?int
{
    return isLoggedIn() ? (int) $_SESSION['user_id'] : null;
}

function currentUserRole(): ?string
{
    return isLoggedIn() ? (string) $_SESSION['user_role'] : null;
}

function hasRole(string ...$roles): bool
{
    $role = currentUserRole();

    return $role !== null && in_array($role, $roles, true);
}

/* ------------------------------------------------------------------ */
/* Login                                                               */
/* ------------------------------------------------------------------ */

/**
 * A hash of a value nobody can supply, used to keep the response time of a
 * login attempt similar whether or not the username exists.
 */
function dummyPasswordHash(): string
{
    static $hash = null;

    if ($hash === null) {
        $hash = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    }

    return $hash;
}

/**
 * Verify credentials and start an authenticated session.
 *
 * Every failure path returns the same generic message so the login form never
 * reveals whether a username or account status exists in the database.
 *
 * @return array{success: bool, message: string}
 */
function attemptLogin(string $username, string $password): array
{
    $failure = [
        'success' => false,
        'message' => 'Invalid username or password.',
    ];

    if ($username === '' || $password === '') {
        return $failure;
    }

    try {
        $stmt = getDbConnection()->prepare(
            'SELECT id, username, password, full_name, email, role, status
             FROM users
             WHERE username = :username
             LIMIT 1'
        );
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch();
    } catch (Throwable $exception) {
        error_log('[AUTH] Login query failed: ' . $exception->getMessage());
        return [
            'success' => false,
            'message' => 'Login is temporarily unavailable. Please try again later.',
        ];
    }

    $storedHash = is_array($user) ? (string) $user['password'] : '';

    // Always run a verification, even when the user was not found.
    $passwordOk = password_verify($password, $storedHash !== '' ? $storedHash : dummyPasswordHash());

    if (!is_array($user) || !$passwordOk) {
        return $failure;
    }

    if ((int) $user['status'] !== 1) {
        return $failure;
    }

    if (!in_array((string) $user['role'], AUTH_LOGIN_ROLES, true)) {
        return $failure;
    }

    loginUser($user);

    return ['success' => true, 'message' => 'Login successful.'];
}

/** Establish the session for an authenticated user and rotate the session id. */
function loginUser(array $user): void
{
    authEnsureSession();

    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_role'] = (string) $user['role'];
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'full_name' => (string) $user['full_name'],
        'email' => (string) $user['email'],
        'role' => (string) $user['role'],
        'status' => (int) $user['status'],
        'last_login_at' => $user['last_login_at'] ?? null,
    ];
    $_SESSION['login_time'] = time();

    try {
        $stmt = getDbConnection()->prepare(
            'UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':id' => (int) $user['id']]);
        $_SESSION['user']['last_login_at'] = date('Y-m-d H:i:s');
    } catch (Throwable $exception) {
        error_log('[AUTH] Unable to update last login: ' . $exception->getMessage());
    }
}

/** Remove all session data and destroy the session. */
function logoutUser(): void
{
    authEnsureSession();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

/* ------------------------------------------------------------------ */
/* Middleware                                                          */
/* ------------------------------------------------------------------ */

/** Send unauthenticated visitors to the login page. */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        setFlash('error', 'Please sign in to continue.');
        redirect('login.php');
    }

    currentUser();
}

/** Restrict a page to administrators only. */
function requireAdmin(): void
{
    requireLogin();

    if (!hasRole('admin')) {
        setFlash('error', 'You do not have permission to access that page.');
        logoutUser();
        redirect('login.php');
    }
}

/**
 * Restrict a page to teachers only.
 *
 * A signed-in administrator is refused as well: teacher pages show personal
 * assignment data, so they are reached through the teacher account itself.
 */
function requireTeacher(): void
{
    requireLogin();

    if (!hasRole('teacher')) {
        setFlash('error', 'You do not have permission to access that page.');
        redirect('login.php');
    }
}

/**
 * Allow either role.
 *
 * Used by the landing page of each area so a user is sent to the dashboard that
 * matches the account they signed in with.
 */
function requireAnyRole(): void
{
    requireLogin();

    if (!hasRole('admin', 'teacher')) {
        logoutUser();
        redirect('login.php');
    }
}

/* ------------------------------------------------------------------ */
/* CSRF                                                                */
/* ------------------------------------------------------------------ */

function csrfToken(): string
{
    authEnsureSession();

    if (empty($_SESSION[CSRF_TOKEN_KEY]) || !is_string($_SESSION[CSRF_TOKEN_KEY])) {
        $_SESSION[CSRF_TOKEN_KEY] = bin2hex(random_bytes(32));
    }

    return $_SESSION[CSRF_TOKEN_KEY];
}

function csrfField(): string
{
    return '<input type="hidden" name="' . e(CSRF_FIELD_NAME) . '" value="' . e(csrfToken()) . '">';
}

function verifyCsrfToken(?string $token): bool
{
    authEnsureSession();

    return is_string($token)
        && !empty($_SESSION[CSRF_TOKEN_KEY])
        && hash_equals((string) $_SESSION[CSRF_TOKEN_KEY], $token);
}
