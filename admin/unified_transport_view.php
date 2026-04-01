<?php
// 统一的交通数据视图，打通用户端预定和管理端车辆分配
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

$database = new Database();
$db = $database->getConnection();

// 获取筛选参数
$project_id = $_GET['project_id'] ?? '';
$status_filter = $_GET['status'] ?? '';
$date_filter = $_GET['travel_date'] ?? '';
$vehicle_type_filter = $_GET['vehicle_type'] ?? '';

// 构建查询条件
$where_conditions = [];
$params = [];

if ($project_id) {
    $where_conditions[] = "tr.project_id = :project_id";
    $params[':project_id'] = $project_id;
}

if ($status_filter) {
    $where_conditions[] = "tr.status = :status";
    $params[':status'] = $status_filter;
}

if ($date_filter) {
    $where_conditions[] = "tr.travel_date = :date";
    $params[':date'] = $date_filter;
}

if ($vehicle_type_filter) {
    $where_conditions[] = "tr.travel_type = :vehicle_type";
    $params[':vehicle_type'] = $vehicle_type_filter;
}

$where_clause = $where_conditions ? "WHERE " . implode(' AND ', $where_conditions) : "";

// 获取统一的交通数据（包含车辆分配信息）
$query = "
    SELECT 
        tr.*,
        p.name as project_name,
        p.code as project_code,
        pr.name as personnel_name,
        pr.phone as personnel_phone,
        pu.username as reporter_username,
        GROUP_CONCAT(DISTINCT f.id) as assigned_vehicle_ids,
        GROUP_CONCAT(DISTINCT f.fleet_number) as assigned_vehicles,
        GROUP_CONCAT(DISTINCT CONCAT(f.vehicle_type, ' - ', f.vehicle_model)) as vehicle_details,
        GROUP_CONCAT(DISTINCT f.driver_name) as assigned_drivers,
        COUNT(DISTINCT f.id) as vehicle_count,
        SUM(f.seats) as total_seats,
        CASE 
            WHEN COUNT(f.id) > 0 THEN '已分配'
            ELSE '待分配'
        END as allocation_status
    FROM transportation_reports tr
    LEFT JOIN projects p ON tr.project_id = p.id
    LEFT JOIN personnel pr ON tr.personnel_id = pr.id
    LEFT JOIN project_users pu ON tr.reported_by = pu.id
    LEFT JOIN transportation_fleet_assignments tfa ON tr.id = tfa.transportation_report_id
    LEFT JOIN fleet f ON tfa.fleet_id = f.id
    $where_clause
    GROUP BY tr.id
    ORDER BY tr.travel_date ASC, tr.created_at DESC
";

$stmt = $db->prepare($query);
$stmt->execute($params);
$transport_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取可用车辆列表（用于分配）
$available_vehicles = [];
if (!empty($transport_records)) {
    $vehicle_query = "
        SELECT 
            f.*,
            CASE 
                WHEN tfa.id IS NULL THEN 'available'
                ELSE 'assigned'
            END as current_status
        FROM fleet f
        LEFT JOIN transportation_fleet_assignments tfa ON f.id = tfa.fleet_id
        LEFT JOIN transportation_reports tr ON tfa.transportation_report_id = tr.id AND tr.travel_date = :travel_date
        WHERE f.status = 'active'
        GROUP BY f.id
        ORDER BY f.fleet_number ASC
    ";
    
    $vehicle_stmt = $db->prepare($vehicle_query);
    $vehicle_stmt->execute([':travel_date' => $date_filter ?: date('Y-m-d')]);
    $available_vehicles = $vehicle_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 获取项目列表
$projects_query = "SELECT id, name, code FROM projects ORDER BY name ASC";
$projects_stmt = $db->prepare($projects_query);
$projects_stmt->execute();
$projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);

// 状态映射
$status_map = [
    'pending' => ['label' => '待确认', 'class' => 'warning'],
    'confirmed' => ['label' => '已确认', 'class' => 'success'],
    'cancelled' => ['label' => '已取消', 'class' => 'danger']
];

$allocation_map = [
    '已分配' => ['label' => '已分配', 'class' => 'success'],
    '待分配' => ['label' => '待分配', 'class' => 'warning']
];

