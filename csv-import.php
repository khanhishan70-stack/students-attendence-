<?php
/**
 * SMART ATTENDANCE AI - PHASE 4
 * Bulk student import from a CSV file.
 *
 * Three screens in one page, driven by POST actions:
 *   1. Upload form  - choose a class, download the sample, pick a file.
 *   2. Preview      - every parsed row with a status and a message.
 *   3. Results      - what was imported, reused, skipped or failed.
 *
 * The parsed and validated batch is staged in the session. Nothing is written
 * to the database until the teacher presses "Confirm import". Cancelling drops
 * the staged batch and deletes the temporary upload.
 */

declare(strict_types=1);

require __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/csv_import.php';

$databaseAvailable = db_is_available();
$classes           = [];
$ocrStatus         = null;

if ($databaseAvailable) {
    try {
        $classes = student_classes(db());
    } catch (Throwable $exception) {
        error_log('[smart-attendance] csv import classes failed: ' . $exception->getMessage());
        $databaseAvailable = false;
    }
}

$action = (string) ($_POST['action'] ?? ($_GET['action'] ?? ''));

/* ---------------------------------------------------------------------------
 * 1. Handle the upload.
 * ------------------------------------------------------------------------ */

$previewRows = null;
$previewMeta = null;
$previewCount = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $action === 'upload') {
    csrf_require();

    $classId = (int) ($_POST['class_id'] ?? 0);

    if (!$databaseAvailable) {
        flash_set('error', 'The database is not available right now. Nothing was staged.');
        header('Location: csv-import.php');
        exit;
    }

    $pdo     = db();
    $classes = student_classes($pdo);
    $class   = student_class_by_id($pdo, $classId);

    if ($class === null) {
        flash_set('error', 'Choose a class before uploading the file.');
        header('Location: csv-import.php');
        exit;
    }

    $check = upload_validate(
        $_FILES['csv_file'] ?? [],
        CSV_ALLOWED_EXT,
        CSV_ALLOWED_MIME,
        2097152
    );

    if (!$check['ok']) {
        flash_set('error', $check['error']);
        header('Location: csv-import.php');
        exit;
    }

    $parsed = csv_parse_file((string) $_FILES['csv_file']['tmp_name']);

    if (!$parsed['ok']) {
        flash_set('error', $parsed['error']);
        header('Location: csv-import.php');
        exit;
    }

    $year    = (string) $class['academic_year'];
    $summary = csv_validate_rows($parsed['rows'], $pdo, $classId, $year);

    $meta = [
        'class_id'         => $classId,
        'enrollment_year'  => $year,
        'source'           => 'csv',
        'source_label'     => 'CSV upload',
        'file_name'        => (string) $_FILES['csv_file']['name'],
        'class_label'      => $class['department'] . ' ' . $class['academic_year'] . ' · ' . $class['division'],
    ];

    import_stage($meta, $summary['rows']);

    header('Location: csv-import.php?action=preview');
    exit;
}

/* ---------------------------------------------------------------------------
 * 2. Handle the confirm / cancel of a staged batch.
 * ------------------------------------------------------------------------ */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $action === 'confirm') {
    csrf_require();

    $staged = import_staged();

    if ($staged === null) {
        flash_set('error', 'There is nothing staged to import. Upload a file first.');
        header('Location: csv-import.php');
        exit;
    }

    if (!$databaseAvailable) {
        flash_set('error', 'The database is not available right now. Nothing was imported.');
        header('Location: csv-import.php');
        exit;
    }

    $result = import_execute(db(), $staged['meta'], $staged['rows']);

    import_clear();

    $_SESSION['import_result'] = $result;

    header('Location: csv-import.php?action=results');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $action === 'cancel') {
    csrf_require();

    import_clear();

    flash_set('info', 'Import cancelled. Nothing was written to the database.');
    header('Location: csv-import.php');
    exit;
}

/* ---------------------------------------------------------------------------
 * 3. Work out which screen to show.
 * ------------------------------------------------------------------------ */

$results = $_SESSION['import_result'] ?? null;
unset($_SESSION['import_result']);

if ($action === 'preview') {
    $staged = import_staged();

    if ($staged === null) {
        flash_set('error', 'There is no staged import to preview. Upload a file first.');
        header('Location: csv-import.php');
        exit;
    }

    $previewRows  = $staged['rows'];
    $previewMeta  = $staged['meta'];
    $previewCount = [
        'valid'     => 0,
        'duplicate' => 0,
        'conflict'  => 0,
        'invalid'   => 0,
    ];

    foreach ($previewRows as $row) {
        $status = (string) ($row['status'] ?? 'invalid');

        if (isset($previewCount[$status])) {
            $previewCount[$status]++;
        }
    }
} elseif ($action === 'results') {
    if ($results === null) {
        header('Location: csv-import.php');
        exit;
    }
}

