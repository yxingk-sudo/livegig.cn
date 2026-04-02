<?php
session_start();

// 引入基础控制器进行权限验证
require_once '../includes/BaseAdminController.php';
require_once '../config/database.php';
// 更可靠地包含page_functions.php，如果文件不存在则定义默认函数
$page_functions_path = __DIR__ . '/page_functions.php';
if (file_exists($page_functions_path)) {
    require_once $page_functions_path;
} else {
    // 定义默认的getCurrentPage函数
    if (!function_exists('getCurrentPage')) {
        function getCurrentPage() {
            return basename($_SERVER['PHP_SELF']);
        }
    }
}

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();

$message = '';
$error = '';

// 获取项目信息（如果有选择项目）
$project_dates = ['start_date' => '', 'end_date' => ''];
if (isset($_GET['project_id']) && intval($_GET['project_id']) > 0) {
    $project_query = "SELECT start_date, end_date FROM projects WHERE id = :project_id";
    $project_stmt = $db->prepare($project_query);
    $project_stmt->bindParam(':project_id', $_GET['project_id']);
    $project_stmt->execute();
    $project_dates = $project_stmt->fetch(PDO::FETCH_ASSOC);
}

// 获取项目筛选参数
$filters = [
    'project_id' => isset($_GET['project_id']) ? intval($_GET['project_id']) : 0,
    'date_from' => $_GET['date_from'] ?? ($project_dates['start_date'] ?: date('Y-m-01')),
    'date_to' => $_GET['date_to'] ?? ($project_dates['end_date'] ?: date('Y-m-d')),
    'meal_type' => $_GET['meal_type'] ?? '',
    'department_id' => $_GET['department_id'] ?? ''
];

// 获取所有项目用于选择
$projects_query = "SELECT id, name, code FROM projects ORDER BY name ASC";
$projects_stmt = $db->prepare($projects_query);
$projects_stmt->execute();
$all_projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取部门列表（只有选择了项目才查询）
$departments = [];
if ($filters['project_id']) {
    $dept_query = "SELECT DISTINCT d.id, d.name 
                   FROM departments d 
                   JOIN project_department_personnel pdp ON d.id = pdp.department_id 
                   WHERE pdp.project_id = :project_id 
                   ORDER BY d.name";
    $dept_stmt = $db->prepare($dept_query);
    $dept_stmt->bindParam(':project_id', $filters['project_id']);
    $dept_stmt->execute();
    $departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);
}

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

