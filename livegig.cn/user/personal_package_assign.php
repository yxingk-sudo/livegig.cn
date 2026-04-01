<?php
// 个人套餐分配页面 - 为每个人分配每天每餐的具体套餐
// 功能：基于已配置的套餐，为项目中的每个人分配每天每餐的套餐
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

// 获取每个人的酒店入住记录，计算入住日期范围
$allDates = [];
$query = "SELECT DISTINCT check_in_date as date FROM hotel_reports WHERE project_id = :project_id
          UNION SELECT DISTINCT check_out_date as date FROM hotel_reports WHERE project_id = :project_id
          ORDER BY date";
$stmt = $db->prepare($query);
$stmt->bindParam(':project_id', $projectId);
$stmt->execute();
$dateRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$allDates = array_column($dateRows, 'date');

// 对所有日期进行排序
sort($allDates);

// 根据选中的日期过滤
if (!empty($selectedMealDates)) {
    $filteredDates = [];
    foreach ($allDates as $date) {
        if (in_array($date, $selectedMealDates)) {
            $filteredDates[$date] = true;
        }
    }
    $allDates = array_keys($filteredDates);
}

// 获取所有可用套餐（只获取启用的套餐）
$packagesQuery = "SELECT * FROM meal_packages 
                   WHERE project_id = :project_id 
                   AND is_active = 1 
                   ORDER BY meal_type, id";
$packagesStmt = $db->prepare($packagesQuery);
$packagesStmt->bindParam(':project_id', $projectId);
$packagesStmt->execute();
$allPackages = $packagesStmt->fetchAll(PDO::FETCH_ASSOC);

// 按餐类型分组套餐
$packagesByType = [];
foreach ($allPackages as $pkg) {
    $type = $pkg['meal_type'];
    if (!isset($packagesByType[$type])) {
        $packagesByType[$type] = [];
    }
    $packagesByType[$type][] = $pkg;
}

// 获取所有人员及其住宿记录（通过 hotel_reports 关联项目）
// 按部门排序：使用 project_department_personnel 和 departments 表，按 sort_order 排序
$personnelQuery = "SELECT DISTINCT 
                       p.id,
                       p.name,
                       hr.check_in_date,
                       hr.check_out_date,
                       hr.room_number,
                       GROUP_CONCAT(DISTINCT d.name ORDER BY d.sort_order ASC, d.name SEPARATOR ', ') as department_names,
                       GROUP_CONCAT(DISTINCT d.id ORDER BY d.sort_order ASC, d.name SEPARATOR ',') as department_ids,
                       MIN(d.sort_order) as min_sort_order
                   FROM personnel p
                   INNER JOIN hotel_reports hr ON p.id = hr.personnel_id
                   INNER JOIN project_department_personnel pdp ON p.id = pdp.personnel_id
                   LEFT JOIN departments d ON pdp.department_id = d.id
                   WHERE hr.project_id = :project_id
                   AND pdp.project_id = :project_id
                   GROUP BY p.id, p.name, hr.check_in_date, hr.check_out_date, hr.room_number
                   ORDER BY MIN(d.sort_order) ASC, p.name ASC";
$personnelStmt = $db->prepare($personnelQuery);
$personnelStmt->bindParam(':project_id', $projectId);
$personnelStmt->execute();
$allPersonnel = $personnelStmt->fetchAll(PDO::FETCH_ASSOC);

// 获取已分配的个人套餐（包含日期维度）
$personalAssignments = [];
$mealReports = []; // 每个人的报餐记录
if (!empty($allDates) && !empty($allPersonnel)) {
    $datePlaceholders = str_repeat('?,', count($allDates) - 1) . '?';
    $personnelIds = array_column($allPersonnel, 'id');
    $personnelPlaceholders = str_repeat('?,', count($personnelIds) - 1) . '?';
    
    // 获取已分配的套餐
    $assignQuery = "SELECT mrd.personnel_id, mr.meal_date, mr.meal_type, mrd.package_id 
                    FROM meal_report_details mrd
                    INNER JOIN meal_reports mr ON mrd.report_id = mr.id
                    WHERE mr.project_id = ? 
                    AND mr.meal_date IN ($datePlaceholders)
                    AND mrd.personnel_id IN ($personnelPlaceholders)";
    
    $params = array_merge([$projectId], $allDates, $personnelIds);
    $assignStmt = $db->prepare($assignQuery);
    $assignStmt->execute($params);
    $assignments = $assignStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($assignments as $assign) {
        $key = $assign['personnel_id'] . '_' . $assign['meal_date'] . '_' . $assign['meal_type'];
        $personalAssignments[$key] = $assign['package_id'];
    }
    
    // 获取每个人的报餐记录（按人员分开）
    $mealReportQuery = "SELECT DISTINCT mr.personnel_id, mr.meal_date, mr.meal_type 
                        FROM meal_reports mr
                        WHERE mr.project_id = ? 
                        AND mr.meal_date IN ($datePlaceholders)
                        AND mr.personnel_id IN ($personnelPlaceholders)
                        AND mr.status IN ('pending', 'confirmed')";
    
    $mealReportStmt = $db->prepare($mealReportQuery);
    $mealReportStmt->execute($params);
    $mealReportRows = $mealReportStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($mealReportRows as $row) {
        $key = $row['personnel_id'] . '_' . $row['meal_date'] . '_' . $row['meal_type'];
        $mealReports[$key] = true;
    }
}

