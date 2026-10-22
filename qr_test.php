<?php
$BASE = "http://localhost/smart-attendance";
$JAR = "C:/Users/Admin/AppData/Local/Temp/opencode/qr-cookies2.txt";
$JAR2 = "C:/Users/Admin/AppData/Local/Temp/opencode/stu-cookies.txt";
@unlink($JAR);
@unlink($JAR2);

function req($url, $post = null) {
    global $BASE, $JAR;
    $ch = curl_init($BASE . "/" . ltrim($url, "/"));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $JAR,
        CURLOPT_COOKIEFILE => $JAR,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20
    ]);
    $hdrs = [];
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        $hdrs[] = "Content-Type: application/x-www-form-urlencoded";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ["code" => $code, "headers" => substr($raw, 0, $hs), "body" => substr($raw, $hs)];
}

function req2($url, $post = null) {
    global $BASE, $JAR2;
    $ch = curl_init($BASE . "/" . ltrim($url, "/"));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $JAR2,
        CURLOPT_COOKIEFILE => $JAR2,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20
    ]);
    $hdrs = [];
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        $hdrs[] = "Content-Type: application/x-www-form-urlencoded";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ["code" => $code, "headers" => substr($raw, 0, $hs), "body" => substr($raw, $hs)];
}

function csrf($b) {
    return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $b, $m) ? $m[1] : "";
}

echo "=== 1. Teacher login ===\n";
$r = req("login.php"); $lc = csrf($r["body"]);
$r = req("login.php", ["csrf_token" => $lc, "email" => "priya.sharma@smartattendance.local", "password" => "Teacher@123", "next" => ""]);
echo "code={$r["code"]}\n";

echo "=== 2. Start QR session ===\n";
$r = req("qr-attendance.php"); $c = csrf($r["body"]);
$r = req("qr-attendance.php", ["csrf_token" => $c, "action" => "start", "class_id" => "1", "subject_id" => "1", "session_date" => date("Y-m-d"), "token_ttl" => "30", "session_label" => "QR Test"]);
echo "code={$r["code"]}\n";
$sid = 0;
if ($r["code"] === 302 && preg_match("/session=(\d+)/", $r["headers"], $m)) {
    $sid = (int)$m[1]; echo "sid=$sid\n";
} else { exit(1); }

echo "=== 3. Live page ===\n";
$r = req("qr-attendance.php?session=$sid");
echo "code={$r["code"]} has_qr=" . (strpos($r["body"], "qr-svg") !== false ? "yes" : "no") . " has_counts=" . (strpos($r["body"], "stat-present") !== false ? "yes" : "no") . "\n";

echo "=== 4. QR token endpoint ===\n";
$r = req("qr-token.php?session=$sid");
echo "code={$r["code"]}\n";
$token = "";
if ($r["code"] === 200) {
    $j = json_decode($r["body"], true);
    if($j === null){
        echo "JSON DECODE FAILED. Body: ".substr($r["body"],0,500)."\n";
    }else{
        $token = $j["token"] ?? "";
        echo "ok=" . ($j["ok"] ?? false) . " has_svg=" . (!empty($j["svg"]) ? "yes" : "no") . " token_len=" . strlen($token) . " expires=" . $j["expires_at"] . "\n";
    }
} else {
    echo "HEADERS: ".str_replace("\r\n"," | ",$r["headers"])."\n";
    echo "BODY: ".substr($r["body"],0,500)."\n";
}

if ($sid && $token) {
    echo "=== 5. Checkin (no auth) ===\n";
    $r = req("qr-checkin.php?session=$sid&token=" . rawurlencode($token));
    echo "code={$r["code"]} redirect_login=" . ($r["code"] === 302 && strpos($r["headers"], "login.php") !== false ? "yes" : "no") . "\n";

    echo "=== 6. Student login ===\n";
    $r = req2("login.php"); $lc = csrf($r["body"]);
    $next = "/smart-attendance/qr-checkin.php?session=$sid&token=" . rawurlencode($token);
    $r = req2("login.php", ["csrf_token" => $lc, "email" => "aarav.sharma@example.com", "password" => "Student@123", "next" => $next]);
    echo "code={$r["code"]}\n";

    echo "=== 7. Checkin (authed) ===\n";
    $r = req2("qr-checkin.php?session=$sid&token=" . rawurlencode($token));
    echo "code={$r["code"]} success=" . ($r["code"] === 200 && strpos($r["body"], "successful") !== false ? "yes" : "no") . " already=" . ($r["code"] === 200 && strpos($r["body"], "already") !== false ? "yes" : "no") . "\n";

    echo "=== 8. Duplicate checkin ===\n";
    $r = req2("qr-checkin.php?session=$sid&token=" . rawurlencode($token));
    echo "code={$r["code"]} already=" . ($r["code"] === 200 && strpos($r["body"], "already") !== false ? "yes" : "no") . "\n";

    echo "=== 9. Live poll ===\n";
    $r = req("qr-live.php?session=$sid");
    echo "code={$r["code"]}\n";
    if ($r["code"] === 200) {
        $j = json_decode($r["body"], true);
        echo "ok=" . ($j["ok"] ?? false) . " present=" . ($j["summary"]["present"] ?? 0) . " total=" . ($j["summary"]["total"] ?? 0) . "\n";
    }

    echo "=== 10. Refresh token ===\n";
    $r = req("qr-attendance.php", ["csrf_token" => csrf(req("qr-attendance.php")["body"]), "action" => "refresh_token", "session_id" => (string)$sid, "token_ttl" => "30"]);
    echo "code={$r["code"]}\n";

    echo "=== 11. Close session ===\n";
    $r = req("qr-attendance.php", ["csrf_token" => csrf(req("qr-attendance.php")["body"]), "action" => "close_session", "session_id" => (string)$sid]);
    echo "code={$r["code"]}\n";
}