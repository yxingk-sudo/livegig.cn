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
$id = intval($_GET['id'] ?? 0);

// 车辆类型映射
$vehicle_type_map = [
    'car' => '轿车',
    'van' => '商务车',
    'minibus' => '中巴车',
    'bus' => '大巴车',
    'truck' => '货车',
    'other' => '其他'
];

// 获取报出行车记录
$query = "SELECT tr.*, p.name as project_name, GROUP_CONCAT(DISTINCT COALESCE(tp_p.name, pr.name) ORDER BY COALESCE(tp_p.name, pr.name)) as personnel_name 
          FROM transportation_reports tr
          JOIN projects p ON tr.project_id = p.id
          LEFT JOIN transportation_passengers tp ON tr.id = tp.transportation_report_id
LEFT JOIN personnel tp_p ON tp.personnel_id = tp_p.id
JOIN personnel pr ON tr.personnel_id = pr.id
          WHERE tr.id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();
$report = $stmt->fetch(PDO::FETCH_ASSOC);

// 获取车辆分配信息
$vehicle_assignments = [];
if ($report) {
    $assignment_query = "SELECT f.fleet_number, f.license_plate, f.vehicle_model, f.driver_name, f.driver_phone
                        FROM transportation_fleet_assignments tfa
                        JOIN fleet f ON tfa.fleet_id = f.id
                        WHERE tfa.transportation_report_id = :report_id
                        ORDER BY f.fleet_number";
    $assignment_stmt = $db->prepare($assignment_query);
    $assignment_stmt->bindParam(':report_id', $id);
    $assignment_stmt->execute();
    $vehicle_assignments = $assignment_stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (!$report) {
    $_SESSION['error'] = '未找到该报出行车记录';
    header("Location: transportation_reports.php");
    exit;
}

// 处理AJAX请求 - 必须放在所有输出之前
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 确保输出为JSON
    header('Content-Type: application/json');
    ini_set('display_errors', 0);
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_passenger') {
        // 添加同行人
        $personnel_id = intval($_POST['personnel_id']);
        
        try {
            // 检查人员是否已存在相同行程
            $check_query = "SELECT id FROM transportation_reports 
                           WHERE personnel_id = :personnel_id 
                           AND project_id = :project_id
                           AND travel_date = :travel_date
                           AND travel_type = :travel_type
                           AND departure_time = :departure_time
                           AND departure_location = :departure_location
                           AND destination_location = :destination_location";
            
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([
                ':personnel_id' => $personnel_id,
                ':project_id' => $_POST['project_id'],
                ':travel_date' => $_POST['travel_date'],
                ':travel_type' => $_POST['travel_type'],
                ':departure_time' => $_POST['departure_time'],
                ':departure_location' => $_POST['departure_location'],
                ':destination_location' => $_POST['destination_location']
            ]);
            
            if ($check_stmt->fetch()) {
                echo json_encode(['success' => false, 'message' => '该人员已存在相同行程记录']);
                exit;
            }
            
            // 获取当前管理员ID
            $admin_id = $_SESSION['admin_id'] ?? 1;
            
            // 插入新的行程记录
            $insert_query = "INSERT INTO transportation_reports 
                           (personnel_id, project_id, travel_date, travel_type, departure_time, 
                            departure_location, destination_location, status, vehicle_type, 
                            passenger_count, contact_phone, special_requirements, reported_by, created_at, updated_at)
                           VALUES 
                           (:personnel_id, :project_id, :travel_date, :travel_type, :departure_time,
                            :departure_location, :destination_location, :status, :vehicle_type,
                            :passenger_count, :contact_phone, :special_requirements, :reported_by, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
            
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->execute([
                ':personnel_id' => $personnel_id,
                ':project_id' => $_POST['project_id'],
                ':travel_date' => $_POST['travel_date'],
                ':travel_type' => $_POST['travel_type'],
                ':departure_time' => $_POST['departure_time'],
                ':departure_location' => $_POST['departure_location'],
                ':destination_location' => $_POST['destination_location'],
                ':status' => $_POST['status'],
                ':vehicle_type' => $_POST['vehicle_type'],
                ':passenger_count' => $_POST['passenger_count'],
                ':contact_phone' => $_POST['contact_phone'],
                ':special_requirements' => $_POST['special_requirements'],
                ':reported_by' => $admin_id
            ]);
            
            echo json_encode(['success' => true]);
            exit;
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => '数据库错误：' . $e->getMessage()]);
            exit;
        }
    } elseif ($action === 'delete_passenger') {
        // 删除同行人
        $passenger_id = intval($_POST['passenger_id']);
        
        try {
            // 验证是否可以删除（不能删除当前页面查看的记录）
            if ($passenger_id == $id) {
                echo json_encode(['success' => false, 'message' => '不能删除当前查看的记录']);
                exit;
            }
            
            $delete_query = "DELETE FROM transportation_reports WHERE id = :id";
            $delete_stmt = $db->prepare($delete_query);
            $delete_stmt->execute([':id' => $passenger_id]);
            
            echo json_encode(['success' => true]);
            exit;
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => '数据库错误：' . $e->getMessage()]);
            exit;
        }
    } else {
        // 原有的状态更新逻辑
        $status = $_POST['status'] ?? 'pending';
        
        try {
            $query = "UPDATE transportation_reports SET 
                      status = :status,
                      updated_at = CURRENT_TIMESTAMP
                      WHERE id = :id";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = '状态更新成功！';
                header("Location: transportation_reports.php");
                exit;
            } else {
                $error = '更新失败，请重试！';
            }
        } catch (PDOException $e) {
            $error = '数据库错误：' . $e->getMessage();
        }
    }
    exit; // 确保AJAX请求不会继续执行后面的HTML
}

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

