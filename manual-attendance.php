<?php
/**
 * SMART ATTENDANCE AI - PHASE 3
 * Manual attendance screen shell.
 */

declare(strict_types=1);

require __DIR__ . '/includes/layout.php';

render_page_start('manual', 'Manual Attendance');

render_placeholder([
    'title' => 'Manual attendance is planned for Phase 5',
    'lead'  => 'The attendance tables and their integrity rules are ready. This screen will let a teacher open a lecture session and mark the class register by roll number.',
    'points' => [
        'Open a session for a class, subject and date',
        'Tick roll numbers from the class roster',
        'Save present, absent and not marked states',
        'Correct a marked record without creating duplicates',
    ],
    'phase' => 'Planned for Phase 5',
    'icon'  => 'check',
]);

render_page_end();
