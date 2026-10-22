<?php
/**
 * SMART ATTENDANCE AI - PHASE 4
 *
 * Student management data layer.
 *
 * Everything in this file uses prepared statements and maps the database
 * trigger messages back to readable text. Roll numbers are strings and are
 * only unique inside a class + academic year, so a student can be re-enrolled
 * in the same class in a later year.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

const STUDENTS_PER_PAGE = 20;

/**
 * Classes available for the enrolment selects.
 */
function student_classes(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT id, department, academic_year, division
           FROM classes
          ORDER BY academic_year DESC, department, division'
    );

    return $statement->fetchAll();
}

/**
 * Classes for the filter bar (same data, kept separate for clarity).
 */
function student_filter_classes(PDO $pdo): array
{
    return student_classes($pdo);
}

/**
 * Builds the WHERE clause shared by the list and the count query.
 */
function students_where_clause(array $filters): array
{
    $sql    = [];
    $params = [];

    $search = trim((string) ($filters['search'] ?? ''));

    if ($search !== '') {
        $sql[]               = '(st.roll_number LIKE :search_roll OR st.full_name LIKE :search_name)';
        $params[':search_roll'] = '%' . $search . '%';
        $params[':search_name'] = '%' . $search . '%';
    }

    $classId = (int) ($filters['class_id'] ?? 0);

    if ($classId > 0) {
        $sql[]            = 'EXISTS (
                                SELECT 1 FROM enrollments e
                                 WHERE e.student_id = st.id
                                   AND e.class_id = :class_id
                              )';
        $params[':class_id'] = $classId;
    }

    $status = (string) ($filters['status'] ?? 'active');

    if ($status === 'active') {
        $sql[] = 'st.is_active = 1';
    } elseif ($status === 'inactive') {
        $sql[] = 'st.is_active = 0';
    }

    return [$sql, $params];
}

/**
 * Paginated student list, each row carrying its primary enrolment.
 *
 * @return array{rows: array, total: int, page: int, pages: int}
 */
function students_fetch_page(PDO $pdo, array $filters, int $page): array
{
    [$sql, $params] = students_where_clause($filters);

    $where = $sql === [] ? '1 = 1' : implode(' AND ', $sql);

    $countStatement = $pdo->prepare('SELECT COUNT(*) FROM students st WHERE ' . $where);
    $countStatement->execute($params);
    $total = (int) $countStatement->fetchColumn();

    $pages = max(1, (int) ceil($total / STUDENTS_PER_PAGE));
    $page  = max(1, min($page, $pages));
    $offset = ($page - 1) * STUDENTS_PER_PAGE;

    $listStatement = $pdo->prepare(
        'SELECT st.id,
                st.roll_number,
                st.full_name,
                st.email,
                st.is_active
           FROM students st
          WHERE ' . $where . '
          ORDER BY st.roll_number + 0, st.roll_number
          LIMIT :limit OFFSET :offset'
    );

    foreach ($params as $key => $value) {
        $listStatement->bindValue($key, $value, PDO::PARAM_STR);
    }

    $listStatement->bindValue(':limit', STUDENTS_PER_PAGE, PDO::PARAM_INT);
    $listStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $listStatement->execute();

    $rows = $listStatement->fetchAll();

    students_attach_enrollments($pdo, $rows);

    return [
        'rows'  => $rows,
        'total' => $total,
        'page'  => $page,
        'pages' => $pages,
    ];
}

/**
 * Loads the enrolments for the given students in one query.
 * `enroll_primary` holds the first enrolment; `enroll_count` counts them all.
 */
function students_attach_enrollments(PDO $pdo, array &$rows): void
{
    if ($rows === []) {
        return;
    }

    $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $statement = $pdo->prepare(
        "SELECT e.student_id,
                e.id                AS enrollment_id,
                e.enrollment_year,
                c.id                AS class_id,
                c.department,
                c.academic_year,
                c.division
           FROM enrollments e
           JOIN classes c ON c.id = e.class_id
          WHERE e.student_id IN ($placeholders)
          ORDER BY e.id"
    );

    $statement->execute($ids);

    $grouped = [];

    foreach ($statement->fetchAll() as $enrollment) {
        $grouped[(int) $enrollment['student_id']][] = $enrollment;
    }

    foreach ($rows as &$row) {
        $studentId   = (int) $row['id'];
        $enrollments = $grouped[$studentId] ?? [];

        $row['enrollments']   = $enrollments;
        $row['enroll_count']  = count($enrollments);
        $row['enroll_primary'] = $enrollments[0] ?? null;
    }
}

/**
 * Single student with every enrolment, used by the edit screen.
 */
