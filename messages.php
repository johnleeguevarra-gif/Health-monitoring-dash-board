<?php
require_once '../includes/auth.php'; requireAdmin();
require_once '../includes/functions.php';
$uid = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $receiver_id = intval($_POST['receiver_id'] ?? 0);
    $subject = sanitize($conn, $_POST['subject'] ?? '');
    $message = sanitize($conn, $_POST['message'] ?? '');
    if ($receiver_id && $message) {
        $conn->query("INSERT INTO messages (sender_id, receiver_id, subject, message, sent_at)
            VALUES ($uid, $receiver_id, '$subject', '$message', NOW())");
        $conn->query("INSERT INTO notifications (user_id, type, message, created_at)
            VALUES ($receiver_id, 'system', 'You have a new message from the admin/clinic.', NOW())");
        header('Location: messages.php?sent=1&thread='.$receiver_id);
        exit;
    }
}

if (isset($_GET['thread'])) {
    $other_id = intval($_GET['thread']);
    $conn->query("UPDATE messages SET is_read=1 WHERE receiver_id=$uid AND sender_id=$other_id AND is_read=0");
}

$thread_user = null;
$thread_msgs = [];
if (isset($_GET['thread'])) {
    $other_id = intval($_GET['thread']);
    $thread_user = $conn->query("SELECT id, full_name, role, employee_id FROM users WHERE id=$other_id")->fetch_assoc();
    if ($thread_user) {
        $res = $conn->query("SELECT m.*, u.full_name as sender_name
            FROM messages m JOIN users u ON u.id=m.sender_id
            WHERE (m.sender_id=$uid AND m.receiver_id=$other_id)
               OR (m.sender_id=$other_id AND m.receiver_id=$uid)
            ORDER BY m.sent_at ASC");
        $thread_msgs = $res->fetch_all(MYSQLI_ASSOC);
    }
}

$threads_q = $conn->query("SELECT
    u.id, u.full_name, u.role, u.employee_id,
    m.message as last_message, m.sent_at as last_time,
    m.sender_id,
    SUM(CASE WHEN m2.receiver_id=$uid AND m2.is_read=0 THEN 1 ELSE 0 END) as unread_count
    FROM (
      SELECT * FROM messages
      WHERE sender_id=$uid OR receiver_id=$uid
      ORDER BY sent_at DESC
    ) m
    JOIN users u ON u.id = IF(m.sender_id=$uid, m.receiver_id, m.sender_id)
    LEFT JOIN messages m2 ON m2.sender_id=u.id AND m2.receiver_id=$uid AND m2.is_read=0
    GROUP BY u.id
    ORDER BY m.sent_at DESC");
$threads = $threads_q ? $threads_q->fetch_all(MYSQLI_ASSOC) : [];

$users = $conn->query("SELECT id, full_name, role, employee_id FROM users WHERE id != $uid ORDER BY role ASC, full_name ASC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Messages — OMSC Health Monitor</title>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
.msg-layout{display:grid;grid-template-columns:320px 1fr;height:calc(100vh - var(--topbar-h));overflow:hidden;}
.msg-sidebar{border-right:1px solid var(--gray-mid);background:var(--white);display:flex;flex-direction:column;overflow:hidden;}
.msg-sidebar-header{padding:16px 18px;border-bottom:1px solid var(--gray-mid);display:flex;align-items:center;justify-content:space-between;}
.msg-sidebar-header h3{font-size:14px;font-weight:700;}
.msg-search{padding:12px 16px;border-bottom:1px solid var(--gray-light);}
.msg-search input{width:100%;padding:8px 12px;border:1.5px solid var(--gray-mid);border-radius:8px;font-size:13px;font-family:inherit;outline:none;background:var(--gray-light);}
.msg-search input:focus{border-color:var(--accent);background:var(--white);}
.thread-list{flex:1;overflow-y:auto;}
.thread-item{display:flex;gap:12px;padding:14px 18px;border-bottom:1px solid var(--gray-light);cursor:pointer;transition:background .15s;align-items:flex-start;}
.thread-item:hover{background:var(--gray-light);}
.thread-item.active{background:var(--accent-light);border-left:3px solid var(--accent);}
.thread-item.unread{background:#f0f7ff;}
.t-avatar{width:40px;height:40px;border-radius:50%;background:var(--navy);color:white;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0;}
.t-avatar.admin{background:var(--accent);}
.t-info{flex:1;min-width:0;}
.t-name{font-size:13.5px;font-weight:700;display:flex;align-items:center;justify-content:space-between;}
.t-name .t-time{font-size:10px;color:var(--text-muted);font-weight:400;}
.t-preview{font-size:12px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;}
.t-unread-badge{background:var(--accent);color:white;font-size:10px;font-weight:700;padding:2px 6px;border-radius:10px;margin-left:6px;white-space:nowrap;}
.msg-main{display:flex;flex-direction:column;overflow:hidden;background:var(--gray-light);}
.chat-header{background:var(--white);padding:14px 24px;border-bottom:1px solid var(--gray-mid);display:flex;align-items:center;gap:14px;}
.chat-user-info h4{font-size:14px;font-weight:700;}
.chat-user-info p{font-size:12px;color:var(--text-muted);}
.chat-messages{flex:1;overflow-y:auto;padding:20px 24px;display:flex;flex-direction:column;gap:12px;}
.msg-bubble{max-width:65%;display:flex;flex-direction:column;}
.msg-bubble.sent{align-self:flex-end;align-items:flex-end;}
.msg-bubble.received{align-self:flex-start;align-items:flex-start;}
.bubble-content{padding:12px 16px;border-radius:16px;font-size:13.5px;line-height:1.5;}
.msg-bubble.sent .bubble-content{background:var(--navy);color:white;border-bottom-right-radius:4px;}
.msg-bubble.received .bubble-content{background:var(--white);color:var(--text-dark);border-bottom-left-radius:4px;box-shadow:var(--shadow-sm);}
.bubble-meta{font-size:10px;color:var(--text-muted);margin-top:4px;}
.bubble-subject{font-size:11px;font-weight:700;margin-bottom:5px;opacity:.75;}
.chat-input{background:var(--white);padding:16px 24px;border-top:1px solid var(--gray-mid);}
.chat-input-row{display:flex;gap:10px;align-items:flex-end;}
.chat-input textarea{flex:1;padding:11px 14px;border:1.5px solid var(--gray-mid);border-radius:12px;font-size:13.5px;font-family:inherit;resize:none;min-height:44px;max-height:120px;transition:border-color .2s;outline:none;}
.chat-input textarea:focus{border-color:var(--accent);}
.send-btn{background:var(--accent);color:white;border:none;width:44px;height:44px;border-radius:12px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .2s;}
.send-btn:hover{background:#1976d2;}
.empty-chat{flex:1;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:12px;color:var(--text-muted);}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(9,30,62,.5);z-index:200;align-items:center;justify-content:center;padding:20px;}
.modal-overlay.open{display:flex;}
.modal{background:var(--white);border-radius:var(--radius-lg);width:100%;max-width:560px;}
.modal-header{padding:20px 24px;border-bottom:1px solid var(--gray-mid);display:flex;align-items:center;justify-content:space-between;}
.modal-title{font-size:16px;font-weight:700;}
.modal-close{background:none;border:none;cursor:pointer;font-size:22px;color:var(--text-muted);}
.modal-body{padding:20px 24px;}
.modal-footer{padding:16px 24px;border-top:1px solid var(--gray-mid);display:flex;justify-content:flex-end;gap:10px;}
.form-group{margin-bottom:16px;}
.form-group label{display:block;font-size:12.5px;font-weight:600;margin-bottom:6px;color:var(--text-dark);}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:10px 14px;border:1.5px solid var(--gray-mid);border-radius:8px;font-size:13.5px;color:var(--text-dark);background:var(--white);font-family:inherit;transition:border-color .2s;}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(33,150,243,.12);}
.form-group textarea{resize:vertical;min-height:100px;}
.alert{padding:12px 16px;border-radius:8px;font-size:13.5px;margin:0 28px 16px;font-weight:500;}
.alert-success{background:var(--success-bg);color:#065f46;border-left:3px solid var(--success);}
</style>
</head>
<body>
<div class="app-layout">
  <?php include '../includes/sidebar-admin.php'; ?>
  <div class="main-content">
    <div class="topbar">
      <div class="topbar-left">
        <h2>Messages</h2>
        <p>Communicate directly with employees and staff</p>
      </div>
      <div class="topbar-right">
        <button class="btn-accent" onclick="document.getElementById('modalCompose').classList.add('open')">New Message</button>
      </div>
    </div>

    <?php if(isset($_GET['sent'])): ?>
    <div class="alert alert-success">Message sent successfully.</div>
    <?php endif; ?>

    <div class="msg-layout">
      <div class="msg-sidebar">
        <div class="msg-sidebar-header">
          <h3>Conversations</h3>
          <span style="font-size:11px;color:var(--text-muted);"><?= count($threads) ?> thread(s)</span>
        </div>
        <div class="msg-search">
          <input type="text" id="threadSearch" placeholder="Search conversations..." oninput="filterThreads(this.value)">
        </div>
        <div class="thread-list" id="threadList">
          <?php if(empty($threads)): ?>
          <div style="padding:32px 20px;text-align:center;color:var(--text-muted);">No conversations yet.</div>
          <?php else: foreach($threads as $t):
            $is_active = isset($_GET['thread']) && intval($_GET['thread']) === intval($t['id']);
            $t_init = strtoupper(substr($t['full_name'],0,1));
            $preview = strlen($t['last_message']) > 45 ? substr($t['last_message'],0,45).'...' : $t['last_message'];
          ?>
          <a href="messages.php?thread=<?= $t['id'] ?>" class="thread-item <?= $is_active?'active':'' ?> <?= $t['unread_count']>0?'unread':'' ?>">
            <div class="t-avatar <?= $t['role']==='admin'?'admin':'' ?>"><?= $t_init ?></div>
            <div class="t-info">
              <div class="t-name">
                <span><?= htmlspecialchars($t['full_name']) ?></span>
                <span class="t-time"><?= date('M d', strtotime($t['last_time'])) ?></span>
              </div>
              <div class="t-preview">
                <?= $t['sender_id']==$uid ? '<span style="color:var(--text-muted)">You: </span>' : '' ?><?= htmlspecialchars($preview) ?>
              </div>
              <?php if($t['unread_count']>0): ?>
              <span class="t-unread-badge"><?= $t['unread_count'] ?> new</span>
              <?php endif; ?>
            </div>
          </a>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <div class="msg-main">
        <?php if($thread_user): ?>
        <div class="chat-header">
          <div style="width:42px;height:42px;border-radius:50%;background:<?= $thread_user['role']==='admin'?'var(--accent)':'var(--navy)' ?>;color:white;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;"><?= strtoupper(substr($thread_user['full_name'],0,1)) ?></div>
          <div class="chat-user-info">
            <h4><?= htmlspecialchars($thread_user['full_name']) ?></h4>
            <p><?= ucfirst($thread_user['role']) ?> <?= $thread_user['employee_id'] ? '· '.$thread_user['employee_id'] : '' ?></p>
          </div>
        </div>
        <div class="chat-messages" id="chatMessages">
          <?php if(empty($thread_msgs)): ?>
          <div style="text-align:center;color:var(--text-muted);">Start your conversation with <?= htmlspecialchars($thread_user['full_name']) ?>.</div>
          <?php else: foreach($thread_msgs as $m):
            $is_sent = $m['sender_id'] == $uid;
          ?>
          <div class="msg-bubble <?= $is_sent?'sent':'received' ?>">
            <?php if($m['subject']): ?><div class="bubble-subject">Subject: <?= htmlspecialchars($m['subject']) ?></div><?php endif; ?>
            <div class="bubble-content"><?= nl2br(htmlspecialchars($m['message'])) ?></div>
            <div class="bubble-meta"><?= $is_sent ? 'You' : htmlspecialchars($m['sender_name']) ?> · <?= date('M d, g:i A', strtotime($m['sent_at'])) ?></div>
          </div>
          <?php endforeach; endif; ?>
        </div>
        <div class="chat-input">
          <form method="POST" id="replyForm">
            <input type="hidden" name="receiver_id" value="<?= $thread_user['id'] ?>">
            <input type="hidden" name="send_message" value="1">
            <div class="chat-input-row">
              <textarea name="message" id="replyBox" placeholder="Type your message..." rows="1" required></textarea>
              <button type="submit" class="send-btn">➤</button>
            </div>
          </form>
        </div>
        <?php else: ?>
        <div class="empty-chat">
          <h3 style="font-size:16px;font-weight:700;color:var(--text-dark);">Select a conversation</h3>
          <p style="font-size:13px;">Choose a thread on the left or start a new message.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="modal-overlay" id="modalCompose">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">New Message</div>
      <button class="modal-close" onclick="document.getElementById('modalCompose').classList.remove('open')">×</button>
    </div>
    <form method="POST">
      <input type="hidden" name="send_message" value="1">
      <div class="modal-body">
        <div class="form-group">
          <label>Send To *</label>
          <select name="receiver_id" required>
            <option value="">- Select recipient -</option>
            <?php foreach($users as $u): ?>
            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['full_name']) ?> (<?= ucfirst($u['role']) ?><?= $u['employee_id'] ? ' - '.$u['employee_id'] : '' ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Subject</label>
          <input type="text" name="subject" placeholder="e.g. Appointment follow-up">
        </div>
        <div class="form-group">
          <label>Message *</label>
          <textarea name="message" rows="5" placeholder="Write your message here..." required></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-outline" onclick="document.getElementById('modalCompose').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn-primary">Send Message</button>
      </div>
    </form>
  </div>
</div>

<script>
const chatMsgs = document.getElementById('chatMessages');
if (chatMsgs) chatMsgs.scrollTop = chatMsgs.scrollHeight;

const replyBox = document.getElementById('replyBox');
if (replyBox) {
  replyBox.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      document.getElementById('replyForm').submit();
    }
  });
  replyBox.addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 120) + 'px';
  });
}

function filterThreads(q) {
  q = q.toLowerCase();
  document.querySelectorAll('.thread-item').forEach(item => {
    const name = item.querySelector('.t-name')?.textContent?.toLowerCase() || '';
    const preview = item.querySelector('.t-preview')?.textContent?.toLowerCase() || '';
    item.style.display = (!q || name.includes(q) || preview.includes(q)) ? '' : 'none';
  });
}

document.getElementById('modalCompose').addEventListener('click', function(e) {
  if (e.target === this) this.classList.remove('open');
});
</script>
</body>
</html>
