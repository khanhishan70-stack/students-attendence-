<?php
/**
 * SMART ATTENDANCE AI - PHASE 6
 *
 * QR attendance data layer.
 *
 * Security model:
 *   - QR tokens are cryptographically secure random bytes (256-bit), stored ONLY as
 *     a SHA-256 hash. The raw token is never written to the database.
 *   - Every token has an explicit expiry (default 30 seconds). A refresh issues a
 *     new token and revokes the previous ones for the same session.
 *   - A token can only be used once per student (the attendance unique key
 *     enforces this at the database level).
 *   - The student must be authenticated (role=student) and their account must be
 *     linked to a student record (users.student_id). The server resolves identity
 *     from the session, never from browser-supplied data.
 *   - The student must be enrolled in the session's class (database trigger
 *     enforces this a second time).
 *   - The session must be open, in QR mode, and not closed.
 *   - Rate limiting prevents hammering the check-in endpoint.
 *   - Closed sessions revoke all their tokens immediately.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/security.php';

const QR_DEFAULT_TOKEN_TTL  = 30;     // seconds a displayed QR code is valid
const QR_REFRESH_INTERVAL   = 30;     // how often the teacher UI rotates the code
const QR_MAX_TOKEN_TTL      = 300;    // hard ceiling (5 minutes)
const QR_MIN_TOKEN_TTL      = 10;     // hard floor (10 seconds)
const QR_RATE_WINDOW        = 60;     // one-minute fixed window
const QR_RATE_MAX_ATTEMPTS  = 10;     // per bucket per window
const QR_RATE_BUCKET_PREFIX = 'qr';

/**
 * Generates a cryptographically secure random token (raw, never logged/stored).
 */
function qr_generate_token(): string
{
    return bin2hex(random_bytes(32)); // 256 bits = 64 hex chars
}

/**
 * Hashes a raw token for storage. Only the hash ever hits the database.
 */
function qr_hash_token(string $rawToken): string
{
    return hash('sha256', $rawToken);
}

/**
 * Creates a new QR session (attendance_mode='qr') or reuses an existing open one.
 *
 * @return array{ok:bool,message:string,session_id:int,token:string,expires_at:string}
 */
