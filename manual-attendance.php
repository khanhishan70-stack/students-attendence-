<?php
/**
 * SMART ATTENDANCE AI - PHASE 5
 * Manual attendance.
 *
 * Three screens in one page, driven by the query string and POST actions:
 *   1. Setup    - pick class, subject, date and seconds per student, then
 *                 start a session. Open sessions can be resumed from here.
 *   2. Marking  - one student at a time in ascending roll-number order with a
 *                 configurable countdown, pause/resume and voice announcements.
 *   3. Finish   - a summary of present / absent / review / not marked, a
 *                 warning while anyone is still unmarked, then a confirmed
 *                 close that locks the session.
 *
 * Every status write is a POST protected by CSRF and runs in a transaction.
 * The one-row-per-student rule is enforced by the database unique key, so a
 * correction updates the existing row instead of creating a duplicate.
 */

declare(strict_types=1);

require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/attendance.php';

/* Attendance is a signed-in, teacher-facing action. */
require_login();

$authUser = auth_user();

if ($authUser === null) {
    header('Location: login.php');
    exit;
}

$databaseAvailable = db_is_available();
$pdo               = null;

if ($databaseAvailable) {
    try {
        $pdo = db();
    } catch (Throwable $exception) {
        error_log('[smart-attendance] manual attendance database failed: ' . $exception->getMessage());
        $databaseAvailable = false;
    }
}

/* ---------------------------------------------------------------------------
 * POST actions. Every one redirects afterwards (Post/Redirect/Get) so a reload
 * never re-submits a mark.
 * ------------------------------------------------------------------------ */

$action = (string) ($_POST['action'] ?? '');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_require();

    if (!$databaseAvailable || $pdo === null) {
        flash_set('error', 'The database is not available right now. Nothing was saved.');
        header('Location: manual-attendance.php');
        exit;
    }

    if ($action === 'start') {
        $validation = attendance_validate_setup($_POST, $pdo);

        if ($validation['errors'] === []) {
            $result = attendance_session_create($validation['values'], (int) $authUser['id']);

            if ($result['ok']) {
                flash_set('success', 'Session started. Call out the first roll number.');
                header('Location: manual-attendance.php?session=' . $result['session_id']);
                exit;
            }

            flash_set('error', $result['message']);

            if ($result['session_id'] > 0) {
                header('Location: manual-attendance.php?session=' . $result['session_id']);
                exit;
            }

            header('Location: manual-attendance.php');
            exit;
        }

        /* Keep the teacher's choices on the form after a validation failure. */
        $_SESSION['attendance_setup'] = [
            'errors' => $validation['errors'],
            'values' => $validation['values'],
        ];

        header('Location: manual-attendance.php');
        exit;
    }

    if ($action === 'mark') {
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $status    = (string) ($_POST['status'] ?? '');
        $pos       = (int) ($_POST['pos'] ?? 1);

        $result = attendance_mark($sessionId, $studentId, $status, (int) $authUser['id']);

        /* Always move to the next student after a save attempt so the register
           keeps flowing, even when the mark was rejected. */
        $next = max(1, $pos + 1);

        flash_set($result['ok'] ? 'success' : 'error', $result['message']);

        header('Location: manual-attendance.php?session=' . $sessionId . '&pos=' . $next);
        exit;
    }

    if ($action === 'finish') {
        $sessionId = (int) ($_POST['session_id'] ?? 0);

        $result = attendance_finish($sessionId);

        flash_set($result['ok'] ? 'success' : 'error', $result['message']);

        header('Location: manual-attendance.php?session=' . $sessionId . '&done=1');
        exit;
    }

    flash_set('error', 'That action is not recognised. Nothing was changed.');
    header('Location: manual-attendance.php');
    exit;
}

/* ---------------------------------------------------------------------------
 * Read the current screen from the query string.
 * ------------------------------------------------------------------------ */

$sessionId = (int) ($_GET['session'] ?? 0);
$session   = null;
$roster    = [];
$summary   = null;
$setupOld  = [];
$setupErr  = [];

if ($sessionId > 0 && $databaseAvailable && $pdo !== null) {
    $session = attendance_session_find($sessionId);

    if ($session === null) {
        flash_set('error', 'That attendance session could not be found.');
        header('Location: manual-attendance.php');
        exit;
    }

    $roster  = attendance_roster($sessionId);
    $summary = attendance_summary($sessionId);
}

