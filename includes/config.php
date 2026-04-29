<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'omsc_health_db');
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_USERNAME', 'castillojohnmarco8@gmail.com');
define('MAIL_PASSWORD', 'lnudbbcmpfkeqvhu');
define('MAIL_ENCRYPTION', 'tls');
define('MAIL_FROM_ADDRESS', 'admin@omsc.edu.ph');
define('MAIL_FROM_NAME', 'Health Monitoring Web System of OMSC San Jose Campus');
define('MAIL_TIMEOUT', 30);
define('MAIL_DEBUG', false);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

session_start();
?>