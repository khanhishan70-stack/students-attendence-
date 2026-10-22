<?php
/**
 * SMART ATTENDANCE AI - PHASE 5
 *
 * Manual attendance data layer.
 *
 * Every function here uses prepared statements. Status values are checked
 * against an explicit allowlist before they reach the database, the session
 * must be open before any mark is written, and the student must belong to the
 * session class (the database trigger enforces that a second time).
 *
 * One attendance row per student per session is guaranteed by the existing
 * unique key uq_attendance_session_student, so marking twice updates the same
 * row instead of creating a duplicate.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

const ATTENDANCE_DEFAULT_SECONDS = 30;
const ATTENDANCE_MIN_SECONDS     = 5;
const ATTENDANCE_MAX_SECONDS     = 600;

/**
 * Up to two initials for the avatar shown next to a student's name.
 * The students table has no photograph column, so the avatar is derived from
 * the name rather than inventing an image.
 */
function student_initials(string $name): string
{
    $words = preg_split('/\s+/', trim($name)) ?: [];
    $initials = '';

    foreach ($words as $word) {
        if ($word === '') {
            continue;
        }

        $initials .= mb_strtoupper(mb_substr($word, 0, 1));

        if (mb_strlen($initials) >= 2) {
            break;
        }
    }

    return $initials === '' ? '?' : $initials;
}

/**
 * The only statuses the application will ever write.
 */
function attendance_status_allowlist(): array
{
    return ['present', 'absent', 'review', 'not_marked'];
}

function attendance_status_label(string $status): string
{
    return match ($status) {
        'present'    => 'Present',
        'absent'     => 'Absent',
        'review'     => 'Review',
        'not_marked' => 'Not marked',
        default      => ucfirst($status),
    };
}

/**
 * A coloured badge for one attendance status, matching the dashboard badges.
 */
function attendance_status_badge(string $status): string
{
    return match ($status) {
        'present'    => '<span class="badge badge-green">Present</span>',
        'absent'     => '<span class="badge badge-red">Absent</span>',
        'review'     => '<span class="badge badge-amber">Review</span>',
        'not_marked' => '<span class="badge badge-grey">Not marked</span>',
        default      => '<span class="badge badge-grey">' . e(attendance_status_label($status)) . '</span>',
    };
}

/* ---------------------------------------------------------------------------
 * Session setup
 * ------------------------------------------------------------------------ */

function attendance_classes(PDO $pdo): array
{
    return $pdo->query(
        'SELECT id, department, academic_year, division
           FROM classes
          ORDER BY academic_year DESC, department, division'
    )->fetchAll();
}

/**
 * Subjects that belong to the given class. The schema links subjects to a
 * class through subjects.class_id; there is no separate teacher-subject table,
 * so "assigned to the class" is the rule the schema actually supports.
 */
function attendance_subjects_for_class(PDO $pdo, int $classId): array
{
    $statement = $pdo->prepare(
        'SELECT id, class_id, subject_code, subject_name
           FROM subjects
          WHERE class_id = :class AND is_active = 1
          ORDER BY subject_code'
    );

    $statement->bindValue(':class', $classId, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

/**
 * Every active subject, tagged with its class id. The setup form renders them
 * all and hides the ones that do not belong to the selected class, so changing
 * class never needs a page reload. The server still re-checks the pairing.
 */
function attendance_all_subjects(PDO $pdo): array
{
    return $pdo->query(
        'SELECT id, class_id, subject_code, subject_name
           FROM subjects
          WHERE is_active = 1
          ORDER BY class_id, subject_code'
    )->fetchAll();
}

function attendance_class_by_id(PDO $pdo, int $classId): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, department, academic_year, division
           FROM classes
          WHERE id = :id'
    );

    $statement->bindValue(':id', $classId, PDO::PARAM_INT);
    $statement->execute();

    $class = $statement->fetch();

    return $class === false ? null : $class;
}

function attendance_subject_by_id(PDO $pdo, int $subjectId): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, class_id, subject_code, subject_name
           FROM subjects
          WHERE id = :id'
    );

    $statement->bindValue(':id', $subjectId, PDO::PARAM_INT);
    $statement->execute();

    $subject = $statement->fetch();

    return $subject === false ? null : $subject;
}

/**
 * Looks for a still-open session for the same class, subject and date.
 *
 * The schema deliberately allows several lectures of one subject on the same
 * day (no unique key on that trio), so this guard lives in the application:
 * it stops the teacher accidentally opening the same lecture twice while a
 * previous one is still open. Closed sessions on the same day are fine.
 */
