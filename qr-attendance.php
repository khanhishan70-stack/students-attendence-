<?php
/**
 * SMART ATTENDANCE AI - PHASE 6
 * QR Attendance — teacher interface.
 *
 * States:
 *   - Setup: pick class, subject, date, token TTL, label → start session
 *   - Live:  shows QR code, countdown, live attendance list, refresh/close
 *   - Closed: summary with final counts
 */

declare(strict_types=1);

require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/qr.php';
require_once __DIR__ . '/includes/attendance.php';

require_login();

$authUser = auth_user();
$databaseAvailable = db_is_available();

$session = null;
$setupErr  = [];
$setupOld  = [];
$currentToken   = '';
$tokenExpiresAt = '';
$liveAttend     = [];
$summary        = [];

if ($databaseAvailable) {
    $pdo = db();
}

if (isset($_GET['session'])) {
    $sessionId = (int) $_GET['session'];
    $session = $databaseAvailable ? qr_session_find($sessionId) : null;

    if ($session !== null && (int) $session['created_by'] !== $authUser['id'] && ($authUser['role'] ?? '') !== 'admin') {
        // Not the owner and not admin - treat as not found
        $session = null;
    }

    if ($session !== null) {
        $currentToken   = (string) ($session['current_token_hash'] ? 'HIDDEN' : ''); // never expose raw token in PHP
        $tokenExpiresAt = (string) ($session['token_expires_at'] ?? '');
        $liveAttend     = $databaseAvailable ? qr_session_live_attendance($sessionId) : [];
        $summary        = $databaseAvailable ? qr_session_summary($sessionId) : [];
    }
}

/* --- POST actions: start / refresh / close --- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $databaseAvailable) {
    csrf_require();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'start') {
        $result = qr_session_start([
            'class_id'       => (int) ($_POST['class_id'] ?? 0),
            'subject_id'     => (int) ($_POST['subject_id'] ?? 0),
            'session_date'   => trim((string) ($_POST['session_date'] ?? '')),
            'token_ttl'      => (int) ($_POST['token_ttl'] ?? QR_DEFAULT_TOKEN_TTL),
            'session_label'  => trim((string) ($_POST['session_label'] ?? '')),
        ], (int) $authUser['id']);

        if ($result['ok']) {
            flash_set('success', $result['message']);
            header('Location: qr-attendance.php?session=' . $result['session_id']);
            exit;
        } else {
            $setupErr = ['form' => $result['message']];
            $setupOld = [
                'class_id'            => (int) ($_POST['class_id'] ?? 0),
                'subject_id'          => (int) ($_POST['subject_id'] ?? 0),
                'session_date'        => trim((string) ($_POST['session_date'] ?? '')),
                'token_ttl'           => (int) ($_POST['token_ttl'] ?? QR_DEFAULT_TOKEN_TTL),
                'session_label'       => trim((string) ($_POST['session_label'] ?? '')),
            ];
        }
    }
    elseif ($action === 'refresh_token' && isset($_POST['session_id'])) {
        $result = qr_session_refresh_token(
            (int) $_POST['session_id'],
            (int) $authUser['id'],
            (int) ($_POST['token_ttl'] ?? QR_DEFAULT_TOKEN_TTL)
        );
        if ($result['ok']) {
            flash_set('success', 'QR code refreshed.');
        } else {
            flash_set('error', $result['message']);
        }
        header('Location: qr-attendance.php?session=' . (int) $_POST['session_id']);
        exit;
    }
    elseif ($action === 'close_session' && isset($_POST['session_id'])) {
        $result = qr_session_close((int) $_POST['session_id'], (int) $authUser['id']);
        if ($result['ok']) {
            flash_set('success', $result['message']);
        } else {
            flash_set('error', $result['message']);
        }
        header('Location: qr-attendance.php?session=' . (int) $_POST['session_id']);
        exit;
    }
}

/* --- Restore failed setup --- */
if (empty($setupErr) && empty($setupOld)) {
    $stored = $_SESSION['qr_setup'] ?? null;
    unset($_SESSION['qr_setup']);
    if (is_array($stored)) {
        $setupErr = $stored['errors'] ?? [];
        $setupOld = $stored['values'] ?? [];
    }
}

/* --- Reference data for the setup form --- */
$classes  = [];
$subjects = [];
$recent   = [];

if ($databaseAvailable) {
    $classes  = attendance_classes($pdo);
    $subjects = attendance_all_subjects($pdo);
    $recent   = qr_recent_sessions(8);
}

$today = date('Y-m-d');

/* ================================================================
     RENDER
     ================================================================ */

render_page_start('qr', 'QR Attendance', ['assets/js/qr-attendance.js']);
?>
<?php if (!$databaseAvailable): ?>
    <div class="notice notice-danger" role="status">
        <strong>The database is not reachable right now.</strong>
        <p>Start MySQL from the XAMPP Control Panel and reload this page. No QR session can be started offline.</p>
    </div>
