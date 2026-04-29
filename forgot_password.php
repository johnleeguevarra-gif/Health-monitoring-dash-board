<?php
require_once 'includes/config.php';

if (isLoggedIn()) {
    header("Location: " . (isAdmin() ? 'admin/dashboard.php' : 'faculty/dashboard.php'));
    exit();
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error   = '';
$success = '';
$step    = $_SESSION['fp_step'] ?? 'request'; // request → verify → done

// ── Email helper (reuses PHPMailer from config) ───────────────────────────────
function sendResetEmail($to_email, $full_name, $otp_code) {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoload)) return false;
    require_once $autoload;

    if (MAIL_USERNAME === 'your-email@gmail.com' || MAIL_PASSWORD === 'your-app-password') return false;

    $safe_name = htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8');
    $body = "
    <html><body style='font-family:Arial,sans-serif;line-height:1.6;color:#0e1c2f;'>
      <h2 style='color:#0d2b55;'>OMSC Health — Password Reset</h2>
      <p>Hello {$safe_name},</p>
      <p>You requested to reset your password. Use the code below:</p>
      <p style='font-size:32px;font-weight:700;letter-spacing:6px;margin:18px 0;color:#0d2b55;'>{$otp_code}</p>
      <p>This code expires in <strong>10 minutes</strong>. If you did not request this, please ignore this email and your password will remain unchanged.</p>
      <hr style='border:none;border-top:1px solid #e0e4ea;margin:20px 0;'>
      <p style='font-size:12px;color:#6b7280;'>Health Monitoring Web System · OMSC San Jose Campus</p>
    </body></html>";
    $alt = "Hello {$full_name},\n\nYour OMSC password reset OTP is: {$otp_code}\n\nExpires in 10 minutes.";

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
        $mail->SMTPDebug  = \PHPMailer\PHPMailer\SMTP::DEBUG_OFF;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($to_email, $full_name);
        $mail->isHTML(true);
        $mail->Subject = 'Your OMSC Health Password Reset Code';
        $mail->Body    = $body;
        $mail->AltBody = $alt;
        return $mail->send();
    } catch (\Throwable $e) {
        return false;
    }
}

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $posted_csrf)) {
        $error = "Invalid request. Please refresh and try again.";
        goto render;
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $action = $_POST['action'] ?? '';

    // ── Step 1: Request OTP by Employee ID + Email ────────────────────────────
    if ($action === 'request_otp') {
        $employee_id = trim($_POST['employee_id'] ?? '');
        $email       = trim(strtolower($_POST['email'] ?? ''));

        if (empty($employee_id) || empty($email)) {
            $error = "Please enter both your Employee ID and email address.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            $stmt = $conn->prepare("SELECT id, full_name, email, employee_id FROM users WHERE employee_id = ? AND status = 'active' LIMIT 1");
            $stmt->bind_param('s', $employee_id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // Always show success to prevent user enumeration
            if ($user && strtolower($user['email']) === $email) {
                $otp_code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $sent = sendResetEmail($user['email'], $user['full_name'], $otp_code);

                if ($sent) {
                    $_SESSION['fp_reset'] = [
                        'user_id'     => $user['id'],
                        'full_name'   => $user['full_name'],
                        'email'       => $user['email'],
                        'otp_hash'    => hash('sha256', $otp_code),
                        'otp_expires' => time() + 600,
                        'attempts'    => 0,
                        'verified'    => false,
                    ];
                    $_SESSION['fp_step'] = 'verify';
                    $step = 'verify';
                    $masked = preg_replace('/(?<=.{3}).(?=.*@)/', '*', $user['email']);
                    $success = "A 6-digit reset code was sent to {$masked}.";
                } else {
                    $error = "Unable to send reset email. Please try again or contact the administrator.";
                }
            } else {
                // Intentionally vague
                usleep(300000);
                $_SESSION['fp_step'] = 'verify';
                $step = 'verify';
                $success = "If an account with that Employee ID and email exists, a reset code has been sent.";
            }
        }

    // ── Step 2: Verify OTP ────────────────────────────────────────────────────
    } elseif ($action === 'verify_otp') {
        $otp_input = trim($_POST['otp_code'] ?? '');
        $pending   = $_SESSION['fp_reset'] ?? null;

        if (!$pending) {
            $error = "Session expired. Please start again.";
            unset($_SESSION['fp_step']);
            $step = 'request';
        } elseif (!preg_match('/^\d{6}$/', $otp_input)) {
            $error = "Enter a valid 6-digit code.";
            $step = 'verify';
        } elseif (time() > (int)$pending['otp_expires']) {
            unset($_SESSION['fp_reset'], $_SESSION['fp_step']);
            $error = "The reset code has expired. Please start again.";
            $step = 'request';
        } else {
            $_SESSION['fp_reset']['attempts'] = (int)$pending['attempts'] + 1;
            if ($_SESSION['fp_reset']['attempts'] > 5) {
                unset($_SESSION['fp_reset'], $_SESSION['fp_step']);
                $error = "Too many incorrect attempts. Please start the process again.";
                $step = 'request';
            } elseif (hash_equals($pending['otp_hash'], hash('sha256', $otp_input))) {
                $_SESSION['fp_reset']['verified'] = true;
                $_SESSION['fp_step'] = 'reset';
                $step = 'reset';
                $success = "Identity verified! Please choose your new password.";
            } else {
                $remaining = max(0, 5 - (int)$_SESSION['fp_reset']['attempts']);
                $error = "Incorrect code. {$remaining} attempt(s) remaining.";
                $step = 'verify';
            }
        }

    // ── Resend OTP ────────────────────────────────────────────────────────────
    } elseif ($action === 'resend_otp') {
        $pending = $_SESSION['fp_reset'] ?? null;
        if (!$pending) {
            $error = "Session expired. Please start again.";
            $step  = 'request';
            unset($_SESSION['fp_step']);
        } else {
            $otp_code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $sent = sendResetEmail($pending['email'], $pending['full_name'], $otp_code);
            if ($sent) {
                $_SESSION['fp_reset']['otp_hash']    = hash('sha256', $otp_code);
                $_SESSION['fp_reset']['otp_expires'] = time() + 600;
                $_SESSION['fp_reset']['attempts']    = 0;
                $masked = preg_replace('/(?<=.{3}).(?=.*@)/', '*', $pending['email']);
                $success = "A new code was sent to {$masked}.";
            } else {
                $error = "Unable to resend. Please try again.";
            }
            $step = 'verify';
        }

    // ── Step 3: Set New Password ──────────────────────────────────────────────
    } elseif ($action === 'set_password') {
        $pending  = $_SESSION['fp_reset'] ?? null;
        $new_pw   = $_POST['new_password'] ?? '';
        $conf_pw  = $_POST['confirm_password'] ?? '';

        if (!$pending || empty($pending['verified'])) {
            $error = "Session expired or not verified. Please start again.";
            unset($_SESSION['fp_step'], $_SESSION['fp_reset']);
            $step = 'request';
        } elseif (strlen($new_pw) < 8) {
            $error = "Password must be at least 8 characters long.";
            $step = 'reset';
        } elseif ($new_pw !== $conf_pw) {
            $error = "Passwords do not match. Please try again.";
            $step = 'reset';
        } else {
            $hashed = password_hash($new_pw, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param('si', $hashed, $pending['user_id']);
            $ok = $stmt->execute();
            $stmt->close();

            if ($ok) {
                unset($_SESSION['fp_reset'], $_SESSION['fp_step']);
                $_SESSION['flash'] = ['type' => 'success', 'msg' => '✅ Password reset successfully! You can now sign in with your new password.'];
                header("Location: index.php");
                exit();
            } else {
                $error = "An error occurred while saving your new password. Please try again.";
                $step = 'reset';
            }
        }
    }
}

$step = $_SESSION['fp_step'] ?? $step ?? 'request';

function isLoggedIn() { return isset($_SESSION['user_id']); }
function isAdmin()    { return isset($_SESSION['role']) && $_SESSION['role'] === 'admin'; }

render:
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password — OMSC Health Monitor</title>
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
    .hero-dot { width: 6px; height: 6px; border-radius: 50%; background: #fbbf24; animation: pulse 2s ease-in-out infinite; }
    @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:0.6;transform:scale(1.3)} }

    .hero-title {
      font-family: 'DM Serif Display', serif;
      font-size: clamp(28px, 3vw, 40px);
      line-height: 1.15;
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

    /* Step indicators */
    .steps { display: flex; flex-direction: column; gap: 14px; }
    .step-item {
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .step-num {
      width: 34px; height: 34px;
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 13px;
      font-weight: 700;
      flex-shrink: 0;
      transition: all 0.3s;
    }
    .step-num.done    { background: #4de0b0; color: #0a2342; }
    .step-num.active  { background: var(--blue-light); color: white; box-shadow: 0 0 0 4px rgba(45,132,232,0.25); }
    .step-num.pending { background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.15); color: rgba(255,255,255,0.4); }
    .step-text strong { display: block; font-size: 13px; font-weight: 600; color: rgba(255,255,255,0.85); }
    .step-text span   { font-size: 12px; color: rgba(255,255,255,0.4); }
    .step-connector   { width: 2px; height: 14px; background: rgba(255,255,255,0.1); margin-left: 16px; }

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

    .form-heading { margin-bottom: 28px; }
    .form-heading h2 {
      font-family: 'DM Serif Display', serif;
      font-size: 28px;
      color: var(--text);
      letter-spacing: -0.5px;
      margin-bottom: 5px;
    }
    .form-heading p { font-size: 13px; color: var(--gray-400); line-height: 1.6; }

    /* ── FORM ELEMENTS ── */
    .form-group { margin-bottom: 18px; }
    .form-group label {
      display: block;
      font-size: 12.5px;
      font-weight: 600;
      color: var(--gray-600);
      margin-bottom: 6px;
      letter-spacing: 0.2px;
    }

    .input-wrap { position: relative; }
    .input-wrap .icon-left {
      position: absolute;
      left: 14px; top: 50%; transform: translateY(-50%);
      color: var(--gray-400);
      pointer-events: none;
    }
    .input-wrap input {
      width: 100%;
      padding: 12px 14px 12px 42px;
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

    .toggle-pw {
      position: absolute;
      right: 13px; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer;
      color: var(--gray-400); padding: 4px;
      display: flex; align-items: center;
      transition: color 0.2s;
    }
    .toggle-pw:hover { color: var(--blue); }

    /* Password strength */
    .strength-wrap { margin-top: 6px; }
    .strength-bar-bg { height: 4px; background: var(--gray-100); border-radius: 2px; overflow: hidden; margin-bottom: 4px; }
    .strength-bar    { height: 100%; width: 0%; border-radius: 2px; transition: width 0.3s, background 0.3s; }
    .strength-label  { font-size: 11.5px; color: var(--gray-400); }

    /* ── ALERTS ── */
    .alert {
      padding: 11px 14px;
      border-radius: var(--radius-sm);
      font-size: 13px;
      font-weight: 500;
      margin-bottom: 20px;
      display: flex;
      align-items: flex-start;
      gap: 8px;
      line-height: 1.5;
    }
    .alert svg { flex-shrink: 0; margin-top: 1px; }
    .alert-danger  { background: var(--danger-bg); color: var(--danger); border: 1px solid #ffd0d0; }
    .alert-success { background: #e8faf3; color: var(--success); border: 1px solid #b3ecd6; }
    .alert-info    { background: #eef6ff; color: var(--blue); border: 1px solid #c5dcf8; }

    /* OTP input */
    input[name="otp_code"] {
      text-align: center !important;
      font-size: 24px !important;
      letter-spacing: 10px !important;
      font-weight: 700 !important;
      padding-left: 14px !important;
    }
    .otp-hint { font-size: 12px; color: var(--gray-400); margin-top: -12px; margin-bottom: 16px; line-height: 1.6; }

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
      position: relative; overflow: hidden;
      display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .btn-submit::after {
      content: '';
      position: absolute; inset: 0;
      background: linear-gradient(135deg, rgba(255,255,255,0.08) 0%, transparent 60%);
      pointer-events: none;
    }
    .btn-submit:hover:not(:disabled) { background: var(--navy-light); box-shadow: 0 6px 20px rgba(10,35,66,0.25); transform: translateY(-1px); }
    .btn-submit:active:not(:disabled) { transform: translateY(0); }
    .btn-submit:disabled { opacity: 0.7; cursor: not-allowed; }

    .spinner {
      width: 16px; height: 16px;
      border: 2px solid rgba(255,255,255,0.35);
      border-top-color: white; border-radius: 50%;
      animation: spin 0.7s linear infinite; display: none;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* ── FOOTER ── */
    .form-footer {
      margin-top: 22px;
      text-align: center;
      font-size: 12.5px;
      color: var(--gray-400);
    }
    .form-footer a { color: var(--blue); text-decoration: none; font-weight: 600; }
    .form-footer a:hover { text-decoration: underline; }

    .btn-link {
      background: transparent; border: none;
      color: var(--blue); font-size: 12.5px; font-weight: 600;
      cursor: pointer; padding: 0; font-family: 'DM Sans', sans-serif;
    }
    .btn-link:hover { text-decoration: underline; }

    .otp-actions { margin-top: 14px; display: flex; gap: 10px; align-items: center; }

    .success-icon {
      width: 60px; height: 60px;
      background: #e8faf3;
      border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 26px;
      margin: 0 auto 20px;
    }

    .slide-in { animation: slideIn 0.4s cubic-bezier(0.22, 1, 0.36, 1) both; }
    @keyframes slideIn {
      from { opacity: 0; transform: translateY(10px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    @media (max-width: 900px) {
      .panel-left { display: none; }
      .divider    { display: none; }
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
      Password Recovery
    </div>

    <h1 class="hero-title">Recover your<br><em>account access.</em></h1>
    <p class="hero-desc">
      Follow the three steps to securely reset your password. A one-time code will be sent to your registered email address.
    </p>

    <div class="steps">
      <!-- Step 1 -->
      <div class="step-item">
        <div class="step-num <?= $step === 'request' ? 'active' : ($step !== 'request' ? 'done' : 'pending') ?>">
          <?= ($step !== 'request') ? '✓' : '1' ?>
        </div>
        <div class="step-text">
          <strong>Verify Identity</strong>
          <span>Enter Employee ID &amp; email</span>
        </div>
      </div>
      <div class="step-connector"></div>
      <!-- Step 2 -->
      <div class="step-item">
        <div class="step-num <?= $step === 'verify' ? 'active' : ($step === 'reset' ? 'done' : 'pending') ?>">
          <?= ($step === 'reset') ? '✓' : '2' ?>
        </div>
        <div class="step-text">
          <strong>Enter OTP Code</strong>
          <span>Confirm the 6-digit code</span>
        </div>
      </div>
      <div class="step-connector"></div>
      <!-- Step 3 -->
      <div class="step-item">
        <div class="step-num <?= $step === 'reset' ? 'active' : 'pending' ?>">3</div>
        <div class="step-text">
          <strong>Set New Password</strong>
          <span>Choose a strong password</span>
        </div>
      </div>
    </div>
  </div>

  <!-- Divider -->
  <div class="divider"></div>

  <!-- Right Panel -->
  <div class="panel-right">
    <div class="form-inner">

      <!-- Alerts -->
      <?php if (!empty($success)): ?>
        <div class="alert alert-success slide-in">
          <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
          <?= htmlspecialchars($success) ?>
        </div>
      <?php endif; ?>
      <?php if (!empty($error)): ?>
        <div class="alert alert-danger slide-in">
          <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <?php if ($step === 'request'): ?>
      <!-- ── STEP 1: Request OTP ── -->
      <div class="form-heading slide-in">
        <h2>Forgot your password?</h2>
        <p>Enter your Employee ID and the email linked to your account. We'll send you a reset code.</p>
      </div>
      <form method="POST" id="reqForm" class="slide-in">
        <input type="hidden" name="action" value="request_otp">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <div class="form-group">
          <label for="employee_id">Employee ID</label>
          <div class="input-wrap">
            <svg class="icon-left" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            <input type="text" id="employee_id" name="employee_id"
              placeholder="e.g. EMP-2024-00001"
              value="<?= htmlspecialchars($_POST['employee_id'] ?? '') ?>"
              required autofocus autocomplete="username">
          </div>
        </div>
        <div class="form-group">
          <label for="email">Email Address</label>
          <div class="input-wrap">
            <svg class="icon-left" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            <input type="email" id="email" name="email"
              placeholder="your-email@omsc.edu.ph"
              value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
              required autocomplete="email">
          </div>
        </div>
        <button type="submit" class="btn-submit" id="reqBtn">
          <span class="spinner" id="reqSpinner"></span>
          <span id="reqBtnText">Send Reset Code</span>
        </button>
      </form>

      <?php elseif ($step === 'verify'): ?>
      <!-- ── STEP 2: Verify OTP ── -->
      <div class="form-heading slide-in">
        <h2>Check your email</h2>
        <p>Enter the 6-digit code we sent to your registered email address. The code expires in 10 minutes.</p>
      </div>
      <form method="POST" id="otpForm" class="slide-in">
        <input type="hidden" name="action" value="verify_otp">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <div class="form-group">
          <label for="otp_code">Reset Code</label>
          <div class="input-wrap">
            <svg class="icon-left" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            <input type="text" id="otp_code" name="otp_code"
              placeholder="000000" maxlength="6"
              inputmode="numeric" pattern="\d{6}"
              required autofocus autocomplete="one-time-code">
          </div>
        </div>
        <p class="otp-hint">Check your inbox and spam folder. The code expires in 10 minutes.</p>
        <button type="submit" class="btn-submit" id="otpBtn">
          <span class="spinner" id="otpSpinner"></span>
          <span id="otpBtnText">Verify Code</span>
        </button>
      </form>
      <div class="otp-actions slide-in">
        <form method="POST" style="display:inline;">
          <input type="hidden" name="action" value="resend_otp">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <button type="submit" class="btn-link">Resend Code</button>
        </form>
        <span style="color:var(--gray-200);">·</span>
        <a href="forgot_password.php?restart=1" class="btn-link" style="color:var(--gray-400);">Start Over</a>
      </div>

      <?php elseif ($step === 'reset'): ?>
      <!-- ── STEP 3: New Password ── -->
      <div class="form-heading slide-in">
        <h2>Create new password</h2>
        <p>Choose a strong password for your account. It must be at least 8 characters long.</p>
      </div>
      <form method="POST" id="pwForm" class="slide-in" autocomplete="off">
        <input type="hidden" name="action" value="set_password">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
        <div class="form-group">
          <label for="new_password">New Password</label>
          <div class="input-wrap">
            <svg class="icon-left" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            <input type="password" id="new_password" name="new_password"
              placeholder="Min. 8 characters" required autofocus autocomplete="new-password"
              oninput="checkStrength(this.value)">
            <button type="button" class="toggle-pw" onclick="togglePw('new_password', this)" aria-label="Show password">
              <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
            </button>
          </div>
          <div class="strength-wrap">
            <div class="strength-bar-bg"><div class="strength-bar" id="strengthBar"></div></div>
            <span class="strength-label" id="strengthLabel">Enter a password</span>
          </div>
        </div>
        <div class="form-group">
          <label for="confirm_password">Confirm New Password</label>
          <div class="input-wrap">
            <svg class="icon-left" width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            <input type="password" id="confirm_password" name="confirm_password"
              placeholder="Re-enter password" required autocomplete="new-password">
            <button type="button" class="toggle-pw" onclick="togglePw('confirm_password', this)" aria-label="Show password">
              <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
            </button>
          </div>
        </div>
        <button type="submit" class="btn-submit" id="pwBtn">
          <span class="spinner" id="pwSpinner"></span>
          <span id="pwBtnText">Reset Password</span>
        </button>
      </form>

      <?php endif; ?>

      <div class="form-footer slide-in">
        Remember your password? <a href="index.php">← Back to Sign In</a>
      </div>
    </div>
  </div>
</div>

<script>
<?php if (isset($_GET['restart'])): ?>
// Restart: clear session on server side via redirect
window.location.href = 'forgot_password.php?_clear=1';
<?php endif; ?>

function togglePw(id, btn) {
  const inp = document.getElementById(id);
  const isHidden = inp.type === 'password';
  inp.type = isHidden ? 'text' : 'password';
  btn.innerHTML = isHidden
    ? `<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>`
    : `<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>`;
}

function checkStrength(pw) {
  const bar   = document.getElementById('strengthBar');
  const label = document.getElementById('strengthLabel');
  if (!bar) return;
  let score = 0;
  if (pw.length >= 8)  score++;
  if (pw.length >= 12) score++;
  if (/[A-Z]/.test(pw)) score++;
  if (/[0-9]/.test(pw)) score++;
  if (/[^A-Za-z0-9]/.test(pw)) score++;
  const levels = [
    { w:'0%',   bg:'#e5e7eb', txt:'Enter a password' },
    { w:'20%',  bg:'#ef4444', txt:'Very weak' },
    { w:'40%',  bg:'#f97316', txt:'Weak' },
    { w:'60%',  bg:'#eab308', txt:'Fair' },
    { w:'80%',  bg:'#22c55e', txt:'Strong' },
    { w:'100%', bg:'#16a34a', txt:'Very strong' },
  ];
  const lvl = levels[score] || levels[0];
  bar.style.width      = lvl.w;
  bar.style.background = lvl.bg;
  label.textContent    = lvl.txt;
  label.style.color    = score >= 3 ? lvl.bg : '#8899b4';
}

// Spinner on submit
['reqForm','otpForm','pwForm'].forEach(id => {
  const form = document.getElementById(id);
  if (!form) return;
  form.addEventListener('submit', () => {
    const prefix = id.replace('Form','');
    const btn = document.getElementById(prefix+'Btn');
    const sp  = document.getElementById(prefix+'Spinner');
    const tx  = document.getElementById(prefix+'BtnText');
    if (btn && sp && tx) { btn.disabled=true; sp.style.display='block'; tx.textContent='Please wait…'; }
  });
});

// OTP: auto-submit on 6 digits
const otpInput = document.getElementById('otp_code');
if (otpInput) {
  otpInput.addEventListener('input', function() {
    this.value = this.value.replace(/\D/g,'').slice(0,6);
    if (this.value.length === 6) document.getElementById('otpForm')?.submit();
  });
}
</script>
</body>
</html>
<?php
// Handle restart: clear session state
if (isset($_GET['_clear'])) {
    unset($_SESSION['fp_step'], $_SESSION['fp_reset']);
    header("Location: forgot_password.php");
    exit();
}
?>
