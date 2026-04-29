<?php require_once 'includes/config.php'; ?>
<?php
// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? 'employee';
    header("Location: " . ($role === 'admin' ? 'admin/dashboard.php' : 'faculty/dashboard.php'));
    exit();
}

// ── CSRF token ───────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = '';
$error   = '';

// ── Auto-generate Employee ID ─────────────────────────────────────────────────
function generateEmployeeId($conn) {
    $year = date('Y');
    do {
        $random    = str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
        $candidate = "EMP-{$year}-{$random}";
        $stmt = $conn->prepare("SELECT id FROM users WHERE employee_id = ?");
        $stmt->bind_param('s', $candidate);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
    } while ($exists);
    return $candidate;
}

// ── Welcome email ─────────────────────────────────────────────────────────────
function sendWelcomeEmail($toEmail, $toName, $employeeId) {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoload)) return false;
    require_once $autoload;

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_ENCRYPTION === 'tls'
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = MAIL_PORT;
        $mail->Timeout    = MAIL_TIMEOUT;
        $mail->SMTPDebug  = defined('MAIL_DEBUG') && MAIL_DEBUG
            ? \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER
            : \PHPMailer\PHPMailer\SMTP::DEBUG_OFF;

        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = 'Welcome to OMSC Health Portal — Your Employee ID';

        $firstName = explode(' ', trim($toName))[0];
        $year      = date('Y');

        $mail->Body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Welcome to OMSC Health Portal</title>
</head>
<body style="margin:0;padding:0;background:#f0f4f8;font-family:'Helvetica Neue',Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f0f4f8;padding:40px 0;">
    <tr>
      <td align="center">
        <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(10,35,66,0.10);">
          <tr>
            <td style="background:linear-gradient(135deg,#0a2342 0%,#1d6fc4 100%);padding:36px 40px 32px;">
              <h1 style="margin:0 0 4px;font-size:22px;font-weight:700;color:#ffffff;">Health Monitoring Web System</h1>
              <p style="margin:0;font-size:12px;color:rgba(255,255,255,0.55);letter-spacing:0.8px;text-transform:uppercase;">OMSC San Jose Campus</p>
            </td>
          </tr>
          <tr>
            <td style="padding:36px 40px 24px;">
              <h2 style="margin:0 0 8px;font-size:24px;color:#0e1c2f;font-weight:700;">Welcome, {$firstName}! 🎉</h2>
              <p style="margin:0 0 24px;font-size:14px;color:#4a5f7d;line-height:1.7;">
                Your account on the OMSC Health Portal has been successfully created. Below is your unique Employee ID — please keep it safe.
              </p>
              <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:28px;">
                <tr>
                  <td style="background:#eef6ff;border:2px dashed #1d6fc4;border-radius:12px;padding:24px;text-align:center;">
                    <p style="margin:0 0 6px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:#4a5f7d;">Your Employee ID</p>
                    <p style="margin:0;font-size:28px;font-weight:700;color:#0a2342;letter-spacing:2px;font-family:'Courier New',monospace;">{$employeeId}</p>
                  </td>
                </tr>
              </table>
              <table width="100%" cellpadding="0" cellspacing="0" style="background:#f7f9fc;border-radius:10px;padding:4px;margin-bottom:28px;">
                <tr>
                  <td style="padding:14px 18px;border-bottom:1px solid #edf1f7;">
                    <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:#8899b4;">Registered Email</span><br>
                    <span style="font-size:14px;color:#0e1c2f;font-weight:500;">{$toEmail}</span>
                  </td>
                </tr>
                <tr>
                  <td style="padding:14px 18px;">
                    <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:#8899b4;">Account Status</span><br>
                    <span style="font-size:14px;color:#0d8a5a;font-weight:600;">✓ Active</span>
                  </td>
                </tr>
              </table>
              <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
                <tr>
                  <td align="center">
                    <a href="http://localhost/omsc-health/index.php" style="display:inline-block;background:#0a2342;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;padding:13px 36px;border-radius:8px;">Sign In to Your Account →</a>
                  </td>
                </tr>
              </table>
              <p style="margin:0;font-size:12.5px;color:#8899b4;line-height:1.7;">
                If you did not register for this account, please contact your system administrator immediately.
              </p>
            </td>
          </tr>
          <tr>
            <td style="background:#f7f9fc;border-top:1px solid #edf1f7;padding:20px 40px;text-align:center;">
              <p style="margin:0 0 4px;font-size:11px;color:#8899b4;">© {$year} Health Monitoring Web System of OMSC San Jose Campus</p>
              <p style="margin:0;font-size:11px;color:#b0bec5;">Your information is kept confidential and secure in accordance with the Data Privacy Act.</p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;

        $mail->AltBody = "Welcome, {$firstName}!\n\nEmployee ID: {$employeeId}\nEmail: {$toEmail}\nStatus: Active\n\n© {$year} OMSC Health Monitoring Web System";
        $mail->send();
        return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log("PHPMailer error for {$toEmail}: " . $mail->ErrorInfo);
        return false;
    }
}

