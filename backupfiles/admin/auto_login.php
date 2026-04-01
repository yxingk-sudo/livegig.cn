<?php
session_start();
require_once '../config/database.php';

// 自动登录为管理员
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_username'] = 'admin';

header("Location: personnel.php");
exit;
?>