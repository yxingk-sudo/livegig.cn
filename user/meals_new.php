<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

session_start();
// 引入权限中间件进行权限验证
require_once '../includes/PermissionMiddleware.php';
$database = new Database();
$db = $database->getConnection();
$middleware = new PermissionMiddleware($db);
$middleware->checkUserPagePermission('frontend:meal:list');

requireLogin();
checkProjectPermission($_SESSION['project_id']);

// 获取数据库连接
$database = new Database();
$db = $database->getConnection();

$projectId = $_SESSION['project_id'];
$action = $_GET['action'] ?? 'list';

// 如果是批量报餐，必须在任何输出前重定向，避免 headers already sent
if ($action === 'batch_order') {
    header('Location: batch_meal_order.php');
    exit;
}

// 获取项目人员
$personnel = getProjectPersonnel($projectId, $db);

// 获取项目部门及人数统计
$dept_query = "SELECT DISTINCT d.id, d.name, COUNT(pdp.personnel_id) as person_count
               FROM departments d 
               JOIN project_department_personnel pdp ON d.id = pdp.department_id 
               WHERE pdp.project_id = :project_id 
               AND pdp.status = 'active'
               GROUP BY d.id, d.name
               ORDER BY d.name";
$dept_stmt = $db->prepare($dept_query);
$dept_stmt->bindParam(':project_id', $projectId);
$dept_stmt->execute();
$departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);

// 构建查询条件
function buildWhereClause($projectId, $filters) {
    $where_conditions = ['mr.project_id = :project_id'];
    $params = [':project_id' => $projectId];
    
    if (!empty($filters['date_from'])) {
        $where_conditions[] = 'mr.meal_date >= :date_from';
        $params[':date_from'] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $where_conditions[] = 'mr.meal_date <= :date_to';
        $params[':date_to'] = $filters['date_to'];
    }
    
    if (!empty($filters['meal_type'])) {
        $where_conditions[] = 'mr.meal_type = :meal_type';
        $params[':meal_type'] = $filters['meal_type'];
    }
    
    if (!empty($filters['department_id'])) {
        $where_conditions[] = 'pdp.department_id = :department_id';
        $params[':department_id'] = $filters['department_id'];
    }
    
    return [
        'where' => implode(' AND ', $where_conditions),
        'params' => $params
    ];
}

