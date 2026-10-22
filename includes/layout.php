<?php
/**
 * SMART ATTENDANCE AI - PHASE 3
 *
 * Shared application layout: page head, sidebar navigation, top bar and footer.
 * Every screen in the dashboard uses these functions so the navigation, the
 * branding and the responsive behaviour stay identical across pages.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/security.php';

const APP_NAME    = 'SMART ATTENDANCE AI';
const APP_PHASE   = 'PHASE 4 — STUDENT MANAGEMENT';
const APP_VERSION = '0.4.0';

/**
 * Navigation model. `phase` marks screens that are not implemented yet.
 */
function app_nav_items(): array
{
    return [
        ['key' => 'dashboard',  'label' => 'Dashboard',         'href' => 'dashboard.php',         'icon' => 'grid',   'phase' => null],
        ['key' => 'students',   'label' => 'Students',          'href' => 'students.php',          'icon' => 'users',  'phase' => null],
        ['key' => 'csv',        'label' => 'CSV Import',        'href' => 'csv-import.php',        'icon' => 'upload', 'phase' => null],
        ['key' => 'ocr',        'label' => 'OCR Import',        'href' => 'ocr-import.php',        'icon' => 'scan',   'phase' => null],
        ['key' => 'classes',    'label' => 'Classes',           'href' => 'classes.php',           'icon' => 'school', 'phase' => 'Planned'],
        ['key' => 'subjects',   'label' => 'Subjects',          'href' => 'subjects.php',          'icon' => 'book',   'phase' => 'Planned'],
        ['key' => 'manual',     'label' => 'Manual Attendance', 'href' => 'manual-attendance.php', 'icon' => 'check',  'phase' => 'Phase 5'],
        ['key' => 'qr',         'label' => 'QR Attendance',     'href' => 'qr-attendance.php',     'icon' => 'qr',     'phase' => 'Phase 6'],
        ['key' => 'reports',    'label' => 'Reports',           'href' => 'reports.php',          'icon' => 'chart',  'phase' => 'Phase 7'],
        ['key' => 'settings',   'label' => 'Settings',          'href' => 'settings.php',          'icon' => 'gear',   'phase' => 'Planned'],
    ];
}

