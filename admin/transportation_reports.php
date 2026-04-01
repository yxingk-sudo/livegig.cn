<?php
session_start();
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

// 设置页面标题和活动页面
$page_title = '出行车管理';
$active_page = 'transportation_reports';

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
        // 调试信息：记录原始数据
        error_log("[DEBUG] 原始车型需求数据: " . $vehicle_requirements_json);
        
        $requirements = json_decode($vehicle_requirements_json, true);
        if (!is_array($requirements)) {
            error_log("[DEBUG] JSON解析失败或非数组格式");
            return [];
        }
        
        error_log("[DEBUG] 解析后的数据: " . print_r($requirements, true));
        
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
        
        error_log("[DEBUG] 最终解析结果: " . print_r($result, true));
        return $result;
    } catch (Exception $e) {
        // 解析错误时返回空数组
        error_log("[DEBUG] 解析异常: " . $e->getMessage());
        return [];
    }
}

$database = new Database();
$db = $database->getConnection();

$message = '';
$error = '';

// 处理操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'assign_vehicle') {
        // 处理车辆分配
        $transportation_id = intval($_POST['transportation_id'] ?? 0);
        $fleet_id = intval($_POST['fleet_id'] ?? 0);
        $project_id = intval($_POST['project_id'] ?? 0);
        
        if ($transportation_id && $fleet_id && $project_id) {
            try {
                // 获取出行记录信息
                $query = "SELECT passenger_count FROM transportation_reports WHERE id = :id AND project_id = :project_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $transportation_id);
                $stmt->bindParam(':project_id', $project_id);
                $stmt->execute();
                $transport_record = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$transport_record) {
                    $error = '出行记录不存在';
                } else {
                    $passenger_count = $transport_record['passenger_count'];
                    
                    // 获取车辆座位数
                    $query = "SELECT seats FROM fleet WHERE id = :id AND project_id = :project_id AND status = 'active'";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':id', $fleet_id);
                    $stmt->bindParam(':project_id', $project_id);
                    $stmt->execute();
                    $fleet_record = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$fleet_record) {
                        $error = '车辆不存在或不可用';
                    } else {
                        $vehicle_seats = $fleet_record['seats'];
                        
                        // 检查车辆是否已分配给该出行记录
                        $query = "SELECT COUNT(*) FROM transportation_fleet_assignments 
                                 WHERE transportation_report_id = :transportation_id AND fleet_id = :fleet_id";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':transportation_id', $transportation_id);
                        $stmt->bindParam(':fleet_id', $fleet_id);
                        $stmt->execute();
                        
                        if ($stmt->fetchColumn() > 0) {
                            $error = '该车辆已分配给此出行记录';
                        } else {
                            // 计算已分配车辆的总座位数
                            $query = "SELECT COALESCE(SUM(f.seats), 0) as total_seats 
                                     FROM transportation_fleet_assignments tfa 
                                     JOIN fleet f ON tfa.fleet_id = f.id 
                                     WHERE tfa.transportation_report_id = :transportation_id";
                            $stmt = $db->prepare($query);
                            $stmt->bindParam(':transportation_id', $transportation_id);
                            $stmt->execute();
                            $result = $stmt->fetch(PDO::FETCH_ASSOC);
                            $current_total_seats = $result['total_seats'];
                            
                            // 计算剩余需要分配的乘客数量
                            $remaining_passengers = max(0, $passenger_count - $current_total_seats);
                            
                            // 分配车辆
                            $query = "INSERT INTO transportation_fleet_assignments (transportation_report_id, fleet_id) VALUES (:transportation_id, :fleet_id)";
                            $stmt = $db->prepare($query);
                            $stmt->bindParam(':transportation_id', $transportation_id);
                            $stmt->bindParam(':fleet_id', $fleet_id);
                            
                            if ($stmt->execute()) {
                                // 根据座位数和乘客数给出提示
                                if ($vehicle_seats > $remaining_passengers * 2) {
                                    $message = "车辆分配成功！提示：此车辆座位数({$vehicle_seats})远大于剩余乘客数量({$remaining_passengers})";
                                } elseif ($vehicle_seats < $remaining_passengers) {
                                    $message = "车辆分配成功！提示：此车辆座位数({$vehicle_seats})不足以容纳所有剩余乘客({$remaining_passengers})，需要继续分配其他车辆";
                                } else {
                                    $message = '车辆分配成功！';
                                }
                            } else {
                                $error = '车辆分配失败，请重试';
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                $error = '分配车辆失败：' . $e->getMessage();
            }
        } else {
            $error = '参数错误';
        }
    } elseif ($action === 'update_status') {
        $id = intval($_POST['id']);
        $status = $_POST['status'];
        
        if (in_array($status, ['pending', 'confirmed', 'cancelled'])) {
            $query = "UPDATE transportation_reports SET status = :status WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                $message = '报出行车状态更新成功！';
            } else {
                $error = '更新失败，请重试！';
            }
        }
    } elseif ($action === 'batch_confirm') {
        // 批量确认功能
        $ids = $_POST['ids'] ?? [];
        
        if (!empty($ids) && is_array($ids)) {
            try {
                $db->beginTransaction();
                
                $confirmed_count = 0;
                foreach ($ids as $id) {
                    $id = intval($id);
                    if ($id > 0) {
                        $query = "UPDATE transportation_reports SET status = 'confirmed' WHERE id = :id AND status = 'pending'";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':id', $id);
                        if ($stmt->execute()) {
                            $confirmed_count++;
                        }
                    }
                }
                
                $db->commit();
                $message = "成功确认 {$confirmed_count} 条记录！";
            } catch (Exception $e) {
                $db->rollback();
                $error = '批量确认失败：' . $e->getMessage();
            }
        } else {
            $error = '请选择要确认的记录！';
        }
    } elseif ($action === 'cancel_assignment') {
        $report_id = intval($_POST['report_id']);
        
        try {
            // 开始事务
            $db->beginTransaction();
            
            // 删除车辆分配记录 - 不需要project_id，因为transportation_report_id已经唯一
            $delete_query = "DELETE FROM transportation_fleet_assignments 
                           WHERE transportation_report_id = :report_id";
            $delete_stmt = $db->prepare($delete_query);
            $delete_stmt->bindParam(':report_id', $report_id);
            $delete_stmt->execute();
            
            
            // 提交事务
            $db->commit();
            
            $message = '车辆分配已成功取消！';
        } catch (Exception $e) {
            // 回滚事务
            $db->rollback();
            $error = '取消车辆分配失败：' . $e->getMessage();
        }
    } elseif ($action === 'cancel_single_assignment') {
        $report_id = intval($_POST['report_id']);
        $fleet_id = intval($_POST['fleet_id']);
        
        try {
            // 删除特定车辆分配记录
            $delete_query = "DELETE FROM transportation_fleet_assignments 
                           WHERE transportation_report_id = :report_id AND fleet_id = :fleet_id";
            $delete_stmt = $db->prepare($delete_query);
            $delete_stmt->bindParam(':report_id', $report_id);
            $delete_stmt->bindParam(':fleet_id', $fleet_id);
            
            if ($delete_stmt->execute() && $delete_stmt->rowCount() > 0) {
                $message = '车辆分配已成功取消！';
            } else {
                $error = '取消车辆分配失败：车辆分配记录不存在';
            }
        } catch (Exception $e) {
            $error = '取消车辆分配失败：' . $e->getMessage();
        }
    }
}

