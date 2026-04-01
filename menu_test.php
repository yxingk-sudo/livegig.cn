<?php
session_start();
$_SESSION['admin_logged_in'] = true;
$_SESSION['admin_username'] = 'test_admin';

// 设置菜单激活变量
$current_page = 'fleet_management.php';

// 模拟检查激活状态
function checkMenuActivation() {
    global $current_page;
    return $current_page == 'fleet_management.php';
}

$isActive = checkMenuActivation();
echo "菜单激活状态: " . ($isActive ? "✅ ACTIVE" : "❌ INACTIVE") . "\n";
echo "当前 \$current_page: " . ($current_page ?? 'NOT SET') . "\n";
?>
<!DOCTYPE html>
<html>
<head>
    <title>菜单测试</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <h2>菜单激活测试</h2>
        <p>当前页面: add_fleet.php</p>
        <p>菜单激活状态: <?php echo $isActive ? '<span class="text-success">ACTIVE</span>' : '<span class="text-danger">INACTIVE</span>'; ?></p>
        
        <div class="card mt-3">
            <div class="card-header">
                <h5>模拟侧边栏菜单</h5>
            </div>
            <div class="card-body">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $isActive ? 'active bg-primary text-white' : 'text-dark'; ?>" href="#">
                            <i class="bi bi-truck me-2"></i>车队管理
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link text-dark" href="#">
                            <i class="bi bi-people me-2"></i>人员管理
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>