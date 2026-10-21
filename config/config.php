<?php

declare(strict_types=1);

/**
 * School Result Management Portal - application configuration.
 *
 * Every value that appears in more than one page is defined once here, so the
 * school identity, the base URL and the security settings can never drift apart
 * between files.
 */

/* ------------------------------------------------------------------ */
/* Application                                                         */
/* ------------------------------------------------------------------ */

define('APP_NAME', 'School Result Management Portal');
define('APP_VERSION', '1.0.0');

/**
 * Set to 'production' on a live server: error output is then hidden and only
 * the friendly messages are shown to visitors.
 */
define('APP_ENV', getenv('APP_ENV') ?: 'development');

/** Root URL of the project, without a trailing slash. */
define('BASE_URL', rtrim(getenv('APP_BASE_URL') ?: '/school-result-portal', '/'));

/* ------------------------------------------------------------------ */
/* School identity                                                     */
/* ------------------------------------------------------------------ */

/**
 * Get a school setting value.
 * Reads from database settings table with constants as fallback.
 *
 * @param string $key Setting key (name, short_name, campaign, foundation, motto, address, phone, email, website, logo_path, stamp_path)
 * @return string
 */
function school(string $key = ''): string
{
    static $cache = null;

    if ($cache === null) {
        try {
            /* Direct database query to avoid circular dependency */
            $pdo = getDbConnection();
            $stmt = $pdo->prepare('SELECT * FROM school_settings WHERE id = 1 LIMIT 1');
            $stmt->execute();
            $cache = $stmt->fetch() ?: [];
        } catch (Throwable) {
            $cache = [];
        }
    }

    $map = [
        'name'         => 'school_name',
        'short_name'   => 'school_short_name',
        'campaign'     => 'school_campaign',
        'foundation'   => 'school_foundation',
        'motto'        => 'school_motto',
        'address'      => 'school_address',
        'phone'        => 'school_phone',
        'email'        => 'school_email',
        'website'      => 'school_website',
        'logo_path'    => 'logo_path',
        'stamp_path'   => 'stamp_path',
    ];

    $dbKey = $map[$key] ?? $key;

    if ($cache !== null && isset($cache[$dbKey]) && $cache[$dbKey] !== '') {
        return (string) $cache[$dbKey];
    }

    /* Fallback to constants */
    $fallbacks = [
        'name'         => 'ORPHANS MODEL NURSERY AND PRIMARY SCHOOL',
        'short_name'   => 'OMNPS',
        'campaign'     => '(MAKARANTAR MARAYU)',
        'foundation'   => 'ZAZZAU ORPHANS INITIATIVE FOUNDATION',
        'motto'        => 'KNOWLEDGE IS SHIELD TO THE ORPHANS',
        'address'      => 'Zazzau, Kaduna State, Nigeria',
        'phone'        => '+234 803 000 0000',
        'email'        => 'info@omnpzazzau.edu.ng',
        'website'      => '',
        'logo_path'    => 'assets/images/logo.jpeg',
        'stamp_path'   => '',
    ];

    return $fallbacks[$key] ?? '';
}

/* Constants as fallback values - school() function will use database when available */
define('SCHOOL_NAME', 'ORPHANS MODEL NURSERY AND PRIMARY SCHOOL');
define('SCHOOL_SHORT_NAME', 'OMNPS');
define('SCHOOL_CAMPAIGN', '(MAKARANTAR MARAYU)');
define('SCHOOL_FOUNDATION', 'ZAZZAU ORPHANS INITIATIVE FOUNDATION');
define('SCHOOL_MOTTO', 'KNOWLEDGE IS SHIELD TO THE ORPHANS');
define('SCHOOL_ADDRESS', 'Zazzau, Kaduna State, Nigeria');
define('SCHOOL_PHONE', '+234 803 000 0000');
define('SCHOOL_EMAIL', 'info@omnpzazzau.edu.ng');

/* ------------------------------------------------------------------ */
/* Security                                                            */
/* ------------------------------------------------------------------ */

