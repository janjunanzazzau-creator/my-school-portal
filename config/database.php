<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Database configuration for the local XAMPP MySQL server.
 * Values may be overridden through environment variables when deploying.
 */
function getDatabaseConfig(): array
{
    return [
        'host'     => getenv('DB_HOST') ?: 'localhost',
        'port'     => getenv('DB_PORT') ?: '3306',
        'dbname'   => getenv('DB_NAME') ?: 'school_result_portal',
        'username' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '',
        'charset'  => 'utf8mb4',
    ];
}

/**
 * Create and return a shared PDO connection.
 *
 * Real driver messages are logged (development) and are never shown to
 * end users, so database credentials and hostnames stay private.
 */
function getDbConnection(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = getDatabaseConfig();

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['port'],
        $config['dbname'],
        $config['charset']
    );

    try {
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return $pdo;
    } catch (PDOException $exception) {
        error_log('[DB] Connection failed: ' . $exception->getMessage());

        throw new RuntimeException(
            'The portal database is unavailable. Please import database/school_result_portal.sql '
            . 'through phpMyAdmin and confirm that MySQL is running.'
        );
    }
}