function app_icon(string $name, string $class = 'app-icon'): string
{
    $paths = [
        'grid'   => '<rect x="3" y="3" width="7.5" height="7.5" rx="1.6"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.6"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.6"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.6"/>',
        'users'  => '<path d="M15.5 20v-1.4a3.6 3.6 0 0 0-3.6-3.6H6.6A3.6 3.6 0 0 0 3 18.6V20"/><circle cx="9.2" cy="7.4" r="3.3"/><path d="M17.4 11.2a3 3 0 1 0 0-6"/><path d="M21 20v-1.3a3.2 3.2 0 0 0-2.4-3.1"/>',
        'school' => '<path d="M3 21h18"/><path d="M5.5 21V8.6L12 4.5l6.5 4.1V21"/><path d="M9.8 21v-4.8h4.4V21"/><path d="M9.6 11.4h1.4M13 11.4h1.4"/>',
        'book'   => '<path d="M4 5.6A2.6 2.6 0 0 1 6.6 3H19v14.6H6.6A2.6 2.6 0 0 0 4 20.2z"/><path d="M4 20.2A2.6 2.6 0 0 1 6.6 17.6H19V21H6.6A2.6 2.6 0 0 1 4 18.6z"/>',
        'check'  => '<path d="M9 4.5h6V7H9z"/><path d="M15 5.8h2.4A1.6 1.6 0 0 1 19 7.4v12A1.6 1.6 0 0 1 17.4 21H6.6A1.6 1.6 0 0 1 5 19.4v-12A1.6 1.6 0 0 1 6.6 5.8H9"/><path d="m9 13.4 2.1 2.1 4-4"/>',
        'qr'     => '<rect x="3.5" y="3.5" width="6.5" height="6.5" rx="1.2"/><rect x="14" y="3.5" width="6.5" height="6.5" rx="1.2"/><rect x="3.5" y="14" width="6.5" height="6.5" rx="1.2"/><path d="M14 14h3v3h-3zM20.5 14.2v2.6M14 20.5h2.6M18 18h3v3"/>',
        'chart'  => '<path d="M4 20.5V10.5"/><path d="M10 20.5V4.5"/><path d="M16 20.5v-7"/><path d="M22 20.5H2.5"/>',
        'gear'   => '<circle cx="12" cy="12" r="3.1"/><path d="M19.3 14.9a1.6 1.6 0 0 0 .3 1.8l.1.1a1.9 1.9 0 1 1-2.7 2.7l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5v.2a1.9 1.9 0 1 1-3.8 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a1.9 1.9 0 1 1-2.7-2.7l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1h-.2a1.9 1.9 0 1 1 0-3.8h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a1.9 1.9 0 1 1 2.7-2.7l.1.1a1.6 1.6 0 0 0 1.8.3h.1a1.6 1.6 0 0 0 1-1.5v-.2a1.9 1.9 0 1 1 3.8 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a1.9 1.9 0 1 1 2.7 2.7l-.1.1a1.6 1.6 0 0 0-.3 1.8v.1a1.6 1.6 0 0 0 1.5 1h.2a1.9 1.9 0 1 1 0 3.8h-.1a1.6 1.6 0 0 0-1.5 1z"/>',
        'arrow'  => '<path d="M19 12H5"/><path d="m12 19-7-7 7-7"/>',
        'menu'   => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close'  => '<path d="M6 6l12 12M18 6 6 18"/>',
        'spark'  => '<path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M18.4 5.6l-2.1 2.1M7.7 16.3l-2.1 2.1"/>',
        'upload' => '<path d="M12 15V4"/><path d="m8 8 4-4 4 4"/><path d="M4 16v2.5A1.5 1.5 0 0 0 5.5 20h13a1.5 1.5 0 0 0 1.5-1.5V16"/>',
        'scan'   => '<rect x="3.5" y="4" width="17" height="14" rx="2"/><path d="M3.5 9h17"/><path d="M7 20h10"/><path d="M8.5 13.5h7"/><path d="M8.5 16h4"/>',
        'pen'    => '<path d="M16.5 4.5 19.5 7.5 8 19H5v-3z"/><path d="m14.5 6.5 3 3"/>',
        'plus'   => '<path d="M12 5v14M5 12h14"/>',
        'trash'  => '<path d="M4 7h16"/><path d="M9.5 7V5.2A1.2 1.2 0 0 1 10.7 4h2.6a1.2 1.2 0 0 1 1.2 1.2V7"/><path d="M6.5 7l.8 12.1A1.5 1.5 0 0 0 8.8 20.5h6.4a1.5 1.5 0 0 0 1.5-1.4L17.5 7"/>',
    ];

    $body = $paths[$name] ?? $paths['grid'];

    return '<svg class="' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
        . 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . $body . '</svg>';
}

/**
 * Opens the document, the sidebar and the top bar.
 */
