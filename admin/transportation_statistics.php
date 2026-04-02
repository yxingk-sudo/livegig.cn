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

// 获取筛选参数
$filters = [
    'project_id' => intval($_GET['project_id'] ?? 0),
    'date' => $_GET['date'] ?? ''
];

// 获取项目列表用于筛选
$projects_query = "SELECT id, name, code FROM projects ORDER BY name ASC";
$projects_stmt = $db->prepare($projects_query);
$projects_stmt->execute();
$projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);

// 只有选择了项目才查询数据
$statistics = [];
$summary = ['total_trips' => 0, 'total_vehicles' => 0, 'total_seats' => 0, 'total_passengers' => 0, 'avg_seats_per_vehicle' => 0, 'overall_occupancy_rate' => 0];
$vehicle_type_stats = [];
$vehicle_details = [];

if ($filters['project_id']) {
    // 构建查询条件
    $where_conditions = ['tr.project_id = :project_id'];
    $params = [':project_id' => $filters['project_id']];

    if ($filters['date']) {
        $where_conditions[] = "tr.travel_date = :date";
        $params[':date'] = $filters['date'];
    }

$where_clause = $where_conditions ? "WHERE " . implode(' AND ', $where_conditions) : "";

// 获取车辆分配统计信息
$stats_query = "
    SELECT 
        p.name as project_name,
        p.code as project_code,
        tr.travel_date,
        tr.travel_type,
        COUNT(DISTINCT tr.id) as total_trips,
        COUNT(DISTINCT f.id) as total_vehicles,
        SUM(f.seats) as total_seats,
        SUM(tr.passenger_count) as total_passengers,
        GROUP_CONCAT(DISTINCT CONCAT(f.vehicle_type, ' - ', f.vehicle_model)) as vehicle_types,
        GROUP_CONCAT(DISTINCT f.fleet_number) as fleet_numbers,
        AVG(f.seats) as avg_seats_per_vehicle,
        SUM(tr.passenger_count) / SUM(f.seats) * 100 as occupancy_rate
    FROM transportation_reports tr
    JOIN projects p ON tr.project_id = p.id
    LEFT JOIN transportation_fleet_assignments tfa ON tr.id = tfa.transportation_report_id
    LEFT JOIN fleet f ON tfa.fleet_id = f.id
    $where_clause
    GROUP BY p.id, tr.travel_date, tr.travel_type
    ORDER BY tr.travel_date DESC, p.name ASC
";

$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute($params);
$statistics = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取汇总统计
$summary_query = "
    SELECT 
        COUNT(DISTINCT tr.id) as total_trips,
        COUNT(DISTINCT f.id) as total_vehicles,
        SUM(f.seats) as total_seats,
        SUM(tr.passenger_count) as total_passengers,
        AVG(f.seats) as avg_seats_per_vehicle,
        SUM(tr.passenger_count) / NULLIF(SUM(f.seats), 0) * 100 as overall_occupancy_rate
    FROM transportation_reports tr
    LEFT JOIN transportation_fleet_assignments tfa ON tr.id = tfa.transportation_report_id
    LEFT JOIN fleet f ON tfa.fleet_id = f.id
    $where_clause
";

$summary_stmt = $db->prepare($summary_query);
$summary_stmt->execute($params);
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

// 获取车辆类型统计
$vehicle_type_query = "
    SELECT 
        f.vehicle_type,
        f.vehicle_model,
        COUNT(f.id) as vehicle_count,
        SUM(f.seats) as total_seats,
        AVG(f.seats) as avg_seats
    FROM transportation_reports tr
    LEFT JOIN transportation_fleet_assignments tfa ON tr.id = tfa.transportation_report_id
    LEFT JOIN fleet f ON tfa.fleet_id = f.id
    $where_clause
    AND f.id IS NOT NULL
    GROUP BY f.vehicle_type, f.vehicle_model
    ORDER BY vehicle_count DESC
";

$vehicle_type_stmt = $db->prepare($vehicle_type_query);
$vehicle_type_stmt->execute($params);
$vehicle_type_stats = $vehicle_type_stmt->fetchAll(PDO::FETCH_ASSOC);

// 处理车辆筛选参数
        $vehicle_filter = [];
        $vehicle_where = "WHERE f.status = 'active'";
        
        if (isset($_GET['vehicle_type']) && $_GET['vehicle_type'] !== '') {
            $vehicle_type = $_GET['vehicle_type'];
            $vehicle_where .= " AND f.vehicle_type = ?";
            $vehicle_filter[] = $vehicle_type;
        }
        
        if (isset($_GET['status_filter']) && $_GET['status_filter'] !== '') {
            $status_filter = $_GET['status_filter'];
            if ($status_filter === 'assigned') {
                $vehicle_where .= " AND tr.id IS NOT NULL";
            } elseif ($status_filter === 'available') {
                $vehicle_where .= " AND tr.id IS NULL";
            }
        }

        // 构建项目筛选条件 - 只显示属于当前筛选项目的车辆信息
        $project_filter_vehicle = "";
        if ($filters['project_id'] > 0) {
            $project_filter_vehicle = " AND (tr.project_id = ? OR f.id IN (
                SELECT DISTINCT tfa.fleet_id 
                FROM transportation_fleet_assignments tfa
                JOIN transportation_reports tr2 ON tfa.transportation_report_id = tr2.id
                WHERE tr2.project_id = ?
            ))";
        }
        
        // 构建日期筛选条件
        $date_filter_vehicle = "";
        if ($filters['date']) {
            $date_filter_vehicle = " AND tr.travel_date = ?";
        }

        // 获取每辆车的详细分配信息 - 修改为获取所有乘车人员
        $vehicle_detail_query = "
            SELECT 
                f.id as vehicle_id,
                f.fleet_number,
                f.vehicle_type,
                f.vehicle_model,
                f.license_plate,
                f.seats,
                f.driver_name,
                f.driver_phone,
                p.name as project_name,
                p.code as project_code,
                tr.id as transportation_id,
                tr.travel_date,
                tr.departure_time,
                tr.vehicle_type,
                tr.departure_location,  -- 修复：使用正确的出发地字段名
                tr.destination_location, -- 修复：使用正确的目的地字段名
                tr.cost,
                tr.description,
                tr.status,
                tr.personnel_id,
                COALESCE(tp_person.name, per.name) as personnel_name,
                COALESCE(tp_dept.name, d.name) as personnel_department,
                tp.id as passenger_id
            FROM fleet f
            LEFT JOIN transportation_fleet_assignments tfa ON f.id = tfa.fleet_id
            LEFT JOIN transportation_reports tr ON tfa.transportation_report_id = tr.id
            LEFT JOIN projects p ON tr.project_id = p.id
            LEFT JOIN personnel per ON tr.personnel_id = per.id
            LEFT JOIN departments d ON per.department_id = d.id
            -- 连接transportation_passengers表获取所有乘车人员
            LEFT JOIN transportation_passengers tp ON tr.id = tp.transportation_report_id
            LEFT JOIN personnel tp_person ON tp.personnel_id = tp_person.id
            LEFT JOIN departments tp_dept ON tp_person.department_id = tp_dept.id
            $vehicle_where
            $project_filter_vehicle
            $date_filter_vehicle
            ORDER BY f.fleet_number ASC, tr.travel_date DESC, tp.id ASC
        ";

