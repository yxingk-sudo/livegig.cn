<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/site_config.php';
require_once 'page_functions.php';

// 添加安全响应头
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

session_start();

// 检查管理员是否已登录
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();

// 获取统计数据
$stats = [];

// 获取公司数量
$companies_query = "SELECT COUNT(*) as count FROM companies";
$companies_stmt = $db->prepare($companies_query);
$companies_stmt->execute();
$stats['companies'] = $companies_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// 获取项目数量
$projects_query = "SELECT COUNT(*) as count FROM projects";
$projects_stmt = $db->prepare($projects_query);
$projects_stmt->execute();
$stats['projects'] = $projects_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// 获取人员数量
$personnel_query = "SELECT COUNT(*) as count FROM personnel";
$personnel_stmt = $db->prepare($personnel_query);
$personnel_stmt->execute();
$stats['personnel'] = $personnel_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// 获取部门数量
$departments_query = "SELECT COUNT(*) as count FROM departments";
$departments_stmt = $db->prepare($departments_query);
$departments_stmt->execute();
$stats['departments'] = $departments_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// 获取今日报餐数量
$today = date('Y-m-d');
$meals_query = "SELECT COUNT(*) as count FROM meal_reports WHERE meal_date = :date";
$meals_stmt = $db->prepare($meals_query);
$meals_stmt->bindParam(':date', $today);
$meals_stmt->execute();
$stats['today_meals'] = $meals_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// 获取今日酒店预订
$hotels_query = "SELECT COUNT(*) as count FROM hotel_reports WHERE check_in_date <= :date AND check_out_date >= :date";
$hotels_stmt = $db->prepare($hotels_query);
$hotels_stmt->bindParam(':date', $today);
$hotels_stmt->execute();
$stats['today_hotels'] = $hotels_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// 获取今日出行车
$transport_query = "SELECT COUNT(*) as count FROM transportation_reports WHERE travel_date = :date";
$transport_stmt = $db->prepare($transport_query);
$transport_stmt->bindParam(':date', $today);
$transport_stmt->execute();
$stats['today_transport'] = $transport_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// 获取最近创建的2个项目
$recent_projects_query = "SELECT p.*, c.name as company_name 
                         FROM projects p 
                         LEFT JOIN companies c ON p.company_id = c.id 
                         ORDER BY p.created_at DESC LIMIT 2";
$recent_projects_stmt = $db->prepare($recent_projects_query);
$recent_projects_stmt->execute();
$recent_projects = $recent_projects_stmt->fetchAll(PDO::FETCH_ASSOC);

// 处理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}
?>
<?php include 'includes/header.php'; ?>

<style>
/* 最近创建的项目表格列宽优化 */
.recent-projects-table th:nth-child(1),
.recent-projects-table td:nth-child(1) { width: 20%; min-width: 120px; } /* 项目名称 */

.recent-projects-table th:nth-child(2),
.recent-projects-table td:nth-child(2) { width: 15%; min-width: 100px; } /* 所属公司 */

.recent-projects-table th:nth-child(3),
.recent-projects-table td:nth-child(3) { width: 15%; min-width: 100px; } /* 项目代码 */

.recent-projects-table th:nth-child(4),
.recent-projects-table td:nth-child(4) { width: 10%; min-width: 80px; } /* 状态 */

.recent-projects-table th:nth-child(5),
.recent-projects-table td:nth-child(5) { width: 20%; min-width: 120px; } /* 创建时间 */

.recent-projects-table th:nth-child(6),
.recent-projects-table td:nth-child(6) { width: 20%; min-width: 100px; } /* 操作 */

/* 响应式调整 */
@media (max-width: 768px) {
    .recent-projects-table th:nth-child(2),
    .recent-projects-table td:nth-child(2) { width: 0; min-width: 0; display: none; } /* 隐藏所属公司列 */
    
    .recent-projects-table th:nth-child(3),
    .recent-projects-table td:nth-child(3) { width: 20%; min-width: 80px; }
    
    .recent-projects-table th:nth-child(5),
    .recent-projects-table td:nth-child(5) { width: 25%; min-width: 100px; }
    
    .recent-projects-table th:nth-child(6),
    .recent-projects-table td:nth-child(6) { width: 25%; min-width: 80px; }
}

