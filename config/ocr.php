<?php
/**
 * SMART ATTENDANCE AI - PHASE 4
 *
 * Local, non-secret OCR configuration.
 *
 * The Tesseract binary used here was installed outside the web root so that no
 * part of the engine is reachable from a browser. Override any of these values
 * in config/ocr.local.php (git-ignored) or through the environment, which is
 * how the same project moves between machines without editing tracked files.
 */

declare(strict_types=1);

$defaults = [
    // Absolute path to the tesseract executable.
    'binary' => 'C:\tesseract\Library\bin\tesseract.exe',

    // Folder that holds the language models (eng.traineddata, ...).
    'tessdata' => 'C:\tesseract\share\tessdata',

    // Language passed to -l. English is the installed model.
    'language' => 'eng',

    // Page segmentation mode. 6 = one uniform block of text (a printed list).
    'psm' => '6',

    // Seconds the process may run before it is killed.
    'timeout' => 30,

    // Largest accepted upload, in bytes.
    'max_bytes' => 4194304,

    // File types accepted for OCR.
    'allowed_ext' => ['png', 'jpg', 'jpeg', 'bmp', 'tif', 'tiff', 'webp', 'gif'],

    // MIME types detected from the file content, never from the name.
    'allowed_mime' => [
        'image/png',
        'image/jpeg',
        'image/bmp',
        'image/tiff',
        'image/webp',
        'image/gif',
    ],
];

$overrides = [];

$localFile = __DIR__ . DIRECTORY_SEPARATOR . 'ocr.local.php';

if (is_file($localFile)) {
    $local = require $localFile;

    if (is_array($local)) {
        $overrides = $local;
    }
}

$envMap = [
    'TESSERACT_BINARY' => 'binary',
    'TESSDATA_PREFIX'  => 'tessdata',
    'TESSERACT_LANG'   => 'language',
];

foreach ($envMap as $envKey => $configKey) {
    $value = getenv($envKey);

    if (is_string($value) && $value !== '') {
        $overrides[$configKey] = $value;
    }
}

return array_merge($defaults, $overrides);
