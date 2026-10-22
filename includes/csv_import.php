<?php
/**
 * SMART ATTENDANCE AI - PHASE 4
 *
 * Bulk student import from a CSV file.
 *
 * The flow is: parse -> validate every row -> stage in the session -> show a
 * preview -> confirm -> execute inside a single transaction. Each row runs in
 * its own savepoint so one bad row never rolls back the rows before it, and
 * the final commit is all-or-nothing for the whole batch.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/students.php';

const CSV_MAX_ROWS     = 2000;
const CSV_ALLOWED_EXT  = ['csv'];
const CSV_ALLOWED_MIME = ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel', 'text/comma-separated-values'];

/**
 * The columns accepted in an uploaded CSV, in template order.
 */
function csv_expected_columns(): array
{
    return ['roll_number', 'full_name', 'email'];
}

/**
 * A ready-to-download sample CSV.
 */
function csv_template_contents(): string
{
    $rows = [
        ['roll_number', 'full_name', 'email'],
        ['01', 'Aarav Sharma', 'aarav.sharma@smartattendance.local'],
        ['02', 'Diya Patel', 'diya.patel@smartattendance.local'],
        ['03', 'Rohan Mehta', ''],
    ];

    $output = fopen('php://temp', 'r+');

    foreach ($rows as $row) {
        fputcsv($output, $row);
    }

    rewind($output);
    $contents = (string) stream_get_contents($output);
    fclose($output);

    return $contents;
}

/**
 * Reads a CSV file into rows keyed by the header names.
 *
 * Handles a UTF-8 BOM, quoted fields and semicolon or comma delimiters.
 *
 * @return array{ok: bool, error: string, rows: array, headers: array}
 */
function csv_parse_file(string $path): array
{
    $handle = @fopen($path, 'rb');

    if ($handle === false) {
        return ['ok' => false, 'error' => 'The uploaded file could not be opened.', 'rows' => [], 'headers' => []];
    }

    try {
        $firstLine = fgets($handle);

        if ($firstLine === false) {
            return ['ok' => false, 'error' => 'The uploaded file is empty.', 'rows' => [], 'headers' => []];
        }

        $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);

        $delimiter = (substr_count($firstLine, ',') >= substr_count($firstLine, ';')) ? ',' : ';';

        rewind($handle);

        $headers = fgetcsv($handle, 0, $delimiter, '"', '\\');

        if ($headers === false) {
            return ['ok' => false, 'error' => 'The header row could not be read.', 'rows' => [], 'headers' => []];
        }

        $headers = array_map(
            static fn ($header): string => strtolower(trim((string) $header)),
            $headers
        );

        foreach (csv_expected_columns() as $expected) {
            if (!in_array($expected, $headers, true)) {
                return [
                    'ok'      => false,
                    'error'   => 'The file is missing the "' . $expected . '" column. Download the sample template and try again.',
                    'rows'    => [],
                    'headers' => [],
                ];
            }
        }

        $rows  = [];
        $line  = 2;
        $count = 0;

        while (($data = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $count++;

            if ($count > CSV_MAX_ROWS) {
                return [
                    'ok'      => false,
                    'error'   => 'The file has more than ' . CSV_MAX_ROWS . ' data rows. Split it into smaller batches.',
                    'rows'    => [],
                    'headers' => [],
                ];
            }

            $row = [];

            foreach ($headers as $index => $header) {
                $row[$header] = isset($data[$index]) ? trim((string) $data[$index]) : '';
            }

            if ($row === [] || implode('', array_values($row)) === '') {
                $line++;
                continue;
            }

            $row['_line'] = $line;
            $rows[]       = $row;
            $line++;
        }

        if ($rows === []) {
            return ['ok' => false, 'error' => 'The file has a header row but no data rows.', 'rows' => [], 'headers' => []];
        }

        return ['ok' => true, 'error' => '', 'rows' => $rows, 'headers' => $headers];
    } finally {
        fclose($handle);
    }
}

/**
 * Adds a status and a message to every parsed row.
 *
 * Statuses:
 *   valid      - safe to import
 *   duplicate  - same roll + name already exists and is enrolled; skipped
 *   conflict   - roll number already taken by another student in this class
 *   invalid    - failed validation; skipped
 *
 * @return array{rows: array, valid: int, duplicate: int, conflict: int, invalid: int}
 */
