<?php
require_once __DIR__ . '/functions.php';
$current_page = basename($_SERVER['PHP_SELF']);
$uid = $_SESSION['user_id'] ?? 0;
$unread_msgs = 0;
if ($uid) {
  $unread_msgs = $conn->query("SELECT COUNT(*) FROM messages WHERE receiver_id=$uid AND is_read=0")->fetch_row()[0] ?? 0;
}
?>
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-icon">
      <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
        <rect width="28" height="28" rx="8" fill="rgba(255,255,255,0.15)"/>
        <path d="M14 6v4M14 18v4M6 14h4M18 14h4" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
        <circle cx="14" cy="14" r="4" stroke="white" stroke-width="1.5"/>
        <circle cx="14" cy="14" r="1.5" fill="white"/>
      </svg>
    </div>
    <div>
      <span class="logo-title">Health Monitoring</span>
      <span class="logo-sub">OMSC San Jose Campus</span>
    </div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section-label">Main</div>
    <a href="/omsc-health/admin/dashboard.php" class="nav-item <?= $current_page=='dashboard.php'?'active':'' ?>">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
      Dashboard
    </a>
    <a href="/omsc-health/admin/employees.php" class="nav-item <?= $current_page=='employees.php'?'active':'' ?>">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0"/></svg>
      Employees
    </a>
    <a href="/omsc-health/admin/health-records.php" class="nav-item <?= $current_page=='health-records.php'?'active':'' ?>">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
      Health Records
    </a>
    <a href="/omsc-health/admin/appointments.php" class="nav-item <?= $current_page=='appointments.php'?'active':'' ?>">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
      Appointments
    </a>
    <a href="/omsc-health/admin/reports.php" class="nav-item <?= $current_page=='reports.php'?'active':'' ?>">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
      Reports
    </a>
    <a href="/omsc-health/admin/messages.php" class="nav-item <?= $current_page=='messages.php'?'active':'' ?>">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
      Messages
      <?php if($unread_msgs > 0): ?>
      <span class="nav-badge"><?= $unread_msgs ?></span>
      <?php endif; ?>
    </a>
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="avatar"><?= initials($_SESSION['full_name'] ?? 'AD') ?></div>
      <div class="info">
        <span><?= htmlspecialchars($_SESSION['full_name'] ?? 'Admin') ?></span>
        <small>Administrator</small>
      </div>
    </div>
    <a href="/omsc-health/logout.php" class="logout-link">
      <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
      Sign out
    </a>
  </div>
</aside>