define('CSRF_FIELD_NAME', 'csrf_token');
define('CSRF_TOKEN_KEY', 'csrf_token');

/** Minimum length accepted for a new password. */
define('PASSWORD_MIN_LENGTH', 8);

/** SESSION_* settings are applied before the session starts. */
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', '1800');

/**
 * Start the secure session exactly once.
 *
 * The cookie uses a dedicated name so the portal never shares a session with
 * another application that happens to run on the same XAMPP installation.
 */
function startSecureSession(): void
{
    static $started = false;

    if ($started || PHP_SAPI === 'cli') {
        return;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $started = true;
        return;
    }

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => BASE_URL . '/',
            'httponly' => true,
            'secure'   => (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'),
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(0, BASE_URL . '/', '', false, true);
    }

    session_name('OMNPSSESSID');
    session_start();

    $started = true;
}

/* ------------------------------------------------------------------ */
/* Error handling                                                      */
/* ------------------------------------------------------------------ */

/**
 * Log the real failure and show the visitor a friendly message that never
 * contains database credentials, host names or file paths.
 */
function handleUncaughtThrowable(Throwable $exception): void
{
    error_log('[APP] Uncaught ' . get_class($exception) . ': ' . $exception->getMessage()
        . ' in ' . $exception->getFile() . ':' . $exception->getLine());

    if (!headers_sent()) {
        http_response_code(500);
    }

    if (APP_ENV === 'production') {
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Something went wrong</title>'
            . '<style>body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;'
            . 'background:#f4f7f5;color:#1f2d24;display:flex;min-height:100vh;align-items:center;'
            . 'justify-content:center;margin:0;padding:24px}'
            . '.box{max-width:520px;background:#fff;border-radius:14px;padding:32px;'
            . 'box-shadow:0 10px 30px rgba(0,0,0,.08);text-align:center}'
            . 'h1{color:#0f5132;font-size:1.35rem;margin:0 0 12px}'
            . 'p{color:#5b6b60;line-height:1.6;margin:0 0 20px}'
            . 'a{display:inline-block;background:#0f5132;color:#fff;text-decoration:none;'
            . 'padding:10px 20px;border-radius:8px;font-weight:600}</style></head><body>'
            . '<div class="box"><h1>Something went wrong</h1>'
            . '<p>The page could not be completed because of an unexpected problem. '
            . 'Please go back and try again.</p>'
            . '<a href="' . htmlspecialchars(BASE_URL . '/', ENT_QUOTES, 'UTF-8') . '">Back to the portal</a>'
            . '</div></body></html>';
        exit;
    }

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Something went wrong</title></head>'
        . '<body style="font-family:system-ui,sans-serif;background:#f4f7f5;padding:24px">'
        . '<div style="max-width:900px;background:#fff;border-radius:12px;padding:24px;'
        . 'box-shadow:0 10px 30px rgba(0,0,0,.08)">'
        . '<h1 style="color:#0f5132;font-size:1.3rem">Development error</h1>'
        . '<p><strong>' . htmlspecialchars(get_class($exception), ENT_QUOTES, 'UTF-8') . '</strong></p>'
        . '<pre style="background:#12161a;color:#e6edf3;padding:16px;border-radius:8px;'
        . 'overflow:auto;font-size:.85rem;white-space:pre-wrap">'
        . htmlspecialchars(
            $exception->getMessage() . "\n\n"
            . $exception->getFile() . ':' . $exception->getLine() . "\n\n"
            . $exception->getTraceAsString(),
            ENT_QUOTES,
            'UTF-8'
        )
        . '</pre></div></body></html>';
    exit;
}

set_exception_handler('handleUncaughtThrowable');

/** Convert PHP notices and warnings into logged exceptions in development. */
function handlePhpError(int $severity, string $message, string $file, int $line): void
{
    if ((error_reporting() & $severity) === 0) {
        return;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
}

set_error_handler('handlePhpError');
