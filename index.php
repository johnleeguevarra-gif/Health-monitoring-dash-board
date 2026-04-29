<?php require_once 'includes/config.php'; ?>
<?php
if (isLoggedIn()) {
    header("Location: " . (isAdmin() ? 'admin/dashboard.php' : 'faculty/dashboard.php'));
    exit();
}

// ── CSRF token ───────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$otp_notice  = '';
$error       = '';
$show_otp_form = !empty($_SESSION['pending_login']);
$mail_error  = '';

// ── Registered-just-now banner ────────────────────────────────────────────────
$just_registered = isset($_GET['registered']) && $_GET['registered'] == '1';
$new_emp_id      = htmlspecialchars($_GET['your_id'] ?? '', ENT_QUOTES, 'UTF-8');

// ── OTP email helper ─────────────────────────────────────────────────────────
function sendOtpEmail($to_email, $full_name, $otp_code) {
    global $mail_error;
    $mail_error = '';

    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        $mail_error = 'PHPMailer not installed.';
        return false;
    }
    require_once $autoload;

    if (MAIL_USERNAME === 'your-email@gmail.com' || MAIL_PASSWORD === 'your-app-password') {
        $mail_error = 'SMTP credentials are still placeholders. Update config.php.';
        return false;
    }

    $safe_name = htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8');
    $subject   = "Your OMSC Health Login OTP";
    $body      = "
    <html><body style='font-family:Arial,sans-serif;line-height:1.6;color:#0e1c2f;'>
      <h2>Health Monitoring Web System of OMSC San Jose Campus</h2>
      <p>Hello {$safe_name},</p>
      <p>Your one-time password (OTP) for login is:</p>
      <p style='font-size:32px;font-weight:700;letter-spacing:6px;margin:18px 0;color:#0a2342;'>{$otp_code}</p>
      <p>This code expires in <strong>5 minutes</strong>. If you did not request this, please ignore this email.</p>
    </body></html>";
    $alt = "Hello {$full_name},\n\nYour OMSC Health login OTP is: {$otp_code}\n\nExpires in 5 minutes.";

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Timeout    = defined('MAIL_TIMEOUT') ? MAIL_TIMEOUT : 30;
        $mail->Host       = MAIL_HOST;
        $mail->Port       = MAIL_PORT;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = preg_replace('/\s+/', '', MAIL_PASSWORD);
        $mail->SMTPSecure = MAIL_ENCRYPTION === 'ssl'
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPDebug  = (defined('MAIL_DEBUG') && MAIL_DEBUG)
            ? \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER
            : \PHPMailer\PHPMailer\SMTP::DEBUG_OFF;
        $mail->Debugoutput = static function ($str) use (&$mail_error) {
            $mail_error .= trim((string)$str) . "\n";
        };
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($to_email, $full_name);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = $alt;
        $sent = $mail->send();
        if (!$sent) $mail_error = $mail->ErrorInfo ?: 'Unknown PHPMailer failure.';
        return $sent;
    } catch (\Throwable $e) {
        $mail_error = $e->getMessage();
        return false;
    }
}

function createAndSendOtp($user) {
    $otp_code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $sent = sendOtpEmail($user['email'], $user['full_name'], $otp_code);
    if ($sent) {
        $_SESSION['pending_login'] = [
            'user_id'      => $user['id'],
            'full_name'    => $user['full_name'],
            'role'         => $user['role'],
            'employee_id'  => $user['employee_id'],
            'email'        => $user['email'],
            'otp_hash'     => hash('sha256', $otp_code),
            'otp_expires'  => time() + 300,
            'otp_attempts' => 0,
        ];
    }
    return $sent;
}

