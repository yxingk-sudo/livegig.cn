<?php
// 套餐分配页面 - 为每日每餐分配套餐
// 功能：基于后台设置的套餐，为每个日期和餐类型分配一个或多个套餐
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

// 获取所有可用的套餐（只获取启用的套餐）
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

// 获取已分配的套餐
$assignedPackages = [];
if (!empty($allDates)) {
    $datePlaceholders = str_repeat('?,', count($allDates) - 1) . '?';
    $assignQuery = "SELECT meal_date, meal_type, package_id FROM meal_package_assignments
                    WHERE project_id = ? AND meal_date IN ($datePlaceholders)";
    $assignStmt = $db->prepare($assignQuery);
    $params = array_merge([$projectId], $allDates);
    $assignStmt->execute($params);
    $assignments = $assignStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($assignments as $assign) {
        $key = $assign['meal_date'] . '_' . $assign['meal_type'];
        if (!isset($assignedPackages[$key])) {
            $assignedPackages[$key] = [];
        }
        $assignedPackages[$key][] = $assign['package_id'];
    }
}

// 设置页面特定变量
$page_title = '套餐分配 - ' . ($_SESSION['project_name'] ?? '项目');
$active_page = 'meal_package_assign';
$show_page_title = '套餐分配';
$page_icon = 'basket';

// 包含统一头部文件
include 'includes/header.php';
?>

<style>
/* ===== Espire 套餐分配页面样式 v2.3-nth-of-type-fix ===== */
.mpa-page { font-size: 1rem; }

/* 顶部标题区 */
.mpa-hero {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    border-radius: 14px;
    padding: 1.75rem 2rem;
    color: #fff;
    margin-bottom: 1.75rem;
    box-shadow: 0 6px 24px rgba(245,87,108,.35);
}
.mpa-hero-title { font-size: 1.55rem; font-weight: 700; margin-bottom: .25rem; }
.mpa-hero-sub   { font-size: 1rem; opacity: .9; }
.mpa-back-link {
    font-size: .95rem; padding: .45rem 1.1rem; border-radius: 8px;
    background: rgba(255,255,255,.18); color: #fff; border: 1px solid rgba(255,255,255,.4);
}
.mpa-back-link:hover { background: rgba(255,255,255,.25); color: #fff; text-decoration: none; }

/* 控制区域 */
.mpa-controls-card {
    border: none;
    border-radius: 14px;
    box-shadow: 0 2px 14px rgba(0,0,0,.08);
    margin-bottom: 1.5rem;
}
.mpa-controls-card .card-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-bottom: 2px solid #dee2e6;
    padding: .9rem 1.25rem;
    font-size: 1.05rem;
    font-weight: 600;
    color: #495057;
}

/* 表格容器 */
.mpa-table-container {
    border: none;
    border-radius: 14px;
    box-shadow: 0 2px 14px rgba(0,0,0,.08);
    overflow-x: auto;
    background: #fff;
}

/* 表格样式 */
.package-assign-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    min-width: 1200px;
}

