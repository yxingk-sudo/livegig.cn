<?php
// 统一头部文件 - 包含导航栏和基础样式
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? '企业项目管理系统'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <!-- 统一导航栏 -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="dashboard.php">
                <i class="bi bi-building"></i> <?php echo $_SESSION['project_name'] ?? '企业项目'; ?> - 企业项目管理系统
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link fw-semibold <?php echo ($active_page ?? '') === 'dashboard' ? 'active' : ''; ?>" href="dashboard.php">
                            <i class="bi bi-speedometer2"></i> 仪表板
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link fw-semibold <?php echo ($active_page ?? '') === 'meals' ? 'active' : ''; ?>" href="meals.php">
                            <i class="bi bi-cup-hot"></i> 报餐管理
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link fw-semibold <?php echo ($active_page ?? '') === 'hotels' ? 'active' : ''; ?>" href="hotels.php">
                            <i class="bi bi-building"></i> 酒店预订
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link fw-semibold <?php echo ($active_page ?? '') === 'transport' ? 'active' : ''; ?>" href="transport.php">
                            <i class="bi bi-truck"></i> 交通预订
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link fw-semibold <?php echo ($active_page ?? '') === 'personnel' ? 'active' : ''; ?>" href="personnel.php">
                            <i class="bi bi-people"></i> 人员管理
                        </a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle fw-semibold" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle"></i> <?php echo $_SESSION['display_name'] ?? '用户'; ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow">
                            <li><a class="dropdown-item" href="profile.php">
                                <i class="bi bi-person-gear"></i> 个人设置
                            </a></li>
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
    
    <!-- 页面内容容器 -->
    <div class="container-fluid mt-4">
        <?php if (isset($show_page_title)): ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 fw-bold text-primary">
                <i class="bi bi-<?php echo $page_icon ?? 'app'; ?>"></i> <?php echo $show_page_title ?? ''; ?>
            </h1>
            <?php if (isset($page_action_url) && isset($page_action_text)): ?>
            <div>
                <a href="<?php echo $page_action_url; ?>" class="btn btn-primary shadow-sm">
                    <i class="bi bi-plus-circle"></i> <?php echo $page_action_text; ?>
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>