function qr_session_start(array $values, int $userId): array
{
    try {
        $pdo = db();
    } catch (Throwable $exception) {
        return ['ok' => false, 'message' => 'Database unavailable.', 'session_id' => 0, 'token' => '', 'expires_at' => ''];
    }

    // Re-use the manual attendance validation logic for class/subject/date.
    require_once __DIR__ . '/attendance.php';
    $validation = attendance_validate_setup($values, $pdo);

    if ($validation['errors'] !== []) {
        return ['ok' => false, 'message' => implode(' ', $validation['errors']), 'session_id' => 0, 'token' => '', 'expires_at' => ''];
    }

    // Check for an existing OPEN qr session for the same class+subject+date.
    $statement = $pdo->prepare(
        "SELECT id, session_label, status
           FROM attendance_sessions
          WHERE class_id = :class
            AND subject_id = :subject
            AND session_date = :date
            AND attendance_mode = 'qr'
            AND status IN ('scheduled','open')
          ORDER BY id DESC
          LIMIT 1"
    );
    $statement->bindValue(':class', (int) $values['class_id'], PDO::PARAM_INT);
    $statement->bindValue(':subject', (int) $values['subject_id'], PDO::PARAM_INT);
    $statement->bindValue(':date', (string) $values['session_date'], PDO::PARAM_STR);
    $statement->execute();
    $existing = $statement->fetch();

    if ($existing !== false) {
        // Session exists and is open/scheduled - issue a fresh token for it.
        $sessionId = (int) $existing['id'];
        $tokenResult = qr_issue_token($pdo, $sessionId, $userId);
        if (!$tokenResult['ok']) {
            return $tokenResult + ['session_id' => $sessionId];
        }
        return [
            'ok'         => true,
            'message'    => 'Resumed existing QR session. New token issued.',
            'session_id' => $sessionId,
            'token'      => $tokenResult['token'],
            'expires_at' => $tokenResult['expires_at'],
        ];
    }

    // No open session - create a new one in QR mode.
    $ttl = max(QR_MIN_TOKEN_TTL, min((int) ($values['token_ttl'] ?? QR_DEFAULT_TOKEN_TTL), QR_MAX_TOKEN_TTL));

    try {
        $statement = $pdo->prepare(
            "INSERT INTO attendance_sessions
                (class_id, subject_id, created_by, session_date, session_label,
                 seconds_per_student, attendance_mode, status, started_at)
             VALUES
                (:class, :subject, :user, :date, :label, :ttl, 'qr', 'open', NOW())"
        );
        $statement->bindValue(':class', (int) $values['class_id'], PDO::PARAM_INT);
        $statement->bindValue(':subject', (int) $values['subject_id'], PDO::PARAM_INT);
        $statement->bindValue(':user', $userId, PDO::PARAM_INT);
        $statement->bindValue(':date', (string) $values['session_date'], PDO::PARAM_STR);
        $statement->bindValue(':label', (string) $values['session_label'], PDO::PARAM_STR);
        $statement->bindValue(':ttl', $ttl, PDO::PARAM_INT);
        $statement->execute();

        $sessionId = (int) $pdo->lastInsertId();
    } catch (PDOException $exception) {
        error_log('[smart-attendance] qr session create failed: ' . $exception->getMessage());
        return ['ok' => false, 'message' => 'Could not create session.', 'session_id' => 0, 'token' => '', 'expires_at' => ''];
    }

    $tokenResult = qr_issue_token($pdo, $sessionId, $userId, $ttl);
    if (!$tokenResult['ok']) {
        return $tokenResult + ['session_id' => $sessionId];
    }

    return [
        'ok'         => true,
        'message'    => 'QR session started.',
        'session_id' => $sessionId,
        'token'      => $tokenResult['token'],
        'expires_at' => $tokenResult['expires_at'],
    ];
}

/**
 * Issues a new token for a session, revoking any previous active tokens.
 *
 * @return array{ok:bool,message:string,token:string,expires_at:string}
 */
function qr_issue_token(PDO $pdo, int $sessionId, int $userId, int $ttl = QR_DEFAULT_TOKEN_TTL): array
{
    // Revoke any unrevoked tokens for this session.
    $pdo->prepare(
        "UPDATE qr_tokens SET revoked_at = NOW() WHERE session_id = :sid AND revoked_at IS NULL"
    )->execute([':sid' => $sessionId]);

    $rawToken = qr_generate_token();
    $tokenHash = qr_hash_token($rawToken);
    $expiresAt = (new DateTime())->modify("+{$ttl} seconds")->format('Y-m-d H:i:s');

    try {
        $statement = $pdo->prepare(
            "INSERT INTO qr_tokens (session_id, token_hash, expires_at, created_by)
             VALUES (:sid, :hash, :expires, :user)"
        );
        $statement->bindValue(':sid', $sessionId, PDO::PARAM_INT);
        $statement->bindValue(':hash', $tokenHash, PDO::PARAM_STR);
        $statement->bindValue(':expires', $expiresAt, PDO::PARAM_STR);
        $statement->bindValue(':user', $userId, PDO::PARAM_INT);
        $statement->execute();
    } catch (PDOException $exception) {
        error_log('[smart-attendance] qr token issue failed: ' . $exception->getMessage());
        return ['ok' => false, 'message' => 'Could not issue token.', 'token' => '', 'expires_at' => ''];
    }

    return [
        'ok'         => true,
        'message'    => 'Token issued.',
        'token'      => $rawToken,
        'expires_at' => $expiresAt,
    ];
}

/**
 * Loads a QR session with its class/subject labels and the current active token.
 */
