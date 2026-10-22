-- ============================================================================
-- SMART ATTENDANCE AI - PHASE 2
-- Demo seed data (idempotent)
--
-- Import  : mysql -u root < database/seed.sql
--           (or paste into phpMyAdmin > SQL after importing schema.sql)
--
-- Every statement is idempotent, so the script can be imported repeatedly
-- without creating duplicate rows. Parent rows use their natural unique key
-- (email, class identity, subject code); students use a reserved id range
-- (101-106) because a student has no globally unique natural key.
--
-- Demo accounts (local development only - change before any real deployment):
--   admin@smartattendance.local   / Admin@123    role: admin
--   priya.sharma@smartattendance.local / Teacher@123  role: teacher
-- The password column stores bcrypt hashes only, never plain text.
-- ============================================================================

SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `smart_attendance`
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE `smart_attendance`;

-- ----------------------------------------------------------------------------
-- users
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `users` (`full_name`, `email`, `password_hash`, `role`, `is_active`) VALUES
('System Administrator', 'admin@smartattendance.local', '$2y$10$8QVR5ouNx0pM9tmtwzS..uZ8bFSQ.xHGbhq1USJg4biAyqVvBh4nO', 'admin', 1),
('Priya Sharma', 'priya.sharma@smartattendance.local', '$2y$10$vmpolCeCv57EKXLjiCSC1uIt0wwyhia8BPsqXwORw7Lj2L2kXB2km', 'teacher', 1);

-- ----------------------------------------------------------------------------
-- classes
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `classes` (`department`, `academic_year`, `division`) VALUES
('Diploma Computer Engineering', '2025-26', 'A'),
('Diploma Computer Engineering', '2024-25', 'B');

-- ----------------------------------------------------------------------------
-- students (roll numbers kept as text so 01, CS01 and 2A all work)
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `students` (`id`, `roll_number`, `full_name`, `email`) VALUES
(101, '01',   'Aarav Sharma',  'aarav.sharma@example.com'),
(102, '02',   'Diya Patel',    'diya.patel@example.com'),
(103, '03',   'Rohan Mehta',   'rohan.mehta@example.com'),
(104, '04',   'Ishita Verma',  'ishita.verma@example.com'),
(105, 'CS01', 'Kabir Singh',   'kabir.singh@example.com'),
(106, 'CS02', 'Ananya Gupta',  'ananya.gupta@example.com');

-- ----------------------------------------------------------------------------
-- enrollments (each student joined to the class of that academic year)
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `enrollments` (`student_id`, `class_id`, `enrollment_year`)
SELECT s.`id`, c.`id`, c.`academic_year`
FROM `students` s
CROSS JOIN `classes` c
WHERE (s.`id` IN (101, 102, 103, 104) AND c.`id` = 1)
   OR (s.`id` IN (105, 106)      AND c.`id` = 2);

-- ----------------------------------------------------------------------------
-- subjects
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `subjects` (`class_id`, `subject_code`, `subject_name`) VALUES
(1, 'DBMS', 'Database Management Systems'),
(1, 'WEB',  'Web Development'),
(2, 'DBMS', 'Database Management Systems');

-- ----------------------------------------------------------------------------
-- attendance_sessions
--   id 1 - manual mode, already closed
--   id 2 - QR mode, still open
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `attendance_sessions`
    (`id`, `class_id`, `subject_id`, `created_by`, `session_date`, `session_label`, `attendance_mode`, `status`, `started_at`, `closed_at`)
SELECT 1, c.`id`, su.`id`, u.`id`, '2026-10-20', 'Lecture 1', 'manual', 'closed',
       '2026-10-20 10:00:00', '2026-10-20 10:30:00'
FROM `classes` c
JOIN `subjects` su ON su.`class_id` = c.`id` AND su.`subject_code` = 'DBMS'
JOIN `users` u     ON u.`email` = 'admin@smartattendance.local'
WHERE c.`department` = 'Diploma Computer Engineering' AND c.`academic_year` = '2025-26' AND c.`division` = 'A';

INSERT IGNORE INTO `attendance_sessions`
    (`id`, `class_id`, `subject_id`, `created_by`, `session_date`, `session_label`, `attendance_mode`, `status`, `started_at`, `closed_at`)
SELECT 2, c.`id`, su.`id`, u.`id`, '2026-10-21', 'Lecture 1', 'qr', 'open',
       '2026-10-21 09:45:00', NULL
FROM `classes` c
JOIN `subjects` su ON su.`class_id` = c.`id` AND su.`subject_code` = 'WEB'
JOIN `users` u     ON u.`email` = 'priya.sharma@smartattendance.local'
WHERE c.`department` = 'Diploma Computer Engineering' AND c.`academic_year` = '2025-26' AND c.`division` = 'A';

-- ----------------------------------------------------------------------------
-- attendance for session 1 (manual, marked by the admin)
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `attendance` (`session_id`, `student_id`, `status`, `marked_by`, `marked_at`)
SELECT 1, s.`id`, v.`status`, u.`id`, '2026-10-20 10:05:00'
FROM `students` s
CROSS JOIN (
    SELECT '01' AS `roll_number`, 'present' AS `status` UNION ALL
    SELECT '02', 'present' UNION ALL
    SELECT '03', 'present' UNION ALL
    SELECT '04', 'absent'
) v
JOIN `users` u ON u.`email` = 'admin@smartattendance.local'
WHERE s.`roll_number` = v.`roll_number`
  AND s.`id` IN (101, 102, 103, 104);

-- ----------------------------------------------------------------------------
-- attendance for session 2 (QR, marked by the teacher, one student pending)
-- ----------------------------------------------------------------------------
INSERT IGNORE INTO `attendance` (`session_id`, `student_id`, `status`, `marked_by`, `marked_at`)
SELECT 2, s.`id`, v.`status`, u.`id`, '2026-10-21 09:50:00'
FROM `students` s
CROSS JOIN (
    SELECT '01' AS `roll_number`, 'present' AS `status` UNION ALL
    SELECT '02', 'present' UNION ALL
    SELECT '03', 'absent'  UNION ALL
    SELECT '04', 'not_marked'
) v
JOIN `users` u ON u.`email` = 'priya.sharma@smartattendance.local'
WHERE s.`roll_number` = v.`roll_number`
  AND s.`id` IN (101, 102, 103, 104);