function render_page_start(string $navKey, string $pageTitle): void
{
    $navItems = app_nav_items();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Smart Attendance AI — attendance management dashboard for the Dual-Mode Digital Student Attendance Management System.">
    <meta name="theme-color" content="#050b1a">
    <title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body class="app-body">
    <a class="skip-link" href="#main-content">Skip to main content</a>

    <div class="app-backdrop" data-nav-close aria-hidden="true"></div>

    <aside class="app-sidebar" id="app-sidebar" aria-label="Main navigation">
        <div class="sidebar-brand">
            <a class="brand" href="dashboard.php">
                <span class="brand-mark" aria-hidden="true">SA</span>
                <span class="brand-text">
                    <strong>Smart Attendance</strong>
                    <span>AI</span>
                </span>
            </a>
            <button class="sidebar-close" type="button" data-nav-close aria-label="Close navigation menu">
                <?= app_icon('close') ?>
            </button>
        </div>

        <nav class="sidebar-nav">
            <p class="sidebar-label">Menu</p>
            <ul class="sidebar-list">
                <?php foreach ($navItems as $item): ?>
                    <?php $isActive = $item['key'] === $navKey; ?>
                    <li>
                        <a class="sidebar-link<?= $isActive ? ' is-active' : '' ?>"
                           href="<?= e($item['href']) ?>"
                           <?= $isActive ? 'aria-current="page"' : '' ?>>
                            <?= app_icon($item['icon']) ?>
                            <span class="sidebar-link-text"><?= e($item['label']) ?></span>
                            <?php if ($item['phase'] !== null): ?>
                                <span class="sidebar-flag"><?= e($item['phase']) ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <div class="sidebar-foot">
            <a class="sidebar-overview" href="index.php">
                <?= app_icon('arrow') ?>
                <span>Project overview</span>
            </a>
            <p class="sidebar-phase"><?= e(APP_PHASE) ?></p>
        </div>
    </aside>

    <div class="app-main">
        <header class="app-topbar">
            <button class="nav-toggle" type="button" data-nav-toggle
                    aria-expanded="false" aria-controls="app-sidebar" aria-label="Open navigation menu">
                <?= app_icon('menu') ?>
            </button>

            <div class="topbar-title">
                <p class="topbar-eyebrow">Dashboard</p>
                <h1 class="topbar-heading"><?= e($pageTitle) ?></h1>
            </div>

            <div class="topbar-meta">
                <p class="topbar-date">
                    <span class="topbar-date-label">Today</span>
                    <span class="topbar-date-full"><?= e(date('l, j F Y')) ?></span>
                    <span class="topbar-date-short"><?= e(date('j M Y')) ?></span>
                </p>

                <div class="user-chip">
                    <span class="user-avatar" aria-hidden="true">G</span>
                    <span class="user-meta">
                        <strong>Guest access</strong>
                        <span>Sign-in not implemented yet</span>
                    </span>
                </div>
            </div>
        </header>

        <main class="app-content" id="main-content">
        <?php render_flash(); ?>
        <?php
}

function render_page_end(): void
{
    ?>
        </main>

        <footer class="app-footer">
            <p>&copy; <?= e(date('Y')) ?> <?= e(APP_NAME) ?></p>
            <p>Diploma Computer Engineering · DBMS Project</p>
            <p class="footer-phase"><?= e(APP_PHASE) ?> · v<?= e(APP_VERSION) ?></p>
        </footer>
    </div>
</body>
</html>
    <?php
}

/**
 * Renders a professional placeholder for a feature that belongs to a later phase.
 */
function render_placeholder(array $options): void
{
    $title  = $options['title'] ?? 'Not built yet';
    $lead   = $options['lead'] ?? '';
    $points = $options['points'] ?? [];
    $phase  = $options['phase'] ?? 'Planned';
    $icon   = $options['icon'] ?? 'spark';
    ?>
    <section class="placeholder" aria-labelledby="placeholder-title">
        <div class="placeholder-card">
            <div class="placeholder-icon" aria-hidden="true"><?= app_icon($icon, 'app-icon app-icon-lg') ?></div>
            <p class="phase-badge phase-badge-soft"><span class="dot dot-amber" aria-hidden="true"></span><?= e($phase) ?></p>
            <h2 id="placeholder-title"><?= e($title) ?></h2>
            <p class="placeholder-lead"><?= e($lead) ?></p>

            <?php if ($points !== []): ?>
                <ul class="placeholder-points">
                    <?php foreach ($points as $point): ?>
                        <li><?= e($point) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <p class="placeholder-foot">
                The database tables for this area are already built in
                <code>smart_attendance</code>. This screen stays read-only until the
                matching phase is implemented.
            </p>

            <a class="btn btn-ghost" href="dashboard.php">Back to dashboard</a>
        </div>
    </section>
    <?php
}

/**
 * Renders the empty state block used when a data set has no rows.
 */
function render_empty_state(string $title, string $text, string $icon = 'spark'): void
{
    ?>
    <div class="empty-state">
        <div class="empty-icon" aria-hidden="true"><?= app_icon($icon, 'app-icon app-icon-lg') ?></div>
        <p class="empty-title"><?= e($title) ?></p>
        <p class="empty-text"><?= e($text) ?></p>
    </div>
    <?php
}