function student_find(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, roll_number, full_name, email, is_active
           FROM students
          WHERE id = :id'
    );

    $statement->bindValue(':id', $id, PDO::PARAM_INT);
    $statement->execute();

    $student = $statement->fetch();

    if ($student === false) {
        return null;
    }

    $enrollmentStatement = $pdo->prepare(
        'SELECT e.id AS enrollment_id,
                e.enrollment_year,
                c.id AS class_id,
                c.department,
                c.academic_year,
                c.division
           FROM enrollments e
           JOIN classes c ON c.id = e.class_id
          WHERE e.student_id = :id
          ORDER BY e.id'
    );

    $enrollmentStatement->bindValue(':id', $id, PDO::PARAM_INT);
    $enrollmentStatement->execute();

    $student['enrollments'] = $enrollmentStatement->fetchAll();

    return $student;
}

/**
 * Looks for an existing student with the same roll number and name.
 * Used by bulk imports so the same person is never created twice.
 */
function student_find_by_roll_name(PDO $pdo, string $rollNumber, string $fullName): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, roll_number, full_name, email, is_active
           FROM students
          WHERE roll_number = :roll
            AND full_name = :name
          LIMIT 1'
    );

    $statement->bindValue(':roll', $rollNumber, PDO::PARAM_STR);
    $statement->bindValue(':name', $fullName, PDO::PARAM_STR);
    $statement->execute();

    $student = $statement->fetch();

    return $student === false ? null : $student;
}

/**
 * Validates one student form submission.
 *
 * @return array{errors: array, values: array}
 */
function student_validate(array $input, PDO $pdo): array
{
    $errors = [];

    $rollNumber = trim((string) ($input['roll_number'] ?? ''));
    $fullName   = trim((string) ($input['full_name'] ?? ''));
    $email      = trim((string) ($input['email'] ?? ''));
    $classId    = (int) ($input['class_id'] ?? 0);

    if ($rollNumber === '') {
        $errors['roll_number'] = 'Roll number is required.';
    } elseif (mb_strlen($rollNumber) > 30) {
        $errors['roll_number'] = 'Roll number must be 30 characters or fewer.';
    } elseif (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} .\/\-]*$/u', $rollNumber)) {
        $errors['roll_number'] = 'Roll number may only contain letters, numbers, spaces, dots, slashes and hyphens.';
    }

    if ($fullName === '') {
        $errors['full_name'] = 'Full name is required.';
    } elseif (mb_strlen($fullName) < 2) {
        $errors['full_name'] = 'Full name is too short.';
    } elseif (mb_strlen($fullName) > 120) {
        $errors['full_name'] = 'Full name must be 120 characters or fewer.';
    }

    if ($email !== '') {
        if (mb_strlen($email) > 190) {
            $errors['email'] = 'Email must be 190 characters or fewer.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address or leave it empty.';
        }
    }

    if ($classId <= 0) {
        $errors['class_id'] = 'Choose a class to enrol this student in.';
    } else {
        $class = student_class_by_id($pdo, $classId);

        if ($class === null) {
            $errors['class_id'] = 'The selected class no longer exists.';
        }
    }

    return [
        'errors' => $errors,
        'values' => [
            'roll_number' => $rollNumber,
            'full_name'   => $fullName,
            'email'       => $email === '' ? null : $email,
            'class_id'    => $classId,
        ],
    ];
}

function student_class_by_id(PDO $pdo, int $classId): ?array
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

/**
 * Creates a student and enrols them in the selected class.
 *
 * If a student with the same roll number and name already exists, the existing
 * profile is reused and only the enrolment is added. Nothing is overwritten.
 *
 * @return array{ok: bool, message: string}
 */
function student_create(PDO $pdo, array $values): array
{
    $class = student_class_by_id($pdo, (int) $values['class_id']);

    if ($class === null) {
        return ['ok' => false, 'message' => 'The selected class no longer exists.'];
    }

    $year = (string) $class['academic_year'];

    $existing = student_find_by_roll_name(
        $pdo,
        (string) $values['roll_number'],
        (string) $values['full_name']
    );

    $reused = false;

    if ($existing !== null) {
        $studentId = (int) $existing['id'];
        $reused    = true;
    } else {
        try {
            $insert = $pdo->prepare(
                'INSERT INTO students (roll_number, full_name, email, is_active)
                 VALUES (:roll, :name, :email, 1)'
            );

            $insert->bindValue(':roll', (string) $values['roll_number'], PDO::PARAM_STR);
            $insert->bindValue(':name', (string) $values['full_name'], PDO::PARAM_STR);
            $insert->bindValue(':email', $values['email'], PDO::PARAM_STR);
            $insert->execute();

            $studentId = (int) $pdo->lastInsertId();
        } catch (PDOException $exception) {
            return ['ok' => false, 'message' => student_friendly_error($exception)];
        }
    }

    $enrollmentResult = student_enroll($pdo, $studentId, (int) $values['class_id']);

    if ($reused && $enrollmentResult['created'] === false) {
        return [
            'ok'      => false,
            'message' => 'A student named ' . (string) $values['full_name']
                . ' with roll number ' . (string) $values['roll_number']
                . ' already exists and is already enrolled in this class.',
        ];
    }

    if ($enrollmentResult['created'] === false) {
        return ['ok' => false, 'message' => $enrollmentResult['message']];
    }

    if ($reused) {
        return [
            'ok'      => true,
            'message' => 'Existing student profile reused and enrolled in '
                . student_class_label($class) . ' for ' . $year . '.',
        ];
    }

    return [
        'ok'      => true,
        'message' => 'Student created and enrolled in '
            . student_class_label($class) . ' for ' . $year . '.',
    ];
}