function csv_validate_rows(array $rows, PDO $pdo, int $classId, string $year): array
{
    $class = student_class_by_id($pdo, $classId);

    if ($class === null) {
        throw new RuntimeException('The selected class no longer exists.');
    }

    $seenInFile = [];

    foreach ($rows as &$row) {
        $roll  = (string) ($row['roll_number'] ?? '');
        $name  = (string) ($row['full_name'] ?? '');
        $email = (string) ($row['email'] ?? '');

        $problems = [];

        if ($roll === '') {
            $problems[] = 'Roll number is missing.';
        } elseif (mb_strlen($roll) > 30) {
            $problems[] = 'Roll number is longer than 30 characters.';
        } elseif (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} .\/\-]*$/u', $roll)) {
            $problems[] = 'Roll number contains unsupported characters.';
        }

        if ($name === '') {
            $problems[] = 'Full name is missing.';
        } elseif (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $problems[] = 'Full name must be between 2 and 120 characters.';
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $problems[] = 'Email address is not valid. Leave it empty if unknown.';
        }

        if ($problems !== []) {
            $row['status']  = 'invalid';
            $row['message'] = implode(' ', $problems);
            continue;
        }

        $fileKey = mb_strtolower($roll . '|' . $name);

        if (isset($seenInFile[$fileKey])) {
            $row['status']  = 'duplicate';
            $row['message'] = 'Appears again in this file (first at line ' . $seenInFile[$fileKey] . '). It will be imported once.';
            continue;
        }

        $seenInFile[$fileKey] = (int) $row['_line'];

        $existing = student_find_by_roll_name($pdo, $roll, $name);

        if ($existing !== null) {
            $enrolled = student_is_enrolled($pdo, (int) $existing['id'], $classId, $year);

            if ($enrolled) {
                $row['status']  = 'duplicate';
                $row['message'] = 'This student already exists and is already enrolled in this class.';
                continue;
            }

            $row['status']  = 'valid';
            $row['message'] = 'Existing student profile will be reused and enrolled.';
            $row['_reuse']  = true;
            continue;
        }

        $rollTaken = student_roll_taken_in_class($pdo, $roll, $classId, $year);

        if ($rollTaken) {
            $row['status']  = 'conflict';
            $row['message'] = 'Another student already uses this roll number in this class for this year.';
            continue;
        }

        $row['status']  = 'valid';
        $row['message'] = 'New student will be created and enrolled.';
    }

    unset($row);

    $summary = [
        'rows'       => $rows,
        'valid'      => 0,
        'duplicate'  => 0,
        'conflict'   => 0,
        'invalid'    => 0,
    ];

    foreach ($rows as $row) {
        $summary[$row['status']]++;
    }

    return $summary;
}

function student_is_enrolled(PDO $pdo, int $studentId, int $classId, string $year): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM enrollments
          WHERE student_id = :student AND class_id = :class AND enrollment_year = :year'
    );

    $statement->bindValue(':student', $studentId, PDO::PARAM_INT);
    $statement->bindValue(':class', $classId, PDO::PARAM_INT);
    $statement->bindValue(':year', $year, PDO::PARAM_STR);
    $statement->execute();

    return (int) $statement->fetchColumn() > 0;
}

function student_roll_taken_in_class(PDO $pdo, string $roll, int $classId, string $year): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
           FROM enrollments e
           JOIN students s ON s.id = e.student_id
          WHERE e.class_id = :class
            AND e.enrollment_year = :year
            AND s.roll_number = :roll'
    );

    $statement->bindValue(':class', $classId, PDO::PARAM_INT);
    $statement->bindValue(':year', $year, PDO::PARAM_STR);
    $statement->bindValue(':roll', $roll, PDO::PARAM_STR);
    $statement->execute();

    return (int) $statement->fetchColumn() > 0;
}

/* ---------------------------------------------------------------------------
 * Staging: the validated batch lives in the session until it is confirmed or
 * cancelled. Nothing touches the database during this step.
 * ------------------------------------------------------------------------ */

function import_stage(array $meta, array $rows): void
{
    $_SESSION['smart_attendance_import'] = [
        'meta'  => $meta,
        'rows'  => $rows,
        'staged_at' => date('Y-m-d H:i:s'),
    ];
}

function import_staged(): ?array
{
    return $_SESSION['smart_attendance_import'] ?? null;
}

