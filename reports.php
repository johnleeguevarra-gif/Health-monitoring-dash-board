<?php
require_once '../includes/auth.php'; requireAdmin();
require_once '../includes/functions.php';

// Ensure risk metric tables exist.
$conn->query("CREATE TABLE IF NOT EXISTS cholesterol (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    total_cholesterol DECIMAL(6,2) NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes TEXT NULL,
    INDEX idx_chol_user (user_id),
    INDEX idx_chol_recorded_at (recorded_at),
    CONSTRAINT fk_cholesterol_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$conn->query("CREATE TABLE IF NOT EXISTS blood_pressure (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    systolic SMALLINT UNSIGNED NOT NULL,
    diastolic SMALLINT UNSIGNED NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes TEXT NULL,
    INDEX idx_bp_user (user_id),
    INDEX idx_bp_recorded_at (recorded_at),
    CONSTRAINT fk_blood_pressure_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$conn->query("CREATE TABLE IF NOT EXISTS uric_acid (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    uric_acid_level DECIMAL(5,2) NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes TEXT NULL,
    INDEX idx_ua_user (user_id),
    INDEX idx_ua_recorded_at (recorded_at),
    CONSTRAINT fk_uric_acid_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

// Backward compatibility: older installs may already have these tables
// with different metric column names. Ensure required columns exist.
$ensureColumn = function($table, $column, $definition) use ($conn) {
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $safeColumn = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
    if ($safeTable === '' || $safeColumn === '') return;
    $exists = $conn->query("SHOW COLUMNS FROM `$safeTable` LIKE '$safeColumn'");
    if (!$exists || $exists->num_rows === 0) {
        $conn->query("ALTER TABLE `$safeTable` ADD COLUMN `$safeColumn` $definition");
    }
};
$ensureColumn('cholesterol', 'total_cholesterol', 'DECIMAL(6,2) NULL');
$ensureColumn('blood_pressure', 'systolic', 'SMALLINT UNSIGNED NULL');
$ensureColumn('blood_pressure', 'diastolic', 'SMALLINT UNSIGNED NULL');
$ensureColumn('uric_acid', 'uric_acid_level', 'DECIMAL(5,2) NULL');

// ── Summary counts ──
$total_employees = $conn->query("SELECT COUNT(*) FROM users WHERE role='employee'")->fetch_row()[0];
$total_records   = $conn->query("SELECT COUNT(*) FROM health_records")->fetch_row()[0];
$total_appts     = $conn->query("SELECT COUNT(*) FROM appointments")->fetch_row()[0];
$critical_cnt    = $conn->query("SELECT COUNT(*) FROM health_records WHERE health_status='critical'")->fetch_row()[0];

// ── Health Status Distribution ──
$status_counts = $conn->query("SELECT health_status, COUNT(*) as cnt FROM health_records GROUP BY health_status");
$sc = ['good'=>0,'mild'=>0,'moderate'=>0,'critical'=>0];
while ($r = $status_counts->fetch_assoc()) $sc[$r['health_status']] = (int)$r['cnt'];

// ── Monthly Health Records (last 6 months) ──
$monthly = $conn->query("SELECT DATE_FORMAT(record_date,'%b %Y') as mo, COUNT(*) as cnt
    FROM health_records WHERE record_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(record_date,'%Y-%m') ORDER BY record_date ASC");
$months=[]; $month_counts=[];
while ($r = $monthly->fetch_assoc()) { $months[]=$r['mo']; $month_counts[]=$r['cnt']; }

// ── Department Stats ──
$dept_stats = $conn->query("SELECT u.department, COUNT(*) as cnt
    FROM health_records hr JOIN users u ON hr.user_id=u.id
    WHERE u.department != '' GROUP BY u.department ORDER BY cnt DESC LIMIT 8");
$dept_labels=[]; $dept_data=[];
while ($r = $dept_stats->fetch_assoc()) { $dept_labels[]=$r['department']; $dept_data[]=$r['cnt']; }

// ── BMI Distribution ──
$bmi_ranges = [
    'Underweight (<18.5)' => (int)$conn->query("SELECT COUNT(*) FROM vitals WHERE bmi IS NOT NULL AND bmi < 18.5")->fetch_row()[0],
    'Normal (18.5–24.9)'  => (int)$conn->query("SELECT COUNT(*) FROM vitals WHERE bmi >= 18.5 AND bmi < 25")->fetch_row()[0],
    'Overweight (25–29.9)'=> (int)$conn->query("SELECT COUNT(*) FROM vitals WHERE bmi >= 25 AND bmi < 30")->fetch_row()[0],
    'Obese (≥30)'         => (int)$conn->query("SELECT COUNT(*) FROM vitals WHERE bmi >= 30")->fetch_row()[0],
];

// ── Blood Pressure Categories ──
$bp_categories = [
    'Normal (<120/80)'         => (int)$conn->query("SELECT COUNT(*) FROM blood_pressure WHERE systolic < 120 AND diastolic < 80")->fetch_row()[0],
    'Elevated (120–129/<80)'   => (int)$conn->query("SELECT COUNT(*) FROM blood_pressure WHERE systolic >= 120 AND systolic <= 129 AND diastolic < 80")->fetch_row()[0],
    'High Stage 1 (130–139)'   => (int)$conn->query("SELECT COUNT(*) FROM blood_pressure WHERE systolic >= 130 AND systolic <= 139")->fetch_row()[0],
    'High Stage 2 (≥140)'      => (int)$conn->query("SELECT COUNT(*) FROM blood_pressure WHERE systolic >= 140")->fetch_row()[0],
    'Hypotension (<90)'        => (int)$conn->query("SELECT COUNT(*) FROM blood_pressure WHERE systolic < 90")->fetch_row()[0],
];

// ── Appointment Trend (last 6 months) ──
$appt_trend = $conn->query("SELECT DATE_FORMAT(appointment_date,'%b %Y') as mo,
    SUM(status='pending') as pending,
    SUM(status='approved') as approved,
    SUM(status='completed') as completed,
    SUM(status='cancelled') as cancelled
    FROM appointments
    WHERE appointment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(appointment_date,'%Y-%m') ORDER BY appointment_date ASC");
$appt_months=[]; $appt_pending=[]; $appt_approved=[]; $appt_completed=[]; $appt_cancelled=[];
while ($r = $appt_trend->fetch_assoc()) {
    $appt_months[]  = $r['mo'];
    $appt_pending[] = (int)$r['pending'];
    $appt_approved[]= (int)$r['approved'];
    $appt_completed[]=(int)$r['completed'];
    $appt_cancelled[]=(int)$r['cancelled'];
}

// ── Risk Indicators ──
// Cholesterol Risk (high = ≥200 mg/dL)
$chol_high    = (int)$conn->query("SELECT COUNT(*) FROM cholesterol WHERE total_cholesterol >= 200")->fetch_row()[0];
$chol_total   = (int)$conn->query("SELECT COUNT(*) FROM cholesterol WHERE total_cholesterol IS NOT NULL")->fetch_row()[0];
$chol_border  = (int)$conn->query("SELECT COUNT(*) FROM cholesterol WHERE total_cholesterol >= 200 AND total_cholesterol < 240")->fetch_row()[0];
$chol_danger  = (int)$conn->query("SELECT COUNT(*) FROM cholesterol WHERE total_cholesterol >= 240")->fetch_row()[0];
$chol_normal  = $chol_total - $chol_high;

// Diabetes Risk (fasting glucose ≥126 = diabetes, 100–125 = prediabetes)
$has_blood_sugar = false;
$blood_sugar_col = $conn->query("SHOW COLUMNS FROM vitals LIKE 'blood_sugar'");
if ($blood_sugar_col && $blood_sugar_col->num_rows > 0) {
    $has_blood_sugar = true;
}
if ($has_blood_sugar) {
    $diab_count    = (int)$conn->query("SELECT COUNT(*) FROM vitals WHERE blood_sugar >= 126")->fetch_row()[0];
    $prediab_count = (int)$conn->query("SELECT COUNT(*) FROM vitals WHERE blood_sugar >= 100 AND blood_sugar < 126")->fetch_row()[0];
    $bs_normal     = (int)$conn->query("SELECT COUNT(*) FROM vitals WHERE blood_sugar IS NOT NULL AND blood_sugar < 100")->fetch_row()[0];
} else {
    $diab_count = 0;
    $prediab_count = 0;
    $bs_normal = 0;
}

// Uric Acid Risk (men >7, women >6 — using >7 as general threshold)
$ua_high      = (int)$conn->query("SELECT COUNT(*) FROM uric_acid WHERE uric_acid_level > 7")->fetch_row()[0];
$ua_normal    = (int)$conn->query("SELECT COUNT(*) FROM uric_acid WHERE uric_acid_level IS NOT NULL AND uric_acid_level <= 7")->fetch_row()[0];

// ── Risk summary ──
$risk_pct = function($n, $tot) { return $tot > 0 ? round($n / $tot * 100) : 0; };
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Reports — OMSC Health Monitor</title>
<link rel="stylesheet" href="../assets/css/style.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
/* ── Risk Indicator Cards ── */
.risk-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;margin-bottom:22px;}
.risk-card{background:var(--white);border-radius:12px;padding:18px;border:1.5px solid var(--gray-mid);position:relative;overflow:hidden;}
.risk-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;}
.risk-card.danger::before{background:#ef4444;}
.risk-card.warning::before{background:#f59e0b;}
.risk-card.info::before{background:#3b82f6;}
.risk-card.success::before{background:#10b981;}
.risk-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);margin-bottom:10px;}
.risk-value{font-size:28px;font-weight:800;line-height:1;}
.risk-sub{font-size:11.5px;color:var(--text-muted);margin-top:4px;}
.risk-bar{height:6px;background:var(--gray-mid);border-radius:3px;margin-top:12px;overflow:hidden;}
.risk-fill{height:100%;border-radius:3px;transition:width .6s ease;}
.risk-fill.danger{background:#ef4444;}
.risk-fill.warning{background:#f59e0b;}
.risk-fill.info{background:#3b82f6;}
.risk-fill.success{background:#10b981;}
.risk-tags{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;}
.risk-tag{font-size:11px;padding:3px 9px;border-radius:20px;font-weight:600;}
.risk-tag.red{background:#fee2e2;color:#991b1b;}
.risk-tag.yellow{background:#fef3c7;color:#92400e;}
.risk-tag.green{background:#d1fae5;color:#065f46;}
.risk-tag.blue{background:#dbeafe;color:#1e40af;}

/* ── Chart layout ── */
.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-bottom:20px;}
@media(max-width:900px){.grid-3{grid-template-columns:1fr 1fr;}}
@media(max-width:600px){.grid-3,.grid-2{grid-template-columns:1fr;}}
.chart-wrap-sm{height:220px;position:relative;}
.chart-wrap-md{height:260px;position:relative;}
.chart-wrap-tall{height:300px;position:relative;}

/* ── BP Category Table ── */
.bp-table{width:100%;border-collapse:collapse;font-size:13px;}
.bp-table th{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:700;padding:8px 12px;border-bottom:2px solid var(--gray-mid);text-align:left;}
.bp-table td{padding:10px 12px;border-bottom:1px solid var(--gray-light);}
.bp-bar-cell{width:40%;}
.bp-mini-bar{height:10px;border-radius:5px;min-width:4px;}

/* Print */
@media print {
  .sidebar,.topbar-right,.no-print{display:none!important;}
  .main-content{margin-left:0!important;}
  .card{break-inside:avoid;}
  .grid-2,.grid-3,.risk-grid{display:block;}
  .grid-2>*,.grid-3>*{margin-bottom:16px;}
}
</style>
</head>
<body>
<div class="app-layout">
  <?php include '../includes/sidebar-admin.php'; ?>
  <div class="main-content">
    <div class="topbar">
      <div class="topbar-left">
        <h2>Health Analytics &amp; Reports</h2>
        <p>Overview of campus health data and risk indicators</p>
      </div>
      <div class="topbar-right no-print">
        <button class="btn-outline" onclick="window.print()">
          <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
          Print Report
        </button>
      </div>
    </div>
    <div class="page-body">

      <!-- Summary Stats -->
      <div class="stat-grid">
        <div class="stat-card"><div class="stat-icon blue">👥</div><div><div class="stat-value"><?= $total_employees ?></div><div class="stat-label">Total Employees</div></div></div>
        <div class="stat-card"><div class="stat-icon green">📋</div><div><div class="stat-value"><?= $total_records ?></div><div class="stat-label">Health Records</div></div></div>
        <div class="stat-card"><div class="stat-icon yellow">📅</div><div><div class="stat-value"><?= $total_appts ?></div><div class="stat-label">Appointments</div></div></div>
        <div class="stat-card"><div class="stat-icon red">⚠️</div><div><div class="stat-value"><?= $critical_cnt ?></div><div class="stat-label">Critical Cases</div></div></div>
      </div>

      <!-- ── Risk Indicators ── -->
      <div style="font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--text-muted);margin-bottom:12px;">Risk Indicators</div>
      <div class="risk-grid">

        <!-- Cholesterol -->
        <?php $chol_pct = $risk_pct($chol_danger, $chol_total ?: 1); ?>
        <div class="risk-card <?= $chol_danger > 0 ? 'danger' : 'success' ?>">
          <div class="risk-title">🩸 High Cholesterol</div>
          <div class="risk-value"><?= $chol_danger ?></div>
          <div class="risk-sub">employees ≥240 mg/dL (high risk)</div>
          <div class="risk-bar"><div class="risk-fill danger" style="width:<?= $risk_pct($chol_danger,$chol_total?:1) ?>%"></div></div>
          <div class="risk-tags">
            <span class="risk-tag red">High: <?= $chol_danger ?></span>
            <span class="risk-tag yellow">Borderline: <?= $chol_border ?></span>
            <span class="risk-tag green">Normal: <?= $chol_normal ?></span>
          </div>
        </div>

        <!-- Diabetes -->
        <?php $diab_pct = $risk_pct($diab_count, ($diab_count+$prediab_count+$bs_normal) ?: 1); ?>
        <div class="risk-card <?= $diab_count > 0 ? 'danger' : 'success' ?>">
          <div class="risk-title">💉 Diabetes (Fasting BG)</div>
          <div class="risk-value"><?= $diab_count ?></div>
          <div class="risk-sub">employees ≥126 mg/dL (diabetic range)</div>
          <div class="risk-bar"><div class="risk-fill danger" style="width:<?= $diab_pct ?>%"></div></div>
          <div class="risk-tags">
            <span class="risk-tag red">Diabetic: <?= $diab_count ?></span>
            <span class="risk-tag yellow">Pre-diabetic: <?= $prediab_count ?></span>
            <span class="risk-tag green">Normal: <?= $bs_normal ?></span>
          </div>
        </div>

        <!-- Pre-diabetes -->
        <div class="risk-card <?= $prediab_count > 0 ? 'warning' : 'success' ?>">
          <div class="risk-title">⚡ Pre-Diabetes</div>
          <div class="risk-value"><?= $prediab_count ?></div>
          <div class="risk-sub">employees 100–125 mg/dL</div>
          <div class="risk-bar"><div class="risk-fill warning" style="width:<?= $risk_pct($prediab_count,($diab_count+$prediab_count+$bs_normal)?:1) ?>%"></div></div>
          <div class="risk-tags">
            <span class="risk-tag yellow">At-risk: <?= $prediab_count ?></span>
            <span class="risk-tag green">Normal: <?= $bs_normal ?></span>
          </div>
        </div>

        <!-- Uric Acid -->
        <div class="risk-card <?= $ua_high > 0 ? 'warning' : 'success' ?>">
          <div class="risk-title">🔬 High Uric Acid</div>
          <div class="risk-value"><?= $ua_high ?></div>
          <div class="risk-sub">employees >7 mg/dL (gout risk)</div>
          <div class="risk-bar"><div class="risk-fill warning" style="width:<?= $risk_pct($ua_high,($ua_high+$ua_normal)?:1) ?>%"></div></div>
          <div class="risk-tags">
            <span class="risk-tag yellow">Elevated: <?= $ua_high ?></span>
            <span class="risk-tag green">Normal: <?= $ua_normal ?></span>
          </div>
        </div>

        <!-- Critical Cases -->
        <div class="risk-card <?= $critical_cnt > 0 ? 'danger' : 'success' ?>">
          <div class="risk-title">🚨 Critical Health Status</div>
          <div class="risk-value"><?= $critical_cnt ?></div>
          <div class="risk-sub">employees flagged as critical</div>
          <div class="risk-bar"><div class="risk-fill danger" style="width:<?= $risk_pct($critical_cnt,$total_records?:1) ?>%"></div></div>
          <div class="risk-tags">
            <span class="risk-tag red">Critical: <?= $critical_cnt ?></span>
            <span class="risk-tag yellow">Moderate: <?= $sc['moderate'] ?></span>
            <span class="risk-tag green">Good: <?= $sc['good'] ?></span>
          </div>
        </div>

        <!-- BP High Stage 2 -->
        <?php $bp_danger = $bp_categories['High Stage 2 (≥140)']; $bp_total = array_sum($bp_categories); ?>
        <div class="risk-card <?= $bp_danger > 0 ? 'danger' : 'success' ?>">
          <div class="risk-title">❤️ Hypertension Stage 2</div>
          <div class="risk-value"><?= $bp_danger ?></div>
          <div class="risk-sub">employees systolic ≥140 mmHg</div>
          <div class="risk-bar"><div class="risk-fill danger" style="width:<?= $risk_pct($bp_danger,$bp_total?:1) ?>%"></div></div>
          <div class="risk-tags">
            <span class="risk-tag red">Stage 2: <?= $bp_danger ?></span>
            <span class="risk-tag yellow">Stage 1: <?= $bp_categories['High Stage 1 (130–139)'] ?></span>
            <span class="risk-tag green">Normal: <?= $bp_categories['Normal (<120/80)'] ?></span>
          </div>
        </div>
      </div>

      <!-- Charts Row 1 -->
      <div class="grid-2" style="margin-bottom:18px;">
        <div class="card">
          <div class="card-header"><div><div class="card-title">Monthly Health Records</div><div class="card-sub">Last 6 months</div></div></div>
          <div class="chart-wrap-md"><canvas id="chartMonthly"></canvas></div>
        </div>
        <div class="card">
          <div class="card-header"><div><div class="card-title">Health Status Distribution</div><div class="card-sub">All records</div></div></div>
          <div class="chart-wrap-md"><canvas id="chartStatus"></canvas></div>
        </div>
      </div>

      <!-- Charts Row 2 -->
      <div class="grid-2" style="margin-bottom:18px;">
        <div class="card">
          <div class="card-header"><div><div class="card-title">Appointment Trend</div><div class="card-sub">By status — last 6 months</div></div></div>
          <div class="chart-wrap-md"><canvas id="chartApptTrend"></canvas></div>
        </div>
        <div class="card">
          <div class="card-header"><div><div class="card-title">BMI Distribution</div><div class="card-sub">Employees with recorded BMI</div></div></div>
          <div class="chart-wrap-md"><canvas id="chartBMI"></canvas></div>
        </div>
      </div>

      <!-- Charts Row 3 -->
      <div class="grid-2" style="margin-bottom:18px;">
        <div class="card">
          <div class="card-header"><div><div class="card-title">Records by Department</div><div class="card-sub">Top 8 departments</div></div></div>
          <div class="chart-wrap-tall"><canvas id="chartDept"></canvas></div>
        </div>
        <div class="card">
          <div class="card-header"><div><div class="card-title">Blood Pressure Categories</div><div class="card-sub">Breakdown across all recorded BP</div></div></div>
          <table class="bp-table">
            <thead>
              <tr>
                <th>Category</th>
                <th>Count</th>
                <th class="bp-bar-cell">Distribution</th>
                <th>%</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $bp_colors = ['#10b981','#f59e0b','#f97316','#ef4444','#3b82f6'];
              $bp_i = 0;
              foreach ($bp_categories as $label => $cnt):
                $pct = $bp_total > 0 ? round($cnt / $bp_total * 100) : 0;
                $col = $bp_colors[$bp_i++];
              ?>
              <tr>
                <td style="font-weight:600;"><?= $label ?></td>
                <td style="font-weight:700;"><?= $cnt ?></td>
                <td class="bp-bar-cell">
                  <div style="background:var(--gray-light);border-radius:5px;height:10px;overflow:hidden;">
                    <div class="bp-mini-bar" style="width:<?= $pct ?>%;background:<?= $col ?>;"></div>
                  </div>
                </td>
                <td style="color:var(--text-muted);font-size:12px;"><?= $pct ?>%</td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <div style="margin-top:14px;">
            <canvas id="chartBP" height="160"></canvas>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
Chart.defaults.font.family = "'Plus Jakarta Sans', 'Segoe UI', sans-serif";
Chart.defaults.font.size   = 12;
Chart.defaults.color       = '#6b7280';

// Monthly Records
new Chart(document.getElementById('chartMonthly'), {
  type: 'line',
  data: {
    labels: <?= json_encode($months) ?>,
    datasets: [{
      label: 'Records', data: <?= json_encode($month_counts) ?>,
      borderColor: '#2196f3', backgroundColor: 'rgba(33,150,243,0.1)',
      borderWidth: 2.5, pointRadius: 4, pointBackgroundColor: '#2196f3',
      fill: true, tension: 0.4
    }]
  },
  options: { responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{display:false} },
    scales:{ y:{beginAtZero:true,grid:{color:'#f0f0f0'},ticks:{stepSize:1}}, x:{grid:{display:false}} }
  }
});

// Health Status Doughnut
new Chart(document.getElementById('chartStatus'), {
  type: 'doughnut',
  data: {
    labels: ['Good','Mild','Moderate','Critical'],
    datasets: [{ data: [<?= $sc['good'].','.$sc['mild'].','.$sc['moderate'].','.$sc['critical'] ?>],
      backgroundColor: ['#10b981','#f59e0b','#f97316','#ef4444'], borderWidth:0, hoverOffset:6
    }]
  },
  options: { responsive:true, maintainAspectRatio:false, cutout:'68%',
    plugins:{ legend:{position:'right',labels:{padding:16,usePointStyle:true,pointStyleWidth:10}} }
  }
});

// Appointment Trend Stacked Bar
new Chart(document.getElementById('chartApptTrend'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($appt_months) ?>,
    datasets: [
      { label:'Pending',   data: <?= json_encode($appt_pending) ?>,  backgroundColor:'#f59e0b', borderRadius:0 },
      { label:'Approved',  data: <?= json_encode($appt_approved) ?>, backgroundColor:'#10b981', borderRadius:0 },
      { label:'Completed', data: <?= json_encode($appt_completed) ?>,backgroundColor:'#3b82f6', borderRadius:0 },
      { label:'Cancelled', data: <?= json_encode($appt_cancelled) ?>,backgroundColor:'#ef4444', borderRadius:0 },
    ]
  },
  options: { responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{position:'bottom',labels:{usePointStyle:true,pointStyleWidth:10,padding:14}} },
    scales:{
      x:{ stacked:true, grid:{display:false} },
      y:{ stacked:true, beginAtZero:true, grid:{color:'#f0f0f0'}, ticks:{stepSize:1} }
    }
  }
});

// BMI
new Chart(document.getElementById('chartBMI'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode(array_keys($bmi_ranges)) ?>,
    datasets: [{ data: <?= json_encode(array_values($bmi_ranges)) ?>,
      backgroundColor: ['#3b82f6','#10b981','#f59e0b','#ef4444'], borderWidth:0
    }]
  },
  options: { responsive:true, maintainAspectRatio:false, cutout:'60%',
    plugins:{ legend:{position:'right',labels:{padding:14,usePointStyle:true,pointStyleWidth:10,font:{size:11}}} }
  }
});

// Department Horizontal Bar
new Chart(document.getElementById('chartDept'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($dept_labels) ?>,
    datasets: [{ label:'Records', data: <?= json_encode($dept_data) ?>,
      backgroundColor: 'rgba(13,43,85,0.8)', borderRadius:6, borderSkipped:false
    }]
  },
  options: { indexAxis:'y', responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{display:false} },
    scales:{
      x:{ beginAtZero:true, grid:{color:'#f0f0f0'}, ticks:{stepSize:1} },
      y:{ grid:{display:false}, ticks:{font:{size:11}} }
    }
  }
});

// BP Horizontal Bar
new Chart(document.getElementById('chartBP'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_keys($bp_categories)) ?>,
    datasets: [{ label:'Employees', data: <?= json_encode(array_values($bp_categories)) ?>,
      backgroundColor: ['#10b981','#f59e0b','#f97316','#ef4444','#3b82f6'],
      borderRadius:6, borderSkipped:false
    }]
  },
  options: { indexAxis:'y', responsive:true, maintainAspectRatio:false,
    plugins:{ legend:{display:false} },
    scales:{
      x:{ beginAtZero:true, grid:{color:'#f0f0f0'}, ticks:{stepSize:1} },
      y:{ grid:{display:false}, ticks:{font:{size:10}} }
    }
  }
});
</script>
</body>
</html>