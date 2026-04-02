<?php
session_start();
// 引入权限中间件进行权限验证
require_once '../includes/PermissionMiddleware.php';
$database = new Database();
$db = $database->getConnection();
$middleware = new PermissionMiddleware($db);
$middleware->checkUserPagePermission('frontend:transport:edit');

require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();
checkProjectPermission($_SESSION['project_id']);

$projectId = $_SESSION['project_id'];
$id = $_GET['id'] ?? 0;

// 获取项目人员
$personnel = getProjectPersonnel($projectId, $db);

// 获取当前行程的详细信息
$query = "SELECT * FROM transportation_reports WHERE id = :id AND project_id = :project_id";
$stmt = $db->prepare($query);
$stmt->execute([':id' => $id, ':project_id' => $projectId]);
$transport = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transport) {
    $_SESSION['message'] = '未找到该行程记录或权限不足';
    header('Location: transport_list.php');
    exit;
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // 处理删除同行人请求（AJAX请求）
    if (isset($_POST['action']) && $_POST['action'] === 'delete_passenger' && isset($_POST['passenger_id'])) {
        header('Content-Type: application/json');
        
        $passenger_id = $_POST['passenger_id'];
        
        try {
            // 验证权限 - 只能删除同项目的记录
            $check_query = "SELECT tr.*, p.name as personnel_name 
                           FROM transportation_reports tr 
                           JOIN personnel p ON tr.personnel_id = p.id 
                           WHERE tr.id = :id AND tr.project_id = :project_id";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->execute([
                ':id' => $passenger_id,
                ':project_id' => $projectId
            ]);
            $passenger = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$passenger) {
                echo json_encode(['success' => false, 'message' => '未找到该同行人记录']);
                exit;
            }
            
            // 执行删除
            $delete_query = "DELETE FROM transportation_reports WHERE id = :id AND project_id = :project_id";
            $delete_stmt = $db->prepare($delete_query);
            $delete_stmt->execute([
                ':id' => $passenger_id,
                ':project_id' => $projectId
            ]);
            
            echo json_encode(['success' => true, 'message' => '删除成功']);
            exit;
            
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => '删除失败：' . $e->getMessage()]);
            exit;
        }
    }
    
    // 处理正常的表单提交
    $personnel_id = $_POST['personnel_id'];
    $travel_date = $_POST['travel_date'];
    $travel_type = $_POST['travel_type'];
    $departure_location = $_POST['departure_location'];
    $destination_location = $_POST['destination_location'];
    $departure_time = $_POST['departure_time'];
    $arrival_time = $_POST['arrival_time'];
    $passenger_count = $_POST['passenger_count'];
    $contact_phone = $_POST['contact_phone'];
    $special_requirements = $_POST['special_requirements'];
    $status = $_POST['status'];
    
    // 引入容量验证器
    require_once __DIR__ . '/../includes/capacity_validator.php';
    $capacityValidator = new CapacityValidator($db);
    
    // 检查容量限制 - 获取当前行程的车辆ID
    $vehicle_id = $capacityValidator->getAssignedVehicleByTransportId($id);
    
    // 检查新的总人数是否超过容量
    $capacity_check = $capacityValidator->checkCapacity([
        'travel_date' => $travel_date,
        'travel_type' => $travel_type,
        'departure_time' => $departure_time,
        'arrival_time' => $arrival_time,
        'departure_location' => $departure_location,
        'destination_location' => $destination_location,
        'project_id' => $projectId
    ], $passenger_count - $transport['passenger_count'], $vehicle_id);
    
    if (!$capacity_check['success']) {
        $error = '❌ 超员警告：当前行程人数修改后将超过车辆容量限制。' . $capacity_check['message'];
        // 保留用户填写的值
        $transport['personnel_id'] = $personnel_id;
        $transport['travel_date'] = $travel_date;
        $transport['travel_type'] = $travel_type;
        $transport['departure_location'] = $departure_location;
        $transport['destination_location'] = $destination_location;
        $transport['departure_time'] = $departure_time;
        $transport['passenger_count'] = $passenger_count;
        $transport['contact_phone'] = $contact_phone;
        $transport['special_requirements'] = $special_requirements;
        $transport['status'] = $status;
    } else {
        $query = "UPDATE transportation_reports SET 
                  personnel_id = :personnel_id, 
                  travel_date = :travel_date, 
                  travel_type = :travel_type, 
                  departure_location = :departure_location, 
                  destination_location = :destination_location, 
                  departure_time = :departure_time, 
                  arrival_time = :arrival_time, 
                  passenger_count = :passenger_count, 
                  contact_phone = :contact_phone, 
                  special_requirements = :special_requirements,
                  status = :status
                  WHERE id = :id AND project_id = :project_id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':personnel_id', $personnel_id);
        $stmt->bindParam(':travel_date', $travel_date);
        $stmt->bindParam(':travel_type', $travel_type);
        $stmt->bindParam(':departure_location', $departure_location);
        $stmt->bindParam(':destination_location', $destination_location);
        $stmt->bindParam(':departure_time', $departure_time);
        $stmt->bindParam(':arrival_time', $arrival_time);
        $stmt->bindParam(':passenger_count', $passenger_count);
        $stmt->bindParam(':contact_phone', $contact_phone);
        $stmt->bindParam(':special_requirements', $special_requirements);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':project_id', $projectId);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "行程更新成功";
            header("Location: transport_list.php");
            exit;
        } else {
            $error = "更新失败，请重试";
        }
    }
}

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
    ':personnel_id' => $transport['personnel_id'],
    ':project_id' => $projectId
]);
$dept_info = $dept_stmt->fetch(PDO::FETCH_ASSOC);
$department_name = $dept_info['department_name'] ?? '未分配部门';

