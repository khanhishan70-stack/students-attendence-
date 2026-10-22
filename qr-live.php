<?php
/**
 * SMART ATTENDANCE AI - PHASE 6
 * Live attendance polling endpoint for the teacher QR session view.
 * Returns JSON with current attendance rows and summary counts.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/qr.php';
require_once __DIR__ . '/includes/auth.php';

require_login();
csrf_require(); // CSRF on POST is enforced; this is GET but we require auth

$sessionId = (int) ($_GET['session'] ?? 0);

if ($sessionId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Missing session id']);
    exit;
}

if (!db_is_available()) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Database unavailable']);
    exit;
}

$authUser = auth_user();
$session = qr_session_find($sessionId);

if ($session === null) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Session not found']);
    exit;
}

// Teacher can only view their own sessions (admin can view all)
if ((int) $session['created_by'] !== (int) $authUser['id'] && ($authUser['role'] ?? '') !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Not authorized to view this session']);
    exit;
}

$live = qr_session_live_attendance($sessionId);
$summary = qr_session_summary($sessionId);

// Current token expiry for the countdown
$tokenExpiresAt = $session['token_expires_at'] ?? null;
$tokenTtl = (int) ($session['seconds_per_student'] ?? QR_DEFAULT_TOKEN_TTL);

header('Content-Type: application/json');
echo json_encode([
    'ok'             => true,
    'session'        => [
        'id'              => (int) $session['id'],
        'status'          => (string) $session['status'],
        'token_expires'   => $tokenExpiresAt,
        'token_ttl'       => $tokenTtl,
    ],
    'summary'        => $summary,
    'students'       => array_map(function ($r) {
        return [
            'student_id'  => $r['student_id'],
            'roll_number' => $r['roll_number'],
            'full_name'   => $r['full_name'],
            'status'      => $r['status'],
            'marked_at'   => $r['marked_at'],
            'source'      => $r['source'],
            'is_present'  => $r['is_present'],
        ];
    }, $live),
    'server_time'    => date('c'),
]);