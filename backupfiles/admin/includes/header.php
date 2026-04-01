<?php
// 简化版header.php文件
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title ?? '管理系统'); ?>-管理后台</title>
    <!-- Favicon -->
    <link rel="shortcut icon" href="assets/picture/logo.png">

    <!-- Core css -->
    <link href="assets/css/app.min.css" rel="stylesheet">
    <link href="assets/css/admin.css" rel="stylesheet">
    <!-- Bootstrap Icons (本地) -->
    <link href="assets/icons/bootstrap-icons.css" rel="stylesheet">
    
    <!-- Bootstrap CSS -->
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Personnel Enhanced CSS -->
    <link href="assets/css/personnel-optimized.css" rel="stylesheet">
    
    <!-- ApexCharts CSS -->
    <link href="assets/css/apexcharts.css" rel="stylesheet">
</head>
<body>
    <!-- 顶部导航栏 -->
    <nav class="header-nav navbar navbar-expand-lg navbar-light bg-white shadow-sm fixed-top">
        <div class="container-fluid">
            <div class="d-flex align-items-center">
                <div class="mobile-toggle me-3">
                    <button class="btn btn-icon btn-flat-primary" id="mobileToggle" type="button">
                        <i class="bi bi-list"></i>
                    </button>
                </div>
 
            </div>
            <div class="d-flex align-items-center">
                <!-- 通知下拉菜单 -->
                <div class="dropdown me-3">
                    <button class="btn btn-icon btn-flat-primary" type="button" id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-bell"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="notificationDropdown">
                        <li><h6 class="dropdown-header">通知</h6></li>
                        <li><a class="dropdown-item" href="#">暂无新通知</a></li>
                    </ul>
                </div>
                <!-- 管理员下拉菜单 -->
                <div class="dropdown">
                    <button class="btn btn-icon btn-flat-primary dropdown-toggle" type="button" id="profileDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="profileDropdown">
                        <li><h6 class="dropdown-header">管理员: <?php echo isset($_SESSION['admin_username']) ? htmlspecialchars($_SESSION['admin_username']) : '未知'; ?></h6></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>个人资料</a></li>
                        <li><a class="dropdown-item" href="?logout=1"><i class="bi bi-box-arrow-right me-2"></i>退出登录</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <!-- 侧边栏 -->
    <div class="side-nav" id="sideNav">
        <?php require_once 'sidebar.php'; ?>
    </div>

    <!-- 主内容区域 -->
    <div class="main-content">