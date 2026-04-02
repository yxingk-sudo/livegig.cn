<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

// 车型映射配置
$vehicle_type_map = [
    'car' => '轿车',
    'van' => '商务车',
    'minibus' => '中巴车',
    'bus' => '大巴车',
    'truck' => '货车',
    'other' => '其他'
];

// 车型需求解析函数 - 用于解析JSON格式的车型需求数据
function parse_vehicle_requirements($vehicle_requirements_json) {
    global $vehicle_type_map;
    
    if (empty($vehicle_requirements_json)) {
        return [];
    }
    
    try {
        $requirements = json_decode($vehicle_requirements_json, true);
        if (!is_array($requirements)) {
            return [];
        }
        
        $result = [];
        foreach ($requirements as $vehicle_type => $details) {
            if (isset($details['type']) && $details['type'] === $vehicle_type && 
                isset($details['quantity']) && $details['quantity'] > 0 && 
                isset($vehicle_type_map[$vehicle_type])) {
                
                $result[] = [
                    'type' => $vehicle_type_map[$vehicle_type],
                    'quantity' => $details['quantity']
                ];
            }
        }
        
        return $result;
    } catch (Exception $e) {
        // 解析错误时返回空数组
        return [];
    }
}

session_start();
// 引入权限中间件进行权限验证
require_once '../includes/PermissionMiddleware.php';
$database = new Database();
$db = $database->getConnection();
$middleware = new PermissionMiddleware($db);
$middleware->checkUserPagePermission('frontend:transport:list');

requireLogin();
checkProjectPermission($_SESSION['project_id']);

$projectId = $_SESSION['project_id'];

// 检查transportation_passengers表是否存在，如果不存在则创建
try {
    $check_table_query = "SHOW TABLES LIKE 'transportation_passengers'";
    $check_table_stmt = $db->prepare($check_table_query);
    $check_table_stmt->execute();
    $table_exists = $check_table_stmt->fetchColumn();
    
    if (!$table_exists) {
        $create_table_query = "CREATE TABLE transportation_passengers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transportation_report_id INT NOT NULL,
            personnel_id INT NOT NULL,
            FOREIGN KEY (transportation_report_id) REFERENCES transportation_reports(id),
            FOREIGN KEY (personnel_id) REFERENCES personnel(id),
            UNIQUE KEY unique_passenger (transportation_report_id, personnel_id)
        )";
        $db->exec($create_table_query);
    }
} catch (Exception $e) {
    // 记录错误但继续执行
    error_log('无法创建transportation_passengers表: ' . $e->getMessage());
}

// 获取筛选参数
$filter_date = $_GET['date'] ?? '';
$filter_type = $_GET['type'] ?? '';
$filter_status = $_GET['status'] ?? '';

// 获取排序参数
$sort_order = $_GET['sort'] ?? 'desc'; // 默认降序排序
$valid_sort_orders = ['asc', 'desc'];
if (!in_array($sort_order, $valid_sort_orders)) {
    $sort_order = 'desc';
}

// 构建查询条件
$where_conditions = ["tr.project_id = :project_id"];
$params = [':project_id' => $projectId];

if ($filter_date) {
    $where_conditions[] = "tr.travel_date = :travel_date";
    $params[':travel_date'] = $filter_date;
}

if ($filter_type) {
    $where_conditions[] = "tr.travel_type = :travel_type";
    $params[':travel_type'] = $filter_type;
}

if ($filter_status) {
    $where_conditions[] = "tr.status = :status";
    $params[':status'] = $filter_status;
}

// 只选择主行程（没有父行程ID的行程）
$where_conditions[] = "tr.parent_transport_id IS NULL";

$where_clause = implode(' AND ', $where_conditions);

// 获取行程记录，从transportation_passengers关联表获取乘客信息
// 确保总乘客数准确，不再通过合并多个行程记录计算
// 根据排序参数动态调整排序方向
$order_direction = ($sort_order === 'desc') ? 'DESC' : 'ASC';
$query = "SELECT 
    tr.id as main_id,
    tr.travel_date,
    tr.travel_type,
    tr.departure_time,
    tr.departure_location,
    tr.destination_location,
    tr.status,
    tr.id as transport_ids,
    tr.contact_phone as contact_phones,
    tr.special_requirements as special_requirements,
    -- 获取乘车人姓名和部门信息
    GROUP_CONCAT(DISTINCT CONCAT(p.name, '|', COALESCE(d.name, '未分配部门')) ORDER BY p.name) as personnel_info,
    1 as trip_count,
    tr.passenger_count as total_passengers,
    tr.vehicle_requirements,
    GROUP_CONCAT(DISTINCT f.fleet_number) as fleet_numbers,
    GROUP_CONCAT(DISTINCT f.vehicle_type) as vehicle_types,
    GROUP_CONCAT(DISTINCT f.license_plate) as license_plates,
    GROUP_CONCAT(DISTINCT f.driver_name) as driver_names,
    GROUP_CONCAT(DISTINCT f.driver_phone) as driver_phones
FROM transportation_reports tr 
LEFT JOIN transportation_passengers tp ON tr.id = tp.transportation_report_id
LEFT JOIN personnel p ON tp.personnel_id = p.id OR tr.personnel_id = p.id
-- 获取人员所属部门信息
LEFT JOIN project_department_personnel pdp ON p.id = pdp.personnel_id AND pdp.project_id = tr.project_id
LEFT JOIN departments d ON pdp.department_id = d.id
LEFT JOIN transportation_fleet_assignments tfa ON tr.id = tfa.transportation_report_id
LEFT JOIN fleet f ON tfa.fleet_id = f.id
WHERE {$where_clause}
GROUP BY tr.id, tr.travel_date, tr.travel_type, tr.departure_time, tr.departure_location, tr.destination_location, tr.status, tr.contact_phone, tr.special_requirements, tr.passenger_count, tr.vehicle_requirements
ORDER BY tr.travel_date {$order_direction}, tr.departure_time {$order_direction}";
$stmt = $db->prepare($query);
$stmt->execute($params);
$transports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取统计信息 - 修正已分配统计逻辑
$stats_query = "SELECT 
    COUNT(*) as total_count,
    COUNT(CASE WHEN tr.status = 'pending' THEN 1 END) as pending_count,
    -- 已分配统计：统计有车辆分配的行程（通过transportation_fleet_assignments表关联）
    COUNT(CASE WHEN tfa.id IS NOT NULL THEN 1 END) as assigned_count,
    COUNT(CASE WHEN tr.status = 'completed' THEN 1 END) as completed_count,
    COUNT(CASE WHEN tr.personnel_id IS NULL THEN 1 END) as unassigned_personnel
    FROM transportation_reports tr 
    LEFT JOIN transportation_fleet_assignments tfa ON tr.id = tfa.transportation_report_id
    WHERE tr.project_id = :project_id";

