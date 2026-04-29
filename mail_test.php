  <?php
require_once 'includes/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

function maskValue($value)
{
    if ($value === '') {
        return '(empty)';
    }

    $length = strlen($value);
    if ($length <= 4) {
        return str_repeat('*', $length);
    }

    return substr($value, 0, 2) . str_repeat('*', max($length - 4, 1)) . substr($value, -2);
}

function sendOtpTestEmail($to, $otp_code, &$smtpLog)
{
    $subject = "Your OMSC Health Login OTP";
    $message = "
    <html>
    <body style='font-family:Arial,sans-serif;line-height:1.6;color:#0e1c2f;'>
      <h2 style='margin-bottom:8px;'>OMSC Health Monitor</h2>
      <p>Hello,</p>
      <p>Your one-time password (OTP) for login is:</p>
      <p style='font-size:28px;font-weight:700;letter-spacing:4px;margin:14px 0;color:#0a2342;'>{$otp_code}</p>
      <p>This code expires in 5 minutes. If you did not request this login, please ignore this email.</p>
    </body>
    </html>";
    $alt_message = "Hello,\n\nYour OMSC Health login OTP is: {$otp_code}\n\nThis code expires in 5 minutes.";

    $smtpPassword = preg_replace('/\s+/', '', MAIL_PASSWORD);

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = MAIL_HOST;
    $mail->Port = MAIL_PORT;
    $mail->SMTPAuth = true;
    $mail->Username = MAIL_USERNAME;
    $mail->Password = $smtpPassword;
    $mail->Timeout = defined('MAIL_TIMEOUT') ? MAIL_TIMEOUT : 30;
    $mail->SMTPSecure = MAIL_ENCRYPTION === 'ssl'
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->SMTPDebug = SMTP::DEBUG_SERVER;
    $mail->Debugoutput = static function ($str, $level) use (&$smtpLog) {
        $smtpLog .= trim((string)$str) . "\n";
    };

    $mail->CharSet = 'UTF-8';
    $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
    $mail->addAddress($to);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $message;
    $mail->AltBody = $alt_message;

    return $mail->send();
}

