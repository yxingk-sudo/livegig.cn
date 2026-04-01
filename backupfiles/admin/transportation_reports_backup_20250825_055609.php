<?php
session_start();
require_once '../config/database.php';
require_once 'page_functions.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();

$message = '';
$error = '';

// 处理操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_status') {
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
    } elseif ($action === 'delete') {
        $report_id = intval($_POST['report_id']);
        
        try {
            // 开始事务
            $db->beginTransaction();
            
            // 首先检查记录是否存在
            $check_query = "SELECT id FROM transportation_reports WHERE id = :id";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute(['id' => $report_id]);
            
            if (!$check_stmt->fetch()) {
                $error = '未找到该行程记录';
                $db->rollback();
                // 抛出异常，让外部的try-catch来处理
                throw new Exception('未找到该行程记录');
            }
            
            // 删除车辆分配记录
            $delete_assign_query = "DELETE FROM transportation_fleet_assignments WHERE transportation_report_id = :report_id";
            $delete_assign_stmt = $db->prepare($delete_assign_query);
            $delete_assign_stmt->execute(['report_id' => $report_id]);
            
            // 删除乘客关联记录（忽略不存在的表）
            try {
                $delete_passengers_query = "DELETE FROM transportation_passengers WHERE transportation_id = :report_id";
                $delete_passengers_stmt = $db->prepare($delete_passengers_query);
                $delete_passengers_stmt->execute(['report_id' => $report_id]);
            } catch (Exception $e) {
                // 表可能不存在，继续执行
            }
            
            // 删除行程记录
            $delete_query = "DELETE FROM transportation_reports WHERE id = :id";
            $delete_stmt = $db->prepare($delete_query);
            $delete_stmt->execute(['id' => $report_id]);
            
            // 提交事务
            $db->commit();
            
            $message = '行程记录已成功删除！';
        } catch (Exception $e) {
            // 回滚事务
            $db->rollback();
            $error = '删除行程记录失败：' . $e->getMessage();
        }
    }
}

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

// 获取报出行车列表 - 不合并行程，每个行程单独显示
$query = "SELECT 
    tr.id,
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
    pr.name as personnel_name,
    pr.gender,
    d.name as department_name,
    (SELECT COUNT(*) FROM transportation_passengers WHERE transportation_report_id = tr.id) + 
    CASE WHEN tr.personnel_id IS NOT NULL THEN 1 ELSE 0 END as total_passengers,
    (SELECT COUNT(*) FROM transportation_fleet_assignments WHERE transportation_report_id = tr.id) as vehicle_count
FROM transportation_reports tr
JOIN projects p ON tr.project_id = p.id
LEFT JOIN personnel pr ON tr.personnel_id = pr.id
LEFT JOIN departments d ON pr.department_id = d.id
$where_clause
ORDER BY tr.travel_date DESC, tr.departure_time DESC, tr.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$transportation_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取每个行程的所有乘车人信息（包括乘客和关联人员）
    foreach ($transportation_reports as &$report) {
        $passengers = [];
        
        // 获取transportation_passengers表中的乘客
        $passenger_query = "SELECT 
        tp.personnel_id,
        p.name as personnel_name,
        p.gender,
        d.name as department_name
    FROM transportation_passengers tp
    LEFT JOIN personnel p ON tp.personnel_id = p.id
    LEFT JOIN departments d ON p.department_id = d.id
    WHERE tp.transportation_report_id = :report_id";
    
    $passenger_stmt = $db->prepare($passenger_query);
    $passenger_stmt->execute([':report_id' => $report['id']]);
    $passengers = $passenger_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 如果有关联的人员，也添加到乘车人列表
    if (!empty($report['personnel_name'])) {
        $passengers[] = [
            'personnel_id' => null,
            'personnel_name' => $report['personnel_name'],
            'gender' => $report['gender'] ?? '',
            'department_name' => $report['department_name'] ?? '未分配部门'
        ];
    }
    
    $report['all_passengers'] = $passengers;
}

// 获取项目列表用于筛选
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



