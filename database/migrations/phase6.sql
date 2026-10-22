-- ============================================================================
-- SMART ATTENDANCE AI - PHASE 6 MIGRATION
-- Dynamic QR code attendance: student accounts, QR tokens, attendance source
--
-- Apply : mysql -u root smart_attendance < database/migrations/phase6.sql
--         (then run: php database/seed_qr_students.php  to create student logins)
--
-- This migration is idempotent and non-destructive:
--   * No DROP DATABASE / DROP TABLE / TRUNCATE is used.
--   * No existing row is deleted or altered in value.
--   * users.role only WIDENS from ('admin','teacher') to
--     ('admin','teacher','student'); every existing value stays valid.
--   * users.student_id is added NULL, so existing admin/teacher rows are
--     untouched. It links a login account to a students row - the minimum
--     secure account-to-student mapping QR identity needs.
--   * attendance.source only adds a column with a DEFAULT of 'manual', so
--     every existing attendance row is unchanged and still correct.
--   * qr_tokens and qr_rate_limits are new tables; nothing existing is
--     modified to create them.
-- ============================================================================

SET NAMES utf8mb4;

USE `smart_attendance`;

-- ----------------------------------------------------------------------------
-- 1. users.role gains 'student'.
--    A QR check-in must resolve to a real, authenticated student account. The
--    users table previously only allowed admin/teacher, so students had no way
--    to sign in. Widening the ENUM keeps every existing row valid.
-- ----------------------------------------------------------------------------
ALTER TABLE `users`
    MODIFY COLUMN `role`
        ENUM('admin','teacher','student') NOT NULL DEFAULT 'teacher';

-- ----------------------------------------------------------------------------
-- 2. users gains student_id - the secure account-to-student link.
--    A user with role='student' points at exactly one students row. This is how
--    the server, never the browser, decides WHO is checking in. NULL for admin
--    and teacher accounts. One account per student (UNIQUE) so a login can
--    never represent two people.
-- ----------------------------------------------------------------------------
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `student_id` INT UNSIGNED NULL DEFAULT NULL
        AFTER `role`;

CREATE UNIQUE INDEX IF NOT EXISTS `uq_users_student_id`
    ON `users` (`student_id`);

-- Foreign key, added only when it does not already exist (idempotent).
SET @fk_exists := (
    SELECT COUNT(*)
      FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = 'smart_attendance'
       AND TABLE_NAME   = 'users'
       AND CONSTRAINT_NAME = 'fk_users_student'
);

SET @fk_sql := IF(
    @fk_exists = 0,
    'ALTER TABLE `users` ADD CONSTRAINT `fk_users_student`
         FOREIGN KEY (`student_id`) REFERENCES `students` (`id`)
         ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1'
);

PREPARE fk_stmt FROM @fk_sql;
EXECUTE fk_stmt;
DEALLOCATE PREPARE fk_stmt;

-- ----------------------------------------------------------------------------
-- 3. qr_tokens - short-lived, hashed bearer tokens for the displayed QR code.
--    Only the SHA-256 HASH of a token is stored, never the raw token, so a
--    database leak cannot be replayed. Every token has an explicit expiry.
--    A refresh issues a new token and revokes the previous ones for the same
--    session (revoked_at), so the old QR stops working the moment it rotates.
--    Closing a session revokes all of its tokens.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `qr_tokens` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id`  INT UNSIGNED NOT NULL,
    `token_hash`  CHAR(64)     NOT NULL,
    `expires_at`  DATETIME     NOT NULL,
    `created_by`  INT UNSIGNED NULL DEFAULT NULL,
    `revoked_at`  DATETIME     NULL DEFAULT NULL,
    `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_qr_token_hash` (`token_hash`),
    KEY `idx_qr_session_active` (`session_id`, `revoked_at`, `expires_at`),
    CONSTRAINT `fk_qr_token_session`
        FOREIGN KEY (`session_id`) REFERENCES `attendance_sessions` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_qr_token_creator`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. attendance.source - records HOW a mark was captured ('manual' or 'qr').
--    Existing rows keep 'manual' through the DEFAULT, so Phase 5 history is
--    described accurately without touching a single row.
-- ----------------------------------------------------------------------------
ALTER TABLE `attendance`
    ADD COLUMN IF NOT EXISTS `source`
        ENUM('manual','qr') NOT NULL DEFAULT 'manual'
        AFTER `marked_by`;

-- ----------------------------------------------------------------------------
-- 5. qr_rate_limits - a small fixed-window counter so a student (or an attacker)
--    cannot hammer the check-in endpoint. Bucketed by an opaque key (a hash of
--    the student id or client address) plus the action and a one-minute window.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `qr_rate_limits` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bucket`       VARCHAR(64)  NOT NULL,
    `action`       VARCHAR(32)  NOT NULL,
    `window_start` INT UNSIGNED NOT NULL,
    `attempts`     INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rate_bucket` (`bucket`, `action`, `window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
