<?php
/**
 * SMART ATTENDANCE AI - PHASE 4
 * Student import from a scanned or photographed list, using real OCR.
 *
 * The page calls the Tesseract engine installed outside the web root, shows
 * the recognised text, and lets the teacher correct every row before anything
 * is written. The confirmed rows are staged and handed to the same validated
 * import path the CSV upload uses, so the two imports share one transaction
 * and one set of rules.
 */

declare(strict_types=1);

require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/ocr.php';
require_once __DIR__ . '/includes/csv_import.php';

$databaseAvailable = db_is_available();
$classes           = [];

if ($databaseAvailable) {
    try {
        $classes = student_classes(db());
    } catch (Throwable $exception) {
        error_log('[smart-attendance] ocr import classes failed: ' . $exception->getMessage());
        $databaseAvailable = false;
    }
}

$ocr = ocr_engine_status();

/* Temporary folder for uploaded images, outside the web root. */
$uploadRoot = 'C:\\xampp\\tmp\\smart-attendance-ocr';

if (!is_dir($uploadRoot)) {
    @mkdir($uploadRoot, 0755, true);
}

$action = (string) ($_POST['action'] ?? ($_GET['action'] ?? ''));

/* ---------------------------------------------------------------------------
 * 1. Extract: run OCR over the uploaded image and show a review table.
 * ------------------------------------------------------------------------ */

$reviewRows = null;
$rawText    = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $action === 'extract') {
    csrf_require();

    if (!$ocr['available']) {
        flash_set('error', 'The OCR engine is not available on this machine. ' . $ocr['message']);
        header('Location: ocr-import.php');
        exit;
    }

    $config = ocr_config();

    $check = upload_validate(
        $_FILES['ocr_image'] ?? [],
        (array) ($config['allowed_ext'] ?? []),
        (array) ($config['allowed_mime'] ?? []),
        (int) ($config['max_bytes'] ?? 4194304)
    );

    if (!$check['ok']) {
        flash_set('error', $check['error']);
        header('Location: ocr-import.php');
        exit;
    }

    $classId = (int) ($_POST['class_id'] ?? 0);

    if ($databaseAvailable) {
        $pdo = db();
        $class = student_class_by_id($pdo, $classId);

        if ($class === null) {
            flash_set('error', 'Choose a class before uploading the image.');
            header('Location: ocr-import.php');
            exit;
        }
    } else {
        $class = null;
    }

    $safeName = upload_safe_name((string) $check['extension']);
    $savePath = $uploadRoot . DIRECTORY_SEPARATOR . $safeName;

    if (!move_uploaded_file((string) $_FILES['ocr_image']['tmp_name'], $savePath)) {
        flash_set('error', 'The uploaded image could not be stored. Nothing was processed.');
        header('Location: ocr-import.php');
        exit;
    }

    $extract = ocr_extract_text($savePath);

    @unlink($savePath);

    if (!$extract['ok']) {
        flash_set('error', $extract['error']);
        header('Location: ocr-import.php');
        exit;
    }

    $parsed   = ocr_parse_records($extract['text']);
    $rawText  = $extract['text'];

    if ($parsed['rows'] === []) {
        flash_set(
            'error',
            'The engine read the image but no roll-number and name pairs were recognised. '
            . 'Check the photo is sharp and the list is printed clearly, then try again.'
        );
        header('Location: ocr-import.php');
        exit;
    }

    $_SESSION['ocr_extract'] = [
        'class_id'  => $classId,
        'rows'      => $parsed['rows'],
        'skipped'   => $parsed['skipped'],
        'raw_text'  => $extract['text'],
        'at'        => date('Y-m-d H:i:s'),
    ];

    header('Location: ocr-import.php?action=review');
    exit;
}