$vehicle_detail_stmt = $db->prepare($vehicle_detail_query);
        
        // 合并所有筛选参数
        $vehicle_params = $vehicle_filter;
        if ($filters['project_id'] > 0) {
            $vehicle_params[] = $filters['project_id'];
            $vehicle_params[] = $filters['project_id']; // 第二个参数用于子查询
        }
        if ($filters['date']) {
            $vehicle_params[] = $filters['date'];
        }
        
        $vehicle_detail_stmt->execute($vehicle_params);
        $vehicle_details = $vehicle_detail_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 处理取消分配请求
if (isset($_GET['remove_assignment']) && isset($_GET['transportation_id']) && isset($_GET['vehicle_id'])) {
    $transportation_id = intval($_GET['transportation_id']);
    $vehicle_id = intval($_GET['vehicle_id']);
    
    try {
        $delete_query = "DELETE FROM transportation_fleet_assignments WHERE transportation_report_id = ? AND fleet_id = ?";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->execute([$transportation_id, $vehicle_id]);
        
        $_SESSION['message'] = '车辆分配已成功取消';
        header("Location: transportation_statistics.php");
        exit;
    } catch (Exception $e) {
        $_SESSION['error'] = '取消分配失败: ' . $e->getMessage();
    }
}

// 处理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// 车辆类型映射
$vehicle_type_map = [
    'car' => '轿车',
    'van' => '商务车',
    'minibus' => '中巴车',
    'bus' => '大巴车',
    'truck' => '货车',
    'other' => '其他',
    '轿车' => '轿车',
    '商务车' => '商务车',
    '中巴车' => '中巴车',
    '大巴车' => '大巴车',
    '货车' => '货车'
];

function get_vehicle_type_label($type) {
    global $vehicle_type_map;
    return $vehicle_type_map[$type] ?? $type;
}

// 页面标题
$page_title = "车辆分配统计信息";

// 包含头部文件（会自动包含侧边栏）
include 'includes/header.php';
?>

<style>
/* 优化后的样式，参考酒店统计页面 */
.card-equal-height {
    display: flex;
    flex-direction: column;
    height: 100%;
}

.card-equal-height .card-body {
    flex: 1 1 auto;
}

.stats-card, .stats-card-secondary, .stats-card-success {
    border-radius: 10px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    border: none;
    transition: all 0.3s ease;
    margin-bottom: 1.5rem;
    height: 100%;
    display: flex;
    flex-direction: column;
}

.stats-card:hover, .stats-card-secondary:hover, .stats-card-success:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 16px rgba(0,0,0,0.12);
}

.stats-card .card-body,
.stats-card-secondary .card-body,
.stats-card-success .card-body {
    padding: 1.25rem;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    height: 100%;
    flex-grow: 1;
}

.stats-card .display-4,
.stats-card-secondary .display-4,
.stats-card-success .display-4 {
    font-size: 2rem;
    margin-bottom: 0.5rem;
}

.stats-card p,
.stats-card-secondary p,
.stats-card-success p {
    font-size: 0.9rem;
    margin-bottom: 0;
}

.stats-card-secondary {
    background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
    color: white;
}

.stats-card-success {
    background: linear-gradient(135deg, #20c997 0%, #198754 100%);
    color: white;
}

.card {
    border-radius: 10px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    border: none;
    transition: all 0.3s ease;
    margin-bottom: 1.5rem;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0,0,0,0.12);
}

.card-header {
    border-radius: 10px 10px 0 0 !important;
    padding: 1rem 1.25rem;
    font-weight: 600;
}

.table {
    border-radius: 8px;
    overflow: hidden;
    width: 100%;
}

.table th {
    font-weight: 600;
    background-color: #f8f9fa;
    border-bottom: 2px solid #e9ecef;
}

.table-responsive {
    overflow-x: hidden;
}

.progress {
    border-radius: 10px;
    height: 10px;
}

.progress-bar {
    border-radius: 10px;
}

.badge {
    font-size: 0.8rem;
    padding: 0.4em 0.7em;
    border-radius: 6px;
}

.btn {
    border-radius: 6px;
    font-weight: 500;
    transition: all 0.3s ease;
}

.btn-sm {
    border-radius: 5px;
    font-size: 0.8rem;
    padding: 0.25rem 0.5rem;
}

.form-control, .form-select {
    border-radius: 6px;
    border: 1px solid #ced4da;
    transition: all 0.3s ease;
}

.form-control:focus, .form-select:focus {
    border-color: #86b7fe;
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
}

/* 紧凑型车辆表格样式 */
.compact-vehicle-table {
    font-size: 0.85rem;
    margin-bottom: 0;
    width: 100%;
}

.compact-vehicle-table th {
    padding: 8px 6px;
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    font-size: 0.8rem;
    white-space: nowrap;
    vertical-align: middle;
}

.compact-vehicle-table td {
    padding: 8px 6px;
    vertical-align: middle;
    border-bottom: 1px solid #e9ecef;
}

/* 车辆表格列宽度 */
.vehicle-id-col { width: 12%; }
.vehicle-info-col { width: 22%; }
.driver-info-col { width: 15%; }
.status-col { width: 12%; }
.trip-info-col { width: 25%; }
.passengers-col { width: 14%; }

/* 车辆信息样式 */
.vehicle-badge {
    font-family: 'Courier New', monospace;
}

.vehicle-info-compact {
    line-height: 1.3;
}

.vehicle-plate {
    margin-bottom: 2px;
}

.vehicle-model {
    margin-bottom: 2px;
}

.vehicle-seats {
    margin-bottom: 0;
}

/* 司机信息样式 */
.driver-info-compact {
    line-height: 1.3;
}

.driver-name {
    margin-bottom: 2px;
}

.driver-phone {
    margin-bottom: 0;
}

/* 行程信息样式优化 */
.trip-container {
    max-width: 100%;
}

.trip-list {
    max-height: 120px;
    overflow-y: auto;
}

.trip-item {
    transition: all 0.2s ease;
    position: relative;
}

.trip-primary {
    border-left: 3px solid #0d6efd !important;
}

.trip-secondary {
    border-left: 3px solid #6c757d !important;
}

.trip-item:hover {
    background-color: #e3f2fd !important;
    transform: translateX(2px);
}

.trip-time {
    font-size: 0.8rem;
    margin-bottom: 1px;
}