function import_clear(): void
{
    unset($_SESSION['smart_attendance_import']);
}

/**
 * Executes the staged batch. Every row runs inside a savepoint; the whole
 * batch commits at the end so a mid-way failure never leaves a half import.
 *
 * @return array{ok: bool, message: string, results: array, summary: array}
 */
function import_execute(PDO $pdo, array $meta, array $rows): array
{
    $classId = (int) ($meta['class_id'] ?? 0);
    $year    = (string) ($meta['enrollment_year'] ?? '');

    $class = student_class_by_id($pdo, $classId);

    if ($class === null) {
        return ['ok' => false, 'message' => 'The selected class no longer exists.', 'results' => [], 'summary' => []];
    }

    $summary = ['imported' => 0, 'reused' => 0, 'skipped' => 0, 'failed' => 0];
    $results = [];

    try {
        $pdo->beginTransaction();

        foreach ($rows as $row) {
            if (($row['status'] ?? 'invalid') !== 'valid') {
                $summary['skipped']++;
                $results[] = [
                    'roll'    => (string) ($row['roll_number'] ?? ''),
                    'name'    => (string) ($row['full_name'] ?? ''),
                    'outcome' => 'skipped',
                    'message' => (string) ($row['message'] ?? 'Row was not valid.'),
                ];
                continue;
            }

            $roll  = (string) $row['roll_number'];
            $name  = (string) $row['full_name'];
            $email = trim((string) ($row['email'] ?? ''));

            $savepoint = 'row_' . md5($roll . '|' . $name . '|' . microtime(true) . '|' . random_int(0, PHP_INT_MAX));

            try {
                $pdo->exec('SAVEPOINT ' . $savepoint);

                $existing = student_find_by_roll_name($pdo, $roll, $name);

                if ($existing !== null) {
                    $enrollResult = student_enroll($pdo, (int) $existing['id'], $classId);

                    if ($enrollResult['created']) {
                        $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                        $summary['reused']++;
                        $results[] = [
                            'roll'    => $roll,
                            'name'    => $name,
                            'outcome' => 'reused',
                            'message' => 'Existing profile reused and enrolled.',
                        ];
                    } else {
                        $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                        $summary['skipped']++;
                        $results[] = [
                            'roll'    => $roll,
                            'name'    => $name,
                            'outcome' => 'skipped',
                            'message' => $enrollResult['message'],
                        ];
                    }

                    continue;
                }

                $insertStudent = $pdo->prepare(
                    'INSERT INTO students (roll_number, full_name, email, is_active)
                     VALUES (:roll, :name, :email, 1)'
                );

                $insertStudent->bindValue(':roll', $roll, PDO::PARAM_STR);
                $insertStudent->bindValue(':name', $name, PDO::PARAM_STR);
                $insertStudent->bindValue(':email', $email === '' ? null : $email, PDO::PARAM_STR);
                $insertStudent->execute();

                $studentId = (int) $pdo->lastInsertId();

                $enrollResult = student_enroll($pdo, $studentId, $classId);

                if (!$enrollResult['created']) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $summary['failed']++;
                    $results[] = [
                        'roll'    => $roll,
                        'name'    => $name,
                        'outcome' => 'failed',
                        'message' => $enrollResult['message'],
                    ];
                    continue;
                }

                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                $summary['imported']++;
                $results[] = [
                    'roll'    => $roll,
                    'name'    => $name,
                    'outcome' => 'imported',
                    'message' => 'Created and enrolled.',
                ];
            } catch (PDOException $exception) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                $summary['failed']++;
                $results[] = [
                    'roll'    => $roll,
                    'name'    => $name,
                    'outcome' => 'failed',
                    'message' => student_friendly_error($exception),
                ];
            }
        }

        $pdo->commit();

        return [
            'ok'      => true,
            'message' => 'Import finished. ' . $summary['imported'] . ' new, '
                . $summary['reused'] . ' reused, ' . $summary['skipped'] . ' skipped, '
                . $summary['failed'] . ' failed.',
            'results' => $results,
            'summary' => $summary,
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('[smart-attendance] import batch failed: ' . $exception->getMessage());

        return [
            'ok'      => false,
            'message' => 'The import failed and nothing was saved. The database was rolled back.',
            'results' => [],
            'summary' => [],
        ];
    }
}