/* Class select options for the upload form. */
$classOptions = [];

foreach ($classes as $class) {
    $classOptions[] = [
        'value' => (string) $class['id'],
        'label' => $class['department'] . ' ' . $class['academic_year'] . ' · ' . $class['division'],
    ];
}

render_page_start('csv', 'CSV Import');
?>
<section class="page-head">
    <div class="page-head-copy">
        <p class="page-kicker">Phase 4 · bulk import</p>
        <h2 class="page-title">Import students from CSV</h2>
        <p class="page-sub">
            Upload a CSV of roll numbers and names. Every row is validated
            before anything is written, and the whole batch commits in one
            transaction so a partial import never happens.
        </p>
    </div>

    <div class="page-head-actions">
        <a class="btn btn-ghost" href="csv-template.php">Download sample CSV</a>
    </div>
</section>

<?php if (!$databaseAvailable): ?>
    <div class="notice notice-danger" role="status">
        <strong>The database is not reachable right now.</strong>
        <p>Start MySQL from the XAMPP Control Panel and reload this page.</p>
    </div>
<?php endif; ?>

<?php if ($action === 'results' && $results !== null): ?>
    <section class="panel" aria-labelledby="results-title">
        <div class="panel-head">
            <div>
                <h3 id="results-title">Import results</h3>
                <p class="panel-sub"><?= e((string) $results['message']) ?></p>
            </div>
            <a class="btn btn-primary" href="csv-import.php">Import another file</a>
        </div>

        <?php if (!empty($results['summary'])): ?>
            <div class="stat-grid stat-grid-compact">
                <article class="stat-card">
                    <div class="stat-body">
                        <p class="stat-label">New students</p>
                        <p class="stat-value count-green"><?= (int) $results['summary']['imported'] ?></p>
                    </div>
                </article>
                <article class="stat-card">
                    <div class="stat-body">
                        <p class="stat-label">Reused profiles</p>
                        <p class="stat-value"><?= (int) $results['summary']['reused'] ?></p>
                    </div>
                </article>
                <article class="stat-card">
                    <div class="stat-body">
                        <p class="stat-label">Skipped</p>
                        <p class="stat-value count-muted"><?= (int) $results['summary']['skipped'] ?></p>
                    </div>
                </article>
                <article class="stat-card">
                    <div class="stat-body">
                        <p class="stat-label">Failed</p>
                        <p class="stat-value count-red"><?= (int) $results['summary']['failed'] ?></p>
                    </div>
                </article>
            </div>
        <?php endif; ?>

        <?php if (!empty($results['results'])): ?>
            <div class="table-wrap">
                <table class="data-table" data-responsive>
                    <caption class="sr-only">Per-row import results</caption>
                    <thead>
                        <tr>
                            <th scope="col">Roll No.</th>
                            <th scope="col">Student</th>
                            <th scope="col">Outcome</th>
                            <th scope="col">Message</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results['results'] as $row): ?>
                            <?php
                            $outcome = (string) $row['outcome'];
                            $badge   = match ($outcome) {
                                'imported' => '<span class="badge badge-green">Imported</span>',
                                'reused'   => '<span class="badge badge-blue">Reused</span>',
                                'skipped'  => '<span class="badge badge-grey">Skipped</span>',
                                'failed'   => '<span class="badge badge-red">Failed</span>',
                                default    => '<span class="badge badge-grey">' . e(ucfirst($outcome)) . '</span>',
                            };
                            ?>
                            <tr>
                                <td data-label="Roll No."><span class="cell-strong"><?= e((string) $row['roll']) ?></span></td>
                                <td data-label="Student"><?= e((string) $row['name']) ?></td>
                                <td data-label="Outcome"><?= $badge ?></td>
                                <td data-label="Message"><span class="cell-sub"><?= e((string) $row['message']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="panel-foot">
            <a class="btn btn-ghost" href="students.php">View the student list</a>
        </div>
    </section>

<?php elseif ($previewRows !== null): ?>
    <section class="panel" aria-labelledby="preview-title">
        <div class="panel-head">
            <div>
                <h3 id="preview-title">Preview</h3>
                <p class="panel-sub">
                    <?= e((string) $previewMeta['class_label']) ?>
                    · <?= (int) count($previewRows) ?> row(s) read
                    · from <?= e((string) $previewMeta['file_name']) ?>
                </p>
            </div>
        </div>

        <div class="stat-grid stat-grid-compact">
            <article class="stat-card">
                <div class="stat-body">
                    <p class="stat-label">Ready to import</p>
                    <p class="stat-value count-green"><?= (int) $previewCount['valid'] ?></p>
                </div>
            </article>
            <article class="stat-card">
                <div class="stat-body">
                    <p class="stat-label">Already enrolled</p>
                    <p class="stat-value count-muted"><?= (int) $previewCount['duplicate'] ?></p>
                </div>
            </article>
            <article class="stat-card">
                <div class="stat-body">
                    <p class="stat-label">Roll conflicts</p>
                    <p class="stat-value" style="color: var(--amber)"><?= (int) $previewCount['conflict'] ?></p>
                </div>
            </article>
            <article class="stat-card">
                <div class="stat-body">
                    <p class="stat-label">Invalid rows</p>
                    <p class="stat-value count-red"><?= (int) $previewCount['invalid'] ?></p>
                </div>
            </article>
        </div>

        <div class="table-wrap">
            <table class="data-table" data-responsive>
                <caption class="sr-only">Validated rows ready for import</caption>
                <thead>
                    <tr>
                        <th scope="col">Line</th>
                        <th scope="col">Roll No.</th>
                        <th scope="col">Student</th>
                        <th scope="col">Email</th>
                        <th scope="col">Status</th>
                        <th scope="col">Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($previewRows as $row): ?>
                        <tr>
                            <td data-label="Line"><?= (int) ($row['_line'] ?? 0) ?></td>
                            <td data-label="Roll No."><span class="cell-strong"><?= e((string) ($row['roll_number'] ?? '')) ?></span></td>
                            <td data-label="Student"><?= e((string) ($row['full_name'] ?? '')) ?></td>
                            <td data-label="Email"><?= e((string) ($row['email'] ?? '')) ?></td>
                            <td data-label="Status"><?= render_row_status((string) ($row['status'] ?? 'invalid')) ?></td>
                            <td data-label="Message"><span class="cell-sub"><?= e((string) ($row['message'] ?? '')) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="panel-foot panel-actions">
            <form method="post" action="csv-import.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="confirm">
                <?php if ($previewCount['valid'] > 0): ?>
                    <button class="btn btn-primary" type="submit">
                        Confirm import (<?= (int) $previewCount['valid'] ?> row(s))
                    </button>
                <?php else: ?>
                    <button class="btn btn-primary" type="submit" disabled>
                        Nothing to import
                    </button>
                <?php endif; ?>
            </form>

            <form method="post" action="csv-import.php">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel">
                <button class="btn btn-ghost" type="submit">Cancel</button>
            </form>
        </div>
    </section>

<?php else: ?>
    <section class="panel" aria-labelledby="upload-title">
        <div class="panel-head">
            <div>
                <h3 id="upload-title">Upload a CSV file</h3>
                <p class="panel-sub">
                    Columns required: <code>roll_number</code>, <code>full_name</code>,
                    <code>email</code> (optional). The class is chosen below.
                </p>
            </div>
        </div>

        <div class="panel-body">
            <form class="form-grid" method="post" action="csv-import.php" enctype="multipart/form-data" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="upload">

                <?php if (!$databaseAvailable): ?>
                    <div class="notice notice-danger" role="status">
                        <strong>Import is unavailable.</strong>
                        <p>Start MySQL from the XAMPP Control Panel and reload this page.</p>
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label class="field-label" for="class_id">Target class <span class="field-req" aria-hidden="true">*</span></label>
                    <select class="field-control" id="class_id" name="class_id" required
                            data-class-select data-year-target="#enrollment_year_display">
                        <option value="0">Choose a class…</option>
                        <?php foreach ($classOptions as $option): ?>
                            <?php
                            $class = null;
                            foreach ($classes as $candidate) {
                                if ((string) $candidate['id'] === $option['value']) {
                                    $class = $candidate;
                                    break;
                                }
                            }
                            ?>
                            <option value="<?= e($option['value']) ?>"
                                data-year="<?= e((string) ($class['academic_year'] ?? '')) ?>">
                                <?= e($option['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field-hint">
                        Academic year: <strong id="enrollment_year_display">chosen with the class</strong>.
                        Every row in the file is enrolled into this class for that year.
                    </p>
                </div>

                <div class="field">
                    <label class="field-label" for="csv_file">CSV file <span class="field-req" aria-hidden="true">*</span></label>
                    <input class="field-control field-file" type="file" id="csv_file" name="csv_file"
                           accept=".csv,text/csv" required>
                    <p class="field-hint">
                        Up to 2000 rows, 2 MB. A UTF-8 or plain ASCII file works.
                        The first row must be the header.
                    </p>
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">Validate file</button>
                    <a class="btn btn-ghost" href="csv-template.php">Download sample CSV</a>
                    <a class="btn btn-ghost" href="students.php">Back to students</a>
                </div>
            </form>

            <div class="notice" role="note">
                <strong>Nothing is written until you confirm.</strong>
                <p>
                    The file is parsed and every row is checked against the database
                    first. You will see a full preview with a status for each row, and
                    can cancel the whole import at that point.
                </p>
            </div>
        </div>
    </section>
<?php endif; ?>
<?php
render_page_end();
