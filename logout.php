<?php
/**
 * SMART ATTENDANCE AI - PHASE 5
 * Sign-out.
 *
 * A POST protected by CSRF so a malicious page cannot silently sign a teacher
 * out. The session is fully destroyed, not just unset.
 */

declare(strict_types=1);

require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: login.php');
    exit;
}

csrf_require();
auth_logout();

/* Start a clean session purely so the flash message can survive the redirect. */
app_session_start();
flash_set('success', 'You have been signed out.');

header('Location: login.php');
exit;
