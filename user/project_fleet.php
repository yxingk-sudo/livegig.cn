<?php
require_once '../config/database.php';
// 项目车辆分配查看页面
session_start();
// 引入权限中间件进行权限验证
require_once '../includes/PermissionMiddleware.php';
$database = new Database();
$db = $database->getConnection();
$middleware = new PermissionMiddleware($db);
$middleware->checkUserPagePermission('frontend:transport:fleet');


if (!isset($_SESSION['project_id'])) {
    header('Location: project_login.php');
    exit;
}

$projectId = $_SESSION['project_id'];
$projectName = $_SESSION['project_name'] ?? '当前项目';

// 初始化数据库连接
$database = new Database();
$db = $database->getConnection();

// 获取已分配给项目的车辆列表
$query = "
    SELECT f.id, f.fleet_number, f.license_plate, f.vehicle_model, f.vehicle_type, 
           f.driver_name, f.driver_phone, f.seats as capacity, f.status,
           CASE 
               WHEN f.vehicle_type = 'car' THEN '轿车'
               WHEN f.vehicle_type = 'van' THEN '商务车'
               WHEN f.vehicle_type = 'bus' THEN '大巴'
               WHEN f.vehicle_type = 'truck' THEN '货车'
               ELSE f.vehicle_type 
           END as type_name,
           COUNT(tfa.id) as assignment_count,
           GROUP_CONCAT(
               CONCAT(tr.travel_date, ' ', tr.departure_location, '→', tr.destination_location) 
               ORDER BY tr.travel_date DESC 
               SEPARATOR '|'
           ) as recent_assignments

    FROM fleet f
    LEFT JOIN transportation_fleet_assignments tfa ON f.id = tfa.fleet_id
    LEFT JOIN transportation_reports tr ON tfa.transportation_report_id = tr.id
    WHERE f.project_id = :project_id
    GROUP BY f.id
    ORDER BY f.fleet_number ASC
";

$stmt = $db->prepare($query);
$stmt->bindParam(':project_id', $projectId);
$stmt->execute();
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 统计信息
$stats = [
    'total' => count($vehicles),
    'available' => 0,
    'assigned' => 0,
    'busy' => 0
];

foreach ($vehicles as $vehicle) {
    if ($vehicle['status'] === 'available') {
        $stats['available']++;
    } elseif ($vehicle['status'] === 'assigned') {
        $stats['assigned']++;
    } elseif ($vehicle['status'] === 'busy') {
        $stats['busy']++;
    }
}

// 页面设置
$page_title = '项目车辆管理 - ' . $projectName;
$active_page = 'fleet';
$show_page_title = '项目车辆管理';
$page_icon = 'truck';
$page_action_text = '查看交通预订';
$page_action_url = 'transport_enhanced.php';

include('includes/header.php');
?>