.package-assign-table th {
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
.package-assign-table .fixed-column {
    position: sticky;
    left: 0;
    background: #fff;
    z-index: 5;
    box-shadow: 2px 0 5px rgba(0,0,0,0.05);
}

.package-assign-table th.fixed-column {
    z-index: 15;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
}

/* 日期列组边框 */
.date-group-header {
    border-left: 3px solid #f5576c !important;
    border-right: 3px solid #f5576c !important;
    position: relative;
}

.date-group-header::before {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 2px;
    background-color: #f5576c;
    opacity: 0.3;
}

.date-group-header .date-label {
    font-size: 0.85rem;
    font-weight: 700;
    color: #f5576c;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* 日期大列背景色 - 使用 nth-of-type 避免计算错误 */
.date-group-header:nth-of-type(4n+1) {
    background: linear-gradient(135deg, rgba(245,87,108, 0.15) 0%, rgba(240,147,251, 0.10) 100%) !important;
}

.date-group-header:nth-of-type(4n+2) {
    background: linear-gradient(135deg, rgba(142,68,173, 0.15) 0%, rgba(155,89,182, 0.10) 100%) !important;
}

.date-group-header:nth-of-type(4n+3) {
    background: linear-gradient(135deg, rgba(52,152,219, 0.15) 0%, rgba(41,128,185, 0.10) 100%) !important;
}

.date-group-header:nth-of-type(4n+4) {
    background: linear-gradient(135deg, rgba(46,204,113, 0.15) 0%, rgba(39,174,96, 0.10) 100%) !important;
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
.package-cell {
    text-align: left;
    padding: 0.75rem 0.5rem;
    border-bottom: 1px solid #e9ecef;
    border-right: 1px solid #dee2e6;
    min-width: 200px;
    vertical-align: top;
}

/* 日期列单元格样式 */
.package-cell[data-meal-type] {
    text-align: center;
    vertical-align: middle;
}

.package-cell:last-child {
    border-right: none;
}

/* 套餐选项容器 */
.package-options {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

/* 套餐复选框样式 */
.package-checkbox {
    display: flex;
    align-items: center;
    padding: 0.35rem 0.5rem;
    border-radius: 6px;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 0.85rem;
}

.package-checkbox:hover {
    background: #e9ecef;
    border-color: #f5576c;
}

.package-checkbox input[type="checkbox"] {
    width: 1.1rem;
    height: 1.1rem;
    margin-right: 0.5rem;
    cursor: pointer;
    accent-color: #f5576c;
}

.package-checkbox input[type="checkbox"]:checked + span {
    font-weight: 600;
    color: #f5576c;
}

.package-checkbox label {
    margin: 0;
    cursor: pointer;
    display: flex;
    align-items: center;
    width: 100%;
}

.package-checkbox span {
    flex: 1;
    word-break: break-word;
}

/* 为每个日期大列的单元格添加对应的淡色背景 - 使用 data-date-index 属性选择器 */
[data-date-index="0"] { background-color: rgba(245,87,108, 0.08); }
[data-date-index="1"] { background-color: rgba(142,68,173, 0.08); }
[data-date-index="2"] { background-color: rgba(52,152,219, 0.08); }
[data-date-index="3"] { background-color: rgba(46,204,113, 0.08); }
[data-date-index="4"] { background-color: rgba(245,87,108, 0.08); }
[data-date-index="5"] { background-color: rgba(142,68,173, 0.08); }
[data-date-index="6"] { background-color: rgba(52,152,219, 0.08); }
[data-date-index="7"] { background-color: rgba(46,204,113, 0.08); }
[data-date-index="8"] { background-color: rgba(245,87,108, 0.08); }
[data-date-index="9"] { background-color: rgba(142,68,173, 0.08); }

/* 已选中的套餐复选框 */
.package-checkbox.checked {
    background: linear-gradient(135deg, rgba(245,87,108, 0.15), rgba(240,147,251, 0.15));
    border-color: #f5576c;
}

/* 下拉选择框样式 */
.package-select {
    width: 100%;
    font-size: 0.85rem;
    padding: 0.4rem 0.3rem;
    border: 1px solid #ced4da;
    border-radius: 6px;
    background: #fff;
    cursor: pointer;
    transition: all 0.2s;
}

.package-select:hover {
    border-color: #f5576c;
    box-shadow: 0 0 0 0.2rem rgba(245,87,108,0.25);
}

.package-select:focus {
    outline: none;
    border-color: #f5576c;
    box-shadow: 0 0 0 0.2rem rgba(245,87,108,0.25);
}

.package-select option {
    padding: 0.5rem;
}

/* 统计卡片 */
.mpa-stat-card {
    background: #fff;
    border-radius: 12px;
    padding: 1.25rem;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
    text-align: center;
}

.mpa-stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: #f5576c;
    margin-bottom: 0.25rem;
}

.mpa-stat-label {
    font-size: 0.9rem;
    color: #6c757d;
}

/* 按钮样式 */
.mpa-btn {
    font-size: 0.95rem;
    padding: 0.5rem 1.2rem;
    border-radius: 8px;
    font-weight: 500;
    transition: all 0.3s;
}

.mpa-btn-primary {
    background: linear-gradient(135deg, #f093fb, #f5576c);
    border: none;
    color: #fff;
}

.mpa-btn-primary:hover {
    opacity: 0.9;
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(245,87,108,.3);
}

.mpa-btn-secondary {
    background: #6c757d;
    border: none;
    color: #fff;
}

.mpa-btn-secondary:hover {
    background: #5a6268;
    color: #fff;
}

/* Toast 消息 */
.mpa-toast {
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
    .mpa-hero {
        padding: 1.25rem;
    }
    .mpa-hero-title {
        font-size: 1.25rem;
    }
    .package-assign-table {
        font-size: 0.8rem;
    }
}
</style>
<?php unset($_enabledCount, $_colors); ?>

<div class="mpa-page">

<!-- 英雄区 -->
<div class="mpa-hero">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <div class="mpa-hero-title">套餐分配</div>
            <div class="mpa-hero-sub">
                <i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($_SESSION['project_name'] ?? '项目'); ?>
            </div>
        </div>
        <a href="meal_management.php" class="mpa-back-link">
            <i class="bi bi-arrow-left me-1"></i>返回报餐管理
        </a>
    </div>
</div>

<!-- 控制区域 -->
<div class="card mpa-controls-card">
    <div class="card-header">
        <i class="bi bi-sliders text-primary me-2"></i>操作控制
    </div>
    <div class="card-body">
        <div class="row g-3 align-items-center">
            <div class="col-md-6">
                <div class="d-flex gap-2">
                    <button type="button" class="btn mpa-btn mpa-btn-primary" onclick="saveAllAssignments()">
                        <i class="bi bi-check-circle me-1"></i>保存所有分配
                    </button>
                    <button type="button" class="btn mpa-btn mpa-btn-secondary" onclick="copyFromPreviousDay()">
                        <i class="bi bi-files me-1"></i>复制前一天
                    </button>
                </div>
            </div>
            <div class="col-md-6">
                <div class="row g-2">
                    <div class="col-md-4">
                        <div class="mpa-stat-card">
                            <div class="mpa-stat-value" id="totalDates"><?php echo count($allDates); ?></div>
                            <div class="mpa-stat-label">总天数</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mpa-stat-card">
                            <div class="mpa-stat-value" id="totalPackages"><?php echo count($allPackages); ?></div>
                            <div class="mpa-stat-label">套餐数</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mpa-stat-card">
                            <div class="mpa-stat-value" id="assignedCount">0</div>
                            <div class="mpa-stat-label">已分配</div>
                        </div>
                    </div>
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
<div class="card mpa-table-container">
    <div class="card-body p-0">
        <?php if (empty($allDates)): ?>
            <div class="text-center py-5">
                <i class="bi bi-info-circle display-1 text-muted"></i>
                <p class="mt-3 text-muted">暂无住宿记录，无法显示套餐分配表格</p>
            </div>
        <?php elseif (empty($allPackages)): ?>
            <div class="text-center py-5">
                <i class="bi bi-basket display-1 text-muted"></i>
                <p class="mt-3 text-muted">暂无可用套餐，请先在后台添加套餐</p>
            </div>
        <?php else: ?>
            <table class="package-assign-table" id="packageAssignTable">
                <thead>
                    <tr>
                        <th class="fixed-column" style="width: 100px;">日期</th>
                        <?php
                        // 生成餐类型表头
                        if ($mealTypeConfig['breakfast_enabled']): ?>
                            <th>早餐</th>
                        <?php endif; 
                        if ($mealTypeConfig['lunch_enabled']): ?>
                            <th>午餐</th>
                        <?php endif; 
                        if ($mealTypeConfig['dinner_enabled']): ?>
                            <th>晚餐</th>
                        <?php endif; 
                        if ($mealTypeConfig['supper_enabled']): ?>
                            <th>宵夜</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    foreach ($allDates as $dateIndex => $date):
                        $dateObj = new DateTime($date);
                        $weekday = $dateObj->format('l');
                        $weekdayMap = [
                            'Monday' => '一', 'Tuesday' => '二', 'Wednesday' => '三',
                            'Thursday' => '四', 'Friday' => '五', 'Saturday' => '六', 'Sunday' => '日'
                        ];
                        $weekdayChar = $weekdayMap[$weekday] ?? '';
                    ?>
                        <tr data-date="<?php echo $date; ?>">
                            <td class="fixed-column fw-bold">
                                <?php echo $date; ?> (周<?php echo $weekdayChar; ?>)
                            </td>
                            <?php 
                            // 为每个餐类型生成单元格
                            if ($mealTypeConfig['breakfast_enabled']): ?>
                                <td class="package-cell" data-meal-type="早餐">
                                    <?php
                                    $key = $date . '_早餐';
                                    $assignedPackageIds = $assignedPackages[$key] ?? [];
                                    ?>
                                    <div class="package-options">
                                        <?php
                                        if (isset($packagesByType['早餐']) && !empty($packagesByType['早餐'])):
                                            foreach ($packagesByType['早餐'] as $pkg):
                                                $isSelected = in_array($pkg['id'], $assignedPackageIds);
                                        ?>
                                            <div class="package-checkbox <?php echo $isSelected ? 'checked' : ''; ?>">
                                                <input type="checkbox" 
                                                       id="pkg_<?php echo $pkg['id']; ?>_<?php echo $date; ?>_早餐"
                                                       value="<?php echo $pkg['id']; ?>"
                                                       data-meal-date="<?php echo $date; ?>"
                                                       data-meal-type="早餐"
                                                       data-package-name="<?php echo htmlspecialchars($pkg['name']); ?>"
                                                       <?php echo $isSelected ? 'checked' : ''; ?>
                                                       onchange="markAsChanged(this)">
                                                <label for="pkg_<?php echo $pkg['id']; ?>_<?php echo $date; ?>_早餐">
                                                    <span><?php echo htmlspecialchars($pkg['name']); ?></span>
                                                </label>
                                            </div>
                                        <?php
                                            endforeach;
                                        else:
                                        ?>
                                            <div class="text-muted small">暂无可用套餐</div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            <?php endif; 
                            if ($mealTypeConfig['lunch_enabled']): ?>
                                <td class="package-cell" data-meal-type="午餐">
                                    <?php
                                    $key = $date . '_午餐';
                                    $assignedPackageIds = $assignedPackages[$key] ?? [];
                                    ?>
                                    <div class="package-options">
                                        <?php
                                        if (isset($packagesByType['午餐']) && !empty($packagesByType['午餐'])):
                                            foreach ($packagesByType['午餐'] as $pkg):
                                                $isSelected = in_array($pkg['id'], $assignedPackageIds);
                                        ?>
                                            <div class="package-checkbox <?php echo $isSelected ? 'checked' : ''; ?>">
                                                <input type="checkbox" 
                                                       id="pkg_<?php echo $pkg['id']; ?>_<?php echo $date; ?>_午餐"
                                                       value="<?php echo $pkg['id']; ?>"
                                                       data-meal-date="<?php echo $date; ?>"
                                                       data-meal-type="午餐"
                                                       data-package-name="<?php echo htmlspecialchars($pkg['name']); ?>"
                                                       <?php echo $isSelected ? 'checked' : ''; ?>
                                                       onchange="markAsChanged(this)">
                                                <label for="pkg_<?php echo $pkg['id']; ?>_<?php echo $date; ?>_午餐">
                                                    <span><?php echo htmlspecialchars($pkg['name']); ?></span>
                                                </label>
                                            </div>
                                        <?php
                                            endforeach;
                                        else:
                                        ?>
                                            <div class="text-muted small">暂无可用套餐</div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            <?php endif; 
                            if ($mealTypeConfig['dinner_enabled']): ?>
                                <td class="package-cell" data-meal-type="晚餐">
                                    <?php
                                    $key = $date . '_晚餐';
                                    $assignedPackageIds = $assignedPackages[$key] ?? [];
                                    ?>
                                    <div class="package-options">
                                        <?php
                                        if (isset($packagesByType['晚餐']) && !empty($packagesByType['晚餐'])):
                                            foreach ($packagesByType['晚餐'] as $pkg):
                                                $isSelected = in_array($pkg['id'], $assignedPackageIds);
                                        ?>
                                            <div class="package-checkbox <?php echo $isSelected ? 'checked' : ''; ?>">
                                                <input type="checkbox" 
                                                       id="pkg_<?php echo $pkg['id']; ?>_<?php echo $date; ?>_晚餐"
                                                       value="<?php echo $pkg['id']; ?>"
                                                       data-meal-date="<?php echo $date; ?>"
                                                       data-meal-type="晚餐"
                                                       data-package-name="<?php echo htmlspecialchars($pkg['name']); ?>"
                                                       <?php echo $isSelected ? 'checked' : ''; ?>
                                                       onchange="markAsChanged(this)">
                                                <label for="pkg_<?php echo $pkg['id']; ?>_<?php echo $date; ?>_晚餐">
                                                    <span><?php echo htmlspecialchars($pkg['name']); ?></span>
                                                </label>
                                            </div>
                                        <?php
                                            endforeach;
                                        else:
                                        ?>
                                            <div class="text-muted small">暂无可用套餐</div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            <?php endif; 
                            if ($mealTypeConfig['supper_enabled']): ?>
                                <td class="package-cell" data-meal-type="宵夜">
                                    <?php
                                    $key = $date . '_宵夜';
                                    $assignedPackageIds = $assignedPackages[$key] ?? [];
                                    ?>
                                    <div class="package-options">
                                        <?php
                                        if (isset($packagesByType['宵夜']) && !empty($packagesByType['宵夜'])):
                                            foreach ($packagesByType['宵夜'] as $pkg):
                                                $isSelected = in_array($pkg['id'], $assignedPackageIds);
                                        ?>
                                            <div class="package-checkbox <?php echo $isSelected ? 'checked' : ''; ?>">
                                                <input type="checkbox" 
                                                       id="pkg_<?php echo $pkg['id']; ?>_<?php echo $date; ?>_宵夜"
                                                       value="<?php echo $pkg['id']; ?>"
                                                       data-meal-date="<?php echo $date; ?>"
                                                       data-meal-type="宵夜"
                                                       data-package-name="<?php echo htmlspecialchars($pkg['name']); ?>"
                                                       <?php echo $isSelected ? 'checked' : ''; ?>
                                                       onchange="markAsChanged(this)">
                                                <label for="pkg_<?php echo $pkg['id']; ?>_<?php echo $date; ?>_宵夜">
                                                    <span><?php echo htmlspecialchars($pkg['name']); ?></span>
                                                </label>
                                            </div>
                                        <?php
                                            endforeach;
                                        else:
                                        ?>
                                            <div class="text-muted small">暂无可用套餐</div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

</div><!-- /.mpa-page -->

<!-- 加载提示 -->
<div id="loadingToast" class="toast mpa-toast bg-primary text-white" role="alert" aria-live="assertive" aria-atomic="true" style="display:none;">
    <div class="toast-body">
        <i class="bi bi-hourglass-split me-2"></i>
        <span id="loadingText">保存中...</span>
    </div>
</div>

<!-- 成功提示 -->
<div id="successToast" class="toast mpa-toast bg-success text-white" role="alert" aria-live="assertive" aria-atomic="true" style="display:none;">
    <div class="toast-body">
        <i class="bi bi-check-circle me-2"></i>
        <span id="successText">保存成功</span>
    </div>
</div>

<!-- 错误提示 -->
<div id="errorToast" class="toast mpa-toast bg-danger text-white" role="alert" aria-live="assertive" aria-atomic="true" style="display:none;">
    <div class="toast-body">
        <i class="bi bi-exclamation-triangle me-2"></i>
        <span id="errorText">保存失败</span>
    </div>
</div>

<script>
console.log('=== 套餐分配页面脚本已加载 ===');
console.log('版本：2026-03-06-v2 (Checkbox 版)');

// 标记为已修改
let hasChanges = false;

function markAsChanged(checkbox) {
    hasChanges = true;
    
    // 更新复选框样式
    const checkboxDiv = checkbox.closest('.package-checkbox');
    if (checkbox.checked) {
        checkboxDiv.classList.add('checked');
    } else {
        checkboxDiv.classList.remove('checked');
    }
    
    updateStatistics();
}

// 更新统计数据
function updateStatistics() {
    let assignedCount = 0;
    document.querySelectorAll('.package-checkbox input[type="checkbox"]:checked').forEach(cb => {
        if (cb.value !== '') {
            assignedCount++;
        }
    });
    document.getElementById('assignedCount').textContent = assignedCount;
}

// 保存所有分配
function saveAllAssignments() {
    if (!hasChanges) {
        showToast('info', '没有需要保存的更改');
        return;
    }
    
    if (!confirm('确定要保存所有套餐分配吗？')) return;
    
    // 收集所有分配数据
    const assignments = [];
    const dateMealTypeMap = new Map();
    
    // 遍历所有选中的复选框
    document.querySelectorAll('.package-checkbox input[type="checkbox"]:checked').forEach(cb => {
        const mealDate = cb.dataset.mealDate;
        const mealType = cb.dataset.mealType;
        const packageId = parseInt(cb.value);
        
        if (packageId > 0) {
            const key = `${mealDate}_${mealType}`;
            if (!dateMealTypeMap.has(key)) {
                dateMealTypeMap.set(key, {
                    meal_date: mealDate,
                    meal_type: mealType,
                    package_ids: []
                });
            }
            dateMealTypeMap.get(key).package_ids.push(packageId);
        }
    });
    
    // 转换为数组格式
    assignments.push(...Array.from(dateMealTypeMap.values()));
    
    // 同时处理未选中任何套餐的情况（清空）
    document.querySelectorAll('.package-checkbox input[type="checkbox"]').forEach(cb => {
        const mealDate = cb.dataset.mealDate;
        const mealType = cb.dataset.mealType;
        const key = `${mealDate}_${mealType}`;
        
        // 如果这个日期 + 餐次没有任何选中的，添加一个空数组
        if (!dateMealTypeMap.has(key)) {
            // 检查是否已经有这个键
            const hasEmpty = assignments.some(a => 
                a.meal_date === mealDate && a.meal_type === mealType
            );
            if (!hasEmpty) {
                // 不需要显式添加空数组，因为后端会删除旧数据
            }
        }
    });
    
    if (assignments.length === 0) {
        // 检查是否有需要清空的记录
        showToast('info', '没有分配任何套餐');
        return;
    }
    
    // 显示加载提示
    showToast('loading', '保存中...');
    
    // 发送到服务器
    fetch('ajax/save_package_assignment.php', {
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
            showToast('success', '保存成功！共保存 ' + data.data.saved_count + ' 条记录');
            hasChanges = false;
            updateStatistics();
        } else {
            showToast('error', data.message || '保存失败');
        }
    })
    .catch(error => {
        console.error('保存失败:', error);
        showToast('error', '网络错误，请稍后重试');
    });
}

// 复制前一天的分配
function copyFromPreviousDay() {
    if (!confirm('确定要复制前一天的套餐分配吗？此操作将覆盖当前所有分配。')) return;
    
    const checkboxes = document.querySelectorAll('.package-checkbox input[type="checkbox"]');
    const dates = Array.from(new Set(Array.from(checkboxes).map(cb => cb.dataset.mealDate))).sort();
    
    if (dates.length < 2) {
        showToast('info', '没有足够的日期进行复制');
        return;
    }
    
    let copiedCount = 0;
    
    for (let i = 1; i < dates.length; i++) {
        const prevDate = dates[i - 1];
        const currentDate = dates[i];
        
        document.querySelectorAll(`.package-checkbox input[type="checkbox"][data-meal-date="${currentDate}"]`).forEach(currentCb => {
            const mealType = currentCb.dataset.mealType;
            const packageName = currentCb.dataset.packageName;
            const prevCb = document.querySelector(
                `.package-checkbox input[type="checkbox"][data-meal-date="${prevDate}"][data-meal-type="${mealType}"][data-package-name="${packageName}"]`
            );
            
            if (prevCb) {
                const wasChecked = currentCb.checked;
                currentCb.checked = prevCb.checked;
                
                if (prevCb.checked && !wasChecked) {
                    markAsChanged(currentCb);
                    copiedCount++;
                } else if (!prevCb.checked && wasChecked) {
                    markAsChanged(currentCb);
                }
            }
        });
    }
    
    showToast('success', `已复制${copiedCount}个套餐的分配`);
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
    
    // 初始化所有 checkbox 的样式
    document.querySelectorAll('.package-checkbox input[type="checkbox"]').forEach(cb => {
        if (cb.checked) {
            cb.closest('.package-checkbox').classList.add('checked');
        }
    });
});
</script>

<?php include 'includes/footer.php'; ?>