function attendance_find_open_session(PDO $pdo, int $classId, int $subjectId, string $date): ?array
{
    $statement = $pdo->prepare(
        "SELECT id, session_label, status, started_at
           FROM attendance_sessions
          WHERE class_id = :class
            AND subject_id = :subject
            AND session_date = :date
            AND status IN ('scheduled','open')
          ORDER BY id DESC
          LIMIT 1"
    );

    $statement->bindValue(':class', $classId, PDO::PARAM_INT);
    $statement->bindValue(':subject', $subjectId, PDO::PARAM_INT);
    $statement->bindValue(':date', $date, PDO::PARAM_STR);
    $statement->execute();

    $session = $statement->fetch();

    return $session === false ? null : $session;
}

/**
 * Validates the session-setup form.
 *
 * @return array{errors: array, values: array}
 */
function attendance_validate_setup(array $input, PDO $pdo): array
{
    $errors  = [];
    $classId = (int) ($input['class_id'] ?? 0);
    $subjectId = (int) ($input['subject_id'] ?? 0);
    $date    = trim((string) ($input['session_date'] ?? ''));
    $seconds = (int) ($input['seconds_per_student'] ?? ATTENDANCE_DEFAULT_SECONDS);
    $label   = trim((string) ($input['session_label'] ?? ''));

    if ($label === '') {
        $label = 'Lecture';
    }

    if (mb_strlen($label) > 100) {
        $errors['session_label'] = 'Session label must be 100 characters or fewer.';
    }

    if ($classId <= 0) {
        $errors['class_id'] = 'Choose the class you are taking attendance for.';
    } elseif (attendance_class_by_id($pdo, $classId) === null) {
        $errors['class_id'] = 'That class no longer exists. Reload the page.';
    }

    if ($subjectId <= 0) {
        $errors['subject_id'] = 'Choose the subject for this lecture.';
    } else {
        $subject = attendance_subject_by_id($pdo, $subjectId);

        if ($subject === null) {
            $errors['subject_id'] = 'That subject no longer exists. Reload the page.';
        } elseif ($classId > 0 && (int) $subject['class_id'] !== $classId) {
            $errors['subject_id'] = 'That subject does not belong to the selected class.';
        }
    }

    if ($date === '') {
        $errors['session_date'] = 'Choose the date of this lecture.';
    } elseif (!attendance_is_valid_date($date)) {
        $errors['session_date'] = 'Enter a real date, for example 2026-10-22.';
    }

    if ($seconds < ATTENDANCE_MIN_SECONDS || $seconds > ATTENDANCE_MAX_SECONDS) {
        $errors['seconds_per_student'] = 'Seconds per student must be between '
            . ATTENDANCE_MIN_SECONDS . ' and ' . ATTENDANCE_MAX_SECONDS . '.';
    }

    $duplicate = null;

    if ($errors === [] && $duplicate === null) {
        $duplicate = attendance_find_open_session($pdo, $classId, $subjectId, $date);

        if ($duplicate !== null) {
            $errors['form'] = 'An attendance session for this class, subject and date is already '
                . 'open. Resume it below instead of starting a second one.';
        }
    }

    return [
        'errors' => $errors,
        'values' => [
            'class_id'            => $classId,
            'subject_id'          => $subjectId,
            'session_date'        => $date,
            'seconds_per_student' => $seconds,
            'session_label'       => $label,
        ],
    ];
}

function attendance_is_valid_date(string $date): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
        return false;
    }

    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/**
 * Creates and opens a new manual attendance session.
 *
 * The session starts as 'open' with started_at set, and the chosen seconds per
 * student is stored so a refresh or a second tab always agrees on the limit.
 *
 * @return array{ok:bool,message:string,session_id:int}
 */