/**
 * Updates a student profile. Enrolments are not touched here.
 *
 * @return array{ok: bool, message: string}
 */
function student_update(PDO $pdo, int $studentId, array $values): array
{
    if (student_find($pdo, $studentId) === null) {
        return ['ok' => false, 'message' => 'That student no longer exists.'];
    }

    try {
        $statement = $pdo->prepare(
            'UPDATE students
                SET roll_number = :roll,
                    full_name   = :name,
                    email       = :email
              WHERE id = :id'
        );

        $statement->bindValue(':roll', (string) $values['roll_number'], PDO::PARAM_STR);
        $statement->bindValue(':name', (string) $values['full_name'], PDO::PARAM_STR);
        $statement->bindValue(':email', $values['email'], PDO::PARAM_STR);
        $statement->bindValue(':id', $studentId, PDO::PARAM_INT);
        $statement->execute();

        return ['ok' => true, 'message' => 'Student details updated.'];
    } catch (PDOException $exception) {
        return ['ok' => false, 'message' => student_friendly_error($exception)];
    }
}

/**
 * Moves or adds an enrolment for a student.
 *
 * @return array{created: bool, message: string}
 */
function student_enroll(PDO $pdo, int $studentId, int $classId): array
{
    $class = student_class_by_id($pdo, $classId);

    if ($class === null) {
        return ['created' => false, 'message' => 'The selected class no longer exists.'];
    }

    $year = (string) $class['academic_year'];

    $exists = $pdo->prepare(
        'SELECT COUNT(*) FROM enrollments
          WHERE student_id = :student AND class_id = :class AND enrollment_year = :year'
    );

    $exists->bindValue(':student', $studentId, PDO::PARAM_INT);
    $exists->bindValue(':class', $classId, PDO::PARAM_INT);
    $exists->bindValue(':year', $year, PDO::PARAM_STR);
    $exists->execute();

    if ((int) $exists->fetchColumn() > 0) {
        return [
            'created' => false,
            'message' => 'This student is already enrolled in '
                . student_class_label($class) . ' for ' . $year . '.',
        ];
    }

    try {
        $insert = $pdo->prepare(
            'INSERT INTO enrollments (student_id, class_id, enrollment_year)
             VALUES (:student, :class, :year)'
        );

        $insert->bindValue(':student', $studentId, PDO::PARAM_INT);
        $insert->bindValue(':class', $classId, PDO::PARAM_INT);
        $insert->bindValue(':year', $year, PDO::PARAM_STR);
        $insert->execute();

        return [
            'created' => true,
            'message' => 'Enrolled in ' . student_class_label($class) . ' for ' . $year . '.',
        ];
    } catch (PDOException $exception) {
        return ['created' => false, 'message' => student_friendly_error($exception)];
    }
}

/**
 * Activates or deactivates a student. The record and its history are kept.
 *
 * @return array{ok: bool, message: string}
 */
function student_set_active(PDO $pdo, int $studentId, bool $active): array
{
    $statement = $pdo->prepare('UPDATE students SET is_active = :flag WHERE id = :id');

    $statement->bindValue(':flag', $active ? 1 : 0, PDO::PARAM_INT);
    $statement->bindValue(':id', $studentId, PDO::PARAM_INT);
    $statement->execute();

    return [
        'ok'      => true,
        'message' => $active
            ? 'Student re-activated. They can be marked again.'
            : 'Student deactivated. Their attendance history is kept.',
    ];
}

function student_class_label(array $class): string
{
    return $class['department'] . ' ' . $class['academic_year'] . ' · ' . $class['division'];
}

/**
 * Turns the database trigger / integrity messages into readable text.
 */
function student_friendly_error(PDOException $exception): string
{
    $message = $exception->getMessage();

    if (str_contains($message, 'roll number already used in this class')) {
        return 'Another student already uses this roll number in that class for this academic year.';
    }

    if (str_contains($message, 'roll number already used in another class context')) {
        return 'Changing to this roll number would clash with another student in a shared class.';
    }

    if (str_contains($message, 'Duplicate entry')) {
        return 'That value is already taken. Check the roll number and class.';
    }

    if (str_contains($message, 'foreign key constraint')) {
        return 'The selected class no longer exists. Reload the page and try again.';
    }

    error_log('[smart-attendance] student operation failed: ' . $message);

    return 'The database rejected that change. Nothing was saved.';
}
