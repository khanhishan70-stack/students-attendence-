<?php
/**
 * SMART ATTENDANCE AI - PHASE 5
 * Sign-in screen.
 *
 * The minimum secure authentication the attendance screens need. The password
 * is checked with password_verify() against the bcrypt hash already stored in
 * the users table. A wrong email and a wrong password return the same message
 * so the form cannot be used to discover valid email addresses.
 */

declare(strict_types=1);

require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/auth.php';

/* Already signed in? Go straight to the dashboard. */
if (auth_check()) {
    header('Location: dashboard.php');
    exit;
}

$errors  = [];
$values  = ['email' => ''];
$oldNext = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_require();

    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $next     = (string) ($_POST['next'] ?? '');

    // Only ever redirect back inside this application.
    if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//')) {
        $next = '/smart-attendance/dashboard.php';
    }

    $oldNext = $next;
    $values['email'] = $email;

    $result = auth_attempt($email, $password);

    if ($result['ok'] && $result['user'] !== null) {
        auth_login($result['user']);

        flash_set('success', 'Welcome back, ' . (string) $result['user']['full_name'] . '.');

        // Convert the internal path back into a browser Location header.
        $target = $next;

        if (str_starts_with($target, '/smart-attendance/')) {
            $target = substr($target, strlen('/smart-attendance/'));
        } else {
            $target = 'dashboard.php';
        }

        header('Location: ' . $target);
        exit;
    }

    $errors['form'] = $result['message'];
}

/* The sign-in page keeps the dashboard chrome so branding stays consistent. */
render_page_start('login', 'Sign in');
?>
<section class="auth-wrap" aria-labelledby="signin-title">
    <div class="auth-card">
        <div class="auth-head">
            <span class="auth-mark" aria-hidden="true">SA</span>
            <p class="page-kicker">Phase 5 · secure sign-in</p>
            <h2 id="signin-title" class="page-title">Sign in</h2>
            <p class="page-sub">
                Attendance is recorded against a real teacher account so every
                marked register can be traced back to the person who took it.
            </p>
        </div>

        <?php if ($errors !== []): ?>
            <div class="flash flash-error" role="alert">
                <span class="dot dot-red" aria-hidden="true"></span>
                <p><?= e($errors['form']) ?></p>
            </div>
        <?php endif; ?>

        <form class="form-grid auth-form" method="post" action="login.php" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="next" value="<?= e($oldNext) ?>">

            <?php render_field([
                'name'     => 'email',
                'label'    => 'Email',
                'type'     => 'email',
                'value'    => $values['email'],
                'required' => true,
                'autocomplete' => 'username',
            ]); ?>

            <?php render_field([
                'name'     => 'password',
                'label'    => 'Password',
                'type'     => 'password',
                'value'    => '',
                'required' => true,
                'autocomplete' => 'current-password',
            ]); ?>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Sign in</button>
                <a class="btn btn-ghost" href="dashboard.php">Back to dashboard</a>
            </div>
        </form>

        <div class="auth-demo">
            <p class="field-label">Demo accounts (local development only)</p>
            <ul class="auth-demo-list">
                <li>
                    <span class="cell-strong">admin@smartattendance.local</span>
                    <span class="cell-sub">Admin@123 · admin</span>
                </li>
                <li>
                    <span class="cell-strong">priya.sharma@smartattendance.local</span>
                    <span class="cell-sub">Teacher@123 · teacher</span>
                </li>
            </ul>
            <p class="field-hint">
                Passwords are stored as bcrypt hashes only. Change them before any
                real deployment.
            </p>
        </div>
    </div>
</section>
<?php
render_page_end();
