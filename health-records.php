<?php
require_once '../includes/auth.php'; requireAdmin();
require_once '../includes/functions.php';

$search  = sanitize($conn, $_GET['search'] ?? '');
$status  = sanitize($conn, $_GET['status'] ?? '');
$date_f  = sanitize($conn, $_GET['date'] ?? '');
$dept_f  = sanitize($conn, $_GET['dept'] ?? '');

$where = "WHERE 1=1";
if ($search) $where .= " AND (u.full_name LIKE '%$search%' OR u.employee_id LIKE '%$search%')";
if ($status) $where .= " AND hr.health_status='$status'";
if ($date_f) $where .= " AND hr.record_date='$date_f'";
if ($dept_f) $where .= " AND u.department='$dept_f'";

$page   = max(1, intval($_GET['page'] ?? 1));
$per    = 15;
$offset = ($page-1)*$per;

$base_sql = "SELECT hr.*, u.full_name, u.employee_id, u.department,
    v.blood_pressure_systolic, v.blood_pressure_diastolic, v.heart_rate,
    v.temperature, v.oxygen_saturation, v.weight, v.bmi
    FROM health_records hr
    JOIN users u ON hr.user_id=u.id
    LEFT JOIN vitals v ON v.health_record_id=hr.id
    $where";

$total  = $conn->query("SELECT COUNT(*) FROM health_records hr JOIN users u ON hr.user_id=u.id $where")->fetch_row()[0];
$pages  = ceil($total / $per);
$records = $conn->query("$base_sql ORDER BY hr.record_date DESC, hr.created_at DESC LIMIT $per OFFSET $offset");

$depts = $conn->query("SELECT DISTINCT department FROM users WHERE department != '' ORDER BY department");