$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute([':project_id' => $projectId]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// 获取交通类型列表
$types_query = "SELECT DISTINCT travel_type FROM transportation_reports WHERE project_id = :project_id ORDER BY travel_type";
$types_stmt = $db->prepare($types_query);
$types_stmt->execute([':project_id' => $projectId]);
$travel_types = $types_stmt->fetchAll(PDO::FETCH_COLUMN);

// 获取实际存在的状态列表
$status_query = "SELECT DISTINCT status FROM transportation_reports WHERE project_id = :project_id ORDER BY status";
$status_stmt = $db->prepare($status_query);
$status_stmt->execute([':project_id' => $projectId]);
$actual_statuses = $status_stmt->fetchAll(PDO::FETCH_COLUMN);

// 页面设置
$page_title = '行程列表 - ' . ($_SESSION['project_name'] ?? '');
$active_page = 'transport_list';
$show_page_title = '行程列表';
$page_icon = 'list-task';
// 返回运输管理按钮设置 - 开始
// 将返回链接从transport_enhanced.php改为quick_transport.php
$page_action_text = '返回运输管理';
$page_action_url = 'quick_transport.php';
// 返回运输管理按钮设置 - 结束

include 'includes/header.php';
?>

<style>
/* 表格样式优化 */
.table-responsive {
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.table th {
    background-color: #f8f9fa;
    font-weight: 600;
    color: #495057;
    border: 1px solid #495057;
    border-bottom: 2px solid #495057;
    white-space: nowrap;
}

.table td {
    vertical-align: middle;
    padding: 12px 8px;
    border: 1px solid #495057;
}

.table-hover tbody tr:hover {
    background-color: #f5f5f5;
    transform: translateY(-1px);
    transition: all 0.2s ease;
}

/* 日期分隔行样式 - 增加高度和红色边框 */
.date-divider {
    border-top: 3px solid #dc3545;
    border-bottom: 1px solid #495057;
}

.date-divider td {
    padding: 16px;
    background-color: #fff5f5;
    border: none;
}

.date-header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.date-main {
    font-size: 1.1em;
    font-weight: bold;
    color: #2c3e50;
}

.date-weekday {
    font-size: 0.95em;
    font-weight: 500;
    color: #5a6c7d;
    background-color: #e8f4fd;
    padding: 4px 8px;
    border-radius: 12px;
}

.date-count {
    font-size: 0.85em;
    color: #6c757d;
    background-color: #f1f3f4;
    padding: 2px 8px;
    border-radius: 10px;
}

.date-divider:hover {
    background-color: #e9ecef;
}

.date-divider:hover .date-weekday {
    background-color: #d1ecf1;
}

.date-divider + tr td {
    border-top: none;
}

/* 移除旧的日期合并样式 */
.date-cell,
.date-content,
.date-count {
    /* 这些样式已被日期分隔行替代 */
}

/* 状态徽章样式 */
.badge {
    font-size: 0.75em;
    padding: 0.5em 0.75em;
}

/* 旧样式保留（用于兼容性） */
.transport-card {
    border: 1px solid #495057;
    border-radius: 8px;
    margin-bottom: 15px;
    transition: all 0.3s ease;
}

.transport-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.transport-header {
    background-color: #f8f9fa;
    padding: 15px;
    border-bottom: 1px solid #495057;
    border-radius: 8px 8px 0 0;
}

.transport-body {
    padding: 15px;
}

.status-badge {
    font-size: 0.75em;
}

.route-info {
    font-size: 1.1em;
    font-weight: bold;
    color: #495057;
}

.filter-section {
    background-color: #f8f9fa;
    border: 1px solid #495057;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

/* 乘车人标签样式 - 与export_transport_html.php保持一致 */
        .passenger-tags {
            display: flex;
            flex-direction: column;
            gap: 8px;
            align-items: stretch;
            padding: 4px 0;
        }
        
        .department-group {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            padding: 6px 0;
            border-left: 3px solid #e3f2fd;
            padding-left: 8px;
            transition: border-color 0.2s ease;
        }
        
        .department-group:hover {
            border-left-color: #1976d2;
        }
        
        .dept-tag {
            background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
            color: #1976d2;
            border: 1px solid #90caf9;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
            white-space: nowrap;
            flex-shrink: 0;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            transition: all 0.2s ease;
            margin-bottom: 0;
        }
        
        .dept-tag:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }
        
        .count-badge {
            background-color: #1976d2;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            margin-left: 6px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        
        .names-list {
            color: #212529;
            font-size: 1.1rem;
            line-height: 1.5;
            font-weight: 500;
            margin-top: 0;
            display: block;
            text-align: left;
            padding-left: 12px;
        }

/* 车辆需求信息显示样式 - 增大字体版本 */
    .vehicle-requirements-display {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    
    .vehicle-requirements-display .fas {
        font-size: 1.2em;  /* 增大图标 */
    }
    
    .vehicle-requirements-display > div {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }
    
    .vehicle-requirements-display .badge {
        font-size: 0.9em;  /* 增大车型需求badge字号 */
        padding: 0.5em 0.8em;
    }
    
    /* 统一表格列宽设置 - 简化控制，避免多处冲突 */
    .table th, .table td {
        /* 序号列 */
        &:nth-child(1) {
            width: 50px;
            min-width: 50px;
        }
        /* 时间列 */
        &:nth-child(2) {
            width: 60px;
            min-width: 60px;
        }
        /* 乘车人列 */
        &:nth-child(3) {
            width: 20%;
            min-width: 350px;
            max-width: 500px;
        }
        /* 乘客数列 */
        &:nth-child(4) {
            width: 60px;
            min-width: 60px;
        }
        /* 路线列 */
        &:nth-child(5) {
            width: 25%;
            min-width: 350px;
            max-width: 700px;
        }
        /* 车辆信息列 */
        &:nth-child(6) {
            width: 120px;
            min-width: 120px;
            max-width: 200px;
        }
        /* 特殊要求列 */
        &:nth-child(7) {
            width: 200px;
            min-width: 200px;
            max-width: 400px;
        }
        /* 操作列 */
        &:nth-child(8) {
            width: 80px;
            min-width: 80px;
        }
    }
    
    /* 车辆信息卡片样式 - 优化宽度和字体 */
    .vehicle-info-card {
        display: flex;
        flex-direction: column;
        gap: 4px;
        padding: 3px;
        border-radius: 4px;
        background-color: #f8f9fa;
        max-width: 180px; /* 增大宽度确保信息完整显示 */
        width: 100%;
    }
    
    .vehicle-number {
        font-size: 0.9em;  /* 适当减小字号 */
        font-weight: bold;
        color: #007bff;
        margin-bottom: 1px;
    }
    
    .vehicle-plate {
        font-size: 0.8em;  /* 适当减小字号 */
        color: #6c757d;
        margin-bottom: 1px;
    }
    
    .vehicle-plate .badge {
        font-size: 0.75em;  /* 减小字号 */
        padding: 0.2em 0.4em;
        font-family: 'Courier New', monospace;
        font-weight: 600;
        background-color: #fff3cd !important;
        border: 1px solid #ffc107 !important;
        color: #856404 !important;
        border-radius: 3px;
    }
    
    .vehicle-driver {
        font-size: 0.8em;  /* 适当减小字号 */
        color: #495057;
        font-weight: 500;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .vehicle-driver-phone {
        font-size: 0.75em;  /* 减小字号但确保手机号完整 */
        color: #495057;
        font-weight: 500;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    .vehicle-multi-item {
        padding: 5px;
        border-left: 2px solid #007bff;
        background-color: white;
        border-radius: 3px;
        box-shadow: 0 1px 2px rgba(0,0,0,0.1);
    }
    
    .vehicle-info-empty .badge {
        font-size: 0.75em;  /* 进一步减小未分配提示的字号 */
        padding: 0.4em 0.7em;
    }
    
    /* 使用部门列样式 */
    .department-list {
        font-size: 0.9em;
        line-height: 1.4;
    }
    
    .department-list span {
        display: block;
        margin-bottom: 2px;
    }
    
    .department-list span:last-child {
        margin-bottom: 0;
    }
    
    /* 特殊要求列样式 */
    .vehicle-requirements {
        font-size: 0.9em;
        line-height: 1.4;
        color: #6c757d;
        white-space: normal;
        word-wrap: break-word;
        word-break: break-word;
    }
    
    .vehicle-requirements i {
        font-size: 1.1em;
        vertical-align: middle;
    }
    
    /* 车辆分隔线样式 */
    .vehicle-divider {
        border-top: 1px dashed #dee2e6;
        margin: 8px 0;
    }
    
    /* 日期分隔空白行样式 - 增加高度 */
.date-spacer {
    background-color: transparent;
}

.date-spacer td {
    height: 30px;
    border: none;
    padding: 0;
    background-color: transparent;
}

/* 第一天的空白行高度调整 */
.table tbody tr:first-child.date-spacer td {
    height: 15px;
}

/* 响应式优化 - 简化并统一控制 */
@media (max-width: 1400px) {
    .table-responsive {
        font-size: 0.85rem;
    }
    
    .table th, .table td {
        &:nth-child(1) { min-width: 50px; width: 50px; } /* 序号 */
        &:nth-child(2) { min-width: 45px; width: 45px; } /* 时间 */
        &:nth-child(3) { min-width: 180px; max-width: 250px; width: 20%; } /* 乘车人 */
        &:nth-child(4) { min-width: 70px; width: 70px; } /* 乘客数 */
        &:nth-child(5) { min-width: 350px; max-width: 500px; width: 25%; } /* 路线 */
        &:nth-child(6) { min-width: 100px; max-width: 150px; width: 100px; } /* 车辆信息 */
        &:nth-child(7) { min-width: 150px; max-width: 300px; width: 150px; } /* 特殊要求 */
        &:nth-child(8) { min-width: 80px; width: 80px; } /* 操作 */
    }
    
    .department-group {
        gap: 6px;
        padding-left: 6px;
    }
    
    .dept-tag {
        font-size: 0.8rem;
        padding: 3px 8px;
    }
    
    .count-badge {
        font-size: 0.7rem;
        padding: 1px 5px;
    }
    
    .names-list {
        font-size: 1.0rem;
        padding-top: 2px;
        text-align: left;
        padding-left: 8px;
    }
}

@media (max-width: 992px) {
    .table th, .table td {
        &:nth-child(1) { min-width: 45px; width: 45px; } /* 序号 */
        &:nth-child(2) { min-width: 45px; width: 45px; } /* 时间 */
        &:nth-child(3) { min-width: 180px; max-width: 250px; width: 20%; } /* 乘车人 */
        &:nth-child(4) { min-width: 60px; width: 60px; } /* 乘客数 */
        &:nth-child(5) { min-width: 250px; max-width: 350px; width: 25%; } /* 路线 */
        &:nth-child(6) { min-width: 80px; max-width: 120px; width: 80px; } /* 车辆信息 */
        &:nth-child(7) { min-width: 120px; max-width: 250px; width: 120px; } /* 特殊要求 */
        &:nth-child(8) { min-width: 80px; width: 80px; } /* 操作 */
    }
    
    .department-group {
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
        border-left-width: 2px;
        padding-left: 8px;
    }
    
    .dept-tag {
        font-size: 0.75rem;
        padding: 3px 8px;
    }
    
    .count-badge {
        font-size: 0.65rem;
    }
    
    .names-list {
        font-size: 0.9rem;
        line-height: 1.4;
        text-align: left;
        padding-left: 6px;
    }
}

@media (max-width: 768px) {
    .table th, .table td {
        &:nth-child(1) { min-width: 40px; width: 40px; } /* 序号 */
        &:nth-child(2) { min-width: 45px; width: 45px; } /* 时间 */
        &:nth-child(3) { min-width: 160px; max-width: 240px; width: 20%; } /* 乘车人 */
        &:nth-child(4) { min-width: 55px; width: 55px; } /* 乘客数 */
        &:nth-child(5) { min-width: 200px; max-width: 300px; width: 25%; } /* 路线 */
        &:nth-child(6) { min-width: 70px; max-width: 100px; width: 70px; } /* 车辆信息 */
        &:nth-child(7) { min-width: 100px; max-width: 200px; width: 100px; } /* 特殊要求 */
        &:nth-child(8) { min-width: 80px; width: 80px; } /* 操作 */
    }
    
    .vehicle-info-card {
        max-width: 100px;
    }
    
    .vehicle-number {
        font-size: 0.95em;
    }
    
    .vehicle-plate {
        font-size: 0.85em;
    }
    
    .vehicle-driver {
        font-size: 0.85em;
    }
    
    .vehicle-driver-phone {
        font-size: 0.8em;
    }
}

/* 超小屏幕响应式样式 */
@media (max-width: 480px) {
    .table {
        font-size: 0.75em;
    }
    
    .table th, .table td {
        &:nth-child(1) { min-width: 40px; width: 40px; } /* 序号 */
        &:nth-child(2) { min-width: 45px; width: 45px; } /* 时间 */
        &:nth-child(3) { min-width: 160px; max-width: 240px; width: 20%; } /* 乘车人 */
        &:nth-child(4) { min-width: 55px; width: 55px; } /* 乘客数 */
        &:nth-child(5) { min-width: 180px; max-width: 250px; width: 25%; } /* 路线 */
        &:nth-child(6) { min-width: 60px; max-width: 90px; width: 60px; } /* 车辆信息 */
        &:nth-child(7) { min-width: 80px; max-width: 150px; width: 80px; } /* 特殊要求 */
        &:nth-child(8) { min-width: 80px; width: 80px; } /* 操作 */
    }
    
    .vehicle-info-card {
        max-width: 90px;
    }
    
    .vehicle-number {
        font-size: 0.85em;
    }
    
    .vehicle-plate {
        font-size: 0.75em;
    }
    
    .vehicle-driver {
        font-size: 0.75em;
    }
    
    .vehicle-driver-phone {
        font-size: 0.7em;
    }
    
    .passenger-tags {
        line-height: 1.2;
        font-size: 0.8em;
        padding: 1px 0;
    }
    
    .department-group {
        flex-direction: row;
        align-items: center;
        gap: 4px;
        margin-bottom: 4px;
        padding: 2px 0;
        border-bottom: 1px solid #f5f5f5;
        text-align: left;
        border-left: none;
        padding-left: 0;
        border-left-width: 0;
    }
    
    .dept-tag {
        font-size: 0.8em;
        margin-right: 0;
        line-height: 1.0;
        display: inline-block;
        text-align: left;
        margin-bottom: 0;
    }
    
    .count-badge {
        font-size: 0.65em;
        padding: 0px 3px;
        margin-left: 3px;
        border-radius: 8px;
        display: inline-block;
        margin: 0 0 0 3px;
        width: auto;
    }
    
    .names-list {
        font-size: 0.9em;
        line-height: 1.3;
        margin-top: 0;
        text-align: left;
        padding-left: 4px;
        display: inline-block;
    }
}
</style>

<div class="row">
    <div class="col-12">
        <!-- 筛选区域 -->
        <div class="filter-section">
            <form method="GET" class="row">
                <div class="col-md-2">
                    <label class="form-label text-danger fw-bold">排序方式</label>
                    <select name="sort" class="form-select">
                        <option value="desc" <?php echo $sort_order === 'desc' ? 'selected' : ''; ?>>日期降序 (最新在前)</option>
                        <option value="asc" <?php echo $sort_order === 'asc' ? 'selected' : ''; ?>>日期升序 (最早在前)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">交通类型</label>
                    <select name="type" class="form-select">
                        <option value="">全部类型</option>
                        <?php foreach ($travel_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $filter_type === $type ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">状态</label>
                    <select name="status" class="form-select">
                        <option value="">全部状态</option>
                        <?php
                        // 状态映射配置
                        $status_map = [
                            'pending' => '待处理',
                            'assigned' => '已分配',
                            'confirmed' => '已确认',
                            'in_progress' => '进行中',
                            'processing' => '处理中',
                            'completed' => '已完成',
                            'cancelled' => '已取消',
                            'approved' => '已批准'
                        ];
                        
                        // 只显示实际存在的状态
                        foreach ($actual_statuses as $status):
                            if (isset($status_map[$status])):
                        ?>
                        <option value="<?php echo $status; ?>" <?php echo $filter_status === $status ? 'selected' : ''; ?>>
                            <?php echo $status_map[$status]; ?>
                        </option>
                        <?php 
                            endif;
                        endforeach;
                        ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">出行日期</label>
                    <input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($filter_date); ?>">
                </div>
                <div class="col-md-1">
                    <label class="form-label">&nbsp;</label>
                    <div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> 筛选
                        </button>
                        <a href="transport_list.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-clockwise"></i> 重置
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- 行程列表 -->
        <?php if (empty($transports)): ?>
            <div class="text-center py-5">
                <i class="bi bi-inbox" style="font-size: 3em; color: #ccc;"></i>
                <h4 class="text-muted mt-3">暂无行程记录</h4>
                <p class="text-muted"><?php echo $filter_date || $filter_type || $filter_status ? '没有符合条件的行程' : '还没有创建任何行程'; ?></p>
                <a href="transport_enhanced.php" class="btn btn-primary mt-3">
                    <i class="bi bi-plus"></i> 创建新行程
                </a>
            </div>
        <?php else: ?>
            <!-- 排序提示信息 -->
            <div class="alert alert-info mb-3">
                <i class="bi bi-info-circle"></i> 
                当前按日期<?php echo $sort_order === 'desc' ? '降序' : '升序'; ?>排列，共找到 <?php echo count($transports); ?> 条行程记录
                <?php if ($filter_date || $filter_type || $filter_status): ?>
                    (已应用筛选条件)
                <?php endif; ?>
                <br><small class="text-muted">序号将根据排序方向自动调整：<?php echo $sort_order === 'desc' ? '最新记录为序号1' : '最早记录为序号1'; ?></small>
            </div>
            <!-- 表格格式显示行程列表 -->
            <div class="table-responsive">
                <table class="table table-hover table-striped table-bordered">
  <thead class="table-light">
                                    <tr>
                                        <!-- 日期列表头已移除 -->
                                        <th width="50" align="center">序号</th>
                                        <th width="30" align="center">时间</th>
                            <th width="160" align="center">乘车人</th>
                            <!-- 乘客数列居中显示设置 - 开始 -->
                            <th width="56" align="center" class="text-center">乘客数</th>
                            <!-- 移除交通类型列表头，内容将合并到时间列下方 -->
                            <th width="120" align="center">路线</th>
                            <th width="70" align="center">车辆信息</th>
                            <th width="120" align="center">特殊要求</th>
                            <th width="36" align="center">操作</th>
                      </tr>
                                </thead>
                    <tbody>
                        <?php
                        // 开始：按日期分组处理行程数据
                        $grouped_transports = [];
                        foreach ($transports as $transport) {
                            $date = $transport['travel_date'];
                            if (!isset($grouped_transports[$date])) {
                                $grouped_transports[$date] = [];
                            }
                            $grouped_transports[$date][] = $transport;
                        }
                        
                        // 根据排序参数调整日期分组排序
                        if ($sort_order === 'asc') {
                            ksort($grouped_transports);
                        } else {
                            krsort($grouped_transports);
                        }
                        // 结束：按日期分组处理行程数据
                        
                        // 初始化序号计数器 - 根据排序方向决定序号显示方式
                        $global_index = 1;
                        if ($sort_order === 'asc') {
                            // 升序排序：从1开始递增
                            $current_sequence = 1;
                        } else {
                            // 降序排序：从总记录数开始递减
                            $current_sequence = count($transports);
                        }
                        
                        foreach ($grouped_transports as $date => $date_transports): 
                            $date_count = count($date_transports);
                            $first_row = true;
                            $date_index = 0; // 日期组索引，用于添加分隔符
                            
                            foreach ($date_transports as $index => $transport):
                                // 添加日期分隔行（仅在每个日期组的第一行前插入）
                                if ($index === 0):
                        ?>
                            <!-- 日期分隔空白行 -->
                            <tr class="date-spacer">
                                <td colspan="8" style="height: 15px; border: none; padding: 0;"></td>
                            </tr>
                            <!-- 日期分隔行 -->
                            <tr class="date-divider">
                                 <td colspan="8" class="text-center">
                                     <div class="date-header">
                                         <!-- 日期显示：添加红色底色突出显示 -->
                                         <span class="date-main bg-danger text-white px-3 py-1 rounded"><?= date('Y/m/d', strtotime($date)) ?></span>
                                         <span class="date-weekday">(<?= [
                                             'Monday' => '星期一',
                                             'Tuesday' => '星期二', 
                                             'Wednesday' => '星期三',
                                             'Thursday' => '星期四',
                                             'Friday' => '星期五',
                                             'Saturday' => '星期六',
                                             'Sunday' => '星期日'
                                         ][date('l', strtotime($date))] ?>)</span>
                                         <span class="date-count">共 <?= $date_count ?> 条</span>
                                     </div>
                                 </td>
                             </tr>
                        <?php
                                endif;
                                // 状态映射配置 - 支持完整状态列表
                                $status_class = [
                                    'pending' => 'warning',
                                    'assigned' => 'info',
                                    'in_progress' => 'primary',
                                    'completed' => 'success',
                                    'cancelled' => 'danger',
                                    'confirmed' => 'success',  // 已确认状态
                                    'approved' => 'success',    // 已批准状态
                                    'processing' => 'primary'   // 处理中状态
                                ][$transport['status']] ?? 'secondary';
                                
                                $status_text = [
                                    'pending' => '待处理',
                                    'assigned' => '已分配',
                                    'in_progress' => '进行中',
                                    'completed' => '已完成',
                                    'cancelled' => '已取消',
                                    'confirmed' => '已确认',    // 已确认状态
                                    'approved' => '已批准',     // 已批准状态
                                    'processing' => '处理中'    // 处理中状态
                                ][$transport['status']] ?? '未知';
                                
                                // 处理乘车人列表（按部门聚合显示）
                                $department_groups = [];
                                if ($transport['personnel_info']) {
                                    $personnel_data = explode(',', $transport['personnel_info']);
                                    foreach ($personnel_data as $data) {
                                        if (!empty($data)) {
                                            $parts = explode('|', $data);
                                            $name = htmlspecialchars($parts[0] ?? '');
                                            $department = htmlspecialchars($parts[1] ?? '未分配部门');
                                            if (!empty($name)) {
                                                if (!isset($department_groups[$department])) {
                                                    $department_groups[$department] = [];
                                                }
                                                $department_groups[$department][] = $name;
                                            }
                                        }
                                    }
                                }
                                // 按部门名称排序
                                ksort($department_groups);
                        ?>
                            <tr>
                                <!-- 日期列已移除，日期信息通过分组标题显示 -->
                                
                                <!-- 序号列：根据排序方向动态显示序号 - 开始 -->
                                <td class="text-center">
                                    <span class="badge bg-light text-dark border"><?php 
                                        echo $current_sequence;
                                        // 根据排序方向更新序号
                                        if ($sort_order === 'asc') {
                                            $current_sequence++;
                                        } else {
                                            $current_sequence--;
                                        }
                                    ?></span>
                                </td>
                                <!-- 序号列：根据排序方向动态显示序号 - 结束 -->
                                <td>
                                    <!-- 时间列：使用加粗文字和黄色底色 -->
                                    <div class="fw-bold bg-warning bg-opacity-25 px-2 py-1 rounded text-center" style="min-width: 60px;">
                                        <?php 
                                        // 格式化时间显示，只显示到分钟
                                        $time = $transport['departure_time'];
                                        if (strlen($time) >= 5) {
                                            echo substr($time, 0, 5); // 只取 HH:MM
                                        } else {
                                            echo htmlspecialchars($time);
                                        }
                                    ?>
                                    </div>
                                    <!-- 交通类型显示在时间下方，添加行距 -->
                                    <div class="mt-1 text-center">
                                        <span class="badge bg-primary"><?php echo htmlspecialchars($transport['travel_type']); ?></span>
                                    </div>
                                    <!-- 添加复制行程信息按钮 -->
                                    <div class="mt-1 text-center">
                                        <button type="button" 
                                                class="btn btn-success btn-sm" 
                                                title="复制行程信息"
                                                onclick="copyTransportInfo(event, '<?php echo $transport['main_id']; ?>')"
                                                style="margin: 0; padding: 0.25rem 0.5rem;">
                                            <i class="bi bi-clipboard"></i>
                                        </button>
                                    </div>
                                </td>
                                <td>
                                    <div class="passenger-tags">
                                        <?php if (!empty($department_groups)): ?>
                                            <?php foreach ($department_groups as $department => $names): ?>
                                                <?php $count = count($names); ?>
                                                <div class="department-group">
                                                    <div class="dept-tag <?php 
                                                        // 开始：特殊部门标红处理
                                                        if (in_array($department, ['Artist/艺人', 'Artist Guest/艺人嘉宾'])) {
                                                            echo 'text-danger fw-bold';
                                                        }
                                                        // 结束：特殊部门标红处理
                                                    ?>">
                                                        <?= $department ?>
                                                        <span class="count-badge">(<?= $count ?>人)</span>
                                                    </div>
                                                    <div class="names-list"><?= implode('、', $names) ?></div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="text-muted">无乘车人</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <!-- 乘客数列：使用亮色背景并居中显示 -->
                                    <span class="badge bg-info text-white"><?php echo htmlspecialchars($transport['total_passengers']); ?>人</span>
                                </td>
                                <!-- 移除单独的交通类型列，内容已合并到时间列下方 -->
                                <td>
                                    <div style="white-space: nowrap;"><strong>出发:</strong> <?php echo htmlspecialchars($transport['departure_location']); ?></div>
                                    <div style="white-space: nowrap;"><strong>到达:</strong> <?php echo htmlspecialchars($transport['destination_location']); ?></div>
                                </td>
                                <td>
                                    <!-- 车辆信息显示区域 - 根据分配状态显示不同内容 -->
                                    <?php if ($transport['fleet_numbers']): ?>
                                        <!-- 已分配车辆信息显示 -->
                                        <div class="vehicle-info-card">
                                            <?php
                                            // 处理多车辆信息
                                            $fleet_numbers = explode(',', $transport['fleet_numbers']);
                                            $license_plates = explode(',', $transport['license_plates'] ?? '');
                                            $driver_names = explode(',', $transport['driver_names'] ?? '');
                                            $driver_phones = explode(',', $transport['driver_phones'] ?? '');
                                            $vehicle_count = count($fleet_numbers);
                                            
                                            for ($i = 0; $i < $vehicle_count; $i++):
                                                $fleet_number = htmlspecialchars(trim($fleet_numbers[$i] ?? ''));
                                                $license_plate = htmlspecialchars(trim($license_plates[$i] ?? ''));
                                                $driver_name = htmlspecialchars(trim($driver_names[$i] ?? '未指定'));
                                                $driver_phone = htmlspecialchars(trim($driver_phones[$i] ?? ''));
                                                
                                                if (empty($fleet_number)) continue;
                                            ?>
                                                <div class="vehicle-multi-item">
                                                    <div class="vehicle-number">
                                                        <!-- 隐藏车辆图标 - 已禁用显示 -->
                                                        <!-- <i class="bi bi-truck text-primary me-1"></i> -->
                                                        <strong><?php echo $fleet_number; ?></strong>
                                                        <!-- 隐藏车辆标号 - 已禁用显示 -->
                                                        <?php if ($vehicle_count > 1 && false): ?>
                                                            <span class="badge bg-secondary ms-1" style="font-size: 0.7em; padding: 0.2em 0.4em;"><?php echo $i + 1; ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="vehicle-plate">
                                                        <!-- 隐藏车牌图标 - 已禁用显示 -->
                                                        <!-- <i class="bi bi-card-text text-muted me-1"></i> -->
                                                        <span class="badge bg-warning text-dark border" style="font-size: 0.75em; padding: 0.25em 0.5em;">
                                                            <?php echo $license_plate ?: '无车牌'; ?>
                                                        </span>
                                                    </div>
                                                    <div class="vehicle-driver">
                                                        <!-- 隐藏司机图标 - 已禁用显示 -->
                                                        <!-- <i class="bi bi-person-circle text-info me-1"></i> -->
                                                        <span class="text-dark fw-bold" style="font-size: 0.85em;"><?php echo $driver_name; ?></span>
                                                    </div>
                                                    <?php if (!empty($driver_phone)): ?>
                                                    <div class="vehicle-driver-phone">
                                                        <i class="bi bi-telephone text-success me-1"></i>
                                                        <a href="tel:<?php echo $driver_phone; ?>" class="text-success fw-bold" style="font-size: 0.8em;"><?php echo $driver_phone; ?></a>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endfor; ?>
                                        </div>
                                    <?php else: ?>
                                        <!-- 未分配车辆时显示车型需求信息 -->
                                        <?php 
                                        // 解析车型需求信息
                                        $vehicle_requirements = parse_vehicle_requirements($transport['vehicle_requirements'] ?? '');
                                        if (!empty($vehicle_requirements)): 
                                        ?>
                                            <div class="vehicle-requirements-display">
                                                <i class="fas fa-car-side text-primary me-1"></i>
                                                <div>
                                                    <?php foreach ($vehicle_requirements as $req): ?>
                                                        <span class="badge bg-primary text-white mb-1 mr-1 d-inline-block">
                                                            <?php echo htmlspecialchars($req['type']); ?>
                                                            <?php if ($req['quantity'] >= 1): ?>
                                                                <span class="badge bg-white text-primary text-xs ml-1">x<?php echo $req['quantity']; ?></span>
                                                            <?php endif; ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="vehicle-info-empty">
                                                <span class="badge bg-warning text-dark">
                                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                                    未分配车辆
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <!-- 车辆信息显示区域结束 -->
                                </td>
                                <!-- 特殊要求列 - 显示特殊要求内容 -->
                                <?php if (!empty($transport['special_requirements'])): ?>
                                    <!-- 特殊要求列样式优化：亮黄色背景、红色加粗文字 - 开始 -->
                                    <td style="background-color: #fff3cd; color: #dc3545; font-weight: bold;">
                                        <div class="vehicle-requirements" style="white-space: normal; word-wrap: break-word; word-break: break-word;">
                                            <i class="fas fa-exclamation-triangle text-warning me-1"></i>
                                            <!-- 特殊要求内容：红色加粗显示设置 - 开始 -->
                                            <span class="text-danger fw-bold"><?php echo htmlspecialchars($transport['special_requirements']); ?></span>
                                            <!-- 特殊要求内容：红色加粗显示设置 - 结束 -->
                                        </div>
                                    </td>
                                    <!-- 特殊要求列样式优化：亮黄色背景、红色加粗文字 - 结束 -->
                                <?php else: ?>
                                    <td>
                                        <div class="vehicle-requirements" style="white-space: normal; word-wrap: break-word; word-break: break-word;">
                                            -
                                        </div>
                                    </td>
                                <?php endif; ?>
                                <!-- 特殊要求列结束 -->
                                <td>
                                    <div class="btn-group btn-group-sm" style="display: flex; justify-content: center; gap: 5px; width: 100%;">
                                        <?php
                                        $ids = explode(',', $transport['transport_ids']);
                                        $firstId = $ids[0] ?? 0;
                                        ?>
                                        <a href="edit_transport.php?id=<?php echo $firstId; ?>" 
                                           class="btn btn-primary btn-sm" title="编辑行程" style="margin: 0;">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <button type="button" 
                                                class="btn btn-success btn-sm" 
                                                title="复制行程信息"
                                                onclick="copyTransportInfo(event, '<?php echo $transport['main_id']; ?>')"
                                                style="margin: 0; display: none;">
                                            <i class="bi bi-clipboard"></i>
                                        </button>
                                        <button type="button" 
                                                class="btn btn-danger btn-sm" 
                                                title="删除行程"
                                                onclick="deleteTransport('<?php echo $transport['transport_ids']; ?>')"
                                                style="margin: 0;">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                    <!-- 在操作按钮下方显示状态信息 -->
                                    <div class="mt-2 text-center">
                                        <span class="badge bg-<?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                    </div>
                                </td>
                            </tr>
                        <?php 
                            endforeach; 
                            
                            // 开始：每天行程的往返程分析
                            $outbound_personnel = [];
                            $return_personnel = [];
                            $incomplete_trips = [];
                            
                            // 收集当天所有行程的人员信息
                            foreach ($date_transports as $transport) {
                                if ($transport['personnel_info']) {
                                    $personnel_data = explode(',', $transport['personnel_info']);
                                    foreach ($personnel_data as $data) {
                                        if (!empty($data)) {
                                            $parts = explode('|', $data);
                                            $name = trim($parts[0] ?? '');
                                            $department = trim($parts[1] ?? '未分配部门');
                                            
                                            if (!empty($name)) {
                                                $key = $name . '|' . $department;
                                                
                                                // 根据行程类型分类 - 优化判断逻辑
                                                $travel_type = strtolower(trim($transport['travel_type']));
                                                $departure = strtolower(trim($transport['departure_location'] ?? ''));
                                                $destination = strtolower(trim($transport['destination_location'] ?? ''));
                                                
                                                // 更精确的去程判断：包含"去"、"出发"、"前往"，或从本地出发
                                                $is_outbound = (
                                                    strpos($travel_type, '去') !== false || 
                                                    strpos($travel_type, '出发') !== false ||
                                                    strpos($travel_type, '前往') !== false ||
                                                    strpos($travel_type, '往') !== false ||
                                                    strpos($travel_type, '到') !== false ||
                                                    (empty($departure) && !empty($destination)) // 只有目的地
                                                );
                                                
                                                // 更精确的返程判断：包含"回"、"返"、"返程"、"返回"
                                                $is_return = (
                                                    strpos($travel_type, '回') !== false || 
                                                    strpos($travel_type, '返') !== false ||
                                                    strpos($travel_type, '返程') !== false ||
                                                    strpos($travel_type, '返回') !== false ||
                                                    strpos($travel_type, '到达') !== false
                                                );
                                                
                                                if ($is_outbound && !$is_return) {
                                                    $outbound_personnel[$key] = ['name' => $name, 'department' => $department];
                                                } elseif ($is_return && !$is_outbound) {
                                                    $return_personnel[$key] = ['name' => $name, 'department' => $department];
                                                } elseif ($is_outbound && $is_return) {
                                                    // 如果同时包含去程和返程关键词，根据出发地和目的地判断
                                                    if (!empty($departure) && !empty($destination)) {
                                                        // 简单的启发式判断：如果出发地是公司/基地等，则为去程
                                                        $home_keywords = ['酒店', '体育场', '体育馆', '机场', '高铁站'];
                                                        $is_from_home = false;
                                                        foreach ($home_keywords as $keyword) {
                                                            if (strpos($departure, $keyword) !== false) {
                                                                $is_from_home = true;
                                                                break;
                                                            }
                                                        }
                                                        
                                                        if ($is_from_home) {
                                                            $outbound_personnel[$key] = ['name' => $name, 'department' => $department];
                                                        } else {
                                                            $return_personnel[$key] = ['name' => $name, 'department' => $department];
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                            
                            // 分析有去程无回程的人员
                            foreach ($outbound_personnel as $key => $person) {
                                if (!isset($return_personnel[$key])) {
                                    $incomplete_trips['outbound_only'][] = $person;
                                }
                            }
                            
                            // 分析有回程无去程的人员
                            foreach ($return_personnel as $key => $person) {
                                if (!isset($outbound_personnel[$key])) {
                                    $incomplete_trips['return_only'][] = $person;
                                }
                            }
                            
                            // 添加调试信息：显示当天分析结果
                            $debug_info = [];
                            $debug_info['date'] = $date;
                            $debug_info['total_transports'] = count($date_transports);
                            $debug_info['outbound_count'] = count($outbound_personnel);
                            $debug_info['return_count'] = count($return_personnel);
                            $debug_info['outbound_only_count'] = count($incomplete_trips['outbound_only'] ?? []);
                            $debug_info['return_only_count'] = count($incomplete_trips['return_only'] ?? []);
                            
                            // 如果有不完整的行程，显示提示
                            if (!empty($incomplete_trips['outbound_only']) || !empty($incomplete_trips['return_only'])):
                        ?>
                            <!-- 开始：当天往返程不完整提示行 -->
                            <tr class="trip-analysis-row" style="background-color: #fff3cd; border-left: 4px solid #ffc107;">
                                <td colspan="8" style="padding: 10px 15px;">
                                    <div class="alert alert-warning mb-0" style="padding: 8px 12px; margin: 0; border: none; background: transparent;">
                                        <h6 class="alert-heading mb-2"><i class="bi bi-exclamation-triangle-fill me-2"></i>当天往返程分析 (<?php echo $date; ?>)</h6>
                                        <div class="small text-muted mb-2">
                                            去程: <?php echo $debug_info['outbound_count']; ?>人, 
                                            返程: <?php echo $debug_info['return_count']; ?>人, 
                                            总行程: <?php echo $debug_info['total_transports']; ?>条
                                        </div>
                                        <div class="mt-2">
                                            <?php if (!empty($incomplete_trips['outbound_only'])): ?>
                                                <div class="mb-1">
                                                    <span class="badge bg-danger me-2">有去程无回程</span>
                                                    <span class="text-dark">
                                                        <?php 
                                                        $names = [];
                                                        foreach ($incomplete_trips['outbound_only'] as $person) {
                                                            $display = htmlspecialchars($person['name']);
                                                            if (!empty($person['department']) && $person['department'] !== '未分配部门') {
                                                                $display .= ' <small class="text-muted">(' . htmlspecialchars($person['department']) . ')</small>';
                                                            }
                                                            $names[] = $display;
                                                        }
                                                        echo implode('、', $names);
                                                        ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($incomplete_trips['return_only'])): ?>
                                                <div class="mb-1">
                                                    <span class="badge bg-warning me-2">有回程无去程</span>
                                                    <span class="text-dark">
                                                        <?php 
                                                        $names = [];
                                                        foreach ($incomplete_trips['return_only'] as $person) {
                                                            $display = htmlspecialchars($person['name']);
                                                            if (!empty($person['department']) && $person['department'] !== '未分配部门') {
                                                                $display .= ' <small class="text-muted">(' . htmlspecialchars($person['department']) . ')</small>';
                                                            }
                                                            $names[] = $display;
                                                        }
                                                        echo implode('、', $names);
                                                        ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if (empty($incomplete_trips['outbound_only']) && empty($incomplete_trips['return_only'])): ?>
                                                <div class="text-success">
                                                    <i class="bi bi-check-circle-fill me-2"></i>
                                                    当天所有人员往返程完整，无异常情况
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <!-- 结束：当天往返程不完整提示行 -->
                        <?php endif; ?>
                        <!-- 结束：每天行程的往返程分析 -->
                        
                        <?php endforeach; ?>
                    </tbody>
                </table>
      </div>
        <?php endif; ?>
    </div>
</div>

<?php
// 开始：查询没有行程记录的人员信息
$no_transport_personnel = [];
try {
    // 获取当前项目ID
    $current_project_id = $_SESSION['project_id'] ?? 0;
    
    if ($current_project_id > 0) {
        // 检查transportation_passengers表是否存在
        $check_table_query = "SHOW TABLES LIKE 'transportation_passengers'";
        $check_stmt = $db->prepare($check_table_query);
        $check_stmt->execute();
        $passengers_table_exists = $check_stmt->fetchColumn();
        
        if ($passengers_table_exists) {
            // 使用transportation_passengers表的查询 - 修复：同时检查两种人员关联方式
            $no_transport_query = "
                SELECT DISTINCT p.id, p.name, p.id_card, d.name as department
                FROM personnel p 
                INNER JOIN project_department_personnel pdp ON p.id = pdp.personnel_id
                LEFT JOIN departments d ON pdp.department_id = d.id
                WHERE p.id NOT IN (
                    -- 检查transportation_passengers表中的关联
                    SELECT DISTINCT tp.personnel_id 
                    FROM transportation_passengers tp
                    INNER JOIN transportation_reports tr ON tp.transportation_report_id = tr.id
                    WHERE tr.project_id = :project_id
                    
                    UNION
                    
                    -- 检查transportation_reports表中的直接personnel_id关联
                    SELECT DISTINCT tr.personnel_id 
                    FROM transportation_reports tr
                    WHERE tr.project_id = :project_id AND tr.personnel_id IS NOT NULL
                )
                AND pdp.project_id = :project_id
                ORDER BY p.name ASC
            ";
        } else {
            // 使用transportation_reports表的personnel_id字段查询 - 修复：过滤NULL值
            $no_transport_query = "
                SELECT DISTINCT p.id, p.name, p.id_card, d.name as department
                FROM personnel p 
                INNER JOIN project_department_personnel pdp ON p.id = pdp.personnel_id
                LEFT JOIN departments d ON pdp.department_id = d.id
                WHERE p.id NOT IN (
                    SELECT DISTINCT tr.personnel_id 
                    FROM transportation_reports tr
                    WHERE tr.project_id = :project_id AND tr.personnel_id IS NOT NULL
                )
                AND pdp.project_id = :project_id
                ORDER BY p.name ASC
            ";
        }
        
        $stmt = $db->prepare($no_transport_query);
        $stmt->execute(['project_id' => $current_project_id]);
        $no_transport_personnel = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // 如果查询出错，静默处理，不显示错误
    error_log("查询无行程人员失败: " . $e->getMessage());
}
// 结束：查询没有行程记录的人员信息
?>

<?php if (!empty($no_transport_personnel)): ?>
<!-- 开始：无行程人员提示区域 -->
<div class="alert alert-info alert-dismissible fade show" role="alert" style="max-width: 800px; margin: 20px auto;">
    <h5 class="alert-heading">
        <i class="bi bi-info-circle-fill me-2"></i>
        人员行程统计提醒
    </h5>
    <p class="mb-2">
        <strong>以下 <?php echo count($no_transport_personnel); ?> 位人员在当前项目中没有任何行程记录：</strong>
    </p>
    <div class="row">
        <?php 
        $chunks = array_chunk($no_transport_personnel, 3);
        foreach ($chunks as $chunk): 
        ?>
            <div class="col-md-4">
                <ul class="list-unstyled mb-0">
                    <?php foreach ($chunk as $person): ?>
                        <li class="small">
                            <i class="bi bi-person text-muted me-1"></i>
                            <?php echo htmlspecialchars($person['name']); ?>
                            <?php if (!empty($person['department'])): ?>
                                <span class="text-muted">(<?php echo htmlspecialchars($person['department']); ?>)</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </div>
    <hr>
    <p class="mb-0 small text-muted">
        提示：这些人员可能还未安排行程，建议检查是否需要为他们创建行程记录。
        <!--<a href="personnel.php" class="alert-link" target="_blank">前往人员管理页面</a>-->
    </p>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="关闭"></button>
</div>
<!-- 结束：无行程人员提示区域 -->
<?php endif; ?>

<!-- 页面底部导出行程表按钮 - 居中显示 -->
<div class="text-center my-5">
    <!-- 新增：导出CSV按钮 - 根据当前排序导出 -->
                <button type="button" class="btn btn-success ms-3" onclick="exportTableToCSV('行程列表.csv')" title="根据当前排序顺序导出CSV">
                    <i class="bi bi-file-earmark-spreadsheet me-2"></i> 按当前排序导出CSV
                </button>
    <!-- 结束：导出行程表功能按钮 -->
</div>

<script>
function deleteTransport(idsStr) {
    // 获取行程ID
    const transportId = parseInt(idsStr);
    
    if (isNaN(transportId)) {
        alert('无效的行程ID');
        return;
    }
    
    // 第一次确认：询问用户是否确定要删除
    if (confirm('确定要删除这个行程吗？\n\n⚠️ 此操作将永久删除该行程记录及其相关信息！')) {
        // 第二次确认：要求用户输入确认文字
        const confirmationText = prompt('请输入 "确认删除" 以继续删除操作：');
        
        if (confirmationText === null) {
            // 用户点击了取消
            alert('已取消删除操作');
            return;
        }
        
        if (confirmationText !== '确认删除') {
            // 输入不正确
            alert('输入错误，已取消删除操作');
            return;
        }
        
        console.log('开始删除行程，ID:', transportId);
        
        // 显示删除进度提示
        const loadingAlert = document.createElement('div');
        loadingAlert.className = 'alert alert-info position-fixed';
        loadingAlert.style.cssText = 'top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 9999; min-width: 300px; text-align: center;';
        loadingAlert.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>正在删除行程，请稍候...';
        document.body.appendChild(loadingAlert);
        
        // 删除单个行程
        fetch('transport_enhanced.php?action=delete&id=' + transportId)
        .then(response => {
            // 移除加载提示
            if (document.body.contains(loadingAlert)) {
                document.body.removeChild(loadingAlert);
            }
            
            // 检查响应类型
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                return response.json();
            } else {
                return response.text().then(text => {
                    console.log('非JSON响应:', text);
                    throw new Error('服务器返回非JSON响应');
                });
            }
        })
        .then(data => {
            if (data.success) {
                alert('✅ 成功删除行程！');
                location.reload();
            } else {
                alert('❌ 删除失败：' + data.message);
            }
        })
        .catch(error => {
            // 移除加载提示
            if (document.body.contains(loadingAlert)) {
                document.body.removeChild(loadingAlert);
            }
            
            console.error('删除错误:', error);
            alert('❌ 删除过程中出现错误：' + error.message);
        });
    }
}

// 导出行程表
    function exportTransportList() {
        const url = new URL('export_transport_html.php', window.location.href);
        
        // 获取当前筛选参数
        const dateFilter = document.querySelector('input[name="date"]').value;
        const typeFilter = document.querySelector('select[name="type"]').value;
        const statusFilter = document.querySelector('select[name="status"]').value;
        
        if (dateFilter) url.searchParams.set('date', dateFilter);
        if (typeFilter && typeFilter !== '') url.searchParams.set('type', typeFilter);
        if (statusFilter && statusFilter !== '') url.searchParams.set('status', statusFilter);
        
        // 打开新窗口下载
        window.open(url.toString(), '_blank');
    }

    // 新增：表格导出为CSV功能 - 根据当前页面排序顺序导出
function exportTableToCSV(filename) {
    var table = document.querySelector('.table-responsive table');
    if (!table) {
        alert('未找到行程表格');
        return;
    }

    var rows = Array.from(table.querySelectorAll('tbody tr'));
    // 按日期排序功能 - 使用当前页面排序参数
    // 收集所有数据行（包括日期分隔行），并按日期分组
    var dateGroups = [];
    var currentDate = null;
    var currentGroup = [];
    rows.forEach(function(row) {
        if (row.classList.contains('date-divider')) {
            // 提取日期字符串
            var dateText = row.querySelector('.date-main')?.innerText || '';
            currentDate = dateText;
            if (currentGroup.length > 0) {
                dateGroups.push({ date: currentDate, rows: currentGroup });
            }
            currentGroup = [row];
        } else {
            currentGroup.push(row);
        }
    });
    if (currentGroup.length > 0 && currentDate) {
        dateGroups.push({ date: currentDate, rows: currentGroup });
    }

    // 获取当前页面排序参数
    var urlParams = new URLSearchParams(window.location.search);
    var sortOrder = urlParams.get('sort') || 'desc'; // 默认降序

    // 根据当前排序参数排序日期组
    dateGroups.sort(function(a, b) {
        // 日期格式为 yyyy/mm/dd
        var ad = a.date.replace(/\//g, '-');
        var bd = b.date.replace(/\//g, '-');
        if (sortOrder === 'asc') {
            return ad.localeCompare(bd); // 升序排序
        } else {
            return bd.localeCompare(ad); // 降序排序
        }
    });

    var headerRow = ['序号', '时间', '部门', '乘车人', '乘客数', '交通类型', '出发', '到达', '车辆信息', '特殊要求'];
    var csv = [headerRow.join(',')];

    dateGroups.forEach(function(group) {
        group.rows.forEach(function(row) {
            if (row.classList.contains('date-divider')) {
                var dateText = row.querySelector('.date-main')?.innerText || '';
                var weekdayText = row.querySelector('.date-weekday')?.innerText || '';
                var countText = row.querySelector('.date-count')?.innerText || '';
                var currentDateInfo = `=== ${dateText} ${weekdayText} ${countText} ===`;
                csv.push('');
                var dividerRow = [
                    '', '', '', currentDateInfo, '', '', '', '', '', ''
                ];
                csv.push(dividerRow.join(','));
                csv.push('');
                return;
            }
            if (row.classList.contains('date-spacer') || row.classList.contains('trip-analysis-row')) return;
            var cols = row.querySelectorAll('td');
            if (cols.length < 8) return;

            // 提取乘车人信息
            var passengerCell = cols[2];
            var departmentArr = [];
            var nameArr = [];
            if (passengerCell) {
                var deptGroups = passengerCell.querySelectorAll('.department-group');
                if (deptGroups.length > 0) {
                    deptGroups.forEach(function(group) {
                        var deptTag = group.querySelector('.dept-tag');
                        var namesList = group.querySelector('.names-list');
                        if (deptTag && namesList) {
                            var deptName = deptTag.childNodes[0].nodeValue.trim();
                            var countMatch = deptTag.querySelector('.count-badge');
                            var count = 0;
                            if (countMatch) {
                                var countText = countMatch.innerText.match(/\d+/);
                                if (countText) count = countText[0];
                            } else {
                                count = namesList.innerText.split('、').length;
                            }
                            if (deptName) departmentArr.push(deptName + '（' + count + '）');
                            var names = namesList.innerText.replace(/\s+/g, ' ').trim();
                            if (names) nameArr.push(names);
                        }
                    });
                } else {
                    var txt = passengerCell.innerText.replace(/\s+/g, ' ').trim();
                    if (txt) nameArr.push(txt);
                }
            }

            // 提取出发地和到达地
            var routeCell = cols[5];
            var departure = '';
            var destination = '';
            if (routeCell) {
                var routeDivs = routeCell.querySelectorAll('div');
                if (routeDivs.length >= 2) {
                    departure = routeDivs[0].innerText.replace(/^出发:/, '').trim();
                    destination = routeDivs[1].innerText.replace(/^到达:/, '').trim();
                } else {
                    var routeText = routeCell.innerText.replace(/\s+/g, ' ').trim();
                    var match = routeText.match(/出发:([^\s]+)\s*到达:([^\s]+)/);
                    if (match) {
                        departure = match[1];
                        destination = match[2];
                    }
                }
            }

            // 提取车辆信息
            var vehicleCell = cols[6];
            var vehicleInfoArr = [];
            if (vehicleCell) {
                var vehicleItems = vehicleCell.querySelectorAll('.vehicle-multi-item');
                if (vehicleItems.length > 0) {
                    vehicleItems.forEach(function(item) {
                        var number = item.querySelector('.vehicle-number')?.innerText.trim() || '';
                        var plate = item.querySelector('.vehicle-plate .badge')?.innerText.trim() || '';
                        var driver = item.querySelector('.vehicle-driver')?.innerText.trim() || '';
                        var info = number;
                        if (plate) info += ' ' + plate;
                        if (driver) info += ' ' + driver;
                        vehicleInfoArr.push(info);
                    });
                } else {
                    var reqDisplay = vehicleCell.querySelector('.vehicle-requirements-display');
                    if (reqDisplay) {
                        vehicleInfoArr.push(reqDisplay.innerText.replace(/\s+/g, ' ').trim());
                    } else {
                        var emptyBadge = vehicleCell.querySelector('.vehicle-info-empty .badge');
                        if (emptyBadge) {
                            vehicleInfoArr.push(emptyBadge.innerText.replace(/\s+/g, ' ').trim());
                        } else {
                            vehicleInfoArr.push(vehicleCell.innerText.replace(/\s+/g, ' ').trim());
                        }
                    }
                }
            }

            var rowData = [];
            // 序号列：由于排序可能影响序号显示，直接从表格中获取当前序号
            rowData.push(cols[0]?.innerText.replace(/\s+/g, ' ').trim() || ''); // 序号
            rowData.push(cols[1]?.innerText.replace(/\s+/g, ' ').trim() || ''); // 时间
            rowData.push(departmentArr.join('\n')); // 部门分行
            rowData.push(nameArr.join('\n')); // 乘车人分行
            rowData.push(cols[3]?.innerText.replace(/\s+/g, ' ').trim() || ''); // 乘客数
            rowData.push(cols[4]?.innerText.replace(/\s+/g, ' ').trim() || ''); // 交通类型
            rowData.push(departure); // 出发
            rowData.push(destination); // 到达
            rowData.push(vehicleInfoArr.join('\n')); // 车辆信息分行
            rowData.push(cols[7]?.innerText.replace(/\s+/g, ' ').trim() || ''); // 特殊要求

            rowData = rowData.map(function(text, idx) {
                text = text.replace(/"/g, '""');
                // 部门、乘车人、车辆信息如有换行，强制加引号
                if (idx === 2 || idx === 3 || idx === 8) {
                    if (text.indexOf(',') !== -1 || text.indexOf('\n') !== -1) {
                        text = '"' + text + '"';
                    }
                } else {
                    if (text.indexOf(',') !== -1 || text.indexOf('\n') !== -1) {
                        text = '"' + text + '"';
                    }
                }
                return text;
            });

            csv.push(rowData.join(','));
        });
    });

    var csvContent = csv.join('\r\n');
    var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    if (window.navigator.msSaveOrOpenBlob) {
        window.navigator.msSaveOrOpenBlob(blob, filename);
    } else {
        var link = document.createElement("a");
        var url = URL.createObjectURL(blob);
        link.setAttribute("href", url);
        link.setAttribute("download", filename);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        setTimeout(function() {
            URL.revokeObjectURL(url);
        }, 100);
    }
}

// 新增：复制行程信息到剪贴板功能
function copyTransportInfo(event, transportId) {
    // 阻止事件冒泡，避免触发行点击事件
    event.stopPropagation();
    
    // 强制显示调试信息
    console.log('=== 复制行程信息功能调试 ===');
    console.log('函数已触发');
    console.log('事件对象:', event);
    console.log('事件目标:', event.target);
    console.log('接收到的行程ID:', transportId);
    console.log('当前时间:', new Date().toLocaleString());
    console.log('浏览器信息:', navigator.userAgent);
    console.log('安全上下文:', window.isSecureContext);
    console.log('Clipboard API支持:', !!navigator.clipboard);
    
    // 通过AJAX获取行程详细信息
    fetch('get_transport_info.php?id=' + transportId)
        .then(response => response.json())
        .then(transportData => {
            // 检查必要数据是否存在
            console.log('开始数据验证...');
            if (!transportData) {
                console.error('❌ 传输数据为空');
                alert('❌ 复制失败：传输数据为空');
                return;
            }
            
            // 验证数据完整性
            console.log('数据完整性检查:');
            console.log('- 时间字段:', transportData.time, '类型:', typeof transportData.time);
            console.log('- 出发地:', transportData.departure, '类型:', typeof transportData.departure);
            console.log('- 目的地:', transportData.destination, '类型:', typeof transportData.destination);
            console.log('- 乘车人:', transportData.passengers, '类型:', typeof transportData.passengers, '长度:', transportData.passengers?.length);
            console.log('- 车辆:', transportData.vehicles, '类型:', typeof transportData.vehicles, '长度:', transportData.vehicles?.length);
            
            // 构建要复制的文本内容
            let textToCopy = '';
            console.log('开始构建文本内容...');
            
            // 添加时间、出发地和目的地
            console.log('阶段1：添加时间、出发地和目的地');
            if (transportData.time && transportData.departure && transportData.destination) {
                const routeInfo = `${transportData.time}  ${transportData.departure} - ${transportData.destination}\n`;
                textToCopy += routeInfo;
                console.log('✅ 添加了路线信息:', routeInfo.trim());
            } else {
                console.warn('⚠️ 缺少时间、出发地或目的地信息:', {
                    time: transportData.time,
                    departure: transportData.departure,
                    destination: transportData.destination
                });
            }
            
            // 添加乘车人信息
            console.log('阶段2：添加乘车人信息');
            if (transportData.passengers && transportData.passengers.length > 0) {
                const passengerText = transportData.passengers.join('+') + '\n\n';
                textToCopy += passengerText;
                console.log('✅ 添加了乘车人信息:', passengerText.trim());
                console.log('乘车人详细列表:', transportData.passengers);
            } else {
                console.warn('⚠️ 缺少乘车人信息');
            }
            
            // 添加车辆信息
            console.log('阶段3：添加车辆信息');
            if (transportData.vehicles && transportData.vehicles.length > 0) {
                console.log('开始处理车辆信息，共', transportData.vehicles.length, '辆车');
                transportData.vehicles.forEach((vehicle, index) => {
                    console.log(`处理第${index + 1}辆车:`, vehicle);
                    
                    // 如果有多辆车，添加车辆编号
                    if (transportData.vehicles.length > 1) {
                        textToCopy += `${index + 1}号车\n`;
                        console.log(`添加了多辆车编号: ${index + 1}号车`);
                    } else {
                        textToCopy += `${vehicle.fleetNumber || (index + 1)}号车\n`;
                        console.log(`添加了单车编号: ${vehicle.fleetNumber || (index + 1)}号车`);
                    }
                    
                    // 添加车型（使用vehicle_type_map映射）
                    if (vehicle.vehicleType) {
                        // 车型映射配置
                        const vehicleTypeMap = {
                            'car': '轿车',
                            'van': '商务车',
                            'minibus': '中巴车',
                            'bus': '大巴车',
                            'truck': '货车',
                            'other': '其他'
                        };
                        
                        const vehicleTypeName = vehicleTypeMap[vehicle.vehicleType] || vehicle.vehicleType;
                        textToCopy += `车型：${vehicleTypeName}\n`;
                        console.log(`添加了车型信息: ${vehicleTypeName}`);
                    } else {
                        console.warn(`第${index + 1}辆车缺少车型信息`);
                    }
                    
                    // 添加车牌
                    if (vehicle.plate && vehicle.plate !== '无车牌') {
                        textToCopy += `车牌：${vehicle.plate}\n`;
                        console.log(`添加了车牌信息: ${vehicle.plate}`);
                    } else {
                        console.log(`第${index + 1}辆车无车牌或车牌为'无车牌'`);
                    }
                    
                    // 添加司机和电话
                    if (vehicle.driver && vehicle.driver !== '未指定') {
                        let driverInfo = `司机：${vehicle.driver}`;
                        if (vehicle.phone) {
                            driverInfo += ` ${vehicle.phone}`;
                            console.log(`添加了司机和电话: ${vehicle.driver} ${vehicle.phone}`);
                        } else {
                            console.log(`添加了司机信息: ${vehicle.driver}`);
                        }
                        textToCopy += driverInfo + '\n';
                    } else {
                        console.log(`第${index + 1}辆车司机信息缺失或为'未指定'`);
                    }
                    
                    textToCopy += '\n';
                });
                console.log('✅ 车辆信息处理完成');
            } else {
                console.warn('⚠️ 缺少车辆信息');
            }
            
            // 阶段4：最终文本处理和复制操作
            console.log('阶段4：最终文本处理和复制操作');
            
            // 移除末尾的空行
            textToCopy = textToCopy.trim() + '\n';
            
            // 调试信息
            console.log('最终准备复制的文本内容:');
            console.log('=== 文本内容开始 ===');
            console.log(textToCopy);
            console.log('=== 文本内容结束 ===');
            console.log('文本长度:', textToCopy.length, '字符');
            console.log('文本字节大小:', new Blob([textToCopy]).size, '字节');
            
            // 检查文本内容是否为空
            const trimmedText = textToCopy.trim();
            console.log('修剪后的文本:', `'${trimmedText}'`);
            if (trimmedText === '\n' || trimmedText === '') {
                console.error('❌ 要复制的文本内容为空');
                console.error('原始数据:', transportData);
                alert('❌ 复制失败：没有可复制的信息');
                return;
            }
            
            // 复制到剪贴板
            console.log('开始尝试复制到剪贴板...');
            
            // 使用现代 Clipboard API
            if (navigator.clipboard && window.isSecureContext) {
                console.log('✅ 使用现代 Clipboard API');
                console.log('安全上下文:', window.isSecureContext);
                
                navigator.clipboard.writeText(textToCopy).then(() => {
                    console.log('✅ Clipboard API 复制成功');
                    showCopySuccess();
                }).catch(err => {
                    console.error('❌ Clipboard API 复制失败:', err);
                    console.error('错误名称:', err.name);
                    console.error('错误消息:', err.message);
                    console.log('回退到传统的 execCommand 方法...');
                    fallbackCopyTextToClipboard(textToCopy);
                });
            } else {
                console.warn('⚠️ 无法使用Clipboard API，原因:', {
                    hasClipboard: !!navigator.clipboard,
                    isSecureContext: window.isSecureContext,
                    protocol: window.location.protocol
                });
                console.log('使用传统的 execCommand 方法');
                fallbackCopyTextToClipboard(textToCopy);
            }
        })
        .catch(error => {
            console.error('获取行程信息失败:', error);
            alert('❌ 获取行程信息失败，请重试');
        });
}

// 回退的复制方法
function fallbackCopyTextToClipboard(text) {
    console.log('执行回退复制方法');
    const textArea = document.createElement("textarea");
    textArea.value = text;
    
    // 避免滚动到底部
    textArea.style.top = "0";
    textArea.style.left = "0";
    textArea.style.position = "fixed";
    textArea.style.opacity = "0";
    
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    
    try {
        const successful = document.execCommand('copy');
        console.log('execCommand 结果:', successful);
        if (successful) {
            showCopySuccess();
        } else {
            showCopyError();
        }
    } catch (err) {
        console.error('Fallback: Oops, unable to copy', err);
        showCopyError();
    }
    
    document.body.removeChild(textArea);
}


// 显示复制成功提示
function showCopySuccess() {
    console.log('显示复制成功提示');
    // 创建一个临时的提示元素
    const toast = document.createElement('div');
    toast.id = 'copy-success-toast';
    toast.innerHTML = `
        <div class="alert alert-success position-fixed" 
             style="top: 20px; right: 20px; z-index: 9999; min-width: 250px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
            <div class="d-flex align-items-center">
                <i class="bi bi-check-circle-fill me-2"></i>
                <div>
                    <strong>复制成功</strong><br>
                    <small>行程信息已复制到剪贴板</small>
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(toast);
    
    // 3秒后自动移除提示
    setTimeout(() => {
        if (toast.parentNode) {
            toast.parentNode.removeChild(toast);
        }
    }, 3000);
    
    // 同时显示一个简单的alert作为备份
    // alert('✅ 复制成功！行程信息已复制到剪贴板');
}

// 显示复制失败提示
function showCopyError() {
    console.log('显示复制失败提示');
    // 显示错误提示
    const errorToast = document.createElement('div');
    errorToast.id = 'copy-error-toast';
    errorToast.innerHTML = `
        <div class="alert alert-danger position-fixed" 
             style="top: 20px; right: 20px; z-index: 9999; min-width: 250px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
            <div class="d-flex align-items-center">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <div>
                    <strong>复制失败</strong><br>
                    <small>请手动复制或重试</small>
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(errorToast);
    
    // 3秒后自动移除错误提示
    setTimeout(() => {
        if (errorToast.parentNode) {
            errorToast.parentNode.removeChild(errorToast);
        }
    }, 3000);
    
    // 同时显示一个简单的alert作为备份
    alert('❌ 复制失败，请手动复制');
}


// 确保所有的函数都正确闭合
console.log('JavaScript functions loaded successfully');
</script>

<?php include 'includes/footer.php'; ?>

