<?php
/**
 * SMART ATTENDANCE AI - PHASE 6
 * QR Attendance — student check-in page.
 *
 * Workflow:
 *   1. Student scans QR code → lands here with ?session=X&token=Y
 *   2. If not logged in → redirect to login.php with next= this URL
 *   3. After login → validate token again on server
 *   4. Confirm authenticated user is a student account linked to a student record
 *   5. Confirm student is enrolled in the session class
 *   6. Confirm session is open, QR mode, token valid
 *   7. Submit attendance (source='qr', marked_by=student user id)
 *   8. Show clear success/error message
 */

declare(strict_types=1);

require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/qr.php';

$sessionId = (int) ($_GET['session'] ?? 0);
$rawToken  = trim((string) ($_GET['token'] ?? ''));

$databaseAvailable = db_is_available();
$message    = '';
$messageType = 'info';
$session    = null;
$canSubmit  = false;
$alreadyMarked = false;

if ($databaseAvailable) {
    $pdo = db();
    if ($sessionId > 0) {
        $session = qr_session_find($sessionId);
    }
}

/* If we have a session and token, and the user is already signed in as a student,
   we can attempt the submission immediately (GET with token acts as a submit).
   If they're not signed in, we'll redirect to login with the full URL as 'next'. */

if ($session !== null && $rawToken !== '') {
    $auth = auth_user();

    if ($auth === null) {
        // Not logged in - redirect to login, preserving the check-in URL
        $next = '/smart-attendance/qr-checkin.php?session=' . $sessionId . '&token=' . rawurlencode($rawToken);
        header('Location: login.php?next=' . rawurlencode($next));
        exit;
    }

    // Logged in - must be a student account
    if (($auth['role'] ?? '') !== 'student') {
        $message = 'This page is for students only. Please sign in with your student account.';
        $messageType = 'error';
    } else {
        // Submit attendance
        $result = qr_submit_attendance($sessionId, $rawToken, (int) $auth['id']);
        $message = $result['message'];
        $messageType = $result['ok'] ? 'success' : 'error';
        $alreadyMarked = $result['ok'] && str_contains($message, 'already marked');
    }
}
elseif ($session !== null && $rawToken === '') {
    $message = 'No QR token provided. Please scan the QR code again.';
    $messageType = 'error';
}
elseif ($session === null && $sessionId > 0) {
    $message = 'This attendance session does not exist or has been closed.';
    $messageType = 'error';
}
else {
    $message = 'Invalid attendance link. Please scan a valid QR code from your teacher.';
    $messageType = 'error';
}

/* Page title and config for JS */
$pageTitle = 'QR Check-in';
$extraScripts = ['assets/js/qr-checkin.js'];

if ($session !== null) {
    $pageTitle = 'Check in: ' . ($session['subject_code'] ?? 'Attendance');
}

render_page_start('qr', $pageTitle, $extraScripts);
?>
<?php if (!$databaseAvailable): ?>
    <div class="notice notice-danger" role="status">
        <strong>The database is not reachable right now.</strong>
        <p>Start MySQL from the XAMPP Control Panel and reload this page.</p>
    </div>
<?php endif; ?>

<?php if ($message !== ''): ?>
    <section class="checkin-result" aria-live="polite">
        <div class="checkin-card">
            <div class="checkin-icon" aria-hidden="true">
                <?php if ($messageType === 'success'): ?>
                    <?= app_icon('check') ?>
                <?php elseif ($messageType === 'error'): ?>
                    <?= app_icon('flag') ?>
                <?php else: ?>
                    <?= app_icon('spark') ?>
                <?php endif; ?>
            </div>

            <h2 class="checkin-title">
                <?php if ($messageType === 'success'): ?>
                    Check-in successful
                <?php elseif ($messageType === 'error'): ?>
                    Unable to check in
                <?php else: ?>
                    Information
                <?php endif; ?>
            </h2>

            <p class="checkin-message"><?= e($message) ?></p>

            <?php if ($session !== null): ?>
                <div class="checkin-details">
                    <dl>
                        <dt>Subject</dt>
                        <dd><?= e($session['subject_code'] ?? '') ?> — <?= e($session['subject_name'] ?? '') ?></dd>
                        <dt>Class</dt>
                        <dd><?= e($session['department'] ?? '') ?> · Div <?= e($session['division'] ?? '') ?> (<?= e($session['academic_year'] ?? '') ?>)</dd>
                        <dt>Session</dt>
                        <dd><?= e($session['session_label'] ?? 'Lecture') ?> · <?= e(date('j M Y', strtotime($session['session_date']))) ?></dd>
                    </dl>
                </div>
            <?php endif; ?>

            <div class="checkin-actions">
                <?php if ($messageType === 'success' && !$alreadyMarked): ?>
                    <p class="checkin-hint">You are marked <strong>Present</strong>. You may close this page.</p>
                <?php elseif ($alreadyMarked): ?>
                    <p class="checkin-hint">Your attendance was already recorded.</p>
                <?php else: ?>
                    <a class="btn btn-primary" href="login.php">Sign in with student account</a>
                <?php endif; ?>
            </div>
        </div>
    </section>
<?php else: ?>
    <section class="checkin-result" aria-live="polite">
        <div class="checkin-card">
            <div class="checkin-icon" aria-hidden="true"><?= app_icon('spark') ?></div>
            <h2 class="checkin-title">QR Attendance Check-in</h2>
            <p class="checkin-message">Please scan the QR code displayed by your teacher to begin.</p>
        </div>
    </section>
<?php endif; ?>

<?php
// Pass config to JS
if ($session !== null) {
    echo '<script id="checkin-config" type="application/json">'
       . json_encode([
           'sessionId'   => $sessionId,
           'token'       => $rawToken,
           'isAuthed'    => auth_check() && (auth_user()['role'] ?? '') === 'student',
           'tokenExpires'=> $session['token_expires_at'] ?? '',
       ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
       . '</script>';
}
?>
<?php render_page_end(); ?>