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

const APP_NAME    = 'SMART ATTENDANCE AI';
const APP_PHASE   = 'PHASE 3 — DASHBOARD UI';
const APP_VERSION = '0.3.0';

/**
 * Navigation model. `phase` marks screens that are not implemented yet.
 */
function app_nav_items(): array
{
    return [
        ['key' => 'dashboard',  'label' => 'Dashboard',         'href' => 'dashboard.php',         'icon' => 'grid',   'phase' => null],
        ['key' => 'students',   'label' => 'Students',          'href' => 'students.php',          'icon' => 'users',  'phase' => 'Phase 4'],
        ['key' => 'classes',    'label' => 'Classes',           'href' => 'classes.php',           'icon' => 'school', 'phase' => 'Phase 4'],
        ['key' => 'subjects',   'label' => 'Subjects',          'href' => 'subjects.php',          'icon' => 'book',   'phase' => 'Phase 4'],
        ['key' => 'manual',     'label' => 'Manual Attendance', 'href' => 'manual-attendance.php', 'icon' => 'check',  'phase' => 'Phase 5'],
        ['key' => 'qr',         'label' => 'QR Attendance',     'href' => 'qr-attendance.php',     'icon' => 'qr',     'phase' => 'Phase 6'],
        ['key' => 'reports',    'label' => 'Reports',           'href' => 'reports.php',          'icon' => 'chart',  'phase' => 'Phase 7'],
        ['key' => 'settings',   'label' => 'Settings',          'href' => 'settings.php',          'icon' => 'gear',   'phase' => 'Phase 4'],
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
