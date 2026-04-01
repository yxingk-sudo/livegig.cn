<?php
// 报餐管理表格页面
// 功能：以表格形式展示人员入住日期范围内的餐次选择
// 版本：2026-03-06-v1

// 禁止浏览器缓存
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once '../config/database.php';
require_once '../includes/functions.php';

session_start();
requireLogin();
checkProjectPermission($_SESSION['project_id']);

// 获取数据库连接
$database = new Database();
$db = $database->getConnection();

$projectId = $_SESSION['project_id'];

// 获取项目详情
$project = getProjectDetails($projectId, $db);
$projectStartDate = $project['start_date'];
$projectEndDate = $project['end_date'];

// 获取项目人员及其部门信息（按部门排序）
$personnel = [];
try {
    $personnel_sql = "
        SELECT 
            p.id,
            p.name,
            GROUP_CONCAT(DISTINCT d.name ORDER BY d.sort_order ASC, d.name SEPARATOR ', ') as departments,
            GROUP_CONCAT(DISTINCT d.id ORDER BY d.sort_order ASC, d.name SEPARATOR ',') as department_ids
        FROM personnel p
        INNER JOIN project_department_personnel pdp ON p.id = pdp.personnel_id
        LEFT JOIN departments d ON pdp.department_id = d.id
        WHERE pdp.project_id = :project_id
        GROUP BY p.id, p.name
        ORDER BY MIN(d.sort_order) ASC, p.name ASC
    ";
    $stmt = $db->prepare($personnel_sql);
    $stmt->bindParam(':project_id', $projectId);
    $stmt->execute();
    $personnel = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $error_message = "获取人员列表时出错：" . $e->getMessage();
    $personnel = [];
}

// 获取每个人的酒店入住记录，计算入住日期范围
$allDates = []; // 收集所有唯一的日期
$personnelDateRanges = []; // 每个人的入住日期范围

foreach ($personnel as &$person) {
    $query = "SELECT check_in_date, check_out_date FROM hotel_reports 
              WHERE personnel_id = :personnel_id AND project_id = :project_id
              ORDER BY check_in_date";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':personnel_id', $person['id']);
    $stmt->bindParam(':project_id', $projectId);
    $stmt->execute();
    
    $hotelReports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 合并所有入住日期范围
    $personDates = [];
    foreach ($hotelReports as $report) {
        $startDate = new DateTime($report['check_in_date']);
        $endDate = new DateTime($report['check_out_date']);
        
        // 生成该时间段内的所有日期
        $interval = DateInterval::createFromDateString('1 day');
        $period = new DatePeriod($startDate, $interval, $endDate->modify('+1 day'));
        
        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');
            $personDates[$dateStr] = true;
            $allDates[$dateStr] = true;
        }
    }
    
    $personnelDateRanges[$person['id']] = $personDates;
}

// 对所有日期进行排序
ksort($allDates);
$sortedDates = array_keys($allDates);

// 获取项目餐类型配置
$mealTypeConfig = [
    'breakfast_enabled' => true,
    'lunch_enabled' => true,
    'dinner_enabled' => true,
    'supper_enabled' => true
];

$config_query = "SELECT breakfast_enabled, lunch_enabled, dinner_enabled, supper_enabled FROM projects WHERE id = :project_id";
$config_stmt = $db->prepare($config_query);
$config_stmt->bindParam(':project_id', $projectId);
$config_stmt->execute();
$config_row = $config_stmt->fetch(PDO::FETCH_ASSOC);
if ($config_row) {
    $mealTypeConfig = [
        'breakfast_enabled' => (bool)$config_row['breakfast_enabled'],
        'lunch_enabled' => (bool)$config_row['lunch_enabled'],
        'dinner_enabled' => (bool)$config_row['dinner_enabled'],
        'supper_enabled' => (bool)$config_row['supper_enabled']
    ];
}

// 获取项目日期范围配置（多选日期）
$selectedMealDates = [];

$date_config_query = "SELECT selected_meal_dates FROM projects WHERE id = :project_id";
$date_config_stmt = $db->prepare($date_config_query);
$date_config_stmt->bindParam(':project_id', $projectId);
$date_config_stmt->execute();
$date_config_row = $date_config_stmt->fetch(PDO::FETCH_ASSOC);
if ($date_config_row && !empty($date_config_row['selected_meal_dates'])) {
    $decodedDates = json_decode($date_config_row['selected_meal_dates'], true);
    if (is_array($decodedDates)) {
        $selectedMealDates = $decodedDates;
    }
}

// 根据选中的日期过滤
if (!empty($selectedMealDates)) {
    $filteredDates = [];
    foreach ($sortedDates as $date) {
        if (in_array($date, $selectedMealDates)) {
            $filteredDates[$date] = true;
        }
    }
    $sortedDates = array_keys($filteredDates);
}

