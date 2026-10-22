-- ============================================================================
-- SMART ATTENDANCE AI - PHASE 2
-- Relational schema for the Dual-Mode Digital Student Attendance Management System
--
-- Target  : MariaDB 10.4 (bundled with XAMPP 8.2.12)
-- Engine  : InnoDB (foreign keys + transactions)
-- Charset : utf8mb4 / utf8mb4_unicode_ci
--
-- Import  : mysql -u root < database/schema.sql
--           (or paste into phpMyAdmin > SQL)
--
-- The script is idempotent: re-running it will not drop, truncate or duplicate
-- any existing structure. No destructive statements are used.
-- ============================================================================

SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `smart_attendance`
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE `smart_attendance`;

-- ----------------------------------------------------------------------------
-- A. users - application users (admin / teacher)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `full_name`     VARCHAR(120)    NOT NULL,
    `email`         VARCHAR(190)    NOT NULL,
    `password_hash` VARCHAR(255)    NOT NULL,
    `role`          ENUM('admin','teacher') NOT NULL DEFAULT 'teacher',
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`),
    KEY `idx_users_role_active` (`role`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- B. classes - academic class / division
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `classes` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `department`    VARCHAR(100)    NOT NULL,
    `academic_year` VARCHAR(20)     NOT NULL,
    `division`      VARCHAR(20)     NOT NULL,
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_classes_identity` (`department`, `academic_year`, `division`),
    KEY `idx_classes_academic_year` (`academic_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- C. students - student profiles (roll numbers are strings: 01, CS01, 2A ...)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `students` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `roll_number`   VARCHAR(30)     NOT NULL,
    `full_name`     VARCHAR(120)    NOT NULL,
    `email`         VARCHAR(190)    NULL DEFAULT NULL,
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_students_roll_number` (`roll_number`),
    KEY `idx_students_full_name` (`full_name`),
    KEY `idx_students_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- D. enrollments - many-to-many between students and classes (per academic year)
--     A student may sit in different classes in different academic years.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `enrollments` (
    `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `student_id`      INT UNSIGNED  NOT NULL,
    `class_id`        INT UNSIGNED  NOT NULL,
    `enrollment_year` VARCHAR(20)   NOT NULL,
    `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_enrollments_student_class_year` (`student_id`, `class_id`, `enrollment_year`),
    KEY `idx_enrollments_class_year` (`class_id`, `enrollment_year`),
    CONSTRAINT `fk_enrollments_student`
        FOREIGN KEY (`student_id`) REFERENCES `students` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_enrollments_class`
        FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- E. subjects - subjects belonging to a class
--     UNIQUE (class_id, id) exists so that attendance_sessions can enforce
--     "subject must belong to the session class" through a composite FK.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subjects` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `class_id`      INT UNSIGNED    NOT NULL,
    `subject_code`  VARCHAR(30)     NOT NULL,
    `subject_name`  VARCHAR(120)    NOT NULL,
    `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_subjects_class_code` (`class_id`, `subject_code`),
    UNIQUE KEY `uq_subjects_class_id` (`class_id`, `id`),
    CONSTRAINT `fk_subjects_class`
        FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- F. attendance_sessions - one lecture = one session
--     No unique key on (subject_id, session_date): several lectures of the same
--     subject can happen on the same day.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance_sessions` (
    `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `class_id`        INT UNSIGNED  NOT NULL,
    `subject_id`      INT UNSIGNED  NOT NULL,
    `created_by`      INT UNSIGNED  NULL DEFAULT NULL,
    `session_date`    DATE          NOT NULL,
    `session_label`   VARCHAR(100)  NOT NULL,
    `seconds_per_student` SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    `attendance_mode` ENUM('manual','qr') NOT NULL DEFAULT 'manual',
    `status`          ENUM('scheduled','open','closed') NOT NULL DEFAULT 'scheduled',
    `started_at`      DATETIME      NULL DEFAULT NULL,
    `closed_at`       DATETIME      NULL DEFAULT NULL,
    `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sessions_class_date` (`class_id`, `session_date`),
    KEY `idx_sessions_subject_date` (`subject_id`, `session_date`),
    KEY `idx_sessions_status` (`status`),
    KEY `idx_sessions_mode` (`attendance_mode`),
    CONSTRAINT `fk_sessions_class`
        FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sessions_subject`
        FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sessions_subject_in_class`
        FOREIGN KEY (`class_id`, `subject_id`) REFERENCES `subjects` (`class_id`, `id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_sessions_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- G. attendance - one row per student per session
--     A correction is an UPDATE of the same row, never a second row.
--     'review' was added in Phase 5 so the REVIEW button stores a real status.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `attendance` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id`  INT UNSIGNED NOT NULL,
    `student_id`  INT UNSIGNED NOT NULL,
    `status`      ENUM('present','absent','review','not_marked') NOT NULL DEFAULT 'not_marked',
    `marked_by`   INT UNSIGNED NULL DEFAULT NULL,
    `marked_at`   DATETIME     NULL DEFAULT NULL,
    `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_attendance_session_student` (`session_id`, `student_id`),
    KEY `idx_attendance_student` (`student_id`),
    KEY `idx_attendance_status` (`status`),
    KEY `idx_attendance_session_status` (`session_id`, `status`),
    KEY `idx_attendance_marked_at` (`marked_at`),
    CONSTRAINT `fk_attendance_session`
        FOREIGN KEY (`session_id`) REFERENCES `attendance_sessions` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_attendance_student`
        FOREIGN KEY (`student_id`) REFERENCES `students` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_attendance_marked_by`
        FOREIGN KEY (`marked_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- TRIGGERS
-- Rules that a plain foreign key cannot express, enforced at the database
-- level so that every future application code path inherits them.
-- ============================================================================

DROP TRIGGER IF EXISTS `trg_enrollments_roll_unique`;
DROP TRIGGER IF EXISTS `trg_students_roll_unique_update`;
DROP TRIGGER IF EXISTS `trg_attendance_enrollment_check`;
DROP TRIGGER IF EXISTS `trg_attendance_enrollment_check_upd`;

DELIMITER $$

-- Rule 1: a roll number must be unique inside a class + academic year.
--         (Roll numbers repeat across different classes and years.)
--         Re-enrolling the same student is allowed - the unique key
--         (student_id, class_id, enrollment_year) already handles that case.
CREATE TRIGGER `trg_enrollments_roll_unique`
BEFORE INSERT ON `enrollments`
FOR EACH ROW
BEGIN
    DECLARE v_roll VARCHAR(30) DEFAULT NULL;
    DECLARE v_roll_taken INT DEFAULT 0;

    SELECT s.`roll_number` INTO v_roll
      FROM `students` s
     WHERE s.`id` = NEW.`student_id`;

    SELECT COUNT(*) INTO v_roll_taken
      FROM `enrollments` e
      JOIN `students` s ON s.`id` = e.`student_id`
     WHERE e.`class_id` = NEW.`class_id`
       AND e.`enrollment_year` = NEW.`enrollment_year`
       AND s.`roll_number` = v_roll
       AND e.`student_id` <> NEW.`student_id`;

    IF v_roll_taken > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Enrollment rejected: roll number already used in this class for this academic year';
    END IF;
END$$

-- Rule 1b: changing a roll number must not create a duplicate inside any
--          class context the student belongs to.
CREATE TRIGGER `trg_students_roll_unique_update`
BEFORE UPDATE ON `students`
FOR EACH ROW
BEGIN
    DECLARE v_conflicts INT DEFAULT 0;

    IF NEW.`roll_number` <> OLD.`roll_number` THEN
        SELECT COUNT(*) INTO v_conflicts
          FROM `enrollments` e
          JOIN `students` other ON other.`id` = e.`student_id`
         WHERE e.`student_id` <> NEW.`id`
           AND other.`roll_number` = NEW.`roll_number`
           AND EXISTS (
                SELECT 1
                  FROM `enrollments` mine
                 WHERE mine.`student_id` = NEW.`id`
                   AND mine.`class_id` = e.`class_id`
                   AND mine.`enrollment_year` = e.`enrollment_year`
           );

        IF v_conflicts > 0 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Student update rejected: roll number already used in another class context';
        END IF;
    END IF;
END$$

-- Rule 2: attendance can only be recorded for a student enrolled in the class
--         of the session, for the academic year of that class.
CREATE TRIGGER `trg_attendance_enrollment_check`
BEFORE INSERT ON `attendance`
FOR EACH ROW
BEGIN
    DECLARE v_class_id INT UNSIGNED DEFAULT NULL;
    DECLARE v_academic_year VARCHAR(20) DEFAULT NULL;
    DECLARE v_enrolled INT DEFAULT 0;

    SELECT s.`class_id`, c.`academic_year` INTO v_class_id, v_academic_year
      FROM `attendance_sessions` s
      JOIN `classes` c ON c.`id` = s.`class_id`
     WHERE s.`id` = NEW.`session_id`;

    IF v_class_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Attendance rejected: session does not exist';
    END IF;

    SELECT COUNT(*) INTO v_enrolled
      FROM `enrollments` e
     WHERE e.`student_id` = NEW.`student_id`
       AND e.`class_id` = v_class_id
       AND e.`enrollment_year` = v_academic_year;

    IF v_enrolled = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Attendance rejected: student is not enrolled in the session class for that academic year';
    END IF;
END$$

-- Rule 3: the same check must run when a record is corrected or re-pointed.
CREATE TRIGGER `trg_attendance_enrollment_check_upd`
BEFORE UPDATE ON `attendance`
FOR EACH ROW
BEGIN
    DECLARE v_class_id INT UNSIGNED DEFAULT NULL;
    DECLARE v_academic_year VARCHAR(20) DEFAULT NULL;
    DECLARE v_enrolled INT DEFAULT 0;

    SELECT s.`class_id`, c.`academic_year` INTO v_class_id, v_academic_year
      FROM `attendance_sessions` s
      JOIN `classes` c ON c.`id` = s.`class_id`
     WHERE s.`id` = NEW.`session_id`;

    IF v_class_id IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Attendance rejected: session does not exist';
    END IF;

    SELECT COUNT(*) INTO v_enrolled
      FROM `enrollments` e
     WHERE e.`student_id` = NEW.`student_id`
       AND e.`class_id` = v_class_id
       AND e.`enrollment_year` = v_academic_year;

    IF v_enrolled = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Attendance rejected: student is not enrolled in the session class for that academic year';
    END IF;
END$$

DELIMITER ;

-- ============================================================================
-- REPORTING VIEW - flattens session + class + subject + student for reports
-- ============================================================================
CREATE OR REPLACE VIEW `v_attendance_records` AS
SELECT
    a.`id`                AS attendance_id,
    a.`status`            AS attendance_status,
    a.`marked_at`         AS marked_at,
    a.`updated_at`        AS attendance_updated_at,
    se.`id`               AS session_id,
    se.`session_date`     AS session_date,
    se.`session_label`    AS session_label,
    se.`attendance_mode`  AS attendance_mode,
    se.`status`           AS session_status,
    c.`id`                AS class_id,
    c.`department`        AS department,
    c.`academic_year`     AS academic_year,
    c.`division`          AS division,
    su.`id`               AS subject_id,
    su.`subject_code`     AS subject_code,
    su.`subject_name`     AS subject_name,
    st.`id`               AS student_id,
    st.`roll_number`      AS roll_number,
    st.`full_name`        AS student_name
FROM `attendance` a
JOIN `attendance_sessions` se ON se.`id` = a.`session_id`
JOIN `classes` c            ON c.`id`  = se.`class_id`
JOIN `subjects` su          ON su.`id` = se.`subject_id`
JOIN `students` st          ON st.`id` = a.`student_id`;
