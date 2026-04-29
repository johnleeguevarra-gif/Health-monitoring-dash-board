<?php
require_once '../includes/auth.php'; requireAdmin();
require_once '../includes/functions.php';

$total_employees  = $conn->query("SELECT COUNT(*) FROM users WHERE role='employee'")->fetch_row()[0];
$total_records    = $conn->query("SELECT COUNT(*) FROM health_records")->fetch_row()[0];
$today_records    = $conn->query("SELECT COUNT(*) FROM health_records WHERE record_date=CURDATE()")->fetch_row()[0];
$critical_count   = $conn->query("SELECT COUNT(*) FROM health_records WHERE health_status='critical'")->fetch_row()[0];
$total_appts      = $conn->query("SELECT COUNT(*) FROM appointments")->fetch_row()[0];
$pending_appts    = $conn->query("SELECT COUNT(*) FROM appointments WHERE status='pending'")->fetch_row()[0];
$active_employees = $conn->query("SELECT COUNT(*) FROM users WHERE role='employee' AND status='active'")->fetch_row()[0];
$this_month_records = $conn->query("SELECT COUNT(*) FROM health_records WHERE MONTH(record_date)=MONTH(CURDATE()) AND YEAR(record_date)=YEAR(CURDATE())")->fetch_row()[0];