// 设置页面特定变量
$page_title = '个人套餐分配 - ' . ($_SESSION['project_name'] ?? '项目');
$active_page = 'personal_package_assign';
$show_page_title = '个人套餐分配';
$page_icon = 'people';

// 包含统一头部文件
include 'includes/header.php';
?>

<style>
/* ===== Espire 个人套餐分配页面样式 v1.0 ===== */
.ppca-page { font-size: 1rem; }

/* 顶部标题区 */
.ppca-hero {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    border-radius: 14px;
    padding: 1.75rem 2rem;
    color: #fff;
    margin-bottom: 1.75rem;
    box-shadow: 0 6px 24px rgba(245,87,108,.35);
}
.ppca-hero-title { font-size: 1.55rem; font-weight: 700; margin-bottom: .25rem; }
.ppca-hero-sub   { font-size: 1rem; opacity: .9; }
.ppca-back-link {
    font-size: .95rem; padding: .45rem 1.1rem; border-radius: 8px;
    background: rgba(255,255,255,.18); color: #fff; border: 1px solid rgba(255,255,255,.4);
}
.ppca-back-link:hover { background: rgba(255,255,255,.25); color: #fff; text-decoration: none; }

/* 控制区域 */
.ppca-controls-card {
    border: none;
    border-radius: 14px;
    box-shadow: 0 2px 14px rgba(0,0,0,.08);
    margin-bottom: 1.5rem;
}
.ppca-controls-card .card-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-bottom: 2px solid #dee2e6;
    padding: .9rem 1.25rem;
    font-size: 1.05rem;
    font-weight: 600;
    color: #495057;
}

/* 表格容器 */
.ppca-table-container {
    border: none;
    border-radius: 14px;
    box-shadow: 0 2px 14px rgba(0,0,0,.08);
    overflow-x: auto;
    background: #fff;
}

/* 表格样式 */
.personal-assign-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    min-width: 1600px;
}

.personal-assign-table th {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-bottom: 2px solid #dee2e6;
    border-right: 1px solid #e9ecef;
    padding: 0.75rem 0.5rem;
    text-align: center;
    font-weight: 600;
    color: #495057;
    font-size: 0.85rem;
    position: sticky;
    top: 0;
    z-index: 10;
    white-space: nowrap;
}

/* 固定列 */
.personal-assign-table .fixed-column {
    position: sticky;
    left: 0;
    background: #fff;
    z-index: 5;
    box-shadow: 2px 0 5px rgba(0,0,0,0.05);
}

.personal-assign-table th.fixed-column {
    z-index: 15;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
}

/* 日期表头样式 */
.date-header {
    background: linear-gradient(135deg, rgba(245,87,108, 0.1) 0%, rgba(240,147,251, 0.1) 100%) !important;
    border-left: 2px solid #f5576c !important;
    border-right: 2px solid #f5576c !important;
}