/**
 * Renders queued flash messages at the top of the page content.
 */
function render_flash(): void
{
    $messages = flash_take();

    if ($messages === []) {
        return;
    }

    foreach ($messages as $message) {
        $type    = (string) ($message['type'] ?? 'info');
        $text    = (string) ($message['message'] ?? '');
        $classes = match ($type) {
            'success' => 'flash flash-success',
            'error'   => 'flash flash-error',
            default   => 'flash flash-info',
        };
        ?>
        <div class="<?= e($classes) ?>" role="status">
            <span class="dot dot-<?= $type === 'success' ? 'green' : ($type === 'error' ? 'red' : 'blue') ?>" aria-hidden="true"></span>
            <p><?= e($text) ?></p>
        </div>
        <?php
    }
}

/**
 * Renders one form field with label, control, hint and error message.
 *
 * @param array $field {
 *     @type string $name
 *     @type string $label
 *     @type string $type    input | textarea | select
 *     @type string $value
 *     @type string $hint
 *     @type string $error
 *     @type array  $options For select: [['value' => '', 'label' => '', 'selected' => bool]]
 *     @type string $required
 * }
 */
function render_field(array $field): void
{
    $name     = (string) ($field['name'] ?? 'field');
    $label    = (string) ($field['label'] ?? $name);
    $type     = (string) ($field['type'] ?? 'input');
    $value    = (string) ($field['value'] ?? '');
    $hint     = (string) ($field['hint'] ?? '');
    $error    = (string) ($field['error'] ?? '');
    $options  = $field['options'] ?? [];
    $required = (bool) ($field['required'] ?? false);
    $invalid  = $error !== '' ? ' is-invalid' : '';
    $hintId   = $name . '-hint';
    $errorId  = $name . '-error';

    $described = [];

    if ($hint !== '') {
        $described[] = $hintId;
    }

    if ($error !== '') {
        $described[] = $errorId;
    }

    $describedBy = $described === [] ? '' : ' aria-describedby="' . e(implode(' ', $described)) . '"';
    ?>
    <div class="field<?= $invalid ?>">
        <label class="field-label" for="<?= e($name) ?>">
            <?= e($label) ?>
            <?php if ($required): ?><span class="field-req" aria-hidden="true">*</span><?php endif; ?>
        </label>

        <?php if ($type === 'textarea'): ?>
            <textarea class="field-control" id="<?= e($name) ?>" name="<?= e($name) ?>"
                      rows="3" <?= $describedBy ?>><?= e($value) ?></textarea>
        <?php elseif ($type === 'select'): ?>
            <select class="field-control" id="<?= e($name) ?>" name="<?= e($name) ?>" <?= $describedBy ?>>
                <?php foreach ($options as $option): ?>
                    <option value="<?= e((string) ($option['value'] ?? '')) ?>"
                        <?= !empty($option['selected']) ? 'selected' : '' ?>>
                        <?= e((string) ($option['label'] ?? '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php else: ?>
            <input class="field-control" type="<?= e($type) ?>" id="<?= e($name) ?>" name="<?= e($name) ?>"
                   value="<?= e($value) ?>"
                   <?= $required ? 'required' : '' ?>
                   <?= $describedBy ?>>
        <?php endif; ?>

        <?php if ($hint !== ''): ?>
            <p class="field-hint" id="<?= e($hintId) ?>"><?= e($hint) ?></p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <p class="field-error" id="<?= e($errorId) ?>"><?= e($error) ?></p>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Renders a small status badge for an import row.
 */
function render_row_status(string $status): string
{
    return match ($status) {
        'valid'     => '<span class="badge badge-green">Ready</span>',
        'duplicate' => '<span class="badge badge-grey">Skipped</span>',
        'conflict'  => '<span class="badge badge-amber">Conflict</span>',
        'invalid'   => '<span class="badge badge-red">Invalid</span>',
        'review'    => '<span class="badge badge-blue">Review</span>',
        default     => '<span class="badge badge-grey">' . e(ucfirst($status)) . '</span>',
    };
}