<div class="row">
    <div class="col-12">
        <!-- 统计卡片 -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body text-center">
                        <h4 class="mb-1"><?php echo $stats['total']; ?></h4>
                        <small>车辆总数</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body text-center">
                        <h4 class="mb-1"><?php echo $stats['available']; ?></h4>
                        <small>可用车辆</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body text-center">
                        <h4 class="mb-1"><?php echo $stats['assigned']; ?></h4>
                        <small>已分配</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-danger text-white">
                    <div class="card-body text-center">
                        <h4 class="mb-1"><?php echo $stats['busy']; ?></h4>
                        <small>使用中</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- 车辆列表 -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">车辆列表</h5>
            </div>
            <div class="card-body">
                <?php if (empty($vehicles)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-truck text-muted" style="font-size: 3rem;"></i>
                        <h4 class="text-muted mt-3">暂无车辆分配给本项目</h4>
                        <p class="text-muted">点击下方按钮添加第一个交通预订</p>
                        <a href="transport_enhanced.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> 新增交通预订
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover compact-vehicle-table">
                            <thead class="table-dark">
                                <tr>
                                    <th class="vehicle-id-col">车队编号</th>
                                    <th class="vehicle-info-col">车辆信息</th>
                                    <th class="driver-info-col">司机信息</th>
                                    <th class="status-col text-center">状态</th>
                                    <th class="assignment-col text-center">分配统计</th>
                                    <th class="recent-tasks-col">最近任务</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <tr class="vehicle-row">
                                        <!-- 车队编号列 -->
                                        <td class="vehicle-id-info">
                                            <div class="d-flex align-items-center">
                                                <span class="vehicle-badge bg-primary text-white px-2 py-1 rounded">
                                                    <?php echo htmlspecialchars($vehicle['fleet_number']); ?>
                                                </span>
                                            </div>
                                        </td>
                                        
                                        <!-- 车辆信息列 -->
                                        <td class="vehicle-details">
                                            <div class="vehicle-info-compact">
                                                <div class="vehicle-plate fw-bold">
                                                    <i class="bi bi-credit-card me-1 text-primary"></i>
                                                    <?php 
                                                    // 显示车牌号码，添加分隔符并应用仿真实车牌样式
                                                    $license_plate = $vehicle['license_plate'];
                                                    if (!empty($license_plate)) {
                                                        // 检查是否已经有分隔符
                                                        if (mb_strpos($license_plate, '·') === false && mb_strlen($license_plate) > 2) {
                                                            // 在省份代码后添加分隔符
                                                            $display_plate = mb_substr($license_plate, 0, 2) . '·' . mb_substr($license_plate, 2);
                                                        } else {
                                                            $display_plate = $license_plate;
                                                        }
                                                    } else {
                                                        $display_plate = '-';
                                                    }
                                                    ?>
                                                    <span class="license-plate" title="<?php echo htmlspecialchars($vehicle['license_plate']); ?>"><?php echo htmlspecialchars($display_plate); ?></span>
                                                </div>
                                                <div class="vehicle-model text-muted small">
                                                    <i class="bi bi-truck me-1"></i>
                                                    <?php echo htmlspecialchars($vehicle['vehicle_model']); ?>
                                                </div>
                                                <div class="vehicle-seats text-muted small">
                                                    <i class="bi bi-people me-1"></i>
                                                    <?php echo $vehicle['capacity']; ?>人座
                                                </div>
                                            </div>
                                        </td>
                                        
                                        <!-- 司机信息列 -->
                                        <td class="driver-details">
                                            <div class="driver-info-compact">
                                                <div class="driver-name fw-bold">
                                                    <i class="bi bi-person me-1 text-success"></i>
                                                    <?php echo htmlspecialchars($vehicle['driver_name']); ?>
                                                </div>
                                                <div class="driver-phone text-muted small">
                                                    <i class="bi bi-telephone me-1"></i>
                                                    <?php echo htmlspecialchars($vehicle['driver_phone']); ?>
                                                </div>
                                            </div>
                                        </td>
                                        
                                        <!-- 状态列 -->
                                        <td class="status-info text-center">
                                            <?php 
                                            $type_name = [
                                                'car' => '轿车', 'sedan' => '轿车',
                                                'van' => '商务车', 'business' => '商务车', 
                                                'minibus' => '中巴', 'bus' => '大巴',
                                                'truck' => '货车', 'lorry' => '货车'
                                            ][strtolower($vehicle['vehicle_type'])] ?? $vehicle['vehicle_type'];
                                            
                                            $type_colors = [
                                                '轿车' => 'primary', '商务车' => 'success',
                                                '中巴' => 'warning', '大巴' => 'warning', 
                                                '货车' => 'danger'
                                            ];
                                            $type_color = $type_colors[$type_name] ?? 'info';
                                            
                                            $status_config = [
                                                'available' => ['class' => 'success', 'bg' => '#198754', 'icon' => 'check-circle-fill', 'text' => '可用'],
                                                'assigned' => ['class' => 'warning', 'bg' => '#ffc107', 'icon' => 'clock-fill', 'text' => '已分配'],
                                                'busy' => ['class' => 'danger', 'bg' => '#dc3545', 'icon' => 'exclamation-circle-fill', 'text' => '使用中'],
                                                'maintenance' => ['class' => 'danger', 'bg' => '#dc3545', 'icon' => 'tools', 'text' => '维修中']
                                            ];
                                            $config = $status_config[$vehicle['status']] ?? ['class' => 'secondary', 'bg' => '#6c757d', 'icon' => 'question-circle', 'text' => $vehicle['status']];
                                            ?>
                                            
                                            <!-- 车型徽章（移到状态上方，稍微放大） -->
                                            <div class="mb-2">
                                                <span class="badge bg-<?php echo $type_color; ?> vehicle-type-badge">
                                                    <i class="bi bi-truck me-1"></i><?php echo $type_name; ?>
                                                </span>
                                            </div>
                                            
                                            <!-- 状态徽章（醒目颜色，缩小尺寸，仅文字） -->
                                            <div class="status-badge-container">
                                                <span class="badge status-badge" style="background-color: <?php echo $config['bg']; ?> !important; color: white !important; border: none !important;">
                                                    <?php echo $config['text']; ?>
                                                </span>
                                            </div>
                                        </td>
                                        
                                        <!-- 分配统计列 -->
                                        <td class="assignment-stats text-center">
                                            <div class="stats-display">
                                                <div class="assignment-count fw-bold text-primary">
                                                    <i class="bi bi-calendar-check me-1"></i>
                                                    <?php echo $vehicle['assignment_count']; ?>次
                                                </div>
                                                <div class="text-muted small">分配任务</div>
                                            </div>
                                        </td>
                                        
                                        <!-- 最近任务列 -->
                                        <td class="recent-tasks">
                                            <?php if ($vehicle['recent_assignments']): ?>
                                                <?php 
                                                $assignments = array_filter(explode('|', $vehicle['recent_assignments']));
                                                $total_assignments = count($assignments);
                                                ?>
                                                <div class="task-container" data-vehicle-id="<?php echo $vehicle['id']; ?>">
                                                    <!-- 始终显示第一个任务 -->
                                                    <?php if (!empty($assignments[0])): ?>
                                                        <div class="task-item task-primary mb-1 p-2 bg-light rounded small border">
                                                            <?php 
                                                            $task_parts = explode(' ', $assignments[0], 2);
                                                            $task_date = $task_parts[0] ?? '';
                                                            $task_route = isset($task_parts[1]) ? $task_parts[1] : $assignments[0];
                                                            ?>
                                                            <div class="task-time fw-bold text-primary d-flex align-items-center">
                                                                <i class="bi bi-calendar3 me-1"></i>
                                                                <?php echo htmlspecialchars($task_date); ?>
                                                            </div>
                                                            <div class="task-route text-muted mt-1">
                                                                <i class="bi bi-arrow-right me-1 text-info"></i>
                                                                <?php 
                                                                if (strpos($task_route, '→') !== false) {
                                                                    $route_parts = explode('→', $task_route);
                                                                    $departure = isset($route_parts[0]) ? trim($route_parts[0]) : '';
                                                                    $destination = isset($route_parts[1]) ? trim($route_parts[1]) : '';
                                                                    echo '<span class="text-success">' . htmlspecialchars($departure) . '</span> → <span class="text-danger">' . htmlspecialchars($destination) . '</span>';
                                                                } else {
                                                                    echo '<span class="text-muted">' . htmlspecialchars($task_route) . '</span>';
                                                                }
                                                                ?>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <!-- 如果有多个任务，显示展开按钮和折叠内容 -->
                                                    <?php if ($total_assignments > 1): ?>
                                                        <div class="task-toggle-btn text-center">
                                                            <button class="btn btn-sm btn-outline-info btn-expand collapsed" 
                                                                    type="button"
                                                                    data-bs-toggle="collapse"
                                                                    data-bs-target="#additional-tasks-<?php echo $vehicle['id']; ?>"
                                                                    aria-expanded="false"
                                                                    aria-controls="additional-tasks-<?php echo $vehicle['id']; ?>"
                                                                    title="查看全部<?php echo $total_assignments; ?>个任务">
                                                                <i class="bi bi-chevron-down"></i>
                                                                <span class="ms-1">还有<?php echo $total_assignments - 1; ?>个</span>
                                                            </button>
                                                        </div>
                                                        
                                                        <!-- 可折叠的其他任务 -->
                                                        <div class="additional-tasks collapse" id="additional-tasks-<?php echo $vehicle['id']; ?>">
                                                            <?php 
                                                            $other_assignments = array_slice($assignments, 1);
                                                            foreach ($other_assignments as $assignment): 
                                                            ?>
                                                                <div class="task-item task-secondary mb-1 p-2 bg-white rounded small border border-secondary">
                                                                    <?php 
                                                                    $task_parts = explode(' ', $assignment, 2);
                                                                    $task_date = $task_parts[0] ?? '';
                                                                    $task_route = isset($task_parts[1]) ? $task_parts[1] : $assignment;
                                                                    ?>
                                                                    <div class="task-time fw-bold text-secondary d-flex align-items-center">
                                                                        <i class="bi bi-calendar3 me-1"></i>
                                                                        <?php echo htmlspecialchars($task_date); ?>
                                                                    </div>
                                                                    <div class="task-route text-muted mt-1">
                                                                        <i class="bi bi-arrow-right me-1 text-info"></i>
                                                                        <?php 
                                                                        if (strpos($task_route, '→') !== false) {
                                                                            $route_parts = explode('→', $task_route);
                                                                            $departure = isset($route_parts[0]) ? trim($route_parts[0]) : '';
                                                                            $destination = isset($route_parts[1]) ? trim($route_parts[1]) : '';
                                                                            echo '<span class="text-success">' . htmlspecialchars($departure) . '</span> → <span class="text-danger">' . htmlspecialchars($destination) . '</span>';
                                                                        } else {
                                                                            echo '<span class="text-muted">' . htmlspecialchars($task_route) . '</span>';
                                                                        }
                                                                        ?>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small d-flex align-items-center">
                                                    <i class="bi bi-calendar-x me-1"></i>暂无任务
                                                </span>
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

<style>
/* 表格整体优化 - 增大字体 */
.compact-vehicle-table {
    font-size: 1rem; /* 增大字体 */
    border-collapse: collapse; /* 合并边框 */
    width: 100%;
}

.compact-vehicle-table th {
    border: 1px solid #dee2e6; /* 添加表头边框 */
    border-top: none;
    font-weight: 600;
    font-size: 1rem; /* 增大表头字体 */
    white-space: nowrap;
    vertical-align: middle;
    padding: 10px 8px; /* 增大内边距 */
    background-color: #f8f9fa; /* 表头背景色 */
}

.compact-vehicle-table td {
    padding: 8px 6px; /* 增大内边距 */
    vertical-align: middle;
    border: 1px solid #dee2e6; /* 添加单元格边框 */
    line-height: 1.3; /* 调整行高 */
}

.compact-vehicle-table tr:first-child td {
    border-top: 1px solid #dee2e6;
}

.compact-vehicle-table tr:last-child td {
    border-bottom: 1px solid #dee2e6;
}

/* 车辆表格列宽度 */
.vehicle-id-col { width: 10%; }
.vehicle-info-col { width: 18%; }
.driver-info-col { width: 14%; }
.status-col { width: 13%; }
.assignment-col { width: 10%; }
.recent-tasks-col { width: 35%; }

/* 车辆信息样式 */
.vehicle-badge {
    font-family: 'Courier New', monospace;
    font-weight: bold;
    font-size: 0.9rem; /* 增大字体 */
    padding: 2px 6px; /* 增大内边距 */
}

.vehicle-info-compact {
    line-height: 1.3; /* 调整行高 */
}

.vehicle-plate {
    margin-bottom: 2px;
    font-size: 1rem; /* 增大字体 */
}

.vehicle-model {
    margin-bottom: 2px;
    font-size: 0.9rem; /* 增大字体 */
}

.vehicle-seats {
    margin-bottom: 0;
    font-size: 0.9rem; /* 增大字体 */
}

/* 司机信息样式 */
.driver-info-compact {
    line-height: 1.3; /* 调整行高 */
}

.driver-name {
    margin-bottom: 2px;
    font-size: 1rem; /* 增大字体 */
}

.driver-phone {
    margin-bottom: 0;
    font-size: 0.9rem; /* 增大字体 */
}

/* 任务信息样式 */
.task-container {
    max-width: 100%;
    padding: 4px; /* 增大内边距 */
}

.task-item {
    transition: all 0.2s ease;
    position: relative;
    margin-bottom: 4px; /* 增大间距 */
    padding: 6px 8px !important; /* 增大内边距 */
    border-radius: 4px;
    border: 1px solid #dee2e6; /* 添加边框 */
}

.task-primary {
    border-left: 3px solid #0d6efd !important;
}

.task-secondary {
    border-left: 3px solid #6c757d !important;
}

.task-item:hover {
    background-color: #e3f2fd !important;
    transform: translateX(2px);
}

.task-time {
    font-size: 0.9rem; /* 增大字体 */
    margin-bottom: 2px;
    font-weight: 600;
}

.task-route {
    font-size: 0.85rem; /* 增大字体 */
    line-height: 1.2;
}

/* 展开/折叠按钮样式 */
.task-toggle-btn {
    text-align: center;
    margin-top: 6px;
}

.btn-expand {
    font-size: 0.9rem; /* 增大字体 */
    padding: 4px 8px; /* 增大内边距 */
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
.additional-tasks {
    margin-top: 6px;
}

.additional-tasks .task-item {
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
.task-route .text-success {
    font-weight: 500;
}

.task-route .text-danger {
    font-weight: 500;
    color: #8b1538 !important;
}

/* 状态徽章样式 */
.status-info .badge {
    font-size: 0.9rem; /* 增大字体 */
    padding: 6px 12px; /* 增大内边距 */
}

/* 车型徽章样式 */
.vehicle-type-badge {
    font-size: 0.9rem !important; /* 增大字体 */
    padding: 6px 10px !important; /* 增大内边距 */
    font-weight: 600;
    border-radius: 15px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    transition: all 0.3s ease;
}

.vehicle-type-badge:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(0,0,0,0.15);
}

/* 状态徽章样式 */
.status-badge {
    font-size: 0.85rem !important; /* 增大字体 */
    padding: 6px 10px !important; /* 增大内边距 */
    font-weight: 600 !important;
    border-radius: 12px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.2) !important;
    border: none !important;
    transition: all 0.3s ease;
    text-align: center;
    letter-spacing: 0.3px;
}

.status-badge:hover {
    transform: scale(1.05);
    box-shadow: 0 2px 8px rgba(0,0,0,0.3) !important;
}

/* 状态容器 */
.status-badge-container {
    display: flex;
    justify-content: center;
    align-items: center;
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

/* 统计卡片优化 */
.stats-display {
    padding: 4px 0; /* 增大内边距 */
}

.assignment-count {
    font-size: 1.1rem; /* 增大字体 */
    margin-bottom: 2px;
}

.assignment-count .bi {
    font-size: 1rem; /* 增大图标 */
}

/* 统计卡片悬停效果 */
.card.bg-primary,
.card.bg-success,
.card.bg-warning,
.card.bg-danger {
    transition: transform 0.2s;
}

.card.bg-primary:hover,
.card.bg-success:hover,
.card.bg-warning:hover,
.card.bg-danger:hover {
    transform: translateY(-2px);
}

/* 响应式优化 */
@media (max-width: 1200px) {
    .compact-vehicle-table {
        font-size: 0.9rem; /* 响应式字体调整 */
    }
    
    .assignment-col {
        display: none;
    }
    
    .vehicle-id-col { width: 12%; }
    .vehicle-info-col { width: 22%; }
    .driver-info-col { width: 18%; }
    .status-col { width: 15%; }
    .recent-tasks-col { width: 33%; }
}

@media (max-width: 768px) {
    .compact-vehicle-table th,
    .compact-vehicle-table td {
        padding: 6px 4px; /* 响应式内边距调整 */
        font-size: 0.85rem;
    }
    
    .driver-info-col {
        display: none;
    }
    
    .vehicle-info-col { width: 35%; }
    .status-col { width: 20%; }
    .recent-tasks-col { width: 45%; }
}

/* 车牌号码样式 - 仿真实车牌 */
.license-plate {
    display: inline-block;
    padding: 4px 10px; /* 增大内边距 */
    font-size: 1rem; /* 增大字体 */
    font-weight: bold;
    color: #ffffff;
    background: #007bff;
    border: 2px solid #ffffff;
    border-radius: 4px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.2);
    min-width: 110px; /* 调整最小宽度 */
    text-align: center;
    font-family: 'Microsoft YaHei', Arial, sans-serif;
    letter-spacing: 1px;
    margin-top: 2px;
}

/* 车牌悬停效果 */
.license-plate:hover {
    box-shadow: 0 2px 5px rgba(0,0,0,0.3);
    transform: translateY(-1px);
    transition: all 0.2s ease;
}