// 获取已有的报餐记录
$mealReportsMap = [];
if (!empty($sortedDates)) {
    $datePlaceholders = str_repeat('?,', count($sortedDates) - 1) . '?';
    $query = "SELECT personnel_id, meal_date, meal_type FROM meal_reports
              WHERE project_id = ?
              AND meal_date IN ($datePlaceholders)";
    
    $stmt = $db->prepare($query);
    $params = array_merge([$projectId], $sortedDates);
    $stmt->execute($params);
    
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($reports as $report) {
        $key = $report['personnel_id'] . '_' . $report['meal_date'] . '_' . $report['meal_type'];
        $mealReportsMap[$key] = true;
    }
}

// 设置页面特定变量
$page_title = '报餐管理 - ' . ($_SESSION['project_name'] ?? '项目');
$active_page = 'meal_management';
$show_page_title = '报餐管理';
$page_icon = 'calendar-check';

// 包含统一头部文件
include 'includes/header.php';
?>

<style>
/* ===== Espire 报餐管理页面样式 ===== */
.mm-page { font-size: 1rem; }

/* 顶部标题区 */
.mm-hero {
    background: linear-gradient(135deg, #11a1fd 0%, #0d8ae6 100%);
    border-radius: 14px;
    padding: 1.75rem 2rem;
    color: #fff;
    margin-bottom: 1.75rem;
    box-shadow: 0 6px 24px rgba(17,161,253,.35);
}
.mm-hero-title { font-size: 1.55rem; font-weight: 700; margin-bottom: .25rem; }
.mm-hero-sub   { font-size: 1rem; opacity: .9; }
.mm-back-link {
    font-size: .95rem; padding: .45rem 1.1rem; border-radius: 8px;
    background: rgba(255,255,255,.18); color: #fff; border: 1px solid rgba(255,255,255,.4);
}
.mm-back-link:hover { background: rgba(255,255,255,.25); color: #fff; text-decoration: none; }

/* 控制区域 */
.mm-controls-card {
    border: none;
    border-radius: 14px;
    box-shadow: 0 2px 14px rgba(0,0,0,.08);
    margin-bottom: 1.5rem;
}
.mm-controls-card .card-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-bottom: 2px solid #dee2e6;
    padding: .9rem 1.25rem;
    font-size: 1.05rem;
    font-weight: 600;
    color: #495057;
}

/* 表格容器 */
.mm-table-container {
    border: none;
    border-radius: 14px;
    box-shadow: 0 2px 14px rgba(0,0,0,.08);
    overflow-x: auto;
    background: #fff;
}

/* 表格样式 */
.meal-management-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    min-width: 1200px;
}

.meal-management-table th {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-bottom: 2px solid #dee2e6;
    border-right: 1px solid #e9ecef;
    padding: 0.75rem 0.5rem;
    text-align: center;
    font-weight: 600;
    color: #495057;
    font-size: 0.9rem;
    position: sticky;
    top: 0;
    z-index: 10;
}

/* 固定列 */
.meal-management-table .fixed-column {
    position: sticky;
    left: 0;
    background: #fff;
    z-index: 5;
    box-shadow: 2px 0 5px rgba(0,0,0,0.05);
}

.meal-management-table th.fixed-column {
    z-index: 15;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
}

/* 日期列组边框 */
.date-group-header {
    border-left: 3px solid #0d6efd !important;
    border-right: 3px solid #0d6efd !important;
    position: relative;
}

.date-group-header::before {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 2px;
    background-color: #0d6efd;
    opacity: 0.3;
}

