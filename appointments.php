<?php
require_once '../includes/auth.php'; requireAdmin();
require_once '../includes/functions.php';

// Update appointment status + send notification
if (isset($_GET['action']) && isset($_GET['id'])) {
    $aid    = intval($_GET['id']);
    $action = sanitize($conn, $_GET['action']);
    $allowed = ['approved','completed','cancelled','pending'];
    if (in_array($action, $allowed)) {
        $conn->query("UPDATE appointments SET status='$action' WHERE id=$aid");

        // Fetch appointment owner
        $appt_row = $conn->query("SELECT user_id FROM appointments WHERE id=$aid")->fetch_assoc();
        if ($appt_row) {
            $notify_uid = (int)$appt_row['user_id'];
            $msg_map = [
                'approved'  => 'Your appointment has been approved. Please be on time.',
                'cancelled'  => 'Your appointment has been cancelled. Please contact the clinic for details.',
                'completed'  => 'Your appointment has been marked as completed. Thank you!',
                'pending'    => 'Your appointment status has been reset to pending.',
            ];
            $notif_msg = sanitize($conn, $msg_map[$action]);
            $conn->query("INSERT INTO notifications (user_id, type, message, created_at)
                VALUES ($notify_uid, 'appointment', '$notif_msg', NOW())");
        }
        redirectMsg('appointments.php', 'Appointment status updated and employee notified.');
    }
}

$status_f = sanitize($conn, $_GET['status'] ?? '');
$date_f   = sanitize($conn, $_GET['date'] ?? '');
$search   = sanitize($conn, $_GET['search'] ?? '');

$where = "WHERE 1=1";
if ($status_f) $where .= " AND a.status='$status_f'";
if ($date_f)   $where .= " AND a.appointment_date='$date_f'";
if ($search)   $where .= " AND (u.full_name LIKE '%$search%' OR u.employee_id LIKE '%$search%')";

$page  = max(1, intval($_GET['page'] ?? 1));
$per   = 15;
$offset = ($page - 1) * $per;
$total  = $conn->query("SELECT COUNT(*) FROM appointments a JOIN users u ON a.user_id=u.id $where")->fetch_row()[0];
$pages  = ceil($total / $per);
$appts  = $conn->query("SELECT a.*, u.full_name, u.employee_id, u.department
    FROM appointments a JOIN users u ON a.user_id=u.id
    $where ORDER BY a.appointment_date ASC, a.appointment_time ASC LIMIT $per OFFSET $offset");

// Status counts for quick filter tabs
$counts_q = $conn->query("SELECT status, COUNT(*) as cnt FROM appointments GROUP BY status");
$counts = ['pending'=>0,'approved'=>0,'completed'=>0,'cancelled'=>0];
while ($cr = $counts_q->fetch_assoc()) $counts[$cr['status']] = (int)$cr['cnt'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Appointments — OMSC Health Monitor</title>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
/* ── Quick-filter tab bar ── */
.tab-bar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px;}
.tab-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:12.5px;font-weight:600;cursor:pointer;border:1.5px solid var(--gray-mid);background:var(--white);color:var(--text-muted);text-decoration:none;transition:all .18s;}
.tab-btn:hover{border-color:var(--accent);color:var(--accent);}
.tab-btn.active-pending{background:#fef3c7;border-color:#f59e0b;color:#92400e;}
.tab-btn.active-approved{background:#d1fae5;border-color:#10b981;color:#065f46;}
.tab-btn.active-completed{background:#dbeafe;border-color:#3b82f6;color:#1e40af;}
.tab-btn.active-cancelled{background:#fee2e2;border-color:#ef4444;color:#991b1b;}
.tab-btn.active-all{background:var(--navy);border-color:var(--navy);color:white;}
.tab-count{background:rgba(0,0,0,.08);border-radius:20px;padding:1px 7px;font-size:11px;}

/* ── Action button group ── */
.btn-group{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}
.btn-approve{background:#d1fae5;color:#065f46;border:1.5px solid #10b981;padding:5px 12px;border-radius:7px;font-size:11.5px;font-weight:700;cursor:pointer;text-decoration:none;white-space:nowrap;transition:all .15s;}
.btn-approve:hover{background:#10b981;color:white;}
.btn-done{background:#dbeafe;color:#1e40af;border:1.5px solid #3b82f6;padding:5px 12px;border-radius:7px;font-size:11.5px;font-weight:700;cursor:pointer;text-decoration:none;white-space:nowrap;transition:all .15s;}
.btn-done:hover{background:#3b82f6;color:white;}
.btn-cancel-sm{background:#fee2e2;color:#991b1b;border:1.5px solid #ef4444;padding:5px 12px;border-radius:7px;font-size:11.5px;font-weight:700;cursor:pointer;text-decoration:none;white-space:nowrap;transition:all .15s;}
.btn-cancel-sm:hover{background:#ef4444;color:white;}
.btn-view{background:var(--gray-light);color:var(--navy);border:1.5px solid var(--gray-mid);padding:5px 12px;border-radius:7px;font-size:11.5px;font-weight:700;cursor:pointer;text-decoration:none;white-space:nowrap;transition:all .15s;}
.btn-view:hover{background:var(--navy);color:white;}

/* ── Detail Modal ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(9,30,62,.55);z-index:300;align-items:center;justify-content:center;padding:20px;}
.modal-overlay.open{display:flex;}
.modal-lg{background:var(--white);border-radius:14px;width:100%;max-width:680px;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;}
.modal-header{padding:20px 24px;border-bottom:1px solid var(--gray-mid);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.modal-title{font-size:16px;font-weight:700;}
.modal-close{background:none;border:none;cursor:pointer;font-size:24px;color:var(--text-muted);line-height:1;}
.modal-body{padding:24px;overflow-y:auto;flex:1;}
.modal-footer{padding:16px 24px;border-top:1px solid var(--gray-mid);display:flex;gap:10px;flex-wrap:wrap;flex-shrink:0;}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px;}
.info-item label{display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted);margin-bottom:3px;}
.info-item span{font-size:14px;font-weight:600;color:var(--text-dark);}
.section-divider{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);border-bottom:1px solid var(--gray-mid);padding-bottom:6px;margin:4px 0 14px;}
.vital-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;}
.vital-chip{background:var(--gray-light);border:1px solid var(--gray-mid);border-radius:8px;padding:8px 14px;font-size:12.5px;flex:1;min-width:100px;}
.vital-chip .vc-label{font-size:10px;color:var(--text-muted);font-weight:600;text-transform:uppercase;}
.vital-chip .vc-val{font-size:15px;font-weight:700;margin-top:2px;}
.no-data{color:var(--text-muted);font-size:13px;font-style:italic;}
</style>
</head>
<body>
<div class="app-layout">
  <?php include '../includes/sidebar-admin.php'; ?>
  <div class="main-content">
    <div class="topbar">
      <div class="topbar-left">
        <h2>Appointment Management</h2>
        <p>Review and manage consultation requests</p>
      </div>
    </div>
    <div class="page-body">
      <?= flashMsg() ?>

      <!-- Quick Status Tabs -->
      <div class="tab-bar">
        <a href="appointments.php" class="tab-btn <?= !$status_f ? 'active-all' : '' ?>">
          All <span class="tab-count"><?= array_sum($counts) ?></span>
        </a>
        <?php foreach(['pending'=>'⏳','approved'=>'✅','completed'=>'🏁','cancelled'=>'❌'] as $s=>$ico): ?>
        <a href="appointments.php?status=<?= $s ?><?= $search ? '&search='.urlencode($search) : '' ?><?= $date_f ? '&date='.urlencode($date_f) : '' ?>"
           class="tab-btn <?= $status_f===$s ? 'active-'.$s : '' ?>">
          <?= $ico ?> <?= ucfirst($s) ?> <span class="tab-count"><?= $counts[$s] ?></span>
        </a>
        <?php endforeach; ?>
      </div>

      <!-- Search / Date Filter -->
      <form method="GET" class="filter-bar" style="margin-bottom:16px;">
        <?php if($status_f): ?><input type="hidden" name="status" value="<?= htmlspecialchars($status_f) ?>"><?php endif; ?>
        <input type="text" name="search" placeholder="Search employee…" value="<?= htmlspecialchars($search) ?>">
        <input type="date" name="date" value="<?= htmlspecialchars($date_f) ?>">
        <button type="submit" class="btn-outline">Filter</button>
        <a href="appointments.php" class="btn-outline">Reset</a>
      </form>

      <div class="card">
        <div class="table-wrapper">
          <table>
            <thead>
              <tr>
                <th>Employee</th>
                <th>Department</th>
                <th>Date</th>
                <th>Time</th>
                <th>Purpose</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($appts->num_rows === 0): ?>
              <tr><td colspan="7"><div class="empty-state"><p>No appointments found.</p></div></td></tr>
              <?php else: while ($a = $appts->fetch_assoc()):
                $appt_id = (int)$a['id'];
              ?>
              <tr>
                <td>
                  <div style="font-weight:600;"><?= htmlspecialchars($a['full_name']) ?></div>
                  <div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($a['employee_id']) ?></div>
                </td>
                <td><?= htmlspecialchars($a['department'] ?? '—') ?></td>
                <td><?= niceDate($a['appointment_date']) ?></td>
                <td><?= date('g:i A', strtotime($a['appointment_time'])) ?></td>
                <td style="max-width:180px;"><?= htmlspecialchars($a['purpose'] ?? '—') ?></td>
                <td><span class="badge badge-<?= $a['status'] ?>"><?= ucfirst($a['status']) ?></span></td>
                <td>
                  <div class="btn-group">
                    <!-- View Detail -->
                    <button class="btn-view" onclick="openModal(<?= $appt_id ?>)">View</button>
                    <?php if ($a['status'] === 'pending'): ?>
                      <a href="?action=approved&id=<?= $appt_id ?><?= $status_f?'&status='.$status_f:'' ?>" class="btn-approve"
                         onclick="return confirm('Approve this appointment?')">✓ Approve</a>
                      <a href="?action=cancelled&id=<?= $appt_id ?><?= $status_f?'&status='.$status_f:'' ?>" class="btn-cancel-sm"
                         onclick="return confirm('Cancel this appointment?')">✕ Cancel</a>
                    <?php elseif ($a['status'] === 'approved'): ?>
                      <a href="?action=completed&id=<?= $appt_id ?><?= $status_f?'&status='.$status_f:'' ?>" class="btn-done"
                         onclick="return confirm('Mark as completed?')">✔ Done</a>
                      <a href="?action=cancelled&id=<?= $appt_id ?><?= $status_f?'&status='.$status_f:'' ?>" class="btn-cancel-sm"
                         onclick="return confirm('Cancel this appointment?')">✕ Cancel</a>
                    <?php else: ?>
                      <span style="font-size:12px;color:var(--text-muted);">—</span>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endwhile; endif; ?>
            </tbody>
          </table>
        </div>
        <?php if ($pages > 1): ?>
        <div class="pagination">
          <?php for ($i = 1; $i <= $pages; $i++): ?>
          <?php if ($i == $page): ?>
          <span class="active"><?= $i ?></span>
          <?php else: ?>
          <a href="?page=<?= $i ?><?= $status_f?'&status='.$status_f:'' ?><?= $search?'&search='.urlencode($search):'' ?><?= $date_f?'&date='.urlencode($date_f):'' ?>"><?= $i ?></a>
          <?php endif; ?>
          <?php endfor; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Detail Modal -->
<div class="modal-overlay" id="detailModal">
  <div class="modal-lg">
    <div class="modal-header">
      <div class="modal-title" id="modalTitle">Appointment Detail</div>
      <button class="modal-close" onclick="closeModal()">×</button>
    </div>
    <div class="modal-body" id="modalBody">
      <div style="text-align:center;padding:40px 0;color:var(--text-muted);">Loading…</div>
    </div>
    <div class="modal-footer" id="modalFooter"></div>
  </div>
</div>

<script>
function openModal(apptId) {
  document.getElementById('detailModal').classList.add('open');
  document.getElementById('modalBody').innerHTML = '<div style="text-align:center;padding:40px 0;color:var(--text-muted);">Loading employee info…</div>';
  fetch('ajax/appointment_detail.php?id=' + apptId)
    .then(r => r.json())
    .then(data => {
      renderModal(data);
    })
    .catch(() => {
      document.getElementById('modalBody').innerHTML = '<p style="color:red;padding:20px;">Failed to load details.</p>';
    });
}
function closeModal() {
  document.getElementById('detailModal').classList.remove('open');
}
document.getElementById('detailModal').addEventListener('click', function(e){
  if (e.target === this) closeModal();
});

function renderModal(d) {
  const a = d.appointment || {};
  const u = d.user || {};
  const v = d.vitals || {};
  const hr = d.health_record || {};

  document.getElementById('modalTitle').textContent = 'Appointment — ' + (u.full_name || '');

  const statusColor = {pending:'#f59e0b',approved:'#10b981',completed:'#3b82f6',cancelled:'#ef4444'};
  const sc = statusColor[a.status] || '#6b7280';

  let html = `
    <div class="section-divider">Appointment Info</div>
    <div class="info-grid">
      <div class="info-item"><label>Date</label><span>${a.appointment_date || '—'}</span></div>
      <div class="info-item"><label>Time</label><span>${a.appointment_time || '—'}</span></div>
      <div class="info-item"><label>Status</label><span style="color:${sc};text-transform:capitalize;">${a.status || '—'}</span></div>
      <div class="info-item"><label>Purpose</label><span>${a.purpose || '—'}</span></div>
    </div>
    ${a.notes ? `<div style="background:var(--gray-light);padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:18px;"><strong>Notes:</strong> ${a.notes}</div>` : ''}

    <div class="section-divider">Employee Information</div>
    <div class="info-grid">
      <div class="info-item"><label>Full Name</label><span>${u.full_name || '—'}</span></div>
      <div class="info-item"><label>Employee ID</label><span>${u.employee_id || '—'}</span></div>
      <div class="info-item"><label>Department</label><span>${u.department || '—'}</span></div>
      <div class="info-item"><label>Email</label><span>${u.email || '—'}</span></div>
    </div>`;

  if (v && Object.keys(v).length > 0) {
    html += `<div class="section-divider">Latest Vitals</div>
    <div class="vital-row">
      <div class="vital-chip"><div class="vc-label">BP</div><div class="vc-val">${v.blood_pressure || '—'}</div></div>
      <div class="vital-chip"><div class="vc-label">Heart Rate</div><div class="vc-val">${v.heart_rate ? v.heart_rate+' bpm' : '—'}</div></div>
      <div class="vital-chip"><div class="vc-label">Weight</div><div class="vc-val">${v.weight ? v.weight+' kg' : '—'}</div></div>
      <div class="vital-chip"><div class="vc-label">BMI</div><div class="vc-val">${v.bmi || '—'}</div></div>
      <div class="vital-chip"><div class="vc-label">Temp</div><div class="vc-val">${v.temperature ? v.temperature+'°C' : '—'}</div></div>
    </div>
    <div class="vital-row" style="margin-top:6px;">
      <div class="vital-chip"><div class="vc-label">Blood Sugar</div><div class="vc-val">${v.blood_sugar ? v.blood_sugar+' mg/dL' : '—'}</div></div>
      <div class="vital-chip"><div class="vc-label">Cholesterol</div><div class="vc-val">${v.cholesterol ? v.cholesterol+' mg/dL' : '—'}</div></div>
      <div class="vital-chip"><div class="vc-label">Uric Acid</div><div class="vc-val">${v.uric_acid ? v.uric_acid+' mg/dL' : '—'}</div></div>
    </div>`;
  } else {
    html += `<div class="section-divider">Latest Vitals</div><p class="no-data">No vitals recorded yet.</p>`;
  }

  if (hr && hr.health_status) {
    html += `<div class="section-divider" style="margin-top:16px;">Health Record</div>
    <div class="info-grid">
      <div class="info-item"><label>Health Status</label><span>${hr.health_status || '—'}</span></div>
      <div class="info-item"><label>Record Date</label><span>${hr.record_date || '—'}</span></div>
    </div>
    ${hr.diagnosis ? `<div style="background:var(--gray-light);padding:10px 14px;border-radius:8px;font-size:13px;"><strong>Diagnosis:</strong> ${hr.diagnosis}</div>` : ''}`;
  }

  document.getElementById('modalBody').innerHTML = html;

  // Footer actions
  let footer = `<button class="btn-outline" onclick="closeModal()">Close</button>`;
  if (a.status === 'pending') {
    footer += `<a href="?action=approved&id=${a.id}" class="btn-approve" onclick="return confirm('Approve?')">✓ Approve</a>`;
    footer += `<a href="?action=cancelled&id=${a.id}" class="btn-cancel-sm" onclick="return confirm('Cancel?')">✕ Cancel</a>`;
  } else if (a.status === 'approved') {
    footer += `<a href="?action=completed&id=${a.id}" class="btn-done" onclick="return confirm('Mark done?')">✔ Mark Done</a>`;
    footer += `<a href="?action=cancelled&id=${a.id}" class="btn-cancel-sm" onclick="return confirm('Cancel?')">✕ Cancel</a>`;
  }
  document.getElementById('modalFooter').innerHTML = footer;
}
</script>
</body>
</html>