// 获取同行程乘车人信息（与transport_list.php逻辑保持一致）
$related_passengers = [];
$debug_info = [];

// 使用transportation_passengers关联表获取准确的乘客信息
// 首先检查transportation_passengers表是否存在
$check_table_query = "SHOW TABLES LIKE 'transportation_passengers'";
$check_table_stmt = $db->prepare($check_table_query);
$check_table_stmt->execute();
$table_exists = $check_table_stmt->fetchColumn();

if ($table_exists) {
    // 使用transportation_passengers表获取准确乘客信息
    $related_query = "
        SELECT tr.id, tr.personnel_id, 
               p.name as personnel_name,
               tr.travel_date, tr.travel_type, tr.departure_time,
               tr.departure_location, tr.destination_location,
               d.name as department_name, tr.status,
               tr.parent_transport_id
        FROM transportation_reports tr
        JOIN transportation_passengers tp ON tr.id = tp.transportation_report_id
        JOIN personnel p ON tp.personnel_id = p.id
        LEFT JOIN project_department_personnel pdp ON p.id = pdp.personnel_id AND pdp.project_id = :project_id
        LEFT JOIN departments d ON pdp.department_id = d.id
        WHERE tr.id = :transport_id AND tr.project_id = :project_id
        
        UNION DISTINCT
        
        -- 包含当前行程的主乘客
        SELECT tr.id, tr.personnel_id, 
               p.name as personnel_name,
               tr.travel_date, tr.travel_type, tr.departure_time,
               tr.departure_location, tr.destination_location,
               d.name as department_name, tr.status,
               tr.parent_transport_id
        FROM transportation_reports tr
        JOIN personnel p ON tr.personnel_id = p.id
        LEFT JOIN project_department_personnel pdp ON p.id = pdp.personnel_id AND pdp.project_id = :project_id
        LEFT JOIN departments d ON pdp.department_id = d.id
        WHERE tr.id = :transport_id AND tr.project_id = :project_id
        
        ORDER BY id ASC, personnel_name ASC
    ";
    
    $related_stmt = $db->prepare($related_query);
    $related_stmt->execute([
        ':transport_id' => $id,
        ':project_id' => $projectId
    ]);
    $related_passengers = $related_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} else {
    // 回退到旧逻辑：使用行程条件匹配
    $main_transport_id = null;
    
    // 确定当前行程是主行程还是子行程
    if (!empty($transport['parent_transport_id'])) {
        $main_transport_id = $transport['parent_transport_id'];
    } else {
        $main_transport_id = $transport['id'];
    }
    
    $related_query = "
        SELECT tr.id, tr.personnel_id, 
               p.name as personnel_name,
               tr.travel_date, tr.travel_type, tr.departure_time,
               tr.departure_location, tr.destination_location,
               d.name as department_name, tr.status,
               tr.parent_transport_id
        FROM transportation_reports tr
        JOIN personnel p ON tr.personnel_id = p.id
        LEFT JOIN project_department_personnel pdp ON p.id = pdp.personnel_id AND pdp.project_id = :project_id
        LEFT JOIN departments d ON pdp.department_id = d.id
        WHERE (
            tr.id = :main_id OR tr.parent_transport_id = :main_id
        )
        AND tr.project_id = :project_id
        ORDER BY tr.id ASC, p.name ASC
    ";
    
    $related_stmt = $db->prepare($related_query);
    $related_stmt->execute([
        ':main_id' => $main_transport_id,
        ':project_id' => $projectId
    ]);
    $related_passengers = $related_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 调试信息 - 检查transportation_passengers表数据
if ($table_exists) {
    $debug_query = "
        SELECT 
            COUNT(*) as total_passengers,
            GROUP_CONCAT(DISTINCT p.name ORDER BY p.name) as passenger_names,
            COUNT(DISTINCT tp.personnel_id) as unique_personnel_count,
            GROUP_CONCAT(DISTINCT tr.id) as related_transport_ids
        FROM transportation_reports tr
        JOIN transportation_passengers tp ON tr.id = tp.transportation_report_id
        JOIN personnel p ON tp.personnel_id = p.id
        WHERE tr.id = :transport_id AND tr.project_id = :project_id
    ";
    
    $debug_stmt = $db->prepare($debug_query);
    $debug_stmt->execute([
        ':transport_id' => $id,
        ':project_id' => $projectId
    ]);
    $debug_info = $debug_stmt->fetch(PDO::FETCH_ASSOC);
} else {
    // 回退调试查询
    $main_transport_id = null;
    if (!empty($transport['parent_transport_id'])) {
        $main_transport_id = $transport['parent_transport_id'];
    } else {
        $main_transport_id = $transport['id'];
    }
    
    $debug_query = "
        SELECT 
            COUNT(*) as total_records,
            GROUP_CONCAT(DISTINCT p.name ORDER BY p.name) as passenger_names,
            COUNT(DISTINCT tr.personnel_id) as unique_personnel_count,
            GROUP_CONCAT(DISTINCT tr.id) as related_transport_ids
        FROM transportation_reports tr
        JOIN personnel p ON tr.personnel_id = p.id
        WHERE (tr.id = :main_id OR tr.parent_transport_id = :main_id)
        AND tr.project_id = :project_id
    ";
    
    $debug_stmt = $db->prepare($debug_query);
    $debug_stmt->execute([
        ':main_id' => $main_transport_id,
        ':project_id' => $projectId
    ]);
    $debug_info = $debug_stmt->fetch(PDO::FETCH_ASSOC);
    $debug_info['main_transport_id'] = $main_transport_id;
}

// 页面设置
$page_title = '编辑行程 - ' . ($_SESSION['project_name'] ?? '');
$active_page = 'transport_list';
$show_page_title = '编辑行程';
$page_icon = 'pencil';
$page_action_text = '返回行程列表';
$page_action_url = 'transport_list.php';

include('includes/header.php');
?>

<style>
.edit-form {
    max-width: 800px;
    margin: 0 auto;
}

.current-info {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border: 1px solid #dee2e6;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.current-info h5 {
    color: #495057;
    margin-bottom: 20px;
    font-size: 1.25rem;
    font-weight: 600;
}

.info-item {
    margin-bottom: 12px;
    padding: 8px 0;
    font-size: 14px;
}

.info-item i {
    margin-right: 8px;
    font-size: 16px;
}

.info-item strong {
    color: #495057;
    margin-right: 5px;
}

.form-section {
    background-color: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
}

.status-options {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
}

.status-option {
    padding: 8px 16px;
    border-radius: 20px;
    border: 2px solid;
    cursor: pointer;
    transition: all 0.3s ease;
}

.status-option input[type="radio"] {
    display: none;
}

.status-pending {
    border-color: #ffc107;
    color: #856404;
    background-color: #fff3cd;
}

.status-assigned {
    border-color: #17a2b8;
    color: #0c5460;
    background-color: #d1ecf1;
}

.status-in_progress {
    border-color: #007bff;
    color: #004085;
    background-color: #cce5ff;
}

.status-completed {
    border-color: #28a745;
    color: #155724;
    background-color: #d4edda;
}

.status-cancelled {
    border-color: #dc3545;
    color: #721c24;
    background-color: #f8d7da;
}

.status-option.selected {
    font-weight: bold;
    transform: scale(1.05);
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.related-passengers {
    margin-top: 30px;
    margin-bottom: 30px;
}

/* 乘车人相关样式已移除 */
</style>

<?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['message'])): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?php echo $_SESSION['message']; ?>
        <?php unset($_SESSION['message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="edit-form">
        <div class="current-info">
            <h5><i class="bi bi-info-circle"></i> 当前行程信息</h5>
            <div class="row">
                <!-- 第一列：基础信息 -->
                <div class="col-md-4">
                    <div class="info-item">
                        <i class="bi bi-hash text-primary"></i>
                        <strong>行程ID:</strong> <?php echo $transport['id']; ?>
                    </div>
                    <div class="info-item">
                        <i class="bi bi-person text-success"></i>
                        <strong>乘车人员:</strong> 
                        <?php 
                        // 获取该行程的所有乘车人员姓名
                        if ($table_exists) {
                            // 从 transportation_passengers 表获取所有乘车人员
                            $all_passengers_query = "
                                SELECT DISTINCT p.name, p.id,
                                       GROUP_CONCAT(DISTINCT d.name ORDER BY d.name) as department_names
                                FROM transportation_passengers tp
                                JOIN personnel p ON tp.personnel_id = p.id
                                LEFT JOIN personnel_departments pd ON p.id = pd.personnel_id
                                LEFT JOIN departments d ON pd.department_id = d.id
                                WHERE tp.transportation_report_id = :transport_id
                                GROUP BY p.id, p.name
                                ORDER BY p.name
                            ";
                            $all_passengers_stmt = $db->prepare($all_passengers_query);
                            $all_passengers_stmt->execute([':transport_id' => $transport['id']]);
                            $all_passengers = $all_passengers_stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            if ($all_passengers) {
                                $passenger_info = [];
                                foreach ($all_passengers as $passenger) {
                                    $dept_info = $passenger['department_names'] ? '[' . htmlspecialchars($passenger['department_names']) . ']' : '';
                                    $passenger_info[] = htmlspecialchars($passenger['name']) . $dept_info;
                                }
                                echo implode('、', $passenger_info);
                            } else {
                                // 如果transportation_passengers表没有数据，回退到原始方法
                                $personnel_name_query = "SELECT name FROM personnel WHERE id = :personnel_id";
                                $personnel_name_stmt = $db->prepare($personnel_name_query);
                                $personnel_name_stmt->execute([':personnel_id' => $transport['personnel_id']]);
                                $personnel_info = $personnel_name_stmt->fetch(PDO::FETCH_ASSOC);
                                echo htmlspecialchars($personnel_info['name'] ?? '未知人员') . '[' . htmlspecialchars($department_name) . ']';
                            }
                        } else {
                            // transportation_passengers表不存在，使用原始方法
                            $personnel_name_query = "SELECT name FROM personnel WHERE id = :personnel_id";
                            $personnel_name_stmt = $db->prepare($personnel_name_query);
                            $personnel_name_stmt->execute([':personnel_id' => $transport['personnel_id']]);
                            $personnel_info = $personnel_name_stmt->fetch(PDO::FETCH_ASSOC);
                            echo htmlspecialchars($personnel_info['name'] ?? '未知人员') . '[' . htmlspecialchars($department_name) . ']';
                        }
                        ?>
                    </div>
                    <div class="info-item">
                        <i class="bi bi-calendar text-info"></i>
                        <strong>出行日期:</strong> <?php echo $transport['travel_date']; ?>
                    </div>
                </div>
                
                <!-- 第二列：类型和时间信息 -->
                <div class="col-md-4">
                    <div class="info-item">
                        <i class="bi bi-geo-alt text-info"></i>
                        <strong>交通类型:</strong> <?php echo $transport['travel_type']; ?>
                    </div>
                    <div class="info-item">
                        <i class="bi bi-clock text-warning"></i>
                        <strong>出发时间:</strong> <?php echo $transport['departure_time'] ?: '未指定'; ?>
                    </div>
                    <div class="info-item">
                        <i class="bi bi-geo text-danger"></i>
                        <strong>出发地点:</strong> <?php echo htmlspecialchars($transport['departure_location']); ?>
                    </div>
                </div>
                
                <!-- 第三列：地点和状态信息 -->
                <div class="col-md-4">
                    <div class="info-item">
                        <i class="bi bi-flag text-success"></i>
                        <strong>目的地点:</strong> <?php echo htmlspecialchars($transport['destination_location']); ?>
                    </div>
                    <div class="info-item">
                        <i class="bi bi-tag text-primary"></i>
                        <strong>当前状态:</strong> 
                        <span class="badge bg-<?php 
                            $status_colors = [
                                'pending' => 'warning',
                                'assigned' => 'info',
                                'in_progress' => 'primary',
                                'completed' => 'success',
                                'cancelled' => 'danger',
                                'p' => 'info'  // 添加p状态映射
                            ];
                            echo $status_colors[$transport['status']] ?? 'secondary';
                        ?>">
                            <?php 
                            $status_texts = [
                                'pending' => '待处理',
                                'assigned' => '已分配',
                                'in_progress' => '进行中',
                                'completed' => '已完成',
                                'cancelled' => '已取消',
                                'p' => '进行中'  // 将p状态显示为进行中
                            ];
                            echo $status_texts[$transport['status']] ?? '待处理';
                            ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <i class="bi bi-people text-info"></i>
                        <strong>乘客数量:</strong> 
                        <span class="text-primary fw-bold"><?php echo $transport['passenger_count']; ?>人</span>
                    </div>
                </div>
            </div>
        </div>

    <!-- 乘车人信息已隐藏 --> <!-- 隐藏乘车人员div -->

    <form method="POST" class="needs-validation" novalidate>
        <!-- 基本信息div已删除，乘车人员字段已整合到行程详情区域 -->
        <!-- 保留原始personnel_id作为隐藏字段 -->
        <input type="hidden" name="personnel_id" value="<?php echo htmlspecialchars($transport['personnel_id']); ?>">

        <div class="form-section">
            <h5><i class="bi bi-geo-alt"></i> 行程详情</h5>
            <!-- 乘车人员选择框已删除 -->
            <div class="row">
                <div class="col-md-6">
                    <label for="travel_date" class="form-label">出行日期 *</label>
                    <input type="date" class="form-control" id="travel_date" name="travel_date" 
                           value="<?php echo $transport['travel_date']; ?>" required>
                </div>
                <div class="col-md-6">
                    <label for="travel_type" class="form-label">交通类型 *</label>
                    <select class="form-select" id="travel_type" name="travel_type" required>
                        <option value="接机/站" <?php echo ($transport['travel_type'] == '接机/站' || $transport['travel_type'] == '接站') ? 'selected' : ''; ?>>接机/站</option>
                        <option value="送机/站" <?php echo ($transport['travel_type'] == '送机/站' || $transport['travel_type'] == '送站') ? 'selected' : ''; ?>>送机/站</option>
                        <option value="混合交通安排（自定义）" <?php echo ($transport['travel_type'] == '混合交通安排（自定义）' || $transport['travel_type'] == '混合交通安排') ? 'selected' : ''; ?>>混合交通安排（自定义）</option>
                        <option value="点对点" <?php echo ($transport['travel_type'] == '点对点') ? 'selected' : ''; ?>>点对点</option>
                    </select>
                </div>
            </div>
            <!-- 删除重复的交通类型选择框，保留出行日期后的那个 -->
            <div class="row mt-3">
                <div class="col-md-6">
                    <label for="departure_time" class="form-label">出发时间</label>
                    <input type="time" class="form-control" id="departure_time" name="departure_time" 
                           value="<?php echo $transport['departure_time']; ?>">
                </div>
                <div class="col-md-6">
                    <label for="arrival_time" class="form-label">到达时间（可选）</label>
                    <input type="time" class="form-control" id="arrival_time" name="arrival_time" 
                           value="<?php echo $transport['arrival_time']; ?>">
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-md-6">
                    <label for="arrival_time" class="form-label">到达时间（可选）</label>
                    <input type="time" class="form-control" id="arrival_time" name="arrival_time" 
                           value="<?php echo $transport['arrival_time']; ?>">
                </div>
                <div class="col-md-6">
                    <label for="departure_location" class="form-label">出发地点 *</label>
                    <input type="text" class="form-control" id="departure_location" name="departure_location" 
                           value="<?php echo htmlspecialchars($transport['departure_location']); ?>" required>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-md-6">
                    <label for="destination_location" class="form-label">目的地点 *</label>
                    <input type="text" class="form-control" id="destination_location" name="destination_location" 
                           value="<?php echo htmlspecialchars($transport['destination_location']); ?>" required>
                </div>
                <div class="col-md-6">
                    <!-- 占位div保持布局平衡 -->
                </div>
            </div>
        </div>

        <div class="form-section">
            <h5><i class="bi bi-info-circle"></i> 其他信息</h5>
            <div class="row">
                <div class="col-md-6">
                    <label for="contact_phone" class="form-label">联系电话</label>
                    <input type="tel" class="form-control" id="contact_phone" name="contact_phone" 
                           value="<?php echo htmlspecialchars($transport['contact_phone']); ?>">
                </div>
                <div class="col-md-6">
                    <!-- 乘客数量已移至当前行程信息区域 -->
                    <input type="hidden" name="passenger_count" value="<?php echo $transport['passenger_count']; ?>">
                </div>
            </div>
            <div class="mb-3 mt-3">
                <label for="special_requirements" class="form-label">特殊要求</label>
                <textarea class="form-control" id="special_requirements" name="special_requirements" 
                          rows="3" placeholder="如：需要儿童座椅、行李较多等"><?php echo htmlspecialchars($transport['special_requirements']); ?></textarea>
            </div>
        </div>

        <!-- 状态设置已移除 -->
         <input type="hidden" name="status" value="<?php echo htmlspecialchars($transport['status']); ?>">

        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
            <a href="transport_list.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> 取消
            </a>
            <button type="submit" class="btn btn-primary">
            <i class="bi bi-check"></i> 保存更改
        </button>
    </div>
</form>
</div>

<script>
// 状态选择交互
document.querySelectorAll('.status-option').forEach(option => {
    option.addEventListener('click', function() {
        // 移除所有选中状态
        document.querySelectorAll('.status-option').forEach(opt => {
            opt.classList.remove('selected');
        });
        
        // 添加当前选中状态
        this.classList.add('selected');
        
        // 选中对应的radio
        this.querySelector('input[type="radio"]').checked = true;
    });
});

// 表单验证
document.querySelector('form').addEventListener('submit', function(event) {
    if (!this.checkValidity()) {
        event.preventDefault();
        event.stopPropagation();
    }
    this.classList.add('was-validated');
});

// 删除同行人功能已移除
</script>

<?php include 'includes/footer.php'; ?>