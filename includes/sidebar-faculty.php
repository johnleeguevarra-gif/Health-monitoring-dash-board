<?php
$current_page = basename($_SERVER['PHP_SELF']);
$uid = $_SESSION['user_id'];
$user_info = $conn->query("SELECT full_name, role, employee_id FROM users WHERE id=$uid")->fetch_assoc();
$initials = '';
foreach(explode(' ', $user_info['full_name']) as $w) $initials .= strtoupper($w[0]);
$initials = substr($initials, 0, 2);

$unread_msgs = $conn->query("SELECT COUNT(*) FROM messages WHERE receiver_id=$uid AND is_read=0")->fetch_row()[0] ?? 0;
$unread_notifs = $conn->query("SELECT COUNT(*) FROM notifications WHERE user_id=$uid AND is_read=0")->fetch_row()[0] ?? 0;
?>
<aside class="sidebar">
  <div class="sidebar-logo">
    <img src="../assets/img/omsc-logo.png" alt="OMSC Seal"
         style="width:42px;height:42px;border-radius:8px;object-fit:contain;background:white;padding:3px;flex-shrink:0;">
    <div>
      <span class="logo-title">Health Monitoring</span>
      <span class="logo-sub">OMSC San Jose Campus</span>
    </div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section-label">Main Menu</div>

    <a href="dashboard.php" class="nav-item <?= $current_page==='dashboard.php'?'active':'' ?>">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
      Dashboard
    </a>

    <div class="nav-section-label">Health</div>

    <a href="my-health.php" class="nav-item <?= $current_page==='my-health.php'?'active':'' ?>">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
      My Health Records
    </a>

    <a href="lab-results.php" class="nav-item <?= $current_page==='lab-results.php'?'active':'' ?>">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
      Lab Results
    </a>

    <div class="nav-section-label">Appointments</div>

    <a href="appointments.php" class="nav-item <?= $current_page==='appointments.php'?'active':'' ?>">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
      My Appointments
    </a>

    <div class="nav-section-label">Communication</div>

    <a href="messages.php" class="nav-item <?= $current_page==='messages.php'?'active':'' ?>">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
      Messages
      <?php if($unread_msgs > 0): ?>
      <span class="nav-badge"><?= $unread_msgs ?></span>
      <?php endif; ?>
    </a>

    <div class="nav-section-label">Account</div>

    <a href="profile.php" class="nav-item <?= $current_page==='profile.php'?'active':'' ?>">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
      My Profile
    </a>
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="avatar"><?= $initials ?></div>
      <div class="info">
        <span><?= htmlspecialchars(explode(' ', $user_info['full_name'])[0]) ?></span>
        <small><?= ucfirst($user_info['role']) ?> <?= $user_info['employee_id'] ? '· '.$user_info['employee_id'] : '' ?></small>
      </div>
    </div>
    <a href="../logout.php" class="logout-link">
      <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
      Sign Out
    </a>
  </div>
</aside>