// Critical cases with details
$critical_cases = $conn->query("SELECT hr.*, u.full_name, u.department, u.employee_id, u.contact_number
    FROM health_records hr JOIN users u ON hr.user_id=u.id
    WHERE hr.health_status='critical'
    ORDER BY hr.created_at DESC LIMIT 5");

// Pending appointments for quick action
$pending_list = $conn->query("SELECT a.*, u.full_name, u.employee_id, u.department
    FROM appointments a JOIN users u ON a.user_id=u.id
    WHERE a.status='pending'
    ORDER BY a.appointment_date ASC, a.appointment_time ASC LIMIT 8");

// Upcoming approved appointments
$upcoming = $conn->query("SELECT a.*, u.full_name, u.department
    FROM appointments a JOIN users u ON a.user_id=u.id
    WHERE a.status='approved' AND a.appointment_date >= CURDATE()
    ORDER BY a.appointment_date ASC, a.appointment_time ASC LIMIT 6");

// Recent records
$recent = $conn->query("SELECT hr.*, u.full_name, u.department FROM health_records hr
    JOIN users u ON hr.user_id=u.id ORDER BY hr.created_at DESC LIMIT 8");

// Monthly trend (last 7 months)
$monthly = $conn->query("SELECT DATE_FORMAT(record_date,'%b %Y') as mo, DATE_FORMAT(record_date,'%Y-%m') as ym, COUNT(*) as cnt
    FROM health_records WHERE record_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(record_date,'%Y-%m') ORDER BY ym ASC");
$months=[]; $month_counts=[];
while($r=$monthly->fetch_assoc()){ $months[]=$r['mo']; $month_counts[]=$r['cnt']; }

// Status distribution
$status_counts = $conn->query("SELECT health_status, COUNT(*) as cnt FROM health_records GROUP BY health_status");
$sc=['good'=>0,'mild'=>0,'moderate'=>0,'critical'=>0];
while($r=$status_counts->fetch_assoc()) $sc[$r['health_status']]=(int)$r['cnt'];

// Dept breakdown
$dept_stats = $conn->query("SELECT u.department, COUNT(*) as cnt FROM health_records hr
    JOIN users u ON hr.user_id=u.id WHERE u.department!=''
    GROUP BY u.department ORDER BY cnt DESC LIMIT 6");
$dept_labels=[]; $dept_data=[];
while($r=$dept_stats->fetch_assoc()){ $dept_labels[]=$r['department']; $dept_data[]=$r['cnt']; }

// BMI
$bmi_ranges=[
    'Underweight' => $conn->query("SELECT COUNT(*) FROM vitals WHERE bmi IS NOT NULL AND bmi < 18.5")->fetch_row()[0],
    'Normal'      => $conn->query("SELECT COUNT(*) FROM vitals WHERE bmi>=18.5 AND bmi<25")->fetch_row()[0],
    'Overweight'  => $conn->query("SELECT COUNT(*) FROM vitals WHERE bmi>=25 AND bmi<30")->fetch_row()[0],
    'Obese'       => $conn->query("SELECT COUNT(*) FROM vitals WHERE bmi>=30")->fetch_row()[0],
];

// Handle quick appointment actions
if(isset($_GET['appt_action']) && isset($_GET['appt_id'])){
    $aid = intval($_GET['appt_id']);
    $act = sanitize($conn,$_GET['appt_action']);
    if(in_array($act,['approved','cancelled'])){
        $conn->query("UPDATE appointments SET status='$act' WHERE id=$aid");
        // Notify employee
        $appt_row = $conn->query("SELECT user_id FROM appointments WHERE id=$aid")->fetch_assoc();
        if($appt_row){
            $msg = $act==='approved' ? 'Your appointment has been approved.' : 'Your appointment has been cancelled.';
            $conn->query("INSERT INTO notifications (user_id,type,message,created_at) VALUES ({$appt_row['user_id']},'appointment','$msg',NOW())");
        }
        header('Location: dashboard.php'); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Dashboard — OMSC Health Monitor</title>
<link rel="stylesheet" href="../assets/css/style.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
.dash-hero{background:linear-gradient(135deg,var(--navy) 0%,var(--navy-light) 100%);border-radius:16px;padding:28px 32px;margin-bottom:24px;color:white;display:flex;align-items:center;justify-content:space-between;position:relative;overflow:hidden;}
.dash-hero::before{content:'';position:absolute;right:-60px;top:-60px;width:240px;height:240px;border-radius:50%;background:rgba(255,255,255,.04);}
.dash-hero::after{content:'';position:absolute;right:60px;bottom:-80px;width:160px;height:160px;border-radius:50%;background:rgba(255,255,255,.04);}
.hero-text h2{font-size:22px;font-weight:800;margin-bottom:4px;}
.hero-text p{font-size:13px;opacity:.7;}
.hero-badge{background:rgba(255,255,255,.12);padding:10px 18px;border-radius:10px;font-size:13px;font-weight:600;backdrop-filter:blur(10px);}
.hero-badge span{display:block;font-size:20px;font-weight:800;margin-bottom:2px;}

.stat-grid-8{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px;}
.stat-card-v2{background:var(--white);border-radius:12px;padding:18px 20px;display:flex;align-items:center;gap:14px;box-shadow:var(--shadow-sm);border:1px solid var(--gray-mid);transition:transform .2s,box-shadow .2s;cursor:default;}
.stat-card-v2:hover{transform:translateY(-2px);box-shadow:var(--shadow-md);}
.stat-icon-v2{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}
.stat-icon-v2.blue{background:#dbeafe;} .stat-icon-v2.green{background:#d1fae5;} .stat-icon-v2.yellow{background:#fef3c7;} .stat-icon-v2.red{background:#fee2e2;} .stat-icon-v2.purple{background:#ede9fe;} .stat-icon-v2.teal{background:#ccfbf1;} .stat-icon-v2.orange{background:#ffedd5;} .stat-icon-v2.navy{background:#e0e7ff;}
.stat-val{font-size:24px;font-weight:800;line-height:1;color:var(--text-dark);}
.stat-lbl{font-size:11.5px;color:var(--text-muted);margin-top:3px;font-weight:500;}
.stat-trend{font-size:10.5px;margin-top:4px;font-weight:600;}
.stat-trend.up{color:#10b981;} .stat-trend.warn{color:#f59e0b;} .stat-trend.danger{color:#ef4444;}

.section-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:20px;}
.section-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:18px;margin-bottom:20px;}
.card{background:var(--white);border-radius:14px;border:1px solid var(--gray-mid);box-shadow:var(--shadow-sm);overflow:hidden;}
.card-hd{padding:16px 20px;border-bottom:1px solid var(--gray-light);display:flex;align-items:center;justify-content:space-between;}
.card-hd h3{font-size:14px;font-weight:700;} .card-hd p{font-size:11.5px;color:var(--text-muted);margin-top:1px;}
.chart-box{padding:16px 20px;} .chart-box canvas{max-height:220px;}

.alert-list{padding:8px 0;}
.alert-row{display:flex;align-items:flex-start;gap:12px;padding:12px 20px;border-bottom:1px solid var(--gray-light);transition:background .15s;}
.alert-row:last-child{border-bottom:none;}
.alert-row:hover{background:var(--gray-light);}
.alert-dot{width:8px;height:8px;border-radius:50%;background:var(--danger);margin-top:5px;flex-shrink:0;box-shadow:0 0 0 3px rgba(239,68,68,.2);}
.alert-info h4{font-size:13px;font-weight:700;} .alert-info p{font-size:11.5px;color:var(--text-muted);margin-top:1px;}
.alert-actions{margin-left:auto;display:flex;gap:6px;align-items:center;flex-shrink:0;}

.appt-row{display:flex;align-items:center;gap:12px;padding:11px 20px;border-bottom:1px solid var(--gray-light);transition:background .15s;}
.appt-row:last-child{border-bottom:none;}
.appt-row:hover{background:var(--gray-light);}
.appt-avatar{width:34px;height:34px;border-radius:50%;background:var(--navy);color:white;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;}
.appt-info{flex:1;min-width:0;}
.appt-info h4{font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.appt-info p{font-size:11px;color:var(--text-muted);}
.appt-time{font-size:11px;font-weight:600;color:var(--accent);white-space:nowrap;}
.appt-actions{display:flex;gap:5px;flex-shrink:0;}

.upcoming-item{display:flex;align-items:center;gap:10px;padding:10px 20px;border-bottom:1px solid var(--gray-light);}
.upcoming-item:last-child{border-bottom:none;}
.upcoming-date{width:42px;height:42px;border-radius:10px;background:var(--accent-light);display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0;}
.upcoming-date .day{font-size:16px;font-weight:800;color:var(--accent);line-height:1;}
.upcoming-date .mon{font-size:9px;font-weight:600;color:var(--accent);text-transform:uppercase;}
.upcoming-info{flex:1;} .upcoming-info h4{font-size:13px;font-weight:600;} .upcoming-info p{font-size:11px;color:var(--text-muted);}

.recent-table{width:100%;border-collapse:collapse;}
.recent-table th{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);padding:10px 16px;background:var(--gray-light);text-align:left;}
.recent-table td{padding:11px 16px;border-bottom:1px solid var(--gray-light);font-size:13px;vertical-align:middle;}
.recent-table tr:last-child td{border-bottom:none;}
.recent-table tr:hover td{background:var(--gray-light);}

.badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;text-transform:capitalize;}
.badge-good{background:#d1fae5;color:#065f46;} .badge-mild{background:#fef3c7;color:#92400e;} .badge-moderate{background:#ffedd5;color:#9a3412;} .badge-critical{background:#fee2e2;color:#991b1b;}
.badge-pending{background:#fef3c7;color:#92400e;} .badge-approved{background:#d1fae5;color:#065f46;} .badge-completed{background:#dbeafe;color:#1e40af;} .badge-cancelled{background:#f3f4f6;color:#6b7280;}

.btn-xs{padding:4px 10px;font-size:11px;font-weight:600;border-radius:6px;border:none;cursor:pointer;font-family:inherit;transition:all .15s;}
.btn-approve{background:#d1fae5;color:#065f46;} .btn-approve:hover{background:#10b981;color:white;}
.btn-cancel{background:#fee2e2;color:#991b1b;} .btn-cancel:hover{background:#ef4444;color:white;}
.btn-view{background:var(--gray-light);color:var(--text-dark);border:1px solid var(--gray-mid);} .btn-view:hover{background:var(--navy);color:white;}
.empty-sm{padding:28px 20px;text-align:center;color:var(--text-muted);font-size:13px;}
.pulse{animation:pulse 2s infinite;}
@keyframes pulse{0%,100%{opacity:1;}50%{opacity:.5;}}

.dashboard-shell{background:linear-gradient(180deg,#f8fbff 0%,#ffffff 45%);min-height:100%;}
.topbar-date{font-size:13px;color:var(--text-muted);}
.critical-pill{background:#fee2e2;color:#991b1b;padding:7px 14px;border-radius:8px;font-size:12px;font-weight:700;display:flex;align-items:center;gap:5px;}
.hero-badges{display:flex;gap:12px;position:relative;z-index:1;}
.chart-tall{height:240px;}
.split-wide{grid-template-columns:380px 1fr;}

@media (max-width:1200px){
  .stat-grid-8{grid-template-columns:repeat(3,1fr);}
}

@media (max-width:992px){
  .dash-hero{padding:22px;align-items:flex-start;gap:16px;flex-direction:column;}
  .hero-badges{width:100%;flex-wrap:wrap;}
  .hero-badge{flex:1;min-width:140px;text-align:center;}
  .section-grid,.section-grid-3,.split-wide{grid-template-columns:1fr !important;}
  .chart-box canvas{max-height:260px;}
}

@media (max-width:768px){
  .stat-grid-8{grid-template-columns:repeat(2,1fr);gap:12px;}
  .card-hd{padding:14px 14px;}
  .chart-box{padding:14px;}
  .alert-row,.appt-row,.upcoming-item{padding-left:14px;padding-right:14px;}
  .recent-table th,.recent-table td{padding:10px 12px;}
  .topbar-right{flex-wrap:wrap;justify-content:flex-end;gap:8px;}
}

@media (max-width:560px){
  .stat-grid-8{grid-template-columns:1fr;}
  .hero-text h2{font-size:19px;}
  .hero-text p{font-size:12px;}
  .appt-row{flex-wrap:wrap;align-items:flex-start;}
  .appt-time{width:100%;order:3;}
  .appt-actions{width:100%;order:4;}
}
</style>
</head>
<body>
<div class="app-layout">
  <?php include '../includes/sidebar-admin.php'; ?>
  <div class="main-content dashboard-shell">
    <div class="topbar">
      <div class="topbar-left">
        <h2>Dashboard Overview</h2>
        <p>OMSC Employee Health Monitoring System</p>
      </div>
      <div class="topbar-right">
        <span class="topbar-date"><?= date('l, F j, Y') ?></span>
        <?php if($critical_count > 0): ?>
        <a href="health-records.php?status=critical" class="critical-pill">
          <span class="pulse">⚠️</span> <?= $critical_count ?> Critical
        </a>
        <?php endif; ?>
      </div>
    </div>

    <div class="page-body">

      <!-- Hero Banner -->
      <div class="dash-hero">
        <div class="hero-text">
          <h2>Good <?= date('H')<12?'Morning':(date('H')<17?'Afternoon':'Evening') ?>, Admin 👋</h2>
          <p>Here's a summary of campus health status for <?= date('F Y') ?></p>
        </div>
        <div class="hero-badges">
          <div class="hero-badge"><span><?= $active_employees ?></span>Active Staff</div>
          <div class="hero-badge"><span><?= $today_records ?></span>Today's Entries</div>
          <div class="hero-badge"><span><?= $pending_appts ?></span>Pending Appts</div>
        </div>
      </div>

      <!-- 8 Stat Cards -->
      <div class="stat-grid-8">
        <div class="stat-card-v2">
          <div class="stat-icon-v2 blue">👥</div>
          <div>
            <div class="stat-val"><?= $total_employees ?></div>
            <div class="stat-lbl">Total Employees</div>
            <div class="stat-trend up">↑ <?= $active_employees ?> active</div>
          </div>
        </div>
        <div class="stat-card-v2">
          <div class="stat-icon-v2 green">📋</div>
          <div>
            <div class="stat-val"><?= $total_records ?></div>
            <div class="stat-lbl">Health Records</div>
            <div class="stat-trend up">↑ <?= $this_month_records ?> this month</div>
          </div>
        </div>
        <div class="stat-card-v2">
          <div class="stat-icon-v2 yellow">📅</div>
          <div>
            <div class="stat-val"><?= $today_records ?></div>
            <div class="stat-lbl">Today's Entries</div>
            <div class="stat-trend">recorded today</div>
          </div>
        </div>
        <div class="stat-card-v2">
          <div class="stat-icon-v2 red">⚠️</div>
          <div>
            <div class="stat-val"><?= $critical_count ?></div>
            <div class="stat-lbl">Critical Cases</div>
            <?php if($critical_count>0): ?><div class="stat-trend danger pulse">Needs attention</div><?php endif; ?>
          </div>
        </div>
        <div class="stat-card-v2">
          <div class="stat-icon-v2 purple">🗓️</div>
          <div>
            <div class="stat-val"><?= $total_appts ?></div>
            <div class="stat-lbl">Total Appointments</div>
            <div class="stat-trend warn">⏳ <?= $pending_appts ?> pending</div>
          </div>
        </div>
        <div class="stat-card-v2">
          <div class="stat-icon-v2 teal">✅</div>
          <div>
            <?php $done = $conn->query("SELECT COUNT(*) FROM appointments WHERE status='completed'")->fetch_row()[0]; ?>
            <div class="stat-val"><?= $done ?></div>
            <div class="stat-lbl">Completed Sessions</div>
            <div class="stat-trend up">appointments done</div>
          </div>
        </div>
        <div class="stat-card-v2">
          <div class="stat-icon-v2 orange">💊</div>
          <div>
            <?php $vitals_count = $conn->query("SELECT COUNT(*) FROM vitals")->fetch_row()[0]; ?>
            <div class="stat-val"><?= $vitals_count ?></div>
            <div class="stat-lbl">Vitals Logged</div>
            <div class="stat-trend">measurements taken</div>
          </div>
        </div>
        <div class="stat-card-v2">
          <div class="stat-icon-v2 navy">🧪</div>
          <div>
            <?php $labs_count = $conn->query("SELECT COUNT(*) FROM lab_results")->fetch_row()[0]; ?>
            <div class="stat-val"><?= $labs_count ?></div>
            <div class="stat-lbl">Lab Results</div>
            <div class="stat-trend">records filed</div>
          </div>
        </div>
      </div>

      <!-- Charts Row 1 -->
      <div class="section-grid">
        <div class="card">
          <div class="card-hd">
            <div><h3>Monthly Health Records</h3><p>Last 6 months trend</p></div>
          </div>
          <div class="chart-box"><canvas id="chartMonthly"></canvas></div>
        </div>
        <div class="card">
          <div class="card-hd">
            <div><h3>Health Status Distribution</h3><p>All records breakdown</p></div>
          </div>
          <div class="chart-box"><canvas id="chartStatus"></canvas></div>
        </div>
      </div>

      <!-- Charts Row 2 -->
      <div class="section-grid">
        <div class="card">
          <div class="card-hd">
            <div><h3>Records by Department</h3><p>Top 3 departments</p></div>
          </div>
          <div class="chart-box chart-tall"><canvas id="chartDept"></canvas></div>
        </div>
        <div class="card">
          <div class="card-hd">
            <div><h3>BMI Distribution</h3><p>Employees with recorded BMI</p></div>
          </div>
          <div class="chart-box"><canvas id="chartBMI"></canvas></div>
        </div>
      </div>

      <!-- Critical Alerts + Pending Appointments -->
      <div class="section-grid">
        <!-- Critical Alerts -->
        <div class="card">
          <div class="card-hd">
            <div>
              <h3 style="color:var(--danger);">🚨 Critical Case Alerts</h3>
              <p>Employees requiring immediate attention</p>
            </div>
            <a href="health-records.php?status=critical" class="btn-xs btn-view">View All</a>
          </div>
          <div class="alert-list">
            <?php if($critical_cases->num_rows===0): ?>
            <div class="empty-sm">✅ No critical cases at this time.</div>
            <?php else: while($c=$critical_cases->fetch_assoc()): ?>
            <div class="alert-row">
              <div class="alert-dot"></div>
              <div class="alert-info">
                <h4><?= htmlspecialchars($c['full_name']) ?></h4>
                <p><?= htmlspecialchars($c['department']??'—') ?> · <?= $c['employee_id'] ?> · <?= date('M d, Y', strtotime($c['record_date'])) ?></p>
              </div>
              <div class="alert-actions">
                <a href="health-records.php?id=<?= $c['id'] ?>" class="btn-xs btn-view">View</a>
              </div>
            </div>
            <?php endwhile; endif; ?>
          </div>
        </div>

        <!-- Pending Appointments Quick Action -->
        <div class="card">
          <div class="card-hd">
            <div>
              <h3>⏳ Pending Appointments</h3>
              <p>Awaiting your approval or cancellation</p>
            </div>
            <a href="appointments.php?status=pending" class="btn-xs btn-view">View All</a>
          </div>
          <div>
            <?php if($pending_list->num_rows===0): ?>
            <div class="empty-sm">No pending appointments.</div>
            <?php else: while($a=$pending_list->fetch_assoc()):
              $init = strtoupper(substr($a['full_name'],0,1));
            ?>
            <div class="appt-row">
              <div class="appt-avatar"><?= $init ?></div>
              <div class="appt-info">
                <h4><?= htmlspecialchars($a['full_name']) ?></h4>
                <p><?= htmlspecialchars($a['department']??'—') ?> · <?= htmlspecialchars($a['purpose']??'Consultation') ?></p>
              </div>
              <div class="appt-time"><?= date('M d', strtotime($a['appointment_date'])) ?><br><?= date('g:i A', strtotime($a['appointment_time'])) ?></div>
              <div class="appt-actions">
                <a href="?appt_action=approved&appt_id=<?= $a['id'] ?>" class="btn-xs btn-approve">✓ Approve</a>
                <a href="?appt_action=cancelled&appt_id=<?= $a['id'] ?>" class="btn-xs btn-cancel" onclick="return confirm('Cancel this appointment?')">✗</a>
              </div>
            </div>
            <?php endwhile; endif; ?>
          </div>
        </div>
      </div>

      <!-- Upcoming Sessions + Recent Records -->
      <div class="section-grid split-wide">
        <!-- Upcoming Sessions -->
        <div class="card">
          <div class="card-hd">
            <div><h3>📆 Upcoming Sessions</h3><p>Approved appointments</p></div>
            <a href="appointments.php?status=approved" class="btn-xs btn-view">View All</a>
          </div>
          <?php if($upcoming->num_rows===0): ?>
          <div class="empty-sm">No upcoming sessions.</div>
          <?php else: while($u=$upcoming->fetch_assoc()): ?>
          <div class="upcoming-item">
            <div class="upcoming-date">
              <div class="day"><?= date('d', strtotime($u['appointment_date'])) ?></div>
              <div class="mon"><?= date('M', strtotime($u['appointment_date'])) ?></div>
            </div>
            <div class="upcoming-info">
              <h4><?= htmlspecialchars($u['full_name']) ?></h4>
              <p><?= htmlspecialchars($u['department']??'—') ?> · <?= date('g:i A', strtotime($u['appointment_time'])) ?></p>
            </div>
            <span class="badge badge-approved">Approved</span>
          </div>
          <?php endwhile; endif; ?>
        </div>

        <!-- Recent Health Records -->
        <div class="card">
          <div class="card-hd">
            <div><h3>🕐 Recent Health Records</h3><p>Latest entries across all employees</p></div>
            <a href="health-records.php" class="btn-xs btn-view">View All</a>
          </div>
          <div style="overflow-x:auto;">
            <table class="recent-table">
              <thead>
                <tr><th>Employee</th><th>Department</th><th>Date</th><th>Status</th><th>Action</th></tr>
              </thead>
              <tbody>
                <?php while($row=$recent->fetch_assoc()): ?>
                <tr>
                  <td style="font-weight:600;"><?= htmlspecialchars($row['full_name']) ?></td>
                  <td style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($row['department']??'—') ?></td>
                  <td><?= date('M d, Y', strtotime($row['record_date'])) ?></td>
                  <td><span class="badge badge-<?= $row['health_status'] ?>"><?= ucfirst($row['health_status']) ?></span></td>
                  <td><a href="health-records.php?id=<?= $row['id'] ?>" class="btn-xs btn-view">View</a></td>
                </tr>
                <?php endwhile; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<script>
Chart.defaults.font.family = "'Plus Jakarta Sans','Segoe UI',sans-serif";
Chart.defaults.font.size = 12;
Chart.defaults.color = '#6b7280';

new Chart(document.getElementById('chartMonthly'),{
  type:'line',
  data:{
    labels:<?= json_encode($months) ?>,
    datasets:[{
      label:'Records',
      data:<?= json_encode($month_counts) ?>,
      borderColor:'#2196f3',backgroundColor:'rgba(33,150,243,0.08)',
      borderWidth:2.5,pointRadius:4,pointBackgroundColor:'#2196f3',fill:true,tension:0.4
    }]
  },
  options:{responsive:true,maintainAspectRatio:false,
    plugins:{legend:{display:false}},
    scales:{y:{beginAtZero:true,grid:{color:'#f0f0f0'},ticks:{stepSize:1}},x:{grid:{display:false}}}
  }
});

new Chart(document.getElementById('chartStatus'),{
  type:'doughnut',
  data:{
    labels:['Good','Mild','Moderate','Critical'],
    datasets:[{
      data:[<?= $sc['good'].','.$sc['mild'].','.$sc['moderate'].','.$sc['critical'] ?>],
      backgroundColor:['#10b981','#f59e0b','#f97316','#ef4444'],
      borderWidth:0,hoverOffset:6
    }]
  },
  options:{responsive:true,maintainAspectRatio:false,cutout:'68%',
    plugins:{legend:{position:'right',labels:{padding:16,usePointStyle:true,pointStyleWidth:10}}}
  }
});

new Chart(document.getElementById('chartDept'),{
  type:'bar',
  data:{
    labels:<?= json_encode($dept_labels) ?>,
    datasets:[{label:'Records',data:<?= json_encode($dept_data) ?>,backgroundColor:'rgba(13,43,85,0.8)',borderRadius:6,borderSkipped:false}]
  },
  options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,
    plugins:{legend:{display:false}},
    scales:{x:{beginAtZero:true,grid:{color:'#f0f0f0'},ticks:{stepSize:1}},y:{grid:{display:false},ticks:{font:{size:11}}}}
  }
});

new Chart(document.getElementById('chartBMI'),{
  type:'doughnut',
  data:{
    labels:<?= json_encode(array_keys($bmi_ranges)) ?>,
    datasets:[{
      data:<?= json_encode(array_values($bmi_ranges)) ?>,
      backgroundColor:['#3b82f6','#10b981','#f59e0b','#ef4444'],borderWidth:0
    }]
  },
  options:{responsive:true,maintainAspectRatio:false,cutout:'60%',
    plugins:{legend:{position:'right',labels:{padding:14,usePointStyle:true,pointStyleWidth:10,font:{size:11}}}}
  }
});
</script>
</body>
</html>