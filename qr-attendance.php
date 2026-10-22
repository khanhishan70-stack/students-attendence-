<?php
/**
 * SMART ATTENDANCE AI - PHASE 3
 * QR attendance screen shell.
 */

declare(strict_types=1);

require __DIR__ . '/includes/layout.php';

render_page_start('qr', 'QR Attendance');

render_placeholder([
    'title' => 'QR attendance is planned for Phase 6',
    'lead'  => 'Sessions already support the qr attendance mode, so a mobile check-in screen can be built on the same tables. Nothing is generated or scanned yet.',
    'points' => [
        'Open a session in QR mode and display a session code',
        'Let a student mark themselves present from a phone browser',
        'Reject a check-in that is duplicated or late',
        'Keep the QR session and manual session data identical',
    ],
    'phase' => 'Planned for Phase 6',
    'icon'  => 'qr',
]);

render_page_end();