// 引入header.php
// 权限验证
$database = new Database();
$db = $database->getConnection();
$middleware = new PermissionMiddleware($db);
$middleware->checkAdminPagePermission('backend:system:config');

require_once 'includes/header.php';
?>

            <!-- 顶部栏 -->


            <div class="container-fluid">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="transportation_reports.php">报出行车管理</a></li>
                        <li class="breadcrumb-item active">编辑车队信息</li>
                    </ol>
                </nav>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill"></i> <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-calendar-check"></i> 出行状态编辑</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="fleetForm">
                                    <div class="row">
                                        <div class="col-md-6">
                                        <h6 class="text-primary">基本信息</h6>
                                        <div class="mb-3">
                                            <label class="form-label">项目</label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($report['project_name']); ?>" readonly>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">同行程人员</label>
                                            <?php
                                            // 获取所有同行程人员 - 使用transportation_passengers关联表逻辑
                                            // 首先检查transportation_passengers表是否存在
                                            $table_exists = false;
                                            try {
                                                $db->query("SELECT 1 FROM transportation_passengers LIMIT 1");
                                                $table_exists = true;
                                            } catch (PDOException $e) {
                                                $table_exists = false;
                                            }
                                            
                                            if ($table_exists) {
                                                // 使用transportation_passengers表获取同行程人员
                                                $all_personnel_query = "
                                                    SELECT DISTINCT tr.id, pr.name as personnel_name, d.name as department_name
                                                    FROM transportation_reports tr
                                                    JOIN transportation_passengers tp ON tr.id = tp.transportation_report_id
                                                    JOIN personnel pr ON tp.personnel_id = pr.id
                                                    LEFT JOIN project_department_personnel pdp ON pr.id = pdp.personnel_id AND pdp.project_id = tr.project_id
                                                    LEFT JOIN departments d ON pdp.department_id = d.id
                                                    WHERE tr.id = :current_id
                                                    
                                                    UNION
                                                    
                                                    SELECT tr.id, pr.name as personnel_name, d.name as department_name
                                                    FROM transportation_reports tr
                                                    JOIN personnel pr ON tr.personnel_id = pr.id
                                                    LEFT JOIN project_department_personnel pdp ON pr.id = pdp.personnel_id AND pdp.project_id = tr.project_id
                                                    LEFT JOIN departments d ON pdp.department_id = d.id
                                                    WHERE tr.id = :current_id
                                                        AND NOT EXISTS (
                                                            SELECT 1 FROM transportation_passengers tp 
                                                            WHERE tp.transportation_report_id = tr.id
                                                        )
                                                    ORDER BY department_name, personnel_name
                                                ";
                                            } else {
                                                // 回退到旧逻辑：使用parent_transport_id
                                                $all_personnel_query = "
                                                    SELECT tr.id, pr.name as personnel_name, d.name as department_name
                                                    FROM transportation_reports tr
                                                    JOIN personnel pr ON tr.personnel_id = pr.id
                                                    LEFT JOIN project_department_personnel pdp ON pr.id = pdp.personnel_id AND pdp.project_id = tr.project_id
                                                    LEFT JOIN departments d ON pdp.department_id = d.id
                                                    WHERE (
                                                        tr.id = :current_id 
                                                        OR tr.parent_transport_id = (
                                                            SELECT COALESCE(parent_transport_id, id) 
                                                            FROM transportation_reports 
                                                            WHERE id = :current_id
                                                        )
                                                    )
                                                    ORDER BY d.name, pr.name
                                                ";
                                            }
                                            
                                            $all_personnel_stmt = $db->prepare($all_personnel_query);
                                            $all_personnel_stmt->execute([
                                                ':current_id' => $id
                                            ]);
                                            $all_personnel = $all_personnel_stmt->fetchAll(PDO::FETCH_ASSOC);
                                            
                                            $personnel_by_dept = [];
                                            foreach ($all_personnel as $person) {
                                                $dept_name = $person['department_name'] ?: '未分配部门';
                                                $personnel_by_dept[$dept_name][] = $person['personnel_name'];
                                            }
                                            ?>
                                            <div class="border rounded p-2 bg-light">
                                                <?php foreach ($personnel_by_dept as $dept => $names): ?>
                                                    <div class="mb-2">
                                                        <strong class="text-primary"><?php echo htmlspecialchars($dept); ?>：</strong>
                                                        <span><?php echo htmlspecialchars(implode('、', $names)); ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">已分配车辆</label>
                                            <div class="form-control" style="height: auto; min-height: 40px; white-space: normal; word-wrap: break-word;">
                                                <?php 
                                                if (!empty($vehicle_assignments)) {
                                                    foreach ($vehicle_assignments as $index => $assignment) {
                                                        $info_parts = [];
                                                        if (!empty($assignment['fleet_number'])) {
                                                            $info_parts[] = $assignment['fleet_number'];
                                                        }
                                                        if (!empty($assignment['license_plate'])) {
                                                            $info_parts[] = $assignment['license_plate'];
                                                        }
                                                        if (!empty($assignment['vehicle_model'])) {
                                                            $info_parts[] = $assignment['vehicle_model'];
                                                        }
                                                        if (!empty($assignment['driver_name'])) {
                                                            $info_parts[] = $assignment['driver_name'];
                                                        }
                                                        if (!empty($info_parts)) {
                                                            if ($index > 0) echo '<br>';
                                                            echo htmlspecialchars(implode(' ', $info_parts));
                                                        }
                                                    }
                                                } else {
                                                    echo '未分配车辆';
                                                }
                                                ?>
                                            </div>
                                        </div>

                                        <!-- 车型需求显示 -->
                                        <div class="mb-3">
                                            <label class="form-label">车型需求</label>
                                            <?php if ($report['vehicle_requirements']): ?>
                                                <div class="border rounded p-2 bg-light">
                                                    <?php 
                                                    $requirements = json_decode($report['vehicle_requirements'], true);
                                                    if (is_array($requirements) && !empty($requirements)): ?>
                                                        <?php foreach ($requirements as $vehicle_type => $vehicle_info): ?>
                                                            <?php if (isset($vehicle_type_map[$vehicle_type]) && isset($vehicle_info['quantity']) && $vehicle_info['quantity'] > 0): ?>
                                                                <span class="badge bg-primary me-1 mb-1">
                                                                    <?php echo htmlspecialchars($vehicle_type_map[$vehicle_type]); ?> x<?php echo intval($vehicle_info['quantity']); ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">无具体车型需求</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="border rounded p-2 bg-light">
                                                    <span class="text-muted">无车型需求</span>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                    </div>
                                        
                                        <div class="col-md-6">
                                        <h6 class="text-primary">行程信息</h6>
                                        <div class="mb-3">
                                            <label class="form-label">路线信息</label>
                                            <div class="border rounded p-2 bg-light" style="word-wrap: break-word; white-space: normal;">
                                                <div><strong>出发：</strong><?php echo htmlspecialchars($report['departure_location']); ?></div>
                                                <div><strong>到达：</strong><?php echo htmlspecialchars($report['destination_location']); ?></div>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">出行日期</label>
                                            <input type="text" class="form-control" value="<?php echo date('Y-m-d', strtotime($report['travel_date'])); ?>" readonly>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">出发时间</label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($report['departure_time']); ?>" readonly>
                                        </div>
                                    </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="status" class="form-label">状态</label>
                                                <select class="form-select" id="status" name="status">
                                                    <?php foreach ($status_map as $key => $info): ?>
                                                        <option value="<?php echo $key; ?>" 
                                                        <?php echo ($report['status'] == $key) ? 'selected' : ''; ?>>
                                                    <?php echo $info['label']; ?>
                                                </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-12">
                                            <div class="mb-3">
                                                <label class="form-label">特殊要求</label>
                                                <textarea class="form-control" rows="3" readonly><?php echo htmlspecialchars($report['special_requirements'] ?? ''); ?></textarea>
                                            </div>

                                        </div>
                                    </div>

                                    <div class="d-flex justify-content-end">
                                        <a href="transportation_reports.php?project_id=<?php echo $report['project_id']; ?>" class="btn btn-secondary me-2">
                                            <i class="bi bi-arrow-left"></i> 返回列表
                                        </a>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check-lg"></i> 保存修改
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- 相关行程信息 -->
                    <?php
                    // 查找相关行程 - 使用与transport_list.php一致的逻辑
                    // 首先获取当前记录的详细信息用于匹配
                    $current_query = "
                        SELECT travel_date, travel_type, departure_time, departure_location, destination_location, status
                        FROM transportation_reports
                        WHERE id = :current_id
                    ";
                    $current_stmt = $db->prepare($current_query);
                    $current_stmt->execute([':current_id' => $id]);
                    $current_trip = $current_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($current_trip) {
                        if ($table_exists) {
                            // 使用transportation_passengers表逻辑 - 基于行程条件匹配
                            $related_query = "
                                SELECT tr.id, tr.personnel_id, pr.name as personnel_name, tr.passenger_count, tr.contact_phone, tr.special_requirements, tr.status
                                FROM transportation_reports tr
                                JOIN personnel pr ON tr.personnel_id = pr.id
                                WHERE tr.travel_date = :travel_date
                                AND tr.travel_type = :travel_type
                                AND tr.departure_time = :departure_time
                                AND tr.departure_location = :departure_location
                                AND tr.destination_location = :destination_location
                                AND tr.status = :status
                                AND tr.id != :current_id
                                ORDER BY pr.name ASC
                            ";
                            $related_stmt = $db->prepare($related_query);
                            $related_stmt->execute([
                                ':travel_date' => $current_trip['travel_date'],
                                ':travel_type' => $current_trip['travel_type'],
                                ':departure_time' => $current_trip['departure_time'],
                                ':departure_location' => $current_trip['departure_location'],
                                ':destination_location' => $current_trip['destination_location'],
                                ':status' => $current_trip['status'],
                                ':current_id' => $id
                            ]);
                        } else {
                            // 回退到parent_transport_id逻辑
                            $related_query = "
                                SELECT tr.id, tr.personnel_id, pr.name as personnel_name, tr.passenger_count, tr.contact_phone, tr.special_requirements, tr.status
                                FROM transportation_reports tr
                                JOIN personnel pr ON tr.personnel_id = pr.id
                                WHERE (
                                    tr.parent_transport_id = (
                                        SELECT COALESCE(parent_transport_id, id) 
                                        FROM transportation_reports 
                                        WHERE id = :current_id
                                    )
                                    OR tr.id = (
                                        SELECT COALESCE(parent_transport_id, id) 
                                        FROM transportation_reports 
                                        WHERE id = :current_id
                                    )
                                )
                                AND tr.id != :current_id
                                ORDER BY pr.name ASC
                            ";
                            $related_stmt = $db->prepare($related_query);
                    $related_stmt->execute([':current_id' => $id]);
                }
            $related_trips = $related_stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $related_trips = [];
        }
        
        $total_related = count($related_trips) + 1; // 包括当前记录
                    ?>

                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="bi bi-people-fill"></i> 相关行程信息
                                    <span class="badge bg-primary ms-2"><?php echo $total_related; ?> 人</span>
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($related_trips)): ?>
                                    <p class="text-muted small">
                                        以下是与当前行程相同条件的其他乘车人：
                                    </p>
                                    <div class="list-group list-group-flush">
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1">当前记录</h6>
                                                <small class="text-muted">ID: <?php echo $id; ?></small>
                                            </div>
                                            <p class="mb-1"><?php echo htmlspecialchars($report['personnel_name']); ?></p>
                                            <small class="text-muted">
                                                电话: <?php echo htmlspecialchars($report['contact_phone']); ?><br>
                                                特殊要求: <?php echo htmlspecialchars($report['special_requirements'] ?: '无'); ?>
                                            </small>
                                        </div>
                                        
                                        <?php foreach ($related_trips as $trip): ?>
                                        <?php
                                        // 获取该人员的部门信息
                                        $dept_query = "
                                            SELECT d.name as department_name
                                            FROM project_department_personnel pdp
                                            JOIN departments d ON pdp.department_id = d.id
                                            WHERE pdp.personnel_id = :personnel_id
                                            AND pdp.project_id = :project_id
                                            LIMIT 1
                                        ";
                                        $dept_stmt = $db->prepare($dept_query);
                                        $dept_stmt->execute([
                                            ':personnel_id' => $trip['personnel_id'],
                                            ':project_id' => $report['project_id']
                                        ]);
                                        $dept_info = $dept_stmt->fetch(PDO::FETCH_ASSOC);
                                        $department_name = $dept_info['department_name'] ?? '未分配部门';
                                        ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between align-items-start">
                                                <div class="flex-grow-1">
                                                    <h6 class="mb-1">相关记录</h6>
                                                    <p class="mb-1">
                                                        <?php echo htmlspecialchars($trip['personnel_name']); ?>
                                                        <span class="badge bg-info ms-2"><?php echo htmlspecialchars($department_name); ?></span>
                                                    </p>
                                                    <small class="text-muted">
                                                        电话: <?php echo htmlspecialchars($trip['contact_phone']); ?><br>
                                                        特殊要求: <?php echo htmlspecialchars($trip['special_requirements'] ?: '无'); ?><br>
                                                        状态: <span class="badge bg-<?php echo $status_map[$trip['status']]['class']; ?>">
                                                            <?php echo $status_map[$trip['status']]['label']; ?>
                                                        </span>
                                                    </small>
                                                </div>
                                                <button type="button" class="btn btn-sm btn-outline-danger delete-passenger" 
                                                        data-passenger-id="<?php echo $trip['id']; ?>" 
                                                        data-passenger-name="<?php echo htmlspecialchars($trip['personnel_name']); ?>">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted">
                                        <i class="bi bi-info-circle"></i> 当前行程是唯一的，没有相同条件的其他乘车人。
                                    </p>
                                <?php endif; ?>
                                
                                <hr>
                                <div class="text-center">
                                    <button type="button" class="btn btn-success btn-sm me-2" data-bs-toggle="modal" data-bs-target="#addPassengerModal">
                                        <i class="bi bi-plus-circle"></i> 添加同行人
                                    </button>
                                    <a href="transportation_reports.php?project_id=<?php echo $report['project_id']; ?>&travel_date=<?php echo $report['travel_date']; ?>&travel_type=<?php echo urlencode($report['travel_type']); ?>" 
                                       class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-list"></i> 查看完整列表
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 添加同行人模态框 -->
    <div class="modal fade" id="addPassengerModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">添加同行人</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="personnelSelect" class="form-label">选择人员</label>
                        <select class="form-select" id="personnelSelect" size="8">
                            <option value="">请选择要添加的人员...</option>
                        </select>
                    </div>
                    <div class="alert alert-info small">
                        <i class="bi bi-info-circle"></i> 将添加与当前行程相同条件的出行记录
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" id="addPassengerBtn">
                        <i class="bi bi-plus-circle"></i> 添加
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // 表单验证（简化，仅保留状态选择）
        document.getElementById('fleetForm').addEventListener('submit', function(e) {
            // 状态选择验证
            const status = document.getElementById('status').value;
            if (!status) {
                alert('请选择出行状态！');
                e.preventDefault();
                return false;
            }
        });

        // 添加同行人功能
        let currentReportData = {
            project_id: '<?php echo $report['project_id']; ?>',
            travel_date: '<?php echo $report['travel_date']; ?>',
            travel_type: '<?php echo $report['travel_type']; ?>',
            departure_time: '<?php echo $report['departure_time']; ?>',
            departure_location: '<?php echo addslashes($report['departure_location']); ?>',
            destination_location: '<?php echo addslashes($report['destination_location']); ?>',
            status: '<?php echo $report['status']; ?>',
            vehicle_type: '<?php echo $report['vehicle_type']; ?>',
            passenger_count: '<?php echo $report['passenger_count']; ?>',
            contact_phone: '<?php echo addslashes($report['contact_phone']); ?>',
            special_requirements: '<?php echo addslashes($report['special_requirements']); ?>'
        };

        // 加载可选人员列表
        function loadAvailablePersonnel() {
            const select = document.getElementById('personnelSelect');
            select.innerHTML = '<option value="">加载中...</option>';

            fetch('api/get_all_personnel.php', {
                credentials: 'include'
            })
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        throw new Error(data.error || '加载失败');
                    }
                    
                    select.innerHTML = '<option value="">请选择要添加的人员...</option>';
                    
                    // 获取当前已存在的人员ID
                    const existingPersonnel = [
                        <?php echo $report['personnel_id']; ?>,
                        <?php echo implode(',', array_column($related_trips, 'personnel_id')); ?>
                    ];

                    data.data.forEach(person => {
                        if (!existingPersonnel.includes(parseInt(person.id))) {
                            const option = document.createElement('option');
                            option.value = person.id;
                            option.textContent = `${person.name} (${person.department || '未分配部门'})`;
                            select.appendChild(option);
                        }
                    });
                })
                .catch(error => {
                    console.error('加载人员列表失败:', error);
                    select.innerHTML = '<option value="">加载失败，请重试</option>';
                });
        }

        // 添加同行人
        document.getElementById('addPassengerBtn').addEventListener('click', function() {
            const select = document.getElementById('personnelSelect');
            const personnelId = select.value;

            if (!personnelId) {
                alert('请选择要添加的人员！');
                return;
            }

            this.disabled = true;
            this.innerHTML = '<i class="bi bi-hourglass-split"></i> 添加中...';

            const formData = new FormData();
            formData.append('personnel_id', personnelId);
            
            // 添加所有currentReportData中的参数
            for (const [key, value] of Object.entries(currentReportData)) {
                formData.append(key, value);
            }

            fetch('api/api_add_passenger.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    // 根据错误类型显示更清晰的提示
                    if (data.error_type === 'capacity_exceeded') {
                        alert(data.message);
                        // 不关闭模态框，让用户可以继续选择其他人
                    } else {
                        alert(data.message || '添加失败，请重试');
                    }
                }
            })
            .catch(error => {
                console.error('添加失败:', error);
                alert('添加失败，请重试');
            })
            .finally(() => {
                this.disabled = false;
                this.innerHTML = '<i class="bi bi-plus-circle"></i> 添加';
            });
        });

        // 删除同行人
        document.querySelectorAll('.delete-passenger').forEach(button => {
            button.addEventListener('click', function() {
                const passengerId = this.dataset.passengerId;
                const passengerName = this.dataset.passengerName;

                if (confirm(`确定要删除 ${passengerName} 的行程记录吗？`)) {
                    fetch('api/api_delete_passenger.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `passenger_id=${passengerId}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            location.reload();
                        } else {
                            alert(data.message || '删除失败，请重试');
                        }
                    })
                    .catch(error => {
                        console.error('删除失败:', error);
                        alert('删除失败，请重试');
                    });
                }
            });
        });

        // 模态框显示时加载人员列表
        document.getElementById('addPassengerModal').addEventListener('show.bs.modal', loadAvailablePersonnel);
    </script>
    <?php include 'includes/footer.php'; ?>
</body>
</html>