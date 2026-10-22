-- ============================================================================
-- SMART ATTENDANCE AI - PHASE 5 MIGRATION
-- Manual attendance: REVIEW status and per-student time limit
--
-- Apply : mysql -u root smart_attendance < database/migrations/phase5.sql
--         (or paste into phpMyAdmin > SQL, or run database/migrate_phase5.php)
--
-- This migration is idempotent and non-destructive:
--   * No DROP DATABASE / DROP TABLE / TRUNCATE is used.
--   * No existing attendance row is deleted or altered in value.
--   * Adding 'review' to the ENUM only widens the allowed set; every existing
--     value ('present','absent','not_marked') stays valid.
--   * seconds_per_student is added with a DEFAULT, so the two seeded sessions
--     automatically report 30 seconds and nothing else changes.
-- ============================================================================

SET NAMES utf8mb4;

USE `smart_attendance`;

-- ----------------------------------------------------------------------------
-- 1. attendance.status gains 'review'.
--    The REVIEW button in the manual marking screen needs a real status so the
--    choice survives a page refresh and is queryable later. Existing rows are
--    untouched; the column simply accepts one more allowed value.
-- ----------------------------------------------------------------------------

ALTER TABLE `attendance`
    MODIFY COLUMN `status`
        ENUM('present','absent','review','not_marked')
        NOT NULL DEFAULT 'not_marked';

-- ----------------------------------------------------------------------------
-- 2. attendance_sessions gains seconds_per_student.
--    The teacher chooses how many seconds each student gets (default 30). The
--    value is stored on the session so a refresh or a second browser tab always
--    shows the same limit. SMALLINT keeps the range honest (5-600 seconds is
--    validated in the application before this column is written).
-- ----------------------------------------------------------------------------

ALTER TABLE `attendance_sessions`
    ADD COLUMN IF NOT EXISTS `seconds_per_student`
        SMALLINT UNSIGNED NOT NULL DEFAULT 30
        AFTER `session_label`;
