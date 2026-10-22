<?php
/**
 * SMART ATTENDANCE AI - PHASE 6
 *
 * Creates one secure login account per active student and links it to the
 * student record through users.student_id.
 *
 * Why this exists: the users table originally only allowed admin and teacher,
 * so a student scanning a QR code had no way to prove who they were. QR
 * attendance is only meaningful if the identity comes from a real, authenticated
 * account, so this script seeds the missing student logins.
 *
 * Safety rules this script follows:
 *   - It never overwrites an existing account. If a student already has a login
 *     (matched on student_id) the row is left exactly as it is.
 *   - Passwords are stored as bcrypt hashes only, generated at run time.
 *   - It only touches students who are is_active = 1.
 *   - It is idempotent: running it twice creates nothing the second time.
 *
 * Usage:
 *   php database/seed_qr_students.php                 # uses the default password
 *   php database/seed_qr_students.php Student@456     # custom password
 */

declare(strict_types=1);

require __DIR__ . '/../config/db.php';

$password = $argv[1] ?? 'Student@123';

if (strlen($password) < 8) {
    fwrite(STDERR, "Password must be at least 8 characters.\n");
    exit(1);
}

try {
    $pdo = db();
} catch (Throwable $exception) {
    fwrite(STDERR, 'Database unavailable: ' . $exception->getMessage() . "\n");
    exit(1);
}

$students = $pdo->query(
    'SELECT id, full_name, email
       FROM students
      WHERE is_active = 1
      ORDER BY id'
)->fetchAll();

$hash = password_hash($password, PASSWORD_DEFAULT);

$find = $pdo->prepare('SELECT id FROM users WHERE student_id = :sid');
$find->bindParam(':sid', $dummy, PDO::PARAM_INT); // bound per iteration below

$create = $pdo->prepare(
    "INSERT INTO users (full_name, email, password_hash, role, is_active, student_id)
     VALUES (:name, :email, :hash, 'student', 1, :sid)"
);

$created = 0;
$skipped = 0;

foreach ($students as $student) {
    $studentId = (int) $student['id'];

    $find->bindValue(':sid', $studentId, PDO::PARAM_INT);
    $find->execute();

    if ($find->fetch() !== false) {
        $skipped++;
        continue;
    }

    $email = $student['email'] !== null && $student['email'] !== ''
        ? (string) $student['email']
        : 'student' . $studentId . '@smartattendance.local';

    // Guard against an email that is already used by a non-student account.
    $dupe = $pdo->prepare('SELECT id FROM users WHERE email = :email');
    $dupe->bindValue(':email', $email, PDO::PARAM_STR);
    $dupe->execute();

    if ($dupe->fetch() !== false) {
        $email = 'student' . $studentId . '@smartattendance.local';
    }

    $create->bindValue(':name', (string) $student['full_name'], PDO::PARAM_STR);
    $create->bindValue(':email', $email, PDO::PARAM_STR);
    $create->bindValue(':hash', $hash, PDO::PARAM_STR);
    $create->bindValue(':sid', $studentId, PDO::PARAM_INT);
    $create->execute();

    $created++;
    echo "Created student login: {$email}  (student_id {$studentId})\n";
}

echo "\nDone. Created {$created}, already present {$skipped}.\n";
echo "Default password for every new student account: {$password}\n";
echo "Change these before any real deployment.\n";
