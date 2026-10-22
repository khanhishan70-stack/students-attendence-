<?php
/**
 * SMART ATTENDANCE AI - PHASE 3
 * Dashboard screen.
 *
 * All figures are read from the smart_attendance database through the shared
 * data layer. If the database is not reachable the page shows clear empty
 * states instead of invented numbers.
 */

declare(strict_types=1);

require __DIR__ . '/includes/layout.php';
require __DIR__ . '/includes/data.php';

$data = fetch_dashboard_data();

$averageLabel = $data['average'] === null ? '—' : number_format($data['average'], 1) . '%';

render_page_start('dashboard', 'Attendance overview');
?>
<section class="page-head">
    <div class="page-head-copy">
        <p class="page-kicker">Phase 3 · interface preview</p>
        <h2 class="page-title">Today at a glance</h2>
        <p class="page-sub">
            Live figures read from the <code>smart_attendance</code> database. Attendance
            marking screens are built in later phases.
        </p>
    </div>

    <?php if ($data['available']): ?>
        <p class="db-chip db-chip-ok">
            <span class="dot dot-green" aria-hidden="true"></span>Database connected
        </p>
    <?php else: ?>
        <p class="db-chip db-chip-off">
            <span class="dot dot-red" aria-hidden="true"></span>Database unavailable
        </p>
    <?php endif; ?>
</section>

<?php if (!$data['available']): ?>
    <div class="notice notice-danger" role="status">
        <strong>The dashboard cannot read the database right now.</strong>
        <p>
            Start MySQL from the XAMPP Control Panel, import
            <code>database/schema.sql</code> and reload this page. No figures are shown
            because no data could be verified.
        </p>
    </div>
<?php endif; ?>

<section class="stat-grid" aria-label="Attendance statistics">
    <article class="stat-card">
        <span class="stat-icon stat-icon-blue" aria-hidden="true"><?= app_icon('users') ?></span>
        <div class="stat-body">
            <p class="stat-label">Total Students</p>
            <p class="stat-value" data-count="<?= $data['students'] ?>"><?= number_format($data['students']) ?></p>
            <p class="stat-hint">Active student profiles</p>
        </div>
    </article>

    <article class="stat-card">
        <span class="stat-icon stat-icon-green" aria-hidden="true"><?= app_icon('check') ?></span>
        <div class="stat-body">
            <p class="stat-label">Present Today</p>
            <p class="stat-value" data-count="<?= $data['present_today'] ?>"><?= number_format($data['present_today']) ?></p>
            <p class="stat-hint">
                <?= $data['sessions_today'] > 0
                    ? $data['sessions_today'] . ' session' . ($data['sessions_today'] === 1 ? '' : 's') . ' scheduled today'
                    : 'No session scheduled today' ?>
            </p>
        </div>
    </article>

    <article class="stat-card">
        <span class="stat-icon stat-icon-amber" aria-hidden="true"><?= app_icon('users') ?></span>
        <div class="stat-body">
            <p class="stat-label">Absent Today</p>
            <p class="stat-value" data-count="<?= $data['absent_today'] ?>"><?= number_format($data['absent_today']) ?></p>
            <p class="stat-hint">Recorded against today’s sessions</p>
        </div>
    </article>

    <article class="stat-card">
        <span class="stat-icon stat-icon-violet" aria-hidden="true"><?= app_icon('chart') ?></span>
        <div class="stat-body">
            <p class="stat-label">Average Attendance</p>
            <p class="stat-value" data-count="<?= $data['average'] === null ? '' : $data['average'] ?>"
               data-suffix="<?= $data['average'] === null ? '' : '%' ?>"><?= e($averageLabel) ?></p>
            <p class="stat-hint">
                <?= $data['marked_total'] > 0
                    ? 'Across ' . number_format($data['marked_total']) . ' marked records'
                    : 'No marked records yet' ?>
            </p>
        </div>
    </article>
</section>