function qr_session_find(int $sessionId): ?array
{
    try {
        $pdo = db();
    } catch (Throwable $exception) {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT se.id,
                se.class_id,
                se.subject_id,
                se.created_by,
                se.session_date,
                se.session_label,
                se.attendance_mode,
                se.status,
                se.started_at,
                se.closed_at,
                c.department,
                c.academic_year,
                c.division,
                su.subject_code,
                su.subject_name
           FROM attendance_sessions se
           JOIN classes c   ON c.id  = se.class_id
           JOIN subjects su ON su.id = se.subject_id
          WHERE se.id = :id'
    );
    $statement->bindValue(':id', $sessionId, PDO::PARAM_INT);
    $statement->execute();

    $session = $statement->fetch();
    if ($session === false) {
        return null;
    }

    // Attach the current (unrevoked, not expired) token if any.
    $tokenStmt = $pdo->prepare(
        "SELECT token_hash, expires_at
           FROM qr_tokens
          WHERE session_id = :sid
            AND revoked_at IS NULL
            AND expires_at > NOW()
          ORDER BY created_at DESC
          LIMIT 1"
    );
    $tokenStmt->bindValue(':sid', $sessionId, PDO::PARAM_INT);
    $tokenStmt->execute();
    $token = $tokenStmt->fetch();
    $session['current_token_hash'] = $token['token_hash'] ?? null;
    $session['token_expires_at']   = $token['expires_at'] ?? null;

    return $session;
}

/**
 * Validates a raw token against the database for a given session.
 *
 * Returns the token row if valid, null if expired/revoked/malformed/not found.
 */
function qr_validate_token(PDO $pdo, int $sessionId, string $rawToken): ?array
{
    if ($rawToken === '' || strlen($rawToken) !== 64 || !ctype_xdigit($rawToken)) {
        return null;
    }

    $tokenHash = qr_hash_token($rawToken);

    $statement = $pdo->prepare(
        "SELECT id, token_hash, expires_at
           FROM qr_tokens
          WHERE session_id = :sid
            AND token_hash = :hash
            AND revoked_at IS NULL
            AND expires_at > NOW()
          LIMIT 1"
    );
    $statement->bindValue(':sid', $sessionId, PDO::PARAM_INT);
    $statement->bindValue(':hash', $tokenHash, PDO::PARAM_STR);
    $statement->execute();

    return $statement->fetch() ?: null;
}

/**
 * Checks the rate limit for an opaque bucket + action.
 *
 * @return array{allowed:bool,retry_after:int}
 */