// 获取详细记录（用于删除确认）
function getDetailedRecords($db, $filters) {
    $clause = buildWhereClause($filters['project_id'], $filters);
    
    $query = "SELECT 
                mr.id,
                mr.meal_date,
                mr.meal_type,
                mr.meal_count,
                p.name as personnel_name,
                d.name as department_name,
                mp.name as package_name,
                mp.price as price
              FROM meal_reports mr
              JOIN personnel p ON mr.personnel_id = p.id
              LEFT JOIN project_department_personnel pdp ON (mr.project_id = pdp.project_id AND p.id = pdp.personnel_id)
              LEFT JOIN departments d ON pdp.department_id = d.id
              LEFT JOIN meal_packages mp ON mr.package_id = mp.id
              WHERE {$clause['where']}
              ORDER BY p.name ASC, mr.meal_date DESC, mr.meal_type";
    
    $stmt = $db->prepare($query);
    $stmt->execute($clause['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 获取每日统计
function getDailyStatistics($db, $filters) {
    $clause = buildWhereClause($filters['project_id'], $filters);
    
    $query = "SELECT 
                mr.meal_date,
                mr.meal_type,
                COUNT(*) as person_count,
                SUM(mr.meal_count) as total_meals,
                SUM(mr.meal_count * COALESCE(mp.price, 0)) as total_amount
              FROM meal_reports mr
              LEFT JOIN personnel p ON mr.personnel_id = p.id
              LEFT JOIN project_department_personnel pdp ON (mr.project_id = pdp.project_id AND p.id = pdp.personnel_id)
              LEFT JOIN meal_packages mp ON mr.package_id = mp.id
              WHERE {$clause['where']}
              GROUP BY mr.meal_date, mr.meal_type
              ORDER BY mr.meal_date DESC, mr.meal_type";
    
    $stmt = $db->prepare($query);
    $stmt->execute($clause['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 获取部门统计
function getDepartmentStatistics($db, $filters) {
    $clause = buildWhereClause($filters['project_id'], $filters);
    
    $query = "SELECT 
                d.name as department_name,
                mr.meal_type,
                COUNT(*) as person_count,
                SUM(mr.meal_count) as total_meals,
                SUM(mr.meal_count * COALESCE(mp.price, 0)) as total_amount
              FROM meal_reports mr
              JOIN personnel p ON mr.personnel_id = p.id
              JOIN project_department_personnel pdp ON (mr.project_id = pdp.project_id AND p.id = pdp.personnel_id)
              JOIN departments d ON pdp.department_id = d.id
              LEFT JOIN meal_packages mp ON mr.package_id = mp.id
              WHERE {$clause['where']}
              GROUP BY d.name, mr.meal_type
              ORDER BY d.name, mr.meal_type";
    
    $stmt = $db->prepare($query);
    $stmt->execute($clause['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 获取项目统计 - 移除项目统计函数
// 注释掉项目统计函数，因为用户要求移除项目用餐统计表
/*
function getProjectStatistics($db, $filters) {
    $clause = buildWhereClause($filters['project_id'], $filters);
    
    $query = "SELECT 
                pr.name as project_name,
                pr.code as project_code,
                mr.meal_type,
                COUNT(*) as person_count,
                SUM(mr.meal_count) as total_meals,
                SUM(mr.meal_count * COALESCE(mp.price, 0)) as total_amount
              FROM meal_reports mr
              JOIN projects pr ON mr.project_id = pr.id
              JOIN personnel p ON mr.personnel_id = p.id
              LEFT JOIN project_department_personnel pdp ON (mr.project_id = pdp.project_id AND p.id = pdp.personnel_id)
              LEFT JOIN meal_packages mp ON mr.package_id = mp.id
              WHERE {$clause['where']}
              GROUP BY pr.name, pr.code, mr.meal_type
              ORDER BY pr.name, mr.meal_type";
    
    $stmt = $db->prepare($query);
    $stmt->execute($clause['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
*/

// 获取套餐统计
function getPackageStatistics($db, $filters) {
    $clause = buildWhereClause($filters['project_id'], $filters);
    
    $query = "SELECT 
                mp.name as package_name,
                mr.meal_type,
                COUNT(*) as order_count,
                SUM(mr.meal_count) as total_meals,
                mp.price,
                SUM(mr.meal_count * mp.price) as total_amount
              FROM meal_reports mr
              JOIN meal_packages mp ON mr.package_id = mp.id
              LEFT JOIN personnel p ON mr.personnel_id = p.id
              LEFT JOIN project_department_personnel pdp ON (mr.project_id = pdp.project_id AND p.id = pdp.personnel_id)
              WHERE {$clause['where']} AND mr.package_id IS NOT NULL
              GROUP BY mp.name, mr.meal_type, mp.price
              ORDER BY total_amount DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($clause['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 获取餐类型统计
function getMealTypeStatistics($db, $filters) {
    $clause = buildWhereClause($filters['project_id'], $filters);
    
    $query = "SELECT 
                mr.meal_type,
                COUNT(*) as person_count,
                SUM(mr.meal_count) as total_meals,
                SUM(mr.meal_count * COALESCE(mp.price, 0)) as total_amount
              FROM meal_reports mr
              LEFT JOIN meal_packages mp ON mr.package_id = mp.id
              LEFT JOIN personnel p ON mr.personnel_id = p.id
              LEFT JOIN project_department_personnel pdp ON (mr.project_id = pdp.project_id AND p.id = pdp.personnel_id)
              WHERE {$clause['where']}
              GROUP BY mr.meal_type
              ORDER BY total_amount DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($clause['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 状态映射
$status_map = [
    'pending' => ['label' => '待确认', 'class' => 'warning'],
    'confirmed' => ['label' => '已确认', 'class' => 'success'],
    'cancelled' => ['label' => '已取消', 'class' => 'danger']
];

// 餐类型映射
$meal_type_map = [
    '早餐' => '早餐',
    '午餐' => '午餐',
    '晚餐' => '晚餐',
    '宵夜' => '宵夜'
];

// 处理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// 初始化统计变量
$detailedRecords = []; // 详细记录数组
$totalRecords = 0;     // 总记录数
$totalMeals = 0;       // 总用餐数
$totalAmount = 0;      // 总金额

$dailyStats = [];      // 每日统计
$departmentStats = []; // 部门统计
$projectStats = [];    // 项目统计
$packageStats = [];    // 套餐统计
$mealTypeStats = [];   // 餐类型统计

// 如果选择了项目，获取统计数据
if ($filters['project_id']) {
    $detailedRecords = getDetailedRecords($db, $filters);
    $dailyStats = getDailyStatistics($db, $filters);
    $departmentStats = getDepartmentStatistics($db, $filters);
    // 移除项目统计调用
    // $projectStats = getProjectStatistics($db, $filters);
    $packageStats = getPackageStatistics($db, $filters);
    $mealTypeStats = getMealTypeStatistics($db, $filters);
    
    $totalRecords = count($detailedRecords);
    
    // 计算总用餐数和总金额
    foreach ($detailedRecords as $record) {
        $totalMeals += isset($record['meal_count']) ? $record['meal_count'] : 0;
        $mealPrice = isset($record['price']) ? $record['price'] : 0;
        $mealCount = isset($record['meal_count']) ? $record['meal_count'] : 0;
        $totalAmount += $mealPrice * $mealCount;
    }
}
// 修改详细记录表部分，实现按人员整合所有用餐信息
// 新增：按人员整合用餐数据的功能
$personMealSummary = [];
foreach ($detailedRecords as $record) {
    $personName = $record['personnel_name'];
    $department = $record['department_name'] ?? '未分配';
    
    if (!isset($personMealSummary[$personName])) {
        $personMealSummary[$personName] = [
            'name' => $personName,
            'department' => $department,
            'meals' => [],
            'total_amount' => 0,
            'meal_count' => 0
        ];
    }
    
    $amount = ($record['price'] ?? 0) * ($record['meal_count'] ?? 1);
    $personMealSummary[$personName]['meals'][] = [
        'date' => $record['meal_date'],
        'type' => $record['meal_type'],
        'package' => $record['package_name'] ?? '标准套餐',
        'count' => $record['meal_count'] ?? 1,
        'price' => $record['price'] ?? 0,
        'amount' => $amount
    ];
    
    $personMealSummary[$personName]['total_amount'] += $amount;
    $personMealSummary[$personName]['meal_count'] += $record['meal_count'] ?? 1;
}

// 计算总计
$grandTotal = array_sum(array_column($personMealSummary, 'total_amount'));
$totalPersons = count($personMealSummary);

// 设置页面标题
$page_title = '用餐统计';
?>
<?php include 'includes/header.php'; ?>

<style>
/* 项目选择区域样式 */
.project-selector {
    background-color: #ffffff;
    border: 1px solid #e9ecef;
    border-radius: 0.5rem;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.05);
    padding: 1rem;
    margin-bottom: 1rem;
}

.project-selector h4 {
    color: #495057;
    font-weight: 600;
    font-size: 1rem;
    margin-bottom: 1rem;
}

.project-status {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.25rem 0.75rem;
    border-radius: 0.5rem;
    font-weight: 600;
    font-size: 0.875rem;
    margin-top: 0.5rem;
}

.project-status.selected {
    background-color: #d1e7dd;
    color: #0f5132;
    border: 1px solid #badbcc;
}

.project-status.not-selected {
    background-color: #f8d7da;
    color: #842029;
    border: 1px solid #f5c2c7;
}

/* 统计容器样式 */
.stats-container {
    background-color: #ffffff;
    border: 1px solid #e9ecef;
    border-radius: 0.5rem;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.05);
    overflow: hidden;
    margin-bottom: 1rem;
}

.stats-header {
    background-color: #f8f9fa;
    color: #495057;
    padding: 0.75rem 1rem;
    margin: 0;
    font-weight: 600;
    font-size: 1rem;
}

.stats-body {
    padding: 1rem;
}

/* 汇总卡片 */
.summary-card {
    background-color: #ffffff;
    border: 1px solid #e9ecef;
    border-radius: 0.5rem;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.05);
    height: 100%;
}

.summary-card .card-body {
    padding: 1rem;
}

.summary-icon {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 0.5rem;
    margin-bottom: 0.5rem;
    font-size: 1rem;
}

.summary-number {
    font-size: 1.5rem;
    font-weight: 700;
    margin-bottom: 0.25rem;
}

.summary-label {
    font-size: 0.875rem;
    color: #6c757d;
    font-weight: 500;
}

/* 空状态 */
.empty-state {
    background-color: #f8f9fa;
    border: 2px dashed #dee2e6;
    border-radius: 0.5rem;
    padding: 2rem;
    text-align: center;
    color: #6c757d;
}

.empty-state i {
    opacity: 0.5;
    margin-bottom: 1rem;
}

.empty-state h5 {
    color: #495057;
    font-weight: 600;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const dateFrom = document.getElementById('date_from');
    const dateTo = document.getElementById('date_to');
    
    if (dateFrom && dateTo) {
        // 监听开始日期变化
        dateFrom.addEventListener('change', function() {
            if (dateTo.value && dateTo.value < dateFrom.value) {
                dateTo.value = dateFrom.value;
            }
            dateTo.min = dateFrom.value;
        });
        
        // 监听结束日期变化
        dateTo.addEventListener('change', function() {
            if (dateFrom.value && dateFrom.value > dateTo.value) {
                dateFrom.value = dateTo.value;
            }
            dateFrom.max = dateTo.value;
        });

        // 项目更改时重置日期范围
        const projectSelect = document.getElementById('project_id');
        if (projectSelect) {
            projectSelect.addEventListener('change', function() {
                if (!this.value) {
                    dateFrom.value = '';
                    dateTo.value = '';
                    dateFrom.removeAttribute('min');
                    dateFrom.removeAttribute('max');
                    dateTo.removeAttribute('min');
                    dateTo.removeAttribute('max');
                }
            });
        }
    }
});
</script>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <!-- 项目选择区域 -->
            <div class="project-selector">
                <h4>
                    <i class="bi bi-building"></i>
                    项目选择
                </h4>
                <form method="GET" id="projectForm">
                    <!-- 保持其他筛选条件 -->
                    <?php if (isset($_GET['date_from'])): ?><input type="hidden" name="date_from" value="<?php echo htmlspecialchars($_GET['date_from']); ?>"><?php endif; ?>
                    <?php if (isset($_GET['date_to'])): ?><input type="hidden" name="date_to" value="<?php echo htmlspecialchars($_GET['date_to']); ?>"><?php endif; ?>
                    <?php if (isset($_GET['meal_type'])): ?><input type="hidden" name="meal_type" value="<?php echo htmlspecialchars($_GET['meal_type']); ?>"><?php endif; ?>
                    <?php if (isset($_GET['department_id'])): ?><input type="hidden" name="department_id" value="<?php echo htmlspecialchars($_GET['department_id']); ?>"><?php endif; ?>
                    
                    <div class="row align-items-end">
                        <div class="col-md-8">
                            <label for="project_id" class="form-label fw-semibold">请选择要查看的项目</label>
                            <select class="form-select" id="project_id" name="project_id" onchange="this.form.submit()">
                                <option value="">请选择项目...</option>
                                <?php foreach ($all_projects as $project): ?>
                                    <option value="<?php echo $project['id']; ?>" 
                                            <?php echo $filters['project_id'] == $project['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($project['name']); ?>
                                        <?php if ($project['code']): ?>
                                            (<?php echo htmlspecialchars($project['code']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <?php if ($filters['project_id']): ?>
                                <div class="project-status selected">
                                    <i class="bi bi-check-circle-fill"></i>
                                    已选择项目
                                </div>
                            <?php else: ?>
                                <div class="project-status not-selected">
                                    <i class="bi bi-exclamation-circle-fill"></i>
                                    请先选择项目
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($filters['project_id']): ?>
                <!-- 统计区域 -->
                <div class="stats-container">
                    <div class="stats-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-bar-chart me-2"></i>用餐统计分析
                            </h5>
                        </div>
                    </div>
                    <div class="stats-body">
                        <!-- 筛选表单 -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-funnel"></i> 筛选条件</h5>
                            </div>
                            <div class="card-body">
                                <form method="GET" class="row g-3">
                                    <input type="hidden" name="project_id" value="<?php echo htmlspecialchars($filters['project_id']); ?>">
                                    <div class="col-md-2">
                                        <label class="form-label">开始日期</label>
                                        <input type="date" class="form-control" name="date_from" id="date_from"
                                               value="<?php echo htmlspecialchars($filters['date_from']); ?>"
                                               min="<?php echo htmlspecialchars($project_dates['start_date']); ?>" 
                                               max="<?php echo htmlspecialchars($project_dates['end_date']); ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">结束日期</label>
                                        <input type="date" class="form-control" name="date_to" id="date_to"
                                               value="<?php echo htmlspecialchars($filters['date_to']); ?>"
                                               min="<?php echo htmlspecialchars($project_dates['start_date']); ?>" 
                                               max="<?php echo htmlspecialchars($project_dates['end_date']); ?>">
                                    </div>
                                    <div class="col-md-2">
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
                                            <i class="bi bi-search"></i> 筛选
                                        </button>
                                        <a href="?project_id=<?php echo $filters['project_id']; ?>" class="btn btn-outline-secondary">
                                            <i class="bi bi-arrow-clockwise"></i> 重置
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- 汇总统计 -->
                        <div class="row mb-4">
                            <div class="col-md-3 mb-3">
                                <div class="summary-card">
                                    <div class="card-body text-center">
                                        <div class="summary-icon bg-primary text-white mx-auto">
                                            <i class="bi bi-people"></i>
                                        </div>
                                        <div class="summary-number text-primary"><?php echo $totalRecords; ?></div>
                                        <div class="summary-label">总记录数</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="summary-card">
                                    <div class="card-body text-center">
                                        <div class="summary-icon bg-success text-white mx-auto">
                                            <i class="bi bi-cup-hot"></i>
                                        </div>
                                        <div class="summary-number text-success"><?php echo $totalMeals; ?></div>
                                        <div class="summary-label">总用餐数</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="summary-card">
                                    <div class="card-body text-center">
                                        <div class="summary-icon bg-info text-white mx-auto">
                                            <i class="bi bi-calendar"></i>
                                        </div>
                                        <div class="summary-number text-info"><?php echo count(array_unique(array_column($detailedRecords, 'meal_date'))); ?></div>
                                        <div class="summary-label">用餐天数</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="summary-card">
                                    <div class="card-body text-center">
                                        <div class="summary-icon bg-warning text-white mx-auto">
                                            <i class="bi bi-currency-dollar"></i>
                                        </div>
                                        <div class="summary-number text-warning">¥<?php echo number_format($totalAmount, 2); ?></div>
                                        <div class="summary-label">总金额</div>
                                    </div>
                                </div>
                            </div>
                        </div>




                        <!-- 餐类型统计 -->
                        <div class="row mb-4">
                            <div class="col-12">
                                <div class="card">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h5 class="mb-0">
                                            <i class="bi bi-calendar3 me-2"></i>餐类型统计
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <?php if (empty($mealTypeStats)): ?>
                                            <p class="text-muted text-center py-4">暂无数据</p>
                                        <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table table-hover">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>餐类型</th>
                                                            <th>用餐人数</th>
                                                            <th>总餐数</th>
                                                            <th>总金额</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($mealTypeStats as $stat): ?>
                                                            <tr>
                                                                <td>
                                                                    <?php 
                                                                    $type_colors = ['早餐'=>'warning', '午餐'=>'primary', '晚餐'=>'success', '宵夜'=>'info'];
                                                                    $color = $type_colors[$stat['meal_type']] ?? 'secondary';
                                                                    ?>
                                                                    <span class="badge bg-<?php echo $color; ?>">
                                                                        <?php echo $stat['meal_type']; ?>
                                                                    </span>
                                                                </td>
                                                                <td>
                                                                    <span class="badge bg-primary rounded-pill">
                                                                        <?php echo $stat['person_count']; ?>
                                                                    </span>
                                                                </td>
                                                                <td><?php echo $stat['total_meals']; ?></td>
                                                                <td class="text-success fw-bold">¥<?php echo number_format($stat['total_amount'], 2); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                    <tfoot class="table-light">
                                                        <tr class="fw-bold">
                                                            <td>总计</td>
                                                            <td><?php echo array_sum(array_column($mealTypeStats, 'person_count')); ?></td>
                                                            <td><?php echo array_sum(array_column($mealTypeStats, 'total_meals')); ?></td>
                                                            <td class="text-success fw-bold">¥<?php echo number_format(array_sum(array_column($mealTypeStats, 'total_amount')), 2); ?></td>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <!-- 每日统计 -->
                            <div class="col-md-6 mb-4">
                                <div class="card h-100">
                                    <div class="card-header">
                                        <h5 class="mb-0">
                                            <i class="bi bi-calendar3 me-2"></i>每日用餐统计
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <?php if (empty($dailyStats)): ?>
                                            <p class="text-muted text-center py-4">暂无数据</p>
                                        <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm">
                                                    <thead>
                                                        <tr>
                                                            <th>日期</th>
                                                            <th>餐类型</th>
                                                            <th>人数</th>
                                                            <th>总餐数</th>
                                                            <th>金额</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php 
                                                        $current_date = '';
                                                        foreach ($dailyStats as $stat): 
                                                            $show_date = ($current_date !== $stat['meal_date']);
                                                            $current_date = $stat['meal_date'];
                                                        ?>
                                                            <tr>
                                                                <td><?php echo $show_date ? $stat['meal_date'] : ''; ?></td>
                                                                <td>
                                                                    <?php 
                                                                    $type_colors = ['早餐'=>'warning', '午餐'=>'primary', '晚餐'=>'success', '宵夜'=>'info'];
                                                                    $color = $type_colors[$stat['meal_type']] ?? 'secondary';
                                                                    ?>
                                                                    <span class="badge bg-<?php echo $color; ?>">
                                                                        <?php echo $stat['meal_type']; ?>
                                                                    </span>
                                                                </td>
                                                                <td>
                                                                    <span class="badge bg-primary rounded-pill">
                                                                        <?php echo $stat['person_count']; ?>
                                                                    </span>
                                                                </td>
                                                                <td><?php echo $stat['total_meals']; ?></td>
                                                                <td class="text-success">¥<?php echo number_format($stat['total_amount'], 2); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- 部门统计 -->
                            <div class="col-md-6 mb-4">
                                <div class="card h-100">
                                    <div class="card-header">
                                        <h5 class="mb-0">
                                            <i class="bi bi-people me-2"></i>部门用餐统计
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <?php if (empty($departmentStats)): ?>
                                            <p class="text-muted text-center py-4">暂无数据</p>
                                        <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm">
                                                    <thead>
                                                        <tr>
                                                            <th>部门</th>
                                                            <th>餐类型</th>
                                                            <th>人数</th>
                                                            <th>总餐数</th>
                                                            <th>金额</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php 
                                                        $current_dept = '';
                                                        foreach ($departmentStats as $stat): 
                                                            $show_dept = ($current_dept !== $stat['department_name']);
                                                            $current_dept = $stat['department_name'];
                                                        ?>
                                                            <tr>
                                                                <td><?php echo $show_dept ? htmlspecialchars($stat['department_name']) : ''; ?></td>
                                                                <td>
                                                                    <?php 
                                                                    $type_colors = ['早餐'=>'warning', '午餐'=>'primary', '晚餐'=>'success', '宵夜'=>'info'];
                                                                    $color = $type_colors[$stat['meal_type']] ?? 'secondary';
                                                                    ?>
                                                                    <span class="badge bg-<?php echo $color; ?>">
                                                                        <?php echo $stat['meal_type']; ?>
                                                                    </span>
                                                                </td>
                                                                <td>
                                                                    <span class="badge bg-primary rounded-pill">
                                                                        <?php echo $stat['person_count']; ?>
                                                                    </span>
                                                                </td>
                                                                <td><?php echo $stat['total_meals']; ?></td>
                                                                <td class="text-success">¥<?php echo number_format($stat['total_amount'], 2); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <!-- 套餐统计 -->
                            <div class="col-12 mb-4">
                                <div class="card h-100">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h5 class="mb-0">
                                            <i class="bi bi-box me-2"></i>套餐订购统计
                                        </h5>
                                        <?php if (!empty($packageStats)): ?>
                                            <span class="badge bg-success">
                                                ¥<?php echo number_format(array_sum(array_column($packageStats, 'total_amount')), 2); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="card-body">
                                        <?php if (empty($packageStats)): ?>
                                            <p class="text-muted text-center py-4">暂无套餐数据</p>
                                        <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table table-hover table-sm">
                                                    <thead>
                                                        <tr>
                                                            <th>套餐名称</th>
                                                            <th>餐类型</th>
                                                            <th>订购次数</th>
                                                            <th>总餐数</th>
                                                            <th>单价</th>
                                                            <th>总金额</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($packageStats as $stat): ?>
                                                            <tr>
                                                                <td class="fw-bold"><?php echo htmlspecialchars($stat['package_name']); ?></td>
                                                                <td>
                                                                    <?php 
                                                                    $type_colors = ['早餐'=>'warning', '午餐'=>'primary', '晚餐'=>'success', '宵夜'=>'info'];
                                                                    $color = $type_colors[$stat['meal_type']] ?? 'secondary';
                                                                    ?>
                                                                    <span class="badge bg-<?php echo $color; ?>">
                                                                        <?php echo $stat['meal_type']; ?>
                                                                    </span>
                                                                </td>
                                                                <td>
                                                                    <span class="badge bg-info rounded-pill">
                                                                        <?php echo $stat['order_count']; ?>
                                                                    </span>
                                                                </td>
                                                                <td>
                                                                    <span class="badge bg-primary rounded-pill">
                                                                        <?php echo $stat['total_meals']; ?>
                                                                    </span>
                                                                </td>
                                                                <td class="text-success">¥<?php echo number_format($stat['price'], 2); ?></td>
                                                                <td class="text-danger fw-bold">¥<?php echo number_format($stat['total_amount'], 2); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                    <tfoot class="table-light">
                                                        <tr class="fw-bold">
                                                            <td colspan="5" class="text-end">总计：</td>
                                                            <td class="text-danger">
                                                                ¥<?php echo number_format(array_sum(array_column($packageStats, 'total_amount')), 2); ?>
                                                            </td>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 详细记录表 - 移到页面最后面 -->
                <!-- 按人员整合的用餐记录表 -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="bi bi-person-lines-fill me-2"></i>按人员用餐汇总
                                </h5>
                                <div class="d-flex gap-2">
                                    <span class="badge bg-primary">
                                        共 <?php echo $totalPersons; ?> 人
                                    </span>
                                    <span class="badge bg-success">
                                        总计 ¥<?php echo number_format($grandTotal, 2); ?>
                                    </span>
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="exportPersonSummary()">
                                        <i class="bi bi-download"></i> 导出Excel
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($personMealSummary)): ?>
                                    <div class="empty-state">
                                        <i class="bi bi-person-x display-1"></i>
                                        <h5 class="mt-3">暂无用餐人员数据</h5>
                                        <p class="text-muted mb-4">当前筛选条件下没有找到用餐人员记录</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table id="personSummaryTable" class="table table-hover">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="width: 12%">人员姓名</th>
                                                    <th style="width: 10%">所属部门</th>
                                                    <th style="width: 35%">用餐详情</th>
                                                    <th style="width: 15%">用餐次数</th>
                                                    <th style="width: 15%">单笔消费</th>
                                                    <th style="width: 13%" class="text-end">累计金额</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($personMealSummary as $person): ?>
                                                    <tr>
                                                        <td>
                                                            <strong class="text-primary"><?php echo htmlspecialchars($person['name']); ?></strong>
                                                        </td>
                                                        <td>
                                                            <?php if ($person['department'] && $person['department'] !== '未分配'): ?>
                                                                <span class="badge bg-secondary">
                                                                    <?php echo htmlspecialchars($person['department']); ?>
                                                                </span>
                                                            <?php else: ?>
                                                                <small class="text-muted">未分配</small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div class="meal-details">
                                                                <?php 
                                                                $mealDetails = [];
                                                                foreach ($person['meals'] as $meal): 
                                                                    $type_colors = ['早餐'=>'warning', '午餐'=>'primary', '晚餐'=>'success', '宵夜'=>'info'];
                                                                    $color = $type_colors[$meal['type']] ?? 'secondary';
                                                                    $mealDetails[] = sprintf(
                                                                        '<span class="badge bg-%s me-1 mb-1" title="%s %s">%s %s</span>',
                                                                        $color,
                                                                        $meal['date'],
                                                                        $meal['type'],
                                                                        $meal['date'],
                                                                        $meal['type']
                                                                    );
                                                                endforeach; 
                                                                echo implode('', $mealDetails);
                                                                ?>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-primary rounded-pill">
                                                                <?php echo $person['meal_count']; ?> 次
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <div class="meal-amounts">
                                                                <?php 
                                                                $amounts = [];
                                                                foreach ($person['meals'] as $meal): 
                                                                    $amounts[] = sprintf(
                                                                        '<small class="text-muted d-block">%s %s: ¥%s</small>',
                                                                        $meal['date'],
                                                                        $meal['type'],
                                                                        number_format($meal['amount'], 2)
                                                                    );
                                                                endforeach; 
                                                                echo implode('', $amounts);
                                                                ?>
                                                            </div>
                                                        </td>
                                                        <td class="text-end">
                                                            <strong class="text-danger fs-5">¥<?php echo number_format($person['total_amount'], 2); ?></strong>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot class="table-light">
                                                <tr class="fw-bold">
                                                    <td colspan="5" class="text-end">总计：</td>
                                                    <td class="text-danger fs-5">
                                                        ¥<?php echo number_format($grandTotal, 2); ?>
                                                    </td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- 空状态 -->
                <div class="empty-state">
                    <i class="bi bi-folder-x" style="font-size: 4rem;"></i>
                    <h5>请先选择项目</h5>
                    <p class="mb-0">请在上方选择一个项目以查看用餐统计数据</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