$justFinished = isset($_GET['done']);

/* Restore a failed setup attempt. */
$stored = $_SESSION['attendance_setup'] ?? null;
unset($_SESSION['attendance_setup']);

if (is_array($stored)) {
    $setupErr = $stored['errors'] ?? [];
    $setupOld = $stored['values'] ?? [];
}

/* Which student is on screen right now (1-based position in the roster). */
$pos = max(1, min((int) ($_GET['pos'] ?? 1), max(1, count($roster))));
$current = $roster[$pos - 1] ?? null;

/* Classes and subjects for the setup form. All subjects are rendered so the
   select can be filtered in the browser when the class changes; the server
   re-checks that the chosen subject belongs to the chosen class. */
$classes  = [];
$subjects = [];

if ($databaseAvailable && $pdo !== null) {
    $classes = attendance_classes($pdo);
    $subjects = attendance_all_subjects($pdo);
}

$recent = $databaseAvailable ? attendance_recent_sessions(8) : [];

$today = date('Y-m-d');

render_page_start('manual', 'Manual Attendance', ['assets/js/attendance.js']);
?>
<?php if (!$databaseAvailable): ?>
    <div class="notice notice-danger" role="status">
        <strong>The database is not reachable right now.</strong>
        <p>Start MySQL from the XAMPP Control Panel and reload this page. No attendance can be taken offline.</p>
    </div>
<?php endif; ?>

