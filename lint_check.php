<?php
$base = "C:/xampp/htdocs/smart-attendance";
$files = [
  "includes/auth.php","login.php","logout.php","includes/attendance.php",
  "manual-attendance.php","includes/layout.php","includes/security.php",
  "includes/students.php","includes/csv_import.php","includes/ocr.php",
  "includes/data.php","dashboard.php","students.php","csv-import.php",
  "csv-template.php","ocr-import.php","index.php","config/helpers.php",
  "config/db.php","config/ocr.php",
  "includes/qr.php","qr-attendance.php","qr-checkin.php","qr-live.php","qr-token.php",
  "database/seed_qr_students.php"
];
$php = "C:\\xampp\\php\\php.exe"; $fail = 0;
foreach ($files as $f) {
    $p = $base . "/" . $f;
    if (!is_file($p)) { echo "MISSING $f\n"; $fail++; continue; }
    $cmd = "\"$php\" -l \"$p\" 2>&1";
    exec($cmd, $out, $code);
    if ($code !== 0) { echo "FAIL $f\n" . implode("\n", $out) . "\n"; $fail++; }
    else { echo "OK   $f\n"; }
    $out = [];
}
echo "\nfailures: $fail / " . count($files) . "\n";