.date-group-header .date-label {
    font-size: 0.85rem;
    font-weight: 700;
    color: #0d6efd;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* 日期大列背景色 - 按奇偶数列交替使用不同颜色 */
.date-group-header:nth-child(4n+1) { /* 第 1, 5, 9...天 - 蓝色系 */
    background: linear-gradient(135deg, rgba(52, 152, 219, 0.15) 0%, rgba(41, 128, 185, 0.10) 100%) !important;
}

.date-group-header:nth-child(4n+2) { /* 第 2, 6, 10...天 - 绿色系 */
    background: linear-gradient(135deg, rgba(46, 204, 113, 0.15) 0%, rgba(39, 174, 96, 0.10) 100%) !important;
}

.date-group-header:nth-child(4n+3) { /* 第 3, 7, 11...天 - 橙色系 */
    background: linear-gradient(135deg, rgba(243, 156, 18, 0.15) 0%, rgba(211, 84, 0, 0.10) 100%) !important;
}

.date-group-header:nth-child(4n+4) { /* 第 4, 8, 12...天 - 紫色系 */
    background: linear-gradient(135deg, rgba(155, 89, 182, 0.15) 0%, rgba(142, 68, 173, 0.10) 100%) !important;
}

/* 对应的表体单元格也需要相同的背景色 */
tbody tr td:nth-child(4n+1),
tbody tr td:nth-child(4n+2),
tbody tr td:nth-child(4n+3),
tbody tr td:nth-child(4n+4) {
    background-clip: padding-box;
}

/* 餐类型子列边框 */
.meal-type-subheader {
    background: #f8f9fa;
    font-size: 0.8rem;
    color: #6c757d;
    padding: 0.4rem 0.3rem;
    border-right: 1px solid #dee2e6;
    font-weight: 600;
}

.meal-type-subheader:last-child {
    border-right: none;
}

/* 单元格样式 */
.meal-cell {
    text-align: center;
    padding: 0.5rem 0.3rem;
    border-bottom: 1px solid #e9ecef;
    border-right: 1px solid #dee2e6;
}

.meal-cell:last-child {
    border-right: none;
}

/* 为每个日期大列的单元格添加对应的淡色背景 */
tbody tr td:nth-child(4n+1) { /* 第 1, 5, 9...天 - 蓝色系 */
    background-color: rgba(52, 152, 219, 0.08);
}

tbody tr td:nth-child(4n+2) { /* 第 2, 6, 10...天 - 绿色系 */
    background-color: rgba(46, 204, 113, 0.08);
}

tbody tr td:nth-child(4n+3) { /* 第 3, 7, 11...天 - 橙色系 */
    background-color: rgba(243, 156, 18, 0.08);
}

tbody tr td:nth-child(4n+4) { /* 第 4, 8, 12...天 - 紫色系 */
    background-color: rgba(155, 89, 182, 0.08);
}

.meal-cell.disabled {
    background: #f8f9fa;
    opacity: 0.5;
}

/* 复选框样式 */
.meal-checkbox {
    width: 1.3rem;
    height: 1.3rem;
    cursor: pointer;
    accent-color: #11a1fd;
}

.meal-checkbox:disabled {
    opacity: 0.3;
    cursor: not-allowed;
}

.meal-checkbox:checked {
    accent-color: #11a1fd;
}

/* 人员姓名列 */
.name-td {
    font-weight: 600;
    color: #495057;
    min-width: 120px;
}

/* 部门列 */
.department-td {
    color: #6c757d;
    min-width: 100px;
    font-size: 0.85rem;
}

/* 统计卡片 */
.mm-stat-card {
    background: #fff;
    border-radius: 12px;
    padding: 1.25rem;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
    text-align: center;
}

.mm-stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: #11a1fd;
    margin-bottom: 0.25rem;
}

.mm-stat-label {
    font-size: 0.9rem;
    color: #6c757d;
}

/* 按钮样式 */
.mm-btn {
    font-size: 0.95rem;
    padding: 0.5rem 1.2rem;
    border-radius: 8px;
    font-weight: 500;
    transition: all 0.3s;
}