<?php if ($session === null): ?>
    <!-- ================================================================
         SCREEN 1 — session setup
         ============================================================== -->
    <section class="page-head">
        <div class="page-head-copy">
            <p class="page-kicker">Phase 5 · manual attendance</p>
            <h2 class="page-title">Start an attendance session</h2>
            <p class="page-sub">
                Choose the class and subject, confirm the date and how many seconds
                each student gets, then call the register one roll number at a time.
            </p>
        </div>
    </section>

    <?php if ($databaseAvailable): ?>
        <section class="panel" aria-labelledby="setup-title">
            <div class="panel-head">
                <div>
                    <h3 id="setup-title">Session setup</h3>
                    <p class="panel-sub">A session can only be opened once per class, subject and date</p>
                </div>
            </div>

            <div class="panel-body">
                <?php if (isset($setupErr['form'])): ?>
                    <div class="flash flash-error" role="alert">
                        <span class="dot dot-red" aria-hidden="true"></span>
                        <p><?= e($setupErr['form']) ?></p>
                    </div>
                <?php endif; ?>

                <form class="form-grid" method="post" action="manual-attendance.php" novalidate
                      data-subject-filter>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="start">

                    <?php render_field([
                        'name'     => 'class_id',
                        'label'    => 'Class',
                        'type'     => 'select',
                        'required' => true,
                        'value'    => (string) ($setupOld['class_id'] ?? ($classes[0]['id'] ?? '')),
                        'options'  => array_map(static function (array $class) use ($setupOld, $classes): array {
                            return [
                                'value'    => (string) $class['id'],
                                'label'    => $class['department'] . ' ' . $class['academic_year'] . ' · ' . $class['division'],
                                'selected' => (string) ($setupOld['class_id'] ?? ($classes[0]['id'] ?? '')) === (string) $class['id'],
                            ];
                        }, $classes),
                        'error'    => (string) ($setupErr['class_id'] ?? ''),
                    ]); ?>

                    <?php render_field([
                        'name'     => 'subject_id',
                        'label'    => 'Subject',
                        'type'     => 'select',
                        'required' => true,
                        'value'    => (string) ($setupOld['subject_id'] ?? ''),
                        'options'  => array_merge(
                            [['value' => '0', 'label' => 'Choose a subject…', 'selected' => (int) ($setupOld['subject_id'] ?? 0) === 0, 'class_id' => 0]],
                            array_map(static function (array $subject) use ($setupOld): array {
                                return [
                                    'value'    => (string) $subject['id'],
                                    'label'    => $subject['subject_code'] . ' — ' . $subject['subject_name'],
                                    'selected' => (string) ($setupOld['subject_id'] ?? '') === (string) $subject['id'],
                                    'class_id' => (int) $subject['class_id'],
                                ];
                            }, $subjects)
                        ),
                        'hint'     => 'Only subjects belonging to the chosen class are listed.',
                        'error'    => (string) ($setupErr['subject_id'] ?? ''),
                    ]); ?>

                    <?php render_field([
                        'name'     => 'session_date',
                        'label'    => 'Date',
                        'type'     => 'date',
                        'required' => true,
                        'value'    => (string) ($setupOld['session_date'] ?? $today),
                        'error'    => (string) ($setupErr['session_date'] ?? ''),
                    ]); ?>

                    <?php render_field([
                        'name'     => 'seconds_per_student',
                        'label'    => 'Seconds per student',
                        'type'     => 'number',
                        'required' => true,
                        'value'    => (string) ($setupOld['seconds_per_student'] ?? ATTENDANCE_DEFAULT_SECONDS),
                        'hint'     => 'Between ' . ATTENDANCE_MIN_SECONDS . ' and ' . ATTENDANCE_MAX_SECONDS . ' seconds. The timer never marks anyone absent on its own.',
                        'error'    => (string) ($setupErr['seconds_per_student'] ?? ''),
                    ]); ?>

                    <?php render_field([
                        'name'  => 'session_label',
                        'label' => 'Session label',
                        'type'  => 'text',
                        'value' => (string) ($setupOld['session_label'] ?? 'Lecture'),
                        'hint'  => 'Optional name shown on reports, e.g. "Lecture 2".',
                        'error' => (string) ($setupErr['session_label'] ?? ''),
                    ]); ?>

                    <div class="form-actions">
                        <button class="btn btn-primary" type="submit">
                            <?= app_icon('check') ?>Start session
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <section class="panel" aria-labelledby="recent-title">
            <div class="panel-head">
                <div>
                    <h3 id="recent-title">Recent manual sessions</h3>
                    <p class="panel-sub">Resume an open session or review one that is already finished</p>
                </div>
            </div>

            <?php if ($recent === []): ?>
                <?php render_empty_state(
                    'No manual sessions yet',
                    'Start your first session above. It will appear here so you can resume or review it.',
                    'check'
                ); ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table" data-responsive>
                        <caption class="sr-only">Recent manual attendance sessions</caption>
                        <thead>
                            <tr>
                                <th scope="col">Date</th>
                                <th scope="col">Session</th>
                                <th scope="col">Class</th>
                                <th scope="col" class="num">Present</th>
                                <th scope="col" class="num">Absent</th>
                                <th scope="col" class="num">Review</th>
                                <th scope="col" class="num">Not marked</th>
                                <th scope="col">Status</th>
                                <th scope="col">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent as $row): ?>
                                <?php
                                $open       = (string) $row['status'] === 'open';
                                $statusClass = $open ? 'badge-green' : 'badge-blue';
                                ?>
                                <tr>
                                    <td data-label="Date">
                                        <time datetime="<?= e(date('Y-m-d', strtotime((string) $row['session_date']))) ?>">
                                            <?= e(date('j M Y', strtotime((string) $row['session_date']))) ?>
                                        </time>
                                    </td>
                                    <td data-label="Session">
                                        <span class="cell-strong"><?= e((string) $row['subject_name']) ?></span>
                                        <span class="cell-sub"><?= e((string) $row['subject_code']) ?> · <?= e((string) $row['session_label']) ?></span>
                                    </td>
                                    <td data-label="Class">
                                        <?= e((string) $row['division']) ?>
                                        <span class="cell-sub"><?= e((string) $row['academic_year']) ?></span>
                                    </td>
                                    <td data-label="Present" class="num"><span class="count count-green"><?= (int) $row['present_count'] ?></span></td>
                                    <td data-label="Absent" class="num"><span class="count count-red"><?= (int) $row['absent_count'] ?></span></td>
                                    <td data-label="Review" class="num"><span class="count count-muted"><?= (int) $row['review_count'] ?></span></td>
                                    <td data-label="Not marked" class="num"><span class="count count-muted"><?= (int) $row['not_marked_count'] ?></span></td>
                                    <td data-label="Status">
                                        <span class="badge <?= e($statusClass) ?>"><?= e(ucfirst((string) $row['status'])) ?></span>
                                    </td>
                                    <td data-label="Action">
                                        <a class="btn btn-ghost btn-sm" href="manual-attendance.php?session=<?= (int) $row['id'] ?>">
                                            <?= $open ? 'Resume' : 'Review' ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