// ── POST handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF guard (skip for resend_otp which also posts the token)
    $posted_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $posted_csrf)) {
        $error = "Invalid request. Please refresh and try again.";
        goto render;
    }
    // Rotate token after each POST
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $action = $_POST['action'] ?? 'login';

    // ── Verify OTP ───────────────────────────────────────────────────────────
    if ($action === 'verify_otp') {
        $otp_input = trim($_POST['otp_code'] ?? '');
        if (empty($_SESSION['pending_login'])) {
            $error = "Your login session has expired. Please sign in again.";
        } elseif (!preg_match('/^\d{6}$/', $otp_input)) {
            $error = "Enter a valid 6-digit OTP code.";
        } else {
            $pending = $_SESSION['pending_login'];
            if (time() > (int)$pending['otp_expires']) {
                unset($_SESSION['pending_login']);
                $error = "OTP has expired. Please sign in again.";
            } else {
                $_SESSION['pending_login']['otp_attempts'] = ((int)$pending['otp_attempts']) + 1;
                if ($_SESSION['pending_login']['otp_attempts'] > 5) {
                    unset($_SESSION['pending_login']);
                    $error = "Too many invalid attempts. Please sign in again.";
                } elseif (hash_equals($pending['otp_hash'], hash('sha256', $otp_input))) {
                    $_SESSION['user_id']     = $pending['user_id'];
                    $_SESSION['full_name']   = $pending['full_name'];
                    $_SESSION['role']        = $pending['role'];
                    $_SESSION['employee_id'] = $pending['employee_id'];
                    unset($_SESSION['pending_login']);
                    header("Location: " . ($pending['role'] === 'admin' ? 'admin/dashboard.php' : 'faculty/dashboard.php'));
                    exit();
                } else {
                    $remaining = max(0, 5 - (int)$_SESSION['pending_login']['otp_attempts']);
                    $error = "Invalid OTP code. {$remaining} attempt(s) left.";
                }
            }
        }

    // ── Resend OTP ───────────────────────────────────────────────────────────
    } elseif ($action === 'resend_otp') {
        if (empty($_SESSION['pending_login'])) {
            $error = "Your login session has expired. Please sign in again.";
        } else {
            $pending = $_SESSION['pending_login'];
            $ok = createAndSendOtp([
                'id'          => $pending['user_id'],
                'full_name'   => $pending['full_name'],
                'role'        => $pending['role'],
                'employee_id' => $pending['employee_id'],
                'email'       => $pending['email'],
            ]);
            if ($ok) {
                $otp_notice = "A new OTP has been sent to your email.";
            } else {
                $error = "Unable to resend OTP email right now. Please try again.";
                if (!empty($mail_error)) {
                    $error .= " (Error: " . htmlspecialchars($mail_error, ENT_QUOTES, 'UTF-8') . ")";
                }
            }
        }

    // ── Login ────────────────────────────────────────────────────────────────
    } else {
        $employee_id = trim($_POST['employee_id'] ?? '');
        $password    = $_POST['password'] ?? '';

        // Prepared statement — safe against SQL injection
        $stmt = $conn->prepare("SELECT * FROM users WHERE employee_id = ? AND status = 'active' LIMIT 1");
        $stmt->bind_param('s', $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user   = $result->fetch_assoc();
        $stmt->close();

        $valid = false;
        if ($user) {
            $stored = $user['password'];
            // Support both password_hash (bcrypt) and legacy MD5
            if (strlen($stored) === 32 && ctype_xdigit($stored)) {
                // Legacy MD5 — verify then upgrade
                if (hash_equals($stored, md5($password))) {
                    $valid = true;
                    // Upgrade to bcrypt silently
                    $new_hash = password_hash($password, PASSWORD_BCRYPT);
                    $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $upd->bind_param('si', $new_hash, $user['id']);
                    $upd->execute();
                    $upd->close();
                }
            } else {
                $valid = password_verify($password, $stored);
            }
        }

        if ($valid) {
            if (empty($user['email'])) {
                $error = "No email is linked to this account. Please contact the administrator.";
            } else {
                $sent = createAndSendOtp($user);
                if ($sent) {
                    $masked = preg_replace('/(?<=.{3}).(?=.*@)/', '*', $user['email']);
                    $otp_notice  = "OTP sent to {$masked}. Enter it below to continue.";
                    $show_otp_form = true;
                } else {
                    $error = "Login verified, but OTP email could not be sent. Please try again.";
                    if (!empty($mail_error)) {
                        $error .= " (Error: " . htmlspecialchars($mail_error, ENT_QUOTES, 'UTF-8') . ")";
                    }
                }
            }
        } else {
            // Constant-time sleep to deter brute-force timing attacks
            usleep(300000);
            $error = "Invalid Employee ID or password. Please try again.";
        }
    }
}