// 处理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>出行车管理 - 管理后台</title>
    <link href="assets/css/app.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .sidebar {
            background-color: #ffffff;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            z-index: 1000;
        }
        .main-content {
            margin-left: 250px;
            padding: 20px;
            min-height: 100vh;
        }
        @media (max-width: 768px) {
            .sidebar {
                width: 100% !important;
                position: relative !important;
                height: auto !important;
            }
            .main-content {
                margin-left: 0;
            }
        }
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
                <h1 class="h3 mb-0"><i class="bi bi-car-front"></i> <?php echo get_current_page_title(); ?></h1>
                <div>
                    <span class="text-muted me-3">
                        <i class="bi bi-person-circle"></i> 管理员: <?php echo htmlspecialchars($_SESSION['admin_username']); ?>
                    </span>
                    <a href="?logout=1" class="btn btn-sm btn-outline-danger">
                        <i class="bi bi-box-arrow-right"></i> 退出登录
                    </a>
                </div>
            </div>
                <!-- 页面标题和筛选 -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1><i class="bi bi-car-front"></i> <?php echo get_current_page_title(); ?></h1>
                    <div>
                        <a href="transportation_statistics.php" class="btn btn-primary">
                            <i class="bi bi-bar-chart-line"></i> 查看统计信息
                        </a>
                    </div>
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
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-funnel"></i> 筛选条件</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-2">
                                <label for="project_id" class="form-label">项目</label>
                                <select class="form-select" id="project_id" name="project_id">
                                    <option value="">所有项目</option>
                                    <?php foreach ($projects as $project): ?>
                                        <option value="<?php echo $project['id']; ?>" 
                                                <?php echo $project_id == $project['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($project['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="status" class="form-label">状态</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">所有状态</option>
                                    <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>待确认</option>
                                    <option value="confirmed" <?php echo $status_filter == 'confirmed' ? 'selected' : ''; ?>>已确认</option>
                                    <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>已取消</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="vehicle_type" class="form-label">交通类型</label>
                                <select class="form-select" id="vehicle_type" name="vehicle_type">
                                    <option value="">所有类型</option>
                                    <option value="接站" <?php echo $vehicle_type_filter == '接站' ? 'selected' : ''; ?>>接站</option>
                                    <option value="送站" <?php echo $vehicle_type_filter == '送站' ? 'selected' : ''; ?>>送站</option>
                                    <option value="混合交通安排" <?php echo ($vehicle_type_filter == '混合交通安排') ? 'selected' : ''; ?>>混合交通安排</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="travel_date" class="form-label">出行日期</label>
                                <input type="date" class="form-control" id="travel_date" name="travel_date" 
                                       value="<?php echo htmlspecialchars($date_filter); ?>">
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-2">
                                    <i class="bi bi-search"></i> 筛选
                                </button>
                                <a href="transportation_reports.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-clockwise"></i> 重置
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- 报出行车列表 -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">报出行车记录列表</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($transportation_reports)): ?>
                            <div class="text-center py-4">
                                <i class="bi bi-inbox display-1 text-muted"></i>
                                <h5 class="text-muted">暂无报出行车记录</h5>
                                <p class="text-muted">请先添加报出行车记录</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>项目</th>
                                            <th>人员</th>
                                            <th>交通类型</th>
                                    <th>车队编号</th>
                                    <th>车牌号码</th>
                                    <th>驾驶员</th>
                                    <th>驾驶员电话</th>
                                <th>出发地</th>
                                <th>目的地</th>
                                <th>出行日期</th>
                                <th>出行时间</th>
                                <th>乘客数量</th>
                                <th>特殊要求</th>
                                <th>车型需求</th>
                                                <th>车辆详情</th>
                                                <th>分配车辆</th>
                                                <th>状态</th>
                                                <th>报告人</th>
                                                <th>报告时间</th>
                                                <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($transportation_reports as $report): ?>
                                            <tr>
                                                <td>
                                                    <code><?php echo htmlspecialchars($report['project_code'] ?? ''); ?></code><br>
                                                    <small><?php echo htmlspecialchars($report['project_name'] ?? ''); ?></small>
                                                </td>
                                                <!-- 人员列 - 按部门分类显示乘车人员，样式参考user/quick_transport.php -->
                                                <td>
                                                    <?php if (!empty($report['all_passengers'])): ?>
                                                        <?php 
                                                        // 调试：显示原始数据
                                                        if (isset($_GET['debug'])) {
                                                            echo '<div class="alert alert-info p-2 mb-2 small">';
                                                            echo '<strong>原始乘客数据：</strong><br>';
                                                            foreach ($report['all_passengers'] as $idx => $passenger) {
                                                                echo ($idx + 1) . '. ' . htmlspecialchars($passenger['personnel_name']) . 
                                                                     ' - ' . htmlspecialchars($passenger['department_name'] ?? '未分配部门') . 
                                                                     ' - ' . htmlspecialchars($passenger['gender'] ?? '') . '<br>';
                                                            }
                                                            echo '</div>';
                                                        }
                                                        
                                                        // 按部门分组乘车人
                                                        $grouped_passengers = [];
                                                        foreach ($report['all_passengers'] as $passenger) {
                                                            $dept = !empty($passenger['department_name']) ? $passenger['department_name'] : '未分配部门';
                                                            if (!empty($passenger['personnel_name'])) {
                                                                $grouped_passengers[$dept][] = [
                                                                    'name' => htmlspecialchars($passenger['personnel_name']),
                                                                    'gender' => $passenger['gender'] ?? ''
                                                                ];
                                                            }
                                                        }
                                                        
                                                        // 调试：显示分组结果
                                                        if (isset($_GET['debug'])) {
                                                            echo '<div class="alert alert-warning p-2 mb-2 small">';
                                                            echo '<strong>分组结果：</strong><br>';
                                                            foreach ($grouped_passengers as $dept => $persons) {
                                                                echo htmlspecialchars($dept) . ': ' . count($persons) . '人<br>';
                                                            }
                                                            echo '</div>';
                                                        }
                                                        
                                                        // 显示分组后的乘车人信息，使用类似quick_transport.php的样式
                                                        foreach ($grouped_passengers as $department => $personnel): ?>
                                                            <div class="mb-2">
                                                                <small class="badge bg-secondary bg-opacity-10 text-secondary fw-semibold px-2 py-1 mb-1 d-inline-block">
                                                                    <?php echo htmlspecialchars($department); ?>
                                                                </small>
                                                                <div class="d-flex flex-wrap gap-1">
                                                                    <?php foreach ($personnel as $person): ?>
                                                                        <span class="badge bg-light text-dark border">
                                                                            <?php 
                                                                            $gender_icon = $person['gender'] === '男' ? '👨' : ($person['gender'] === '女' ? '👩' : '👤');
                                                                            echo $gender_icon . ' ' . $person['name'];
                                                                            ?>
                                                                        </span>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info">
                                                        <?php echo $report['travel_type']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="text-muted">-</span>
                                                </td>
                                                <td>
                                                    <span class="text-muted">-</span>
                                                </td>
                                                <td>
                                                    <span class="text-muted">-</span>
                                                </td>
                                                <td>
                                                    <span class="text-muted">-</span>
                                                </td>
                                                <td><?php echo htmlspecialchars($report['departure_location']); ?></td>
                                                <td><?php echo htmlspecialchars($report['destination_location']); ?></td>
                                                <td><?php echo date('Y-m-d', strtotime($report['travel_date'])); ?></td>
                                                <td><?php echo isset($report['departure_time']) ? substr($report['departure_time'], 0, 5) : '-'; ?></td>
                                                <td><?php echo $report['total_passengers']; ?></td>
                                                <td>
                                                    <?php if ($report['special_requirements']): ?>
                                                        <small class="text-muted" title="<?php echo htmlspecialchars($report['special_requirements']); ?>">
                                                            <?php echo mb_strlen($report['special_requirements']) > 20 ? mb_substr($report['special_requirements'], 0, 20) . '...' : $report['special_requirements']; ?>
                                                        </small>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                    // 显示车型需求
                                                    if ($report['vehicle_requirements']): 
                                                        $vehicle_type_map = [
                                                            'car' => '轿车',
                                                            'van' => '商务车',
                                                            'minibus' => '中巴车',
                                                            'bus' => '大巴车',
                                                            'truck' => '货车',
                                                            'other' => '其他'
                                                        ];
                                                        
                                                        // 解析JSON格式的车型需求
                                                        $decoded = json_decode($report['vehicle_requirements'], true);
                                                        if (is_array($decoded) && !empty($decoded)):
                                                            // 新的数据结构：键是车型，值包含数量和类型信息
                                                            foreach ($decoded as $vehicle_type => $vehicle_info):
                                                                if (isset($vehicle_type_map[$vehicle_type]) && isset($vehicle_info['quantity']) && $vehicle_info['quantity'] > 0):
                                                                    $display_name = $vehicle_type_map[$vehicle_type];
                                                                    $quantity = intval($vehicle_info['quantity']);
                                                    ?>
                                                                    <span class="badge bg-primary me-1 mb-1"><?php echo htmlspecialchars($display_name); ?> x<?php echo $quantity; ?></span>
                                                    <?php 
                                                                endif;
                                                            endforeach;
                                                        else:
                                                            // 兼容旧格式
                                                            echo '<span class="badge bg-secondary me-1 mb-1">' . htmlspecialchars(trim($report['vehicle_requirements'])) . '</span>';
                                                        endif;
                                                    else: 
                                                    ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                    if (isset($report['vehicle_details']) && $report['vehicle_details'] && $report['vehicle_details'] !== ''): 
                                                    ?>
                                                        <div class="mb-1">
                                                            <small class="text-muted"><?php echo htmlspecialchars($report['vehicle_details']); ?></small>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($report['vehicle_count'] == 0): ?>
                                                        <a href="assign_fleet.php?project_id=<?php echo $project_id; ?>&transportation_id=<?php echo $report['id']; ?>" 
                                                           class="btn btn-sm btn-outline-primary">
                                                           分配车辆
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-success"><i class="bi bi-check-circle"></i> 已分配 (<?php echo $report['vehicle_count']; ?>辆)</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $status_map[$report['status']]['class']; ?>">
                                                        <?php echo $status_map[$report['status']]['label']; ?>
                                                    </span>
                                                </td>
                                                <!-- 报告人列 -->
                                                <td>
                                                    <span class="text-muted">-</span>
                                                </td>
                                                <td><?php echo date('Y-m-d H:i', strtotime($report['created_at'])); ?></td>
                                                <td>
                                                    <div class="btn-group-vertical btn-group-sm" role="group">
                                                        <form method="POST" style="display: inline; margin-bottom: 2px;">
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="id" value="<?php echo $report['id']; ?>">
                                                            <select class="form-select form-select-sm" name="status" 
                                                                    onchange="this.form.submit()">
                                                                <option value="pending" <?php echo $report['status'] == 'pending' ? 'selected' : ''; ?>>待确认</option>
                                                                <option value="confirmed" <?php echo $report['status'] == 'confirmed' ? 'selected' : ''; ?>>已确认</option>
                                                                <option value="cancelled" <?php echo $report['status'] == 'cancelled' ? 'selected' : ''; ?>>已取消</option>
                                                            </select>
                                                        </form>
                                                        <a href="edit_transportation.php?id=<?php echo $report['id']; ?>" 
                                                           class="btn btn-outline-primary btn-sm">
                                                            <i class="bi bi-pencil"></i> 编辑
                                                        </a>
                                                        <form method="POST" style="display: inline; margin-bottom: 2px;" 
                                                            onsubmit="return confirm('确定要删除这条行程记录吗？此操作不可撤销！');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                                            <i class="bi bi-trash"></i> 删除
                                                        </button>
                                                    </form>
                                                        <?php if ($report['vehicle_count'] > 0): ?>
                                                            <form method="POST" style="display: inline;" 
                                                              onsubmit="return confirm('确定要取消该记录的车辆分配吗？这将释放所有已分配的车辆。')">
                                                            <input type="hidden" name="action" value="cancel_assignment">
                                                            <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                                                <i class="bi bi-x-circle"></i> 取消分配
                                                            </button>
                                                        </form>
                                                        <?php endif; ?>
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
            </div>
        </div>
    </div>

    <script src="assets/js/app.min.js"></script>
    <?php include 'includes/footer.php'; ?>
</body>
</html>