$view_rec = null;
if (isset($_GET['view'])) {
    $vid = intval($_GET['view']);
    $view_rec = $conn->query("SELECT hr.*, u.full_name, u.employee_id, u.department, u.position,
        v.blood_pressure_systolic, v.blood_pressure_diastolic, v.heart_rate,
        v.temperature, v.oxygen_saturation, v.weight, v.height, v.bmi
        FROM health_records hr
        JOIN users u ON hr.user_id=u.id
        LEFT JOIN vitals v ON v.health_record_id=hr.id
        WHERE hr.id=$vid")->fetch_assoc();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Health Records — OMSC Health Monitor</title>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="app-layout">
  <?php include '../includes/sidebar-admin.php'; ?>
  <div class="main-content">
    <div class="topbar">
      <div class="topbar-left">
        <h2>Health Records</h2>
        <p>All submitted health data across faculty and staff</p>
      </div>
      <div class="topbar-right topbar-date"><?= date('F j, Y') ?></div>
    </div>
    <div class="page-body">
      <?= flashMsg() ?>

      <form method="GET" class="filter-bar">
        <input type="text"  name="search" placeholder="Search employee…" value="<?= htmlspecialchars($search) ?>">
        <input type="date"  name="date"   value="<?= htmlspecialchars($date_f) ?>">
        <select name="status">
          <option value="">All Status</option>
          <?php foreach(['good','mild','moderate','critical'] as $s): ?>
          <option value="<?=$s?>" <?=$status===$s?'selected':''?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="dept">
          <option value="">All Departments</option>
          <?php while($d=$depts->fetch_assoc()): ?>
          <option value="<?= htmlspecialchars($d['department']) ?>" <?=$dept_f===$d['department']?'selected':''?>>
            <?= htmlspecialchars($d['department']) ?>
          </option>
          <?php endwhile; ?>
        </select>
        <button type="submit" class="btn-outline">Filter</button>
        <a href="health-records.php" class="btn-outline">Reset</a>
        <span style="margin-left:auto;font-size:12px;color:var(--text-muted);"><?=$total?> record(s)</span>
      </form>

      <div class="card">
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Employee</th>
                <th>Department</th>
                <th>Date</th>
                <th>Status</th>
                <th>BP</th>
                <th>HR</th>
                <th>Temp (°C)</th>
                <th>SpO₂ (%)</th>
                <th>BMI</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if($records->num_rows===0): ?>
              <tr><td colspan="10"><div class="empty-state"><p>No records found.</p></div></td></tr>
              <?php else: while($r=$records->fetch_assoc()): ?>
              <tr>
                <td>
                  <div style="font-weight:600;"><?= htmlspecialchars($r['full_name']) ?></div>
                  <div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($r['employee_id']) ?></div>
                </td>
                <td><?= htmlspecialchars($r['department'] ?: '—') ?></td>
                <td><?= niceDate($r['record_date']) ?></td>
                <td><span class="badge badge-<?= $r['health_status'] ?>"><?= ucfirst($r['health_status']) ?></span></td>
                <td><?= $r['blood_pressure_systolic'] ? $r['blood_pressure_systolic'].'/'.$r['blood_pressure_diastolic'] : '—' ?></td>
                <td><?= $r['heart_rate'] ?: '—' ?></td>
                <td><?= $r['temperature'] ?: '—' ?></td>
                <td><?= $r['oxygen_saturation'] ?: '—' ?></td>
                <td><?= $r['bmi'] ?: '—' ?></td>
                <td>
                  <a href="?view=<?= $r['id'] ?>&<?= http_build_query(array_filter(['search'=>$search,'status'=>$status,'date'=>$date_f,'dept'=>$dept_f,'page'=>$page])) ?>"
                     class="btn-outline btn-sm">View</a>
                </td>
              </tr>
              <?php endwhile; endif; ?>
            </tbody>
          </table>
        </div>
        <?php if($pages>1): ?>
        <div class="pagination">
          <?php for($i=1;$i<=$pages;$i++): ?>
          <<?=$i==$page?'span class="active"':'a href="?page='.$i.'&search='.urlencode($search).'&status='.urlencode($status).'"'?>>
            <?=$i?>
          <?=$i==$page?'</span>':'</a>'?>
          <?php endfor; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if($view_rec): ?>
<div class="modal-overlay open" id="modal-view">
  <div class="modal" style="max-width:700px;">
    <div class="modal-header">
      <div>
        <div class="modal-title"><?= htmlspecialchars($view_rec['full_name']) ?>'s Health Record</div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:2px;"><?= niceDate($view_rec['record_date']) ?> &bull; <?= htmlspecialchars($view_rec['employee_id']) ?></div>
      </div>
      <a href="health-records.php" class="modal-close">×</a>
    </div>
    <div class="modal-body">
      <div style="margin-bottom:16px;">
        <span class="badge badge-<?= $view_rec['health_status'] ?>" style="font-size:13px;padding:5px 14px;">
          <?= ucfirst($view_rec['health_status']) ?> Health Status
        </span>
      </div>

      <p style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);margin-bottom:12px;">Vital Signs</p>
      <div class="vital-grid" style="margin-bottom:20px;">
        <?php
        $bp_class = bpClass($view_rec['blood_pressure_systolic'], $view_rec['blood_pressure_diastolic']);
        $spo2_class = spo2Class($view_rec['oxygen_saturation']);
        $bmi_class  = $view_rec['bmi'] ? bmiClass($view_rec['bmi']) : ['—',''];
        ?>
        <div class="vital-card">
          <div class="v-label">Blood Pressure</div>
          <div class="v-value"><?= $view_rec['blood_pressure_systolic'] ? $view_rec['blood_pressure_systolic'].'/'.$view_rec['blood_pressure_diastolic'] : '—' ?></div>
          <div class="v-unit">mmHg</div>
          <div class="v-status <?= $bp_class[1] ?>"><?= $bp_class[0] ?></div>
        </div>
        <div class="vital-card">
          <div class="v-label">Heart Rate</div>
          <div class="v-value"><?= $view_rec['heart_rate'] ?: '—' ?></div>
          <div class="v-unit">bpm</div>
        </div>
        <div class="vital-card">
          <div class="v-label">Temperature</div>
          <div class="v-value"><?= $view_rec['temperature'] ?: '—' ?></div>
          <div class="v-unit">°C</div>
        </div>
        <div class="vital-card">
          <div class="v-label">SpO₂</div>
          <div class="v-value"><?= $view_rec['oxygen_saturation'] ?: '—' ?></div>
          <div class="v-unit">%</div>
          <div class="v-status <?= $spo2_class[1] ?>"><?= $spo2_class[0] ?></div>
        </div>
        <div class="vital-card">
          <div class="v-label">BMI</div>
          <div class="v-value"><?= $view_rec['bmi'] ?: '—' ?></div>
          <div class="v-unit">kg/m²</div>
          <div class="v-status <?= $bmi_class[1] ?>"><?= $bmi_class[0] ?></div>
        </div>
        <div class="vital-card">
          <div class="v-label">Weight / Height</div>
          <div class="v-value" style="font-size:16px;"><?= $view_rec['weight'] ? $view_rec['weight'].' kg' : '—' ?></div>
          <div class="v-unit"><?= $view_rec['height'] ? $view_rec['height'].' cm' : '' ?></div>
        </div>
      </div>

      <?php if($view_rec['symptoms']): ?>
      <div style="margin-bottom:14px;">
        <p style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);margin-bottom:6px;">Symptoms</p>
        <p style="font-size:13.5px;"><?= nl2br(htmlspecialchars($view_rec['symptoms'])) ?></p>
      </div>
      <?php endif; ?>

      <?php if($view_rec['diagnosis']): ?>
      <div style="margin-bottom:14px;">
        <p style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);margin-bottom:6px;">Diagnosis</p>
        <p style="font-size:13.5px;"><?= htmlspecialchars($view_rec['diagnosis']) ?></p>
      </div>
      <?php endif; ?>

      <?php if($view_rec['remarks']): ?>
      <div>
        <p style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);margin-bottom:6px;">Remarks</p>
        <p style="font-size:13.5px;"><?= nl2br(htmlspecialchars($view_rec['remarks'])) ?></p>
      </div>
      <?php endif; ?>
    </div>
    <div class="modal-footer">
      <a href="health-records.php" class="btn-outline">Close</a>
      <a href="javascript:window.print()" class="btn-primary">Print</a>
    </div>
  </div>
</div>
<?php endif; ?>
</body>
</html>