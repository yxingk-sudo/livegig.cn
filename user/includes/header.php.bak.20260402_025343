<?php
// 统一头部文件 - 包含完整的导航栏和样式
// 确保输出缓冲正确管理
if (!isset($page_title)) {
    $page_title = '企业项目管理系统';
}
require_once '../config/database.php';
require_once '../includes/functions.php';
// 全局输出缓冲：避免因提前输出导致的 headers already sent 警告
if (!headers_sent() && !ob_get_level()) {
    ob_start();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        /* 统一头部样式增强 */
        .navbar-brand {
            font-weight: 700;
            font-size: 1.1rem;
        }
        .nav-link {
            font-weight: 500;
            transition: all 0.3s ease;
            border-radius: 0.375rem;
            margin: 0 0.125rem;
        }
        .nav-link:hover {
            background-color: rgba(255, 255, 255, 0.1);
            transform: translateY(-1px);
        }
        .nav-link.active {
            background-color: rgba(255, 255, 255, 0.2) !important;
            font-weight: 600 !important;
        }
        .navbar.shadow-sm {
            box-shadow: 0 2px 8px rgba(0,0,0,.15) !important;
        }
        .dropdown-menu {
            border: none;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            border-radius: 0.5rem;
        }
        .dropdown-item i {
            margin-right: 0.5rem;
            width: 1rem;
            text-align: center;
        }
    </style>
    <!-- 添加 Bootstrap JS -->
    <script src="assets/js/bootstrap.bundle.min.js"></script>
</head>
<body>
    <!-- 统一导航栏 -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="bi bi-building"></i> 
                <?php echo htmlspecialchars($_SESSION['project_name'] ?? '项目管理系统'); ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <!-- 隐藏仪表板菜单项，因为顶部项目名已经链接到dashboard.php -->
                    <?php /* 
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active_page ?? '') === 'dashboard' ? 'active' : ''; ?>" 
                           href="./dashboard.php">
                            <i class="bi bi-speedometer2"></i> 仪表板
                        </a>
                    </li>
                    */ ?>
                    <!-- 人员管理下拉菜单 -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo in_array($active_page ?? '', ['personnel', 'batch_add_personnel']) ? 'active' : ''; ?>" 
                           href="#" id="personnelDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-people"></i> 人员
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item <?php echo ($active_page ?? '') === 'batch_add_personnel' ? 'active' : ''; ?>" 
                                   href="./batch_add_personnel.php">
                                    <i class="bi bi-person-plus"></i> 添加人员
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?php echo ($active_page ?? '') === 'personnel' ? 'active' : ''; ?>" 
                                   href="./personnel.php">
                                    <i class="bi bi-people"></i> 人员管理
                                </a>
                            </li>
                        </ul>
                    </li>
                    <!-- 报餐管理下拉菜单 -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo in_array($active_page ?? '', ['meals', 'meals_new', 'meals_statistics', 'batch_meal_order', 'meal_allowance']) ? 'active' : ''; ?>" 
                           href="#" id="mealsDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-cup-hot"></i> 报餐
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item <?php echo ($active_page ?? '') === 'batch_meal_order' ? 'active' : ''; ?>" 
                                   href="./batch_meal_order.php">
                                    <i class="bi bi-calendar-plus"></i> 报餐
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?php echo ($active_page ?? '') === 'meals_new' ? 'active' : ''; ?>" 
                                   href="./meals_new.php">
                                    <i class="bi bi-calendar-check"></i> 报餐统计
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?php echo ($active_page ?? '') === 'meals_statistics' ? 'active' : ''; ?>" 
                                   href="./meals_statistics.php">
                                    <i class="bi bi-graph-up"></i> 报餐记录
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?php echo ($active_page ?? '') === 'meal_allowance' ? 'active' : ''; ?>" 
                                   href="./meal_allowance.php">
                                    <i class="bi bi-cash"></i> 餐费补助明细
                                </a>
                            </li>
                        </ul>
                    </li>
                    <!-- 酒店管理下拉菜单 -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo in_array($active_page ?? '', ['hotel_add', 'hotels', 'hotel_statistics']) ? 'active' : ''; ?>" 
                           href="#" id="hotelDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-building"></i> 酒店
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item <?php echo ($active_page ?? '') === 'hotel_add' ? 'active' : ''; ?>" 
                                   href="./hotel_add.php">
                                    <i class="bi bi-plus-circle"></i> 添加入住
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?php echo ($active_page ?? '') === 'hotels' ? 'active' : ''; ?>" 
                                   href="./hotels.php">
                                    <i class="bi bi-building"></i> 入住记录
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?php echo ($active_page ?? '') === 'hotel_statistics' ? 'active' : ''; ?>" 
                                   href="./hotel_statistics.php">
                                    <i class="bi bi-bar-chart"></i> 入住统计
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?php echo ($active_page ?? '') === 'hotel_room_list' ? 'active' : ''; ?>" 
                                   href="./hotel_room_list.php" target="_blank" rel="noopener noreferrer">
                                    <i class="bi bi-list"></i> 房表一
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?php echo ($active_page ?? '') === 'hotel_room_list_2' ? 'active' : ''; ?>" 
                                   href="./hotel_room_list_2.php" target="_blank" rel="noopener noreferrer">
                                    <i class="bi bi-list"></i> 房表二
                                </a>
                            </li>
                        </ul>
                    </li>

                    <?php /* 隐藏交通预订菜单项
                    <li class="nav-item">
                        <a class="nav-link <?php echo ($active_page ?? '') === 'transport' ? 'active' : ''; ?>" 
                           href="./transport_enhanced.php">
                            <i class="bi bi-truck"></i> 交通预订
                        </a>
                    </li>
                    */ ?>
                    <!-- 行程安排下拉菜单 -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo in_array($active_page ?? '', ['quick_transport', 'transport_enhanced', 'transport_list', 'export_transport', 'fleet']) ? 'active' : ''; ?>" 
                           href="#" id="transportDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-calendar3-range"></i> 行程
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item <?php echo ($active_page ?? '') === 'quick_transport' ? 'active' : ''; ?>" 
                                   href="./quick_transport.php">
                                    <i class="bi bi-lightning-fill"></i> 快速安排
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?php echo ($active_page ?? '') === 'transport_enhanced' ? 'active' : ''; ?>" 
                                   href="./transport_enhanced.php">
                                    <i class="bi bi-list-check"></i> 批量安排
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?php echo ($active_page ?? '') === 'transport_list' ? 'active' : ''; ?>" 
                                   href="./transport_list.php">
                                    <i class="bi bi-calendar-week"></i> 车程管理
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?php echo ($active_page ?? '') === 'fleet' ? 'active' : ''; ?>" 
                                   href="./project_fleet.php">
                                    <i class="bi bi-car-front"></i> 车队信息
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?php echo ($active_page ?? '') === 'export_transport' ? 'active' : ''; ?>" 
                                   href="./export_transport_html.php?sort=asc" target="_blank" rel="noopener noreferrer">
                                    <i class="bi bi-download"></i> 车程表
                                </a>
                            </li>
                        </ul>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" 
                           data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle"></i> 
                            <?php 
                            $display_name = $_SESSION['display_name'] ?? $_SESSION['username'] ?? '用户';
                            echo htmlspecialchars($display_name); 
                            ?>
                            <span class="badge bg-light text-primary ms-1">
                                <?php echo ($_SESSION['role'] ?? 'user') === 'admin' ? '管理员' : '普通用户'; ?>
                            </span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="profile.php">
                                <i class="bi bi-person-circle"></i> 个人资料
                            </a></li>
                            <?php if (($_SESSION['role'] ?? 'user') === 'admin'): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="user_permission_management.php">
                                <i class="bi bi-shield-lock"></i> 权限管理
                            </a></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="logout.php">
                                <i class="bi bi-box-arrow-right"></i> 退出登录
                            </a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    
    <!-- 页面标题区域 -->
    <?php if (isset($show_page_title)): ?>
    <div class="container-fluid mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h4 fw-bold text-primary">
                <i class="bi bi-<?php echo $page_icon ?? 'app'; ?>"></i> 
                <?php echo htmlspecialchars($show_page_title); ?>
            </h1>
            <?php if (isset($page_action_url) && isset($page_action_text) && basename($_SERVER['PHP_SELF']) !== 'transport_enhanced.php'): ?>
            <div>
                <a href="<?php echo htmlspecialchars($page_action_url); ?>" class="btn btn-primary shadow-sm">
                    <i class="bi bi-plus-circle"></i> <?php echo htmlspecialchars($page_action_text); ?>
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>
<?php endif; ?>