// 获取每日统计
function getDailyStatistics($projectId, $db, $filters) {
    $clause = buildWhereClause($projectId, $filters);
    
    $query = "SELECT 
                mr.id,
                mr.meal_date,
                mr.meal_type,
                COUNT(*) as person_count,
                SUM(mr.meal_count) as total_meals,
                mp.name as package_name
              FROM meal_reports mr
              LEFT JOIN meal_packages mp ON mr.package_id = mp.id
              LEFT JOIN personnel p ON mr.personnel_id = p.id
              LEFT JOIN project_department_personnel pdp ON (mr.project_id = pdp.project_id AND p.id = pdp.personnel_id)
              WHERE {$clause['where']}
              GROUP BY mr.id, mr.meal_date, mr.meal_type, mp.name
              ORDER BY mr.meal_date DESC, mr.meal_type";
    
    $stmt = $db->prepare($query);
    $stmt->execute($clause['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 获取部门统计
function getDepartmentStatistics($projectId, $db, $filters) {
    $clause = buildWhereClause($projectId, $filters);
    
    $query = "SELECT 
                mr.id,
                d.name as department_name,
                mr.meal_type,
                COUNT(*) as person_count,
                SUM(mr.meal_count) as total_meals
              FROM meal_reports mr
              JOIN personnel p ON mr.personnel_id = p.id
              JOIN project_department_personnel pdp ON (mr.project_id = pdp.project_id AND p.id = pdp.personnel_id)
              JOIN departments d ON pdp.department_id = d.id
              WHERE {$clause['where']}
              GROUP BY mr.id, d.name, mr.meal_type
              ORDER BY d.name, mr.meal_type";
    
    $stmt = $db->prepare($query);
    $stmt->execute($clause['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 获取报餐记录
function getEnhancedMealReports($projectId, $db, $filters = []) {
    $where_conditions = ['mr.project_id = :project_id'];
    $params = [':project_id' => $projectId];
    
    if (!empty($filters['date'])) {
        $where_conditions[] = 'mr.meal_date = :date';
        $params[':date'] = $filters['date'];
    }
    
    if (!empty($filters['meal_type'])) {
        $where_conditions[] = 'mr.meal_type = :meal_type';
        $params[':meal_type'] = $filters['meal_type'];
    }
    
    if (!empty($filters['department_id'])) {
        $where_conditions[] = 'pdp.department_id = :department_id';
        $params[':department_id'] = $filters['department_id'];
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // 先获取详细记录用于按日统计
    $query = "SELECT mr.*, 
                     p.name as personnel_name,
                     mp.name as package_name,
                     mp.description as package_description,
                     d.name as department_name,
                     pu.display_name as reported_by_name
              FROM meal_reports mr
              JOIN personnel p ON mr.personnel_id = p.id
              LEFT JOIN meal_packages mp ON mr.package_id = mp.id
              LEFT JOIN project_department_personnel pdp ON (mr.project_id = pdp.project_id AND p.id = pdp.personnel_id)
              LEFT JOIN departments d ON pdp.department_id = d.id
              LEFT JOIN project_users pu ON mr.reported_by = pu.id
              WHERE $where_clause
              ORDER BY mr.meal_date DESC, mr.meal_type, p.name";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 获取按日统计的报餐数据
function getDailyMealStatistics($projectId, $db, $filters = []) {
    $where_conditions = ['mr.project_id = :project_id'];
    $params = [':project_id' => $projectId];
    
    if (!empty($filters['date'])) {
        $where_conditions[] = 'mr.meal_date = :date';
        $params[':date'] = $filters['date'];
    }
    
    if (!empty($filters['meal_type'])) {
        $where_conditions[] = 'mr.meal_type = :meal_type';
        $params[':meal_type'] = $filters['meal_type'];
    }
    
    if (!empty($filters['department_id'])) {
        $where_conditions[] = 'pdp.department_id = :department_id';
        $params[':department_id'] = $filters['department_id'];
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $query = "SELECT 
                mr.meal_date,
                mr.meal_type,
                COUNT(*) as total_count,
                COUNT(DISTINCT mr.personnel_id) as personnel_count,
                COUNT(CASE WHEN mp.id IS NOT NULL THEN 1 END) as package_count,
                GROUP_CONCAT(DISTINCT mp.name ORDER BY mp.name) as package_names,
                GROUP_CONCAT(DISTINCT d.name ORDER BY d.name) as department_names,
                GROUP_CONCAT(
                    DISTINCT CONCAT(p.name, '(', COALESCE(d.name, '未分配'), ')')
                    ORDER BY p.name
                    SEPARATOR ', '
                ) as personnel_list
              FROM meal_reports mr
              JOIN personnel p ON mr.personnel_id = p.id
              LEFT JOIN meal_packages mp ON mr.package_id = mp.id
              LEFT JOIN project_department_personnel pdp ON (mr.project_id = pdp.project_id AND p.id = pdp.personnel_id)
              LEFT JOIN departments d ON pdp.department_id = d.id
              WHERE $where_clause
              GROUP BY mr.meal_date, mr.meal_type
              ORDER BY mr.meal_date DESC, mr.meal_type";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 获取每日餐类型详细统计数据
function getDailyMealTypeStatistics($projectId, $db, $filters = []) {
    $where_conditions = ['mr.project_id = :project_id'];
    $params = [':project_id' => $projectId];
    
    if (!empty($filters['date'])) {
        $where_conditions[] = 'mr.meal_date = :date';
        $params[':date'] = $filters['date'];
    }
    
    if (!empty($filters['department_id'])) {
        $where_conditions[] = 'pdp.department_id = :department_id';
        $params[':department_id'] = $filters['department_id'];
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $query = "SELECT 
                mr.meal_date,
                mr.meal_type,
                COUNT(*) as meal_count,
                COUNT(DISTINCT mr.personnel_id) as unique_personnel,
                GROUP_CONCAT(DISTINCT mp.name ORDER BY mp.name) as packages,
                GROUP_CONCAT(DISTINCT d.name ORDER BY d.name) as departments
              FROM meal_reports mr
              JOIN personnel p ON mr.personnel_id = p.id
              LEFT JOIN meal_packages mp ON mr.package_id = mp.id
              LEFT JOIN project_department_personnel pdp ON (mr.project_id = pdp.project_id AND p.id = pdp.personnel_id)
              LEFT JOIN departments d ON pdp.department_id = d.id
              WHERE $where_clause
              GROUP BY mr.meal_date, mr.meal_type
              ORDER BY mr.meal_date DESC, 
                       CASE mr.meal_type 
                           WHEN '早餐' THEN 1 
                           WHEN '午餐' THEN 2 
                           WHEN '晚餐' THEN 3 
                           WHEN '宵夜' THEN 4 
                           ELSE 5 
                       END";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 获取每种餐类型的可用套餐数量
function getMealTypePackageCounts($projectId, $db) {
    $query = "SELECT meal_type, COUNT(*) as package_count
              FROM meal_packages 
              WHERE project_id = :project_id 
              AND is_active = 1
              GROUP BY meal_type";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':project_id', $projectId);
    $stmt->execute();
    
    $counts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $counts[$row['meal_type']] = (int)$row['package_count'];
    }
    
    return $counts;
}

$filters = [
    'date' => $_GET['date'] ?? '',
    'meal_type' => $_GET['meal_type'] ?? '',
    'department_id' => $_GET['department_id'] ?? '',
    'date_from' => $_GET['date_from'] ?? date('Y-m-01'),
    'date_to' => $_GET['date_to'] ?? date('Y-m-d')
];

// 获取视图模式：按日统计或详细记录
$view_mode = $_GET['view_mode'] ?? 'daily';

// 获取统计数据
$dailyStats = getDailyStatistics($projectId, $db, $filters);
$departmentStats = getDepartmentStatistics($projectId, $db, $filters);

if ($view_mode === 'daily') {
    $daily_meals = getDailyMealStatistics($projectId, $db, $filters);
    $meals = []; // 详细记录留空
    
    // 获取餐类型详细统计数据
    $daily_meal_types = getDailyMealTypeStatistics($projectId, $db, $filters);
} else {
    $daily_meals = []; // 按日统计留空
    $meals = getEnhancedMealReports($projectId, $db, $filters);
    $daily_meal_types = []; // 餐类型统计留空
}

// 获取餐类型套餐统计
$mealTypePackageCounts = getMealTypePackageCounts($projectId, $db);

// 显示消息
$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);

// 设置页面特定变量
$page_title = '智能报餐系统 - ' . ($_SESSION['project_name'] ?? '项目');
$active_page = 'meals_new';
$show_page_title = '智能报餐系统';
$page_icon = 'cup-hot';
$page_action_text = '批量报餐';
$page_action_url = 'meals_new.php?action=batch_order';

// 包含统一头部文件
include 'includes/header.php';
?>

<style>
.meal-card {
    border: 2px solid #e9ecef;
    border-radius: 10px;
    transition: all 0.3s ease;
    cursor: pointer;
    background-color: #f8f9ff;
}
.meal-card:hover {
    border-color: #007bff;
    background-color: #e3f2fd;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,123,255,0.15);
}
.meal-card.selected {
    border-color: #007bff;
    background-color: #e3f2fd;
    box-shadow: 0 4px 8px rgba(0,123,255,0.15);
}
.package-option {
    transition: all 0.3s ease;
    cursor: pointer;
}
.package-option:hover {
    background-color: #f8f9fa !important;
    border-color: #007bff !important;
}
.form-check-input:checked + .form-check-label .package-option {
    background-color: #e3f2fd !important;
    border-color: #007bff !important;
}

/* 按日统计表格样式 */
.daily-stats-table td {
    vertical-align: middle;
}
.personnel-list {
    max-height: 100px;
    overflow-y: auto;
    line-height: 1.4;
}
.table-hover tbody tr:hover {
    background-color: rgba(0, 123, 255, 0.05);
}
.date-group {
    border-top: 2px solid #dee2e6;
}
.date-group:first-child {
    border-top: none;
}
/* 已选人员区域样式优化 */
.selected-personnel-container {
    min-height: 200px;
    padding: 15px;
    background-color: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #dee2e6;
}
.selected-personnel-title {
    font-size: 1.2rem;
    font-weight: bold;
    color: #495057;
    margin-bottom: 15px;
}
.selected-personnel-count {
    font-size: 1.1rem;
    font-weight: 600;
    color: #007bff;
}
.selected-personnel-badge {
    font-size: 1rem !important;
    padding: 8px 12px !important;
    margin: 5px;
    background-color: #007bff !important;
    font-weight: 500;
}
.no-selection-text {
    font-size: 1.1rem;
    color: #6c757d;
    font-style: italic;
}

/* 必填字段样式优化 */
.form-label.required {
    font-weight: bold;
    color: #dc3545;
}

.form-label.required::after {
    content: " *";
    color: #dc3545;
    font-weight: bold;
}

.form-control:required {
    border-left: 4px solid #dc3545;
    background-color: #fff8f8;
}

.form-control:required:focus {
    border-color: #dc3545;
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
}

.btn-check:required + .btn {
    border-left: 4px solid #dc3545;
}

.btn-check:required + .btn:focus {
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25);
}
</style>

<div class="container-fluid mt-4">
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- 报餐记录列表页面 -->
        <div class="row">
            <div class="col-12">
                <!-- 筛选条件 -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">日期筛选</label>
                                <input type="date" class="form-control" name="date" 
                                       value="<?php echo $filters['date']; ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">餐类型</label>
                                <select class="form-select" name="meal_type">
                                    <option value="">全部</option>
                                    <?php foreach(['早餐', '午餐', '晚餐', '宵夜'] as $type): ?>
                                        <option value="<?php echo $type; ?>" 
                                                <?php echo $filters['meal_type'] === $type ? 'selected' : ''; ?>>
                                            <?php echo $type; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">部门</label>
                                <select class="form-select" name="department_id">
                                    <option value="">全部</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>"
                                                <?php echo $filters['department_id'] == $dept['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dept['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-2">
                                    <i class="bi bi-funnel me-1"></i>筛选
                                </button>
                                <a href="meals_new.php" class="btn btn-outline-secondary">重置</a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- 报餐记录表格 -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-list-ul me-2"></i>报餐记录
                        </h5>
                        <div class="d-flex gap-2">
                            <div class="btn-group btn-group-sm" role="group">
                                <?php
                                $daily_url_params = $filters;
                                $daily_url_params['view_mode'] = 'daily';
                                $detail_url_params = $filters;
                                $detail_url_params['view_mode'] = 'detail';
                                ?>
                                <a href="meals_new.php?<?php echo http_build_query($daily_url_params); ?>" 
                                   class="btn btn-outline-primary <?php echo $view_mode === 'daily' ? 'active' : ''; ?>">
                                    <i class="bi bi-calendar3 me-1"></i>按日统计
                                </a>
                                <a href="meals_new.php?<?php echo http_build_query($detail_url_params); ?>" 
                                   class="btn btn-outline-primary <?php echo $view_mode === 'detail' ? 'active' : ''; ?>">
                                    <i class="bi bi-list-ul me-1"></i>详细记录
                                </a>
                            </div>
                            <a href="meals_statistics.php" class="btn btn-info btn-sm">
                                <i class="bi bi-graph-up me-1"></i>统计报表
                            </a>
                            <a href="batch_meal_order.php" class="btn btn-primary btn-sm">
                                <i class="bi bi-plus-circle me-1"></i>批量报餐
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if ($view_mode === 'daily'): ?>
                            <?php if (empty($daily_meals)): ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-inbox display-1 text-muted"></i>
                                    <h5 class="text-muted mt-3">暂无报餐记录</h5>
                                    <p class="text-muted">点击上方"批量报餐"按钮开始报餐</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover daily-stats-table">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>用餐日期</th>
                                                <th>餐类型</th>
                                                <th>用餐数量</th>
                                                <th>用餐人数</th>
                                                <th>套餐统计</th>
                                                <th>涉及部门</th>
                                                <th>人员名单</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $current_date = '';
                                            $daily_totals = [];
                                            foreach ($daily_meals as $meal): 
                                                $type_colors = ['早餐'=>'warning', '午餐'=>'primary', '晚餐'=>'success', '宵夜'=>'info'];
                                                $color = $type_colors[$meal['meal_type']] ?? 'secondary';
                                                
                                                // 计算每日总计
                                                if (!isset($daily_totals[$meal['meal_date']])) {
                                                    $daily_totals[$meal['meal_date']] = [
                                                        'total_meals' => 0,
                                                        'total_personnel' => 0
                                                    ];
                                                }
                                                $daily_totals[$meal['meal_date']]['total_meals'] += $meal['total_count'];
                                                $daily_totals[$meal['meal_date']]['total_personnel'] += $meal['personnel_count'];
                                            ?>
                                                <tr>
                                                    <td class="fw-bold">
                                                        <?php 
                                                        if ($current_date !== $meal['meal_date']) {
                                                            echo $meal['meal_date'];
                                                            $current_date = $meal['meal_date'];
                                                        } else {
                                                            echo '<span class="text-muted">〃</span>';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $color; ?>">
                                                            <?php echo $meal['meal_type']; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $color; ?> fs-6">
                                                            <?php echo $meal['total_count']; ?>
                                                        </span>
                                                        <span>份</span>
                                                    </td>
                                                    <td>
                                                        <span class="text-info fw-bold">
                                                            <?php echo $meal['personnel_count']; ?> 人
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($meal['package_names']): ?>
                                                            <?php 
                                                            $packages = explode(',', $meal['package_names']);
                                                            foreach ($packages as $package): 
                                                            ?>
                                                                <span class="me-2 mb-1" style="font-size: 1.1em; font-weight: 500;">
                                                                    <?php echo htmlspecialchars($package); ?>
                                                                </span>
                                                            <?php endforeach; ?>
                                                        <?php else: ?>
                                                            <span class="text-muted">无套餐</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($meal['department_names']): ?>
                                                            <?php 
                                                            $departments = explode(',', $meal['department_names']);
                                                            foreach ($departments as $dept): 
                                                            ?>
                                                                <span class="badge bg-secondary me-1 mb-1">
                                                                    <?php echo htmlspecialchars($dept); ?>
                                                                </span>
                                                            <?php endforeach; ?>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">未分配</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="personnel-list" style="max-width: 300px;">
                                                            <small class="text-muted">
                                                                <?php echo htmlspecialchars($meal['personnel_list']); ?>
                                                            </small>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <?php if (!empty($daily_totals) || !empty($daily_meal_types)): ?>
                                <div class="mt-4">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h6 class="text-primary mb-0"><i class="bi bi-bar-chart me-2"></i>每日总计统计</h6>
                                        <div class="btn-group" role="group">
                                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="exportDailyStats('excel')">
                                                <i class="bi bi-file-earmark-excel"></i> 导出Excel
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="exportDailyStats('csv')">
                                                <i class="bi bi-file-text"></i> 导出CSV
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <?php 
                                    // 按日期和餐类型整理统计数据
                                    $date_meal_stats = [];
                                    foreach ($daily_meal_types as $item) {
                                        $date = $item['meal_date'];
                                        if (!isset($date_meal_stats[$date])) {
                                            $date_meal_stats[$date] = [];
                                        }
                                        $date_meal_stats[$date][$item['meal_type']] = $item;
                                    }
                                    ?>
                                    
                                    <div class="row">
                                        <?php foreach ($date_meal_stats as $date => $meal_types): ?>
                                        <div class="col-md-4 mb-3">
                                            <div class="card border-primary">
                                                <div class="card-header bg-primary text-white">
                                                    <h6 class="mb-0"><?php echo $date; ?></h6>
                                                </div>
                                                <div class="card-body p-0">
                                                    <div class="table-responsive">
                                                        <table class="table table-sm mb-0">
                                                            <thead class="table-light">
                                                                <tr>
                                                                    <th>餐类型</th>
                                                                    <th>份数</th>
                                                                    <th>人数</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php 
                                                                $type_colors = ['早餐'=>'warning', '午餐'=>'primary', '晚餐'=>'success', '宵夜'=>'info'];
                                                                $daily_total_meals = 0;
                                                                $daily_total_personnel = 0;
                                                                
                                                                foreach ($meal_types as $type => $data): 
                                                                    $color = $type_colors[$type] ?? 'secondary';
                                                                    $daily_total_meals += $data['meal_count'];
                                                                    $daily_total_personnel += $data['unique_personnel'];
                                                                ?>
                                                                <tr>
                                                                    <td>
                                                                        <span class="badge bg-<?php echo $color; ?>">
                                                                            <?php echo $type; ?>
                                                                        </span>
                                                                    </td>
                                                                    <td><?php echo $data['meal_count']; ?> 份</td>
                                                                    <td><?php echo $data['unique_personnel']; ?> 人</td>
                                                                </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                            <tfoot class="table-light">
                                                                <tr class="fw-bold">
                                                                    <td>每日总计</td>
                                                                    <td><?php echo $daily_total_meals; ?> 份</td>
                                                                    <td><?php echo $daily_total_personnel; ?> 人</td>
                                                                </tr>
                                                            </tfoot>
                                                        </table>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <!-- 添加每日用餐统计表和部门用餐统计表 -->
                                <div class="row mt-4">
                                    <!-- 每日统计 -->
                                    <div class="col-md-6 mb-4">
                                        <div class="card shadow-sm">
                                            <div class="card-header bg-primary text-white">
                                                <h5 class="mb-0 d-flex justify-content-between align-items-center">
                                                    <span><i class="bi bi-calendar3 me-2"></i>每日用餐统计</span>
                                                    <?php if (!empty($dailyStats)): ?>
                                                        <span class="badge bg-light text-primary rounded-pill">
                                                            <?php echo count(array_unique(array_column($dailyStats, 'meal_date'))); ?> 天
                                                        </span>
                                                    <?php endif; ?>
                                                </h5>
                                            </div>
                                            <div class="card-body">
                                                <?php if (empty($dailyStats)): ?>
                                                    <p class="text-muted text-center py-4">暂无数据</p>
                                                <?php else: ?>
                                                    <div class="table-responsive">
                                                        <table class="table table-hover table-striped">
                                                            <thead class="table-light">
                                                                <tr>
                                                                    <th width="25%" class="text-center">日期</th>
                                                                    <th width="25%" class="text-center">餐类型</th>
                                                                    <th width="25%" class="text-center">人数</th>
                                                                    <th width="25%" class="text-center">总餐数</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php 
                                                                $current_date = '';
                                                                $date_totals = [];
                                                                $meal_type_totals = ['早餐' => 0, '午餐' => 0, '晚餐' => 0, '宵夜' => 0];
                                                                $total_persons = 0;
                                                                $total_meals = 0;
                                                                
                                                                // 计算总计
                                                                foreach ($dailyStats as $stat) {
                                                                    if (!isset($date_totals[$stat['meal_date']])) {
                                                                        $date_totals[$stat['meal_date']] = ['persons' => 0, 'meals' => 0];
                                                                    }
                                                                    $date_totals[$stat['meal_date']]['persons'] += $stat['person_count'];
                                                                    $date_totals[$stat['meal_date']]['meals'] += $stat['total_meals'];
                                                                    $meal_type_totals[$stat['meal_type']] += $stat['total_meals'];
                                                                    $total_persons += $stat['person_count'];
                                                                    $total_meals += $stat['total_meals'];
                                                                }
                                                                
                                                                // 按日期分组数据
                                                                $grouped_stats = [];
                                                                foreach ($dailyStats as $stat) {
                                                                    $grouped_stats[$stat['meal_date']][] = $stat;
                                                                }
                                                                
                                                                // 遍历分组后的数据
                                                                foreach ($grouped_stats as $date => $stats): 
                                                                ?>
                                                                    <tr>
                                                                        <td class="text-center align-middle">
                                                                            <strong><?php echo date('m-d', strtotime($date)); ?></strong>
                                                                            <div class="small text-muted"><?php echo date('Y', strtotime($date)); ?></div>
                                                                        </td>
                                                                        <td class="text-center align-middle">
                                                                            <div class="d-flex flex-wrap justify-content-center align-items-center">
                                                                            <?php 
                                                                            // 合并显示餐类型和对应人数，相同的只显示一次
                                                                            $merged_stats = [];
                                                                            foreach ($stats as $stat) {
                                                                                $key = $stat['meal_type'] . '_' . $stat['person_count'];
                                                                                if (!isset($merged_stats[$key])) {
                                                                                    $merged_stats[$key] = [
                                                                                        'meal_type' => $stat['meal_type'],
                                                                                        'person_count' => $stat['person_count'],
                                                                                        'count' => 1
                                                                                    ];
                                                                                } else {
                                                                                    $merged_stats[$key]['count']++;
                                                                                }
                                                                            }
                                                                            
                                                                            foreach ($merged_stats as $merged_stat): 
                                                                                $type_colors = ['早餐'=>'warning', '午餐'=>'primary', '晚餐'=>'success', '宵夜'=>'info'];
                                                                                $color = $type_colors[$merged_stat['meal_type']] ?? 'secondary';
                                                                            ?>
                                                                                <div class="d-inline-flex align-items-center me-2 mb-1">
                                                                                    <span class="badge bg-<?php echo $color; ?> px-1 py-0" style="font-size: 0.7rem;">
                                                                                        <?php echo $merged_stat['meal_type']; ?>
                                                                                    </span>
                                                                                    <span class="badge bg-primary rounded-pill px-1 py-0 ms-1" style="font-size: 0.7rem;">
                                                                                        <?php echo $merged_stat['person_count']; ?>
                                                                                        <?php if ($merged_stat['count'] > 1): ?>
                                                                                            ×<?php echo $merged_stat['count']; ?>
                                                                                        <?php endif; ?>
                                                                                    </span>
                                                                                </div>
                                                                            <?php endforeach; ?>
                                                                            </div>
                                                                        </td>
                                                                        <td class="text-center align-middle fw-bold">
                                                                            <?php echo $date_totals[$date]['meals']; ?> 份
                                                                        </td>
                                                                    </tr>
                                                                    <tr class="table-light">
                                                                        <td class="text-end" colspan="1"><strong><?php echo date('m-d', strtotime($date)); ?> 小计：</strong></td>
                                                                        <td class="text-center"><?php echo $date_totals[$date]['persons']; ?> 人</td>
                                                                        <td class="text-center fw-bold"><?php echo $date_totals[$date]['meals']; ?> 份</td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                            <tfoot class="table-dark">
                                                                <tr>
                                                                    <td colspan="2" class="text-end"><strong>总计：</strong></td>
                                                                    <td class="text-center"><?php echo $total_persons; ?> 人</td>
                                                                    <td class="text-center fw-bold"><?php echo $total_meals; ?> 份</td>
                                                                </tr>
                                                            </tfoot>
                                                        </table>
                                                    </div>
                                                    
                                                    <!-- 餐类型分布统计 -->
                                                    <div class="mt-3">
                                                        <h6 class="border-bottom pb-2"><i class="bi bi-pie-chart me-2"></i>餐类型分布</h6>
                                                        <div class="row text-center">
                                                            <?php foreach ($meal_type_totals as $type => $count): if ($count > 0): ?>
                                                                <div class="col-3">
                                                                    <?php 
                                                                    $type_colors = ['早餐'=>'warning', '午餐'=>'primary', '晚餐'=>'success', '宵夜'=>'info'];
                                                                    $color = $type_colors[$type] ?? 'secondary';
                                                                    ?>
                                                                    <div class="py-2 px-1 rounded bg-light">
                                                                        <span class="badge bg-<?php echo $color; ?> d-block mb-1"><?php echo $type; ?></span>
                                                                        <span class="d-block fw-bold"><?php echo $count; ?> 份</span>
                                                                        <span class="small text-muted"><?php echo round(($count / $total_meals) * 100, 1); ?>%</span>
                                                                    </div>
                                                                </div>
                                                            <?php endif; endforeach; ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- 部门统计 -->
                                    <div class="col-md-6 mb-4">
                                        <div class="card shadow-sm">
                                            <div class="card-header bg-success text-white">
                                                <h5 class="mb-0 d-flex justify-content-between align-items-center">
                                                    <span><i class="bi bi-people me-2"></i>部门用餐统计</span>
                                                    <?php if (!empty($departmentStats)): ?>
                                                        <span class="badge bg-light text-success rounded-pill">
                                                            <?php echo count(array_unique(array_column($departmentStats, 'department_name'))); ?> 个部门
                                                        </span>
                                                    <?php endif; ?>
                                                </h5>
                                            </div>
                                            <div class="card-body">
                                                <?php if (empty($departmentStats)): ?>
                                                    <p class="text-muted text-center py-4">暂无数据</p>
                                                <?php else: ?>
                                                    <?php
                                                    // 计算部门总计和餐类型总计
                                                    $dept_totals = [];
                                                    $meal_type_totals = ['早餐' => 0, '午餐' => 0, '晚餐' => 0, '宵夜' => 0];
                                                    $total_persons = 0;
                                                    $total_meals = 0;
                                                    
                                                    foreach ($departmentStats as $stat) {
                                                        if (!isset($dept_totals[$stat['department_name']])) {
                                                            $dept_totals[$stat['department_name']] = ['persons' => 0, 'meals' => 0];
                                                        }
                                                        $dept_totals[$stat['department_name']]['persons'] += $stat['person_count'];
                                                        $dept_totals[$stat['department_name']]['meals'] += $stat['total_meals'];
                                                        $meal_type_totals[$stat['meal_type']] += $stat['total_meals'];
                                                        $total_persons += $stat['person_count'];
                                                        $total_meals += $stat['total_meals'];
                                                    }
                                                    
                                                    // 按总餐数排序部门
                                                    arsort($dept_totals);
                                                    ?>
                                                    
                                                    <!-- 部门排名卡片 -->
                                                    <div class="mb-3">
                                                        <h6 class="border-bottom pb-2 mb-3"><i class="bi bi-bar-chart me-2"></i>部门用餐排名</h6>
                                                        <div class="row">
                                                            <?php 
                                                            $rank = 1;
                                                            foreach (array_slice($dept_totals, 0, 4) as $dept_name => $totals): 
                                                            ?>
                                                                <div class="col-md-6 col-lg-3 mb-2">
                                                                    <div class="card h-100 border-0 bg-light">
                                                                        <div class="card-body p-2 text-center">
                                                                            <div class="position-absolute top-0 start-0 p-1">
                                                                                <span class="badge <?php echo $rank <= 3 ? 'bg-danger' : 'bg-secondary'; ?> rounded-circle">
                                                                                    <?php echo $rank++; ?>
                                                                                </span>
                                                                            </div>
                                                                            <h6 class="card-title text-truncate mb-1" title="<?php echo htmlspecialchars($dept_name); ?>">
                                                                                <?php echo htmlspecialchars($dept_name); ?>
                                                                            </h6>
                                                                            <div class="d-flex justify-content-around">
                                                                                <div>
                                                                                    <small class="text-muted">人数</small>
                                                                                    <div class="fw-bold"><?php echo $totals['persons']; ?></div>
                                                                                </div>
                                                                                <div>
                                                                                    <small class="text-muted">总餐数</small>
                                                                                    <div class="fw-bold text-success"><?php echo $totals['meals']; ?></div>
                                                                                </div>
                                                                            </div>
                                                                            <div class="progress mt-2" style="height: 5px;">
                                                                                <div class="progress-bar bg-success" style="width: <?php echo round(($totals['meals'] / max(array_column($dept_totals, 'meals'))) * 100); ?>%"></div>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="table-responsive">
                                                        <table class="table table-hover table-striped">
                                                            <thead class="table-light">
                                                                <tr>
                                                                    <th width="40%" class="text-center">部门</th>
                                                                    <th width="20%" class="text-center">餐类型</th>
                                                                    <th width="20%" class="text-center">人数</th>
                                                                    <th width="20%" class="text-center">总餐数</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php 
                                                                // 按部门分组数据
                                                                $grouped_dept_stats = [];
                                                                foreach ($departmentStats as $stat) {
                                                                    $grouped_dept_stats[$stat['department_name']][] = $stat;
                                                                }
                                                                
                                                                // 遍历分组后的数据
                                                                foreach ($grouped_dept_stats as $dept_name => $stats): 
                                                                ?>
                                                                    <tr>
                                                                        <td class="align-middle">
                                                                            <span class="fw-bold"><?php echo htmlspecialchars($dept_name); ?></span>
                                                                        </td>
                                                                        <td class="text-center align-middle" colspan="2">
                                                                            <div class="d-flex flex-wrap justify-content-center align-items-center">
                                                                            <?php 
                                                                            // 合并显示餐类型和对应人数，相同的只显示一次
                                                                            $merged_stats = [];
                                                                            foreach ($stats as $stat) {
                                                                                $key = $stat['meal_type'] . '_' . $stat['person_count'];
                                                                                if (!isset($merged_stats[$key])) {
                                                                                    $merged_stats[$key] = [
                                                                                        'meal_type' => $stat['meal_type'],
                                                                                        'person_count' => $stat['person_count'],
                                                                                        'count' => 1
                                                                                    ];
                                                                                } else {
                                                                                    $merged_stats[$key]['count']++;
                                                                                }
                                                                            }
                                                                            
                                                                            foreach ($merged_stats as $merged_stat): 
                                                                                $type_colors = ['早餐'=>'warning', '午餐'=>'primary', '晚餐'=>'success', '宵夜'=>'info'];
                                                                                $color = $type_colors[$merged_stat['meal_type']] ?? 'secondary';
                                                                            ?>
                                                                                <div class="d-inline-flex align-items-center me-2 mb-1">
                                                                                    <span class="badge bg-<?php echo $color; ?> px-1 py-0" style="font-size: 0.7rem;">
                                                                                        <?php echo $merged_stat['meal_type']; ?>
                                                                                    </span>
                                                                                    <span class="badge bg-primary rounded-pill px-1 py-0 ms-1" style="font-size: 0.7rem;">
                                                                                        <?php echo $merged_stat['person_count']; ?>
                                                                                        <?php if ($merged_stat['count'] > 1): ?>
                                                                                            ×<?php echo $merged_stat['count']; ?>
                                                                                        <?php endif; ?>
                                                                                    </span>
                                                                                </div>
                                                                            <?php endforeach; ?>
                                                                            </div>
                                                                        </td>
                                                                        <td class="text-center align-middle fw-bold">
                                                                            <?php echo $dept_totals[$dept_name]['meals']; ?> 份
                                                                        </td>
                                                                    </tr>
                                                                    <tr class="table-light">
                                                                        <td class="text-end"><strong><?php echo htmlspecialchars($dept_name); ?> 小计：</strong></td>
                                                                        <td class="text-center" colspan="2"><?php echo $dept_totals[$dept_name]['persons']; ?> 人</td>
                                                                        <td class="text-center fw-bold"><?php echo $dept_totals[$dept_name]['meals']; ?> 份</td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                            <tfoot class="table-dark">
                                                                <tr>
                                                                    <td colspan="2" class="text-end"><strong>总计：</strong></td>
                                                                    <td class="text-center"><?php echo $total_persons; ?> 人</td>
                                                                    <td class="text-center fw-bold"><?php echo $total_meals; ?> 份</td>
                                                                </tr>
                                                            </tfoot>
                                                        </table>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <!-- 统计表结束 -->
                                
                            <?php endif; ?>
                        <?php else: ?>
                            <?php if (empty($meals)): ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-inbox display-1 text-muted"></i>
                                    <h5 class="text-muted mt-3">暂无报餐记录</h5>
                                    <p class="text-muted">点击上方"批量报餐"按钮开始报餐</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-dark">
                                            <tr>
                                                <th>人员</th>
                                                <th>部门</th>
                                                <th>用餐日期</th>
                                                <th>餐类型</th>
                                                <th>套餐</th>
                                                <th>用餐时间</th>
                                                <th>送餐时间</th>
                                                <th>状态</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($meals as $meal): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($meal['personnel_name']); ?></td>
                                                    <td>
                                                        <span class="badge bg-secondary">
                                                            <?php echo htmlspecialchars($meal['department_name'] ?? '未分配'); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo $meal['meal_date']; ?></td>
                                                    <td>
                                                        <?php 
                                                        $type_colors = ['早餐'=>'warning', '午餐'=>'primary', '晚餐'=>'success', '宵夜'=>'info'];
                                                        $color = $type_colors[$meal['meal_type']] ?? 'secondary';
                                                        ?>
                                                        <span class="badge bg-<?php echo $color; ?>">
                                                            <?php echo $meal['meal_type']; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($meal['package_name']): ?>
                                                            <span class="text-primary fw-bold">
                                                                <?php echo htmlspecialchars($meal['package_name']); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">未选择</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo $meal['meal_time'] ?: '-'; ?></td>
                                                    <td><?php echo $meal['delivery_time'] ?: '-'; ?></td>
                                                    <td>
                                                        <?php 
                                                        $status_config = [
                                                            'pending' => ['class' => 'warning', 'text' => '待确认'],
                                                            'confirmed' => ['class' => 'success', 'text' => '已确认'],
                                                            'cancelled' => ['class' => 'danger', 'text' => '已取消']
                                                        ];
                                                        $status = $status_config[$meal['status']] ?? ['class' => 'secondary', 'text' => '未知'];
                                                        ?>
                                                        <span class="badge bg-<?php echo $status['class']; ?>">
                                                            <?php echo $status['text']; ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 页面初始化逻辑（保留其他功能）
    // 注：批量报餐相关JS已移至 batch_meal_order.php
});
    
    // 导出按日统计数据
    window.exportDailyStats = function(format) {
        // 优先使用新的餐类型统计数据
        const dailyCards = document.querySelectorAll('.card.border-primary');
        if (dailyCards.length > 0) {
            exportDailyMealTypeStats(format);
            return;
        }

        // 回退到原始表格数据
        const table = document.querySelector('.daily-stats-table');
        if (!table) {
            alert('没有找到可导出的数据');
            return;
        }

        const rows = Array.from(table.querySelectorAll('tbody tr'));
        const data = [];
        
        // 添加表头
        const headers = ['用餐日期', '餐类型', '用餐数量', '用餐人数', '套餐统计', '涉及部门', '人员名单'];
        data.push(headers);
        
        // 提取数据
        rows.forEach(row => {
            const cells = Array.from(row.querySelectorAll('td'));
            const rowData = cells.map(cell => {
                // 处理包含HTML标签的单元格
                let text = cell.textContent.trim();
                if (cell.querySelector('.badge')) {
                    // 处理包含多个标签的单元格
                    const badges = Array.from(cell.querySelectorAll('.badge'));
                    text = badges.map(badge => badge.textContent.trim()).join(', ');
                }
                return text;
            });
            data.push(rowData);
        });

        if (format === 'excel') {
            exportToExcel(data, '每日用餐统计');
        } else if (format === 'csv') {
            exportToCSV(data, '每日用餐统计');
        }
    }

    // 导出包含餐类型区分的统计数据
    window.exportDailyMealTypeStats = function(format) {
        const dailyCards = document.querySelectorAll('.card.border-primary');
        if (dailyCards.length === 0) {
            alert('没有找到可导出的餐类型统计数据');
            return;
        }

        const data = [];
        
        // 添加表头
        const headers = ['用餐日期', '餐类型', '用餐份数', '用餐人数'];
        data.push(headers);
        
        // 提取每日餐类型统计数据
        dailyCards.forEach(card => {
            const date = card.querySelector('.card-header h6').textContent.trim();
            const rows = card.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length >= 3) {
                    const mealType = cells[0].textContent.trim();
                    const mealCount = cells[1].textContent.trim();
                    const personnelCount = cells[2].textContent.trim();
                    
                    // 跳过总计行
                    if (mealType !== '每日总计') {
                        data.push([date, mealType, mealCount, personnelCount]);
                    }
                }
            });
            
            // 添加每日总计行
            const totalRow = card.querySelector('tfoot tr');
            if (totalRow) {
                const totalCells = totalRow.querySelectorAll('td');
                if (totalCells.length >= 3) {
                    const totalMeals = totalCells[1].textContent.trim();
                    const totalPersonnel = totalCells[2].textContent.trim();
                    data.push([date, '每日总计', totalMeals, totalPersonnel]);
                }
            }
        });

        if (format === 'excel') {
            exportToExcel(data, '每日餐类型统计');
        } else if (format === 'csv') {
            exportToCSV(data, '每日餐类型统计');
        }
    }

    function exportToExcel(data, filename) {
        // 检查是否支持XLSX
        if (typeof XLSX === 'undefined') {
            // 动态加载XLSX库
            const script = document.createElement('script');
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
            script.onload = function() {
                const ws = XLSX.utils.aoa_to_sheet(data);
                const wb = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(wb, ws, 'Sheet1');
                XLSX.writeFile(wb, filename + '.xlsx');
            };
            document.head.appendChild(script);
        } else {
            const ws = XLSX.utils.aoa_to_sheet(data);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, 'Sheet1');
            XLSX.writeFile(wb, filename + '.xlsx');
        }
    }

    function exportToCSV(data, filename) {
        const csvContent = data.map(row => 
            row.map(cell => `"${cell.toString().replace(/"/g, '""')}"`).join(',')
        ).join('\n');
        
        const blob = new Blob(['\ufeff' + csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename + '.csv';
        link.click();
    }
</script>

<?php include 'includes/footer.php'; ?>