<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

$projectName    = 'SMART ATTENDANCE AI';
$projectTagline = 'Dual-Mode Digital Student Attendance Management System';
$phaseLabel     = 'PHASE 1 — ENVIRONMENT SETUP';
$currentYear    = (int) date('Y');

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Smart Attendance AI — a dual-mode digital student attendance management system. Phase 1 foundation preview.">
    <meta name="theme-color" content="#050b1a">
    <title><?= e($projectName) ?> | Phase 1 Foundation</title>
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
                        <a class="btn btn-primary" href="#modes">Explore the modes</a>
                        <a class="btn btn-ghost" href="#status">View phase status</a>
                    </div>

                    <p class="hero-note">
                        Foundation build only — attendance tracking, QR scanning, and database
                        connectivity are not implemented yet.
                    </p>
                </div>

                <aside class="system-card" aria-labelledby="snapshot-title">
                    <div class="system-card-head">
                        <h2 id="snapshot-title">Project snapshot</h2>
                        <span class="chip">Phase 1 of 6</span>
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
                            <span class="snapshot-value"><span class="dot dot-amber" aria-hidden="true"></span>Phase 2</span>
                        </li>
                        <li>
                            <span class="snapshot-label">Attendance logic</span>
                            <span class="snapshot-value muted">Not implemented</span>
                        </li>
                    </ul>

                    <p class="system-card-foot">
                        This page confirms the project foundation renders correctly. No live
                        attendance data is read or stored.
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
                        Both modes are described below so the project scope is clear. They are
                        planned features and are not functional in Phase 1.
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
                        <span class="mode-tag">Planned mode</span>
                        <h3>Manual Attendance</h3>
                        <p>
                            Roll-number-based attendance for the classroom. A teacher will enter
                            or tick roll numbers for the active class session.
                        </p>
                        <ul class="mode-points">
                            <li>Roll-number entry per class</li>
                            <li>Present and absent review</li>
                            <li>Simple, fast classroom workflow</li>
                        </ul>
                        <p class="mode-status">Not implemented yet</p>
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
                        <p class="mode-status">Not implemented yet</p>
                    </article>
                </div>
            </div>
        </section>

        <section class="section status" id="status" aria-labelledby="status-title">
            <div class="container status-grid">
                <div class="status-copy">
                    <p class="section-kicker">Build progress</p>
                    <h2 id="status-title">Phase 1 creates the foundation only</h2>
                    <p class="section-text">
                        The current deliverable is the project structure and this responsive
                        landing page. Database integration begins in Phase 2.
                    </p>

                    <div class="notice" role="note">
                        <strong>Attendance features are not available.</strong>
                        <p>
                            Login, student records, manual attendance, QR scanning, OCR, AI
                            insights, and reports are not part of this phase. Nothing on this
                            page records or stores attendance.
                        </p>
                    </div>
                </div>

                <ol class="phase-list">
                    <li class="phase-item is-current">
                        <span class="phase-index">01</span>
                        <div>
                            <h3>Environment setup</h3>
                            <p>Project structure, landing page, and local XAMPP verification.</p>
                        </div>
                        <span class="phase-state">Current</span>
                    </li>
                    <li class="phase-item">
                        <span class="phase-index">02</span>
                        <div>
                            <h3>Database design</h3>
                            <p>MySQL schema, relationships, and connection configuration.</p>
                        </div>
                        <span class="phase-state">Next</span>
                    </li>
                    <li class="phase-item">
                        <span class="phase-index">03</span>
                        <div>
                            <h3>Authentication &amp; students</h3>
                            <p>Accounts, roles, and student record management.</p>
                        </div>
                        <span class="phase-state">Planned</span>
                    </li>
                    <li class="phase-item">
                        <span class="phase-index">04</span>
                        <div>
                            <h3>Manual attendance</h3>
                            <p>Roll-number-based marking and session records.</p>
                        </div>
                        <span class="phase-state">Planned</span>
                    </li>
                    <li class="phase-item">
                        <span class="phase-index">05</span>
                        <div>
                            <h3>QR attendance</h3>
                            <p>Session QR codes and mobile check-in.</p>
                        </div>
                        <span class="phase-state">Planned</span>
                    </li>
                    <li class="phase-item">
                        <span class="phase-index">06</span>
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