.date-label {
    font-size: 0.85rem;
    font-weight: 700;
    color: #f5576c;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* 餐类型子表头 */
.meal-subheader {
    background: #f8f9fa;
    font-size: 0.75rem;
    color: #6c757d;
    padding: 0.4rem 0.2rem;
    border-right: 1px solid #dee2e6;
    font-weight: 600;
}

.meal-subheader:last-child {
    border-right: none;
}

/* 人员信息列 */
.person-info {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    text-align: left;
}
.person-name {
    font-weight: 600;
    color: #212529;
}
.person-room {
    font-size: 0.75rem;
    color: #6c757d;
}

/* 单元格样式 */
.package-cell {
    text-align: center;
    padding: 0.5rem 0.3rem;
    border-bottom: 1px solid #e9ecef;
    border-right: 1px solid #dee2e6;
    min-width: 140px;
    vertical-align: middle;
}

.package-cell:last-child {
    border-right: none;
}

/* 套餐选择器 */
.package-select {
    width: 100%;
    font-size: 0.8rem;
    padding: 0.3rem 0.2rem;
    border: 2px solid #f5576c; /* 醒目的粉色边框 */
    border-radius: 4px;
    background: #fff5f6; /* 淡粉色背景 */
    cursor: pointer;
    transition: all 0.2s;
    font-weight: 500;
}

.package-select:hover {
    border-color: #f093fb;
    box-shadow: 0 0 0 0.2rem rgba(240, 147, 251, 0.2);
    background: #fff0f3;
}

.package-select:focus {
    outline: none;
    border-color: #f5576c;
    box-shadow: 0 0 0 0.25rem rgba(245, 87, 108, 0.25);
}
    outline: none;
    border-color: #f5576c;
    box-shadow: 0 0 0 0.2rem rgba(245,87,108,0.15);
}

.package-select option {
    padding: 0.4rem;
}

/* 统计卡片 */
.ppca-stat-card {
    background: #fff;
    border-radius: 12px;
    padding: 1.25rem;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
    text-align: center;
}
.ppca-stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: #f5576c;
    margin-bottom: 0.25rem;
}
.ppca-stat-label {
    font-size: 0.9rem;
    color: #6c757d;
}

