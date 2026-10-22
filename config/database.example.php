<?php
/**
 * SMART ATTENDANCE AI - PHASE 2
 *
 * Template for the local database configuration.
 *
 * Copy this file to `config/database.php` and adjust the values for your
 * machine. `config/database.php` is listed in .gitignore, so real
 * credentials are never committed to the repository.
 *
 * XAMPP default values are already filled in below.
 */

declare(strict_types=1);

return [
    'host'    => '127.0.0.1',
    'port'    => 3306,
    'name'    => 'smart_attendance',
    'user'    => 'root',
    'pass'    => '',
    'charset' => 'utf8mb4',
];
