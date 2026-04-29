<?php
// Sanitize input
function sanitize($conn, $value) {
    return $conn->real_escape_string(trim(htmlspecialchars($value)));
}

// Calculate BMI
function calculateBMI($weight, $height_cm) {
    if ($height_cm <= 0) return null;
    $height_m = $height_cm / 100;
    return round($weight / ($height_m * $height_m), 2);
}

// BMI classification
function bmiClass($bmi) {
    if ($bmi < 18.5) return ['Underweight', 'mild'];
    if ($bmi < 25)   return ['Normal', 'good'];
    if ($bmi < 30)   return ['Overweight', 'mild'];
    return ['Obese', 'critical'];
}

// Format date nicely
function niceDate($date) {
    return date('F j, Y', strtotime($date));
}

// Get initials from name
function initials($name) {
    $parts = explode(' ', trim($name));
    $init = '';
    foreach ($parts as $p) $init .= strtoupper(substr($p, 0, 1));
    return substr($init, 0, 2);
}

// Blood pressure classification
function bpClass($sys, $dia) {
    if ($sys < 120 && $dia < 80)  return ['Normal', 'good'];
    if ($sys < 130 && $dia < 80)  return ['Elevated', 'mild'];
    if ($sys < 140 || $dia < 90)  return ['High Stage 1', 'mild'];
    if ($sys >= 140 || $dia >= 90) return ['High Stage 2', 'critical'];
    return ['Unknown', ''];
}

// Oxygen saturation classification
function spo2Class($spo2) {
    if ($spo2 >= 95) return ['Normal', 'good'];
    if ($spo2 >= 90) return ['Low', 'mild'];
    return ['Critical', 'critical'];
}

// Paginate query results
function paginate($conn, $sql, $page = 1, $per_page = 15) {
    $offset = ($page - 1) * $per_page;
    $count_sql = "SELECT COUNT(*) FROM (" . $sql . ") AS t";
    $total = $conn->query($count_sql)->fetch_row()[0];
    $pages = ceil($total / $per_page);
    $results = $conn->query($sql . " LIMIT $per_page OFFSET $offset");
    return ['data' => $results, 'total' => $total, 'pages' => $pages, 'current' => $page];
}

// Redirect with message
function redirectMsg($url, $msg, $type = 'success') {
    $_SESSION['flash_msg'] = $msg;
    $_SESSION['flash_type'] = $type;
    header("Location: $url");
    exit();
}

// Display flash message
function flashMsg() {
    if (!empty($_SESSION['flash_msg'])) {
        $type = $_SESSION['flash_type'] ?? 'success';
        $msg = $_SESSION['flash_msg'];
        unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
        return "<div class='alert alert-$type'>$msg</div>";
    }
    return '';
}
?>