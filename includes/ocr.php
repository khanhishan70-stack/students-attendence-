<?php
/**
 * SMART ATTENDANCE AI - PHASE 4
 *
 * OCR adapter: turns a scanned or photographed student list into candidate
 * records.
 *
 * This calls the Tesseract binary that is installed outside the web root. It
 * is real OCR: the engine reads the image and returns the text it recognises.
 * Nothing is simulated, and no cloud or paid API is used. The extracted text
 * is then parsed with conservative heuristics and every candidate is shown to
 * the teacher for review before anything is written to the database.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/helpers.php';

/**
 * Loads the OCR configuration (config/ocr.php with local overrides).
 */
function ocr_config(): array
{
    static $config = null;

    if ($config === null) {
        $loaded = require __DIR__ . '/../config/ocr.php';

        $config = is_array($loaded) ? $loaded : [];
    }

    return $config;
}

/**
 * Reports whether the OCR engine can run on this machine.
 *
 * @return array{available: bool, binary: string, message: string, detail: string}
 */
function ocr_engine_status(): array
{
    $config = ocr_config();
    $binary = (string) ($config['binary'] ?? '');

    if ($binary === '') {
        return [
            'available' => false,
            'binary'    => '',
            'message'   => 'OCR is not configured on this machine.',
            'detail'    => 'Set the tesseract path in config/ocr.php or config/ocr.local.php.',
        ];
    }

    if (!is_file($binary)) {
        return [
            'available' => false,
            'binary'    => $binary,
            'message'   => 'The OCR engine is not installed on this machine.',
            'detail'    => 'Expected the Tesseract binary at ' . $binary
                . '. Install Tesseract or point config/ocr.php at the correct path.',
        ];
    }

    if (!is_executable($binary)) {
        return [
            'available' => false,
            'binary'    => $binary,
            'message'    => 'The OCR engine is not executable on this machine.',
            'detail'    => 'Check the file permissions for ' . $binary . '.',
        ];
    }

    $version = ocr_run_version($binary);

    return [
        'available' => $version !== null,
        'binary'    => $binary,
        'message'   => $version !== null
            ? 'OCR engine ready (' . $version . ').'
            : 'The OCR engine could not be started.',
        'detail'    => $version !== null
            ? 'Language models: ' . (string) ($config['tessdata'] ?? '(default)')
            : 'Tesseract failed to start. Check the install path and the language models.',
    ];
}

/**
 * Runs `tesseract --version` and returns the short version string.
 */
function ocr_run_version(string $binary): ?string
{
    $result = ocr_exec($binary, '--version', [], 10);

    if (!$result['ok']) {
        return null;
    }

    if (preg_match('/tesseract\s+([0-9][0-9.]*)/', $result['output'], $matches) === 1) {
        return 'Tesseract ' . $matches[1];
    }

    return 'Tesseract';
}

/**
 * Runs the OCR engine over one image and returns the recognised text.
 *
 * @return array{ok: bool, text: string, error: string}
 */
function ocr_extract_text(string $imagePath): array
{
    $config = ocr_config();
    $binary = (string) ($config['binary'] ?? '');

    if ($binary === '' || !is_file($binary)) {
        return ['ok' => false, 'text' => '', 'error' => 'The OCR engine is not available on this machine.'];
    }

    $language = (string) ($config['language'] ?? 'eng');
    $psm      = (int) ($config['psm'] ?? 6);
    $timeout  = (int) ($config['timeout'] ?? 30);

    $tessdata = (string) ($config['tessdata'] ?? '');

    $env = [];

    if ($tessdata !== '' && is_dir($tessdata)) {
        $env['TESSDATA_PREFIX'] = $tessdata;
    }

    $arguments = escapeshellarg($imagePath)
        . ' stdout'
        . ' -l ' . escapeshellarg($language)
        . ' --psm ' . escapeshellarg((string) $psm);

    $result = ocr_exec($binary, $arguments, $env, $timeout);

    if (!$result['ok']) {
        return ['ok' => false, 'text' => '', 'error' => $result['error']];
    }

    $text = trim($result['output']);

    if ($text === '') {
        return [
            'ok'    => false,
            'text'  => '',
            'error' => 'The engine could not read any text from that image. '
                . 'Try a sharper, well-lit photo with the list laid flat.',
        ];
    }

    return ['ok' => true, 'text' => $text, 'error' => ''];
}