function qr_check_rate_limit(PDO $pdo, string $bucket, string $action): array
{
    $windowStart = (int) (time() / QR_RATE_WINDOW) * QR_RATE_WINDOW;
    $key = hash('sha256', QR_RATE_BUCKET_PREFIX . ':' . $bucket . ':' . $action);

    try {
        $pdo->beginTransaction();

        $statement = $pdo->prepare(
            "SELECT attempts FROM qr_rate_limits
              WHERE bucket = :bucket AND action = :action AND window_start = :window
              FOR UPDATE"
        );
        $statement->bindValue(':bucket', $key, PDO::PARAM_STR);
        $statement->bindValue(':action', $action, PDO::PARAM_STR);
        $statement->bindValue(':window', $windowStart, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        if ($row === false) {
            $pdo->prepare(
                "INSERT INTO qr_rate_limits (bucket, action, window_start, attempts)
                 VALUES (:bucket, :action, :window, 1)"
            )->execute([
                ':bucket'  => $key,
                ':action'  => $action,
                ':window'  => $windowStart,
            ]);
            $pdo->commit();
            return ['allowed' => true, 'retry_after' => 0];
        }

        $attempts = (int) $row['attempts'];
        if ($attempts >= QR_RATE_MAX_ATTEMPTS) {
            $pdo->rollBack();
            return ['allowed' => false, 'retry_after' => $windowStart + QR_RATE_WINDOW - time()];
        }

        $pdo->prepare(
            "UPDATE qr_rate_limits SET attempts = attempts + 1
              WHERE bucket = :bucket AND action = :action AND window_start = :window"
        )->execute([
            ':bucket'  => $key,
            ':action'  => $action,
            ':window'  => $windowStart,
        ]);
        $pdo->commit();
        return ['allowed' => true, 'retry_after' => 0];
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[smart-attendance] rate limit check failed: ' . $exception->getMessage());
        // Fail open - don't block on rate limiter errors
        return ['allowed' => true, 'retry_after' => 0];
    }
}

/**
 * Records QR attendance for the authenticated student.
 *
 * The student identity comes ONLY from the server-side session (auth_user()).
 * The browser supplies only the session ID and the raw QR token.
 *
 * @return array{ok:bool,message:string,status:string|null}
 */
function qr_submit_attendance(int $sessionId, string $rawToken, int $userId): array
{
    // 1) Auth user must be a student account linked to a student record.
    $auth = auth_user();
    if ($auth === null || ($auth['role'] ?? '') !== 'student') {
        return ['ok' => false, 'message' => 'Only student accounts can submit QR attendance.', 'status' => null];
    }

    try {
        $pdo = db();
    } catch (Throwable $exception) {
        return ['ok' => false, 'message' => 'Database unavailable.', 'status' => null];
    }

    // 2) Resolve the student_id from the authenticated user account.
    $studentId = (int) ($auth['student_id'] ?? 0);
    if ($studentId <= 0) {
        return ['ok' => false, 'message' => 'Your account is not linked to a student record. Contact your teacher.', 'status' => null];
    }

    // 3) Validate the session exists and is an open QR session.
    $session = qr_session_find($sessionId);
    if ($session === null) {
        return ['ok' => false, 'message' => 'Session not found.', 'status' => null];
    }
    if ((string) $session['attendance_mode'] !== 'qr') {
        return ['ok' => false, 'message' => 'This session is not a QR attendance session.', 'status' => null];
    }
    if ((string) $session['status'] !== 'open') {
        return ['ok' => false, 'message' => 'This attendance session is no longer open.', 'status' => null];
    }

    // 4) Validate the token (hash match, not expired, not revoked).
    $tokenRow = qr_validate_token($pdo, $sessionId, $rawToken);
    if ($tokenRow === null) {
        return ['ok' => false, 'message' => 'This QR code has expired or is invalid. Ask your teacher to refresh.', 'status' => null];
    }

    // 5) Rate limit per student (by student_id).
    $rate = qr_check_rate_limit($pdo, (string) $studentId, 'checkin');
    if (!$rate['allowed']) {
        return ['ok' => false, 'message' => 'Too many attempts. Please wait ' . $rate['retry_after'] . ' seconds.', 'status' => null];
    }

    // 6) Verify the student is enrolled in the session's class (the DB trigger
    //    enforces this a second time, but we check first for a friendly message).
    require_once __DIR__ . '/attendance.php';
    $roster = attendance_roster($sessionId);
    $onRoster = false;
    foreach ($roster as $row) {
        if ((int) $row['student_id'] === $studentId) {
            $onRoster = true;
            break;
        }
    }
    if (!$onRoster) {
        return ['ok' => false, 'message' => 'You are not enrolled in this class.', 'status' => null];
    }

    // 7) Record attendance - source='qr', marked_by=user (the student account).
    //    The unique key on (session_id, student_id) makes this an upsert.
    try {
        $pdo->beginTransaction();

        $statement = $pdo->prepare(
            'INSERT INTO attendance (session_id, student_id, status, marked_by, marked_at, source)
             VALUES (:session, :student, :status, :user, NOW(), "qr")
             ON DUPLICATE KEY UPDATE
                status    = VALUES(status),
                marked_by = VALUES(marked_by),
                marked_at = VALUES(marked_at),
                source    = VALUES(source)'
        );
        $statement->bindValue(':session', $sessionId, PDO::PARAM_INT);
        $statement->bindValue(':student', $studentId, PDO::PARAM_INT);
        $statement->bindValue(':status', 'present', PDO::PARAM_STR);
        $statement->bindValue(':user', $userId, PDO::PARAM_INT);
        $statement->execute();

        $pdo->commit();

        return [
            'ok'      => true,
            'message' => 'Attendance recorded. You are marked Present.',
            'status'  => 'present',
        ];
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[smart-attendance] qr attendance submit failed: ' . $exception->getMessage());
        // Check if it was a duplicate (the upsert should handle this, but just in case)
        if ($exception->getCode() === '23000' || str_contains($exception->getMessage(), 'Duplicate entry')) {
            // Re-read to give a precise message
            $stmt = $pdo->prepare("SELECT status FROM attendance WHERE session_id = :sid AND student_id = :stid");
            $stmt->execute([':sid' => $sessionId, ':stid' => $studentId]);
            $existing = $stmt->fetch();
            $status = $existing['status'] ?? 'present';
            return [
                'ok'      => true,
                'message' => 'You were already marked ' . ucfirst($status) . ' for this session.',
                'status'  => $status,
            ];
        }
        return ['ok' => false, 'message' => 'Could not record attendance. Please try again.', 'status' => null];
    }
}

/**
 * Refreshes the QR token for a session (teacher action).
 * Revokes all existing tokens and issues a new one.
 *
 * @return array{ok:bool,message:string,token:string,expires_at:string}
 */
function qr_session_refresh_token(int $sessionId, int $userId, int $ttl = QR_DEFAULT_TOKEN_TTL): array
{
    try {
        $pdo = db();
    } catch (Throwable $exception) {
        return ['ok' => false, 'message' => 'Database unavailable.', 'token' => '', 'expires_at' => ''];
    }

    // Verify the session belongs to this teacher (or they are admin).
    $auth = auth_user();
    $session = qr_session_find($sessionId);
    if ($session === null) {
        return ['ok' => false, 'message' => 'Session not found.', 'token' => '', 'expires_at' => ''];
    }
    if ((string) $session['attendance_mode'] !== 'qr') {
        return ['ok' => false, 'message' => 'Not a QR session.', 'token' => '', 'expires_at' => ''];
    }
    if ((int) $session['created_by'] !== $userId && ($auth['role'] ?? '') !== 'admin') {
        return ['ok' => false, 'message' => 'You can only refresh your own sessions.', 'token' => '', 'expires_at' => ''];
    }
    if ((string) $session['status'] !== 'open') {
        return ['ok' => false, 'message' => 'Cannot refresh a closed session.', 'token' => '', 'expires_at' => ''];
    }

    return qr_issue_token($pdo, $sessionId, $userId, $ttl);
}

/**
 * Closes a QR session (teacher action). Revokes all tokens.
 *
 * @return array{ok:bool,message:string}
 */
function qr_session_close(int $sessionId, int $userId): array
{
    try {
        $pdo = db();
    } catch (Throwable $exception) {
        return ['ok' => false, 'message' => 'Database unavailable.'];
    }

    $auth = auth_user();
    $session = qr_session_find($sessionId);
    if ($session === null) {
        return ['ok' => false, 'message' => 'Session not found.'];
    }
    if ((int) $session['created_by'] !== $userId && ($auth['role'] ?? '') !== 'admin') {
        return ['ok' => false, 'message' => 'You can only close your own sessions.'];
    }
    if ((string) $session['status'] !== 'open') {
        return ['ok' => false, 'message' => 'Session is already closed.'];
    }

    try {
        $pdo->beginTransaction();

        // Revoke all tokens for this session.
        $pdo->prepare(
            "UPDATE qr_tokens SET revoked_at = NOW() WHERE session_id = :sid AND revoked_at IS NULL"
        )->execute([':sid' => $sessionId]);

        // Close the session.
        $pdo->prepare(
            "UPDATE attendance_sessions SET status = 'closed', closed_at = NOW()
              WHERE id = :sid AND status = 'open'"
        )->execute([':sid' => $sessionId]);

        $pdo->commit();
        return ['ok' => true, 'message' => 'Session closed and all tokens revoked.'];
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[smart-attendance] qr session close failed: ' . $exception->getMessage());
        return ['ok' => false, 'message' => 'Could not close session.'];
    }
}

/**
 * Gets the live attendance list for a QR session (teacher view).
 *
 * Returns an array of student rows with their current status and marked_at.
 */
function qr_session_live_attendance(int $sessionId): array
{
    try {
        $pdo = db();
    } catch (Throwable $exception) {
        return [];
    }

    $statement = $pdo->prepare(
        'SELECT st.id              AS student_id,
                st.roll_number,
                st.full_name,
                a.status,
                a.marked_at,
                a.source
           FROM attendance_sessions se
           JOIN classes c       ON c.id = se.class_id
           JOIN enrollments e   ON e.class_id = se.class_id
                                AND e.enrollment_year = c.academic_year
           JOIN students st     ON st.id = e.student_id
                                AND st.is_active = 1
           LEFT JOIN attendance a ON a.session_id = se.id
                                 AND a.student_id = st.id
          WHERE se.id = :sid
          ORDER BY st.roll_number + 0, st.roll_number'
    );
    $statement->bindValue(':sid', $sessionId, PDO::PARAM_INT);
    $statement->execute();

    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $rows[] = [
            'student_id'   => (int) $row['student_id'],
            'roll_number'  => (string) $row['roll_number'],
            'full_name'    => (string) $row['full_name'],
            'status'       => $row['status'] ?? 'not_marked',
            'marked_at'    => $row['marked_at'],
            'source'       => $row['source'] ?? 'manual',
            'is_present'   => ($row['status'] ?? 'not_marked') === 'present',
        ];
    }

    return $rows;
}