/* ---------------------------------------------------------------------------
 * 2. Review: the teacher edits the rows and sends them to the import preview.
 * ------------------------------------------------------------------------ */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $action === 'review') {
    csrf_require();

    $staged = $_SESSION['ocr_extract'] ?? null;

    if ($staged === null) {
        flash_set('error', 'There is no OCR result to review. Upload an image first.');
        header('Location: ocr-import.php');
        exit;
    }

    if (!$databaseAvailable) {
        flash_set('error', 'The database is not available right now. Nothing was staged.');
        header('Location: ocr-import.php');
        exit;
    }

    $classId = (int) ($staged['class_id'] ?? 0);
    $pdo     = db();
    $class   = student_class_by_id($pdo, $classId);

    if ($class === null) {
        flash_set('error', 'The class chosen earlier no longer exists. Start again.');
        header('Location: ocr-import.php');
        exit;
    }

    $edited = $_POST['row'] ?? [];

    $rows = [];

    foreach ($staged['rows'] as $index => $original) {
        $include = isset($edited['include'][$index]) && (string) $edited['include'][$index] === '1';

        $rows[] = [
            '_line'       => (int) $original['_line'],
            'roll_number' => trim((string) ($edited['roll'][$index] ?? $original['roll_number'])),
            'full_name'   => trim((string) ($edited['name'][$index] ?? $original['full_name'])),
            'email'       => trim((string) ($edited['email'][$index] ?? $original['email'])),
            'included'    => $include,
        ];
    }

    $year    = (string) $class['academic_year'];
    $include = array_values(array_filter($rows, static fn (array $row): bool => $row['included']));

    if ($include === []) {
        flash_set('error', 'Tick at least one row before sending the list to the import preview.');
        header('Location: ocr-import.php?action=review');
        exit;
    }

    $summary = csv_validate_rows($include, $pdo, $classId, $year);

    $meta = [
        'class_id'        => $classId,
        'enrollment_year' => $year,
        'source'          => 'ocr',
        'source_label'    => 'OCR image',
        'file_name'       => 'scanned list',
        'class_label'     => $class['department'] . ' ' . $class['academic_year'] . ' · ' . $class['division'],
    ];

    import_stage($meta, $summary['rows']);

    unset($_SESSION['ocr_extract']);

    header('Location: csv-import.php?action=preview');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $action === 'cancel') {
    csrf_require();

    unset($_SESSION['ocr_extract']);

    flash_set('info', 'OCR import discarded. Nothing was written.');
    header('Location: ocr-import.php');
    exit;
}

/* ---------------------------------------------------------------------------
 * 3. Which screen to show.
 * ------------------------------------------------------------------------ */

$skippedLines = [];

if ($action === 'review') {
    $extracted = $_SESSION['ocr_extract'] ?? null;

    if ($extracted === null) {
        header('Location: ocr-import.php');
        exit;
    }

    $reviewRows  = $extracted['rows'];
    $rawText     = $extracted['raw_text'];
    $skippedLines = $extracted['skipped'] ?? [];
    $classId     = (int) $extracted['class_id'];
} else {
    $extracted = null;
}

$classOptions = [];

foreach ($classes as $class) {
    $classOptions[] = [
        'value' => (string) $class['id'],
        'label' => $class['department'] . ' ' . $class['academic_year'] . ' · ' . $class['division'],
    ];
}

$selectedClass = $classId ?? 0;

render_page_start('ocr', 'OCR Import');
?>
<section class="page-head">
    <div class="page-head-copy">
        <p class="page-kicker">Phase 4 · scanned list import</p>
        <h2 class="page-title">Import students from an image</h2>
        <p class="page-sub">
            Upload a photo or scan of a printed student list. Real OCR reads the
            text, every recognised row is shown for correction, and nothing is
            written until you confirm.
        </p>
    </div>
</section>

<?php if (!$ocr['available']): ?>
    <div class="notice notice-danger" role="status">
        <strong><?= e($ocr['message']) ?></strong>
        <p><?= e($ocr['detail']) ?></p>
        <p>
            Once Tesseract is installed, set the binary path in
            <code>config/ocr.php</code> (or <code>config/ocr.local.php</code>) and
            reload this page. The CSV import path works without OCR.
        </p>
    </div>
<?php else: ?>
    <p class="db-chip db-chip-ok">
        <span class="dot dot-green" aria-hidden="true"></span><?= e($ocr['message']) ?>
    </p>
<?php endif; ?>

<?php if (!$databaseAvailable): ?>
    <div class="notice notice-danger" role="status">
        <strong>The database is not reachable right now.</strong>
        <p>Start MySQL from the XAMPP Control Panel and reload this page.</p>
    </div>
<?php endif; ?>