/**
 * Runs the Tesseract binary with a hard timeout.
 *
 * Uses proc_open so the output is captured, and a manual wait loop so a hung
 * process is killed instead of blocking the request forever.
 *
 * @return array{ok: bool, output: string, error: string}
 */
function ocr_exec(string $binary, string $arguments, array $env, int $timeout): array
{
    $command = escapeshellarg($binary) . ' ' . $arguments;

    $fullEnv = getenv();

    foreach ($env as $key => $value) {
        $fullEnv[$key] = $value;
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = @proc_open($command, $descriptors, $pipes, null, $fullEnv);

    if (!is_resource($process)) {
        return ['ok' => false, 'output' => '', 'error' => 'The OCR process could not be started.'];
    }

    fclose($pipes[0]);

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $start  = time();

    while (true) {
        $status = proc_get_status($process);

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);

        if (!$status['running']) {
            break;
        }

        if (time() - $start >= $timeout) {
            proc_terminate($process, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            return [
                'ok'     => false,
                'output' => '',
                'error'  => 'OCR took longer than ' . $timeout . ' seconds and was stopped. '
                    . 'Use a smaller or simpler image.',
            ];
        }

        usleep(100000);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        $detail = trim($stderr);

        if ($detail === '') {
            $detail = 'Tesseract exited with code ' . $exitCode . '.';
        }

        return ['ok' => false, 'output' => '', 'error' => 'OCR failed: ' . $detail];
    }

    return ['ok' => true, 'output' => $stdout, 'error' => ''];
}

/**
 * Turns the recognised text into candidate student records.
 *
 * The parser is deliberately conservative: a line only becomes a candidate when
 * it has a plausible roll number and a plausible name. Anything ambiguous is
 * still returned but flagged as `uncertain` so the teacher can fix it in the
 * review table. Header lines, page furniture and blank lines are dropped.
 *
 * @return array{rows: array, skipped: array}
 */
function ocr_parse_records(string $text): array
{
    $lines = preg_split('/\R/u', $text);

    $rows    = [];
    $skipped = [];
    $order   = 0;

    foreach ($lines as $index => $rawLine) {
        $lineNumber = $index + 1;
        $line       = trim((string) $rawLine);

        if ($line === '') {
            continue;
        }

        if (ocr_is_header_line($line)) {
            $skipped[] = ['line' => $lineNumber, 'text' => $line, 'reason' => 'Header row'];
            continue;
        }

        $parsed = ocr_parse_line($line);

        if ($parsed === null) {
            $skipped[] = ['line' => $lineNumber, 'text' => $line, 'reason' => 'No roll number and name pair found'];
            continue;
        }

        $order++;

        $rows[] = [
            '_line'        => $lineNumber,
            '_source'      => 'ocr',
            '_order'       => $order,
            'roll_number'  => $parsed['roll'],
            'full_name'    => $parsed['name'],
            'email'        => $parsed['email'],
            'status'       => 'review',
            'message'      => $parsed['note'],
            'uncertain'    => $parsed['uncertain'],
        ];
    }

    return ['rows' => $rows, 'skipped' => $skipped];
}

/**
 * Header lines to ignore: they name the columns rather than a student.
 */
function ocr_is_header_line(string $line): bool
{
    $lower = mb_strtolower($line);

    $headerWords = [
        'roll no', 'roll no.', 'roll number', 'roll',
        'student name', 'name of student', 'name',
        'sr no', 'sr no.', 's. no', 's.no', 'serial no',
        'admission no', 'enrolment no', 'enrollment no',
        'attendance', 'class', 'division', 'section',
        'page', 'total', 'date', 'signature',
    ];

    $stripped = preg_replace('/[^a-z .]/', '', $lower);
    $stripped = trim((string) $stripped);

    foreach ($headerWords as $word) {
        if ($stripped === $word) {
            return true;
        }
    }

    // "Roll No. Student Name" as a combined header.
    if (str_contains($lower, 'roll') && (str_contains($lower, 'name') || str_contains($lower, 'student'))) {
        // Only treat as a header when there is no obvious number-led student row.
        if (preg_match('/\d/', $line) === 0) {
            return true;
        }
    }

    return false;
}

/**
 * Parses one line into a roll number and a name.
 *
 * Accepted shapes:
 *   01  Aarav Sharma
 *   CS01 Aarav Sharma
 *   01 - Aarav Sharma
 *   01. Aarav Sharma
 *   Aarav Sharma 01              (roll at the end)
 *   01 Aarav Sharma aarav@x.com  (optional trailing email)
 *
 * @return array{roll: string, name: string, email: string, note: string, uncertain: bool}|null
 */
function ocr_parse_line(string $line): ?array
{
    $clean = trim((string) preg_replace('/[\t|]+/', '  ', $line));
    $clean = trim((string) preg_replace('/\s{2,}/', '  ', $clean));

    $email = '';

    if (preg_match('/\b([A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})\b/', $clean, $emailMatch) === 1) {
        $email = (string) $emailMatch[1];
        $clean = trim((string) preg_replace('/\b' . preg_quote($email, '/') . '\b/', '', $clean));
    }

    $tokens = preg_split('/\s+/', trim($clean));

    if ($tokens === false || count($tokens) < 2) {
        return null;
    }

    // Shape 1: roll number first.
    $lead = (string) $tokens[0];

    $lead = trim($lead, ' .-');

    if (ocr_looks_like_roll($lead) && count($tokens) >= 2) {
        $name     = trim(implode(' ', array_slice($tokens, 1)));
        $uncertain = false;
        $note     = 'Detected from the start of the line.';

        if (preg_match('/\s{2,}/', $clean) === 0 && count($tokens) === 2) {
            $uncertain = true;
            $note      = 'Only one space separates the roll number and the name - please check.';
        }

        if (count($tokens) === 2 && preg_match('/\s/u', $name) === 0) {
            $uncertain = true;
            $note      = 'The name looks like a single word - please check it is complete.';
        }

        if (!preg_match('/^[\p{L}][\p{L}\' .-]*$/u', $name)) {
            $uncertain = true;
            $note      = 'The name contains unusual characters - please check it.';
        }

        return [
            'roll'      => $lead,
            'name'      => $name,
            'email'     => $email,
            'note'      => $note,
            'uncertain' => $uncertain,
        ];
    }

    // Shape 2: roll number at the end.
    $tail = trim((string) array_pop($tokens), ' .-');

    if (ocr_looks_like_roll($tail) && count($tokens) >= 1) {
        $name = trim(implode(' ', $tokens));

        if ($name !== '') {
            return [
                'roll'      => $tail,
                'name'      => $name,
                'email'     => $email,
                'note'      => 'Roll number detected at the end of the line - please check.',
                'uncertain' => true,
            ];
        }
    }

    return null;
}

/**
 * A roll number is short and contains at least one digit.
 * This keeps stray words such as "Attendance" out of the roll column.
 */
function ocr_looks_like_roll(string $token): bool
{
    $token = trim($token);

    if ($token === '' || mb_strlen($token) > 30) {
        return false;
    }

    if (preg_match('/^[\p{L}\p{N}][\p{L}\p{N} .\/\-]*$/u', $token) !== 1) {
        return false;
    }

    return preg_match('/\d/', $token) === 1;
}
