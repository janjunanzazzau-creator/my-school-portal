<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Small reusable helpers: output escaping, redirects, flash messages and
 * input validation. Used by the public pages and the auth layer.
 */

/**
 * Escape a value for safe output in HTML.
 *
 * Accepts any scalar (counts and ids are passed through as integers) and
 * returns an empty string for anything that must never be printed, such as
 * an array.
 */
function e($value): string
{
    if ($value === null) {
        return '';
    }

    if (is_bool($value)) {
        return $value ? '1' : '';
    }

    if (!is_scalar($value)) {
        return '';
    }

    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Redirect to a path inside the project (absolute URLs pass through). */
function redirect(string $path): void
{
    $location = preg_match('#^https?://#i', $path) === 1
        ? $path
        : BASE_URL . '/' . ltrim($path, '/');

    if (!headers_sent()) {
        header('Location: ' . $location);
    }

    exit;
}

/** Absolute URL for a project-relative path. */
function assetUrl(string $path): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

/* ------------------------------------------------------------------ */
/* Flash messages                                                      */
/* ------------------------------------------------------------------ */

function setFlash(string $type, string $message): void
{
    startSecureSession();
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Read and clear the queued flash messages. */
function pullFlashes(): array
{
    startSecureSession();

    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return is_array($flashes) ? $flashes : [];
}

/* ------------------------------------------------------------------ */
/* Validation                                                          */
/* ------------------------------------------------------------------ */

function postString(string $key, int $maxLength = 255): string
{
    $value = $_POST[$key] ?? '';
    $value = is_string($value) ? trim($value) : '';

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength);
    }

    return substr($value, 0, $maxLength);
}

function isValidUsername(string $username): bool
{
    return (bool) preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username);
}

function isValidEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}
