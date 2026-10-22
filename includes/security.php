<?php
/**
 * SMART ATTENDANCE AI - PHASE 4
 *
 * Security helpers: session handling, CSRF protection, flash messages and
 * upload validation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/helpers.php';

function app_session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'path'     => '/',
        ]);

        session_start();
    }
}

app_session_start();

/* ---------------------------------------------------------------------------
 * CSRF protection
 * ------------------------------------------------------------------------ */

function csrf_token(): string
{
    app_session_start();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_is_valid(): bool
{
    app_session_start();

    $posted = $_POST['csrf_token'] ?? '';

    if (!is_string($posted) || $posted === '') {
        return false;
    }

    $stored = $_SESSION['csrf_token'] ?? '';

    return is_string($stored) && $stored !== '' && hash_equals($stored, $posted);
}

function csrf_require(): void
{
    if (csrf_is_valid()) {
        return;
    }

    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Your session expired or this form was not submitted from this page. Go back, reload the page and try again.');
}

/* ---------------------------------------------------------------------------
 * Flash messages
 * ------------------------------------------------------------------------ */

function flash_set(string $type, string $message): void
{
    app_session_start();

    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_take(): array
{
    app_session_start();

    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return is_array($messages) ? $messages : [];
}

/* ---------------------------------------------------------------------------
 * Upload validation
 * ------------------------------------------------------------------------ */

/**
 * Validates an uploaded file without trusting the name or the browser type.
 *
 * @param array  $file         Entry from $_FILES
 * @param array  $allowedExt   Lower case extensions that are accepted
 * @param array  $allowedMime  MIME types detected from the file content
 * @param int    $maxBytes     Maximum accepted size in bytes
 */
function upload_validate(array $file, array $allowedExt, array $allowedMime, int $maxBytes): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'error' => 'The upload could not be read.'];
    }

    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'error' => 'The uploaded file is larger than the server allows.'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'The file could not be uploaded. Please try again.'];
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'The upload could not be verified.'];
    }

    if ($file['size'] <= 0) {
        return ['ok' => false, 'error' => 'The uploaded file is empty.'];
    }

    if ($file['size'] > $maxBytes) {
        return ['ok' => false, 'error' => 'The file is larger than the allowed limit of ' . upload_max_label($maxBytes) . '.'];
    }

    $extension = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));

    if (!in_array($extension, $allowedExt, true)) {
        return ['ok' => false, 'error' => 'Unsupported file type. Allowed types: ' . implode(', ', $allowedExt) . '.'];
    }

    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $realMime = (string) $finfo->file($file['tmp_name']);

    if (!in_array($realMime, $allowedMime, true)) {
        return ['ok' => false, 'error' => 'The file content does not match an allowed file type.'];
    }

    return ['ok' => true, 'error' => '', 'extension' => $extension, 'mime' => $realMime];
}

function upload_max_label(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }

    return round($bytes / 1024) . ' KB';
}

/**
 * Creates a safe, random file name. The original name is never used on disk.
 */
function upload_safe_name(string $extension): string
{
    return bin2hex(random_bytes(12)) . '.' . $extension;
}
