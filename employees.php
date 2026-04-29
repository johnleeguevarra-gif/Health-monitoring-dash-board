<?php
require_once '../includes/auth.php'; requireAdmin();
require_once '../includes/functions.php';

// Handle add/edit employee
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type'])) {
    $ft = $_POST['form_type'];

    if ($ft === 'add_employee' || $ft === 'edit_employee') {
        $id       = intval($_POST['id'] ?? 0);
        $emp_id   = sanitize($conn, $_POST['employee_id']);
        $name     = sanitize($conn, $_POST['full_name']);
        $email    = sanitize($conn, $_POST['email']);
        $role     = sanitize($conn, $_POST['role']);
        $dept     = sanitize($conn, $_POST['department']);
        $position = sanitize($conn, $_POST['position']);
        $contact  = sanitize($conn, $_POST['contact_number']);
        $status   = sanitize($conn, $_POST['status']);

        if ($id > 0) {
            $pwd_sql = '';
            if (!empty($_POST['password'])) {
                $pwd = md5($_POST['password']);
                $pwd_sql = ", password='$pwd'";
            }
            $conn->query("UPDATE users SET employee_id='$emp_id', full_name='$name', email='$email',
                role='$role', department='$dept', position='$position',
                contact_number='$contact', status='$status' $pwd_sql WHERE id=$id");
            redirectMsg('employees.php', 'Employee updated.');
        } else {
            $pwd = md5($_POST['password']);
            $check = $conn->query("SELECT id FROM users WHERE employee_id='$emp_id' OR email='$email'");
            if ($check->num_rows > 0) {
                $_SESSION['form_error'] = 'Employee ID or Email already exists.';
            } else {
                $conn->query("INSERT INTO users (employee_id,full_name,email,password,role,department,position,contact_number,status)
                    VALUES ('$emp_id','$name','$email','$pwd','$role','$dept','$position','$contact','$status')");
                redirectMsg('employees.php', 'Employee added.');
            }
        }
    }

    if ($ft === 'log_vitals') {
        $uid2      = intval($_POST['user_id']);
        $weight    = floatval($_POST['weight'] ?? 0);
        $height    = floatval($_POST['height'] ?? 0);
        $bmi       = ($height > 0) ? round($weight / (($height/100) ** 2), 1) : null;
        $bp_sys    = sanitize($conn, $_POST['bp_systolic'] ?? '');
        $bp_dia    = sanitize($conn, $_POST['bp_diastolic'] ?? '');
        $hr        = intval($_POST['heart_rate'] ?? 0);
        $temp      = floatval($_POST['temperature'] ?? 0);
        $notes     = sanitize($conn, $_POST['notes'] ?? '');
        $rec_date  = sanitize($conn, $_POST['record_date']);
        $bmi_sql   = $bmi !== null ? $bmi : 'NULL';
        $conn->query("INSERT INTO vitals (user_id,weight,height,bmi,bp_systolic,bp_diastolic,heart_rate,temperature,notes,record_date)
            VALUES ($uid2,$weight,$height,$bmi_sql,'$bp_sys','$bp_dia',$hr,$temp,'$notes','$rec_date')");
        redirectMsg('employees.php', 'Vitals logged successfully.');
    }

    if ($ft === 'log_lab') {
        $uid2     = intval($_POST['user_id']);
        $test     = sanitize($conn, $_POST['test_name']);
        $result   = sanitize($conn, $_POST['result_value']);
        $unit     = sanitize($conn, $_POST['unit'] ?? '');
        $ref      = sanitize($conn, $_POST['reference_range'] ?? '');
        $status   = sanitize($conn, $_POST['result_status']);
        $notes    = sanitize($conn, $_POST['notes'] ?? '');
        $rec_date = sanitize($conn, $_POST['record_date']);
        $conn->query("INSERT INTO lab_results (user_id,test_name,result_value,unit,reference_range,result_status,notes,record_date)
            VALUES ($uid2,'$test','$result','$unit','$ref','$status','$notes','$rec_date')");
        redirectMsg('employees.php', 'Lab result logged.');
    }
}

// Archive/restore/delete
if (isset($_GET['archive'])) {
    $conn->query("UPDATE users SET status='inactive' WHERE id=".intval($_GET['archive'])." AND role='employee'");
    redirectMsg('employees.php', 'Employee archived.', 'warning');
}
if (isset($_GET['restore'])) {
    $conn->query("UPDATE users SET status='active' WHERE id=".intval($_GET['restore']));
    redirectMsg('employees.php', 'Employee restored.');
}
if (isset($_GET['delete'])) {
    $del = intval($_GET['delete']);
    $conn->query("DELETE FROM users WHERE id=$del AND role='employee'");
    redirectMsg('employees.php', 'Employee removed permanently.', 'danger');
}

// Filters
$search  = sanitize($conn, $_GET['search'] ?? '');
$dept_f  = sanitize($conn, $_GET['dept'] ?? '');
$status_f = sanitize($conn, $_GET['status_filter'] ?? 'active');
$where   = "WHERE role IN ('employee','admin')";
if ($search)   $where .= " AND (full_name LIKE '%$search%' OR employee_id LIKE '%$search%' OR email LIKE '%$search%')";
if ($dept_f)   $where .= " AND department='$dept_f'";
if ($status_f !== '') $where .= " AND status='$status_f'";

$page   = max(1, intval($_GET['page'] ?? 1));
$per    = 12; $offset = ($page - 1) * $per;
$total  = $conn->query("SELECT COUNT(*) FROM users $where")->fetch_row()[0];
$pages  = ceil($total / $per);
$users  = $conn->query("SELECT * FROM users $where ORDER BY full_name ASC LIMIT $per OFFSET $offset");
$depts  = $conn->query("SELECT DISTINCT department FROM users WHERE department!='' ORDER BY department");

// Profile view
$profile_user = null; $vitals_history = []; $lab_history = [];
if (isset($_GET['view'])) {
    $vid = intval($_GET['view']);
    $profile_user = $conn->query("SELECT * FROM users WHERE id=$vid")->fetch_assoc();
    if ($profile_user) {
        $vr = $conn->query("SELECT * FROM vitals WHERE id=$vid ORDER BY recorded_at DESC LIMIT 20");
        $vitals_history = $vr->fetch_all(MYSQLI_ASSOC);
        $lr = $conn->query("SELECT * FROM lab_results WHERE user_id=$vid ORDER BY created_at DESC LIMIT 30");
        $lab_history = $lr->fetch_all(MYSQLI_ASSOC);
    }
}

// Edit user
$edit_user = null;
if (isset($_GET['edit'])) {
    $edit_user = $conn->query("SELECT * FROM users WHERE id=".intval($_GET['edit']))->fetch_assoc();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Employees — OMSC Health Monitor</title>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
.filter-bar{display:flex;align-items:center;gap:10px;background:var(--white);padding:14px 18px;border-radius:12px;border:1px solid var(--gray-mid);margin-bottom:18px;flex-wrap:wrap;}
.filter-bar input,.filter-bar select{padding:8px 12px;border:1.5px solid var(--gray-mid);border-radius:8px;font-size:13px;font-family:inherit;outline:none;background:var(--white);}
.filter-bar input:focus,.filter-bar select:focus{border-color:var(--accent);}

.emp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;}
.emp-card{background:var(--white);border-radius:14px;border:1px solid var(--gray-mid);padding:20px;box-shadow:var(--shadow-sm);transition:all .2s;position:relative;}
.emp-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-md);}
.emp-card.inactive{opacity:.7;border-style:dashed;}
.emp-avatar{width:56px;height:56px;border-radius:50%;background:var(--navy);color:white;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:800;margin:0 auto 12px;}
.emp-avatar.admin-av{background:var(--accent);}
.emp-name{font-size:14px;font-weight:700;text-align:center;}
.emp-id{font-size:11.5px;color:var(--text-muted);text-align:center;margin-top:2px;}
.emp-dept{font-size:12px;color:var(--text-muted);text-align:center;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.emp-badges{display:flex;justify-content:center;gap:6px;margin:10px 0;}
.emp-actions{display:flex;gap:6px;margin-top:12px;justify-content:center;flex-wrap:wrap;}
.btn-sm2{padding:6px 12px;font-size:11.5px;font-weight:600;border-radius:7px;cursor:pointer;font-family:inherit;border:none;transition:all .15s;}
.btn-view{background:var(--navy);color:white;} .btn-view:hover{background:var(--navy-light);}
.btn-edit{background:var(--gray-light);color:var(--text-dark);border:1px solid var(--gray-mid);} .btn-edit:hover{background:var(--navy);color:white;}
.btn-archive{background:#fef3c7;color:#92400e;} .btn-archive:hover{background:#f59e0b;color:white;}
.btn-restore{background:#d1fae5;color:#065f46;} .btn-restore:hover{background:#10b981;color:white;}
.btn-del{background:#fee2e2;color:#991b1b;} .btn-del:hover{background:#ef4444;color:white;}

/* Modal styles */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(9,30,62,.55);z-index:200;align-items:flex-start;justify-content:center;padding:24px;overflow-y:auto;}
.modal-overlay.open{display:flex;}
.modal-lg{background:var(--white);border-radius:16px;width:100%;max-width:900px;margin:auto;}
.modal-md{background:var(--white);border-radius:16px;width:100%;max-width:560px;margin:auto;}
.modal-header{padding:20px 24px;border-bottom:1px solid var(--gray-mid);display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:var(--white);border-radius:16px 16px 0 0;z-index:1;}
.modal-title{font-size:16px;font-weight:700;}
.modal-close{background:none;border:none;cursor:pointer;font-size:24px;color:var(--text-muted);line-height:1;}
.modal-body{padding:24px;}
.modal-footer{padding:16px 24px;border-top:1px solid var(--gray-mid);display:flex;justify-content:flex-end;gap:10px;}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.form-group{display:flex;flex-direction:column;gap:5px;}
.form-group label{font-size:12px;font-weight:600;color:var(--text-muted);}
.form-group input,.form-group select,.form-group textarea{padding:9px 12px;border:1.5px solid var(--gray-mid);border-radius:8px;font-size:13.5px;font-family:inherit;outline:none;transition:border-color .2s;}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--accent);}
.form-group.full{grid-column:1/-1;}

/* Profile */
.profile-hero{background:linear-gradient(135deg,var(--navy),var(--navy-light));border-radius:12px;padding:24px;color:white;display:flex;align-items:center;gap:20px;margin-bottom:20px;}
.p-avatar{width:64px;height:64px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:800;flex-shrink:0;}
.profile-tabs{display:flex;gap:2px;background:var(--gray-light);padding:4px;border-radius:10px;margin-bottom:16px;}
.tab-btn{flex:1;padding:8px 16px;border:none;background:transparent;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer;border-radius:7px;transition:all .2s;color:var(--text-muted);}
.tab-btn.active{background:var(--white);color:var(--text-dark);box-shadow:var(--shadow-sm);}
.tab-content{display:none;} .tab-content.active{display:block;}
.data-table{width:100%;border-collapse:collapse;}
.data-table th{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);padding:9px 12px;background:var(--gray-light);text-align:left;}
.data-table td{padding:10px 12px;border-bottom:1px solid var(--gray-light);font-size:13px;}
.data-table tr:last-child td{border-bottom:none;}
.badge-normal{background:#d1fae5;color:#065f46;} .badge-abnormal{background:#fee2e2;color:#991b1b;} .badge-borderline{background:#fef3c7;color:#92400e;}
.badge-active{background:#d1fae5;color:#065f46;} .badge-inactive{background:#f3f4f6;color:#6b7280;}
.badge-employee{background:#dbeafe;color:#1e40af;} .badge-admin{background:#ede9fe;color:#5b21b6;}

.stat-pill{display:inline-flex;flex-direction:column;align-items:center;background:rgba(255,255,255,.12);border-radius:10px;padding:10px 16px;min-width:80px;}
.sp-val{font-size:18px;font-weight:800;} .sp-lbl{font-size:10px;opacity:.7;margin-top:2px;}
.empty-state{padding:32px;text-align:center;color:var(--text-muted);}
.btn-primary{background:var(--navy);color:white;border:none;padding:10px 20px;border-radius:8px;font-size:13.5px;font-weight:600;cursor:pointer;font-family:inherit;}
.btn-primary:hover{background:var(--navy-light);}
.btn-accent{background:var(--accent);color:white;border:none;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:6px;}
.btn-accent:hover{background:#1976d2;}
.btn-outline-sm{background:transparent;color:var(--navy);border:1.5px solid var(--navy);padding:7px 14px;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;font-family:inherit;}
.btn-outline-sm:hover{background:var(--navy);color:white;}
.badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;}
.pagination{display:flex;gap:6px;padding:14px 20px;justify-content:center;}
.pagination a,.pagination span{padding:7px 13px;border-radius:8px;font-size:13px;font-weight:600;background:var(--gray-light);color:var(--text-dark);cursor:pointer;border:1px solid var(--gray-mid);}
.pagination span{background:var(--navy);color:white;border-color:var(--navy);}
.alert{padding:12px 16px;border-radius:8px;font-size:13.5px;margin-bottom:16px;font-weight:500;}
.alert-success{background:#d1fae5;color:#065f46;border-left:3px solid #10b981;}
.alert-warning{background:#fef3c7;color:#92400e;border-left:3px solid #f59e0b;}
.alert-danger{background:#fee2e2;color:#991b1b;border-left:3px solid #ef4444;}
</style>
</head>
<body>
<div class="app-layout">
  <?php include '../includes/sidebar-admin.php'; ?>
  <div class="main-content">
    <div class="topbar">
      <div class="topbar-left">
        <h2>Employee Management</h2>
        <p>Manage faculty, staff accounts and health profiles</p>
      </div>
      <div class="topbar-right">
        <button class="btn-accent" onclick="openModal('modal-add')">
          <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
          Add Employee
        </button>
      </div>
    </div>
    <div class="page-body">
      <?= flashMsg() ?>
      <?php if(!empty($_SESSION['form_error'])): ?>
        <div class="alert alert-danger"><?= $_SESSION['form_error'] ?></div>
        <?php unset($_SESSION['form_error']); endif; ?>

      <form method="GET" class="filter-bar">
        <input type="text" name="search" placeholder="Search name, ID or email…" value="<?= htmlspecialchars($search) ?>">
        <select name="dept">
          <option value="">All Departments</option>
          <?php $depts->data_seek(0); while($d=$depts->fetch_assoc()): ?>
          <option value="<?= htmlspecialchars($d['department']) ?>" <?= $dept_f===$d['department']?'selected':'' ?>><?= htmlspecialchars($d['department']) ?></option>
          <?php endwhile; ?>
        </select>
        <select name="status_filter">
          <option value="">All Status</option>
          <option value="active" <?= $status_f==='active'?'selected':'' ?>>Active</option>
          <option value="inactive" <?= $status_f==='inactive'?'selected':'' ?>>Archived</option>
        </select>
        <button type="submit" class="btn-outline-sm">Filter</button>
        <a href="employees.php" class="btn-outline-sm">Reset</a>
        <span style="margin-left:auto;font-size:12px;color:var(--text-muted);"><?= $total ?> employee(s)</span>
      </form>

      <div class="emp-grid">
        <?php if($users->num_rows===0): ?>
        <div style="grid-column:1/-1;"><div class="empty-state"><p>No employees found.</p></div></div>
        <?php else: while($u=$users->fetch_assoc()):
          $init = strtoupper(substr($u['full_name'],0,1).(strlen($u['full_name'])>1?$u['full_name'][strpos($u['full_name'],' ')+1]??'':''));
          $last_record = $conn->query("SELECT health_status, record_date FROM health_records WHERE user_id={$u['id']} ORDER BY record_date DESC LIMIT 1")->fetch_assoc();
        ?>
        <div class="emp-card <?= $u['status']==='inactive'?'inactive':'' ?>">
          <div class="emp-avatar <?= $u['role']==='admin'?'admin-av':'' ?>"><?= htmlspecialchars($init) ?></div>
          <div class="emp-name"><?= htmlspecialchars($u['full_name']) ?></div>
          <div class="emp-id"><?= htmlspecialchars($u['employee_id']) ?></div>
          <div class="emp-dept"><?= htmlspecialchars($u['department']??'—') ?></div>
          <div class="emp-badges">
            <span class="badge badge-<?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span>
            <span class="badge badge-<?= $u['status'] ?>"><?= ucfirst($u['status']) ?></span>
            <?php if($last_record): ?>
            <span class="badge badge-<?= $last_record['health_status'] ?>"><?= ucfirst($last_record['health_status']) ?></span>
            <?php endif; ?>
          </div>
          <div class="emp-actions">
            <a href="?view=<?= $u['id'] ?>" class="btn-sm2 btn-view" onclick="openModal('modal-profile')">View</a>
            <a href="?edit=<?= $u['id'] ?>" class="btn-sm2 btn-edit" onclick="openModal('modal-edit')">Edit</a>
            <?php if($u['status']==='active'): ?>
            <a href="?archive=<?= $u['id'] ?>" class="btn-sm2 btn-archive" onclick="return confirm('Archive this employee?')">Archive</a>
            <?php else: ?>
            <a href="?restore=<?= $u['id'] ?>" class="btn-sm2 btn-restore">Restore</a>
            <?php endif; ?>
            <?php if($u['id'] != $_SESSION['user_id']): ?>
            <a href="?delete=<?= $u['id'] ?>" class="btn-sm2 btn-del" onclick="return confirm('Permanently delete? This cannot be undone.')">Delete</a>
            <?php endif; ?>
          </div>
        </div>
        <?php endwhile; endif; ?>
      </div>

      <?php if($pages > 1): ?>
      <div class="pagination" style="margin-top:20px;">
        <?php for($i=1;$i<=$pages;$i++): ?>
        <<?= $i==$page?'span':'a href="?page='.$i.'&search='.urlencode($search).'&dept='.urlencode($dept_f).'&status_filter='.urlencode($status_f).'"' ?>>
          <?= $i ?>
        <?= $i==$page?'</span>':'</a>' ?>
        <?php endfor; ?>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<!-- Profile Modal -->
<?php if($profile_user): ?>
<div class="modal-overlay open" id="modal-profile">
  <div class="modal-lg">
    <div class="modal-header">
      <div class="modal-title">Employee Profile</div>
      <div style="display:flex;gap:8px;align-items:center;">
        <button class="btn-accent" onclick="openModal('modal-vitals');document.getElementById('vitals_uid').value=<?= $profile_user['id'] ?>">
          + Log Vitals
        </button>
        <button class="btn-outline-sm" onclick="openModal('modal-lab');document.getElementById('lab_uid').value=<?= $profile_user['id'] ?>">
          + Lab Result
        </button>
        <a href="employees.php?search=<?= urlencode($search) ?>" class="modal-close" style="text-decoration:none;">×</a>
      </div>
    </div>
    <div class="modal-body">
      <!-- Profile Hero -->
      <div class="profile-hero">
        <div class="p-avatar"><?= strtoupper(substr($profile_user['full_name'],0,2)) ?></div>
        <div style="flex:1;">
          <div style="font-size:20px;font-weight:800;"><?= htmlspecialchars($profile_user['full_name']) ?></div>
          <div style="opacity:.7;font-size:13px;margin-top:2px;"><?= htmlspecialchars($profile_user['employee_id']) ?> · <?= htmlspecialchars($profile_user['position']??'—') ?></div>
          <div style="opacity:.7;font-size:13px;"><?= htmlspecialchars($profile_user['department']??'—') ?> · <?= htmlspecialchars($profile_user['email']) ?></div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <?php
          $latest_vital = $conn->query("SELECT * FROM vitals WHERE id={$profile_user['id']} ORDER BY recorded_at DESC LIMIT 1")->fetch_assoc();
          if($latest_vital):
          ?>
          <?php if($latest_vital['bmi']): ?>
          <div class="stat-pill"><span class="sp-val"><?= $latest_vital['bmi'] ?></span><span class="sp-lbl">BMI</span></div>
          <?php endif; ?>
          <?php if($latest_vital['blood_pressure_systolic']): ?>
          <div class="stat-pill"><span class="sp-val"><?= $latest_vital['blood_pressure_systolic'] ?>/<?= $latest_vital['blood_pressure_diastolic'] ?></span><span class="sp-lbl">BP</span></div>
          <?php endif; ?>
          <?php if($latest_vital['heart_rate']): ?>
          <div class="stat-pill"><span class="sp-val"><?= $latest_vital['heart_rate'] ?></span><span class="sp-lbl">HR/min</span></div>
          <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Tabs -->
      <div class="profile-tabs">
        <button class="tab-btn active" onclick="showTab('tab-vitals',this)">📊 Vitals History</button>
        <button class="tab-btn" onclick="showTab('tab-labs',this)">🧪 Lab Results</button>
        <button class="tab-btn" onclick="showTab('tab-info',this)">👤 Personal Info</button>
      </div>

      <!-- Vitals Tab -->
      <div id="tab-vitals" class="tab-content active">
        <?php if(empty($vitals_history)): ?>
        <div class="empty-state">No vitals recorded yet.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
          <table class="data-table">
            <thead>
              <tr><th>Date</th><th>Weight (kg)</th><th>Height (cm)</th><th>BMI</th><th>BP</th><th>HR</th><th>Temp (°C)</th><th>Notes</th></tr>
            </thead>
            <tbody>
              <?php foreach($vitals_history as $v): ?>
              <tr>
                <td><?= date('M d, Y', strtotime($v['record_date'])) ?></td>
                <td><?= $v['weight'] ? $v['weight'].' kg' : '—' ?></td>
                <td><?= $v['height'] ? $v['height'].' cm' : '—' ?></td>
                <td>
                  <?php if($v['bmi']):
                    $bmi_class = $v['bmi']<18.5?'badge-borderline':($v['bmi']<25?'badge-normal':($v['bmi']<30?'badge-borderline':'badge-abnormal'));
                  ?>
                  <span class="badge <?= $bmi_class ?>"><?= $v['bmi'] ?></span>
                  <?php else: echo '—'; endif; ?>
                </td>
                <td><?= ($v['bp_systolic']&&$v['bp_diastolic']) ? $v['bp_systolic'].'/'.$v['bp_diastolic'] : '—' ?></td>
                <td><?= $v['heart_rate'] ? $v['heart_rate'].' bpm' : '—' ?></td>
                <td><?= $v['temperature'] ? $v['temperature'].'°C' : '—' ?></td>
                <td style="font-size:12px;color:var(--text-muted);max-width:160px;"><?= htmlspecialchars($v['notes']??'') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <!-- Lab Results Tab -->
      <div id="tab-labs" class="tab-content">
        <?php if(empty($lab_history)): ?>
        <div class="empty-state">No lab results on record.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
          <table class="data-table">
            <thead>
              <tr><th>Date</th><th>Test</th><th>Result</th><th>Unit</th><th>Reference</th><th>Status</th><th>Notes</th></tr>
            </thead>
            <tbody>
              <?php foreach($lab_history as $l): ?>
              <tr>
                <td><?= date('M d, Y', strtotime($l['record_date'])) ?></td>
                <td style="font-weight:600;"><?= htmlspecialchars($l['test_name']) ?></td>
                <td><?= htmlspecialchars($l['result_value']) ?></td>
                <td><?= htmlspecialchars($l['unit']??'—') ?></td>
                <td style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($l['reference_range']??'—') ?></td>
                <td><span class="badge badge-<?= $l['result_status'] ?>"><?= ucfirst($l['result_status']) ?></span></td>
                <td style="font-size:12px;color:var(--text-muted);max-width:140px;"><?= htmlspecialchars($l['notes']??'') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <!-- Personal Info Tab -->
      <div id="tab-info" class="tab-content">
        <div class="form-grid" style="pointer-events:none;">
          <div class="form-group"><label>Full Name</label><input type="text" value="<?= htmlspecialchars($profile_user['full_name']) ?>" readonly></div>
          <div class="form-group"><label>Employee ID</label><input type="text" value="<?= htmlspecialchars($profile_user['employee_id']) ?>" readonly></div>
          <div class="form-group"><label>Email</label><input type="text" value="<?= htmlspecialchars($profile_user['email']) ?>" readonly></div>
          <div class="form-group"><label>Contact</label><input type="text" value="<?= htmlspecialchars($profile_user['contact_number']??'') ?>" readonly></div>
          <div class="form-group"><label>Department</label><input type="text" value="<?= htmlspecialchars($profile_user['department']??'') ?>" readonly></div>
          <div class="form-group"><label>Position</label><input type="text" value="<?= htmlspecialchars($profile_user['position']??'') ?>" readonly></div>
          <div class="form-group"><label>Role</label><input type="text" value="<?= ucfirst($profile_user['role']) ?>" readonly></div>
          <div class="form-group"><label>Status</label><input type="text" value="<?= ucfirst($profile_user['status']) ?>" readonly></div>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <a href="employees.php" class="btn-outline-sm">Close</a>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Log Vitals Modal -->
<div class="modal-overlay" id="modal-vitals">
  <div class="modal-md">
    <div class="modal-header">
      <div class="modal-title">📊 Log Vitals</div>
      <button class="modal-close" onclick="closeModal('modal-vitals')">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="form_type" value="log_vitals">
      <input type="hidden" name="user_id" id="vitals_uid" value="">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group">
            <label>Record Date *</label>
            <input type="date" name="record_date" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="form-group"><label>Weight (kg)</label><input type="number" name="weight" step="0.1" placeholder="e.g. 65.5"></div>
          <div class="form-group"><label>Height (cm)</label><input type="number" name="height" step="0.1" placeholder="e.g. 168"></div>
          <div class="form-group"><label>BP Systolic</label><input type="number" name="bp_systolic" placeholder="e.g. 120"></div>
          <div class="form-group"><label>BP Diastolic</label><input type="number" name="bp_diastolic" placeholder="e.g. 80"></div>
          <div class="form-group"><label>Heart Rate (bpm)</label><input type="number" name="heart_rate" placeholder="e.g. 72"></div>
          <div class="form-group"><label>Temperature (°C)</label><input type="number" name="temperature" step="0.1" placeholder="e.g. 36.6"></div>
          <div class="form-group full"><label>Notes</label><textarea name="notes" rows="2" placeholder="Additional observations…"></textarea></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-outline-sm" onclick="closeModal('modal-vitals')">Cancel</button>
        <button type="submit" class="btn-primary">Save Vitals</button>
      </div>
    </form>
  </div>
</div>

<!-- Log Lab Result Modal -->
<div class="modal-overlay" id="modal-lab">
  <div class="modal-md">
    <div class="modal-header">
      <div class="modal-title">🧪 Log Lab Result</div>
      <button class="modal-close" onclick="closeModal('modal-lab')">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="form_type" value="log_lab">
      <input type="hidden" name="user_id" id="lab_uid" value="">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group">
            <label>Record Date *</label>
            <input type="date" name="record_date" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="form-group">
            <label>Test Name *</label>
            <select name="test_name" required>
              <option value="">Select test…</option>
              <option>Complete Blood Count (CBC)</option>
              <option>Blood Glucose (Fasting)</option>
              <option>Blood Glucose (Random)</option>
              <option>HbA1c</option>
              <option>Total Cholesterol</option>
              <option>LDL Cholesterol</option>
              <option>HDL Cholesterol</option>
              <option>Triglycerides</option>
              <option>Uric Acid</option>
              <option>Creatinine</option>
              <option>BUN</option>
              <option>SGPT (ALT)</option>
              <option>SGOT (AST)</option>
              <option>Urinalysis</option>
              <option>Other</option>
            </select>
          </div>
          <div class="form-group"><label>Result Value *</label><input type="text" name="result_value" placeholder="e.g. 5.6" required></div>
          <div class="form-group"><label>Unit</label><input type="text" name="unit" placeholder="e.g. mmol/L, mg/dL"></div>
          <div class="form-group"><label>Reference Range</label><input type="text" name="reference_range" placeholder="e.g. 3.9–6.1 mmol/L"></div>
          <div class="form-group">
            <label>Status *</label>
            <select name="result_status" required>
              <option value="normal">Normal</option>
              <option value="abnormal">Abnormal</option>
              <option value="borderline">Borderline</option>
            </select>
          </div>
          <div class="form-group full"><label>Notes</label><textarea name="notes" rows="2" placeholder="Clinical remarks…"></textarea></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-outline-sm" onclick="closeModal('modal-lab')">Cancel</button>
        <button type="submit" class="btn-primary">Save Result</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Employee Modal -->
<div class="modal-overlay" id="modal-add">
  <div class="modal-md">
    <div class="modal-header">
      <div class="modal-title">Add New Employee</div>
      <button class="modal-close" onclick="closeModal('modal-add')">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="form_type" value="add_employee">
      <input type="hidden" name="id" value="0">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group"><label>Employee ID *</label><input type="text" name="employee_id" placeholder="EMP-2024-001" required></div>
          <div class="form-group"><label>Full Name *</label><input type="text" name="full_name" required></div>
          <div class="form-group"><label>Email *</label><input type="email" name="email" required></div>
          <div class="form-group"><label>Password *</label><input type="password" name="password" required></div>
          <div class="form-group"><label>Department</label><input type="text" name="department" placeholder="e.g. College of Engineering"></div>
          <div class="form-group"><label>Position</label><input type="text" name="position" placeholder="e.g. Instructor I"></div>
          <div class="form-group"><label>Contact Number</label><input type="text" name="contact_number" placeholder="+63 9XX XXX XXXX"></div>
          <div class="form-group"><label>Role</label><select name="role"><option value="employee">Employee</option><option value="admin">Admin</option></select></div>
          <div class="form-group"><label>Status</label><select name="status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-outline-sm" onclick="closeModal('modal-add')">Cancel</button>
        <button type="submit" class="btn-primary">Save Employee</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Employee Modal -->
<?php if($edit_user): ?>
<div class="modal-overlay open" id="modal-edit">
  <div class="modal-md">
    <div class="modal-header">
      <div class="modal-title">Edit Employee</div>
      <a href="employees.php" class="modal-close" style="text-decoration:none;">×</a>
    </div>
    <form method="POST">
      <input type="hidden" name="form_type" value="edit_employee">
      <input type="hidden" name="id" value="<?= $edit_user['id'] ?>">
      <div class="modal-body">
        <div class="form-grid">
          <div class="form-group"><label>Employee ID *</label><input type="text" name="employee_id" value="<?= htmlspecialchars($edit_user['employee_id']) ?>" required></div>
          <div class="form-group"><label>Full Name *</label><input type="text" name="full_name" value="<?= htmlspecialchars($edit_user['full_name']) ?>" required></div>
          <div class="form-group"><label>Email *</label><input type="email" name="email" value="<?= htmlspecialchars($edit_user['email']) ?>" required></div>
          <div class="form-group"><label>New Password <span style="font-weight:400;opacity:.6">(leave blank)</span></label><input type="password" name="password"></div>
          <div class="form-group"><label>Department</label><input type="text" name="department" value="<?= htmlspecialchars($edit_user['department']??'') ?>"></div>
          <div class="form-group"><label>Position</label><input type="text" name="position" value="<?= htmlspecialchars($edit_user['position']??'') ?>"></div>
          <div class="form-group"><label>Contact Number</label><input type="text" name="contact_number" value="<?= htmlspecialchars($edit_user['contact_number']??'') ?>"></div>
          <div class="form-group"><label>Role</label><select name="role"><option value="employee" <?= $edit_user['role']==='employee'?'selected':'' ?>>Employee</option><option value="admin" <?= $edit_user['role']==='admin'?'selected':'' ?>>Admin</option></select></div>
          <div class="form-group"><label>Status</label><select name="status"><option value="active" <?= $edit_user['status']==='active'?'selected':'' ?>>Active</option><option value="inactive" <?= $edit_user['status']==='inactive'?'selected':'' ?>>Inactive</option></select></div>
        </div>
      </div>
      <div class="modal-footer">
        <a href="employees.php" class="btn-outline-sm">Cancel</a>
        <button type="submit" class="btn-primary">Update Employee</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
function openModal(id){ document.getElementById(id)?.classList.add('open'); }
function closeModal(id){ document.getElementById(id)?.classList.remove('open'); }
function showTab(id, btn){
  document.querySelectorAll('.tab-content').forEach(t=>t.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.getElementById(id)?.classList.add('active');
  btn.classList.add('active');
}
document.querySelectorAll('.modal-overlay').forEach(m=>{
  m.addEventListener('click',e=>{ if(e.target===m) m.classList.remove('open'); });
});
</script>
</body>
</html>