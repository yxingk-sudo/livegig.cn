<?php
// 简单的认证检查 - 确保用户已登录
if (!isset($_SESSION)) {
    session_start();
}

// 检查用户是否已登录
if (!isset($_SESSION['user_id']) || !isset($_SESSION['project_id'])) {
    // 重定向到登录页面
    header('Location: login.php');
    exit();
}

// 设置项目ID
if (!isset($projectId) && isset($_SESSION['project_id'])) {
    $projectId = $_SESSION['project_id'];
}
?>