// 处理车辆分配请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'assign_vehicle' && isset($_POST['transport_id']) && isset($_POST['vehicle_id'])) {
        $transport_id = intval($_POST['transport_id']);
        $vehicle_id = intval($_POST['vehicle_id']);
        
        try {
            // 检查是否已分配
            $check_query = "SELECT id FROM transportation_fleet_assignments WHERE transportation_report_id = ? AND fleet_id = ?";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([$transport_id, $vehicle_id]);
            
            if (!$check_stmt->fetch()) {
                // 分配车辆
                $assign_query = "INSERT INTO transportation_fleet_assignments (transportation_report_id, fleet_id) VALUES (?, ?)";
                $assign_stmt = $db->prepare($assign_query);
                $assign_stmt->execute([$transport_id, $vehicle_id]);
                
                // 更新状态为已确认
                $update_query = "UPDATE transportation_reports SET status = 'confirmed' WHERE id = ?";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->execute([$transport_id]);
                
                $_SESSION['message'] = '车辆分配成功！';
            } else {
                $_SESSION['error'] = '该车辆已分配给此行程！';
            }
        } catch (Exception $e) {
            $_SESSION['error'] = '分配失败：' . $e->getMessage();
        }
        header("Location: unified_transport_view.php");
        exit;
    }
    
    if ($action === 'remove_assignment' && isset($_POST['transport_id']) && isset($_POST['vehicle_id'])) {
        $transport_id = intval($_POST['transport_id']);
        $vehicle_id = intval($_POST['vehicle_id']);
        
        try {
            $remove_query = "DELETE FROM transportation_fleet_assignments WHERE transportation_report_id = ? AND fleet_id = ?";
            $remove_stmt = $db->prepare($remove_query);
            $remove_stmt->execute([$transport_id, $vehicle_id]);
            
            $_SESSION['message'] = '车辆分配已取消！';
        } catch (Exception $e) {
            $_SESSION['error'] = '取消分配失败：' . $e->getMessage();
        }
        header("Location: unified_transport_view.php");
        exit;
    }
}