function attendance_session_create(array $values, int $userId): array
{
    try {
        $pdo = db();
    } catch (Throwable $exception) {
        return ['ok' => false, 'message' => 'The database is not available. No session was created.', 'session_id' => 0];
    }

    // Re-check the duplicate guard inside the same request that writes.
    $duplicate = attendance_find_open_session(
        $pdo,
        (int) $values['class_id'],
        (int) $values['subject_id'],
        (string) $values['session_date']
    );

    if ($duplicate !== null) {
        return [
            'ok'         => false,
            'message'    => 'A session for this class, subject and date is already open.',
            'session_id' => (int) $duplicate['id'],
        ];
    }

    try {
        $statement = $pdo->prepare(
            "INSERT INTO attendance_sessions
                (class_id, subject_id, created_by, session_date, session_label,
                 seconds_per_student, attendance_mode, status, started_at)
             VALUES
                (:class, :subject, :user, :date, :label, :seconds, 'manual', 'open', NOW())"
        );

        $statement->bindValue(':class', (int) $values['class_id'], PDO::PARAM_INT);
        $statement->bindValue(':subject', (int) $values['subject_id'], PDO::PARAM_INT);
        $statement->bindValue(':user', $userId, PDO::PARAM_INT);
        $statement->bindValue(':date', (string) $values['session_date'], PDO::PARAM_STR);
        $statement->bindValue(':label', (string) $values['session_label'], PDO::PARAM_STR);
        $statement->bindValue(':seconds', (int) $values['seconds_per_student'], PDO::PARAM_INT);
        $statement->execute();

        return [
            'ok'         => true,
            'message'    => 'Session started.',
            'session_id' => (int) $pdo->lastInsertId(),
        ];
    } catch (PDOException $exception) {
        error_log('[smart-attendance] session create failed: ' . $exception->getMessage());

        return ['ok' => false, 'message' => attendance_friendly_error($exception), 'session_id' => 0];
    }
}

/**
 * Loads one session joined with its class and subject labels.
 */
function attendance_session_find(int $sessionId): ?array
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
                se.seconds_per_student,
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

    return $session === false ? null : $session;
}

/* ---------------------------------------------------------------------------
 * Roster and marking
 * ------------------------------------------------------------------------ */

/**
 * The class register for a session, in ascending roll-number order.
 *
 * Roll numbers are strings ('01', 'CS01', '2A'), so the sort is numeric aware:
 * pure-numeric rolls sort by their numeric value first, then everything else
 * falls back to the string order. This matches the students list ordering.
 *
 * Each row carries the attendance status already stored for that student, or
 * 'not_marked' when no row exists yet.
 */
function attendance_roster(int $sessionId): array
{
    try {
        $pdo = db();
    } catch (Throwable $exception) {
        return [];
    }

    $statement = $pdo->prepare(
        'SELECT st.id                AS student_id,
                st.roll_number,
                st.full_name,
                a.status             AS stored_status,
                a.marked_at,
                a.id                 AS attendance_id
           FROM attendance_sessions se
           JOIN classes c       ON c.id = se.class_id
           JOIN enrollments e   ON e.class_id = se.class_id
                               AND e.enrollment_year = c.academic_year
           JOIN students st     ON st.id = e.student_id
                               AND st.is_active = 1
           LEFT JOIN attendance a ON a.session_id = se.id
                                AND a.student_id = st.id
          WHERE se.id = :id
          ORDER BY st.roll_number + 0, st.roll_number'
    );

    $statement->bindValue(':id', $sessionId, PDO::PARAM_INT);
    $statement->execute();

    $rows = [];

    foreach ($statement->fetchAll() as $index => $row) {
        $stored = $row['stored_status'];

        $rows[] = [
            'student_id'     => (int) $row['student_id'],
            'roll_number'    => (string) $row['roll_number'],
            'full_name'      => (string) $row['full_name'],
            'status'         => is_string($stored) && $stored !== '' ? $stored : 'not_marked',
            'marked_at'      => $row['marked_at'],
            'attendance_id'  => $row['attendance_id'] === null ? null : (int) $row['attendance_id'],
            'position'       => $index + 1,
        ];
    }

    return $rows;
}

/**
 * Present / absent / review / not-marked counts for a session.
 *
 * 'total' is the size of the roster, not the number of stored rows, so an
 * untouched student is still counted as not marked.
 */
function attendance_summary(int $sessionId): array
{
    $roster = attendance_roster($sessionId);

    $summary = [
        'total'       => count($roster),
        'present'     => 0,
        'absent'      => 0,
        'review'      => 0,
        'not_marked'  => 0,
    ];

    foreach ($roster as $row) {
        $status = (string) $row['status'];

        if (isset($summary[$status])) {
            $summary[$status]++;
        } else {
            $summary['not_marked']++;
        }
    }

    return $summary;
}

/**
 * Records or corrects one student's status inside a transaction.
 *
 * The unique key on (session_id, student_id) means this is an upsert: the
 * first mark inserts, any later mark updates the same row. That is how a
 * teacher corrects a status before finishing without creating duplicates.
 *
 * @return array{ok:bool,message:string}
 */
