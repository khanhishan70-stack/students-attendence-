<?php
/**
 * SMART ATTENDANCE AI - PHASE 3
 * Settings screen shell.
 */

declare(strict_types=1);

require __DIR__ . '/includes/layout.php';

render_page_start('settings', 'Settings');

render_placeholder([
    'title' => 'Settings are planned for Phase 4',
    'lead'  => 'User accounts and roles are stored in the users table, but no account can be signed into yet. Settings will manage the application preferences of the signed-in teacher or admin.',
    'points' => [
        'Change the password of the current account',
        'Set the academic year and default class',
        'Manage user accounts and their roles',
        'Review the database connection status',
    ],
    'phase' => 'Planned for Phase 4',
    'icon'  => 'gear',
]);

render_page_end();