$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);
$error = $_SESSION['error'] ?? '';
unset($_SESSION['error']);

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>统一交通管理 - 管理后台</title>
    <link href="assets/css/app.min.css" rel="stylesheet">
    <style>
        .allocation-status { font-weight: bold; }
        .vehicle-card { border-left: 4px solid #007bff; }
        .assignment-card { border-left: 4px solid #28a745; }
        .action-buttons { white-space: nowrap; }
    </style>
</head>
<body>
    <div class="d-flex">
        <div class="sidebar position-fixed top-0 start-0 h-100" style="width: 250px;">
            <?php require_once 'includes/sidebar.php'; ?>
        </div>
        
        <div class="main-content flex-grow-1">
            <!-- 顶部栏 -->
            <div class="d-flex justify-content-between align-items-center mb-4 p-3 bg-white rounded shadow-sm">
                <h1 class="h3 mb-0"><i class="bi bi-car-front"></i> 统一交通管理</h1>
                <div>
                    <span class="text-muted me-3">
                        <i class="bi bi-person-circle"></i> 管理员: <?php echo htmlspecialchars($_SESSION['admin_username']); ?>
                    </span>
                    <a href="?logout=1" class="btn btn-sm btn-outline-danger">
                        <i class="bi bi-box-arrow-right"></i> 退出登录
                    </a>
                </div>
            </div>

            <!-- 消息显示 -->
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
                                        <?php echo ($project_id == $project['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($project['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="status" class="form-label">状态</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">所有状态</option>
                                <option value="pending" <?php echo ($status_filter == 'pending') ? 'selected' : ''; ?>>待确认</option>
                                <option value="confirmed" <?php echo ($status_filter == 'confirmed') ? 'selected' : ''; ?>>已确认</option>
                                <option value="cancelled" <?php echo ($status_filter == 'cancelled') ? 'selected' : ''; ?>>已取消</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="travel_date" class="form-label">出行日期</label>
                            <input type="date" class="form-control" id="travel_date" name="travel_date" 
                                   value="<?php echo $date_filter; ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="vehicle_type" class="form-label">交通类型</label>
                            <select class="form-select" id="vehicle_type" name="vehicle_type">
                                <option value="">所有类型</option>
                                <option value="接站" <?php echo ($vehicle_type_filter == '接站') ? 'selected' : ''; ?>>接站</option>
                                <option value="送站" <?php echo ($vehicle_type_filter == '送站') ? 'selected' : ''; ?>>送站</option>
                                <option value="混合交通安排" <?php echo ($vehicle_type_filter == '混合交通安排') ? 'selected' : ''; ?>>混合交通安排</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i> 筛选
                            </button>
                            <a href="unified_transport_view.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-clockwise"></i> 重置
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- 统计信息 -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card bg-light">
                        <div class="card-body text-center">
                            <h5 class="card-title">总预订</h5>
                            <p class="card-text display-6"><?php echo count($transport_records); ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-success text-white">
                        <div class="card-body text-center">
                            <h5 class="card-title">已分配</h5>
                            <p class="card-text display-6">
                                <?php echo count(array_filter($transport_records, fn($r) => $r['vehicle_count'] > 0)); ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-warning text-white">
                        <div class="card-body text-center">
                            <h5 class="card-title">待分配</h5>
                            <p class="card-text display-6">
                                <?php echo count(array_filter($transport_records, fn($r) => $r['vehicle_count'] == 0)); ?>
                            </p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-info text-white">
                        <div class="card-body text-center">
                            <h5 class="card-title">总座位</h5>
                            <p class="card-text display-6">
                                <?php echo array_sum(array_column($transport_records, 'total_seats')); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 交通预订列表 -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-list"></i> 交通预订列表</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($transport_records)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-inbox display-1 text-muted"></i>
                            <h5 class="text-muted">暂无交通预订</h5>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>项目/人员</th>
                                        <th>出行信息</th>
                                        <th>乘客信息</th>
                                        <th>状态</th>
                                        <th>车辆分配</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transport_records as $record): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($record['project_code']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($record['project_name']); ?></small><br>
                                                <small><?php echo htmlspecialchars($record['personnel_name']); ?></small>
                                            </td>
                                            <td>
                                                <strong><?php echo $record['travel_type']; ?></strong><br>
                                                <small><?php echo $record['travel_date']; ?></small><br>
                                                <small><?php echo $record['departure_time'] ?: '未设置'; ?></small><br>
                                                <small><?php echo htmlspecialchars($record['departure_location']); ?> → <?php echo htmlspecialchars($record['destination_location']); ?></small>
                                            </td>
                                            <td>
                                                人数: <?php echo $record['passenger_count']; ?><br>
                                                电话: <?php echo htmlspecialchars($record['contact_phone']); ?><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($record['special_requirements']); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $status_map[$record['status']]['class']; ?>">
                                                    <?php echo $status_map[$record['status']]['label']; ?>
                                                </span><br>
                                                <span class="badge bg-<?php echo $allocation_map[$record['allocation_status']]['class']; ?>">
                                                    <?php echo $allocation_map[$record['allocation_status']]['label']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($record['vehicle_count'] > 0): ?>
                                                    <small><?php echo $record['assigned_vehicles']; ?></small><br>
                                                    <small class="text-muted"><?php echo $record['vehicle_details']; ?></small>
                                                <?php else: ?>
                                                    <span class="text-warning">待分配</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="action-buttons">
                                                <?php if ($record['vehicle_count'] == 0): ?>
                                                    <!-- 分配车辆按钮 -->
                                                    <button type="button" class="btn btn-sm btn-primary" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#assignModal<?php echo $record['id']; ?>">
                                                        <i class="bi bi-plus-circle"></i> 分配
                                                    </button>
                                                <?php else: ?>
                                                    <!-- 取消分配按钮 -->
                                                    <form method="POST" style="display: inline;" 
                                                          onsubmit="return confirm('确定要取消车辆分配吗？');">
                                                        <input type="hidden" name="action" value="remove_assignment">
                                                        <input type="hidden" name="transport_id" value="<?php echo $record['id']; ?>">
                                                        <input type="hidden" name="vehicle_id" value="<?php echo explode(',', $record['assigned_vehicle_ids'])[0]; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                                            <i class="bi bi-x-circle"></i> 取消
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                
                                                <a href="transportation_reports.php?project_id=<?php echo $record['project_id']; ?>" 
                                                   class="btn btn-sm btn-outline-info">
                                                    <i class="bi bi-eye"></i> 详情
                                                </a>
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

    <!-- 车辆分配模态框 -->
    <?php foreach ($transport_records as $record): ?>
        <?php if ($record['vehicle_count'] == 0): ?>
            <div class="modal fade" id="assignModal<?php echo $record['id']; ?>" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">分配车辆 - <?php echo $record['travel_type']; ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST">
                            <div class="modal-body">
                                <input type="hidden" name="action" value="assign_vehicle">
                                <input type="hidden" name="transport_id" value="<?php echo $record['id']; ?>">
                                
                                <div class="mb-3">
                                    <label class="form-label">选择车辆</label>
                                    <select class="form-select" name="vehicle_id" required>
                                        <option value="">请选择车辆</option>
                                        <?php 
                                        // 获取可用车辆（按日期筛选）
                                        $vehicle_query = "
                                            SELECT f.*, COUNT(tfa.id) as current_assignments
                                            FROM fleet f
                                            LEFT JOIN transportation_fleet_assignments tfa ON f.id = tfa.fleet_id
                                            LEFT JOIN transportation_reports tr ON tfa.transportation_report_id = tr.id 
                                                AND tr.travel_date = :travel_date
                                            WHERE f.status = 'active'
                                            GROUP BY f.id
                                            HAVING current_assignments = 0
                                            ORDER BY f.fleet_number ASC
                                        ";
                                        $vehicle_stmt = $db->prepare($vehicle_query);
                                        $vehicle_stmt->execute([':travel_date' => $record['travel_date']]);
                                        $available_vehicles = $vehicle_stmt->fetchAll(PDO::FETCH_ASSOC);
                                        
                                        foreach ($available_vehicles as $vehicle): 
                                        ?>
                                            <option value="<?php echo $vehicle['id']; ?>">
                                                <?php echo htmlspecialchars($vehicle['fleet_number']); ?> - 
                                                <?php echo htmlspecialchars($vehicle['vehicle_type']); ?> 
                                                (<?php echo $vehicle['seats']; ?>座)
                                                <?php echo htmlspecialchars($vehicle['driver_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="alert alert-info">
                                    <strong>出行信息：</strong><br>
                                    日期：<?php echo $record['travel_date']; ?><br>
                                    类型：<?php echo $record['travel_type']; ?><br>
                                    人数：<?php echo $record['passenger_count']; ?><br>
                                    路线：<?php echo htmlspecialchars($record['departure_location']); ?> → <?php echo htmlspecialchars($record['destination_location']); ?>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                                <button type="submit" class="btn btn-primary">确认分配</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <script src="assets/js/app.min.js"></script>
</body>
</html>