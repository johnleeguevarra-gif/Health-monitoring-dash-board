<?php
session_start();
session_unset();
session_destroy();
header("Location: /omsc-health/index.php");
exit();
?>