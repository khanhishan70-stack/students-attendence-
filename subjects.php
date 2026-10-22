<?php
/**
 * SMART ATTENDANCE AI - PHASE 3
 * Subjects screen shell.
 */

declare(strict_types=1);

require __DIR__ . '/includes/layout.php';

render_page_start('subjects', 'Subjects');

render_placeholder([
    'title' => 'Subject management is planned for Phase 4',
    'lead'  => 'Subjects are already stored per class with a unique subject code inside that class. This screen will maintain the subject list of every class.',
    'points' => [
        'Add a subject code and name to a class',
        'Keep subject codes unique inside a class',
        'Activate or deactivate a subject',
        'List the subjects used by each class',
    ],
    'phase' => 'Planned for Phase 4',
    'icon'  => 'book',
]);

render_page_end();