<?php endif; ?>

<?php if ($session === null): ?>
    <!-- ================================================================
         SCREEN 1 — SESSION SETUP
         ============================================================== -->
    <section class="page-head">
        <div class="page-head-copy">
            <p class="page-kicker">Phase 6 · QR attendance</p>
            <h2 class="page-title">Start a QR attendance session</h2>
            <p class="page-sub">
                Choose the class, subject and date. The QR code will rotate every
                <?= QR_REFRESH_INTERVAL ?> seconds. Students scan it with their phone camera
                and sign in to mark themselves present.
            </p>
        </div>
    </section>

    <section class="card" aria-labelledby="setup-title">
        <h3 id="setup-title">Session setup</h3>

        <form class="form-grid" method="post" action="qr-attendance.php" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="start">

            <?php render_field([
                'name'     => 'class_id',
                'label'    => 'Class',
                'type'     => 'select',
                'value'    => (string) ($setupOld['class_id'] ?? ''),
                'required' => true,
                'error'    => (string) ($setupErr['class_id'] ?? ''),
                'options'  => array_merge(
                    [['value' => '', 'label' => 'Choose a class…']],
                    array_map(fn($c) => [
                        'value' => (string) $c['id'],
                        'label' => $c['department'] . ' · ' . $c['academic_year'] . ' · Div ' . $c['division'],
                    ], $classes)
                ),
            ]); ?>

            <?php render_field([
                'name'     => 'subject_id',
                'label'    => 'Subject',
                'type'     => 'select',
                'value'    => (string) ($setupOld['subject_id'] ?? ''),
                'required' => true,
                'error'    => (string) ($setupErr['subject_id'] ?? ''),
                'options'  => array_merge(
                    [['value' => '', 'label' => 'Choose a subject…']],
                    array_map(fn($s) => [
                        'value'        => (string) $s['id'],
                        'label'        => $s['subject_code'] . ' — ' . $s['subject_name'],
                        'class_id'     => (int) $s['class_id'],
                    ], $subjects)
                ),
            ]); ?>

            <?php render_field([
                'name'        => 'session_date',
                'label'       => 'Date',
                'type'        => 'date',
                'value'       => (string) ($setupOld['session_date'] ?? $today),
                'required'    => true,
                'error'       => (string) ($setupErr['session_date'] ?? ''),
            ]); ?>

            <?php render_field([
                'name'        => 'token_ttl',
                'label'       => 'QR token lifetime (seconds)',
                'type'        => 'number',
                'value'       => (string) ($setupOld['token_ttl'] ?? QR_DEFAULT_TOKEN_TTL),
                'required'    => true,
                'error'       => (string) ($setupErr['token_ttl'] ?? ''),
                'hint'        => 'How long each displayed QR code is valid. Between ' . QR_MIN_TOKEN_TTL . ' and ' . QR_MAX_TOKEN_TTL . ' seconds. Default ' . QR_DEFAULT_TOKEN_TTL . '.',
            ]); ?>

            <?php render_field([
                'name'     => 'session_label',
                'label'    => 'Session label (optional)',
                'type'     => 'input',
                'value'    => (string) ($setupOld['session_label'] ?? ''),
                'hint'     => 'e.g. "Lecture 1", "Lab session". Defaults to "Lecture".',
            ]); ?>

            <?php if (isset($setupErr['form'])): ?>
                <div class="flash flash-error" role="alert" style="grid-column: 1 / -1;">
                    <span class="dot dot-red" aria-hidden="true"></span>
                    <p><?= e($setupErr['form']) ?></p>
                </div>
            <?php endif; ?>

            <div class="form-actions" style="grid-column: 1 / -1;">
                <button class="btn btn-primary" type="submit">Start QR session</button>
                <a class="btn btn-ghost" href="dashboard.php">Cancel</a>
            </div>
        </form>
    </section>

    <?php if ($recent !== []): ?>
        <section class="card" aria-labelledby="recent-title">
            <h3 id="recent-title">Recent QR sessions</h3>
            <p class="card-sub">Resume an open session instead of starting a new one.</p>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Class</th>
                            <th scope="col">Subject</th>
                            <th scope="col">Label</th>
                            <th scope="col">Status</th>
                            <th scope="col">Present</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $r): ?>
                            <tr>
                                <td><?= e($r['session_date']) ?></td>
                                <td><?= e($r['division']) ?> (<?= e($r['academic_year']) ?>)</td>
                                <td><?= e($r['subject_code']) ?></td>
                                <td><?= e($r['session_label']) ?></td>
                                <td>
                                    <?php
                                    $badge = match ($r['status']) {
                                        'open'      => '<span class="badge badge-green">Open</span>',
                                        'scheduled' => '<span class="badge badge-amber">Scheduled</span>',
                                        default     => '<span class="badge badge-grey">Closed</span>',
                                    };
                                    echo $badge;
                                    ?>
                                </td>
                                <td><?= (int) $r['present_count'] ?></td>
                                <td>
                                    <?php if ((string) $r['status'] === 'open'): ?>
                                        <a class="btn btn-primary btn-sm" href="qr-attendance.php?session=<?= (int) $r['id'] ?>">Open</a>
                                    <?php else: ?>
                                        <span class="badge badge-grey">Finished</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

