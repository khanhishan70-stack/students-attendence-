<?php
/**
 * SMART ATTENDANCE AI - PHASE 5
 *
 * Minimum secure authentication.
 *
 * The users table already existed (Phase 2) with bcrypt password hashes, but
 * no screen ever signed anyone in, so attendance could not be attributed to a
 * real teacher. This module adds the smallest secure sign-in that the manual
 * attendance screens need:
 *
 *   - password_verify() against the stored bcrypt hash (never plain text)
 *   - a PHP session that remembers the signed-in user id, name and role
 *   - require_login() so attendance screens reject anonymous requests
 *
 * There is no password reset, no MFA and no registration screen. Those are out
 * of scope for this phase and are listed as known limitations in the docs.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/security.php';

/**
 * Signs the given user in. Only the id, display name and role are kept in the
 * session; the password hash is never stored in the session.
 */
function auth_login(array $user): void
{
    app_session_start();

    // Rotate the session id on privilege change to block session fixation.
    session_regenerate_id(true);

    $_SESSION['user_id']   = (int) $user['id'];
    $_SESSION['user_name'] = (string) $user['full_name'];
    $_SESSION['user_role'] = (string) $user['role'];
    $_SESSION['user_email']= (string) $user['email'];
}

/**
 * Signs the current user out and forgets every session key we set.
 */
function auth_logout(): void
{
    app_session_start();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool) $params['secure'],
            (bool) $params['httponly']
        );
    }

    session_destroy();
}

/**
 * Returns the signed-in user, or null when nobody is signed in.
 *
 * @return array{id:int,name:string,role:string,email:string}|null
 */
function auth_user(): ?array
{
    app_session_start();

    $id = $_SESSION['user_id'] ?? null;

    if (!is_int($id) && !ctype_digit((string) $id)) {
        return null;
    }

    return [
        'id'    => (int) $id,
        'name'  => (string) ($_SESSION['user_name'] ?? ''),
        'role'  => (string) ($_SESSION['user_role'] ?? 'teacher'),
        'email' => (string) ($_SESSION['user_email'] ?? ''),
    ];
}

function auth_check(): bool
{
    return auth_user() !== null;
}

/**
 * The single entry point the attendance screens call. Anyone who is not signed
 * in is bounced to the login page; nothing else changes.
 */
function require_login(): void
{
    if (auth_check()) {
        return;
    }

    flash_set('info', 'Please sign in to take attendance.');

    $next = isset($_SERVER['REQUEST_URI'])
        ? (string) $_SERVER['REQUEST_URI']
        : 'dashboard.php';

    header('Location: login.php?next=' . rawurlencode($next));
    exit;
}

/**
 * Looks a user up by email for sign-in. Returns null when the email is unknown
 * or the account has been deactivated, without revealing which of the two it
 * was.
 */
function auth_find_by_email(PDO $pdo, string $email): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, full_name, email, password_hash, role, is_active
           FROM users
          WHERE email = :email'
    );

    $statement->bindValue(':email', $email, PDO::PARAM_STR);
    $statement->execute();

    $user = $statement->fetch();

    if ($user === false || (int) $user['is_active'] !== 1) {
        return null;
    }

    return $user;
}

/**
 * Verifies an email + password pair against the database.
 *
 * The same generic failure message is returned for an unknown email, a wrong
 * password and a deactivated account so the form cannot be used to discover
 * which email addresses exist.
 *
 * @return array{ok:bool,message:string,user:?array}
 */
function auth_attempt(string $email, string $password): array
{
    $generic = 'Email or password is incorrect.';

    if ($email === '' || $password === '') {
        return ['ok' => false, 'message' => 'Enter your email and password.', 'user' => null];
    }

    try {
        $pdo = db();
    } catch (Throwable $exception) {
        error_log('[smart-attendance] login database unavailable: ' . $exception->getMessage());

        return [
            'ok'      => false,
            'message' => 'The database is not available right now. Try again shortly.',
            'user'    => null,
        ];
    }

    $user = auth_find_by_email($pdo, $email);

    if ($user === null) {
        // Burn comparable time so a missing account is not faster to probe.
        password_verify($password, '$2y$10$8QVR5ouNx0pM9tmtwzS..uZ8bFSQ.xHGbhq1USJg4biAyqVvBh4nO');

        return ['ok' => false, 'message' => $generic, 'user' => null];
    }

    if (!password_verify($password, (string) $user['password_hash'])) {
        return ['ok' => false, 'message' => $generic, 'user' => null];
    }

    // Rehash if PHP's default cost has moved on since the hash was written.
    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
        try {
            $rehash = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
            $rehash->bindValue(':hash', password_hash($password, PASSWORD_DEFAULT), PDO::PARAM_STR);
            $rehash->bindValue(':id', (int) $user['id'], PDO::PARAM_INT);
            $rehash->execute();
        } catch (Throwable $exception) {
            error_log('[smart-attendance] password rehash failed: ' . $exception->getMessage());
        }
    }

    return ['ok' => true, 'message' => 'Signed in.', 'user' => $user];
}
