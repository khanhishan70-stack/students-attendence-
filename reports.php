<?php
/**
 * SMART ATTENDANCE AI - PHASE 3
 * Reports screen shell.
 */

declare(strict_types=1);

require __DIR__ . '/includes/layout.php';

render_page_start('reports', 'Reports');

render_placeholder([
    'title' => 'Reports and insights are planned for Phase 7',
    'lead'  => 'The reporting view v_attendance_records and the report indexes are already in place. This screen will present those results as printable summaries.',
    'points' => [
        'Class wise daily attendance sheet',
        'Student wise attendance percentage and default list',
        'Date range and subject filters',
        'Export a summary for department review',
    ],
    'phase' => 'Planned for Phase 7',
    'icon'  => 'chart',
]);

render_page_end();
