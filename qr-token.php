<?php
/**
 * SMART ATTENDANCE AI - PHASE 6
 * QR Token endpoint — generates a fresh SVG QR code for the teacher view.
 *
 * Called by qr-attendance.js via AJAX. Returns JSON with:
 *   - svg: the QR code as inline SVG
 *   - token: the RAW token (only used to build the checkin URL if needed)
 *   - expires_at: ISO datetime string
 *
 * The raw token is NOT stored in the database; only its SHA-256 hash is.
 * This endpoint issues a new token (revoking the previous one) on every call.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/qr.php';
require_once __DIR__ . '/includes/auth.php';

require_login();
// CSRF not required for this GET endpoint — it is called via fetch from the
// teacher's own browser session and only rotates the token. The session cookie
// provides same-origin protection.

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

// Only the creator (or admin) can fetch QR tokens for this session
if ((int) $session['created_by'] !== (int) $authUser['id'] && ($authUser['role'] ?? '') !== 'admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Not authorized']);
    exit;
}

if ((string) $session['status'] !== 'open') {
    http_response_code(409);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Session is not open']);
    exit;
}

if ((string) $session['attendance_mode'] !== 'qr') {
    http_response_code(409);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Not a QR session']);
    exit;
}

// Issue a new token (revokes previous)
$ttl = (int) ($session['seconds_per_student'] ?? QR_DEFAULT_TOKEN_TTL);
$tokenResult = qr_issue_token(db(), $sessionId, (int) $authUser['id'], $ttl);

if (!$tokenResult['ok']) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $tokenResult['message']]);
    exit;
}

// Generate the SVG QR code using BaconQRCode
require_once __DIR__ . '/vendor/autoload.php';
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

$checkinUrl = 'qr-checkin.php?session=' . $sessionId . '&token=' . $tokenResult['token'];

$backend = new SvgImageBackEnd();
$renderer = new ImageRenderer(new RendererStyle(220), $backend);
$writer = new Writer($renderer);
$svg = $writer->writeString($checkinUrl);

header('Content-Type: application/json');
echo json_encode([
    'ok'         => true,
    'svg'        => $svg,
    'token'      => $tokenResult['token'],
    'expires_at' => $tokenResult['expires_at'],
]);