<div class="dash-grid">
    <div class="dash-main">
        <section class="panel" aria-labelledby="sessions-title">
            <div class="panel-head">
                <div>
                    <h3 id="sessions-title">Recent Attendance Sessions</h3>
                    <p class="panel-sub">Latest lectures and their marking progress</p>
                </div>
                <span class="badge badge-blue"><?= count($data['sessions']) ?> shown</span>
            </div>

            <?php if ($data['sessions'] === []): ?>
                <?php render_empty_state(
                    'No sessions yet',
                    'Attendance sessions appear here as soon as a lecture is created.',
                    'check'
                ); ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table" data-responsive>
                        <caption class="sr-only">Recent attendance sessions with present, absent and pending counts</caption>
                        <thead>
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Session</th>
                                <th scope="col">Class</th>
                                <th scope="col">Mode</th>
                                <th scope="col" class="num">Present</th>
                                <th scope="col" class="num">Absent</th>
                                <th scope="col" class="num">Pending</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['sessions'] as $session): ?>
                                <?php
                                $modeClass = $session['attendance_mode'] === 'qr' ? 'badge-green' : 'badge-blue';
                                $statusClass = match ($session['status']) {
                                    'open'  => 'badge-green',
                                    'closed'=> 'badge-blue',
                                    default => 'badge-grey',
                                };
                                ?>
                                <tr>
                                    <td data-label="Date">
                                        <time datetime="<?= e(date('Y-m-d', strtotime((string) $session['session_date']))) ?>">
                                            <?= e(date('j M Y', strtotime((string) $session['session_date']))) ?>
                                        </time>
                                    </td>
                                    <td data-label="Session">
                                        <span class="cell-strong"><?= e($session['subject_name']) ?></span>
                                        <span class="cell-sub"><?= e($session['subject_code']) ?> · <?= e($session['session_label']) ?></span>
                                    </td>
                                    <td data-label="Class">
                                        <?= e($session['division']) ?>
                                        <span class="cell-sub"><?= e($session['academic_year']) ?></span>
                                    </td>
                                    <td data-label="Mode">
                                        <span class="badge <?= e($modeClass) ?>"><?= e(strtoupper((string) $session['attendance_mode'])) ?></span>
                                    </td>
                                    <td data-label="Present" class="num"><span class="count count-green"><?= (int) $session['present_count'] ?></span></td>
                                    <td data-label="Absent" class="num"><span class="count count-red"><?= (int) $session['absent_count'] ?></span></td>
                                    <td data-label="Pending" class="num"><span class="count count-muted"><?= (int) $session['pending_count'] ?></span></td>
                                    <td data-label="Status">
                                        <span class="badge <?= e($statusClass) ?>"><?= e(ucfirst((string) $session['status'])) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel" aria-labelledby="overview-title">
            <div class="panel-head">
                <div>
                    <h3 id="overview-title">Attendance Overview</h3>
                    <p class="panel-sub">Present as a share of marked records, last 7 days</p>
                </div>
                <?php if ($data['overview'] !== []): ?>
                    <span class="badge badge-grey"><?= count($data['overview']) ?> day(s) with data</span>
                <?php endif; ?>
            </div>

            <?php if ($data['overview'] === []): ?>
                <?php render_empty_state(
                    'No attendance data to chart yet',
                    'Once lectures are marked, the daily attendance rate is drawn here.',
                    'chart'
                ); ?>
            <?php else: ?>
                <div class="chart" role="img"
                     aria-label="Attendance percentage per day for the last seven days that contain marked records">
                    <?php foreach ($data['overview'] as $day): ?>
                        <?php $height = max(2.0, (float) $day['percentage']); ?>
                        <div class="chart-col">
                            <span class="chart-value"><?= e(number_format((float) $day['percentage'], 0)) ?>%</span>
                            <div class="chart-track">
                                <div class="chart-bar<?= $day['percentage'] < 75 ? ' is-low' : '' ?>"
                                     style="--bar-height: <?= e((string) $height) ?>%"></div>
                            </div>
                            <span class="chart-label"><?= e($day['label']) ?></span>
                            <span class="chart-meta"><?= (int) $day['present'] ?>/<?= (int) $day['marked'] ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="chart-legend">
                    <span class="legend-dot legend-dot-green" aria-hidden="true"></span> 75% or above
                    <span class="legend-dot legend-dot-amber" aria-hidden="true"></span> below 75%
                </p>
            <?php endif; ?>
        </section>

        <section class="panel" aria-labelledby="low-title">
            <div class="panel-head">
                <div>
                    <h3 id="low-title">Low Attendance</h3>
                    <p class="panel-sub">Students below 75% in the recorded sessions</p>
                </div>
                <?php if ($data['low'] !== []): ?>
                    <span class="badge badge-amber"><?= count($data['low']) ?> student(s)</span>
                <?php endif; ?>
            </div>

            <?php if ($data['low'] === []): ?>
                <?php render_empty_state(
                    'No student below the threshold',
                    'Everyone with marked records is currently at or above 75% attendance.',
                    'check'
                ); ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table" data-responsive>
                        <caption class="sr-only">Students with attendance below 75 percent</caption>
                        <thead>
                            <tr>
                                <th scope="col">Roll No.</th>
                                <th scope="col">Student</th>
                                <th scope="col">Class</th>
                                <th scope="col" class="num">Present / Marked</th>
                                <th scope="col" class="num">Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['low'] as $student): ?>
                                <tr>
                                    <td data-label="Roll No."><span class="cell-strong"><?= e($student['roll']) ?></span></td>
                                    <td data-label="Student"><?= e($student['name']) ?></td>
                                    <td data-label="Class">
                                        <?= e($student['division']) ?>
                                        <span class="cell-sub"><?= e($student['year']) ?></span>
                                    </td>
                                    <td data-label="Present / Marked" class="num">
                                        <?= (int) $student['present'] ?> / <?= (int) $student['marked'] ?>
                                    </td>
                                    <td data-label="Rate" class="num">
                                        <span class="badge badge-amber"><?= e(number_format((float) $student['percentage'], 1)) ?>%</span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <aside class="dash-side">
        <section class="panel" aria-labelledby="quick-title">
            <div class="panel-head">
                <div>
                    <h3 id="quick-title">Quick Actions</h3>
                    <p class="panel-sub">Jump to a workspace</p>
                </div>
            </div>

            <ul class="quick-grid">
                <li>
                    <a class="quick-card" href="students.php">
                        <span class="quick-icon quick-icon-blue" aria-hidden="true"><?= app_icon('users') ?></span>
                        <span class="quick-text">
                            <strong>Manage Students</strong>
                            <span>Add, edit and deactivate student records</span>
                        </span>
                        <span class="badge badge-grey">Phase 4</span>
                    </a>
                </li>
                <li>
                    <a class="quick-card" href="classes.php">
                        <span class="quick-icon quick-icon-green" aria-hidden="true"><?= app_icon('school') ?></span>
                        <span class="quick-text">
                            <strong>Manage Classes</strong>
                            <span>Departments, academic years and divisions</span>
                        </span>
                        <span class="badge badge-grey">Phase 4</span>
                    </a>
                </li>
                <li>
                    <a class="quick-card" href="subjects.php">
                        <span class="quick-icon quick-icon-violet" aria-hidden="true"><?= app_icon('book') ?></span>
                        <span class="quick-text">
                            <strong>Manage Subjects</strong>
                            <span>Subject codes and names per class</span>
                        </span>
                        <span class="badge badge-grey">Phase 4</span>
                    </a>
                </li>
                <li>
                    <a class="quick-card" href="manual-attendance.php">
                        <span class="quick-icon quick-icon-blue" aria-hidden="true"><?= app_icon('check') ?></span>
                        <span class="quick-text">
                            <strong>Take Manual Attendance</strong>
                            <span>Mark a class register by roll number</span>
                        </span>
                        <span class="badge badge-grey">Phase 5</span>
                    </a>
                </li>
                <li>
                    <a class="quick-card" href="qr-attendance.php">
                        <span class="quick-icon quick-icon-green" aria-hidden="true"><?= app_icon('qr') ?></span>
                        <span class="quick-text">
                            <strong>Start QR Attendance</strong>
                            <span>Open a session and let students scan</span>
                        </span>
                        <span class="badge badge-grey">Phase 6</span>
                    </a>
                </li>
                <li>
                    <a class="quick-card" href="reports.php">
                        <span class="quick-icon quick-icon-violet" aria-hidden="true"><?= app_icon('chart') ?></span>
                        <span class="quick-text">
                            <strong>View Reports</strong>
                            <span>Class wise and student wise summaries</span>
                        </span>
                        <span class="badge badge-grey">Phase 7</span>
                    </a>
                </li>
            </ul>

            <p class="panel-foot">
                These shortcuts open the screen shells only. The screens themselves are
                implemented in the phases shown above.
            </p>
        </section>

        <section class="panel" aria-labelledby="today-title">
            <div class="panel-head">
                <div>
                    <h3 id="today-title">Status</h3>
                    <p class="panel-sub">Connection and coverage</p>
                </div>
            </div>

            <ul class="status-list">
                <li>
                    <span class="status-label">Database</span>
                    <span class="status-value">
                        <?php if ($data['available']): ?>
                            <span class="badge badge-green">Connected</span>
                        <?php else: ?>
                            <span class="badge badge-red">Unavailable</span>
                        <?php endif; ?>
                    </span>
                </li>
                <li>
                    <span class="status-label">Sessions today</span>
                    <span class="status-value"><?= (int) $data['sessions_today'] ?></span>
                </li>
                <li>
                    <span class="status-label">Marked records</span>
                    <span class="status-value"><?= number_format((int) $data['marked_total']) ?></span>
                </li>
                <li>
                    <span class="status-label">Below 75%</span>
                    <span class="status-value"><?= count($data['low']) ?></span>
                </li>
                <li>
                    <span class="status-label">Sign-in</span>
                    <span class="status-value"><span class="badge badge-grey">Not implemented</span></span>
                </li>
            </ul>
        </section>
    </aside>
</div>
<?php
render_page_end();