/* 按钮样式 */
.ppca-btn {
    font-size: 0.95rem;
    padding: 0.5rem 1.2rem;
    border-radius: 8px;
    font-weight: 500;
    transition: all 0.3s;
}
.ppca-btn-primary {
    background: linear-gradient(135deg, #f093fb, #f5576c);
    border: none;
    color: #fff;
}
.ppca-btn-primary:hover {
    opacity: 0.9;
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(245,87,108,.3);
}
.ppca-btn-secondary {
    background: #6c757d;
    border: none;
    color: #fff;
}
.ppca-btn-secondary:hover {
    background: #5a6268;
    color: #fff;
}

/* Toast 消息 */
.ppca-toast {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    min-width: 300px;
    animation: slideInRight 0.3s ease-out;
}
@keyframes slideInRight {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

/* 响应式设计 */
@media (max-width: 768px) {
    .ppca-hero { padding: 1.25rem; }
    .ppca-hero-title { font-size: 1.25rem; }
    .personal-assign-table { font-size: 0.75rem; }
}
</style>

<div class="ppca-page">

<!-- 英雄区 -->
<div class="ppca-hero">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <div class="ppca-hero-title">个人套餐分配</div>
            <div class="ppca-hero-sub">
                <i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($_SESSION['project_name'] ?? '项目'); ?>
            </div>
        </div>
        <a href="meal_management.php" class="ppca-back-link">
            <i class="bi bi-arrow-left me-1"></i>返回报餐管理
        </a>
    </div>
</div>

<!-- 控制区域 -->
<div class="card ppca-controls-card">
    <div class="card-header">
        <i class="bi bi-sliders text-primary me-2"></i>操作控制
    </div>
    <div class="card-body">
        <div class="row g-3 align-items-center">
            <div class="col-md-6">
                <div class="d-flex gap-2 flex-wrap">
                    <button type="button" class="btn ppca-btn ppca-btn-primary" onclick="saveAllAssignments()">
                        <i class="bi bi-check-circle me-1"></i>保存所有分配
                    </button>
                </div>
            </div>
            <div class="col-md-6">
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <label class="form-label mb-0">按部门批量分配：</label>
                    <select class="form-select form-select-sm" id="batchDepartmentSelect" style="max-width: 200px;">
                        <option value="">选择部门...</option>
                        <?php
                        // 获取部门列表
                        $dept_stmt = $db->prepare("SELECT DISTINCT d.id, d.name FROM departments d INNER JOIN project_department_personnel pdp ON d.id = pdp.department_id WHERE pdp.project_id = ? ORDER BY d.sort_order ASC, d.name");
                        $dept_stmt->execute([$projectId]);
                        $departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($departments as $dept):
                        ?>
                            <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="form-select form-select-sm" id="batchDateSelect" style="max-width: 200px;">
                        <option value="">选择日期...</option>
                        <?php foreach ($allDates as $date): ?>
                            <option value="<?php echo $date; ?>"><?php echo $date; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select class="form-select form-select-sm" id="batchPackageSelect" style="max-width: 200px;">
                        <option value="">选择套餐...</option>
                        <?php foreach ($allPackages as $pkg): ?>
                            <option value="<?php echo $pkg['id']; ?>" data-meal-type="<?php echo $pkg['meal_type']; ?>">
                                <?php echo htmlspecialchars($pkg['name']); ?> (<?php echo $pkg['meal_type']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn ppca-btn ppca-btn-primary btn-sm" onclick="batchAssignByDepartment()">
                        <i class="bi bi-person-check me-1"></i>批量分配
                    </button>
                    <button type="button" class="btn ppca-btn ppca-btn-success btn-lg" onclick="saveBatchAssignment()" title="保存批量分配的结果">
                        <i class="bi bi-save"></i> 保存批量分配
                    </button>
                </div>
            </div>
        </div>
        <div class="row g-2 mt-3">
            <div class="col-md-3">
                <div class="ppca-stat-card">
                    <div class="ppca-stat-value" id="totalPersons"><?php echo count($allPersonnel); ?></div>
                    <div class="ppca-stat-label">总人数</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="ppca-stat-card">
                    <div class="ppca-stat-value" id="totalDates"><?php echo count($allDates); ?></div>
                    <div class="ppca-stat-label">总天数</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="ppca-stat-card">
                    <div class="ppca-stat-value" id="unassignedCount">0</div>
                    <div class="ppca-stat-label">未分配</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="ppca-stat-card">
                    <div class="ppca-stat-value" id="assignedCount">0</div>
                    <div class="ppca-stat-label">已分配</div>
                </div>
            </div>
        </div>
        <?php if (empty($allPackages)): ?>
        <div class="mt-3">
            <div class="alert alert-warning mb-0">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <strong>提示：</strong>暂无可用套餐，请先在后台 <a href="../admin/meal_packages.php?project_id=<?php echo $projectId; ?>" target="_blank">套餐管理</a> 中添加套餐。
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 表格区域 -->
<div class="card ppca-table-container">
    <div class="card-body p-0">
        <?php if (empty($allPersonnel)): ?>
            <div class="text-center py-5">
                <i class="bi bi-info-circle display-1 text-muted"></i>
                <p class="mt-3 text-muted">暂无人员记录，无法显示分配表格</p>
            </div>
        <?php elseif (empty($allDates)): ?>
            <div class="text-center py-5">
                <i class="bi bi-calendar display-1 text-muted"></i>
                <p class="mt-3 text-muted">暂无住宿记录，无法显示日期</p>
            </div>
        <?php elseif (empty($allPackages)): ?>
            <div class="text-center py-5">
                <i class="bi bi-basket display-1 text-muted"></i>
                <p class="mt-3 text-muted">暂无可用套餐，请先在后台添加套餐</p>
            </div>
        <?php else: ?>
            <table class="personal-assign-table" id="personalAssignTable">
                <thead>
                    <tr>
                        <th class="fixed-column" style="width: 200px;">人员信息</th>
                        <?php
                        // 动态生成日期和餐类型表头
                        foreach ($allDates as $dateIndex => $date):
                            $dateObj = new DateTime($date);
                            $weekday = $dateObj->format('l');
                            $weekdayMap = [
                                'Monday' => '一', 'Tuesday' => '二', 'Wednesday' => '三',
                                'Thursday' => '四', 'Friday' => '五', 'Saturday' => '六', 'Sunday' => '日'
                            ];
                            $weekdayChar = $weekdayMap[$weekday] ?? '';
                            
                            // 计算 colspan（启用的餐类型数量）
                            $mealColspan = 0;
                            if ($mealTypeConfig['breakfast_enabled']) $mealColspan++;
                            if ($mealTypeConfig['lunch_enabled']) $mealColspan++;
                            if ($mealTypeConfig['dinner_enabled']) $mealColspan++;
                            if ($mealTypeConfig['supper_enabled']) $mealColspan++;
                        ?>
                            <th colspan="<?php echo $mealColspan; ?>" class="date-header" data-date-index="<?php echo $dateIndex; ?>">
                                <div class="date-label"><?php echo $date; ?> (周<?php echo $weekdayChar; ?>)</div>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th class="fixed-column"></th>
                        <?php foreach ($allDates as $date): ?>
                            <?php if ($mealTypeConfig['breakfast_enabled']): ?>
                                <th class="meal-subheader">早</th>
                            <?php endif; ?>
                            <?php if ($mealTypeConfig['lunch_enabled']): ?>
                                <th class="meal-subheader">午</th>
                            <?php endif; ?>
                            <?php if ($mealTypeConfig['dinner_enabled']): ?>
                                <th class="meal-subheader">晚</th>
                            <?php endif; ?>
                            <?php if ($mealTypeConfig['supper_enabled']): ?>
                                <th class="meal-subheader">宵</th>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    foreach ($allPersonnel as $person):
                        $personId = $person['id'];
                        $personName = htmlspecialchars($person['name']);
                        $roomNumber = $person['room_number'] ? htmlspecialchars($person['room_number']) : '';
                        $departmentIds = $person['department_ids'] ?? '';
                    ?>
                        <tr data-personnel-id="<?php echo $personId; ?>" data-personnel-dept="<?php echo $departmentIds; ?>">
                            <td class="fixed-column">
                                <div class="person-info">
                                    <div class="person-name">
                                        <i class="bi bi-person-circle text-primary me-1"></i>
                                        <?php echo $personName; ?>
                                    </div>
                                    <?php if ($roomNumber): ?>
                                    <div class="person-room">
                                        <i class="bi bi-door-closed me-1"></i><?php echo $roomNumber; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <?php 
                            // 为每个日期的每个餐类型生成单元格
                            foreach ($allDates as $dateIndex => $date):
                                if ($mealTypeConfig['breakfast_enabled']):
                                    // 检查该人员在该日期和餐次是否有报餐
                                    $mealKey = $personId . '_' . $date . '_早餐';
                                    $hasMealReport = isset($mealReports[$mealKey]);
                                    ?>
                                    <td class="package-cell" data-meal-type="早餐" data-date="<?php echo $date; ?>" data-date-index="<?php echo $dateIndex; ?>">
                                        <?php if ($hasMealReport): ?>
                                            <?php
                                            $key = $personId . '_' . $date . '_早餐';
                                            $selectedPackageId = $personalAssignments[$key] ?? '';
                                            ?>
                                            <select class="package-select" 
                                                    data-personnel-id="<?php echo $personId; ?>" 
                                                    data-meal-date="<?php echo $date; ?>"
                                                    data-meal-type="早餐"
                                                    onchange="markAsChanged(this)">
                                                <option value="">请选择</option>
                                                <?php
                                                if (isset($packagesByType['早餐']) && !empty($packagesByType['早餐'])):
                                                    foreach ($packagesByType['早餐'] as $pkg):
                                                        $isSelected = ($selectedPackageId == $pkg['id']);
                                                ?>
                                                    <option value="<?php echo $pkg['id']; ?>" 
                                                            data-package-name="<?php echo htmlspecialchars($pkg['name']); ?>"
                                                            <?php echo $isSelected ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($pkg['name']); ?>
                                                    </option>
                                                <?php
                                                    endforeach;
                                                else:
                                                ?>
                                                    <option value="" disabled>暂无可用套餐</option>
                                                <?php endif; ?>
                                            </select>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size: 12px;">未报餐</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; 
                                if ($mealTypeConfig['lunch_enabled']):
                                    // 检查该人员在该日期和餐次是否有报餐
                                    $mealKey = $personId . '_' . $date . '_午餐';
                                    $hasMealReport = isset($mealReports[$mealKey]);
                                    ?>
                                    <td class="package-cell" data-meal-type="午餐" data-date="<?php echo $date; ?>" data-date-index="<?php echo $dateIndex; ?>">
                                        <?php if ($hasMealReport): ?>
                                            <?php
                                            $key = $personId . '_' . $date . '_午餐';
                                            $selectedPackageId = $personalAssignments[$key] ?? '';
                                            ?>
                                            <select class="package-select" 
                                                    data-personnel-id="<?php echo $personId; ?>" 
                                                    data-meal-date="<?php echo $date; ?>"
                                                    data-meal-type="午餐"
                                                    onchange="markAsChanged(this)">
                                                <option value="">请选择</option>
                                                <?php
                                                if (isset($packagesByType['午餐']) && !empty($packagesByType['午餐'])):
                                                    foreach ($packagesByType['午餐'] as $pkg):
                                                        $isSelected = ($selectedPackageId == $pkg['id']);
                                                ?>
                                                    <option value="<?php echo $pkg['id']; ?>" 
                                                            data-package-name="<?php echo htmlspecialchars($pkg['name']); ?>"
                                                            <?php echo $isSelected ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($pkg['name']); ?>
                                                    </option>
                                                <?php
                                                    endforeach;
                                                else:
                                                ?>
                                                    <option value="" disabled>暂无可用套餐</option>
                                                <?php endif; ?>
                                            </select>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size: 12px;">未报餐</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; 
                                if ($mealTypeConfig['dinner_enabled']):
                                    // 检查该人员在该日期和餐次是否有报餐
                                    $mealKey = $personId . '_' . $date . '_晚餐';
                                    $hasMealReport = isset($mealReports[$mealKey]);
                                    ?>
                                    <td class="package-cell" data-meal-type="晚餐" data-date="<?php echo $date; ?>" data-date-index="<?php echo $dateIndex; ?>">
                                        <?php if ($hasMealReport): ?>
                                            <?php
                                            $key = $personId . '_' . $date . '_晚餐';
                                            $selectedPackageId = $personalAssignments[$key] ?? '';
                                            ?>
                                            <select class="package-select" 
                                                    data-personnel-id="<?php echo $personId; ?>" 
                                                    data-meal-date="<?php echo $date; ?>"
                                                    data-meal-type="晚餐"
                                                    onchange="markAsChanged(this)">
                                                <option value="">请选择</option>
                                                <?php
                                                if (isset($packagesByType['晚餐']) && !empty($packagesByType['晚餐'])):
                                                    foreach ($packagesByType['晚餐'] as $pkg):
                                                        $isSelected = ($selectedPackageId == $pkg['id']);
                                                ?>
                                                    <option value="<?php echo $pkg['id']; ?>" 
                                                            data-package-name="<?php echo htmlspecialchars($pkg['name']); ?>"
                                                            <?php echo $isSelected ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($pkg['name']); ?>
                                                    </option>
                                                <?php
                                                    endforeach;
                                                else:
                                                ?>
                                                    <option value="" disabled>暂无可用套餐</option>
                                                <?php endif; ?>
                                            </select>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size: 12px;">未报餐</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; 
                                if ($mealTypeConfig['supper_enabled']): ?>
                                    <td class="package-cell" data-meal-type="宵夜" data-date="<?php echo $date; ?>" data-date-index="<?php echo $dateIndex; ?>">
                                        <?php
                                        $key = $personId . '_' . $date . '_宵夜';
                                        $selectedPackageId = $personalAssignments[$key] ?? '';
                                        ?>
                                        <select class="package-select" 
                                                data-personnel-id="<?php echo $personId; ?>" 
                                                data-meal-date="<?php echo $date; ?>"
                                                data-meal-type="宵夜"
                                                onchange="markAsChanged(this)">
                                            <option value="">请选择</option>
                                            <?php
                                            if (isset($packagesByType['宵夜']) && !empty($packagesByType['宵夜'])):
                                                foreach ($packagesByType['宵夜'] as $pkg):
                                                    $isSelected = ($selectedPackageId == $pkg['id']);
                                            ?>
                                                <option value="<?php echo $pkg['id']; ?>" 
                                                        data-package-name="<?php echo htmlspecialchars($pkg['name']); ?>"
                                                        <?php echo $isSelected ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($pkg['name']); ?>
                                                </option>
                                            <?php
                                                endforeach;
                                            else:
                                            ?>
                                                <option value="" disabled>暂无可用套餐</option>
                                            <?php endif; ?>
                                        </select>
                                    </td>
                                <?php endif; 
                            endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

</div><!-- /.ppca-page -->

<!-- 加载提示 -->
<div id="loadingToast" class="toast ppca-toast bg-primary text-white" role="alert" aria-live="assertive" aria-atomic="true" style="display:none;">
    <div class="toast-body">
        <i class="bi bi-hourglass-split me-2"></i>
        <span id="loadingText">保存中...</span>
    </div>
</div>

<!-- 成功提示 -->
<div id="successToast" class="toast ppca-toast bg-success text-white" role="alert" aria-live="assertive" aria-atomic="true" style="display:none;">
    <div class="toast-body">
        <i class="bi bi-check-circle me-2"></i>
        <span id="successText">保存成功</span>
    </div>
</div>

<!-- 错误提示 -->
<div id="errorToast" class="toast ppca-toast bg-danger text-white" role="alert" aria-live="assertive" aria-atomic="true" style="display:none;">
    <div class="toast-body">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <span id="errorText">保存失败</span>
    </div>
</div>

<script>
console.log('=== 个人套餐分配页面脚本已加载 ===');
console.log('版本：2026-03-06-v1 (下拉菜单版)');

// 标记为已修改
let hasChanges = false;

// 自动保存定时器
let autoSaveTimer = null;

function markAsChanged(select) {
    hasChanges = true;
    updateStatistics();
    
    // 触发自动保存：1 秒后自动保存
    triggerAutoSave();
}

// 更新统计数据
function updateStatistics() {
    let assignedCount = 0;
    let unassignedCount = 0;
    
    document.querySelectorAll('.package-select').forEach(sel => {
        if (sel.value !== '' && sel.value !== '0') {
            assignedCount++;
        } else {
            unassignedCount++;
        }
    });
    
    document.getElementById('assignedCount').textContent = assignedCount;
    document.getElementById('unassignedCount').textContent = unassignedCount;
}

// 保存所有分配
function saveAllAssignments(silentMode = false) {
    if (!hasChanges) {
        if (!silentMode) {
            showToast('info', '没有需要保存的更改');
        }
        return;
    }
    
    if (!silentMode && !confirm('确定要保存所有套餐分配吗？')) return;
    
    // 收集所有分配数据
    const assignments = [];
    
    // 遍历所有下拉菜单
    document.querySelectorAll('.package-select').forEach(select => {
        const personnelId = parseInt(select.dataset.personnelId);
        const mealDate = select.dataset.mealDate;
        const mealType = select.dataset.mealType;
        const packageId = select.value ? parseInt(select.value) : 0;
        
        console.log(`检查下拉菜单：人员=${personnelId}, 日期=${mealDate}, 餐类型=${mealType}, 套餐 ID=${packageId}`);
        
        if (packageId > 0) {
            const optionElement = select.querySelector(`option[value="${packageId}"]`);
            if (optionElement) {
                assignments.push({
                    personnel_id: personnelId,
                    meal_date: mealDate,
                    meal_type: mealType,
                    package_id: packageId,
                    package_name: optionElement.dataset.packageName || ''
                });
            }
        }
    });
    
    console.log(`收集到 ${assignments.length} 条待保存记录`);
    
    if (assignments.length === 0) {
        showToast('info', '没有分配任何套餐');
        return;
    }
    
    // 显示加载提示
    showToast('loading', '保存中...');
    
    // 发送到服务器
    fetch('ajax/save_personal_package_assignment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            project_id: '<?php echo $projectId; ?>',
            assignments: assignments
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (!silentMode) {
                showToast('success', '保存成功！共保存 ' + data.data.saved_count + ' 条记录');
            } else {
                // 静默保存模式：只显示一个小提示
                console.log('自动保存成功：' + data.data.saved_count + ' 条记录');
                // 可以在页面角落显示一个小的保存状态指示器
                showAutoSaveIndicator(data.data.saved_count);
            }
            hasChanges = false;
            updateStatistics();
        } else {
            if (!silentMode) {
                showToast('error', data.message || '保存失败');
            } else {
                console.error('自动保存失败:', data.message);
            }
        }
    })
    .catch(error => {
        console.error('保存失败:', error);
        if (!silentMode) {
            showToast('error', '网络错误，请稍后重试');
        } else {
            console.error('自动保存时发生错误:', error);
        }
    });
}

// 触发自动保存：1 秒后执行，避免频繁保存
function triggerAutoSave() {
    // 清除之前的定时器
    if (autoSaveTimer) {
        clearTimeout(autoSaveTimer);
    }
    
    // 1 秒后自动保存
    autoSaveTimer = setTimeout(function() {
        if (hasChanges) {
            console.log('=== 自动保存开始 ===');
            saveAllAssignments(true); // true 表示静默保存
        } else {
            console.log('没有更改，跳过自动保存');
        }
    }, 1000);
}

// 显示自动保存状态指示器（更大更明显）
function showAutoSaveIndicator(savedCount) {
    // 创建一个大的、显眼的状态指示器
    let indicator = document.getElementById('autoSaveIndicator');
    if (!indicator) {
        indicator = document.createElement('div');
        indicator.id = 'autoSaveIndicator';
        indicator.style.cssText = `
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 20px 30px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(40, 167, 69, 0.4);
            z-index: 9999;
            font-size: 18px;
            font-weight: bold;
            display: none;
            animation: slideInRight 0.3s ease-out;
        `;
        indicator.innerHTML = '<i class="bi bi-check-circle-fill" style="font-size: 24px; vertical-align: middle;"></i> <span></span>';
        document.body.appendChild(indicator);
        
        // 添加动画样式
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideInRight {
                from { transform: translateX(400px); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes fadeOutUp {
                from { transform: translateY(0); opacity: 1; }
                to { transform: translateY(-20px); opacity: 0; }
            }
        `;
        document.head.appendChild(style);
    }
    
    indicator.querySelector('span').textContent = `已自动保存 ${savedCount} 条记录`;
    indicator.style.display = 'block';
    
    // 3 秒后隐藏
    setTimeout(() => {
        indicator.style.animation = 'fadeOutUp 0.3s ease-out';
        setTimeout(() => {
            indicator.style.display = 'none';
        }, 300);
    }, 3000);
}

// 复制前一天的分配（简化版本，实际需要更复杂的逻辑）
function copyFromPreviousDay() {
    showToast('info', '此功能开发中，敬请期待！');
}

// 按部门批量分配套餐
function batchAssignByDepartment() {
    const deptSelect = document.getElementById('batchDepartmentSelect');
    const dateSelect = document.getElementById('batchDateSelect');
    const pkgSelect = document.getElementById('batchPackageSelect');
    const departmentId = deptSelect.value;
    const selectedDate = dateSelect.value;
    const packageId = pkgSelect.value;
    
    if (!departmentId) {
        showToast('error', '请选择部门');
        return;
    }
    
    if (!selectedDate) {
        showToast('error', '请选择日期');
        return;
    }
    
    if (!packageId) {
        showToast('error', '请选择套餐');
        return;
    }
    
    // 获取选中的套餐信息
    const selectedOption = pkgSelect.options[pkgSelect.selectedIndex];
    const mealType = selectedOption.dataset.mealType;
    const packageName = selectedOption.text;
    
    if (!confirm(`确定要为该部门下所有人员批量分配套餐吗？\n\n部门：${deptSelect.options[deptSelect.selectedIndex].text}\n日期：${selectedDate}\n套餐：${packageName}\n餐类型：${mealType}`)) {
        return;
    }
    
    // 查找该部门下的所有人员
    let assignedCount = 0;
    let changedSelects = []; // 收集被修改的下拉菜单
    
    console.log('=== 批量分配开始 ===');
    console.log(`部门 ID: ${departmentId}, 日期：${selectedDate}, 餐类型：${mealType}, 套餐 ID: ${packageId}`);
    
    document.querySelectorAll('[data-personnel-dept]').forEach(row => {
        const personnelDepts = row.dataset.personnelDept.split(',');
        if (personnelDepts.includes(departmentId)) {
            // 找到该人员指定日期的该餐类型的下拉菜单
            const selects = row.querySelectorAll(`.package-select[data-meal-type="${mealType}"][data-meal-date="${selectedDate}"]`);
            console.log(`找到 ${selects.length} 个匹配的下拉菜单`);
            
            selects.forEach(select => {
                const oldValue = select.value;
                select.value = packageId;
                const newValue = select.value;
                console.log(`设置下拉菜单：旧值=${oldValue}, 新值=${newValue}`);
                
                changedSelects.push(select); // 收集起来
                assignedCount++;
            });
        }
    });
    
    console.log(`总共修改了 ${assignedCount} 个下拉菜单`);
    console.log('=== 批量分配完成 ===');
    
    // 统一标记为已修改，只触发一次自动保存
    changedSelects.forEach(select => {
        markAsChanged(select);
    });
    
    if (assignedCount > 0) {
        showToast('success', `已为 ${assignedCount} 个餐次分配套餐，请点击“保存批量分配”按钮保存到数据库`);
    } else {
        showToast('info', '该部门下没有人员需要分配');
    }
    
    // 重置选择
    deptSelect.value = '';
    dateSelect.value = '';
    pkgSelect.value = '';
}

// 保存批量分配的结果
function saveBatchAssignment() {
    if (!hasChanges) {
        showToast('info', '没有需要保存的更改，请先使用批量分配功能');
        return;
    }
    
    // 调用统一的保存函数
    saveAllAssignments();
}

// 显示 Toast 消息
function showToast(type, message) {
    const toast = document.getElementById(type + 'Toast');
    const textSpan = document.getElementById(type + 'Text');
    textSpan.textContent = message;
    toast.style.display = 'block';
    
    setTimeout(() => {
        toast.style.display = 'none';
    }, 2000);
}

// 页面卸载前检查
window.addEventListener('beforeunload', function(e) {
    if (hasChanges) {
        e.preventDefault();
        e.returnValue = '';
        return '';
    }
});

// 页面加载完成后初始化
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM 加载完成');
    
    // 初始化统计数据
    updateStatistics();
    
    // 初始化所有下拉菜单的值（如果有已保存的数据）
    // 这里需要从服务器加载已保存的数据并设置
});
</script>

<?php include 'includes/footer.php'; ?>
