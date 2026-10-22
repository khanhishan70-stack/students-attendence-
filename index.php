<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

require __DIR__ . '/config/helpers.php';
require __DIR__ . '/config/db.php';

$projectName    = 'SMART ATTENDANCE AI';
$projectTagline = 'Dual-Mode Digital Student Attendance Management System';
$phaseLabel     = 'PHASE 5 — MANUAL ATTENDANCE';
$currentYear    = (int) date('Y');

$tables = [
    ['name' => 'users', 'purpose' => 'Teachers and admins, bcrypt password hashes only.', 'keys' => ['PK id', 'UNIQUE email']],
    ['name' => 'classes', 'purpose' => 'Department, academic year and division.', 'keys' => ['PK id', 'UNIQUE identity']],
    ['name' => 'students', 'purpose' => 'Student profiles, roll numbers stored as text.', 'keys' => ['PK id', 'indexed roll']],
    ['name' => 'enrollments', 'purpose' => 'Links a student to a class per academic year.', 'keys' => ['FK students', 'FK classes']],
    ['name' => 'subjects', 'purpose' => 'Subjects taught inside a class.', 'keys' => ['FK classes', 'UNIQUE code']],
    ['name' => 'attendance_sessions', 'purpose' => 'One lecture session in manual or QR mode.', 'keys' => ['FK classes', 'FK subjects']],
    ['name' => 'attendance', 'purpose' => 'Present, absent or not marked per student.', 'keys' => ['FK sessions', 'FK students', 'FK users']],
];

$dbConnected = false;
$dbCounts    = [];

