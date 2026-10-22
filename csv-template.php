<?php
/**
 * SMART ATTENDANCE AI - PHASE 4
 * Sample CSV template download.
 *
 * Serves a real CSV file (not a page) with the exact headers the importer
 * expects, plus three example rows that match the seeded demo classes.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/csv_import.php';

$contents = csv_template_contents();

$fileName = 'students-import-template-' . date('Ymd') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Content-Length: ' . (string) strlen($contents));
header('Cache-Control: no-store');

echo $contents;
exit;