/**
 * Summary counts for a QR session.
 */
function qr_session_summary(int $sessionId): array
{
    $rows = qr_session_live_attendance($sessionId);
    $summary = [
        'total'      => count($rows),
        'present'    => 0,
        'absent'     => 0,
        'review'     => 0,
        'not_marked' => 0,
    ];
    foreach ($rows as $r) {
        $s = $r['status'];
        if (isset($summary[$s])) {
            $summary[$s]++;
        } else {
            $summary['not_marked']++;
        }
    }
    return $summary;
}

/**
 * Recent QR sessions for the "resume" list on the setup screen.
 */
function qr_recent_sessions(int $limit = 8): array
{
    try {
        $pdo = db();
    } catch (Throwable $exception) {
        return [];
    }

    $statement = $pdo->prepare(
        'SELECT se.id,
                se.session_date,
                se.session_label,
                se.status,
                se.attendance_mode,
                c.division,
                c.academic_year,
                su.subject_code,
                su.subject_name,
                (SELECT COUNT(*) FROM attendance a1 WHERE a1.session_id = se.id AND a1.status = \'present\')     AS present_count,
                (SELECT COUNT(*) FROM attendance a2 WHERE a2.session_id = se.id AND a2.status = \'absent\')      AS absent_count,
                (SELECT COUNT(*) FROM attendance a3 WHERE a3.session_id = se.id AND a3.status = \'review\')      AS review_count,
                (SELECT COUNT(*) FROM attendance a4 WHERE a4.session_id = se.id AND a4.status = \'not_marked\')  AS not_marked_count
           FROM attendance_sessions se
           JOIN classes c   ON c.id  = se.class_id
           JOIN subjects su ON su.id = se.subject_id
          WHERE se.attendance_mode = \'qr\'
          ORDER BY se.status = \'open\' DESC, se.session_date DESC, se.id DESC
          LIMIT :limit'
    );

    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}