try {
    $pdo        = db();
    $dbConnected = true;

    foreach ($tables as $table) {
        $dbCounts[$table['name']] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table['name'] . '`')->fetchColumn();
    }
} catch (Throwable $exception) {
    $dbConnected = false;
    error_log('[smart-attendance] landing page database check failed: ' . $exception->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Smart Attendance AI — a dual-mode digital student attendance management system. Phase 5 manual attendance.">
    <meta name="theme-color" content="#050b1a">
    <title><?= e($projectName) ?> | Phase 5 Manual Attendance</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body>
    <a class="skip-link" href="#main">Skip to content</a>

    <header class="site-header">
        <div class="container header-inner">
            <a class="brand" href="./" aria-label="<?= e($projectName) ?> home">
                <span class="brand-mark" aria-hidden="true">SA</span>
                <span class="brand-text">
                    <strong>Smart Attendance</strong>
                    <span>AI</span>
                </span>
            </a>

            <nav class="site-nav" aria-label="Primary navigation">
                <a href="#overview">Overview</a>
                <a href="#modes">Modes</a>
                <a href="#database">Database</a>
                <a href="#status">Status</a>
            </nav>
        </div>
    </header>

    <main id="main">
        <section class="hero" id="overview">
            <div class="container hero-grid">
                <div class="hero-copy">
                    <p class="phase-badge">
                        <span class="pulse-dot" aria-hidden="true"></span>
                        <?= e($phaseLabel) ?>
                    </p>

                    <h1><?= e($projectName) ?></h1>
                    <p class="hero-subtitle"><?= e($projectTagline) ?></p>

                    <p class="hero-text">
                        A second-year Diploma Computer Engineering DBMS project that will bring
                        manual roll-number attendance and mobile QR-code attendance into one
                        clean, responsive web application.
                    </p>
                    <div class="hero-actions">
                        <a class="btn btn-primary" href="dashboard.php">Open dashboard</a>
                        <a class="btn btn-ghost" href="#database">See the schema</a>
                        <a class="btn btn-ghost" href="#status">View phase status</a>
                    </div>

                    <p class="hero-note">
                        The relational foundation, the dashboard, student management
                        with CSV and OCR import, and secure manual attendance are
                        live. QR scanning, reports and AI insights are still to come.
                    </p>
                </div>

                <aside class="system-card" aria-labelledby="snapshot-title">
                    <div class="system-card-head">
                        <h2 id="snapshot-title">Project snapshot</h2>
                        <span class="chip">Phase 5 of 7</span>
                    </div>

                    <ul class="snapshot-list">
                        <li>
                            <span class="snapshot-label">Environment</span>
                            <span class="snapshot-value"><span class="dot dot-green" aria-hidden="true"></span>XAMPP prepared</span>
                        </li>
                        <li>
                            <span class="snapshot-label">Backend runtime</span>
                            <span class="snapshot-value">PHP 8.2</span>
                        </li>
                        <li>
                            <span class="snapshot-label">Database</span>
                            <span class="snapshot-value">
                                <?php if ($dbConnected): ?>
                                    <span class="dot dot-green" aria-hidden="true"></span>Connected
                                <?php else: ?>
                                    <span class="dot dot-red" aria-hidden="true"></span>Unavailable
                                <?php endif; ?>
                            </span>
                        </li>
                        <li>
                            <span class="snapshot-label">Schema</span>
                            <span class="snapshot-value"><?= $dbConnected ? count($tables) . ' InnoDB tables' : 'Not loaded' ?></span>
                        </li>
                        <li>
                            <span class="snapshot-label">Attendance logic</span>
                            <span class="snapshot-value">Manual marking live</span>
                        </li>
                    </ul>

                    <p class="system-card-foot">
                        The connection check is read-only. No attendance data is written by this
                        page, and no server or credential details are exposed.
                    </p>
                </aside>
            </div>
        </section>

        <section class="section modes" id="modes" aria-labelledby="modes-title">
            <div class="container">
                <div class="section-head">
                    <p class="section-kicker">Two planned attendance modes</p>
                    <h2 id="modes-title">One system, two ways to mark attendance</h2>
                    <p class="section-text">
                        Both modes are described below so the project scope is clear. The
                        database can already store both, but the marking screens are planned
                        features and are not functional yet.
                    </p>
                </div>

                <div class="card-grid">
                    <article class="mode-card">
                        <div class="mode-icon mode-icon-blue" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M9 5H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"></path>
                                <path d="m9 15 3-3L22 2"></path>
                            </svg>
                        </div>
                        <span class="mode-tag">Live mode</span>
                        <h3>Manual Attendance</h3>
                        <p>
                            Roll-number-based attendance for the classroom. A teacher signs
                            in, starts a session, then marks each student in turn.
                        </p>
                        <ul class="mode-points">
                            <li>One student at a time in roll order</li>
                            <li>Present, absent, review and not-marked</li>
                            <li>Configurable per-student countdown</li>
                        </ul>
                        <p class="mode-status">Live in Phase 5 · secure sign-in required</p>
                    </article>

                    <article class="mode-card">
                        <div class="mode-icon mode-icon-green" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="3" width="7" height="7" rx="1.5"></rect>
                                <rect x="14" y="3" width="7" height="7" rx="1.5"></rect>
                                <rect x="3" y="14" width="7" height="7" rx="1.5"></rect>
                                <path d="M14 14h3v3h-3zM20 14h1M14 20h1M18 20h3v1"></path>
                            </svg>
                        </div>
                        <span class="mode-tag">Planned mode</span>
                        <h3>QR Attendance</h3>
                        <p>
                            Mobile QR-code attendance. Students will scan a session code from a
                            phone browser to mark themselves present.
                        </p>
                        <ul class="mode-points">
                            <li>Session QR code per class</li>
                            <li>Phone-friendly scanning flow</li>
                            <li>Duplicate-entry checks planned</li>
                        </ul>
                        <p class="mode-status">Schema ready · screens not implemented</p>
                    </article>
                </div>
            </div>
        </section>

        <section class="section database" id="database" aria-labelledby="database-title">
            <div class="container">
                <div class="section-head">
                    <p class="section-kicker">Relational foundation</p>
                    <h2 id="database-title">Seven tables, rules enforced by the database</h2>
                    <p class="section-text">
                        The schema lives in <code>smart_attendance</code> with InnoDB storage and
                        utf8mb4 encoding. Foreign keys, unique constraints and triggers protect
                        the data no matter which screen writes to it.
                    </p>
                </div>

                <div class="db-grid">
                    <?php foreach ($tables as $table): ?>
                        <article class="db-card">
                            <h3 class="db-name"><?= e($table['name']) ?></h3>
                            <p class="db-purpose"><?= e($table['purpose']) ?></p>
                            <ul class="db-tags">
                                <?php foreach ($table['keys'] as $key): ?>
                                    <li class="db-tag"><?= e($key) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <p class="db-count">
                                <?php if ($dbConnected): ?>
                                    <?= (int) ($dbCounts[$table['name']] ?? 0) ?> rows
                                <?php else: ?>
                                    Database offline
                                <?php endif; ?>
                            </p>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="db-rules">
                    <h3>Integrity rules already in place</h3>
                    <ul class="rule-list">
                        <li>A subject used in a session must belong to the session class (composite foreign key).</li>
                        <li>A student can only receive attendance in a class they are enrolled in for that academic year (trigger).</li>
                        <li>Roll numbers are unique inside a class and academic year, but may repeat across classes (trigger).</li>
                        <li>One attendance row per student per session, so a correction updates the row instead of adding another.</li>
                        <li>Several lectures of the same subject on the same date are allowed; the application blocks a second <em>open</em> session for the same class, subject and date.</li>
                        <li>Passwords are stored as bcrypt hashes and role, mode and status columns only accept their allowed values.</li>
                    </ul>
                </div>
            </div>
        </section>

        <section class="section status" id="status" aria-labelledby="status-title">
            <div class="container status-grid">
                <div class="status-copy">
                    <p class="section-kicker">Build progress</p>
                    <h2 id="status-title">Phase 5 turns marking into a real, signed-in workflow</h2>
                    <p class="section-text">
                        The schema, the connection layer, the seeded demo data, the
                        dashboard shell, student management and bulk import are in
                        place. Manual attendance now writes real records against a
                        teacher account.
                    </p>

                    <div class="notice" role="note">
                        <strong>Manual attendance is live behind sign-in.</strong>
                        <p>
                            Sign in, start a session for a class and subject, then mark
                            the register one roll number at a time. QR scanning, AI
                            insights and reports are still to come in later phases.
                        </p>
                    </div>
                </div>

                <ol class="phase-list">
                    <li class="phase-item is-done">
                        <span class="phase-index">01</span>
                        <div>
                            <h3>Environment setup</h3>
                            <p>Project structure, landing page, and local XAMPP verification.</p>
                        </div>
                        <span class="phase-state">Done</span>
                    </li>
                    <li class="phase-item is-done">
                        <span class="phase-index">02</span>
                        <div>
                            <h3>Database design</h3>
                            <p>MySQL schema, relationships, triggers, and connection layer.</p>
                        </div>
                        <span class="phase-state">Done</span>
                    </li>
                    <li class="phase-item is-done">
                        <span class="phase-index">03</span>
                        <div>
                            <h3>Dashboard &amp; navigation</h3>
                            <p>Responsive application shell, statistics and screen placeholders.</p>
                        </div>
                        <span class="phase-state">Done</span>
                    </li>
                    <li class="phase-item is-done">
                        <span class="phase-index">04</span>
                        <div>
                            <h3>Student management &amp; bulk import</h3>
                            <p>Add, edit and deactivate students, enrol them into classes, bulk import from CSV, and import a scanned list with real OCR.</p>
                        </div>
                        <span class="phase-state">Done</span>
                    </li>
                    <li class="phase-item is-current">
                        <span class="phase-index">05</span>
                        <div>
                            <h3>Manual attendance</h3>
                            <p>Secure sign-in, session setup, one-by-one marking in roll-number order with a configurable timer, and a locked final summary.</p>
                        </div>
                        <span class="phase-state">Current</span>
                    </li>
                    <li class="phase-item">
                        <span class="phase-index">06</span>
                        <div>
                            <h3>QR attendance</h3>
                            <p>Session QR codes and mobile check-in.</p>
                        </div>
                        <span class="phase-state">Planned</span>
                    </li>
                    <li class="phase-item">
                        <span class="phase-index">07</span>
                        <div>
                            <h3>Reports &amp; insights</h3>
                            <p>Attendance reports, summaries, and AI-assisted analysis.</p>
                        </div>
                        <span class="phase-state">Planned</span>
                    </li>
                </ol>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="container footer-inner">
            <p>&copy; <?= $currentYear ?> <?= e($projectName) ?></p>
            <p>Diploma Computer Engineering · DBMS Project</p>
            <p class="footer-phase"><?= e($phaseLabel) ?></p>
        </div>
    </footer>
</body>
</html>
