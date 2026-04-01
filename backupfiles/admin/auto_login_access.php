<?php
session_start();

// 设置管理员会话
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_username'] = 'admin';
$_SESSION['admin_id'] = 1;

// 重定向到 personnel_project_access.php 页面，使用正确的人员ID
header("Location: personnel_project_access.php?id=21");
exit;
?>