<?php
/**
 * SMART ATTENDANCE AI - PHASE 3
 * Students screen shell.
 */

declare(strict_types=1);

require __DIR__ . '/includes/layout.php';

render_page_start('students', 'Students');

render_placeholder([
    'title' => 'Student management is planned for Phase 4',
    'lead'  => 'The students and enrolments tables are already built and verified in the database. This screen will add, edit and deactivate student records and manage their class enrolments.',
    'points' => [
        'Search students by roll number or name',
        'Create and edit student profiles',
        'Enrol a student into a class for an academic year',
        'Deactivate a student without deleting history',
    ],
    'phase' => 'Planned for Phase 4',
    'icon'  => 'users',
]);

render_page_end();
