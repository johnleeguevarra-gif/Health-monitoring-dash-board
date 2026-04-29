<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

$log = '';

try {
    $smtpPassword = preg_replace('/\s+/', '', MAIL_PASSWORD);

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = MAIL_HOST;
    $mail->Port = MAIL_PORT;
    $mail->SMTPAuth = true;
    $mail->Username = MAIL_USERNAME;
    $mail->Password = $smtpPassword;
    $mail->SMTPSecure = MAIL_ENCRYPTION === 'ssl'
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Timeout = defined('MAIL_TIMEOUT') ? MAIL_TIMEOUT : 30;
    $mail->SMTPDebug = SMTP::DEBUG_SERVER;
    $mail->Debugoutput = static function ($str, $level) use (&$log) {
        $log .= trim((string) $str) . PHP_EOL;
    };

    $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
    $mail->addAddress(MAIL_USERNAME, 'Admin');
    $mail->Subject = 'OMSC Health SMTP Debug';
    $mail->Body = 'SMTP debug test message.';
    $mail->AltBody = 'SMTP debug test message.';
    $mail->send();

    echo "SEND_OK" . PHP_EOL;
    echo $log;
} catch (\Throwable $e) {
    echo "SEND_FAIL: " . $e->getMessage() . PHP_EOL;
    echo $log;
}
