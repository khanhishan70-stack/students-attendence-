<?php
/**
 * SMART ATTENDANCE AI - PHASE 3
 * Classes screen shell.
 */

declare(strict_types=1);

require __DIR__ . '/includes/layout.php';

render_page_start('classes', 'Classes');

render_placeholder([
    'title' => 'Class management is planned for Phase 4',
    'lead'  => 'The classes table already stores department, academic year and division with a uniqueness rule. This screen will create those class definitions and show the roster of each class.',
    'points' => [
        'Create classes for a department, academic year and division',
        'Prevent duplicate class definitions',
        'View the student roster of a class',
        'See how many subjects and sessions a class has',
    ],
    'phase' => 'Planned for Phase 4',
    'icon'  => 'school',
]);

render_page_end();
