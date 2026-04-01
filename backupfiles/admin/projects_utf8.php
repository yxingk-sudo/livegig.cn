<?php
// 修复编码问题的项目页面
require_once '../config/database.php';
require_once '../includes/functions.php';

// 设置编码
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');

session_start();

// 检查管理员是否已登录
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();

// 获取项目列表
$projects_query = "SELECT p.*, c.name as company_name 
                   FROM projects p 
                   JOIN companies c ON p.company_id = c.id 
                   ORDER BY p.created_at DESC";
$projects_stmt = $db->prepare($projects_query);
$projects_stmt->execute();
$projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取公司列表
$companies_query = "SELECT * FROM companies ORDER BY name ASC";
$companies_stmt = $db->prepare($companies_query);
$companies_stmt->execute();
$companies = $companies_stmt->fetchAll(PDO::FETCH_ASSOC);

// 状态映射
$status_map = [
    'active' => ['label' => '活跃', 'class' => 'success'],
    'inactive' => ['label' => '非活跃', 'class' => 'secondary'],
    'completed' => ['label' => '已完成', 'class' => 'info']
];

// 处理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>项目管理 - 后台管理</title>
    <link href="assets/css/app.min.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- 侧边栏 -->
            <div class="col-md-3 col-lg-2 bg-light">
                <div class="list-group mt-3">
                    <a href="index.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-house"></i> 首页
                    </a>
                    <a href="companies.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-building"></i> 公司管理
                    </a>
                    <a href="projects.php" class="list-group-item list-group-item-action active">
                        <i class="bi bi-folder"></i> 项目管理
                    </a>
                    <a href="personnel.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-people"></i> 人员管理
                    </a>
                    <a href="departments.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-diagram-3"></i> 部门管理
                    </a>
                    <a href="meal_reports.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-cup-hot"></i> 报餐管理
                    </a>
                    <a href="hotel_reports.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-building"></i> 报酒店管理
                    </a>
                    <a href="transportation_reports.php" class="list-group-item list-group-item-action">
                        <i class="bi bi-car-front"></i> 报出行车管理
                    </a>
                </div>
            </div>

            <!-- 主内容区 -->
            <div class="col-md-9 col-lg-10">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1>项目管理</h1>
                </div>

                <?php if (empty($projects)): ?>
                    <div class="text-center py-4">
                        <h5>暂无项目</h5>
                        <p>请先添加项目</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>项目代码</th>
                                    <th>项目名称</th>
                                    <th>所属公司</th>
                                    <th>状态</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($projects as $project): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($project['code']); ?></td>
                                        <td><?php echo htmlspecialchars($project['name']); ?></td>
                                        <td><?php echo htmlspecialchars($project['company_name']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $status_map[$project['status']]['class']; ?>">
                                                <?php echo $status_map[$project['status']]['label']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>