// ── FORM HANDLING ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    $posted_csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $posted_csrf)) {
        $error = "Invalid request. Please refresh and try again.";
    } else {
        // Rotate token
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $full_name  = trim($_POST['full_name']      ?? '');
        $email      = strtolower(trim($_POST['email'] ?? ''));
        $department = trim($_POST['department']      ?? '');
        $position   = trim($_POST['position']        ?? '');
        $contact    = trim($_POST['contact_number']  ?? '');
        $birthdate  = trim($_POST['birthdate']       ?? '');
        $password   = $_POST['password']             ?? '';
        $confirm_pw = $_POST['confirm_password']     ?? '';

        // Validation
        if (empty($full_name) || empty($email) || empty($password)) {
            $error = "Please fill in all required fields.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } elseif ($password !== $confirm_pw) {
            $error = "Passwords do not match.";
        } elseif (strlen($password) < 6) {
            $error = "Password must be at least 6 characters.";
        } else {
            // Check duplicate email — prepared statement
            $check = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $check->bind_param('s', $email);
            $check->execute();
            $check->store_result();
            $duplicate = $check->num_rows > 0;
            $check->close();

            if ($duplicate) {
                $error = "This email address is already registered. Try signing in instead.";
            } else {
                $employee_id = generateEmployeeId($conn);
                $hashed      = password_hash($password, PASSWORD_BCRYPT);

                // Detect available columns
                $columns = [];
                $col_res = $conn->query("SHOW COLUMNS FROM users");
                if ($col_res) {
                    while ($col = $col_res->fetch_assoc()) {
                        $columns[$col['Field']] = true;
                    }
                }

                // Build INSERT with prepared statement
                $fields = ['employee_id', 'full_name', 'email', 'password', 'role', 'department', 'position', 'status'];
                $values = [$employee_id, $full_name, $email, $hashed, 'employee', $department, $position, 'active'];
                $types  = 'ssssssss';

                if (isset($columns['contact_number'])) { $fields[] = 'contact_number'; $values[] = $contact;   $types .= 's'; }
                if (isset($columns['phone']))           { $fields[] = 'phone';          $values[] = $contact;   $types .= 's'; }
                if (isset($columns['birthdate']))        { $fields[] = 'birthdate';      $values[] = $birthdate; $types .= 's'; }
                if (isset($columns['date_of_birth']))    { $fields[] = 'date_of_birth';  $values[] = $birthdate; $types .= 's'; }

                $placeholders = implode(', ', array_fill(0, count($fields), '?'));
                $col_list     = implode(', ', $fields);

                $stmt = $conn->prepare("INSERT INTO users ({$col_list}) VALUES ({$placeholders})");
                $stmt->bind_param($types, ...$values);

                if ($stmt->execute()) {
                    $stmt->close();
                    sendWelcomeEmail($email, $full_name, $employee_id);
                    header("Location: index.php?registered=1&your_id=" . urlencode($employee_id));
                    exit();
                }
                $stmt->close();
                $error = "Unable to register your account at the moment. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Register — Health Monitoring Web System of OMSC San Jose Campus</title>
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
      --warning:    #d97706;
      --radius:     14px;
      --radius-sm:  8px;
    }

    html, body {
      min-height: 100%;
      font-family: 'DM Sans', sans-serif;
      background: var(--navy);
    }

    .page {
      display: flex;
      min-height: 100vh;
      position: relative;
      overflow: hidden;
    }

    .blob {
      position: fixed;
      border-radius: 50%;
      filter: blur(80px);
      opacity: 0.22;
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

    .brand { display: flex; align-items: center; gap: 14px; margin-bottom: 56px; }
    .brand-icon {
      width: 44px; height: 44px;
      background: rgba(255,255,255,0.12);
      border: 1px solid rgba(255,255,255,0.2);
      border-radius: 12px;
      display: flex; align-items: center; justify-content: center;
      backdrop-filter: blur(8px);
    }
    .brand-name { font-family: 'DM Serif Display', serif; font-size: 20px; color: white; letter-spacing: -0.3px; }
    .brand-sub  { font-size: 11px; color: rgba(255,255,255,0.45); margin-top: 1px; letter-spacing: 0.5px; text-transform: uppercase; }

    .hero-tag {
      display: inline-flex; align-items: center; gap: 6px;
      background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.15);
      border-radius: 20px; padding: 5px 14px;
      font-size: 11px; color: rgba(255,255,255,0.65);
      letter-spacing: 0.8px; text-transform: uppercase; margin-bottom: 20px;
    }
    .hero-dot { width: 6px; height: 6px; border-radius: 50%; background: #4de0b0; animation: pulse 2s ease-in-out infinite; }
    @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:0.6;transform:scale(1.3)} }

    .hero-title {
      font-family: 'DM Serif Display', serif;
      font-size: clamp(30px, 3vw, 44px);
      line-height: 1.1; color: white; margin-bottom: 16px; letter-spacing: -1px;
    }
    .hero-title em { font-style: italic; color: rgba(255,255,255,0.55); }

    .hero-desc { font-size: 13.5px; color: rgba(255,255,255,0.55); line-height: 1.75; max-width: 340px; margin-bottom: 40px; }

    .steps { display: flex; flex-direction: column; gap: 0; }
    .step { display: flex; align-items: flex-start; gap: 16px; padding-bottom: 24px; position: relative; animation: fadeUp 0.6s ease both; }
    .step:nth-child(1) { animation-delay: 0.1s; }
    .step:nth-child(2) { animation-delay: 0.2s; }
    .step:nth-child(3) { animation-delay: 0.3s; }
    .step:not(:last-child)::after {
      content: ''; position: absolute; left: 17px; top: 36px;
      width: 2px; height: calc(100% - 36px); background: rgba(255,255,255,0.1);
    }
    @keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
    .step-num {
      width: 34px; height: 34px; border-radius: 50%;
      background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2);
      display: flex; align-items: center; justify-content: center;
      font-size: 12px; font-weight: 700; color: rgba(255,255,255,0.8); flex-shrink: 0;
    }
    .step-text strong { display: block; font-size: 13px; font-weight: 600; color: rgba(255,255,255,0.85); }
    .step-text span   { font-size: 12px; color: rgba(255,255,255,0.4); margin-top: 2px; display: block; }

    /* ── DIVIDER ── */
    .divider { width: 1px; background: rgba(255,255,255,0.08); position: relative; z-index: 1; flex-shrink: 0; }

    /* ── RIGHT PANEL ── */
    .panel-right {
      flex: 1; background: var(--white);
      display: flex; flex-direction: column;
      justify-content: flex-start; align-items: flex-start;
      padding: 44px 72px; position: relative; z-index: 1;
      overflow-y: auto; min-height: 100vh;
    }
    .panel-right::before {
      content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
      background: linear-gradient(90deg, var(--navy), var(--blue), var(--blue-light));
    }

    .form-inner { max-width: 520px; width: 100%; }

    /* ── TAB SWITCHER ── */
    .auth-tabs {
      display: flex; background: var(--gray-100); border-radius: 10px;
      padding: 4px; margin-bottom: 28px; position: relative;
    }
    .tab-pill {
      position: absolute; top: 4px; bottom: 4px;
      width: calc(50% - 4px); left: 4px;
      background: white; border-radius: 7px;
      box-shadow: 0 2px 8px rgba(10,35,66,0.1);
      transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
      transform: translateX(calc(100% + 0px));
    }
    .tab-btn {
      flex: 1; text-align: center; padding: 9px 0;
      font-size: 13.5px; font-weight: 600; color: var(--gray-400);
      cursor: pointer; position: relative; z-index: 1;
      transition: color 0.2s; text-decoration: none; display: block;
      border-radius: 7px; font-family: 'DM Sans', sans-serif;
    }
    .tab-btn.active { color: var(--navy); }
    .tab-btn:hover:not(.active) { color: var(--gray-600); }

    /* ── FORM HEADING ── */
    .form-heading { margin-bottom: 22px; }
    .form-heading h2 {
      font-family: 'DM Serif Display', serif;
      font-size: 26px; color: var(--text); letter-spacing: -0.5px; margin-bottom: 4px;
    }
    .form-heading p { font-size: 13px; color: var(--gray-400); }

    /* ── SECTION LABEL ── */
    .section-label {
      font-size: 10.5px; font-weight: 700; text-transform: uppercase;
      letter-spacing: 1px; color: var(--gray-400); margin: 18px 0 12px;
      display: flex; align-items: center; gap: 8px;
    }
    .section-label::after { content: ''; flex: 1; height: 1px; background: var(--gray-200); }

    /* ── NOTICES ── */
    .auto-id-note, .email-note {
      display: flex; align-items: center; gap: 8px;
      border-radius: var(--radius-sm); padding: 10px 14px; margin-bottom: 16px;
      font-size: 12.5px;
    }
    .auto-id-note { background: #eef6ff; border: 1px solid #c5dcf8; color: var(--blue); }
    .email-note   { background: #f0faf5; border: 1px solid #b3ecd6; color: var(--success); }
    .auto-id-note svg, .email-note svg { flex-shrink: 0; }

    /* ── FORM ELEMENTS ── */
    .form-group { margin-bottom: 14px; }
    .form-group label {
      display: block; font-size: 12px; font-weight: 600;
      color: var(--gray-600); margin-bottom: 5px; letter-spacing: 0.2px;
    }
    .form-group label .req { color: var(--blue); }

    .input-wrap { position: relative; }
    .input-wrap .icon-left {
      position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
      color: var(--gray-400); pointer-events: none;
    }
    .input-wrap input {
      width: 100%; padding: 10px 38px 10px 38px;
      border: 1.5px solid var(--gray-200); border-radius: var(--radius-sm);
      font-size: 13px; font-family: 'DM Sans', sans-serif;
      color: var(--text); background: var(--off-white);
      transition: border-color 0.2s, box-shadow 0.2s, background 0.2s; outline: none;
    }
    .input-wrap input:focus {
      border-color: var(--blue); background: white;
      box-shadow: 0 0 0 3px rgba(29,111,196,0.1);
    }
    .input-wrap input.valid   { border-color: var(--success); }
    .input-wrap input.invalid { border-color: var(--danger); }
    .input-wrap input::placeholder { color: var(--gray-400); font-size: 12.5px; }

    /* Show/hide password */
    .toggle-pw {
      position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer;
      color: var(--gray-400); padding: 4px;
      display: flex; align-items: center; transition: color 0.2s;
    }
    .toggle-pw:hover { color: var(--blue); }

    /* Password strength */
    .strength-wrap { margin-top: 5px; }
    .strength-bar-bg { height: 3px; background: var(--gray-200); border-radius: 2px; overflow: hidden; }
    .strength-bar    { height: 100%; width: 0; border-radius: 2px; transition: width 0.3s, background 0.3s; }
    .strength-label  { font-size: 11px; color: var(--gray-400); margin-top: 3px; }

    /* Confirm match indicator */
    .match-hint { font-size: 11px; margin-top: 4px; }
    .match-hint.ok  { color: var(--success); }
    .match-hint.bad { color: var(--danger); }

    /* Field-level error */
    .field-err { font-size: 11px; color: var(--danger); margin-top: 3px; display: none; }

    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

    /* ── ALERT ── */
    .alert {
      padding: 10px 13px; border-radius: var(--radius-sm);
      font-size: 12.5px; font-weight: 500; margin-bottom: 16px;
      display: flex; align-items: flex-start; gap: 8px;
    }
    .alert svg { flex-shrink: 0; margin-top: 1px; }
    .alert-danger  { background: var(--danger-bg); color: var(--danger); border: 1px solid #ffd0d0; }
    .alert-success { background: #e8faf3; color: var(--success); border: 1px solid #b3ecd6; }

    /* ── SUBMIT BUTTON ── */
    .btn-submit {
      width: 100%; padding: 13px;
      background: var(--navy); color: white; border: none;
      border-radius: var(--radius-sm); font-size: 14px; font-weight: 600;
      font-family: 'DM Sans', sans-serif; cursor: pointer;
      transition: background 0.2s, transform 0.15s, box-shadow 0.2s;
      margin-top: 8px; position: relative; overflow: hidden;
      display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .btn-submit::after {
      content: ''; position: absolute; inset: 0;
      background: linear-gradient(135deg, rgba(255,255,255,0.08) 0%, transparent 60%);
      pointer-events: none;
    }
    .btn-submit:hover:not(:disabled) { background: var(--navy-light); box-shadow: 0 6px 20px rgba(10,35,66,0.25); transform: translateY(-1px); }
    .btn-submit:active:not(:disabled) { transform: translateY(0); }
    .btn-submit:disabled { opacity: 0.7; cursor: not-allowed; }

    .spinner {
      width: 15px; height: 15px;
      border: 2px solid rgba(255,255,255,0.35);
      border-top-color: white; border-radius: 50%;
      animation: spin 0.7s linear infinite; display: none;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* ── FOOTER ── */
    .form-footer {
      margin-top: 20px; text-align: center;
      font-size: 12px; color: var(--gray-400); line-height: 1.7;
    }
    .form-footer a { color: var(--blue); text-decoration: none; font-weight: 600; }
    .form-footer a:hover { text-decoration: underline; }

    .slide-in { animation: slideIn 0.4s cubic-bezier(0.22, 1, 0.36, 1) both; }
    @keyframes slideIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

    @media (max-width: 900px) {
      .panel-left { display: none; }
      .divider     { display: none; }
      .panel-right { width: 100%; padding: 32px 24px; }
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
          <path d="M12 4v4M12 16v4M4 12h4M16 12h4"/><circle cx="12" cy="12" r="4"/>
        </svg>
      </div>
      <div>
        <div class="brand-name">Health Monitoring Web System</div>
        <div class="brand-sub">OMSC San Jose Campus</div>
      </div>
    </div>

    <div class="hero-tag"><span class="hero-dot"></span>Create Your Account</div>

    <h1 class="hero-title">Join the<br><em>health portal.</em></h1>

    <p class="hero-desc">
      Register as a faculty or staff member of OMSC San Jose Campus to access your personalized health dashboard.
    </p>

    <div class="steps">
      <div class="step">
        <div class="step-num">1</div>
        <div class="step-text">
          <strong>Fill in your details</strong>
          <span>Enter your name, email, and work information</span>
        </div>
      </div>
      <div class="step">
        <div class="step-num">2</div>
        <div class="step-text">
          <strong>Create a secure password</strong>
          <span>At least 6 characters to keep your account safe</span>
        </div>
      </div>
      <div class="step">
        <div class="step-num">3</div>
        <div class="step-text">
          <strong>Check your email</strong>
          <span>Your Employee ID will be sent to your inbox automatically</span>
        </div>
      </div>
    </div>
  </div>

  <div class="divider"></div>

  <!-- Right Panel -->
  <div class="panel-right">
    <div class="form-inner">

      <div class="auth-tabs">
        <div class="tab-pill"></div>
        <a href="index.php" class="tab-btn">Sign In</a>
        <a href="register.php" class="tab-btn active">Register</a>
      </div>

      <div class="form-heading slide-in">
        <h2>Create your account</h2>
        <p>All fields marked <span style="color:var(--blue);">*</span> are required</p>
      </div>

      <?php if (!empty($error)): ?>
        <div class="alert alert-danger slide-in">
          <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <div class="auto-id-note slide-in">
        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
        <span>Your <strong>Employee ID</strong> will be automatically generated after registration.</span>
      </div>

      <div class="email-note slide-in">
        <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
          <path stroke-linecap="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
        </svg>
        <span>Your <strong>Employee ID will be emailed</strong> to you after registration. Check your inbox!</span>
      </div>

      <form method="POST" autocomplete="off" class="slide-in" id="regForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <div class="section-label">Account Info</div>

        <div class="form-row">
          <div class="form-group">
            <label>Full Name <span class="req">*</span></label>
            <div class="input-wrap">
              <svg class="icon-left" width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
              </svg>
              <input type="text" name="full_name" id="full_name" placeholder="Juan dela Cruz"
                value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>" required>
            </div>
          </div>
          <div class="form-group">
            <label>Email Address <span class="req">*</span></label>
            <div class="input-wrap">
              <svg class="icon-left" width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
              </svg>
              <input type="email" name="email" id="email" placeholder="juan@omsc.edu.ph"
                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
            </div>
            <div class="field-err" id="email-err">Please enter a valid email address.</div>
          </div>
        </div>

        <div class="section-label">Work Details</div>

        <div class="form-row">
          <div class="form-group">
            <label>Department</label>
            <div class="input-wrap">
              <svg class="icon-left" width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
              </svg>
              <input type="text" name="department" placeholder="e.g. CAS, CIT, COE"
                value="<?= htmlspecialchars($_POST['department'] ?? '') ?>">
            </div>
          </div>
          <div class="form-group">
            <label>Position</label>
            <div class="input-wrap">
              <svg class="icon-left" width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
              </svg>
              <input type="text" name="position" placeholder="e.g. Instructor I"
                value="<?= htmlspecialchars($_POST['position'] ?? '') ?>">
            </div>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Contact Number</label>
            <div class="input-wrap">
              <svg class="icon-left" width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
              </svg>
              <input type="text" name="contact_number" placeholder="+63 9XX XXX XXXX"
                value="<?= htmlspecialchars($_POST['contact_number'] ?? '') ?>">
            </div>
          </div>
          <div class="form-group">
            <label>Date of Birth</label>
            <div class="input-wrap">
              <svg class="icon-left" width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>
              </svg>
              <input type="date" name="birthdate" max="<?= date('Y-m-d') ?>"
                value="<?= htmlspecialchars($_POST['birthdate'] ?? '') ?>">
            </div>
          </div>
        </div>

        <div class="section-label">Security</div>

        <div class="form-row">
          <div class="form-group">
            <label>Password <span class="req">*</span></label>
            <div class="input-wrap">
              <svg class="icon-left" width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>
              </svg>
              <input type="password" name="password" id="pw" placeholder="Min. 6 characters" required>
              <button type="button" class="toggle-pw" onclick="togglePw('pw', this)" aria-label="Show password">
                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                  <path stroke-linecap="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                  <path stroke-linecap="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                </svg>
              </button>
            </div>
            <div class="strength-wrap">
              <div class="strength-bar-bg"><div class="strength-bar" id="strengthBar"></div></div>
              <div class="strength-label" id="strengthLabel"></div>
            </div>
          </div>
          <div class="form-group">
            <label>Confirm Password <span class="req">*</span></label>
            <div class="input-wrap">
              <svg class="icon-left" width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
              </svg>
              <input type="password" name="confirm_password" id="cpw" placeholder="Re-enter password" required>
              <button type="button" class="toggle-pw" onclick="togglePw('cpw', this)" aria-label="Show confirm password">
                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                  <path stroke-linecap="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                  <path stroke-linecap="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                </svg>
              </button>
            </div>
            <div class="match-hint" id="matchHint"></div>
          </div>
        </div>

        <button type="submit" class="btn-submit" id="regBtn">
          <span class="spinner" id="regSpinner"></span>
          <span id="regBtnText">Create Account →</span>
        </button>
      </form>

      <div class="form-footer">
        Already have an account? <a href="index.php">Sign in here</a><br><br>
        <span style="font-size:11px;color:#b0bec5;">By registering, you agree to OMSC Health's data privacy policy.<br>Your information is kept confidential and secure.</span>
      </div>

    </div>
  </div>
</div>

<script>
// ── Show / Hide password ─────────────────────────────────────────────────────
function togglePw(inputId, btn) {
  const inp = document.getElementById(inputId);
  const isHidden = inp.type === 'password';
  inp.type = isHidden ? 'text' : 'password';
  btn.innerHTML = isHidden
    ? `<svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
        <path stroke-linecap="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
      </svg>`
    : `<svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
        <path stroke-linecap="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
        <path stroke-linecap="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
      </svg>`;
}

// ── Password strength ────────────────────────────────────────────────────────
const pwInput     = document.getElementById('pw');
const cpwInput    = document.getElementById('cpw');
const strengthBar = document.getElementById('strengthBar');
const strengthLbl = document.getElementById('strengthLabel');
const matchHint   = document.getElementById('matchHint');

function calcStrength(pw) {
  let score = 0;
  if (pw.length >= 6)  score++;
  if (pw.length >= 10) score++;
  if (/[A-Z]/.test(pw)) score++;
  if (/[0-9]/.test(pw)) score++;
  if (/[^A-Za-z0-9]/.test(pw)) score++;
  return score;
}

pwInput.addEventListener('input', function() {
  const score = calcStrength(this.value);
  const pct   = Math.min(score / 5 * 100, 100);
  const colors = ['#e03434','#e03434','#d97706','#1d6fc4','#0d8a5a'];
  const labels = ['','Weak','Fair','Good','Strong','Very Strong'];
  strengthBar.style.width      = pct + '%';
  strengthBar.style.background = colors[Math.max(score-1,0)];
  strengthLbl.textContent      = this.value.length ? labels[score] || 'Very Strong' : '';
  checkMatch();
});

cpwInput.addEventListener('input', checkMatch);

function checkMatch() {
  if (!cpwInput.value) { matchHint.textContent = ''; matchHint.className = 'match-hint'; return; }
  if (pwInput.value === cpwInput.value) {
    matchHint.textContent = '✓ Passwords match';
    matchHint.className   = 'match-hint ok';
  } else {
    matchHint.textContent = '✗ Passwords do not match';
    matchHint.className   = 'match-hint bad';
  }
}

// ── Email real-time validation ───────────────────────────────────────────────
const emailInput = document.getElementById('email');
const emailErr   = document.getElementById('email-err');
emailInput.addEventListener('blur', function() {
  const valid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.value);
  if (this.value && !valid) {
    emailErr.style.display = 'block';
    this.classList.add('invalid');
  } else {
    emailErr.style.display = 'none';
    this.classList.remove('invalid');
  }
});

// ── Loading spinner on submit ────────────────────────────────────────────────
document.getElementById('regForm').addEventListener('submit', function(e) {
  // Client-side confirm match
  if (pwInput.value !== cpwInput.value) {
    e.preventDefault();
    matchHint.textContent = '✗ Passwords do not match';
    matchHint.className   = 'match-hint bad';
    cpwInput.focus();
    return;
  }
  const btn     = document.getElementById('regBtn');
  const spinner = document.getElementById('regSpinner');
  const text    = document.getElementById('regBtnText');
  btn.disabled          = true;
  spinner.style.display = 'block';
  text.textContent      = 'Creating account…';
});
</script>
</body>
</html>