$result = null;
$smtpLog = '';
$generatedOtp = '';
$testMode = $_POST['test_mode'] ?? 'generic';
$isSuccess = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = trim($_POST['to_email'] ?? '');

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $result = 'Enter a valid recipient email address.';
    } elseif (MAIL_USERNAME === 'your-email@gmail.com' || MAIL_PASSWORD === 'your-app-password') {
        $result = 'SMTP credentials are still placeholders in includes/config.php.';
    } else {
        try {
            if ($testMode === 'otp') {
                $generatedOtp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $sent = sendOtpTestEmail($to, $generatedOtp, $smtpLog);
                if ($sent) {
                    $result = 'OTP email sent successfully. Check your inbox for the 6-digit code.';
                    $isSuccess = true;
                } else {
                    $result = 'OTP email could not be sent.';
                }
            } else {
                $smtpPassword = preg_replace('/\s+/', '', MAIL_PASSWORD);

                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = MAIL_HOST;
                $mail->Port = MAIL_PORT;
                $mail->SMTPAuth = true;
                $mail->Username = MAIL_USERNAME;
                $mail->Password = $smtpPassword;
                $mail->Timeout = defined('MAIL_TIMEOUT') ? MAIL_TIMEOUT : 30;
                $mail->SMTPSecure = MAIL_ENCRYPTION === 'ssl'
                    ? PHPMailer::ENCRYPTION_SMTPS
                    : PHPMailer::ENCRYPTION_STARTTLS;
                $mail->SMTPDebug = SMTP::DEBUG_SERVER;
                $mail->Debugoutput = static function ($str, $level) use (&$smtpLog) {
                    $smtpLog .= trim((string)$str) . "\n";
                };

                $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
                $mail->addAddress($to);
                $mail->isHTML(true);
                $mail->Subject = 'PHPMailer test message';
                $mail->Body = '<p>This is a PHPMailer SMTP test message from OMSC Health.</p>';
                $mail->AltBody = 'This is a PHPMailer SMTP test message from OMSC Health.';
                $mail->send();
                $result = 'Message sent successfully.';
                $isSuccess = true;
            }
        } catch (\Throwable $e) {
            $result = 'Message could not be sent: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mail Debug — OMSC Health</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy: #0a2342;
            --navy-mid: #0d2f5c;
            --navy-light: #1a4080;
            --blue: #1d6fc4;
            --blue-light: #2d84e8;
            --white: #ffffff;
            --off-white: #f7f9fc;
            --gray-100: #edf1f7;
            --gray-200: #d4dcea;
            --gray-300: #b0c0d8;
            --gray-400: #8899b4;
            --gray-600: #4a5f7d;
            --text: #0e1c2f;
            --danger: #e03434;
            --danger-bg: #fff0f0;
            --success: #0d8a5a;
            --success-bg: #e8faf3;
            --warning: #f0a030;
            --warning-bg: #fff8e6;
            --radius: 14px;
            --radius-sm: 10px;
            --shadow-sm: 0 1px 3px rgba(10,35,66,0.06);
            --shadow: 0 4px 24px rgba(10,35,66,0.10);
            --shadow-lg: 0 12px 40px rgba(10,35,66,0.14);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--off-white);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.6;
        }

        /* ── HEADER ── */
        .header {
            background: var(--navy);
            position: relative;
            overflow: hidden;
        }
        .header::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--blue), var(--blue-light), #4de0b0);
        }
        .header-inner {
            max-width: 860px;
            margin: 0 auto;
            padding: 40px 28px 36px;
            position: relative;
            z-index: 1;
        }
        .header-brand {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 20px;
        }
        .header-brand-icon {
            width: 44px; height: 44px;
            background: rgba(255,255,255,0.10);
            border: 1px solid rgba(255,255,255,0.18);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            backdrop-filter: blur(8px);
        }
        .header-brand-text h1 {
            font-size: 22px;
            font-weight: 700;
            color: white;
            letter-spacing: -0.3px;
        }
        .header-brand-text span {
            font-size: 11px;
            color: rgba(255,255,255,0.50);
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .header-desc {
            font-size: 14px;
            color: rgba(255,255,255,0.60);
            max-width: 480px;
        }

        /* ── BLOB DECORATIONS ── */
        .blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(60px);
            opacity: 0.18;
            pointer-events: none;
        }
        .blob-1 { width: 300px; height: 300px; background: var(--blue-light); top: -80px; right: -60px; }
        .blob-2 { width: 200px; height: 200px; background: #4de0b0; bottom: -60px; left: 10%; opacity: 0.10; }

        /* ── LAYOUT ── */
        .container {
            max-width: 860px;
            margin: 0 auto;
            padding: 32px 28px 60px;
        }

        /* ── CARDS ── */
        .card {
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 24px;
            overflow: hidden;
            animation: slideUp 0.5s ease both;
        }
        .card:nth-child(1) { animation-delay: 0.05s; }
        .card:nth-child(2) { animation-delay: 0.10s; }
        .card:nth-child(3) { animation-delay: 0.15s; }
        .card:nth-child(4) { animation-delay: 0.20s; }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .card-header {
            padding: 22px 28px 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .card-header h2 {
            font-size: 17px;
            font-weight: 700;
            color: var(--navy);
        }
        .card-body {
            padding: 18px 28px 28px;
        }
        .card-body p {
            font-size: 13.5px;
            color: var(--gray-600);
            margin-bottom: 16px;
        }

        /* ── INFO GRID ── */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
        }
        .info-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            background: var(--off-white);
            border-radius: var(--radius-sm);
            font-size: 13px;
        }
        .info-item .label {
            color: var(--gray-400);
            font-weight: 500;
        }
        .info-item .value {
            color: var(--text);
            font-weight: 600;
            margin-left: auto;
        }
        .status-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            background: var(--success);
            flex-shrink: 0;
        }
        .status-dot.warning { background: var(--warning); }

        /* ── FORM ── */
        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--gray-600);
            margin-bottom: 7px;
        }
        .input-wrap {
            position: relative;
        }
        .input-wrap input[type="email"] {
            width: 100%;
            padding: 13px 16px 13px 44px;
            border: 1.5px solid var(--gray-200);
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-family: 'DM Sans', sans-serif;
            color: var(--text);
            background: var(--off-white);
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
            outline: none;
        }
        .input-wrap input[type="email"]:focus {
            border-color: var(--blue);
            background: white;
            box-shadow: 0 0 0 3px rgba(29,111,196,0.10);
        }
        .input-wrap .input-icon {
            position: absolute;
            left: 14px; top: 50%; transform: translateY(-50%);
            color: var(--gray-400);
            pointer-events: none;
        }

        /* ── RADIO GROUP ── */
        .mode-select {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .mode-option {
            flex: 1;
            min-width: 140px;
            position: relative;
        }
        .mode-option input {
            position: absolute;
            opacity: 0;
            width: 0; height: 0;
        }
        .mode-option .mode-box {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 18px 16px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-sm);
            cursor: pointer;
            transition: all 0.2s;
            background: var(--off-white);
        }
        .mode-option .mode-box:hover {
            border-color: var(--gray-300);
            background: white;
        }
        .mode-option input:checked + .mode-box {
            border-color: var(--blue);
            background: white;
            box-shadow: 0 0 0 3px rgba(29,111,196,0.10);
        }
        .mode-option input:focus + .mode-box {
            outline: 2px solid var(--blue-light);
            outline-offset: 2px;
        }
        .mode-icon {
            width: 40px; height: 40px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
            background: var(--gray-100);
            transition: background 0.2s;
        }
        .mode-option input:checked + .mode-box .mode-icon {
            background: var(--blue);
            color: white;
        }
        .mode-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--gray-600);
        }
        .mode-option input:checked + .mode-box .mode-label {
            color: var(--navy);
        }
        .mode-desc {
            font-size: 11.5px;
            color: var(--gray-400);
            text-align: center;
        }

        /* ── BUTTON ── */
        .btn-submit {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            background: var(--navy);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-weight: 600;
            font-family: 'DM Sans', sans-serif;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 4px;
        }
        .btn-submit:hover {
            background: var(--navy-light);
            box-shadow: 0 6px 20px rgba(10,35,66,0.25);
            transform: translateY(-1px);
        }
        .btn-submit:active { transform: translateY(0); }
        .btn-submit.otp-mode { background: var(--blue); }
        .btn-submit.otp-mode:hover { background: var(--blue-light); }

        /* ── ALERT / RESULT ── */
        .result-box {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 16px 18px;
            border-radius: var(--radius-sm);
            font-size: 13.5px;
            font-weight: 500;
            animation: slideUp 0.4s ease both;
        }
        .result-box.success {
            background: var(--success-bg);
            color: var(--success);
            border: 1px solid #b3ecd6;
        }
        .result-box.error {
            background: var(--danger-bg);
            color: var(--danger);
            border: 1px solid #ffd0d0;
        }
        .result-icon {
            width: 20px; height: 20px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        /* ── OTP DISPLAY ── */
        .otp-display {
            text-align: center;
            padding: 28px;
            background: linear-gradient(135deg, #e8faf3 0%, #f0fdf8 100%);
            border: 1px solid #b3ecd6;
            border-radius: var(--radius-sm);
            animation: slideUp 0.5s ease both;
        }
        .otp-display .otp-label {
            font-size: 12px;
            color: var(--gray-400);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }
        .otp-display .otp-code {
            font-size: 42px;
            font-weight: 700;
            color: var(--navy);
            letter-spacing: 8px;
            font-family: 'DM Mono', monospace;
        }
        .otp-display .otp-hint {
            font-size: 12.5px;
            color: var(--gray-600);
            margin-top: 10px;
        }

        /* ── LOG BOX ── */
        .log-box {
            background: #0e1c2f;
            color: #c8d4e4;
            padding: 18px 22px;
            border-radius: var(--radius-sm);
            font-family: 'SF Mono', 'Fira Code', monospace;
            font-size: 12px;
            line-height: 1.7;
            white-space: pre-wrap;
            overflow-x: auto;
            max-height: 400px;
            overflow-y: auto;
        }
        .log-box::-webkit-scrollbar { width: 6px; }
        .log-box::-webkit-scrollbar-track { background: transparent; }
        .log-box::-webkit-scrollbar-thumb { background: var(--navy-light); border-radius: 3px; }

        /* ── TIP ── */
        .tip-box {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 14px 16px;
            background: var(--warning-bg);
            border: 1px solid #ffd699;
            border-radius: var(--radius-sm);
            font-size: 12.5px;
            color: #8a6000;
            margin-bottom: 20px;
        }
        .tip-box strong { color: #664800; }

        /* ── FOOTER ── */
        .page-footer {
            text-align: center;
            padding: 28px;
            font-size: 12px;
            color: var(--gray-400);
        }

        @media (max-width: 600px) {
            .header-inner { padding: 28px 20px 24px; }
            .container { padding: 20px 16px 40px; }
            .card-header, .card-body { padding-left: 20px; padding-right: 20px; }
            .mode-select { flex-direction: column; }
            .otp-display .otp-code { font-size: 32px; letter-spacing: 5px; }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
        <div class="header-inner">
            <div class="header-brand">
                <div class="header-brand-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round">
                        <path d="M12 4v4M12 16v4M4 12h4M16 12h4"/>
                        <circle cx="12" cy="12" r="4"/>
                    </svg>
                </div>
                <div class="header-brand-text">
                    <h1>Mail Debug</h1>
                    <span>OMSC Health Monitor</span>
                </div>
            </div>
            <p class="header-desc">Verify SMTP delivery independently from the OTP login flow. Test generic connectivity or send a real OTP email.</p>
        </div>
    </header>

    <div class="container">
        <!-- SMTP Config Card -->
        <div class="card">
            <div class="card-header">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--navy)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="3" width="20" height="14" rx="2"/><path d="M2 7l10 6 10-6"/>
                </svg>
                <h2>SMTP Configuration</h2>
            </div>
            <div class="card-body">
                <div class="info-grid">
                    <div class="info-item">
                        <span class="label">Host</span>
                        <span class="value"><?= htmlspecialchars(MAIL_HOST) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Port</span>
                        <span class="value"><?= (int) MAIL_PORT ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Encryption</span>
                        <span class="value"><?= htmlspecialchars(strtoupper(MAIL_ENCRYPTION)) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Username</span>
                        <span class="value"><?= htmlspecialchars(maskValue(MAIL_USERNAME)) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">From</span>
                        <span class="value"><?= htmlspecialchars(MAIL_FROM_ADDRESS) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="label">Password</span>
                        <span class="value" style="display:flex;align-items:center;gap:6px;">
                            <?php if (MAIL_PASSWORD !== 'your-app-password'): ?>
                                <span class="status-dot"></span> Set
                            <?php else: ?>
                                <span class="status-dot warning"></span> Not set
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Test Form Card -->
        <div class="card">
            <div class="card-header">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--navy)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/>
                </svg>
                <h2>Send Test Email</h2>
            </div>
            <div class="card-body">
                <div class="tip-box">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" style="flex-shrink:0;margin-top:1px;">
                        <circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>
                    </svg>
                    <div>
                        <strong>Tip:</strong> Choose <em>OTP Test</em> to verify that the 6-digit code appears in the email body. Choose <em>Generic Test</em> for a basic SMTP connectivity check.
                    </div>
                </div>

                <form method="post">
                    <div class="form-group">
                        <label for="to_email">Recipient email address</label>
                        <div class="input-wrap">
                            <svg class="input-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 8l10 6 10-6"/>
                            </svg>
                            <input id="to_email" name="to_email" type="email" placeholder="you@example.com" required value="<?= htmlspecialchars($_POST['to_email'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Test mode</label>
                        <div class="mode-select">
                            <label class="mode-option">
                                <input type="radio" name="test_mode" value="generic" <?= ($testMode !== 'otp') ? 'checked' : '' ?>>
                                <div class="mode-box">
                                    <div class="mode-icon">📡</div>
                                    <span class="mode-label">Generic Test</span>
                                    <span class="mode-desc">Basic SMTP check</span>
                                </div>
                            </label>
                            <label class="mode-option">
                                <input type="radio" name="test_mode" value="otp" <?= ($testMode === 'otp') ? 'checked' : '' ?>>
                                <div class="mode-box">
                                    <div class="mode-icon">🔐</div>
                                    <span class="mode-label">OTP Test</span>
                                    <span class="mode-desc">Real 6-digit code</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit <?= ($testMode === 'otp') ? 'otp-mode' : '' ?>">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/>
                        </svg>
                        Send Test Email
                    </button>
                </form>
            </div>
        </div>

        <!-- Result Card -->
        <?php if ($result !== null): ?>
        <div class="card" style="animation-delay:0.1s;">
            <div class="card-header">
                <?php if ($isSuccess): ?>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--success)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M5 13l4 4L19 7"/>
                </svg>
                <?php else: ?>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--danger)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/>
                </svg>
                <?php endif; ?>
                <h2 style="color:<?= $isSuccess ? 'var(--success)' : 'var(--danger)' ?>">Result</h2>
            </div>
            <div class="card-body">
                <div class="result-box <?= $isSuccess ? 'success' : 'error' ?>">
                    <?php if ($isSuccess): ?>
                    <svg class="result-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M5 13l4 4L19 7"/>
                    </svg>
                    <?php else: ?>
                    <svg class="result-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>
                    </svg>
                    <?php endif; ?>
                    <?= htmlspecialchars($result) ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- OTP Display Card -->
        <?php if ($generatedOtp !== ''): ?>
        <div class="card" style="animation-delay:0.15s;">
            <div class="card-header">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--navy)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>
                </svg>
                <h2>Generated OTP</h2>
            </div>
            <div class="card-body">
                <div class="otp-display">
                    <div class="otp-label">One-Time Password</div>
                    <div class="otp-code"><?= htmlspecialchars($generatedOtp) ?></div>
                    <div class="otp-hint">This is the code that was sent in the email. It expires in 5 minutes.</div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- SMTP Log Card -->
        <?php if ($smtpLog !== ''): ?>
        <div class="card" style="animation-delay:0.2s;">
            <div class="card-header">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--navy)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>
                </svg>
                <h2>SMTP Log</h2>
            </div>
            <div class="card-body" style="padding-top:0;">
                <div class="log-box"><?= htmlspecialchars($smtpLog) ?></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <footer class="page-footer">
        Occidental Mindoro State College · San Jose Campus · Health Monitoring System
    </footer>
</body>
</html>