<?php if ($action === 'review' && $reviewRows !== null): ?>
    <section class="panel" aria-labelledby="review-title">
        <div class="panel-head">
            <div>
                <h3 id="review-title">Review recognised rows</h3>
                <p class="panel-sub">
                    <?= count($reviewRows) ?> row(s) recognised
                    <?php if ($skippedLines !== []): ?>
                        · <?= count($skippedLines) ?> line(s) ignored
                    <?php endif; ?>
                    · every field below can be corrected before importing
                </p>
            </div>
        </div>

        <form method="post" action="ocr-import.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="review">

            <div class="table-wrap">
                <table class="data-table" data-responsive>
                    <caption class="sr-only">Rows recognised from the image, editable before import</caption>
                    <thead>
                        <tr>
                            <th scope="col">Include</th>
                            <th scope="col">Line</th>
                            <th scope="col">Roll No.</th>
                            <th scope="col">Full name</th>
                            <th scope="col">Email</th>
                            <th scope="col">Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reviewRows as $index => $row): ?>
                            <tr>
                                <td data-label="Include">
                                    <input type="checkbox" name="row[include][<?= (int) $index ?>]" value="1" checked
                                           aria-label="Include row <?= (int) $index + 1 ?>">
                                </td>
                                <td data-label="Line"><?= (int) $row['_line'] ?></td>
                                <td data-label="Roll No.">
                                    <input class="field-control field-inline" type="text"
                                           name="row[roll][<?= (int) $index ?>]"
                                           value="<?= e((string) $row['roll_number']) ?>"
                                           maxlength="30" aria-label="Roll number for line <?= (int) $row['_line'] ?>">
                                </td>
                                <td data-label="Full name">
                                    <input class="field-control field-inline" type="text"
                                           name="row[name][<?= (int) $index ?>]"
                                           value="<?= e((string) $row['full_name']) ?>"
                                           maxlength="120" aria-label="Full name for line <?= (int) $row['_line'] ?>">
                                </td>
                                <td data-label="Email">
                                    <input class="field-control field-inline" type="text"
                                           name="row[email][<?= (int) $index ?>]"
                                           value="<?= e((string) $row['email']) ?>"
                                           maxlength="190" aria-label="Email for line <?= (int) $row['_line'] ?>">
                                </td>
                                <td data-label="Note">
                                    <?php if (!empty($row['uncertain'])): ?>
                                        <span class="badge badge-amber">Check</span>
                                    <?php else: ?>
                                        <span class="badge badge-green">Clear</span>
                                    <?php endif; ?>
                                    <span class="cell-sub"><?= e((string) $row['message']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="panel-foot panel-actions">
                <button class="btn btn-primary" type="submit">Send to import preview</button>
                <a class="btn btn-ghost" href="ocr-import.php">Start over</a>
            </div>
        </form>

        <?php if ($rawText !== null): ?>
            <details class="raw-text">
                <summary>Show the raw text the engine read</summary>
                <pre class="raw-text-body"><?= e((string) $rawText) ?></pre>
            </details>
        <?php endif; ?>
    </section>

<?php else: ?>
    <section class="panel" aria-labelledby="ocr-upload-title">
        <div class="panel-head">
            <div>
                <h3 id="ocr-upload-title">Upload a list image</h3>
                <p class="panel-sub">
                    PNG, JPG, BMP, TIFF, WEBP or GIF. Up to 4 MB. A printed list
                    with a clear roll-number column works best.
                </p>
            </div>
            <a class="btn btn-ghost" href="csv-import.php">Use CSV instead</a>
        </div>

        <div class="panel-body">
            <form class="form-grid" method="post" action="ocr-import.php" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="extract">

                <?php if (!$ocr['available']): ?>
                    <div class="notice notice-danger" role="status">
                        <strong>OCR import is unavailable.</strong>
                        <p>Install Tesseract or point config/ocr.php at the binary, then reload.</p>
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label class="field-label" for="class_id">Target class <span class="field-req" aria-hidden="true">*</span></label>
                    <select class="field-control" id="class_id" name="class_id"
                            <?= $ocr['available'] && $databaseAvailable ? 'required' : 'disabled' ?>>
                        <option value="0">Choose a class…</option>
                        <?php foreach ($classOptions as $option): ?>
                            <option value="<?= e($option['value']) ?>"
                                <?= $selectedClass === (int) $option['value'] ? 'selected' : '' ?>>
                                <?= e($option['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field-hint">
                        Every recognised student is enrolled into this class for the class academic year.
                    </p>
                </div>

                <div class="field">
                    <label class="field-label" for="ocr_image">List image <span class="field-req" aria-hidden="true">*</span></label>
                    <input class="field-control field-file" type="file" id="ocr_image" name="ocr_image"
                           accept="image/png,image/jpeg,image/bmp,image/tiff,image/webp,image/gif"
                           <?= $ocr['available'] && $databaseAvailable ? 'required' : 'disabled' ?>>
                    <p class="field-hint">
                        Take the photo straight on, with the whole list in frame and
                        even light. Blurry or angled photos produce uncertain rows.
                    </p>
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit" <?= $ocr['available'] && $databaseAvailable ? '' : 'disabled' ?>>
                        Run OCR
                    </button>
                    <a class="btn btn-ghost" href="students.php">Back to students</a>
                </div>
            </form>

            <div class="notice" role="note">
                <strong>Real OCR, reviewed by a human.</strong>
                <p>
                    The engine reads the image locally. Every recognised row is shown
                    for correction before anything is staged, and the staged rows then
                    go through the same validation and single-transaction import as a
                    CSV file. No cloud service and no paid API is involved.
                </p>
            </div>
        </div>
    </section>
<?php endif; ?>
<?php
render_page_end();