function attendance_mark(int $sessionId, int $studentId, string $status, int $userId): array
{
    if (!in_array($status, attendance_status_allowlist(), true)) {
        return ['ok' => false, 'message' => 'That attendance status is not allowed.'];
    }

    try {
        $pdo = db();
    } catch (Throwable $exception) {
        return ['ok' => false, 'message' => 'The database is not available. Nothing was saved.'];
    }

    $session = attendance_session_find($sessionId);

    if ($session === null) {
        return ['ok' => false, 'message' => 'That attendance session no longer exists.'];
    }

    if ((string) $session['status'] !== 'open') {
        return ['ok' => false, 'message' => 'This session is already finished. Marks can no longer be changed.'];
    }

    // The student must be on this session's roster before we touch the table.
    $roster = attendance_roster($sessionId);
    $onRoster = false;

    foreach ($roster as $row) {
        if ((int) $row['student_id'] === $studentId) {
            $onRoster = true;
            break;
        }
    }

    if (!$onRoster) {
        return ['ok' => false, 'message' => 'That student is not enrolled in this class, so they cannot be marked.'];
    }

    try {
        $pdo->beginTransaction();

        $statement = $pdo->prepare(
            'INSERT INTO attendance (session_id, student_id, status, marked_by, marked_at)
             VALUES (:session, :student, :status, :user, NOW())
             ON DUPLICATE KEY UPDATE
                status    = VALUES(status),
                marked_by = VALUES(marked_by),
                marked_at = VALUES(marked_at)'
        );

        $statement->bindValue(':session', $sessionId, PDO::PARAM_INT);
        $statement->bindValue(':student', $studentId, PDO::PARAM_INT);
        $statement->bindValue(':status', $status, PDO::PARAM_STR);
        $statement->bindValue(':user', $userId, PDO::PARAM_INT);
        $statement->execute();

        $pdo->commit();

        return [
            'ok'      => true,
            'message' => attendance_status_label($status) . ' recorded for roll number '
                . attendance_roll_of($roster, $studentId) . '.',
        ];
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('[smart-attendance] attendance mark failed: ' . $exception->getMessage());

        return ['ok' => false, 'message' => attendance_friendly_error($exception)];
    }
}

function attendance_roll_of(array $roster, int $studentId): string
{
    foreach ($roster as $row) {
        if ((int) $row['student_id'] === $studentId) {
            return (string) $row['roll_number'];
        }
    }

    return (string) $studentId;
}

/**
 * Closes a session so no further marks are accepted.
 *
 * Not-marked students are never converted to absent. They stay not marked and
 * are listed in the final summary so the teacher can see exactly who was
 * skipped.
 *
 * @return array{ok:bool,message:string}
 */
function attendance_finish(int $sessionId): array
{
    try {
        $pdo = db();
    } catch (Throwable $exception) {
        return ['ok' => false, 'message' => 'The database is not available. The session was not finished.'];
    }

    $session = attendance_session_find($sessionId);

    if ($session === null) {
        return ['ok' => false, 'message' => 'That attendance session no longer exists.'];
    }

    if ((string) $session['status'] === 'closed') {
        return ['ok' => false, 'message' => 'This session has already been finished.'];
    }

    try {
        $statement = $pdo->prepare(
            "UPDATE attendance_sessions
                SET status = 'closed',
                    closed_at = NOW()
              WHERE id = :id
                AND status = 'open'"
        );

        $statement->bindValue(':id', $sessionId, PDO::PARAM_INT);
        $statement->execute();

        return ['ok' => true, 'message' => 'Session finished and locked.'];
    } catch (PDOException $exception) {
        error_log('[smart-attendance] session finish failed: ' . $exception->getMessage());

        return ['ok' => false, 'message' => attendance_friendly_error($exception)];
    }
}

/**
 * Recent sessions for the "resume or review" list on the setup screen.
 */
function attendance_recent_sessions(int $limit = 8): array
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
          WHERE se.attendance_mode = \'manual\'
          ORDER BY se.status = \'open\' DESC, se.session_date DESC, se.id DESC
          LIMIT :limit'
    );

    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

/**
 * Turns database integrity messages into readable text for the teacher.
 */
function attendance_friendly_error(PDOException $exception): string
{
    $message = $exception->getMessage();

    if (str_contains($message, 'student is not enrolled in the session class')) {
        return 'That student is not enrolled in this class for the session academic year.';
    }

    if (str_contains($message, 'session does not exist')) {
        return 'That attendance session no longer exists.';
    }

    if (str_contains($message, 'Duplicate entry')) {
        return 'That student already has a record in this session. The existing record was updated.';
    }

    if (str_contains($message, 'foreign key constraint')) {
        return 'The class, subject or student this session points at no longer exists.';
    }

    if (str_contains($message, 'Data too long')) {
        return 'One of the values is not allowed for that column. Check the status you are saving.';
    }

    return 'The database rejected that change. Nothing was saved.';
}
