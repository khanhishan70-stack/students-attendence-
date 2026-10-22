<?php
/**
 * SMART ATTENDANCE AI - PHASE 2
 *
 * Database access layer (PDO / MySQL).
 *
 * - Reads the local configuration from config/database.php
 * - Throws exceptions on failure so the caller can degrade gracefully
 * - Never writes credentials or server details to the browser
 */

declare(strict_types=1);

function db_config(): array
{
    static $config = null;

    if ($config === null) {
        $file = __DIR__ . DIRECTORY_SEPARATOR . 'database.php';

        if (!is_file($file)) {
            throw new RuntimeException(
                'Database configuration missing. Copy config/database.example.php to config/database.php.'
            );
        }

        $loaded = require $file;

        if (!is_array($loaded)) {
            throw new RuntimeException('Database configuration is invalid.');
        }

        $config = $loaded;
    }

    return $config;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = db_config();

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['host'],
        (int) $config['port'],
        $config['name'],
        $config['charset']
    );

    try {
        $pdo = new PDO($dsn, (string) $config['user'], (string) $config['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);

        $pdo->exec(
            "SET SESSION sql_mode = 'STRICT_TRANS_TABLES,STRICT_ALL_TABLES,"
            . "NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'"
        );
    } catch (PDOException $exception) {
        error_log('[smart-attendance] database connection failed: ' . $exception->getMessage());
        throw new RuntimeException('Database connection is unavailable.', 0, $exception);
    }

    return $pdo;
}

function db_is_available(): bool
{
    try {
        db();

        return true;
    } catch (Throwable $exception) {
        return false;
    }
}