<?php else: ?>
    <!-- ================================================================
         SCREEN 2 — LIVE QR SESSION / CLOSED SUMMARY
         ============================================================== -->
    <?php
    $isClosed = (string) $session['status'] === 'closed';
    $checkinUrl = 'qr-checkin.php?session=' . (int) $session['id'] . '&token='; // token appended by JS
    ?>
    <section class="page-head">
        <div class="page-head-copy">
            <p class="page-kicker">
                Phase 6 · QR attendance
                <?php if ($isClosed): ?><span class="badge badge-grey" style="margin-left: .5rem;">Closed</span><?php endif; ?>
            </p>
            <h2 class="page-title">
                <?= e($session['department']) ?> · <?= e($session['division']) ?> (<?= e($session['academic_year']) ?>)
                <br><small style="font-weight:400"> <?= e($session['subject_code']) ?> — <?= e($session['subject_name']) ?> · <?= e($session['session_label']) ?></small>
            </h2>
            <p class="page-sub">
                Session started <?= e(date('H:i', strtotime($session['started_at']))) ?>
                <?php if ($isClosed && $session['closed_at']): ?> · Closed <?= e(date('H:i', strtotime($session['closed_at']))) ?><?php endif; ?>
            </p>
        </div>
    </section>

    <?php if (!$isClosed): ?>
        <!-- LIVE QR CODE + COUNTDOWN -->
        <section class="card qr-live-card" aria-labelledby="qr-title">
            <h3 id="qr-title">Scan to check in</h3>

            <div class="qr-display" id="qr-display">
                <div class="qr-code-wrap" aria-live="polite">
                    <svg class="qr-svg" id="qr-svg" viewBox="0 0 220 220" aria-hidden="true" role="img"
                         aria-label="QR code for attendance check-in"></svg>
                </div>

                <div class="qr-meta">
                    <div class="qr-countdown" id="qr-countdown" aria-live="assertive" aria-atomic="true">
                        <span class="countdown-label">Next code in</span>
                        <span class="countdown-value" id="countdown-value">--</span>
                        <span class="countdown-unit">s</span>
                    </div>
                    <p class="qr-instruction">
                        Students scan with their phone camera.<br>
                        The code refreshes every <strong><?= QR_REFRESH_INTERVAL ?> seconds</strong>.
                    </p>
                </div>
            </div>

            <div class="qr-controls">
                <div class="session-stats" aria-live="polite">
                    <div class="stat">
                        <span class="stat-value badge badge-green" id="stat-present"><?= $summary['present'] ?></span>
                        <span class="stat-label">Present</span>
                    </div>
                    <div class="stat">
                        <span class="stat-value badge badge-grey" id="stat-not-marked"><?= $summary['not_marked'] ?></span>
                        <span class="stat-label">Not marked</span>
                    </div>
                    <div class="stat">
                        <span class="stat-value badge badge-red" id="stat-absent"><?= $summary['absent'] ?></span>
                        <span class="stat-label">Absent</span>
                    </div>
                    <div class="stat">
                        <span class="stat-value badge badge-amber" id="stat-review"><?= $summary['review'] ?></span>
                        <span class="stat-label">Review</span>
                    </div>
                </div>

                <form method="post" action="qr-attendance.php" class="qr-actions" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="refresh_token">
                    <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
                    <input type="hidden" name="token_ttl" value="<?= (int) ($session['seconds_per_student'] ?? QR_DEFAULT_TOKEN_TTL) ?>">
                    <button class="btn btn-primary" type="submit" id="btn-refresh">
                        <?= app_icon('spark') ?> Refresh QR now
                    </button>
                </form>

                <form method="post" action="qr-attendance.php" class="qr-actions" style="display:inline;" onsubmit="return confirm('Close this session? Students will no longer be able to check in, and the QR code will stop working.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="close_session">
                    <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
                    <button class="btn btn-danger" type="submit" id="btn-close">
                        <?= app_icon('flag') ?> Close session
                    </button>
                </form>
            </div>
        </section>

        <!-- LIVE ATTENDANCE LIST -->
        <section class="card" aria-labelledby="live-title">
            <h3 id="live-title">Live check-ins</h3>
            <p class="card-sub" id="live-update-hint">Auto-refreshes every 5 seconds.</p>

            <div class="table-wrap" id="live-table-wrap">
                <table class="data-table" id="live-table">
                    <thead>
                        <tr>
                            <th scope="col">Roll</th>
                            <th scope="col">Name</th>
                            <th scope="col">Status</th>
                            <th scope="col">Marked at</th>
                            <th scope="col">Source</th>
                        </tr>
                    </thead>
                    <tbody id="live-tbody">
                        <?php if ($liveAttend === []): ?>
                            <tr><td colspan="5" class="empty-row">No students enrolled in this class.</td></tr>
                        <?php else: ?>
                            <?php foreach ($liveAttend as $row): ?>
                                <tr data-student-id="<?= (int) $row['student_id'] ?>" class="<?= $row['is_present'] ? 'is-present' : '' ?>">
                                    <td data-label="Roll"><?= e($row['roll_number']) ?></td>
                                    <td data-label="Name"><?= e($row['full_name']) ?></td>
                                    <td data-label="Status"><?= attendance_status_badge($row['status']) ?></td>
                                    <td data-label="Marked at"><?= $row['marked_at'] ? e(date('H:i:s', strtotime($row['marked_at']))) : '<span class="muted">—</span>' ?></td>
                                    <td data-label="Source">
                                        <span class="badge <?= $row['source'] === 'qr' ? 'badge-blue' : 'badge-grey' ?>">
                                            <?= e(ucfirst($row['source'])) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    <?php else: ?>
        <!-- CLOSED SESSION SUMMARY -->
        <section class="card qr-summary-card" aria-labelledby="summary-title">
            <h3 id="summary-title">Session closed</h3>

            <div class="summary-stats" aria-live="polite">
                <div class="stat stat-large">
                    <span class="stat-value badge badge-green"><?= $summary['present'] ?></span>
                    <span class="stat-label">Present</span>
                </div>
                <div class="stat stat-large">
                    <span class="stat-value badge badge-red"><?= $summary['absent'] ?></span>
                    <span class="stat-label">Absent</span>
                </div>
                <div class="stat stat-large">
                    <span class="stat-value badge badge-amber"><?= $summary['review'] ?></span>
                    <span class="stat-label">Review</span>
                </div>
                <div class="stat stat-large">
                    <span class="stat-value badge badge-grey"><?= $summary['not_marked'] ?></span>
                    <span class="stat-label">Not marked</span>
                </div>
                <div class="stat stat-large">
                    <span class="stat-value"><?= $summary['total'] ?></span>
                    <span class="stat-label">Total enrolled</span>
                </div>
            </div>

            <div class="notice notice-info" role="note">
                <strong>Session locked.</strong> No further check-ins are accepted.
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Roll</th>
                            <th scope="col">Name</th>
                            <th scope="col">Status</th>
                            <th scope="col">Marked at</th>
                            <th scope="col">Source</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($liveAttend === []): ?>
                            <tr><td colspan="5" class="empty-row">No students enrolled.</td></tr>
                        <?php else: ?>
                            <?php foreach ($liveAttend as $row): ?>
                                <tr class="<?= $row['is_present'] ? 'is-present' : '' ?>">
                                    <td data-label="Roll"><?= e($row['roll_number']) ?></td>
                                    <td data-label="Name"><?= e($row['full_name']) ?></td>
                                    <td data-label="Status"><?= attendance_status_badge($row['status']) ?></td>
                                    <td data-label="Marked at"><?= $row['marked_at'] ? e(date('H:i:s', strtotime($row['marked_at']))) : '<span class="muted">—</span>' ?></td>
                                    <td data-label="Source">
                                        <span class="badge <?= $row['source'] === 'qr' ? 'badge-blue' : 'badge-grey' ?>">
                                            <?= e(ucfirst($row['source'])) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="form-actions" style="margin-top: 1.5rem;">
                <a class="btn btn-primary" href="qr-attendance.php"><?= app_icon('plus') ?> New QR session</a>
                <a class="btn btn-ghost" href="dashboard.php"><?= app_icon('arrow') ?> Dashboard</a>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>

<?php
/* Inline config for the JS */
if ($session !== null && !$isClosed) {
    echo '<script id="qr-config" type="application/json">'
       . json_encode([
           'sessionId'      => (int) $session['id'],
           'tokenTtl'       => (int) ($session['seconds_per_student'] ?? QR_DEFAULT_TOKEN_TTL),
           'refreshInterval'=> QR_REFRESH_INTERVAL * 1000,
           'checkinBaseUrl' => 'qr-checkin.php?session=' . (int) $session['id'] . '&token=',
           'livePollUrl'    => 'qr-live.php?session=' . (int) $session['id'],
       ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
       . '</script>';
}
?>
<?php render_page_end(); ?>