@media (max-width: 576px) {
    .recent-projects-table th:nth-child(3),
    .recent-projects-table td:nth-child(3) { width: 0; min-width: 0; display: none; } /* 隐藏项目代码列 */
    
    .recent-projects-table th:nth-child(4),
    .recent-projects-table td:nth-child(4) { width: 15%; min-width: 60px; }
    
    .recent-projects-table th:nth-child(5),
    .recent-projects-table td:nth-child(5) { width: 0; min-width: 0; display: none; } /* 隐藏创建时间列 */
    
    .recent-projects-table th:nth-child(6),
    .recent-projects-table td:nth-child(6) { width: 40%; min-width: 80px; }
}
</style>

<div class="container-fluid">
    <!-- 统计卡片 -->
    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h3><?php echo $stats['companies']; ?></h3>
                            <span class="text-muted fw-semibold">公司数量</span>
                        </div>
                        <div class="text-primary fw-bold font-size-lg">
                            <i class="bi bi-building" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h3><?php echo $stats['projects']; ?></h3>
                            <span class="text-muted fw-semibold">项目总数</span>
                        </div>
                        <div class="text-success fw-bold font-size-lg">
                            <i class="bi bi-folder" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h3><?php echo $stats['personnel']; ?></h3>
                            <span class="text-muted fw-semibold">人员总数</span>
                        </div>
                        <div class="text-warning fw-bold font-size-lg">
                            <i class="bi bi-people" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h3><?php echo $stats['departments']; ?></h3>
                            <span class="text-muted fw-semibold">部门数量</span>
                        </div>
                        <div class="text-info fw-bold font-size-lg">
                            <i class="bi bi-diagram-3" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-3 mb-3">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h3><?php echo $stats['today_meals']; ?></h3>
                            <span class="text-muted fw-semibold">今日报餐</span>
                        </div>
                        <div class="text-danger fw-bold font-size-lg">
                            <i class="bi bi-cup-hot" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h3><?php echo $stats['today_hotels']; ?></h3>
                            <span class="text-muted fw-semibold">今日酒店</span>
                        </div>
                        <div class="text-primary fw-bold font-size-lg">
                            <i class="bi bi-building" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h3><?php echo $stats['today_transport']; ?></h3>
                            <span class="text-muted fw-semibold">今日交通</span>
                        </div>
                        <div class="text-success fw-bold font-size-lg">
                            <i class="bi bi-car-front" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 mb-3">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <h3><?php echo $stats['projects']; ?></h3>
                            <span class="text-muted fw-semibold">活跃项目</span>
                        </div>
                        <div class="text-info fw-bold font-size-lg">
                            <i class="bi bi-check-circle" style="font-size: 2rem;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 快速操作 -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-lightning"></i> 快速操作</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-2">
                            <a href="companies.php" class="btn btn-outline-primary w-100">
                                <i class="bi bi-building"></i> 公司管理
                            </a>
                        </div>
                        <div class="col-md-3 mb-2">
                            <a href="projects.php" class="btn btn-outline-success w-100">
                                <i class="bi bi-folder"></i> 项目管理
                            </a>
                        </div>
                        <div class="col-md-3 mb-2">
                            <a href="personnel_enhanced.php" class="btn btn-outline-info w-100">
                                <i class="bi bi-people"></i> 人员管理
                            </a>
                        </div>
                        <div class="col-md-3 mb-2">
                            <a href="departments_enhanced.php" class="btn btn-outline-warning w-100">
                                <i class="bi bi-diagram-3"></i> 部门管理
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 最近项目 -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-clock-history"></i> 最近创建的项目</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($recent_projects)): ?>
                        <div class="table-responsive">
                            <table class="table table-hover recent-projects-table">
                                <thead>
                                    <tr>
                                        <th>项目名称</th>
                                        <th>所属公司</th>
                                        <th>项目代码</th>
                                        <th>状态</th>
                                        <th>创建时间</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_projects as $project): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($project['name']); ?></td>
                                            <td><?php echo htmlspecialchars($project['company_name']); ?></td>
                                            <td>
                                                <code><?php echo htmlspecialchars($project['code']); ?></code>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $project['status'] === 'active' ? 'success' : ($project['status'] === 'inactive' ? 'warning' : 'secondary'); ?>">
                                                    <?php echo $project['status'] === 'active' ? '活跃' : ($project['status'] === 'inactive' ? '非活跃' : '已完成'); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('Y-m-d H:i', strtotime($project['created_at'])); ?></td>
                                            <td>
                                                <a href="../user/project.php?code=<?php echo $project['code']; ?>" 
                                                   class="btn btn-sm btn-outline-primary" target="_blank">
                                                    <i class="bi bi-box-arrow-up-right"></i> 访问
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="bi bi-inbox display-1 text-muted"></i>
                            <h5 class="text-muted">暂无项目</h5>
                            <p class="text-muted">请先创建公司和项目</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>