$show_otp_form = !empty($_SESSION['pending_login']);

function isLoggedIn() { return isset($_SESSION['user_id']); }
function isAdmin()    { return isset($_SESSION['role']) && $_SESSION['role'] === 'admin'; }

render:
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign In — Health Monitoring Web System of OMSC San Jose Campus</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

    :root {
      --navy:       #0a2342;
      --navy-mid:   #0d2f5c;
      --navy-light: #1a4080;
      --blue:       #1d6fc4;
      --blue-light: #2d84e8;
      --white:      #ffffff;
      --off-white:  #f7f9fc;
      --gray-100:   #edf1f7;
      --gray-200:   #d4dcea;
      --gray-400:   #8899b4;
      --gray-600:   #4a5f7d;
      --text:       #0e1c2f;
      --danger:     #e03434;
      --danger-bg:  #fff0f0;
      --success:    #0d8a5a;
      --radius:     14px;
      --radius-sm:  8px;
    }

    html, body {
      height: 100%;
      font-family: 'DM Sans', sans-serif;
      background: var(--navy);
      overflow: hidden;
    }

    .page {
      display: flex;
      height: 100vh;
      position: relative;
      overflow: hidden;
    }

    /* ── BLOBS ── */
    .blob {
      position: absolute;
      border-radius: 50%;
      filter: blur(80px);
      opacity: 0.25;
      pointer-events: none;
      z-index: 0;
      animation: drift 12s ease-in-out infinite alternate;
    }
    .blob-1 { width: 500px; height: 500px; background: var(--blue-light); top: -150px; left: -100px; animation-delay: 0s; }
    .blob-2 { width: 350px; height: 350px; background: #3fa4ff; bottom: -100px; left: 15%; animation-delay: -4s; }
    .blob-3 { width: 280px; height: 280px; background: var(--navy-light); top: 40%; right: 65%; animation-delay: -8s; }
    @keyframes drift {
      0%   { transform: translate(0, 0) scale(1); }
      100% { transform: translate(30px, 20px) scale(1.08); }
    }

    /* ── LEFT PANEL ── */
    .panel-left {
      width: 40%;
      flex-shrink: 0;
      display: flex;
      flex-direction: column;
      justify-content: center;
      padding: 52px 56px;
      position: relative;
      z-index: 1;
      color: white;
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 14px;
      margin-bottom: 56px;
    }
    .brand-icon {
      width: 44px; height: 44px;
      background: rgba(255,255,255,0.12);
      border: 1px solid rgba(255,255,255,0.2);
      border-radius: 12px;
      display: flex; align-items: center; justify-content: center;
      backdrop-filter: blur(8px);
    }
    .brand-name {
      font-family: 'DM Serif Display', serif;
      font-size: 20px;
      color: white;
      letter-spacing: -0.3px;
    }
    .brand-sub {
      font-size: 11px;
      color: rgba(255,255,255,0.45);
      margin-top: 1px;
      letter-spacing: 0.5px;
      text-transform: uppercase;
    }

    .hero-tag {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: rgba(255,255,255,0.08);
      border: 1px solid rgba(255,255,255,0.15);
      border-radius: 20px;
      padding: 5px 14px;
      font-size: 11px;
      color: rgba(255,255,255,0.65);
      letter-spacing: 0.8px;
      text-transform: uppercase;
      margin-bottom: 20px;
    }
    .hero-dot { width: 6px; height: 6px; border-radius: 50%; background: #4de0b0; animation: pulse 2s ease-in-out infinite; }
    @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:0.6;transform:scale(1.3)} }

    .hero-title {
      font-family: 'DM Serif Display', serif;
      font-size: clamp(30px, 3vw, 44px);
      line-height: 1.1;
      color: white;
      margin-bottom: 16px;
      letter-spacing: -1px;
    }
    .hero-title em { font-style: italic; color: rgba(255,255,255,0.55); }

    .hero-desc {
      font-size: 13.5px;
      color: rgba(255,255,255,0.55);
      line-height: 1.75;
      max-width: 340px;
      margin-bottom: 40px;
    }

    .features { display: flex; flex-direction: column; gap: 16px; }
    .feature {
      display: flex;
      align-items: flex-start;
      gap: 14px;
      animation: fadeUp 0.6s ease both;
    }
    .feature:nth-child(1) { animation-delay: 0.1s; }
    .feature:nth-child(2) { animation-delay: 0.2s; }
    .feature:nth-child(3) { animation-delay: 0.3s; }
    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(12px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    .feature-icon {
      width: 36px; height: 36px;
      border-radius: 10px;
      background: rgba(255,255,255,0.08);
      border: 1px solid rgba(255,255,255,0.12);
      display: flex; align-items: center; justify-content: center;
      font-size: 15px;
      flex-shrink: 0;
    }
    .feature-text strong { display: block; font-size: 13px; font-weight: 600; color: rgba(255,255,255,0.85); }
    .feature-text span   { font-size: 12px; color: rgba(255,255,255,0.4); margin-top: 2px; display: block; }

    /* ── DIVIDER ── */
    .divider {
      width: 1px;
      background: rgba(255,255,255,0.08);
      position: relative;
      z-index: 1;
      flex-shrink: 0;
    }

    /* ── RIGHT PANEL ── */
    .panel-right {
      flex: 1;
      background: var(--white);
      display: flex;
      flex-direction: column;
      justify-content: center;
      padding: 52px 72px;
      position: relative;
      z-index: 1;
      overflow-y: auto;
    }
    .panel-right::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 4px;
      background: linear-gradient(90deg, var(--navy), var(--blue), var(--blue-light));
    }

    .form-inner { max-width: 480px; width: 100%; }

    /* ── TAB SWITCHER ── */
    .auth-tabs {
      display: flex;
      background: var(--gray-100);
      border-radius: 10px;
      padding: 4px;
      margin-bottom: 36px;
      position: relative;
    }
    .tab-pill {
      position: absolute;
      top: 4px; bottom: 4px;
      width: calc(50% - 4px);
      left: 4px;
      background: white;
      border-radius: 7px;
      box-shadow: 0 2px 8px rgba(10,35,66,0.1);
      transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    .tab-btn {
      flex: 1;
      text-align: center;
      padding: 9px 0;
      font-size: 13.5px;
      font-weight: 600;
      color: var(--gray-400);
      cursor: pointer;
      position: relative;
      z-index: 1;
      transition: color 0.2s;
      text-decoration: none;
      display: block;
      border-radius: 7px;
      font-family: 'DM Sans', sans-serif;
    }
    .tab-btn.active { color: var(--navy); }
    .tab-btn:hover:not(.active) { color: var(--gray-600); }

    /* ── FORM HEADING ── */
    .form-heading { margin-bottom: 28px; }
    .form-heading h2 {
      font-family: 'DM Serif Display', serif;
      font-size: 28px;
      color: var(--text);
      letter-spacing: -0.5px;
      margin-bottom: 5px;
    }
    .form-heading p { font-size: 13px; color: var(--gray-400); }

    /* ── FORM ELEMENTS ── */
    .form-group { margin-bottom: 18px; }
    .form-group label {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 12.5px;
      font-weight: 600;
      color: var(--gray-600);
      margin-bottom: 6px;
      letter-spacing: 0.2px;
    }
    .label-link {
      font-size: 11.5px;
      font-weight: 600;
      color: var(--blue);
      text-decoration: none;
    }
    .label-link:hover { text-decoration: underline; }

    .input-wrap { position: relative; }
    .input-wrap .icon-left {
      position: absolute;
      left: 14px; top: 50%; transform: translateY(-50%);
      color: var(--gray-400);
      pointer-events: none;
    }
    .input-wrap input {
      width: 100%;
      padding: 12px 42px 12px 42px;
      border: 1.5px solid var(--gray-200);
      border-radius: var(--radius-sm);
      font-size: 14px;
      font-family: 'DM Sans', sans-serif;
      color: var(--text);
      background: var(--off-white);
      transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
      outline: none;
    }
    .input-wrap input:focus {
      border-color: var(--blue);
      background: white;
      box-shadow: 0 0 0 3px rgba(29,111,196,0.1);
    }
    .input-wrap input::placeholder { color: var(--gray-400); font-size: 13px; }

    /* Show/hide password toggle */
    .toggle-pw {
      position: absolute;
      right: 13px; top: 50%; transform: translateY(-50%);
      background: none;
      border: none;
      cursor: pointer;
      color: var(--gray-400);
      padding: 4px;
      display: flex; align-items: center;
      transition: color 0.2s;
    }
    .toggle-pw:hover { color: var(--blue); }

    /* ── ALERT ── */
    .alert {
      padding: 11px 14px;
      border-radius: var(--radius-sm);
      font-size: 13px;
      font-weight: 500;
      margin-bottom: 20px;
      display: flex;
      align-items: flex-start;
      gap: 8px;
    }
    .alert svg { flex-shrink: 0; margin-top: 1px; }
    .alert-danger  { background: var(--danger-bg); color: var(--danger); border: 1px solid #ffd0d0; }
    .alert-success { background: #e8faf3; color: var(--success); border: 1px solid #b3ecd6; }
    .alert-info    { background: #eef6ff; color: var(--blue); border: 1px solid #c5dcf8; }

    .otp-hint { font-size: 12px; color: var(--gray-400); margin-top: -10px; margin-bottom: 16px; line-height: 1.6; }
    .otp-actions { margin-top: 14px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
    .btn-link {
      background: transparent;
      border: none;
      color: var(--blue);
      font-size: 12.5px;
      font-weight: 600;
      cursor: pointer;
      padding: 0;
      font-family: 'DM Sans', sans-serif;
    }
    .btn-link:hover { text-decoration: underline; }
    .btn-link.muted { color: var(--gray-400); }

    /* Password strength bar */
    .strength-bar-wrap {
      margin-top: 6px;
      height: 3px;
      background: var(--gray-200);
      border-radius: 2px;
      overflow: hidden;
    }
    .strength-bar {
      height: 100%;
      width: 0%;
      border-radius: 2px;
      transition: width 0.3s, background 0.3s;
    }

    /* ── SUBMIT BUTTON ── */
    .btn-submit {
      width: 100%;
      padding: 14px;
      background: var(--navy);
      color: white;
      border: none;
      border-radius: var(--radius-sm);
      font-size: 15px;
      font-weight: 600;
      font-family: 'DM Sans', sans-serif;
      cursor: pointer;
      transition: background 0.2s, transform 0.15s, box-shadow 0.2s;
      margin-top: 6px;
      position: relative;
      overflow: hidden;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }
    .btn-submit::after {
      content: '';
      position: absolute;
      inset: 0;
      background: linear-gradient(135deg, rgba(255,255,255,0.08) 0%, transparent 60%);
      pointer-events: none;
    }
    .btn-submit:hover:not(:disabled) { background: var(--navy-light); box-shadow: 0 6px 20px rgba(10,35,66,0.25); transform: translateY(-1px); }
    .btn-submit:active:not(:disabled) { transform: translateY(0); }
    .btn-submit:disabled { opacity: 0.7; cursor: not-allowed; }

    /* Spinner */
    .spinner {
      width: 16px; height: 16px;
      border: 2px solid rgba(255,255,255,0.35);
      border-top-color: white;
      border-radius: 50%;
      animation: spin 0.7s linear infinite;
      display: none;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* OTP input */
    input[name="otp_code"] {
      text-align: center;
      font-size: 22px !important;
      letter-spacing: 8px;
      font-weight: 700;
    }

    /* ── NEW REGISTRATION BANNER ── */
    .reg-banner {
      background: linear-gradient(135deg, #e8faf3, #f0fdf8);
      border: 1px solid #b3ecd6;
      border-radius: var(--radius-sm);
      padding: 14px 16px;
      margin-bottom: 20px;
      display: flex;
      gap: 12px;
      align-items: flex-start;
    }
    .reg-banner-icon { font-size: 20px; flex-shrink: 0; }
    .reg-banner-text { font-size: 13px; color: #0e1c2f; line-height: 1.5; }
    .reg-banner-text strong { color: var(--success); }
    .emp-id-chip {
      display: inline-block;
      background: #0a2342;
      color: white;
      font-family: 'Courier New', monospace;
      font-size: 13px;
      font-weight: 700;
      letter-spacing: 1.5px;
      padding: 3px 10px;
      border-radius: 5px;
      margin-top: 4px;
    }

    /* ── FOOTER ── */
    .form-footer {
      margin-top: 28px;
      text-align: center;
      font-size: 12.5px;
      color: var(--gray-400);
      line-height: 1.7;
    }
    .form-footer a { color: var(--blue); text-decoration: none; font-weight: 600; }
    .form-footer a:hover { text-decoration: underline; }

    .slide-in { animation: slideIn 0.4s cubic-bezier(0.22, 1, 0.36, 1) both; }
    @keyframes slideIn {
      from { opacity: 0; transform: translateY(10px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    @media (max-width: 900px) {
      .panel-left { display: none; }
      .divider     { display: none; }
      .panel-right { width: 100%; padding: 40px 28px; }
      html, body { overflow: auto; }
    }
  </style>
</head>
<body>
<div class="page">
  <div class="blob blob-1"></div>
  <div class="blob blob-2"></div>
  <div class="blob blob-3"></div>

  <!-- Left Panel -->
  <div class="panel-left">
    <div class="brand">
      <div class="brand-icon">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round">
          <path d="M12 4v4M12 16v4M4 12h4M16 12h4"/>
          <circle cx="12" cy="12" r="4"/>
        </svg>
      </div>
      <div>
        <div class="brand-name">OMSC Health</div>
        <div class="brand-sub">San Jose Campus</div>
      </div>
    </div>

    <div class="hero-tag">
      <span class="hero-dot"></span>
      Faculty &amp; Staff Portal
    </div>

    <h1 class="hero-title">Your health,<br><em>always in check.</em></h1>

    <p class="hero-desc">
      A complete health management platform for Occidental Mindoro State College.
      Monitor vitals, view your records, and coordinate care — all in one place.
    </p>

    <div class="features">
      <div class="feature">
        <div class="feature-icon">💙</div>
        <div class="feature-text">
          <strong>Real-time Vitals Tracking</strong>
          <span>Log BP, heart rate, temperature, SpO₂ and more</span>
        </div>
      </div>
      <div class="feature">
        <div class="feature-icon">📊</div>
        <div class="feature-text">
          <strong>Health Analytics</strong>
          <span>Department-wide trends and personal history charts</span>
        </div>
      </div>
      <div class="feature">
        <div class="feature-icon">📅</div>
        <div class="feature-text">
          <strong>Appointment Management</strong>
          <span>Schedule consultations with the campus health office</span>
        </div>
      </div>
    </div>
  </div>

  <!-- Divider -->
  <div class="divider"></div>

  <!-- Right Panel -->
  <div class="panel-right">
    <div class="form-inner">

      <!-- Tab Switcher -->
      <div class="auth-tabs">
        <div class="tab-pill"></div>
        <a href="index.php" class="tab-btn active">Sign In</a>
        <a href="register.php" class="tab-btn">Register</a>
      </div>

      <!-- Registration success banner -->
      <?php if ($just_registered && $new_emp_id): ?>
      <div class="reg-banner slide-in">
        <div class="reg-banner-icon">🎉</div>
        <div class="reg-banner-text">
          <strong>Account created successfully!</strong><br>
          Your Employee ID has been sent to your email. You can also find it below:<br>
          <span class="emp-id-chip"><?= $new_emp_id ?></span>
        </div>
      </div>
      <?php endif; ?>

      <!-- Alerts -->
      <?php if (!empty($otp_notice)): ?>
        <div class="alert alert-success slide-in">
          <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
          <?= htmlspecialchars($otp_notice) ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($error)): ?>
        <div class="alert alert-danger slide-in">
          <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <?php if (!$show_otp_form): ?>

      <!-- ── Login Form ── -->
      <div class="form-heading slide-in">
        <h2>Welcome back</h2>
        <p>Sign in to the Health Monitoring Web System of OMSC San Jose Campus</p>
      </div>

      <form method="POST" autocomplete="off" class="slide-in" id="loginForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <div class="form-group">
          <label for="employee_id">Employee ID</label>
          <div class="input-wrap">
            <svg class="icon-left" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
              <path stroke-linecap="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
            </svg>
            <input type="text" id="employee_id" name="employee_id"
              placeholder="e.g. EMP-2024-00001"
              value="<?= htmlspecialchars($_POST['employee_id'] ?? ($just_registered ? '' : '')) ?>"
              required autofocus autocomplete="username">
          </div>
        </div>

        <div class="form-group">
          <label for="password">
            Password
            <a href="forgot_password.php" class="label-link">Forgot password?</a>
          </label>
          <div class="input-wrap">
            <svg class="icon-left" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
              <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>
            </svg>
            <input type="password" id="password" name="password"
              placeholder="Enter your password"
              required autocomplete="current-password">
            <button type="button" class="toggle-pw" onclick="togglePw('password', this)" aria-label="Show password">
              <!-- Eye icon -->
              <svg id="eye-login" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                <path stroke-linecap="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
              </svg>
            </button>
          </div>
        </div>

        <button type="submit" class="btn-submit" id="loginBtn">
          <span class="spinner" id="loginSpinner"></span>
          <span id="loginBtnText">Sign In</span>
        </button>
      </form>

      <div class="form-footer slide-in">
        Don't have an account? <a href="register.php">Register here</a><br>
        <span style="font-size:11px;color:#b0bec5;">Health Monitoring Web System · OMSC San Jose Campus</span>
      </div>

      <?php else: ?>

      <!-- ── OTP Form ── -->
      <div class="form-heading slide-in">
        <h2>Verify your identity</h2>
        <p>Enter the 6-digit code sent to your email address</p>
      </div>

      <form method="POST" autocomplete="off" class="slide-in" id="otpForm">
        <input type="hidden" name="action" value="verify_otp">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <div class="form-group">
          <label for="otp_code">OTP Code</label>
          <div class="input-wrap">
            <svg class="icon-left" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
              <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>
            </svg>
            <input type="text" id="otp_code" name="otp_code"
              placeholder="000000" maxlength="6"
              inputmode="numeric" pattern="\d{6}"
              required autofocus autocomplete="one-time-code">
          </div>
        </div>
        <p class="otp-hint">The code expires in 5 minutes. Check your inbox and spam folder.</p>
        <button type="submit" class="btn-submit" id="otpBtn">
          <span class="spinner" id="otpSpinner"></span>
          <span id="otpBtnText">Verify &amp; Continue</span>
        </button>
      </form>

      <div class="otp-actions slide-in">
        <form method="POST" style="display:inline;">
          <input type="hidden" name="action" value="resend_otp">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <button type="submit" class="btn-link">Resend OTP</button>
        </form>
        <span style="color:var(--gray-200);">·</span>
        <a href="logout.php" class="btn-link muted">← Back to login</a>
      </div>

      <?php endif; ?>

    </div>
  </div>
</div>

<script>
// ── Show / Hide password toggle ─────────────────────────────────────────────
function togglePw(inputId, btn) {
  const inp = document.getElementById(inputId);
  const isHidden = inp.type === 'password';
  inp.type = isHidden ? 'text' : 'password';
  btn.innerHTML = isHidden
    ? `<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
        <path stroke-linecap="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
      </svg>`
    : `<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
        <path stroke-linecap="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
        <path stroke-linecap="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
      </svg>`;
}

// ── Loading spinner on submit ────────────────────────────────────────────────
function attachSpinner(formId, btnId, spinnerId, textId) {
  const form = document.getElementById(formId);
  if (!form) return;
  form.addEventListener('submit', function() {
    const btn     = document.getElementById(btnId);
    const spinner = document.getElementById(spinnerId);
    const text    = document.getElementById(textId);
    if (btn && spinner && text) {
      btn.disabled      = true;
      spinner.style.display = 'block';
      text.textContent  = 'Please wait…';
    }
  });
}
attachSpinner('loginForm', 'loginBtn', 'loginSpinner', 'loginBtnText');
attachSpinner('otpForm',   'otpBtn',   'otpSpinner',   'otpBtnText');

// ── OTP: auto-advance on 6 digits ───────────────────────────────────────────
const otpInput = document.getElementById('otp_code');
if (otpInput) {
  otpInput.addEventListener('input', function() {
    this.value = this.value.replace(/\D/g, '').slice(0, 6);
    if (this.value.length === 6) {
      document.getElementById('otpForm')?.submit();
    }
  });
}
</script>
</body>
</html>