// 获取筛选参数
$filters = [
    'project_id' => $_GET['project_id'] ?? '',
    'status' => $_GET['status'] ?? '',
    'travel_date' => $_GET['travel_date'] ?? '',
    'vehicle_type' => $_GET['vehicle_type'] ?? ''
];

// 获取项目列表用于筛选
$projects_query = "SELECT id, name, code FROM projects ORDER BY name ASC";
$projects_stmt = $db->prepare($projects_query);
$projects_stmt->execute();
$projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);

// 只有选择了项目才查询数据
$transportation_reports = [];
if ($filters['project_id']) {
    // 构建查询条件
    $where_conditions = ['tr.project_id = :project_id'];
    $params = [':project_id' => $filters['project_id']];

    if ($filters['status']) {
        $where_conditions[] = "tr.status = :status";
        $params[':status'] = $filters['status'];
    }

    if ($filters['travel_date']) {
        $where_conditions[] = "tr.travel_date = :date";
        $params[':date'] = $filters['travel_date'];
    }

    if ($filters['vehicle_type']) {
        $where_conditions[] = "tr.travel_type = :vehicle_type";
        $params[':vehicle_type'] = $filters['vehicle_type'];
    }

    $where_clause = "WHERE " . implode(' AND ', $where_conditions);

    // 获取报出行车列表 - 使用与transport_list.php相同的乘车人信息获取方式
    $query = "SELECT 
        tr.id,
        tr.project_id,
        tr.travel_date,
        tr.travel_type,
        tr.departure_time,
        tr.departure_location,
        tr.destination_location,
        tr.status,
        tr.contact_phone,
        tr.special_requirements,
        tr.vehicle_requirements,
        tr.created_at,
        p.code as project_code,
        p.name as project_name,
        -- 使用GROUP_CONCAT获取所有乘车人信息，格式为：姓名|部门
        GROUP_CONCAT(DISTINCT CONCAT(COALESCE(pr_main.name, pr.name), '|', COALESCE(d_main.name, d.name, '未分配部门')) ORDER BY COALESCE(pr_main.name, pr.name)) as personnel_info,
        tr.passenger_count as total_passengers,
        (SELECT COUNT(*) FROM transportation_fleet_assignments WHERE transportation_report_id = tr.id) as vehicle_count,
        -- 获取车辆分配信息：车队编号、车牌号码、驾驶员、驾驶员电话（使用子查询避免重复）
        (SELECT GROUP_CONCAT(f_sub.fleet_number ORDER BY f_sub.fleet_number) 
         FROM transportation_fleet_assignments tfa_sub 
         JOIN fleet f_sub ON tfa_sub.fleet_id = f_sub.id 
         WHERE tfa_sub.transportation_report_id = tr.id) as fleet_numbers,
        (SELECT GROUP_CONCAT(COALESCE(f_sub.license_plate, '-') ORDER BY f_sub.fleet_number) 
         FROM transportation_fleet_assignments tfa_sub 
         JOIN fleet f_sub ON tfa_sub.fleet_id = f_sub.id 
         WHERE tfa_sub.transportation_report_id = tr.id) as license_plates,
        (SELECT GROUP_CONCAT(COALESCE(f_sub.vehicle_model, '-') ORDER BY f_sub.fleet_number) 
         FROM transportation_fleet_assignments tfa_sub 
         JOIN fleet f_sub ON tfa_sub.fleet_id = f_sub.id 
         WHERE tfa_sub.transportation_report_id = tr.id) as vehicle_models,
        (SELECT GROUP_CONCAT(COALESCE(f_sub.driver_name, '未分配') ORDER BY f_sub.fleet_number) 
         FROM transportation_fleet_assignments tfa_sub 
         JOIN fleet f_sub ON tfa_sub.fleet_id = f_sub.id 
         WHERE tfa_sub.transportation_report_id = tr.id) as driver_names,
        (SELECT GROUP_CONCAT(COALESCE(f_sub.driver_phone, '-') ORDER BY f_sub.fleet_number) 
         FROM transportation_fleet_assignments tfa_sub 
         JOIN fleet f_sub ON tfa_sub.fleet_id = f_sub.id 
         WHERE tfa_sub.transportation_report_id = tr.id) as driver_phones,
        -- 获取分配的车辆ID，用于单车辆取消分配
        (SELECT GROUP_CONCAT(f_sub.id ORDER BY f_sub.fleet_number) 
         FROM transportation_fleet_assignments tfa_sub 
         JOIN fleet f_sub ON tfa_sub.fleet_id = f_sub.id 
         WHERE tfa_sub.transportation_report_id = tr.id) as assigned_fleet_ids,
        -- 获取分配的车辆座位数
        (SELECT GROUP_CONCAT(f_sub.seats ORDER BY f_sub.fleet_number) 
         FROM transportation_fleet_assignments tfa_sub 
         JOIN fleet f_sub ON tfa_sub.fleet_id = f_sub.id 
         WHERE tfa_sub.transportation_report_id = tr.id) as vehicle_seats,
        -- 获取报告人信息（前台提交人）
        COALESCE(reporter.display_name, reporter.username, '未知') as reporter_name
    FROM transportation_reports tr
    JOIN projects p ON tr.project_id = p.id
    -- 获取关联人员信息
    LEFT JOIN personnel pr ON tr.personnel_id = pr.id
    LEFT JOIN project_department_personnel pdp ON pdp.personnel_id = pr.id AND pdp.project_id = tr.project_id
    LEFT JOIN departments d ON pdp.department_id = d.id
    -- 获取transportation_passengers表中的乘客信息
    LEFT JOIN transportation_passengers tp ON tr.id = tp.transportation_report_id
    LEFT JOIN personnel pr_main ON tp.personnel_id = pr_main.id
    LEFT JOIN project_department_personnel pdp_main ON pdp_main.personnel_id = pr_main.id AND pdp_main.project_id = tr.project_id
    LEFT JOIN departments d_main ON d_main.id = pdp_main.department_id
    -- 车辆分配信息已通过子查询获取，无需JOIN
    -- 获取报告人信息（前台提交人）
    LEFT JOIN project_users reporter ON tr.reported_by = reporter.id
    $where_clause
    GROUP BY tr.id, tr.project_id, tr.travel_date, tr.travel_type, tr.departure_time, tr.departure_location, tr.destination_location, tr.status, tr.contact_phone, tr.special_requirements, tr.vehicle_requirements, tr.created_at, p.code, p.name, pr.name, pr.gender, d.name, reporter.display_name, reporter.username
    ORDER BY tr.travel_date DESC, tr.departure_time DESC, tr.created_at DESC";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $transportation_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 新的查询已经包含了所有乘车人信息，无需额外处理

// 状态映射
$status_map = [
    'pending' => ['label' => '待确认', 'class' => 'warning'],
    'confirmed' => ['label' => '已确认', 'class' => 'success'],
    'cancelled' => ['label' => '已取消', 'class' => 'danger']
];

// 处理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// 引入头部
require_once 'includes/header.php';
?>

<style>
/* 改进的响应式表格样式 */
.transportation-table {
    font-size: 14px;
    table-layout: auto; /* 改为auto让表格自适应 */
    width: 100%;
    min-width: 1200px; /* 设置最小宽度确保内容完整显示 */
    border-collapse: collapse; /* 添加边框合并 */
    border: 1px solid #dee2e6; /* 添加表格边框 */
}

.transportation-table th {
    background-color: #f8f9fa;
    font-weight: 600;
    border: 1px solid #dee2e6; /* 添加表头边框 */
    white-space: nowrap;
    vertical-align: middle;
    padding: 8px 6px;
    position: relative; /* 添加相对定位 */
}

.transportation-table td {
    vertical-align: middle;
    border: 1px solid #dee2e6; /* 添加单元格边框 */
    padding: 8px 6px;
    word-wrap: break-word;
    position: relative; /* 添加相对定位 */
}

/* 响应式列宽设置 - 使用百分比和最小宽度 */
.col-time { width: 12%; min-width: 120px; }
.col-personnel { width: 22%; min-width: 200px; }
.col-vehicle { width: 16%; min-width: 160px; }
.col-route { width: 18%; min-width: 180px; }
.col-count { width: 6%; min-width: 60px; text-align: center; }
.col-requirements { width: 12%; min-width: 120px; }
.col-reporter { width: 8%; min-width: 80px; }
.col-actions { width: 10%; min-width: 120px; }

/* 路线信息样式 */
.route-display {
    font-size: 13px;
    line-height: 1.4;
}
.route-display .departure,
.route-display .arrival {
    display: block;
    margin: 2px 0;
}
.route-display strong {
    color: #495057;
    font-weight: 600;
}

/* 乘车人员样式 */
.personnel-info {
    font-size: 13px;
    line-height: 1.3;
}

/* 车辆信息列优化样式 */
.vehicle-info {
    font-size: 13px;
    line-height: 1.3;
}

.vehicle-info .vehicle-assigned {
    /* 移除背景色，使容器更简洁 */
    border: 1px solid #dee2e6; /* 添加边框 */
    border-radius: 0.375rem;
    padding: 0.5rem;
    margin-bottom: 0.5rem;
}

.vehicle-info .vehicle-item {
    background-color: #f8f9fa;
    border: 1px solid #dee2e6; /* 添加边框 */
    border-radius: 0.25rem;
    padding: 0.25rem;
    margin-bottom: 0.25rem;
    transition: all 0.2s ease;
}

.vehicle-info .vehicle-item:hover {
    background-color: #e9ecef;
}

.vehicle-info .vehicle-unassigned {
    background-color: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 0.375rem;
    padding: 0.5rem;
    margin-bottom: 0.5rem;
    text-align: center;
}

.vehicle-info .assign-btn {
    background: linear-gradient(135deg, #28a745, #20c997);
    border: none;
    box-shadow: 0 2px 4px rgba(40, 167, 69, 0.3);
    transition: all 0.2s ease;
}

.vehicle-info .assign-btn:hover {
    background: linear-gradient(135deg, #218838, #1ea085);
    box-shadow: 0 4px 8px rgba(40, 167, 69, 0.4);
    transform: translateY(-1px);
}

/* 移动端优化 */
@media (max-width: 768px) {
    .vehicle-info .vehicle-item {
        padding: 0.25rem;
    }
    
    .vehicle-info .vehicle-item .btn-sm {
        padding: 0.1rem 0.25rem;
        font-size: 10px;
    }
}

.vehicle-requirements-info {
    background-color: #e3f2fd;
    border: 1px solid #bbdefb;
    border-radius: 0.25rem;
    color: #1565c0;
    padding: 0.25rem;
}

/* 特殊要求样式 */
.special-req {
    font-size: 12px;
    background-color: #fff3cd;
    color: #856404;
    padding: 4px 6px;
    border-radius: 4px;
    word-wrap: break-word;
    border: 1px solid #ffeaa7; /* 添加边框 */
}

/* 人数显示 */
.passenger-count {
    display: inline-block;
    background-color: #e3f2fd;
    color: #1976d2;
    border: 1px solid #90caf9;
    border-radius: 50%;
    width: 32px;
    height: 32px;
    line-height: 30px;
    text-align: center;
    font-weight: bold;
    font-size: 14px;
}

/* 日期分隔行 - 用户偏好的底色设置 */
.date-divider td {
    background-color: #e9ecef !important;
    font-weight: 600;
    color: #495057;
    border: 1px solid #dee2e6; /* 添加边框 */
}

/* 表格响应式设置 */
.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

/* 侧边栏状态适应 */
body:not(.sidebar-collapsed) .transportation-table {
    /* 侧边栏展开状态 - 较小的表格宽度 */
    min-width: 1100px;
}

body.sidebar-collapsed .transportation-table {
    /* 侧边栏收起状态 - 可以使用更大的宽度 */
    min-width: 1300px;
}

/* 侧边栏收起时调整列宽 */
body.sidebar-collapsed .col-time { width: 10%; min-width: 120px; }
body.sidebar-collapsed .col-personnel { width: 20%; min-width: 220px; }
body.sidebar-collapsed .col-vehicle { width: 18%; min-width: 180px; }
body.sidebar-collapsed .col-route { width: 20%; min-width: 200px; }
body.sidebar-collapsed .col-count { width: 5%; min-width: 60px; }
body.sidebar-collapsed .col-requirements { width: 12%; min-width: 140px; }
body.sidebar-collapsed .col-reporter { width: 8%; min-width: 90px; }
body.sidebar-collapsed .col-actions { width: 10%; min-width: 120px; }

/* 确保复选框和按钮可点击 */
.transportation-table .form-check-input,
.transportation-table .btn,
.transportation-table .form-select {
    position: relative;
    z-index: 10;
    pointer-events: auto;
}

/* 批量操作栏的复选框确保可点击 */
.batch-operation-bar .form-check-input,
.batch-operation-bar .btn {
    position: relative;
    z-index: 20;
    pointer-events: auto;
}

/* 响应式优化 */
@media (max-width: 768px) {
    .transportation-table {
        font-size: 12px;
        min-width: 1000px; /* 移动端最小宽度 */
    }
    .transportation-table th,
    .transportation-table td {
        padding: 4px 3px;
    }
    /* 移动端不区分侧边栏状态 */
    .col-time { width: 12%; min-width: 100px; }
    .col-personnel { width: 22%; min-width: 180px; }
    .col-vehicle { width: 16%; min-width: 140px; }
    .col-vehicle { width: 16%; min-width: 140px; }
    .col-route { width: 18%; min-width: 160px; }
    .col-count { width: 6%; min-width: 50px; }
    .col-requirements { width: 12%; min-width: 100px; }
    .col-reporter { width: 8%; min-width: 80px; }
    .col-actions { width: 10%; min-width: 100px; }
}
</style>

<!-- 页面内容开始 -->
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <!-- 页面标题 -->


            <!-- 项目选择区域 -->
            <div class="project-selector">
                <h4>
                    <i class="bi bi-building me-2"></i>项目选择
                </h4>
                <form method="GET" id="projectForm">
                    <!-- 保持其他筛选条件 -->
                    <?php if ($filters['status']): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($filters['status']); ?>"><?php endif; ?>
                    <?php if ($filters['travel_date']): ?><input type="hidden" name="travel_date" value="<?php echo htmlspecialchars($filters['travel_date']); ?>"><?php endif; ?>
                    <?php if ($filters['vehicle_type']): ?><input type="hidden" name="vehicle_type" value="<?php echo htmlspecialchars($filters['vehicle_type']); ?>"><?php endif; ?>
                    
                    <div class="row align-items-end">
                        <div class="col-md-8">
                            <label for="project_id" class="form-label">请选择要管理的项目</label>
                            <select class="form-select" id="project_id" name="project_id" onchange="this.form.submit()">
                                <option value="">请选择项目...</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo $project['id']; ?>" 
                                            <?php echo $filters['project_id'] == $project['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($project['name']); ?>
                                        <?php if (!empty($project['code'])): ?>
                                            (<?php echo htmlspecialchars($project['code']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <?php if ($filters['project_id']): ?>
                                <div class="project-status selected">
                                    <i class="bi bi-check-circle-fill"></i>已选择项目
                                </div>
                            <?php else: ?>
                                <div class="project-status not-selected">
                                    <i class="bi bi-exclamation-circle-fill"></i>请先选择项目
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

            <!-- 筛选表单 -->
            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-funnel me-2"></i>筛选条件</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <!-- 项目选择已在顶部区域，此处移除避免重复 -->
                        <div class="col-md-3">
                            <label for="status" class="form-label">状态</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">所有状态</option>
                                <option value="pending" <?php echo $filters['status'] == 'pending' ? 'selected' : ''; ?>>待确认</option>
                                <option value="confirmed" <?php echo $filters['status'] == 'confirmed' ? 'selected' : ''; ?>>已确认</option>
                                <option value="cancelled" <?php echo $filters['status'] == 'cancelled' ? 'selected' : ''; ?>>已取消</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="vehicle_type" class="form-label">交通类型</label>
                            <select class="form-select" id="vehicle_type" name="vehicle_type">
                                <option value="">所有类型</option>
                                <option value="接站" <?php echo $filters['vehicle_type'] == '接站' ? 'selected' : ''; ?>>接站</option>
                                <option value="送站" <?php echo $filters['vehicle_type'] == '送站' ? 'selected' : ''; ?>>送站</option>
                                <option value="混合交通安排" <?php echo ($filters['vehicle_type'] == '混合交通安排') ? 'selected' : ''; ?>>混合交通安排</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="travel_date" class="form-label">出行日期</label>
                            <input type="date" class="form-control" id="travel_date" name="travel_date" 
                                   value="<?php echo htmlspecialchars($filters['travel_date']); ?>">
                        </div>
                        <!-- 隐藏的项目ID参数，保持当前选择的项目 -->
                        <?php if ($filters['project_id']): ?>
                            <input type="hidden" name="project_id" value="<?php echo htmlspecialchars($filters['project_id']); ?>">
                        <?php endif; ?>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary btn-sm me-2">
                                <i class="bi bi-search"></i> 筛选
                            </button>
                            <a href="transportation_reports.php<?php echo $filters['project_id'] ? '?project_id=' . htmlspecialchars($filters['project_id']) : ''; ?>" class="btn btn-secondary btn-sm">
                                <i class="bi bi-arrow-clockwise"></i> 重置
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- 记录列表 -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-table me-2"></i>
                        <?php 
                        // 显示当前筛选的项目名称
                        if ($filters['project_id']) {
                            $project_id = $filters['project_id'];
                            $filtered_projects = array_filter($projects, function($p) use ($project_id) {
                                return $p['id'] == $project_id;
                            });
                            $current_project = reset($filtered_projects);
                            if ($current_project) {
                                echo htmlspecialchars($current_project['name']) . ' 记录列表';
                            } else {
                                echo '记录列表';
                            }
                        } else {
                            echo '记录列表';
                        }
                        ?>
                    </h5>
                    <div>
                        <?php 
                        // 显示当前筛选的项目状态
                        if ($filters['project_id']) {
                            $project_id = $filters['project_id'];
                            $filtered_projects = array_filter($projects, function($p) use ($project_id) {
                                return $p['id'] == $project_id;
                            });
                            $current_project = reset($filtered_projects);
                            if ($current_project) {
                                echo '<span class="badge bg-primary">' . htmlspecialchars($current_project['name']) . '</span>';
                            }
                        } else {
                            echo '<span class="badge bg-secondary">所有项目</span>';
                        }
                        ?>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($transportation_reports)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-inbox display-5 text-muted"></i>
                            <h5 class="text-muted mt-3">暂无报出行车记录</h5>
                            <p class="text-muted small mb-0">请先添加报出行车记录</p>
                        </div>
                    <?php else: ?>
                        <!-- 添加批量操作按钮到表格头部 -->
                        <div class="d-flex justify-content-between align-items-center p-3 batch-operation-bar">
                            <div>
                                <button type="button" class="btn btn-outline-primary me-2" id="select-all-btn">
                                    <i class="bi bi-check-all"></i> 全选
                                </button>
                                <button type="button" class="btn btn-outline-secondary" id="deselect-all-btn">
                                    <i class="bi bi-x-circle"></i> 取消选择
                                </button>
                            </div>
                            <div>
                                <button type="button" class="btn btn-success btn-lg shadow-sm" id="batch-confirm-btn">
                                    <i class="bi bi-check-circle-fill"></i> 批量确认
                                </button>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 transportation-table">
                                <thead>
                                    <tr>
                                        <th class="col-time">出行时间</th>
                                        <th class="col-personnel">乘车人员</th>
                                        <th class="col-count">人数</th>
                                        <th class="col-route">出行路线</th>
                                        <th class="col-vehicle">车辆信息</th>
                                        <th class="col-requirements">特殊要求</th>
                                        <th class="col-reporter">报告信息</th>
                                        <th class="col-actions">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // 按日期分组处理行程数据
                                    $grouped_reports = [];
                                    foreach ($transportation_reports as $report) {
                                        $date = $report['travel_date'];
                                        if (!isset($grouped_reports[$date])) {
                                            $grouped_reports[$date] = [];
                                        }
                                        $grouped_reports[$date][] = $report;
                                    }
                                        
                                    // 按日期降序排序
                                    krsort($grouped_reports);
                                        
                                    foreach ($grouped_reports as $date => $date_reports): 
                                        $date_count = count($date_reports);
                                            
                                        foreach ($date_reports as $index => $report):
                                            // 添加日期分隔行（仅在每个日期组的第一行前插入）
                                            if ($index === 0):
                                        ?>
                                            <!-- 日期分隔行 -->
                                            <tr class="date-divider">
                                                <td colspan="8" class="text-center p-2">
                                                    <div class="card stat-card border-0 mb-0">
                                                        <div class="card-body py-2 px-3">
                                                            <span class="date-main fw-bold"><?= date('Y/m/d', strtotime($date)) ?></span>
                                                            <span class="date-weekday ms-2">(<?= [
                                                                'Monday' => '星期一',
                                                                'Tuesday' => '星期二', 
                                                                'Wednesday' => '星期三',
                                                                'Thursday' => '星期四',
                                                                'Friday' => '星期五',
                                                                'Saturday' => '星期六',
                                                                'Sunday' => '星期日'
                                                            ][date('l', strtotime($date))] ?>)</span>
                                                            <span class="date-count ms-2 badge bg-primary"><?= $date_count ?> 条记录</span>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php
                                                endif;
                                        ?>
                                            <tr>
                                                <!-- 出行时间列 -->
                                                <td class="col-time">
                                                    <div class="text-center">
                                                        <?php if (isset($report['departure_time']) && $report['departure_time'] !== '-'): ?>
                                                            <div class="fw-bold text-primary" style="font-size: 16px;">
                                                                <?php echo substr($report['departure_time'], 0, 5); ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="text-muted">-</div>
                                                        <?php endif; ?>
                                                        <div class="mt-1">
                                                            <span class="badge bg-info"><?php echo $report['travel_type']; ?></span>
                                                        </div>
                                                        <div class="mt-1">
                                                            <span class="badge bg-<?php echo $status_map[$report['status']]['class']; ?>">
                                                                <?php echo $status_map[$report['status']]['label']; ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <!-- 乘车人员列 -->
                                                <td class="col-personnel">
                                                    <div class="personnel-info">
                                                        <?php 
                                                        $department_groups = [];
                                                        if ($report['personnel_info']) {
                                                            $personnel_data = explode(',', $report['personnel_info']);
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
                                                        ksort($department_groups);
                                                        ?>
                                                         
                                                        <?php if (!empty($department_groups)): ?>
                                                            <?php foreach ($department_groups as $department => $names): ?>
                                                                <div class="mb-1">
                                                                    <div class="text-primary fw-bold"><?= $department ?>
                                                                        <span class="badge bg-secondary ms-1"><?= count($names) ?>人</span>
                                                                    </div>
                                                                    <div class="text-dark"><?= implode('、', $names) ?></div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        <?php else: ?>
                                                            <span class="text-muted">无乘车人</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <!-- 人数列 -->
                                                <td class="col-count">
                                                    <span class="passenger-count"><?php echo $report['total_passengers']; ?></span>
                                                </td>
                                                <!-- 出行路线列 -->
                                                <td class="col-route">
                                                    <div class="route-display">
                                                        <div class="departure">
                                                            <strong>出发:</strong> <?php echo htmlspecialchars($report['departure_location']); ?>
                                                        </div>
                                                        <div class="arrival">
                                                            <strong>到达:</strong> <?php echo htmlspecialchars($report['destination_location']); ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <!-- 车辆信息列 -->
                                                <td class="col-vehicle">
                                                    <div class="vehicle-info">
                                                        <?php 
                                                        $fleet_numbers = $report['fleet_numbers'] && $report['fleet_numbers'] !== '' ? explode(',', $report['fleet_numbers']) : [];
                                                        $license_plates = $report['license_plates'] && $report['license_plates'] !== '' ? explode(',', $report['license_plates']) : [];
                                                        $driver_names = $report['driver_names'] && $report['driver_names'] !== '' ? explode(',', $report['driver_names']) : [];
                                                        $driver_phones = $report['driver_phones'] && $report['driver_phones'] !== '' && $report['driver_phones'] !== '-' ? explode(',', $report['driver_phones']) : [];
                                                        $vehicle_models = $report['vehicle_models'] && $report['vehicle_models'] !== '' ? explode(',', $report['vehicle_models']) : [];
                                                        $fleet_ids = $report['assigned_fleet_ids'] && $report['assigned_fleet_ids'] !== '' ? explode(',', $report['assigned_fleet_ids']) : [];
                                                        $vehicle_seats = $report['vehicle_seats'] && $report['vehicle_seats'] !== '' ? explode(',', $report['vehicle_seats']) : [];
                                                         
                                                        // 始终显示已分配车辆信息和分配功能（支持同一行程分配多辆车）
                                                        ?>
                                                            <div class="vehicle-assigned">
                                                                <?php if (!empty($fleet_numbers) || !empty($license_plates) || !empty($driver_names)): ?>
                                                                    <div class="mb-2">
                                                                        <span class="badge bg-success me-1">
                                                                            <i class="bi bi-check-circle"></i> 已分配车辆
                                                                        </span>
                                                                        <span class="badge bg-primary"><?php echo $report['vehicle_count']; ?>辆</span>
                                                                    </div>
                                                        <?php
                                                            $vehicle_count = max(count($fleet_numbers), count($license_plates), count($driver_names));
                                                            for ($i = 0; $i < $vehicle_count; $i++):
                                                                $fleet_id = isset($fleet_ids[$i]) ? $fleet_ids[$i] : '';
                                                                $fleet_number = isset($fleet_numbers[$i]) && $fleet_numbers[$i] !== '-' ? $fleet_numbers[$i] : '';
                                                                $license_plate = isset($license_plates[$i]) && $license_plates[$i] !== '-' ? $license_plates[$i] : '';
                                                                $driver_name = isset($driver_names[$i]) && $driver_names[$i] !== '未分配' ? $driver_names[$i] : '';
                                                                $driver_phone = isset($driver_phones[$i]) && $driver_phones[$i] !== '-' ? $driver_phones[$i] : '';
                                                                $vehicle_model = isset($vehicle_models[$i]) && $vehicle_models[$i] !== '-' ? $vehicle_models[$i] : '';
                                                                $seats = isset($vehicle_seats[$i]) && $vehicle_seats[$i] !== '-' ? $vehicle_seats[$i] : '';
                                                        ?>
                                                                <div class="mb-1 p-1 border border-secondary rounded position-relative vehicle-item">
                                                                    <?php if ($fleet_number): ?>
                                                                        <div class="fw-bold text-primary"><?php echo htmlspecialchars($fleet_number); ?></div>
                                                                    <?php endif; ?>
                                                                    <?php if ($license_plate): ?>
                                                                        <div class="text-success"><?php echo htmlspecialchars($license_plate); ?></div>
                                                                    <?php endif; ?>
                                                                    <?php if ($seats): ?>
                                                                        <div class="text-muted small"><?php echo htmlspecialchars($seats); ?>座</div>
                                                                    <?php endif; ?>
                                                                    <?php if ($driver_name): ?>
                                                                        <div class="text-info"><?php echo htmlspecialchars($driver_name); ?></div>
                                                                    <?php endif; ?>
                                                                    <?php if ($driver_phone): ?>
                                                                        <div><a href="tel:<?php echo htmlspecialchars($driver_phone); ?>" class="text-warning text-decoration-none"><?php echo htmlspecialchars($driver_phone); ?></a></div>
                                                                    <?php endif; ?>
                                                                    <!-- 取消分配按钮 -->
                                                                    <?php if ($fleet_id): ?>
                                                                        <form method="POST" class="d-inline position-absolute top-0 end-0 me-1 mt-1" 
                                                                              onsubmit="return confirm('确定要取消分配车辆 <?php echo htmlspecialchars($fleet_number); ?> 吗？')">
                                                                            <input type="hidden" name="action" value="cancel_single_assignment">
                                                                            <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                                                            <input type="hidden" name="fleet_id" value="<?php echo $fleet_id; ?>">
                                                                            <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1" style="font-size: 12px; line-height: 1;" title="取消分配此车辆">
                                                                                <i class="bi bi-x-lg"></i>
                                                                            </button>
                                                                        </form>
                                                                    <?php endif; ?>
                                                                </div>
                                                        <?php 
                                                            endfor;
                                                        ?>
                                                                <?php endif; // 结束已分配车辆显示 ?>
                                                                
                                                                <?php if (!empty($report['vehicle_requirements'])): ?>
                                                                    <div class="mb-2">
                                                                        <span class="badge bg-info text-white mb-1">
                                                                            <i class="bi bi-info-circle"></i> 车型需求
                                                                        </span>
                                                                        <div class="vehicle-requirements-info py-2 px-2" style="font-size: 12px; line-height: 1.3;">
                                                                            <?php 
                                                                            // 解析车型需求
                                                                            $vehicle_requirements = parse_vehicle_requirements($report['vehicle_requirements']);
                                                                            if (!empty($vehicle_requirements)): 
                                                                                foreach ($vehicle_requirements as $req): 
                                                                            ?>
                                                                                <span class="badge bg-primary text-white me-1 mb-1">
                                                                                    <?php echo htmlspecialchars($req['type']); ?> x<?php echo $req['quantity']; ?>
                                                                                </span>
                                                                            <?php 
                                                                                endforeach;
                                                                            else:
                                                                                // 如果解析失败，显示原始数据作为备选
                                                                                echo '<div class="text-muted small">解析错误：' . htmlspecialchars($report['vehicle_requirements']) . '</div>';
                                                                            endif;
                                                                            ?>
                                                                        </div>
                                                                    </div>
                                                                <?php endif; ?>
                                                                
                                                                <div class="vehicle-assign-inline mt-2">
                                                                    <select class="form-select form-select-sm mb-2" 
                                                                            id="vehicle-select-<?php echo $report['id']; ?>"
                                                                            style="font-size: 11px;">
                                                                        <option value="">选择车辆...</option>
                                                                        <?php
                                                                        // 获取可用车辆列表
                                                                        $fleet_query = "SELECT id, fleet_number, vehicle_type, vehicle_model, license_plate, driver_name, seats 
                                                                                       FROM fleet 
                                                                                       WHERE project_id = :project_id AND status = 'active' 
                                                                                       ORDER BY fleet_number ASC";
                                                                        $fleet_stmt = $db->prepare($fleet_query);
                                                                        $fleet_stmt->bindParam(':project_id', $report['project_id']);
                                                                        $fleet_stmt->execute();
                                                                        $available_vehicles = $fleet_stmt->fetchAll(PDO::FETCH_ASSOC);
                                                                        
                                                                        foreach ($available_vehicles as $vehicle):
                                                                        ?>
                                                                            <option value="<?php echo $vehicle['id']; ?>">
                                                                                <?php echo htmlspecialchars($vehicle['fleet_number']); ?> - 
                                                                                <?php echo htmlspecialchars($vehicle['license_plate']); ?> - 
                                                                                <?php echo htmlspecialchars($vehicle['driver_name'] ?? '无司机'); ?> - 
                                                                                <?php echo htmlspecialchars($vehicle['seats']); ?>座
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                    <button type="button" 
                                                                            class="btn btn-success btn-sm assign-btn w-100" 
                                                                            onclick="assignVehicleInline(<?php echo $report['project_id']; ?>, <?php echo $report['id']; ?>)"
                                                                            style="font-size: 11px; padding: 6px 12px;">
                                                                        <i class="bi bi-plus-circle"></i> 添加车辆
                                                                    </button>
                                                                </div>
                                                            </div>
                                                    </div>
                                                </td>
                                                <!-- 特殊要求列 -->
                                                <td class="col-requirements">
                                                    <?php if ($report['special_requirements']): ?>
                                                        <div class="special-req">
                                                            <?php echo htmlspecialchars($report['special_requirements']); ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <!-- 报告信息列 -->
                                                <td class="col-reporter">
                                                    <div class="small">
                                                        <?php if ($report['reporter_name'] && $report['reporter_name'] !== '未知'): ?>
                                                            <div class="fw-bold text-secondary"><?php echo htmlspecialchars($report['reporter_name']); ?></div>
                                                        <?php else: ?>
                                                            <div class="text-muted">未知</div>
                                                        <?php endif; ?>
                                                        <div class="text-muted" style="font-size: 11px;">
                                                            <?php echo date('m-d H:i', strtotime($report['created_at'])); ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <!-- 操作列 -->
                                                <td class="col-actions">
                                                    <div class="d-flex flex-column gap-1">
                                                        <!-- 复选框 -->
                                                        <div class="form-check form-check-inline">
                                                            <input class="form-check-input report-checkbox" type="checkbox" 
                                                                   value="<?php echo $report['id']; ?>" 
                                                                   id="report_<?php echo $report['id']; ?>"
                                                                   data-status="<?php echo $report['status']; ?>">
                                                            <label class="form-check-label" for="report_<?php echo $report['id']; ?>" style="font-size: 11px;">选择</label>
                                                        </div>
                                                        
                                                        <!-- 状态修改 -->
                                                        <form method="POST" class="mb-1">
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="id" value="<?php echo $report['id']; ?>">
                                                            <select class="form-select form-select-sm" name="status" 
                                                                    onchange="this.form.submit()" style="font-size: 11px;">
                                                                <option value="pending" <?php echo $report['status'] == 'pending' ? 'selected' : ''; ?>>待确认</option>
                                                                <option value="confirmed" <?php echo $report['status'] == 'confirmed' ? 'selected' : ''; ?>>已确认</option>
                                                                <option value="cancelled" <?php echo $report['status'] == 'cancelled' ? 'selected' : ''; ?>>已取消</option>
                                                            </select>
                                                        </form>
                                                        
                                                        <!-- 操作按钮 -->
                                                        <div class="btn-group-vertical" role="group" style="font-size: 11px;">
                                                            <a href="edit_transportation.php?id=<?php echo $report['id']; ?>" 
                                                               class="btn btn-outline-primary btn-sm" style="padding: 2px 6px;">
                                                                <i class="bi bi-pencil"></i> 编辑
                                                            </a>
                                                            <button type="button" class="btn btn-outline-danger btn-sm" 
                                                                    onclick="deleteRecord(<?php echo $report['id']; ?>)" style="padding: 2px 6px;">
                                                                <i class="bi bi-trash"></i> 删除
                                                            </button>
                                                            <?php if ($report['vehicle_count'] > 0): ?>
                                                                <form method="POST" class="d-inline"
                                                                      onsubmit="return confirm('确定要取消该记录的车辆分配吗？这将释放所有已分配的车辆。')">
                                                                    <input type="hidden" name="action" value="cancel_assignment">
                                                                    <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                                                    <button type="submit" class="btn btn-outline-warning btn-sm" style="padding: 2px 6px;">
                                                                        <i class="bi bi-x-circle"></i> 取消分配
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 交通报告页面专用JavaScript -->
<script>
// 全选/取消选择功能
document.addEventListener('DOMContentLoaded', function() {
    const selectAllBtn = document.getElementById('select-all-btn');
    const deselectAllBtn = document.getElementById('deselect-all-btn');
    const batchConfirmBtn = document.getElementById('batch-confirm-btn');
    const checkboxes = document.querySelectorAll('.report-checkbox');
    
    // 确保按钮始终可用
    function ensureButtonsAccessible() {
        [selectAllBtn, deselectAllBtn, batchConfirmBtn].forEach(btn => {
            if (btn) {
                btn.style.pointerEvents = 'auto';
                btn.style.position = 'relative';
                btn.style.zIndex = '20';
            }
        });
        
        // 确保复选框可用
        checkboxes.forEach(checkbox => {
            checkbox.style.pointerEvents = 'auto';
            checkbox.style.position = 'relative';
            checkbox.style.zIndex = '10';
        });
    }
    
    // 初始化时确保按钮可用
    ensureButtonsAccessible();
    
    // 监听侧边栏切换事件
    window.addEventListener('sidebarCollapsed', function() {
        console.log('侧边栏已收起，重新初始化按钮');
        setTimeout(ensureButtonsAccessible, 100);
        updateTableLayout();
    });
    
    window.addEventListener('sidebarExpanded', function() {
        console.log('侧边栏已展开，重新初始化按钮');
        setTimeout(ensureButtonsAccessible, 100);
        updateTableLayout();
    });
    
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function(e) {
            e.preventDefault();
            console.log('全选按钮被点击');
            checkboxes.forEach(checkbox => {
                checkbox.checked = true;
            });
            updateBatchButton();
        });
    }
    
    if (deselectAllBtn) {
        deselectAllBtn.addEventListener('click', function(e) {
            e.preventDefault();
            console.log('取消选择按钮被点击');
            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            updateBatchButton();
        });
    }
    
    // 为每个复选框添加事件监听
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateBatchButton);
    });
    
    // 批量确认按钮事件
    if (batchConfirmBtn) {
        batchConfirmBtn.addEventListener('click', batchConfirm);
    }
    
    // 初始化按钮状态
    updateBatchButton();
});

// 更新表格布局
function updateTableLayout() {
    const table = document.querySelector('.transportation-table');
    if (table) {
        // 强制重新计算表格布局
        table.style.display = 'none';
        table.offsetHeight; // 触发重排
        table.style.display = 'table';
        
        console.log('表格布局已更新');
    }
}

// 更新批量操作按钮状态
function updateBatchButton() {
    const checkedBoxes = document.querySelectorAll('.report-checkbox:checked');
    const batchBtn = document.getElementById('batch-confirm-btn');
    
    if (batchBtn) {
        if (checkedBoxes.length === 0) {
            batchBtn.disabled = true;
            batchBtn.innerHTML = '<i class="bi bi-check-circle-fill"></i> 批量确认';
        } else {
            batchBtn.disabled = false;
            batchBtn.innerHTML = `<i class="bi bi-check-circle-fill"></i> 批量确认 (${checkedBoxes.length})`;
        }
    }
}

// 批量确认功能
function batchConfirm() {
    const checkedBoxes = document.querySelectorAll('.report-checkbox:checked');
    if (checkedBoxes.length === 0) {
        alert('请至少选择一条记录');
        return;
    }
    
    // 检查是否有非待确认状态的记录
    const pendingCount = Array.from(checkedBoxes).filter(cb => cb.dataset.status === 'pending').length;
    
    if (pendingCount === 0) {
        alert('所选记录中没有待确认状态的记录');
        return;
    }
    
    if (confirm(`确定要确认选中的 ${pendingCount} 条待确认记录吗？`)) {
        const ids = Array.from(checkedBoxes).map(cb => cb.value);
        
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="batch_confirm">
            ${ids.map(id => `<input type="hidden" name="ids[]" value="${id}">`).join('')}
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// 删除记录功能
function deleteRecord(id) {
    if (confirm('确定要删除这条出行记录吗？此操作不可撤销！')) {
        fetch('transportation_report_delete.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'id=' + id
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('删除成功', 'success');
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                showToast('删除失败：' + data.message, 'danger');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('删除失败，请重试', 'danger');
        });
    }
}

// 车辆分配功能 - 在当前页面完成分配
function assignVehicleInline(projectId, transportationId) {
    const selectElement = document.getElementById(`vehicle-select-${transportationId}`);
    const selectedVehicleId = selectElement.value;

    if (!selectedVehicleId) {
        alert('请选择一辆车辆进行分配！');
        return;
    }

    // 创建表单并提交
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="assign_vehicle">
        <input type="hidden" name="project_id" value="${projectId}">
        <input type="hidden" name="transportation_id" value="${transportationId}">
        <input type="hidden" name="fleet_id" value="${selectedVehicleId}">
    `;
    document.body.appendChild(form);
    form.submit();
}

// 修复筛选表单中的变量名
document.addEventListener('DOMContentLoaded', function() {
    // 修复状态筛选
    const statusSelect = document.getElementById('status');
    if (statusSelect) {
        const urlParams = new URLSearchParams(window.location.search);
        const statusValue = urlParams.get('status');
        if (statusValue) {
            statusSelect.value = statusValue;
        }
    }
    
    // 修复车辆类型筛选
    const vehicleTypeSelect = document.getElementById('vehicle_type');
    if (vehicleTypeSelect) {
        const urlParams = new URLSearchParams(window.location.search);
        const vehicleTypeValue = urlParams.get('vehicle_type');
        if (vehicleTypeValue) {
            vehicleTypeSelect.value = vehicleTypeValue;
        }
    }
    
    // 修复日期筛选
    const travelDateInput = document.getElementById('travel_date');
    if (travelDateInput) {
        const urlParams = new URLSearchParams(window.location.search);
        const travelDateValue = urlParams.get('travel_date');
        if (travelDateValue) {
            travelDateInput.value = travelDateValue;
        }
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>