<?php elseif ($session !== null && ((string) $session['status'] !== 'open' || $justFinished)): ?>
    <!-- ================================================================
         SCREEN 3 — finished session summary
         ============================================================== -->
    <section class="page-head">
        <div class="page-head-copy">
            <p class="page-kicker">Phase 5 · manual attendance</p>
            <h2 class="page-title"><?= e((string) $session['subject_name']) ?> — session summary</h2>
            <p class="page-sub">
                <?= e((string) $session['department']) ?>
                <?= e((string) $session['academic_year']) ?> · <?= e((string) $session['division']) ?>
                · <?= e(date('j F Y', strtotime((string) $session['session_date']))) ?>
                · <?= e((string) $session['session_label']) ?>
            </p>
        </div>

        <div class="page-head-actions">
            <a class="btn btn-ghost" href="manual-attendance.php">Back to setup</a>
        </div>
    </section>

    <?php if ($justFinished && (string) $session['status'] === 'closed'): ?>
        <div class="notice notice-danger" role="status">
            <strong>This session is now closed and locked.</strong>
            <p>
                Marks can no longer be changed from this screen. There is no
                correction or reopening workflow in this phase — see the notes at
                the bottom of the screen.
            </p>
        </div>
    <?php endif; ?>

    <section class="stat-grid stat-grid-compact" aria-label="Session totals">
        <article class="stat-card">
            <div class="stat-body">
                <p class="stat-label">Present</p>
                <p class="stat-value count-green"><?= (int) $summary['present'] ?></p>
            </div>
        </article>
        <article class="stat-card">
            <div class="stat-body">
                <p class="stat-label">Absent</p>
                <p class="stat-value count-red"><?= (int) $summary['absent'] ?></p>
            </div>
        </article>
        <article class="stat-card">
            <div class="stat-body">
                <p class="stat-label">Review</p>
                <p class="stat-value"><?= (int) $summary['review'] ?></p>
            </div>
        </article>
        <article class="stat-card">
            <div class="stat-body">
                <p class="stat-label">Not marked</p>
                <p class="stat-value count-muted"><?= (int) $summary['not_marked'] ?></p>
            </div>
        </article>
        <article class="stat-card">
            <div class="stat-body">
                <p class="stat-label">Class size</p>
                <p class="stat-value"><?= (int) $summary['total'] ?></p>
            </div>
        </article>
    </section>

    <?php if ($summary['not_marked'] > 0): ?>
        <div class="notice" role="status">
            <strong><?= (int) $summary['not_marked'] ?> student(s) were never marked.</strong>
            <p>
                They stay recorded as <em>not marked</em>. They were not converted
                to absent. Their roll numbers are listed below.
            </p>
        </div>
    <?php endif; ?>

    <section class="panel" aria-labelledby="summary-roster-title">
        <div class="panel-head">
            <div>
                <h3 id="summary-roster-title">Class register</h3>
                <p class="panel-sub">Every student in the session, in roll-number order</p>
            </div>
        </div>

        <?php if ($roster === []): ?>
            <?php render_empty_state(
                'No students in this class',
                'No active student is enrolled in this class for its academic year.',
                'users'
            ); ?>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table" data-responsive>
                    <caption class="sr-only">Final register for this attendance session</caption>
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Roll No.</th>
                            <th scope="col">Student</th>
                            <th scope="col">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($roster as $row): ?>
                            <tr>
                                <td data-label="#"><?= (int) $row['position'] ?></td>
                                <td data-label="Roll No."><span class="cell-strong"><?= e((string) $row['roll_number']) ?></span></td>
                                <td data-label="Student"><?= e((string) $row['full_name']) ?></td>
                                <td data-label="Status">
                                    <?= attendance_status_badge((string) $row['status']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

<?php else: ?>
    <!-- ================================================================
         SCREEN 2 — one-by-one marking
         ============================================================== -->
    <?php
    $timerSeconds = max(ATTENDANCE_MIN_SECONDS, min((int) $session['seconds_per_student'], ATTENDANCE_MAX_SECONDS));
    $nextPos      = $pos < count($roster) ? $pos + 1 : $pos;
    ?>
    <section class="page-head">
        <div class="page-head-copy">
            <p class="page-kicker">Phase 5 · taking attendance</p>
            <h2 class="page-title"><?= e((string) $session['subject_name']) ?></h2>
            <p class="page-sub">
                <?= e((string) $session['department']) ?>
                <?= e((string) $session['academic_year']) ?> · <?= e((string) $session['division']) ?>
                · <?= e(date('j F Y', strtotime((string) $session['session_date']))) ?>
                · <?= e((string) $session['session_label']) ?>
            </p>
        </div>

        <div class="page-head-actions">
            <span class="badge badge-green">Open</span>
            <a class="btn btn-ghost" href="manual-attendance.php">Leave session</a>

            <form method="post" action="manual-attendance.php" data-confirm
                  data-confirm-text="Finish this session? Students still not marked will stay not marked, and the session will be locked.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="finish">
                <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
                <button class="btn btn-primary" type="submit">Finish attendance</button>
            </form>
        </div>
    </section>

    <?php if ($roster === []): ?>
        <div class="notice notice-danger" role="status">
            <strong>This class has no active students to mark.</strong>
            <p>
                No student is enrolled in this class for its academic year. Add or
                enrol students, then start a new session.
            </p>
        </div>
    <?php else: ?>
        <section class="panel mark-panel" aria-labelledby="mark-title"
                 data-attendance
                 data-timer-seconds="<?= $timerSeconds ?>"
                 data-pos="<?= (int) $pos ?>"
                 data-total="<?= count($roster) ?>"
                 data-student-id="<?= (int) ($current['student_id'] ?? 0) ?>"
                 data-session-id="<?= (int) $session['id'] ?>"
                 data-next-url="manual-attendance.php?session=<?= (int) $session['id'] ?>&pos=<?= $nextPos ?>"
                 data-current-url="manual-attendance.php?session=<?= (int) $session['id'] ?>&pos=<?= (int) $pos ?>"
                 data-voice="<?= e((string) $session['roll_number']) ?>|<?= e((string) $session['full_name']) ?>">

            <!-- live counts -->
            <div class="mark-counts" aria-label="Running totals">
                <div class="mark-count mark-count-green">
                    <span class="mark-count-num"><?= (int) $summary['present'] ?></span>
                    <span class="mark-count-label">Present</span>
                </div>
                <div class="mark-count mark-count-red">
                    <span class="mark-count-num"><?= (int) $summary['absent'] ?></span>
                    <span class="mark-count-label">Absent</span>
                </div>
                <div class="mark-count mark-count-amber">
                    <span class="mark-count-num"><?= (int) $summary['review'] ?></span>
                    <span class="mark-count-label">Review</span>
                </div>
                <div class="mark-count mark-count-muted">
                    <span class="mark-count-num"><?= (int) $summary['not_marked'] ?></span>
                    <span class="mark-count-label">Not marked</span>
                </div>
            </div>

            <div class="mark-body">
                <!-- the current student -->
                <?php if ($current !== null): ?>
                    <div class="mark-student" id="mark-title">
                        <p class="mark-position">
                            Student <strong><?= (int) $current['position'] ?></strong>
                            of <?= count($roster) ?>
                        </p>

                        <div class="mark-avatar" aria-hidden="true">
                            <?= e(student_initials((string) $current['full_name'])) ?>
                        </div>

                        <p class="mark-roll"><?= e((string) $current['roll_number']) ?></p>
                        <p class="mark-name"><?= e((string) $current['full_name']) ?></p>

                        <p class="mark-current">
                            Current status:
                            <?= attendance_status_badge((string) $current['status']) ?>
                        </p>
                    </div>

                    <!-- countdown -->
                    <div class="mark-timer" data-timer
                         role="timer" aria-live="off" aria-atomic="true">
                        <div class="mark-timer-ring" data-timer-ring
                             style="--p: 100">
                            <span class="mark-timer-num" data-timer-num><?= $timerSeconds ?></span>
                            <span class="mark-timer-unit">sec</span>
                        </div>
                        <p class="mark-timer-hint" data-timer-hint>
                            Each student gets <?= $timerSeconds ?> seconds. The timer
                            never marks anyone absent on its own.
                        </p>
                        <button class="btn btn-ghost btn-sm" type="button" data-timer-toggle>
                            <?= app_icon('pause') ?><span data-timer-toggle-text>Pause</span>
                        </button>
                    </div>

                    <!-- the four status buttons -->
                    <form class="mark-actions" method="post" action="manual-attendance.php" id="mark-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="mark">
                        <input type="hidden" name="session_id" value="<?= (int) $session['id'] ?>">
                        <input type="hidden" name="student_id" value="<?= (int) $current['student_id'] ?>">
                        <input type="hidden" name="pos" value="<?= (int) $pos ?>">

                        <button class="mark-btn mark-btn-present" type="submit" name="status" value="present">
                            <?= app_icon('check', 'app-icon app-icon-lg') ?>
                            <span>Present</span>
                        </button>
                        <button class="mark-btn mark-btn-absent" type="submit" name="status" value="absent">
                            <?= app_icon('close', 'app-icon app-icon-lg') ?>
                            <span>Absent</span>
                        </button>
                        <button class="mark-btn mark-btn-review" type="submit" name="status" value="review">
                            <?= app_icon('flag', 'app-icon app-icon-lg') ?>
                            <span>Review</span>
                        </button>
                        <button class="mark-btn mark-btn-skip" type="submit" name="status" value="not_marked">
                            <?= app_icon('skip', 'app-icon app-icon-lg') ?>
                            <span>Skip / Not marked</span>
                        </button>
                    </form>

                    <!-- previous / next -->
                    <div class="mark-nav">
                        <?php if ($pos > 1): ?>
                            <a class="btn btn-ghost" href="manual-attendance.php?session=<?= (int) $session['id'] ?>&pos=<?= $pos - 1 ?>">
                                <?= app_icon('arrow') ?>Previous student
                            </a>
                        <?php else: ?>
                            <span class="btn btn-ghost" aria-disabled="true">
                                <?= app_icon('arrow') ?>Previous student
                            </span>
                        <?php endif; ?>

                        <?php if ($pos < count($roster)): ?>
                            <a class="btn btn-ghost" href="manual-attendance.php?session=<?= (int) $session['id'] ?>&pos=<?= $pos + 1 ?>">
                                Next student<?= app_icon('arrowr') ?>
                            </a>
                        <?php else: ?>
                            <span class="btn btn-ghost" aria-disabled="true">
                                Last student<?= app_icon('arrowr') ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <!-- voice toggle -->
                    <div class="mark-voice">
                        <label class="mark-voice-toggle">
                            <input type="checkbox" data-voice-toggle>
                            <?= app_icon('mic') ?>
                            <span>Read the roll number and name aloud</span>
                        </label>
                        <p class="field-hint" data-voice-hint>
                            Uses your browser's built-in speech engine. Nothing is
                            uploaded and no recording is made.
                        </p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- roster strip: jump to any student -->
            <div class="mark-roster" aria-label="Class register">
                <?php foreach ($roster as $row): ?>
                    <?php
                    $isCurrent = (int) $row['student_id'] === (int) ($current['student_id'] ?? 0);
                    $status    = (string) $row['status'];
                    $chipClass = match ($status) {
                        'present'    => 'is-present',
                        'absent'     => 'is-absent',
                        'review'     => 'is-review',
                        default      => 'is-empty',
                    };
                    ?>
                    <a class="mark-chip <?= e($chipClass) ?><?= $isCurrent ? ' is-current' : '' ?>"
                       href="manual-attendance.php?session=<?= (int) $session['id'] ?>&pos=<?= (int) $row['position'] ?>"
                       title="<?= e((string) $row['roll_number']) ?> — <?= e(attendance_status_label($status)) ?>">
                        <?= e((string) $row['roll_number']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="notice" role="note">
            <strong>Timer and refresh behaviour</strong>
            <p>
                The countdown is a guide only. When it reaches zero the student is
                left <em>not marked</em>, is flagged for review in the register, and
                the screen moves to the next student. The timer never writes a
                status by itself, so it can never create a duplicate or wrong
                record. Refreshing the page keeps every saved status and restarts
                the countdown for whoever is on screen.
            </p>
        </div>

        <div class="notice" role="note">
            <strong>Corrections and locking</strong>
            <p>
                Go back to any student with <em>Previous</em> and pick a different
                status — it updates the same record instead of adding a new one.
                Once you press <em>Finish attendance</em> the session is closed and
                marks are locked. There is no reopen or correction workflow in this
                phase.
            </p>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php
render_page_end();
