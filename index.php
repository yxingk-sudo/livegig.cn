<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/site_config.php';

// 获取网站配置
$site_config = new SiteConfig();
$site_info = $site_config->getSiteInfo();

// 设置页面标题
$page_title = $site_info['frontend_title'];

// 获取当前域名
$current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($site_info['meta_description']); ?>">
    <meta name="keywords" content="<?php echo htmlspecialchars($site_info['meta_keywords']); ?>">
    
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <style>
        body {
            font-size: 14px;
        }
    </style>
</head>
<body>
    <!-- 导航栏 -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="<?php echo $site_info['site_url']; ?>">
                <i class="bi bi-people-fill"></i> 
                <?php echo htmlspecialchars($site_info['logo_text']); ?>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="<?php echo $site_info['site_url']; ?>">首页</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="user/dashboard.php">工作台</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="user/project.php">项目中心</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="user/meals.php">报餐服务</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="user/hotels.php">酒店预订</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="user/transport.php">出行服务</a>
                    </li>
                </ul>
                
                <div class="d-flex">
                    <a href="user/login.php" class="btn btn-outline-light me-2">
                        <i class="bi bi-person-circle"></i> 登录
                    </a>
                    <a href="admin/login.php" class="btn btn-outline-light">
                        <i class="bi bi-shield-lock"></i> 管理后台
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- 主要内容 -->
    <div class="hero-section bg-primary text-white py-5">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6">
                    <h1 class="display-4 fw-bold mb-4">
                        <?php echo htmlspecialchars($site_info['site_name']); ?>
                    </h1>
                    <p class="lead mb-4">
                        <?php echo htmlspecialchars($site_info['meta_description']); ?>
                    </p>
                    <div class="d-flex gap-3">
                        <a href="user/dashboard.php" class="btn btn-light btn-lg">
                        <i class="bi bi-rocket-takeoff"></i> 开始使用
                    </a>
                    <a href="user/project.php" class="btn btn-outline-light btn-lg">
                        <i class="bi bi-folder"></i> 查看项目
                    </a>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="text-center">
                        <i class="bi bi-people-fill" style="font-size: 8rem; opacity: 0.8;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 功能特色 -->
    <div class="container py-5">
        <div class="row text-center mb-5">
            <div class="col">
                <h2 class="display-5">核心功能</h2>
                <p class="lead text-muted">为您提供全方位的团队接待服务</p>
            </div>
        </div>
        
        <div class="row g-4">
            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body text-center">
                        <i class="bi bi-cup-hot text-primary" style="font-size: 3rem;"></i>
                        <h4 class="card-title mt-3">智能报餐</h4>
                        <p class="card-text">便捷的在线报餐系统，支持多种餐型和特殊需求</p>
                        <a href="user/meals.php" class="btn btn-primary">立即报餐</a>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body text-center">
                        <i class="bi bi-building text-success" style="font-size: 3rem;"></i>
                        <h4 class="card-title mt-3">酒店预订</h4>
                        <p class="card-text">一站式酒店预订服务，支持多种房型和入住需求</p>
                        <a href="user/hotels.php" class="btn btn-success">预订酒店</a>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card h-100 shadow-sm">
                    <div class="card-body text-center">
                        <i class="bi bi-car-front text-warning" style="font-size: 3rem;"></i>
                        <h4 class="card-title mt-3">出行服务</h4>
                        <p class="card-text">灵活的出行车安排，满足各种团队出行需求</p>
                        <a href="user/transport.php" class="btn btn-warning">安排出行</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 联系信息 -->
    <div class="bg-light py-5">
        <div class="container">
            <div class="row">
                <div class="col-md-8">
                    <h3>联系我们</h3>
                    <p>如有任何问题或建议，请随时联系我们</p>
                    <div class="row">
                        <div class="col-md-6">
                            <p><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($site_info['contact_email']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($site_info['contact_phone']); ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 text-md-end">
                    <a href="user/login.php" class="btn btn-primary">
                        <i class="bi bi-person-circle"></i> 用户登录
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- 页脚 -->
    <footer class="bg-dark text-white py-4">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h5><?php echo htmlspecialchars($site_info['logo_text']); ?></h5>
                    <p class="text-muted"><?php echo htmlspecialchars($site_info['meta_description']); ?></p>
                </div>
                <div class="col-md-6 text-md-end">
                    <p class="mb-0"><?php echo htmlspecialchars($site_info['footer_text']); ?></p>
                </div>
            </div>
        </div>
    </footer>

    <script src="assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>