.trip-route {
    font-size: 0.75rem;
    line-height: 1.2;
}

/* 展开/折叠按钮样式 */
.trip-toggle-btn {
    text-align: center;
    margin-top: 4px;
}

.btn-expand {
    font-size: 0.7rem;
    padding: 2px 6px;
    border-radius: 12px;
    transition: all 0.3s ease;
}

.btn-expand:hover {
    background-color: #0dcaf0;
    color: white;
    transform: scale(1.05);
}

.btn-expand.collapsed .bi-chevron-down {
    transform: rotate(0deg);
}

.btn-expand:not(.collapsed) .bi-chevron-down {
    transform: rotate(180deg);
}

.bi-chevron-down {
    transition: transform 0.3s ease;
}

/* 折叠内容样式 */
.additional-trips {
    margin-top: 4px;
}

.additional-trips .trip-item {
    animation: slideInUp 0.3s ease;
}

@keyframes slideInUp {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* 地点名称样式 */
.trip-route .text-success {
    font-weight: 500;
}

.trip-route .text-danger {
    font-weight: 500;
    color: #8b1538 !important; /* 暗红色 */
}

/* 乘客信息样式 */
.passengers-list {
    max-height: 100px;
    overflow-y: auto;
}

.passengers-list .badge {
    font-size: 0.7rem;
    padding: 3px 6px;
}

/* 状态徽章样式 */
.status-info .badge {
    font-size: 0.75rem;
    padding: 6px 10px;
}

/* 车辆行悬停效果 */
.vehicle-row {
    transition: all 0.2s ease;
}

.vehicle-row:hover {
    background-color: #f8f9fa !important;
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

/* 响应式优化 */
@media (max-width: 1200px) {
    .compact-vehicle-table {
        font-size: 0.8rem;
    }
    
    .trip-info-col, .passengers-col {
        display: none;
    }
    
    .vehicle-info-col { width: 35%; }
    .driver-info-col { width: 25%; }
    .status-col { width: 20%; }
}

@media (max-width: 768px) {
    .compact-vehicle-table th,
    .compact-vehicle-table td {
        padding: 6px 4px;
    }
    
    .vehicle-badge {
        width: 24px !important;
        height: 24px !important;
        font-size: 10px !important;
    }
    
    .card {
        margin-bottom: 1rem;
    }
    
    .stats-card, .stats-card-secondary, .stats-card-success {
        margin-bottom: 1rem;
    }
    
    /* 在移动端确保卡片高度一致 */
    .stats-card .card-body,
    .stats-card-secondary .card-body,
    .stats-card-success .card-body {
        min-height: 120px;
    }
}

/* DataTables样式优化 */
.dataTables_wrapper .dataTables_length,
.dataTables_wrapper .dataTables_filter {
    margin-bottom: 10px;
}

.dataTables_wrapper .dataTables_info,
.dataTables_wrapper .dataTables_paginate {
    margin-top: 10px;
}

.dataTables_wrapper .dataTables_filter input {
    border-radius: 4px;
    border: 1px solid #ced4da;
    padding: 4px 8px;
}

/* 表格头部样式 */
.table-dark th {
    background-color: #495057 !important;
    border-color: #495057 !important;
    color: white !important;
}

/* 确保下拉菜单正常工作 */
.dropdown-toggle::after {
    display: inline-block;
    margin-left: 0.255em;
    vertical-align: 0.255em;
    content: "";
    border-top: 0.3em solid;
    border-right: 0.3em solid transparent;
    border-bottom: 0;
    border-left: 0.3em solid transparent;
}
</style>

<!-- 顶部栏 -->
<div class="d-flex justify-content-between align-items-center mb-4 p-3 bg-white rounded shadow-sm">
    <h1 class="h3 mb-0"><i class="bi bi-bar-chart-line"></i> 车辆分配统计信息</h1>
</div>

<div class="container-fluid">
        <!-- 筛选表单 -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-funnel"></i> 筛选条件</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label for="project_id" class="form-label">项目</label>
                        <select class="form-select" id="project_id" name="project_id">
                            <option value="">所有项目</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?php echo $project['id']; ?>" 
                                        <?php echo $filters['project_id'] == $project['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($project['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="date" class="form-label">出行日期</label>
                        <input type="date" class="form-control" id="date" name="date" 
                               value="<?php echo htmlspecialchars($filters['date']); ?>">
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-search"></i> 筛选
                        </button>
                        <a href="transportation_statistics.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-clockwise"></i> 重置
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- 汇总统计卡片 -->
        <?php if ($summary && !empty($summary['total_trips'])): ?>
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card stats-card card-equal-height">
                    <div class="card-body text-center">
                        <i class="bi bi-calendar-event display-4 mb-2"></i>
                        <h3 class="mb-1"><?php echo number_format($summary['total_trips'] ?? 0); ?></h3>
                        <p class="mb-0">总行程数</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card-secondary card-equal-height">
                    <div class="card-body text-center">
                        <i class="bi bi-truck display-4 mb-2"></i>
                        <h3 class="mb-1"><?php echo number_format($summary['total_vehicles'] ?? 0); ?></h3>
                        <p class="mb-0">总车辆数</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card-success card-equal-height">
                    <div class="card-body text-center">
                        <i class="bi bi-person-badge display-4 mb-2"></i>
                        <h3 class="mb-1"><?php echo number_format($summary['total_passengers'] ?? 0); ?></h3>
                        <p class="mb-0">总出行人次</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-dark text-white card-equal-height">
                    <div class="card-body text-center">
                        <i class="bi bi-percent display-4 mb-2"></i>
                        <h3 class="mb-1"><?php echo number_format($summary['overall_occupancy_rate'] ?? 0, 1); ?>%</h3>
                        <p class="mb-0">平均上座率</p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 车辆类型统计 -->
        <?php if ($vehicle_type_stats): ?>
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card card-equal-height">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-pie-chart"></i> 车辆类型统计</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive" style="overflow-x: hidden;">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>车辆类型</th>
                                        <th>数量</th>
                                        <th>总座位数</th>
                                        <th>平均座位数</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($vehicle_type_stats as $vehicle): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo get_vehicle_type_label($vehicle['vehicle_type'] ?? '未知'); ?></strong><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($vehicle['vehicle_model'] ?? '未知型号'); ?></small>
                                        </td>
                                        <td><?php echo number_format($vehicle['vehicle_count'] ?? 0); ?></td>
                                        <td><?php echo number_format($vehicle['total_seats'] ?? 0); ?></td>
                                        <td><?php echo number_format($vehicle['avg_seats'] ?? 0, 1); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card card-equal-height">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-graph-up"></i> 上座率分析</h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($vehicle_type_stats as $vehicle): ?>
                        <?php 
                        $total_seats = $vehicle['total_seats'] ?? 0;
                        $vehicle_count = $vehicle['vehicle_count'] ?? 0;
                        $occupancy = ($total_seats > 0) ? min(100, ($vehicle_count * 4) / $total_seats * 100) : 0;
                        ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span>
                                    <?php echo get_vehicle_type_label($vehicle['vehicle_type'] ?? '未知'); ?>
                                    <!-- 在车型下方显示车辆信息 -->
                                    <small class="text-muted d-block"><?php echo htmlspecialchars($vehicle['vehicle_model'] ?? '未知型号'); ?></small>
                                </span>
                                <span><?php echo number_format($occupancy, 1); ?>%</span>
                            </div>
                            <div class="progress" style="height: 20px;">
                                <div class="progress-bar progress-bar-animated bg-<?php echo $occupancy > 80 ? 'success' : ($occupancy > 60 ? 'warning' : 'danger'); ?>" 
                                     role="progressbar" 
                                     style="width: <?php echo $occupancy; ?>%"
                                     aria-valuenow="<?php echo $occupancy; ?>" 
                                     aria-valuemin="0" 
                                     aria-valuemax="100">
                                    <?php echo number_format($occupancy, 1); ?>%
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 详细统计表 -->
        <div class="card card-equal-height">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-table"></i> 详细分配统计</h5>
                <div class="btn-group">
                    <button class="btn btn-sm btn-outline-secondary" onclick="exportTableToCSV('statisticsTable', '车辆分配统计.csv')">
                        <i class="bi bi-download"></i> 导出CSV
                    </button>
                    <button class="btn btn-sm btn-outline-primary" onclick="toggleTableSort()">
                        <i class="bi bi-sort-down"></i> 排序
                    </button>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($statistics)): ?>
                    <div class="text-center py-4">
                        <i class="bi bi-inbox display-1 text-muted"></i>
                        <h5 class="text-muted">暂无统计数据</h5>
                        <p class="text-muted">请先分配车辆到报出行车记录</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="statisticsTable">
                            <thead>
                                <tr>
                                    <th data-sort="project">项目 <i class="bi bi-arrow-down"></i></th>
                                    <th data-sort="date">出行日期</th>
                                    <th data-sort="type">交通类型</th>
                                    <th data-sort="trips">行程数</th>
                                    <th data-sort="vehicles">车辆数</th>
                                    <th data-sort="seats">座位数</th>
                                    <th data-sort="passengers">出行人次</th>
                                    <th data-sort="occupancy">上座率</th>
                                    <th>车辆详情</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($statistics as $stat): ?>
                                <?php $occupancy_rate = $stat['occupancy_rate'] ?? 0; ?>
                                <tr class="animate-fade-in-up">
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" 
                                                 style="width: 32px; height: 32px; font-size: 12px;">
                                                <?php echo substr(htmlspecialchars($stat['project_code'] ?? '未知'), 0, 2); ?>
                                            </div>
                                            <div>
                                                <strong class="d-block"><?php echo htmlspecialchars($stat['project_name'] ?? '未知项目'); ?></strong>
                                                <small class="text-muted"><?php echo htmlspecialchars($stat['project_code'] ?? '未知'); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark">
                                            <i class="bi bi-calendar"></i> 
                                            <?php echo date('Y-m-d', strtotime($stat['travel_date'] ?? date('Y-m-d'))); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">
                                            <i class="bi bi-<?php echo $stat['travel_type'] === '去程' ? 'arrow-up-right' : ($stat['travel_type'] === '返程' ? 'arrow-down-left' : 'arrow-left-right'); ?>"></i>
                                            <?php echo htmlspecialchars($stat['travel_type'] ?? '未知'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="fw-bold text-primary">
                                            <?php echo number_format($stat['total_trips'] ?? 0); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="fw-bold text-warning">
                                            <?php echo number_format($stat['total_vehicles'] ?? 0); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="fw-bold text-success">
                                            <?php echo number_format($stat['total_seats'] ?? 0); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="fw-bold text-danger">
                                            <?php echo number_format($stat['total_passengers'] ?? 0); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="progress me-2" style="width: 60px; height: 8px;">
                                                <div class="progress-bar bg-<?php echo $occupancy_rate > 80 ? 'success' : ($occupancy_rate > 60 ? 'warning' : 'danger'); ?>" 
                                                     style="width: <?php echo $occupancy_rate; ?>%">
                                                </div>
                                            </div>
                                            <span class="badge bg-<?php echo $occupancy_rate > 80 ? 'success' : ($occupancy_rate > 60 ? 'warning' : 'danger'); ?>">
                                                <?php echo number_format($occupancy_rate, 1); ?>%
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                <i class="bi bi-info-circle"></i> 详情
                                            </button>
                                            <div class="dropdown-menu">
                                                <h6 class="dropdown-header">车辆信息</h6>
                                                <div class="px-3 py-2">
                                                    <small class="text-muted">
                                                        <strong>车型:</strong> <?php echo htmlspecialchars($stat['vehicle_types'] ?? '无车辆信息'); ?><br>
                                                        <strong>车队编号:</strong> <?php echo htmlspecialchars($stat['fleet_numbers'] ?? '无'); ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 详细车辆分配信息 -->
        <div class="card mt-4 card-equal-height">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-truck"></i> 车辆分配详情</h5>
                <div>
                    <a href="assign_fleet.php" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-circle"></i> 新建分配
                    </a>
                    <!-- 管理车辆按钮 - 修复路径 -->
                     <a href="/admin/fleet_management.php" class="btn btn-secondary btn-sm">
                         <i class="bi bi-gear"></i> 管理车辆
                     </a>
                     <!-- 管理车辆按钮修复结束 -->
                </div>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-2 mb-3">
                    <?php if ($filters['project_id'] > 0): ?>
                        <input type="hidden" name="project_id" value="<?php echo htmlspecialchars($filters['project_id']); ?>">
                    <?php endif; ?>
                    <?php if ($filters['date']): ?>
                        <input type="hidden" name="date" value="<?php echo htmlspecialchars($filters['date']); ?>">
                    <?php endif; ?>
                    <div class="col-6 col-md-3">
                        <label for="vehicle_type" class="form-label small">车辆类型</label>
                        <select class="form-select form-select-sm" name="vehicle_type" id="vehicle_type">
                            <option value="">全部类型</option>
                            <option value="car" <?php echo (isset($_GET['vehicle_type']) && $_GET['vehicle_type'] === 'car') ? 'selected' : ''; ?>>轿车</option>
                            <option value="van" <?php echo (isset($_GET['vehicle_type']) && $_GET['vehicle_type'] === 'van') ? 'selected' : ''; ?>>商务车</option>
                            <option value="minibus" <?php echo (isset($_GET['vehicle_type']) && $_GET['vehicle_type'] === 'minibus') ? 'selected' : ''; ?>>中巴车</option>
                            <option value="bus" <?php echo (isset($_GET['vehicle_type']) && $_GET['vehicle_type'] === 'bus') ? 'selected' : ''; ?>>大巴车</option>
                            <option value="truck" <?php echo (isset($_GET['vehicle_type']) && $_GET['vehicle_type'] === 'truck') ? 'selected' : ''; ?>>货车</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label for="status_filter" class="form-label small">分配状态</label>
                        <select class="form-select form-select-sm" name="status_filter" id="status_filter">
                            <option value="">全部状态</option>
                            <option value="assigned" <?php echo (isset($_GET['status_filter']) && $_GET['status_filter'] === 'assigned') ? 'selected' : ''; ?>>已分配</option>
                            <option value="available" <?php echo (isset($_GET['status_filter']) && $_GET['status_filter'] === 'available') ? 'selected' : ''; ?>>空闲</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-6 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="bi bi-funnel"></i> 筛选
                        </button>
                        <a href="transportation_statistics.php<?php echo $filters['project_id'] > 0 || $filters['date'] ? '?' . http_build_query(array_filter(['project_id' => $filters['project_id'], 'date' => $filters['date']])) : ''; ?>" class="btn btn-secondary btn-sm">
                            <i class="bi bi-arrow-clockwise"></i> 重置
                        </a>
                    </div>
                </form>
                
                <?php
                // 初始化车辆分组数组
                $grouped_vehicles = [];
                foreach ($vehicle_details as $detail) {
                    $vehicle_id = $detail['vehicle_id'];
                    if (!isset($grouped_vehicles[$vehicle_id])) {
                        $grouped_vehicles[$vehicle_id] = [
                            'vehicle_info' => $detail,
                            'assignments' => []
                        ];
                    }
                    if ($detail['transportation_id']) {
                        $grouped_vehicles[$vehicle_id]['assignments'][] = $detail;
                    }
                }
                
                // 计算筛选后的统计信息
                $total_vehicles = count($grouped_vehicles);
                $assigned_vehicles = 0;
                $available_vehicles = 0;
                $total_seats = 0;
                
                foreach ($grouped_vehicles as $group) {
                    $total_seats += $group['vehicle_info']['seats'] ?? 0;
                    if (empty($group['assignments'])) {
                        $available_vehicles++;
                    } else {
                        $assigned_vehicles++;
                    }
                }
                ?>
                
                <!-- 车辆统计卡片 - 紧凑型设计 -->
                <div class="row mb-3">
                    <div class="col-6 col-md-3 mb-2">
                        <div class="card bg-light border-0 card-equal-height">
                            <div class="card-body p-3 text-center d-flex flex-column justify-content-center">
                                <i class="bi bi-truck text-primary mb-1" style="font-size: 1.5rem;"></i>
                                <h6 class="card-title mb-1 small">总车辆</h6>
                                <h4 class="mb-0 text-primary"><?php echo $total_vehicles; ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-2">
                        <div class="card bg-success text-white border-0 card-equal-height">
                            <div class="card-body p-3 text-center d-flex flex-column justify-content-center">
                                <i class="bi bi-check-circle mb-1" style="font-size: 1.5rem;"></i>
                                <h6 class="card-title mb-1 small">空闲车辆</h6>
                                <h4 class="mb-0"><?php echo $available_vehicles; ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-2">
                        <div class="card bg-warning text-white border-0 card-equal-height">
                            <div class="card-body p-3 text-center d-flex flex-column justify-content-center">
                                <i class="bi bi-clock mb-1" style="font-size: 1.5rem;"></i>
                                <h6 class="card-title mb-1 small">已分配</h6>
                                <h4 class="mb-0"><?php echo $assigned_vehicles; ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-2">
                        <div class="card bg-info text-white border-0 card-equal-height">
                            <div class="card-body p-3 text-center d-flex flex-column justify-content-center">
                                <i class="bi bi-people mb-1" style="font-size: 1.5rem;"></i>
                                <h6 class="card-title mb-1 small">总座位</h6>
                                <h4 class="mb-0"><?php echo $total_seats; ?></h4>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if (empty($vehicle_details)): ?>
                    <div class="text-center py-4">
                        <i class="bi bi-inbox display-1 text-muted"></i>
                        <h5 class="text-muted">暂无车辆信息</h5>
                        <p class="text-muted">请先添加车辆到系统中</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm compact-vehicle-table" id="vehicleDetailTable">
                            <thead class="table-dark">
                                <tr>
                                    <th class="vehicle-id-col">车队编号</th>
                                    <th class="vehicle-info-col">车辆信息</th>
                                    <th class="driver-info-col">司机信息</th>
                                    <th class="status-col text-center">状态</th>
                                    <th class="trip-info-col">出行安排</th>
                                    <th class="passengers-col">乘车人员</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                // 车辆分组已经在上面初始化，直接使用$grouped_vehicles
                                
                                foreach ($grouped_vehicles as $vehicle_id => $group): 
                                    $vehicle = $group['vehicle_info'];
                                    $assignments = $group['assignments'];
                                ?>
                                <tr class="vehicle-row">
                                    <!-- 车队编号列 -->
                                    <td class="vehicle-id-info">
                                        <div class="d-flex align-items-center">
                                            <div class="vehicle-badge bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" 
                                                 style="width: 28px; height: 28px; font-size: 11px; font-weight: bold;">
                                                <?php echo substr($vehicle['fleet_number'], -2); ?>
                                            </div>
                                            <span class="fw-bold text-primary small"><?php echo htmlspecialchars($vehicle['fleet_number']); ?></span>
                                        </div>
                                    </td>
                                    
                                    <!-- 车辆信息列 -->
                                    <td class="vehicle-details">
                                        <div class="vehicle-info-compact">
                                            <div class="vehicle-plate fw-bold small">
                                                <i class="bi bi-credit-card text-info me-1"></i>
                                                <?php echo htmlspecialchars($vehicle['license_plate'] ?? '未知车牌'); ?>
                                            </div>
                                            <div class="vehicle-model text-muted small">
                                                <i class="bi bi-truck me-1"></i>
                                                <?php echo get_vehicle_type_label($vehicle['vehicle_type'] ?? '未知'); ?> - <?php echo htmlspecialchars($vehicle['vehicle_model'] ?? '未知型号'); ?>
                                            </div>
                                            <div class="vehicle-seats text-success small">
                                                <i class="bi bi-people me-1"></i>
                                                座位: <span class="fw-bold"><?php echo $vehicle['seats']; ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <!-- 司机信息列 -->
                                    <td class="driver-details">
                                        <div class="driver-info-compact">
                                            <div class="driver-name small">
                                                <i class="bi bi-person me-1"></i>
                                                <?php echo htmlspecialchars($vehicle['driver_name'] ?? '未设置'); ?>
                                            </div>
                                            <?php if (!empty($vehicle['driver_phone'])): ?>
                                            <div class="driver-phone text-muted small">
                                                <i class="bi bi-telephone me-1"></i>
                                                <?php echo htmlspecialchars($vehicle['driver_phone']); ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    
                                    <!-- 状态列 -->
                                    <td class="status-info text-center">
                                        <?php if (empty($assignments)): ?>
                                            <span class="badge bg-success rounded-pill">
                                                <i class="bi bi-check-circle me-1"></i>空闲
                                            </span>
                                        <?php else: ?>
                                            <?php 
                                            // 计算实际的唯一行程数，而不是乘客数
                                            $unique_trips_count = [];
                                            foreach ($assignments as $assignment) {
                                                $trip_key = $assignment['travel_date'] . '|' . $assignment['departure_time'] . '|' . $assignment['departure_location'] . '|' . $assignment['destination_location'];
                                                $unique_trips_count[$trip_key] = true;
                                            }
                                            $actual_trip_count = count($unique_trips_count);
                                            ?>
                                            <span class="badge bg-warning rounded-pill">
                                                <i class="bi bi-clock me-1"></i>已分配
                                            </span>
                                            <div class="small text-muted mt-1">
                                                <?php echo $actual_trip_count; ?> 个行程
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- 出行信息列 -->
                                    <td class="trip-details">
                                        <?php if (!empty($assignments)): ?>
                                            <?php 
                                            // 获取唯一的出行信息
                                            $unique_trips = [];
                                            foreach ($assignments as $assignment) {
                                                $trip_key = $assignment['travel_date'] . '|' . $assignment['departure_time'] . '|' . $assignment['departure_location'] . '|' . $assignment['destination_location'];
                                                if (!isset($unique_trips[$trip_key])) {
                                                    $unique_trips[$trip_key] = $assignment;
                                                }
                                            }
                                            $total_trips = count($unique_trips);
                                            ?>
                                            <div class="trip-container" data-vehicle-id="<?php echo $vehicle_id; ?>">
                                                <!-- 始终显示第一个行程 -->
                                                <?php 
                                                $first_trip = array_slice($unique_trips, 0, 1);
                                                foreach ($first_trip as $assignment): 
                                                ?>
                                                    <div class="trip-item trip-primary mb-1 p-2 bg-light rounded small border">
                                                        <div class="trip-time fw-bold text-primary d-flex align-items-center">
                                                            <i class="bi bi-calendar3 me-1"></i>
                                                            <?php echo date('m-d', strtotime($assignment['travel_date'])); ?>
                                                            <span class="ms-2 badge bg-primary"><?php echo isset($assignment['departure_time']) ? substr($assignment['departure_time'], 0, 5) : '-'; ?></span>
                                                        </div>
                                                        <div class="trip-route text-muted mt-1">
                                                            <i class="bi bi-arrow-right me-1 text-info"></i>
                                                            <?php 
                                                                $departure = isset($assignment['departure_location']) ? trim($assignment['departure_location']) : '未知出发地';
                                                                $destination = isset($assignment['destination_location']) ? trim($assignment['destination_location']) : '未知目的地';
                                                                echo '<span class="text-success">' . htmlspecialchars($departure) . '</span> → <span class="text-danger">' . htmlspecialchars($destination) . '</span>';
                                                            ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                                
                                                <!-- 如果有多个行程，显示展开按钮和折叠内容 -->
                                                <?php if ($total_trips > 1): ?>
                                                    <div class="trip-toggle-btn">
                                                        <button class="btn btn-sm btn-outline-info btn-expand collapsed" 
                                                                type="button"
                                                                data-bs-toggle="collapse"
                                                                data-bs-target="#additional-trips-<?php echo $vehicle_id; ?>"
                                                                aria-expanded="false"
                                                                aria-controls="additional-trips-<?php echo $vehicle_id; ?>"
                                                                title="查看全部<?php echo $total_trips; ?>个行程">
                                                            <i class="bi bi-chevron-down"></i>
                                                            <span class="ms-1">还有<?php echo $total_trips - 1; ?>个</span>
                                                        </button>
                                                    </div>
                                                    
                                                    <!-- 可折叠的其他行程 -->
                                                    <div class="additional-trips collapse" id="additional-trips-<?php echo $vehicle_id; ?>">
                                                        <?php 
                                                        $other_trips = array_slice($unique_trips, 1);
                                                        foreach ($other_trips as $assignment): 
                                                        ?>
                                                            <div class="trip-item trip-secondary mb-1 p-2 bg-white rounded small border border-secondary">
                                                                <div class="trip-time fw-bold text-secondary d-flex align-items-center">
                                                                    <i class="bi bi-calendar3 me-1"></i>
                                                                    <?php echo date('m-d', strtotime($assignment['travel_date'])); ?>
                                                                    <span class="ms-2 badge bg-secondary"><?php echo isset($assignment['departure_time']) ? substr($assignment['departure_time'], 0, 5) : '-'; ?></span>
                                                                </div>
                                                                <div class="trip-route text-muted mt-1">
                                                                    <i class="bi bi-arrow-right me-1 text-info"></i>
                                                                    <?php 
                                                                        $departure = isset($assignment['departure_location']) ? trim($assignment['departure_location']) : '未知出发地';
                                                                        $destination = isset($assignment['destination_location']) ? trim($assignment['destination_location']) : '未知目的地';
                                                                        echo '<span class="text-success">' . htmlspecialchars($departure) . '</span> → <span class="text-danger">' . htmlspecialchars($destination) . '</span>';
                                                                    ?>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small d-flex align-items-center">
                                                <i class="bi bi-calendar-x me-1"></i>暂无安排
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <!-- 乘车人员列 -->
                                    <td class="passengers-info">
                                        <?php if (!empty($assignments)): ?>
                                            <?php 
                                            // 收集所有乘车人员
                                            $all_passengers = [];
                                            foreach ($assignments as $assignment) {
                                                if (!empty($assignment['personnel_name'])) {
                                                    $all_passengers[] = $assignment['personnel_name'];
                                                }
                                            }
                                            $unique_passengers = array_unique($all_passengers);
                                            ?>
                                            <?php if (!empty($unique_passengers)): ?>
                                                <div class="passengers-list">
                                                    <?php foreach (array_slice($unique_passengers, 0, 3) as $passenger): ?>
                                                        <span class="badge bg-light text-dark me-1 mb-1 small">
                                                            <i class="bi bi-person-fill me-1"></i><?php echo htmlspecialchars($passenger); ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                    <?php if (count($unique_passengers) > 3): ?>
                                                        <div class="text-muted small mt-1">
                                                            还有 <?php echo count($unique_passengers) - 3; ?> 人...
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small">无人员信息</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
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
</div>

<script>
    // 确保在Bootstrap组件完全加载后再执行自定义代码
    document.addEventListener('DOMContentLoaded', function() {
        // 延迟执行以确保Bootstrap组件初始化完成
        setTimeout(function() {
            // 表格排序功能
            const table = document.getElementById('statisticsTable');
            if (table) {
                // 添加表头点击事件
                document.querySelectorAll('#statisticsTable th[data-sort]').forEach(header => {
                    header.addEventListener('click', () => {
                        sortTable(header.dataset.sort, 'asc');
                    });
                });
                
                // 初始排序
                sortTable('project', 'desc');
            }
            
            const vehicleTable = document.getElementById('vehicleDetailTable');
            if (vehicleTable) {
                // 车辆详情表格功能
            }
            
            // 修复折叠按钮功能 - 确保不会被全局事件监听器干扰
            const expandButtons = document.querySelectorAll('.btn-expand');
            expandButtons.forEach(function(button) {
                // 移除可能已存在的事件监听器，避免重复绑定
                const newButton = button.cloneNode(true);
                button.parentNode.replaceChild(newButton, button);
                
                // 添加点击事件监听，防止事件被阻止
                newButton.addEventListener('click', function(e) {
                    // 阻止事件冒泡到可能干扰的全局监听器
                    e.stopPropagation();
                    e.preventDefault();
                    
                    // 获取目标折叠元素
                    const targetId = this.getAttribute('data-bs-target');
                    if (targetId) {
                        const targetElement = document.querySelector(targetId);
                        if (targetElement) {
                            // 使用Bootstrap的Collapse插件
                            if (typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
                                // 获取或创建Collapse实例
                                let collapseInstance = bootstrap.Collapse.getInstance(targetElement);
                                if (!collapseInstance) {
                                    collapseInstance = new bootstrap.Collapse(targetElement, {
                                        toggle: false // 不自动切换
                                    });
                                }
                                // 手动切换状态
                                collapseInstance.toggle();
                            } else {
                                // 降级处理：手动切换显示状态
                                const isShown = targetElement.classList.contains('show');
                                targetElement.classList.toggle('show');
                                this.classList.toggle('collapsed');
                                
                                // 更新图标和文本
                                const icon = this.querySelector('.bi-chevron-down');
                                const textSpan = this.querySelector('span');
                                if (icon) {
                                    if (!isShown) {
                                        icon.style.transform = 'rotate(180deg)';
                                        if (textSpan) textSpan.textContent = '收起';
                                    } else {
                                        icon.style.transform = 'rotate(0deg)';
                                        if (textSpan) {
                                            // 重新计算隐藏的行程数量
                                            const hiddenCount = targetElement.querySelectorAll('.trip-item').length;
                                            textSpan.textContent = `还有${hiddenCount}个`;
                                        }
                                    }
                                    icon.style.transition = 'transform 0.3s ease';
                                }
                            }
                        }
                    }
                });
            });
            
            // 使用Bootstrap原生事件监听器处理折叠功能
            // 监听折叠显示事件
            document.addEventListener('show.bs.collapse', function(e) {
                // 检查是否是行程折叠元素
                if (e.target.classList.contains('additional-trips')) {
                    // 找到对应的按钮
                    const button = document.querySelector(`[data-bs-target="#${e.target.id}"]`);
                    if (button) {
                        button.classList.remove('collapsed');
                        const icon = button.querySelector('.bi-chevron-down');
                        const textSpan = button.querySelector('span');
                        if (icon) icon.style.transform = 'rotate(180deg)';
                        if (textSpan) textSpan.textContent = '收起';
                    }
                }
            });
            
            // 监听折叠隐藏事件
            document.addEventListener('hide.bs.collapse', function(e) {
                // 检查是否是行程折叠元素
                if (e.target.classList.contains('additional-trips')) {
                    // 找到对应的按钮
                    const button = document.querySelector(`[data-bs-target="#${e.target.id}"]`);
                    if (button) {
                        button.classList.add('collapsed');
                        const icon = button.querySelector('.bi-chevron-down');
                        const textSpan = button.querySelector('span');
                        if (icon) icon.style.transform = 'rotate(0deg)';
                        if (textSpan) {
                            // 重新计算隐藏的行程数量
                            const hiddenCount = e.target.querySelectorAll('.trip-item').length;
                            textSpan.textContent = `还有${hiddenCount}个`;
                        }
                    }
                }
            });
            
            // 添加悬停提示
            const tooltips = document.querySelectorAll('[title]');
            tooltips.forEach(element => {
                element.addEventListener('mouseenter', function() {
                    // 确保元素还没有被初始化为tooltip
                    if (!this.hasAttribute('data-bs-toggle')) {
                        this.setAttribute('data-bs-toggle', 'tooltip');
                    }
                });
            });
            
            // 表格排序函数
            let currentSort = { column: 'project', direction: 'desc' };
            
            function sortTable(column, direction) {
                const table = document.getElementById('statisticsTable');
                if (!table) return;
                
                const tbody = table.querySelector('tbody');
                if (!tbody) return;
                
                const rows = Array.from(tbody.querySelectorAll('tr'));
                
                rows.sort((a, b) => {
                    let aValue, bValue;
                    
                    switch (column) {
                        case 'project':
                            const aProject = a.cells[0]?.querySelector('strong');
                            const bProject = b.cells[0]?.querySelector('strong');
                            aValue = aProject ? aProject.textContent.toLowerCase() : '';
                            bValue = bProject ? bProject.textContent.toLowerCase() : '';
                            break;
                        case 'date':
                            const aDateSpan = a.cells[1]?.querySelector('span');
                            const bDateSpan = b.cells[1]?.querySelector('span');
                            aValue = aDateSpan ? new Date(aDateSpan.textContent) : new Date(0);
                            bValue = bDateSpan ? new Date(bDateSpan.textContent) : new Date(0);
                            break;
                        case 'type':
                            const aTypeSpan = a.cells[2]?.querySelector('span');
                            const bTypeSpan = b.cells[2]?.querySelector('span');
                            aValue = aTypeSpan ? aTypeSpan.textContent : '';
                            bValue = bTypeSpan ? bTypeSpan.textContent : '';
                            break;
                        case 'trips':
                            const aTripsSpan = a.cells[3]?.querySelector('span');
                            const bTripsSpan = b.cells[3]?.querySelector('span');
                            aValue = aTripsSpan ? parseInt(aTripsSpan.textContent.replace(/,/g, '')) : 0;
                            bValue = bTripsSpan ? parseInt(bTripsSpan.textContent.replace(/,/g, '')) : 0;
                            break;
                        case 'vehicles':
                            const aVehiclesSpan = a.cells[4]?.querySelector('span');
                            const bVehiclesSpan = b.cells[4]?.querySelector('span');
                            aValue = aVehiclesSpan ? parseInt(aVehiclesSpan.textContent.replace(/,/g, '')) : 0;
                            bValue = bVehiclesSpan ? parseInt(bVehiclesSpan.textContent.replace(/,/g, '')) : 0;
                            break;
                        case 'seats':
                            const aSeatsSpan = a.cells[5]?.querySelector('span');
                            const bSeatsSpan = b.cells[5]?.querySelector('span');
                            aValue = aSeatsSpan ? parseInt(aSeatsSpan.textContent.replace(/,/g, '')) : 0;
                            bValue = bSeatsSpan ? parseInt(bSeatsSpan.textContent.replace(/,/g, '')) : 0;
                            break;
                        case 'passengers':
                            const aPassengersSpan = a.cells[6]?.querySelector('span');
                            const bPassengersSpan = b.cells[6]?.querySelector('span');
                            aValue = aPassengersSpan ? parseInt(aPassengersSpan.textContent.replace(/,/g, '')) : 0;
                            bValue = bPassengersSpan ? parseInt(bPassengersSpan.textContent.replace(/,/g, '')) : 0;
                            break;
                        case 'occupancy':
                            const aOccupancyBadge = a.cells[7]?.querySelector('.badge');
                            const bOccupancyBadge = b.cells[7]?.querySelector('.badge');
                            aValue = aOccupancyBadge ? parseFloat(aOccupancyBadge.textContent.replace('%', '')) : 0;
                            bValue = bOccupancyBadge ? parseFloat(bOccupancyBadge.textContent.replace('%', '')) : 0;
                            break;
                        default:
                            return 0;
                    }
                    
                    if (direction === 'asc') {
                        return aValue > bValue ? 1 : -1;
                    } else {
                        return aValue < bValue ? 1 : -1;
                    }
                });
                
                // 移除现有行并添加排序后的行
                while (tbody.firstChild) {
                    tbody.removeChild(tbody.firstChild);
                }
                
                rows.forEach(row => tbody.appendChild(row));
                
                // 更新表头排序指示器
                const headers = table.querySelectorAll('th[data-sort]');
                headers.forEach(header => {
                    const icon = header.querySelector('i');
                    if (icon) {
                        if (header.dataset.sort === column) {
                            icon.className = direction === 'asc' ? 'bi bi-arrow-up' : 'bi bi-arrow-down';
                        } else {
                            icon.className = 'bi bi-arrow-down';
                        }
                    }
                });
                
                currentSort = { column, direction };
            }
            
            function toggleTableSort() {
                const newDirection = currentSort.direction === 'asc' ? 'desc' : 'asc';
                sortTable(currentSort.column, newDirection);
            }
            
            // CSV导出功能
            function exportTableToCSV(tableId, filename) {
                const table = document.getElementById(tableId);
                if (!table) return;
                
                let csv = [];
                
                // 表头
                const headers = Array.from(table.querySelectorAll('thead th'))
                    .map(th => `"${th.textContent.replace(/\n/g, ' ').trim()}"`);
                csv.push(headers.join(','));
                
                // 数据行
                table.querySelectorAll('tbody tr').forEach(row => {
                    const cols = Array.from(row.querySelectorAll('td')).map(td => {
                        let text = td.textContent.replace(/\n/g, ' ').trim();
                        // 处理包含逗号或引号的内容
                        if (text.includes(',') || text.includes('"')) {
                            text = `"${text.replace(/"/g, '""')}"`;
                        }
                        return text;
                    });
                    csv.push(cols.join(','));
                });
                
                // 创建下载链接
                const csvContent = csv.join('\n');
                const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                const link = document.createElement('a');
                
                if (navigator.msSaveBlob) {
                    navigator.msSaveBlob(blob, filename);
                } else {
                    link.href = URL.createObjectURL(blob);
                    link.download = filename;
                    link.style.display = 'none';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                }
            }

            // 创建车辆类型分布饼图
            <?php if ($vehicle_type_stats): ?>
            const vehicleTypeCtx = document.getElementById('vehicleTypeChart');
            if (vehicleTypeCtx) {
                const vehicleTypeData = [
                    <?php 
                    $data_items = [];
                    foreach ($vehicle_type_stats as $vehicle) {
                        $label = addslashes(get_vehicle_type_label($vehicle['vehicle_type'] ?? '未知'));
                        $count = $vehicle['vehicle_count'] ?? 0;
                        $data_items[] = "{label: \"" . $label . "\", count: " . $count . "}";
                    }
                    echo implode(",\n                    ", $data_items);
                    ?>
                ];

                new Chart(vehicleTypeCtx, {
                    type: 'pie',
                    data: {
                        labels: vehicleTypeData.map(item => item.label),
                        datasets: [{
                            data: vehicleTypeData.map(item => item.count),
                            backgroundColor: [
                                '#667eea', '#f093fb', '#43e97b', '#ffd93d', '#ff6b6b',
                                '#4ecdc4', '#45b7d1', '#f78fb3', '#f3a683', '#786fa6'
                            ],
                            borderWidth: 2,
                            borderColor: '#ffffff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right',
                                labels: {
                                    font: {
                                        size: 12
                                    }
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.raw || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = ((value / total) * 100).toFixed(1);
                                        return `${label}: ${value} 辆 (${percentage}%)`;
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // 创建上座率分析柱状图
            const occupancyCtx = document.getElementById('occupancyChart');
            if (occupancyCtx) {
                const occupancyData = [
                    <?php 
                    $occupancy_items = [];
                    foreach ($vehicle_type_stats as $vehicle) {
                        $total_seats = $vehicle['total_seats'] ?? 0;
                        $vehicle_count = $vehicle['vehicle_count'] ?? 0;
                        $occupancy = ($total_seats > 0) ? min(100, ($vehicle_count * 4) / $total_seats * 100) : 0;
                        $label = addslashes(get_vehicle_type_label($vehicle['vehicle_type'] ?? '未知'));
                        $occupancy_items[] = "{label: \"" . $label . "\", occupancy: " . number_format($occupancy, 1) . "}";
                    }
                    echo implode(",\n                    ", $occupancy_items);
                    ?>
                ];

                new Chart(occupancyCtx, {
                    type: 'bar',
                    data: {
                        labels: occupancyData.map(item => item.label),
                        datasets: [{
                            label: '上座率 (%)',
                            data: occupancyData.map(item => item.occupancy),
                            backgroundColor: occupancyData.map(item => 
                                item.occupancy > 80 ? '#43e97b' : 
                                item.occupancy > 60 ? '#ffd93d' : '#ff6b6b'
                            ),
                            borderWidth: 1,
                            borderRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 100,
                                title: {
                                    display: true,
                                    text: '上座率 (%)'
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: '车辆类型'
                                }
                            }
                        },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return `上座率: ${context.raw}%`;
                                    }
                                }
                            }
                        }
                    }
                });
            }
            <?php endif; ?>
        }, 100); // 延迟100毫秒确保Bootstrap完全初始化
    });
</script>

<?php include 'includes/footer.php'; ?>