.mm-btn-primary {
    background: linear-gradient(135deg, #11a1fd, #0d8ae6);
    border: none;
    color: #fff;
}

.mm-btn-primary:hover {
    opacity: 0.9;
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(17,161,253,.3);
}

.mm-btn-secondary {
    background: #6c757d;
    border: none;
    color: #fff;
}

.mm-btn-secondary:hover {
    background: #5a6268;
    color: #fff;
}

/* 加载状态 */
.mm-loading {
    text-align: center;
    padding: 3rem;
    color: #6c757d;
}

.mm-loading i {
    font-size: 2rem;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* 消息提示 */
.mm-toast {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    min-width: 300px;
    animation: slideInRight 0.3s ease-out;
}

@keyframes slideInRight {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

/* 响应式设计 */
@media (max-width: 768px) {
    .mm-hero {
        padding: 1.25rem;
    }
    .mm-hero-title {
        font-size: 1.25rem;
    }
    .meal-management-table {
        font-size: 0.8rem;
    }
    .meal-checkbox {
        width: 1.1rem;
        height: 1.1rem;
    }
}
</style>

<div class="mm-page">

<!-- 英雄区 -->
<div class="mm-hero">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <div class="mm-hero-title">报餐管理</div>
            <div class="mm-hero-sub">
                <i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($_SESSION['project_name'] ?? '项目'); ?>
            </div>
        </div>
        <a href="meals_new.php" class="mm-back-link">
            <i class="bi bi-arrow-left me-1"></i>返回报餐
        </a>
    </div>
</div>

<!-- 控制区域 -->
<div class="card mm-controls-card">
    <div class="card-header">
        <i class="bi bi-sliders text-primary me-2"></i>操作控制
    </div>
    <div class="card-body">
        <div class="row g-3 align-items-center">
            <div class="col-md-3">
                <div class="d-flex gap-2">
                    <button type="button" class="btn mm-btn mm-btn-primary" onclick="selectAllMeals()">
                        <i class="bi bi-check-square me-1"></i>全选
                    </button>
                    <button type="button" class="btn mm-btn mm-btn-secondary" onclick="deselectAllMeals()">
                        <i class="bi bi-x-square me-1"></i>取消全选
                    </button>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">按部门批量报餐</label>
                <select class="form-select form-select-sm" id="departmentSelect">
                    <option value="">选择部门...</option>
                    <?php
                    // 获取部门列表
                    $dept_query = "SELECT DISTINCT d.id, d.name 
                                   FROM departments d 
                                   JOIN project_department_personnel pdp ON d.id = pdp.department_id 
                                   WHERE pdp.project_id = ? 
                                   ORDER BY d.sort_order, d.name";
                    $dept_stmt = $db->prepare($dept_query);
                    $dept_stmt->execute([$projectId]);
                    $departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($departments as $dept):
                    ?>
                        <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- 选择日期 -->
            <div class="col-md-2">
                <label class="form-label small mb-1">报餐日期（可选）</label>
                <select class="form-select form-select-sm" id="batchDateInput">
                    <option value="">所有日期</option>
                    <?php
                    // 按格式显示日期（YYYY-MM-DD (周 X)）
                    foreach ($sortedDates as $date): 
                        $dateObj = new DateTime($date);
                        $weekDay = $dateObj->format('N'); // 1 (Mon) to 7 (Sun)
                        $weekMap = ['1' => '一', '2' => '二', '3' => '三', '4' => '四', '5' => '五', '6' => '六', '7' => '日'];
                        $weekStr = $weekMap[$weekDay];
                        $displayDate = $date . ' (周' . $weekStr . ')';
                    ?>
                        <option value="<?php echo $date; ?>"><?php echo htmlspecialchars($displayDate); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- 选择餐类型 -->
            <div class="col-md-2">
                <label class="form-label small mb-1">餐类型</label>
                <select class="form-select form-select-sm" id="batchMealTypeSelect">
                    <option value="">请选择...</option>
                    <option value="breakfast">早餐</option>
                    <option value="lunch">午餐</option>
                    <option value="dinner">晚餐</option>
                    <option value="supper">宵夜</option>
                </select>
            </div>
            
            <!-- 执行按钮 -->
            <div class="col-md-2 d-flex align-items-end">
                <button type="button" class="btn mm-btn mm-btn-primary w-100 btn-sm" onclick="selectByDepartment()">
                    <i class="bi bi-check-circle me-1"></i>执行批量报餐
                </button>
            </div>
            <div class="col-md-6">
                <div class="row g-2">
                    <div class="col-md-4">
                        <div class="mm-stat-card">
                            <div class="mm-stat-value" id="totalPersons"><?php echo count($personnel); ?></div>
                            <div class="mm-stat-label">总人数</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mm-stat-card">
                            <div class="mm-stat-value" id="totalDates"><?php echo count($sortedDates); ?></div>
                            <div class="mm-stat-label">总天数</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mm-stat-card">
                            <div class="mm-stat-value" id="selectedCount">0</div>
                            <div class="mm-stat-label">已选餐次</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 表格区域 -->
<div class="card mm-table-container">
    <div class="card-body p-0">
        <?php if (empty($sortedDates)): ?>
            <div class="mm-loading">
                <i class="bi bi-info-circle"></i>
                <p class="mt-3">暂无住宿记录，无法显示报餐表格</p>
            </div>
        <?php else: ?>
            <table class="meal-management-table" id="mealManagementTable">
                <thead>
                    <tr>
                        <th class="fixed-column" style="width: 50px;">序号</th>
                        <th class="fixed-column" style="width: 120px; left: 50px;">姓名</th>
                        <th class="fixed-column" style="width: 100px; left: 170px;">部门</th>
                        <?php
                        // 动态生成日期表头
                        $currentDateGroup = null;
                        foreach ($sortedDates as $index => $date):
                            $dateObj = new DateTime($date);
                            $weekday = $dateObj->format('l');
                            $weekdayMap = [
                                'Monday' => '一', 'Tuesday' => '二', 'Wednesday' => '三',
                                'Thursday' => '四', 'Friday' => '五', 'Saturday' => '六', 'Sunday' => '日'
                            ];
                            $weekdayChar = $weekdayMap[$weekday] ?? '';
                            
                            // 每 7 天添加一个分组边框（周）
                            $isMonday = ($dateObj->format('N') == 1);
                            $dateGroupClass = $isMonday ? 'date-group-header' : '';
                            
                            // 计算该日期显示的列数（基于启用的餐类型）
                            $colspan = 0;
                            if ($mealTypeConfig['breakfast_enabled']) $colspan++;
                            if ($mealTypeConfig['lunch_enabled']) $colspan++;
                            if ($mealTypeConfig['dinner_enabled']) $colspan++;
                            if ($mealTypeConfig['supper_enabled']) $colspan++;
                        ?>
                            <?php if ($colspan > 0): ?>
                                <th colspan="<?php echo $colspan; ?>" class="<?php echo $dateGroupClass; ?>">
                                    <div class="date-label"><?php echo $date; ?> (周<?php echo $weekdayChar; ?>)</div>
                                </th>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th class="fixed-column"></th>
                        <th class="fixed-column"></th>
                        <th class="fixed-column"></th>
                        <?php foreach ($sortedDates as $date): ?>
                            <?php if ($mealTypeConfig['breakfast_enabled']): ?>
                                <th class="meal-type-subheader">早餐</th>
                            <?php endif; ?>
                            <?php if ($mealTypeConfig['lunch_enabled']): ?>
                                <th class="meal-type-subheader">午餐</th>
                            <?php endif; ?>
                            <?php if ($mealTypeConfig['dinner_enabled']): ?>
                                <th class="meal-type-subheader">晚餐</th>
                            <?php endif; ?>
                            <?php if ($mealTypeConfig['supper_enabled']): ?>
                                <th class="meal-type-subheader">宵夜</th>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php $serialNumber = 1; foreach ($personnel as $person): ?>
                        <tr data-personnel-id="<?php echo $person['id']; ?>" 
                            data-personnel-dept="<?php echo explode(',', $person['department_ids'])[0] ?? ''; ?>">
                            <td class="fixed-column"><?php echo $serialNumber++; ?></td>
                            <td class="fixed-column name-td">
                                <?php echo htmlspecialchars($person['name']); ?>
                            </td>
                            <td class="fixed-column department-td">
                                <?php 
                                $departments = explode(', ', $person['departments'] ?? '');
                                echo htmlspecialchars($departments[0] ?? '-');
                                ?>
                            </td>
                            <?php foreach ($sortedDates as $date): ?>
                                <?php
                                // 检查该人员在该日期是否有住宿
                                $hasAccommodation = isset($personnelDateRanges[$person['id']][$date]);
                                ?>
                                <?php if ($mealTypeConfig['breakfast_enabled']): ?>
                                    <td class="meal-cell <?php echo $hasAccommodation ? '' : 'disabled'; ?>">
                                        <?php if ($hasAccommodation): ?>
                                            <input type="checkbox" 
                                                   class="meal-checkbox"
                                                   data-personnel-id="<?php echo $person['id']; ?>"
                                                   data-meal-date="<?php echo $date; ?>"
                                                   data-meal-type="早餐"
                                                   <?php 
                                                   $key = $person['id'] . '_' . $date . '_早餐';
                                                   echo isset($mealReportsMap[$key]) ? 'checked' : '';
                                                   ?>
                                                   onchange="toggleMealSelection(this)">
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <?php if ($mealTypeConfig['lunch_enabled']): ?>
                                    <td class="meal-cell <?php echo $hasAccommodation ? '' : 'disabled'; ?>">
                                        <?php if ($hasAccommodation): ?>
                                            <input type="checkbox" 
                                                   class="meal-checkbox"
                                                   data-personnel-id="<?php echo $person['id']; ?>"
                                                   data-meal-date="<?php echo $date; ?>"
                                                   data-meal-type="午餐"
                                                   <?php 
                                                   $key = $person['id'] . '_' . $date . '_午餐';
                                                   echo isset($mealReportsMap[$key]) ? 'checked' : '';
                                                   ?>
                                                   onchange="toggleMealSelection(this)">
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <?php if ($mealTypeConfig['dinner_enabled']): ?>
                                    <td class="meal-cell <?php echo $hasAccommodation ? '' : 'disabled'; ?>">
                                        <?php if ($hasAccommodation): ?>
                                            <input type="checkbox" 
                                                   class="meal-checkbox"
                                                   data-personnel-id="<?php echo $person['id']; ?>"
                                                   data-meal-date="<?php echo $date; ?>"
                                                   data-meal-type="晚餐"
                                                   <?php 
                                                   $key = $person['id'] . '_' . $date . '_晚餐';
                                                   echo isset($mealReportsMap[$key]) ? 'checked' : '';
                                                   ?>
                                                   onchange="toggleMealSelection(this)">
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <?php if ($mealTypeConfig['supper_enabled']): ?>
                                    <td class="meal-cell <?php echo $hasAccommodation ? '' : 'disabled'; ?>">
                                        <?php if ($hasAccommodation): ?>
                                            <input type="checkbox" 
                                                   class="meal-checkbox"
                                                   data-personnel-id="<?php echo $person['id']; ?>"
                                                   data-meal-date="<?php echo $date; ?>"
                                                   data-meal-type="宵夜"
                                                   <?php 
                                                   $key = $person['id'] . '_' . $date . '_宵夜';
                                                   echo isset($mealReportsMap[$key]) ? 'checked' : '';
                                                   ?>
                                                   onchange="toggleMealSelection(this)">
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

</div><!-- /.mm-page -->

<!-- 加载提示 -->
<div id="loadingToast" class="toast mm-toast bg-primary text-white" role="alert" aria-live="assertive" aria-atomic="true" style="display:none;">
    <div class="toast-body">
        <i class="bi bi-hourglass-split me-2"></i>
        <span id="loadingText">保存中...</span>
    </div>
</div>

<!-- 成功提示 -->
<div id="successToast" class="toast mm-toast bg-success text-white" role="alert" aria-live="assertive" aria-atomic="true" style="display:none;">
    <div class="toast-body">
        <i class="bi bi-check-circle me-2"></i>
        <span id="successText">保存成功</span>
    </div>
</div>

<!-- 错误提示 -->
<div id="errorToast" class="toast mm-toast bg-danger text-white" role="alert" aria-live="assertive" aria-atomic="true" style="display:none;">
    <div class="toast-body">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <span id="errorText">保存失败</span>
    </div>
</div>

<script>
console.log('=== 报餐管理页面脚本已加载 ===');
console.log('版本：2026-03-06-v1');

// 从 PHP 传递配置到 JavaScript
const ENABLED_MEAL_TYPES = [
    <?php if ($mealTypeConfig['breakfast_enabled']) echo "'早餐',"; ?>
    <?php if ($mealTypeConfig['lunch_enabled']) echo "'午餐',"; ?>
    <?php if ($mealTypeConfig['dinner_enabled']) echo "'晚餐',"; ?>
    <?php if ($mealTypeConfig['supper_enabled']) echo "'宵夜',"; ?>
].filter(t => t);

console.log('启用的餐类型:', ENABLED_MEAL_TYPES);

// 防抖函数
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// 显示 Toast 消息
function showToast(type, message) {
    // 安全检查：确保元素存在
    const toast = document.getElementById(type + 'Toast');
    const textSpan = document.getElementById(type + 'Text');
    
    if (!toast || !textSpan) {
        // 如果 Toast 元素不存在，使用 alert
        console.warn('Toast 元素不存在，使用 alert 代替');
        alert(message);
        return;
    }
    
    textSpan.textContent = message;
    toast.style.display = 'block';
    
    setTimeout(() => {
        toast.style.display = 'none';
    }, 2000);
}

// 更新统计数据
function updateStatistics() {
    const selectedCount = document.querySelectorAll('.meal-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = selectedCount;
}

// 单个餐次选择切换
function toggleMealSelection(checkbox) {
    const personnelId = checkbox.dataset.personnelId;
    const mealDate = checkbox.dataset.mealDate;
    const mealType = checkbox.dataset.mealType;
    const isSelected = checkbox.checked;
    
    // 显示加载提示
    showToast('loading', '保存中...');
    
    // 防抖处理，避免频繁请求
    debouncedSaveMealSelection(personnelId, mealDate, mealType, isSelected);
    
    // 更新统计
    updateStatistics();
}

// 保存餐次选择（带防抖）
const debouncedSaveMealSelection = debounce((personnelId, mealDate, mealType, isSelected) => {
    fetch('ajax/save_meal_selection.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            personnel_id: personnelId,
            meal_date: mealDate,
            meal_type: mealType,
            is_selected: isSelected,
            project_id: '<?php echo $projectId; ?>'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('success', '保存成功');
        } else {
            showToast('error', data.message || '保存失败');
            // 恢复复选框状态
            const checkbox = document.querySelector(
                `.meal-checkbox[data-personnel-id="${personnelId}"][data-meal-date="${mealDate}"][data-meal-type="${mealType}"]`
            );
            if (checkbox) {
                checkbox.checked = !isSelected;
            }
        }
    })
    .catch(error => {
        console.error('保存失败:', error);
        showToast('error', '网络错误，请稍后重试');
        // 恢复复选框状态
        const checkbox = document.querySelector(
            `.meal-checkbox[data-personnel-id="${personnelId}"][data-meal-date="${mealDate}"][data-meal-type="${mealType}"]`
        );
        if (checkbox) {
            checkbox.checked = !isSelected;
        }
    });
}, 300);

// 全选功能
function selectAllMeals() {
    if (!confirm('确定要选中所有可用餐次吗？')) return;
    
    document.querySelectorAll('.meal-checkbox:not(:disabled)').forEach(cb => {
        if (!cb.checked) {
            cb.checked = true;
            toggleMealSelection(cb);
        }
    });
    
    updateStatistics();
}

// 取消全选
function deselectAllMeals() {
    if (!confirm('确定要取消所有已选餐次吗？')) return;
    
    document.querySelectorAll('.meal-checkbox:checked').forEach(cb => {
        cb.checked = false;
        toggleMealSelection(cb);
    });
    
    updateStatistics();
}

// 按部门批量报餐（支持日期和餐次选择）
function selectByDepartment() {
    console.log('=== 开始批量报餐 ===');
    
    // 获取选中的部门、日期和餐类型
    const departmentId = document.getElementById('departmentSelect').value;
    const selectedDate = document.getElementById('batchDateInput').value;
    const selectedMealType = document.getElementById('batchMealTypeSelect').value;
    
    console.log('部门 ID:', departmentId);
    console.log('选中日期:', selectedDate || '(所有日期)');
    console.log('选中餐类型:', selectedMealType);
    
    // 验证必填项
    if (!departmentId) {
        alert('请选择部门');
        return;
    }
    
    if (!selectedMealType) {
        alert('请选择餐类型');
        return;
    }
    
    // 获取餐类型的中文名称
    const mealTypeNames = {
        'breakfast': '早餐',
        'lunch': '午餐',
        'dinner': '晚餐',
        'supper': '宵夜'
    };
    
    const mealTypeName = mealTypeNames[selectedMealType];
    
    console.log('准备显示确认框...');
    
    // 使用 setTimeout 确保 UI 更新后显示确认框
    setTimeout(() => {
        let confirmMessage = `确定要为该部门所有人员报餐？\n\n`;
        confirmMessage += `部门：${departmentId}\n`;
        confirmMessage += `餐类型：${mealTypeName}\n`;
        
        if (selectedDate) {
            confirmMessage += `日期：${selectedDate}`;
        } else {
            confirmMessage += `日期：所有日期`;
        }
        
        const confirmed = confirm(confirmMessage);
        
        console.log('用户选择:', confirmed ? '确认' : '取消');
        
        if (!confirmed) {
            return;
        }
        
        // 格式化日期为 YYYY-MM-DD
        const formattedDate = selectedDate;
        
        // 使用中文餐类型名称进行匹配（因为 data-meal-type 存储的是中文）
        const chineseMealType = mealTypeName; // 直接使用中文
        
        // 查找该部门下所有人员的指定日期和餐次的复选框
        let selectedCount = 0;
        
        console.log('开始遍历所有人员行...');
        console.log('目标部门 ID:', departmentId);
        console.log('目标日期:', selectedDate || '(所有日期)');
        console.log('目标餐类型:', chineseMealType);
        
        // 遍历所有行，查找属于该部门的人员
        document.querySelectorAll('tbody tr[data-personnel-id]').forEach((row, index) => {
            const deptAttr = row.getAttribute('data-personnel-dept');
            const personnelId = row.getAttribute('data-personnel-id');
            
            console.log(`\n检查第 ${index + 1} 行:`);
            console.log('  personnelId:', personnelId);
            console.log('  data-personnel-dept:', deptAttr);
            
            // 检查该人员是否属于指定部门（支持多部门，用逗号分隔）
            if (deptAttr) {
                const deptIds = deptAttr.split(',').map(id => id.trim());
                console.log('  拆分后的部门 IDs:', deptIds);
                console.log('  是否包含目标部门:', deptIds.includes(departmentId));
                
                if (deptIds.includes(departmentId)) {
                    console.log('  ✅ 该人员属于目标部门，查找复选框...');
                    
                    // 列出该行所有的复选框，帮助调试
                    const allCheckboxes = row.querySelectorAll('input[type="checkbox"]');
                    console.log(`    该行共有 ${allCheckboxes.length} 个复选框:`);
                    allCheckboxes.forEach((cb, idx) => {
                        const mealDate = cb.getAttribute('data-meal-date');
                        const mealType = cb.getAttribute('data-meal-type');
                        console.log(`      [${idx}] date=${mealDate}, type=${mealType}`);
                    });
                    
        // 根据是否选择了日期来决定查询方式
        if (selectedDate) {
            // 只处理指定日期的复选框
            const checkbox = row.querySelector(`input[type="checkbox"][data-meal-date="${selectedDate}"][data-meal-type="${chineseMealType}"]`);
            
            console.log(`    查询条件：[data-meal-date="${selectedDate}"][data-meal-type="${chineseMealType}"]`);
            console.log(`    查询结果:`, checkbox);
            
            if (checkbox) {
                console.log('    找到复选框:', checkbox);
                console.log('    disabled:', checkbox.disabled);
                console.log('    checked:', checkbox.checked);
                
                if (!checkbox.disabled && !checkbox.checked) {
                    console.log('    ✅ 勾选该复选框');
                    checkbox.checked = true;
                    // 注意：不调用 toggleMealSelection，避免频繁 AJAX 请求
                    // 稍后统一保存
                    selectedCount++;
                } else {
                    console.log('    ⚠️ 复选框已勾选或已禁用，跳过');
                }
            } else {
                console.log('    ❌ 未找到匹配的复选框');
            }
        } else {
            // 处理所有日期的该餐类型复选框
            const allMealTypeCheckboxes = row.querySelectorAll(`input[type="checkbox"][data-meal-type="${chineseMealType}"]`);
            
            console.log(`    查询条件：[data-meal-type="${chineseMealType}"] (所有日期)`);
            console.log(`    找到 ${allMealTypeCheckboxes.length} 个匹配的复选框`);
            
            allMealTypeCheckboxes.forEach(checkbox => {
                const mealDate = checkbox.getAttribute('data-meal-date');
                console.log(`      处理日期 ${mealDate}:`, checkbox);
                
                if (!checkbox.disabled && !checkbox.checked) {
                    console.log('        ✅ 勾选该复选框');
                    checkbox.checked = true;
                    // 注意：不调用 toggleMealSelection，避免频繁 AJAX 请求
                    // 稍后统一保存
                    selectedCount++;
                } else {
                    console.log('        ⚠️ 复选框已勾选或已禁用，跳过');
                }
            });
        }
    });
    
    console.log('\n=== 报餐完成 ===');
    console.log('选中人数:', selectedCount);
    
    // 批量保存所有选中的复选框
    if (selectedCount > 0) {
        saveBatchMeals(departmentId, selectedDate, chineseMealType, selectedCount);
    } else {
            // 详细提示，帮助用户理解为什么没有人被选中
            const message = `ℹ 未找到可报餐的人员\n\n可能原因：\n1. 该部门人员在所选日期没有入住酒店\n2. 所有人员的该餐次已经报过餐了\n3. 该日期不是项目日期范围`;
            alert(message);
        }
        
        // 重置选择
        document.getElementById('departmentSelect').value = '';
        document.getElementById('batchDateInput').value = '';
        document.getElementById('batchMealTypeSelect').value = '';
        
        updateStatistics();
    }, 100);
}

// 批量保存餐次选择
function saveBatchMeals(departmentId, selectedDate, mealType, totalCount) {
    console.log('开始批量保存...');
    console.log('部门 ID:', departmentId);
    console.log('日期:', selectedDate || '所有日期');
    console.log('餐类型:', mealType);
    console.log('总人数:', totalCount);
    
    // 收集所有需要保存的餐次
    const batchData = [];
    
    document.querySelectorAll('tbody tr[data-personnel-id]').forEach(row => {
        const deptAttr = row.getAttribute('data-personnel-dept');
        const personnelId = row.getAttribute('data-personnel-id');
        
        if (deptAttr) {
            const deptIds = deptAttr.split(',').map(id => id.trim());
            
            if (deptIds.includes(departmentId.toString())) {
                // 根据是否选择了日期来决定处理方式
                if (selectedDate) {
                    // 只处理指定日期的复选框
                    const checkbox = row.querySelector(`input[type="checkbox"][data-meal-date="${selectedDate}"][data-meal-type="${mealType}"]`);
                    
                    if (checkbox && checkbox.checked) {
                        batchData.push({
                            personnel_id: personnelId,
                            meal_date: selectedDate,
                            meal_type: mealType
                        });
                    }
                } else {
                    // 处理所有日期的该餐类型复选框
                    row.querySelectorAll(`input[type="checkbox"][data-meal-type="${mealType}"]`).forEach(checkbox => {
                        if (checkbox.checked) {
                            batchData.push({
                                personnel_id: personnelId,
                                meal_date: checkbox.getAttribute('data-meal-date'),
                                meal_type: mealType
                            });
                        }
                    });
                }
            }
        }
    });
    
    console.log('收集到的待保存数据:', batchData);
    console.log('待保存记录数:', batchData.length);
    
    // 显示加载提示
    showToast('loading', `正在保存 ${batchData.length} 条记录...`);
    
    // 批量发送到服务器
    fetch('ajax/batch_save_meal_selection.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            records: batchData,
            project_id: '<?php echo $projectId; ?>'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('批量保存成功:', data);
            showToast('success', `✓ 已保存 ${data.saved_count || batchData.length} 条记录`);
            
            // 更新统计数据
            updateStatistics();
        } else {
            console.error('批量保存失败:', data.message);
            showToast('error', data.message || '批量保存失败，请重试');
        }
    })
    .catch(error => {
        console.error('批量保存错误:', error);
        showToast('error', '网络错误，请稍后重试');
    });
}

// 页面加载完成后初始化
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM 加载完成');
    
    // 初始化统计数据
    updateStatistics();
    
    // 为表格添加横向滚动优化
    const tableContainer = document.querySelector('.mm-table-container');
    if (tableContainer) {
        tableContainer.addEventListener('scroll', function() {
            // 可以在这里添加滚动时的优化逻辑
        });
    }
});
</script>

<?php include 'includes/footer.php'; ?>
