<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

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
$action = $_GET['action'] ?? 'list';

// 处理删除请求
if ($action === 'delete' && isset($_GET['id'])) {
    $transport_id = (int)$_GET['id'];
    
    // 清除所有输出缓冲区
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    try {
        // 首先检查记录是否存在
        $check_query = "SELECT id FROM transportation_reports WHERE id = :id AND project_id = :project_id";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->execute(['id' => $transport_id, 'project_id' => $projectId]);
        
        if (!$check_stmt->fetch()) {
            $message = '未找到该行程记录或权限不足';
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
        
        $db->beginTransaction();
        
        // 删除车队分配（忽略不存在的表）
        try {
            $delete_assign_query = "DELETE FROM transportation_fleet_assignments WHERE transportation_report_id = :transport_id";
            $delete_assign_stmt = $db->prepare($delete_assign_query);
            $delete_assign_stmt->execute(['transport_id' => $transport_id]);
        } catch (Exception $e) {
            // 表可能不存在，继续执行
        }
        
        // 删除乘客关联记录（忽略不存在的表）
        try {
            $delete_passengers_query = "DELETE FROM transportation_passengers WHERE transportation_id = :transport_id";
            $delete_passengers_stmt = $db->prepare($delete_passengers_query);
            $delete_passengers_stmt->execute(['transport_id' => $transport_id]);
        } catch (Exception $e) {
            // 表可能不存在，继续执行
        }
        
        // 删除行程记录
        $delete_query = "DELETE FROM transportation_reports WHERE id = :id AND project_id = :project_id";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->execute([
            'id' => $transport_id,
            'project_id' => $projectId
        ]);
        
        $deleted_count = $delete_stmt->rowCount();
        
        if ($deleted_count > 0) {
            $db->commit();
            
            // 始终返回JSON响应
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => '删除成功']);
            exit;
        } else {
            $db->rollBack();
            $message = '删除失败：未找到记录';
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        
        $error_message = '删除失败：' . $e->getMessage();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $error_message]);
        exit;
    }
}

// 获取项目人员和车辆
$personnel = getProjectPersonnel($projectId, $db);
$vehicles = getProjectVehicles($projectId, $db);

// 获取项目部门信息，用于按部门选择乘客
$departments = [];
try {
    $dept_query = "
        SELECT d.id, d.name 
        FROM departments d
        JOIN project_department_personnel pdp ON d.id = pdp.department_id
        WHERE pdp.project_id = :project_id
        GROUP BY d.id, d.name
        ORDER BY d.name ASC
    ";
    $dept_stmt = $db->prepare($dept_query);
    $dept_stmt->execute([':project_id' => $projectId]);
    $departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // 错误处理：如果查询失败，departments保持为空数组
    error_log("获取部门信息失败: " . $e->getMessage());
}

// ========== 点对点交通类型地点选择功能 ==========
// 当用户选择"点对点"交通类型时，出发地点和目的地点将从以下获取到的地点列表中选择
$locations = [];
$transport_locations = []; // 机场和高铁站
$venue_locations = []; // 项目场地和酒店

if ($db && $projectId) {
    try {
        // 获取项目关联的场地位置（使用projects表的location字段）
        $query = "SELECT DISTINCT location FROM projects WHERE id = :project_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':project_id', $projectId);
        $stmt->execute();
        $project_venue_locations = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // 获取项目关联的酒店名称 - 支持多酒店功能
        // 检查project_hotels表是否存在
        $check_table_query = "SHOW TABLES LIKE 'project_hotels'";
        $table_exists = $db->query($check_table_query)->rowCount() > 0;
        
        if ($table_exists) {
            // 使用新的多酒店关联模式
            $query = "SELECT DISTINCT h.hotel_name_cn 
                     FROM hotels h 
                     JOIN project_hotels ph ON h.id = ph.hotel_id 
                     WHERE ph.project_id = :project_id 
                     ORDER BY h.hotel_name_cn";
        } else {
            // 使用旧的项目单酒店模式（向后兼容）
            $query = "SELECT h.hotel_name_cn FROM projects p 
                     JOIN hotels h ON p.hotel_id = h.id 
                     WHERE p.id = :project_id";
        }
        $stmt = $db->prepare($query);
        $stmt->bindParam(':project_id', $projectId);
        $stmt->execute();
        $project_hotels = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // 获取项目信息中的机场和高铁站
        $query = "SELECT arrival_airport, arrival_railway_station, departure_airport, departure_railway_station 
                 FROM projects WHERE id = :project_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':project_id', $projectId);
        $stmt->execute();
        $project_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 构建交通地点（机场和高铁站）
        $transport_locations = [];
        if (!empty($project_info['arrival_airport']) && $project_info['arrival_airport'] !== '未设置') {
            $transport_locations[] = $project_info['arrival_airport'] . '（机场）';
        }
        if (!empty($project_info['arrival_railway_station']) && $project_info['arrival_railway_station'] !== '未设置') {
            $transport_locations[] = $project_info['arrival_railway_station'] . '（高铁站）';
        }
        if (!empty($project_info['departure_airport']) && $project_info['departure_airport'] !== '未设置') {
            $transport_locations[] = $project_info['departure_airport'] . '（机场）';
        }
        if (!empty($project_info['departure_railway_station']) && $project_info['departure_railway_station'] !== '未设置') {
            $transport_locations[] = $project_info['departure_railway_station'] . '（高铁站）';
        }
        
        // 构建场地地点（项目场地和酒店）
        $venue_locations = [];
        foreach ($project_venue_locations as $location) {
            if (!empty($location)) {
                $venue_locations[] = $location;
            }
        }
        
        foreach ($project_hotels as $hotel_name) {
            if (!empty($hotel_name)) {
                $venue_locations[] = $hotel_name;
            }
        }
        
        // 合并所有地点用于默认显示
        $locations = array_unique(array_merge($transport_locations, $venue_locations));
        sort($locations);
        
    } catch (PDOException $e) {
        // 错误处理
    }
}
// ========== 点对点交通类型地点选择功能结束 ==========

// 处理编辑请求重定向
if ($action === 'edit' && $id) {
    header("Location: transport.php?action=edit&id=$id");
    exit;
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['create_routes'])) {
        // 批量创建点对点行程，支持直接分配乘客
        $routes = $_POST['routes'];
        $success_count = 0;

        // 引入容量验证器
        require_once __DIR__ . '/../includes/capacity_validator.php';
        $capacityValidator = new CapacityValidator($db);

        foreach ($routes as $route) {
            if (empty($route['travel_date']) || (empty($route['departure_location']) && empty($route['departure_location_custom'])) || (empty($route['destination_location']) && empty($route['destination_location_custom']))) {
                continue;
            }
            
            // 处理地点数据：优先使用主字段，如果为空则使用自定义字段
            $departure_location = !empty($route['departure_location']) ? $route['departure_location'] : ($route['departure_location_custom'] ?? '');
            $destination_location = !empty($route['destination_location']) ? $route['destination_location'] : ($route['destination_location_custom'] ?? '');
            
            // 检查处理后的地点是否为空
            if (empty($departure_location) || empty($destination_location)) {
                continue;
            }
            
            // 获取选择的乘客
            $personnel_ids = $route['personnel_ids'] ?? [];
            
            // 修复：确保至少有一个人员被选择
            if (empty($personnel_ids)) {
                $_SESSION['message'] = '请为每个行程至少选择一个乘客！';
                $_SESSION['message_type'] = 'warning';
                header('Location: transport_enhanced.php');
                exit;
            }
            
            $passenger_count = count($personnel_ids);
            
            // 检查容量限制 - 仅在选择车辆时进行验证
                if (!empty($route['fleet_id'])) {
                    $capacity_check = $capacityValidator->checkCapacity([
                        'travel_date' => $route['travel_date'],
                        'travel_type' => $route['travel_type'],
                        'departure_time' => $route['departure_time'],
                        'arrival_time' => $route['arrival_time'] ?? null,
                        'departure_location' => $route['departure_location'],
                        'destination_location' => $route['destination_location'],
                        'project_id' => $projectId
                    ], $passenger_count, $route['fleet_id']);
                } else {
                    // 未选择车辆时，容量验证通过
                    $capacity_check = ['success' => true];
                }
            
            if (!$capacity_check['success']) {
                $_SESSION['message'] = '容量限制：' . $capacity_check['message'];
                $_SESSION['message_type'] = 'danger';
                header('Location: transport_enhanced.php');
                exit;
            }
            
            // 处理车型需求数据
            $vehicle_requirements = isset($route['vehicle_requirements']) ? json_encode($route['vehicle_requirements']) : null;
            
            // 创建一个整体行程，支持多乘客关联
            // 修复：确保personnel_id不为null，如果没有选择人员则使用当前登录用户的关联人员ID
            if (!empty($personnel_ids)) {
                $main_personnel_id = $personnel_ids[0];
            } else {
                // 如果没有选择人员，使用当前登录用户关联的人员ID或默认人员ID
                $main_personnel_id = $_SESSION['personnel_id'] ?? $_SESSION['user_id'] ?? 1;
            }
            
            // 先创建主行程 - 新增vehicle_requirements字段
            $query = "INSERT INTO transportation_reports (project_id, travel_date, travel_type, departure_location, destination_location, departure_time, personnel_id, passenger_count, contact_phone, special_requirements, vehicle_requirements, reported_by, status) 
                      VALUES (:project_id, :travel_date, :travel_type, :departure_location, :destination_location, :departure_time, :personnel_id, :passenger_count, :contact_phone, :special_requirements, :vehicle_requirements, :reported_by, 'pending')";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':project_id', $projectId);
            $stmt->bindParam(':travel_date', $route['travel_date']);
            $stmt->bindParam(':travel_type', $route['travel_type']);
            $stmt->bindParam(':departure_location', $departure_location);
            $stmt->bindParam(':destination_location', $destination_location);
            $stmt->bindParam(':departure_time', $route['departure_time']);
            $stmt->bindParam(':personnel_id', $main_personnel_id);
            $stmt->bindParam(':passenger_count', $passenger_count);
            $stmt->bindParam(':contact_phone', $route['contact_phone']);
            $stmt->bindParam(':special_requirements', $route['special_requirements']);
            $stmt->bindParam(':vehicle_requirements', $vehicle_requirements);
            $stmt->bindParam(':reported_by', $_SESSION['user_id']);
            
            if ($stmt->execute()) {
                $success_count++;
                // 获取刚插入的行程ID
                $main_transport_id = $db->lastInsertId();
                
                // 如果选择了车辆，保存车辆分配信息
                if (!empty($route['fleet_id'])) {
                    $assign_query = "INSERT INTO transportation_fleet_assignments (transportation_report_id, fleet_id) VALUES (:transport_id, :fleet_id)";
                    $assign_stmt = $db->prepare($assign_query);
                    $assign_stmt->execute(['transport_id' => $main_transport_id, 'fleet_id' => $route['fleet_id']]);
                }
                
                // 行程以整体为主体，不再为每个人员单独创建行程
            // 已在主行程中设置了正确的passenger_count为选择的人员总数
            // 人员关联将在显示时处理
            
            // 如果选择了多个人员，记录额外的人员关联关系
            if (count($personnel_ids) > 1) {
                // 使用transportation_passengers关联表存储多乘客信息
                // 首先检查该表是否存在，如果不存在则创建
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
                    
                    // 存储所有选择的人员（包括第一个）与行程的关联
                    foreach ($personnel_ids as $personnel_id) {
                        $passenger_query = "INSERT IGNORE INTO transportation_passengers (transportation_report_id, personnel_id) 
                                            VALUES (:transport_id, :personnel_id)";
                        $passenger_stmt = $db->prepare($passenger_query);
                        $passenger_stmt->execute(['transport_id' => $main_transport_id, 'personnel_id' => $personnel_id]);
                    }
                } catch (Exception $e) {
                    // 如果创建表或插入关联失败，继续执行，不影响主行程的创建
                }
            }
            }
        }
        
        $_SESSION['message'] = "成功创建 {$success_count} 个行程";
        $_SESSION['message_type'] = 'success';
        header('Location: transport_enhanced.php');
        exit;
    }

    if (isset($_POST['assign_personnel'])) {
        $transport_ids = $_POST['transport_ids'] ?? [];
        $personnel_ids = $_POST['personnel_ids'] ?? [];
        
        if (empty($transport_ids) || empty($personnel_ids)) {
            $_SESSION['message'] = '请选择行程和人员！';
            $_SESSION['message_type'] = 'warning';
            header('Location: transport_enhanced.php');
            exit;
        }
        
        // 引入容量验证器
        require_once __DIR__ . '/../includes/capacity_validator.php';
        $capacityValidator = new CapacityValidator($db);
        
        try {
            $db->beginTransaction();
            
            $success_count = 0;
            
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
            }
            
            foreach ($transport_ids as $transport_id) {
                // 获取原始行程信息
                $query = "SELECT * FROM transportation_reports WHERE id = :id AND project_id = :project_id";
                $stmt = $db->prepare($query);
                $stmt->execute(['id' => $transport_id, 'project_id' => $projectId]);
                $original_transport = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$original_transport) continue;
                
                // 检查容量限制 - 仅在已分配车辆时进行验证
                $vehicle_id = $capacityValidator->getAssignedVehicleByTransportId($transport_id);
                
                // 使用新分配的人员数量
                $new_total_passengers = count($personnel_ids);
                
                if (!empty($vehicle_id)) {
                    $capacity_check = $capacityValidator->checkCapacity([
                        'travel_date' => $original_transport['travel_date'],
                        'travel_type' => $original_transport['travel_type'],
                        'departure_time' => $original_transport['departure_time'],
                        'arrival_time' => $original_transport['arrival_time'] ?? null,
                        'departure_location' => $original_transport['departure_location'],
                        'destination_location' => $original_transport['destination_location'],
                        'project_id' => $projectId
                    ], $new_total_passengers, $vehicle_id);
                } else {
                    // 未分配车辆时，容量验证通过
                    $capacity_check = ['success' => true];
                }
                
                if (!$capacity_check['success']) {
                    $db->rollBack();
                    // 使用更清晰的错误提示，并保留用户选择
                    $_SESSION['message'] = '❌ 超员警告：当前行程已登记人员加上新分配人员将超过车辆容量限制。' . $capacity_check['message'];
                    $_SESSION['message_type'] = 'danger';
                    $_SESSION['preserve_selection'] = true; // 标记保留用户选择
                    $_SESSION['last_selected_personnel'] = $personnel_ids;
                    $_SESSION['last_selected_transports'] = $transport_ids;
                    header('Location: transport_enhanced.php');
                    exit;
                }
                
                // 设置主要负责人（第一个选择的人员）
                // 修复：确保personnel_id不为null
                $main_personnel_id = !empty($personnel_ids) ? $personnel_ids[0] : ($_SESSION['personnel_id'] ?? $_SESSION['user_id'] ?? 1);
                
                // 更新主行程
                $updateQuery = "UPDATE transportation_reports 
                               SET personnel_id = :personnel_id, 
                                   passenger_count = :passenger_count 
                               WHERE id = :id AND project_id = :project_id";
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->execute([
                    'personnel_id' => $main_personnel_id,
                    'passenger_count' => $new_total_passengers,
                    'id' => $transport_id,
                    'project_id' => $projectId
                ]);
                
                // 先删除原有的乘客关联
                $delete_passengers_query = "DELETE FROM transportation_passengers WHERE transportation_report_id = :transport_id";
                $delete_passengers_stmt = $db->prepare($delete_passengers_query);
                $delete_passengers_stmt->execute(['transport_id' => $transport_id]);
                
                // 存储所有选择的人员与行程的关联
                foreach ($personnel_ids as $personnel_id) {
                    $passenger_query = "INSERT IGNORE INTO transportation_passengers (transportation_report_id, personnel_id) 
                                        VALUES (:transport_id, :personnel_id)";
                    $passenger_stmt = $db->prepare($passenger_query);
                    $passenger_stmt->execute(['transport_id' => $transport_id, 'personnel_id' => $personnel_id]);
                }
                
                $success_count++;
            }
            
            $db->commit();
            
            $_SESSION['message'] = "成功将 " . count($personnel_ids) . " 个人员分配到 " . count($transport_ids) . " 个行程";
            $_SESSION['message_type'] = 'success';
            
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['message'] = '分配失败：' . $e->getMessage();
            $_SESSION['message_type'] = 'danger';
        }
        
        header("Location: transport_enhanced.php");
        exit;
    }
}

// 获取当前项目的行程记录
$query = "SELECT tr.*, p.name as personnel_name, p.gender, f.fleet_number, f.license_plate
          FROM transportation_reports tr 
          LEFT JOIN personnel p ON tr.personnel_id = p.id 
          LEFT JOIN transportation_fleet_assignments tfa ON tr.id = tfa.transportation_report_id
          LEFT JOIN fleet f ON tfa.fleet_id = f.id
          WHERE tr.project_id = :project_id
          ORDER BY tr.travel_date ASC, tr.created_at DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':project_id', $projectId);
$stmt->execute();
$transports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取待分配行程（没有人员）
$pending_query = "SELECT tr.*, f.fleet_number, f.license_plate
                 FROM transportation_reports tr 
                 LEFT JOIN transportation_fleet_assignments tfa ON tr.id = tfa.transportation_report_id
                 LEFT JOIN fleet f ON tfa.fleet_id = f.id
                 WHERE tr.project_id = :project_id AND tr.personnel_id IS NULL 
                 ORDER BY tr.travel_date ASC";
$pending_stmt = $db->prepare($pending_query);
$pending_stmt->bindParam(':project_id', $projectId);
$pending_stmt->execute();
$pending_transports = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);

// 显示消息
$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

// 页面设置
$page_title = '增强版运输管理 - ' . ($_SESSION['project_name'] ?? '');
$active_page = 'transport_enhanced';
$show_page_title = '增强版运输管理';
$page_icon = 'truck';
$page_action_text = '返回车辆管理';
$page_action_url = 'project_fleet.php';

include 'includes/header.php';
?>

<style>
/* 整体紧凑化样式 */
.route-row {
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 12px;
    transition: all 0.2s ease;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    background: #fff;
}

/* 为不同行程设置左边框颜色 - 更简洁 */
.route-row:nth-child(4n+1) {
    border-left: 3px solid #007bff;
}

.route-row:nth-child(4n+2) {
    border-left: 3px solid #28a745;
}

.route-row:nth-child(4n+3) {
    border-left: 3px solid #17a2b8;
}

.route-row:nth-child(4n+4) {
    border-left: 3px solid #ffc107;
}

.route-row:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.12);
}

.route-header {
    font-weight: 600;
    color: #495057;
    margin-bottom: 10px;
    font-size: 1rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-bottom: 8px;
    border-bottom: 1px solid #e9ecef;
}

.route-header i {
    margin-right: 6px;
}

/* 紧凑化表单间距 */
.route-row .row {
    margin-bottom: 8px;
}

.route-row .row:last-child {
    margin-bottom: 0;
}

.form-label {
    font-size: 0.875rem;
    margin-bottom: 4px;
    font-weight: 500;
}

.form-control, .form-select {
    padding: 6px 10px;
    font-size: 0.875rem;
}

.form-control-sm {
    padding: 4px 8px;
    font-size: 0.8rem;
}

/* 卡片优化 */
.card {
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border: 1px solid #e3e6f0;
}

.card-header {
    padding: 12px 16px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-bottom: none;
}

.card-header h5 {
    margin-bottom: 0;
    color: white;
    font-weight: 600;
}

.card-body {
    padding: 16px;
}

/* 乘客选择区域优化 */
.personnel-options {
    max-height: 160px;
    overflow-y: auto;
}

.personnel-item {
    margin-bottom: 0.2rem;
    padding: 0.1rem 0.3rem 0.1rem 0;
}

.personnel-item .form-check {
    margin-bottom: 0.15rem;
    padding: 0.3rem 0.3rem 0.3rem 1.5rem;
    border-radius: 0.25rem;
    transition: all 0.2s;
    display: flex;
    align-items: flex-start;
    border: 1px solid transparent;
}

.personnel-item .form-check:hover {
    background-color: #f8f9fa;
}

.personnel-item .form-check-input:checked + .form-check-label {
    color: #0d6efd;
    font-weight: 500;
}

.personnel-item .form-check:has(.form-check-input:checked) {
    background-color: #e7f3ff;
    border-color: #0d6efd;
    box-shadow: 0 0 0 1px rgba(13, 110, 253, 0.2);
}

.personnel-item .form-check-label {
    font-size: 0.75rem;
    line-height: 1.3;
    padding-left: 0.5rem;
    flex: 1;
}

.personnel-item .form-check-input {
    margin-right: 0.5rem;
    margin-top: 0.1rem;
    flex-shrink: 0;
}

/* 按钮优化 */
.btn {
    padding: 6px 12px;
    font-size: 0.875rem;
    border-radius: 6px;
}

.btn-sm {
    padding: 4px 8px;
    font-size: 0.8rem;
}

/* 车型需求区域优化 */
.vehicle-requirements {
    max-height: 80px;
    overflow-y: auto;
}

.vehicle-requirements .d-flex {
    gap: 8px;
}

/* 响应式调整 */
@media (max-width: 768px) {
    .route-row {
        padding: 10px 12px;
        margin-bottom: 10px;
    }
    
    .route-header {
        font-size: 0.95rem;
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    
    .card-body {
        padding: 12px;
    }
    
    .personnel-options {
        max-height: 120px;
    }
}

/* 乘客数量显示样式 */
.passenger-display {
    font-size: 0.8rem;
    font-weight: 500;
}

.selected-count-display {
    font-weight: 700;
    font-size: 0.9rem;
    color: #fff;
}

/* 状态徽章 */
.status-badge {
    font-size: 0.75em;
}

/* 表格优化 */
.table-responsive {
    border-radius: 6px;
    overflow: hidden;
}

.table th {
    font-size: 0.875rem;
    font-weight: 600;
    padding: 8px 12px;
    background-color: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
}

.table td {
    font-size: 0.875rem;
    padding: 8px 12px;
    vertical-align: middle;
}

/* 搜索框和筛选优化 */
.search-filter-row {
    background-color: #f8f9fa;
    padding: 8px;
    border-radius: 4px;
    margin-bottom: 8px;
}

/* 部门筛选下拉框优化 */
.department-filter {
    max-width: 200px;
}

/* 徽章和标签优化 */
.badge {
    font-size: 0.75rem;
    padding: 4px 8px;
}

/* 紧凑化间距 */
.mb-4 {
    margin-bottom: 1rem !important;
}

.mt-3 {
    margin-top: 0.75rem !important;
}

.mb-3 {
    margin-bottom: 0.75rem !important;
}

/* 优化alert样式 */
.alert {
    padding: 8px 12px;
    margin-bottom: 12px;
    border-radius: 6px;
}

/* 到达时间输入框样式 - 浅橘色主题（与 quick_transport.php 保持一致） */
.arrival-time-input:focus {
    border-color: #fd7e14;
    box-shadow: 0 0 0 0.25rem rgba(253, 126, 20, 0.25);
}

.arrival-time-input {
    border-color: #fd7e14;
}

/* 隐藏独立“到达时间”列（按需求不再单独填写） */
.arrival-time-col {
    display: none !important;
}
</style>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?php echo $message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-12">
        <!-- 快速创建行程 -->
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="bi bi-plus-circle"></i> 快速创建点对点行程
                    <small class="ms-2 opacity-75">支持一次性创建多个行程</small>
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" id="routeForm">
                    <div id="routesContainer">
                        <!-- 行程1样式优化 - 更紧凑的边框和背景 -->
                        <div class="route-row" data-index="0" style="border: 2px solid #0d6efd; border-radius: 0.375rem; background-color: #f8f9ff; box-shadow: 0 0.125rem 0.25rem rgba(13, 110, 253, 0.1);">
                            <div class="route-header">
                                <div class="d-flex align-items-center">
                                    <!-- 行程1标题样式优化 - 更紧凑的大字体标题 -->
                                    <span style="font-size: 1.1rem; font-weight: 600; color: #0d6efd;">
                                        <i class="bi bi-geo-alt-fill"></i> 行程 1
                                    </span>
                                    <span class="badge bg-primary ms-2 passenger-display">
                                        乘客 <strong class="selected-count-display" data-index="0">0</strong> 人
                                    </span>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRoute(this)" title="删除此行程">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                            <!-- 乘客数量隐藏字段 -->
                            <input type="hidden" name="routes[0][passenger_count]" class="passenger-count" value="0">
                            <div class="row g-2">
                                <div class="col-md-3 col-6">
                                    <label class="form-label">出行日期 <span class="text-danger">*</span></label>
                                    <input type="date" name="routes[0][travel_date]" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="col-md-3 col-6">
                                    <label class="form-label">交通类型</label>
                                    <select name="routes[0][travel_type]" class="form-select travel-type-select" data-index="0" onchange="updateLocationOptions(0)">
                                        <option value="接机/站">接机/站</option>
                                        <option value="送机/站">送机/站</option>
                                        <option value="混合交通安排（自定义）">混合交通安排（自定义）</option>
                                        <option value="点对点">点对点</option>
                                    </select>
                                </div>
                                <div class="col-md-3 col-6">
                                    <label class="form-label time-label" data-index="0">出发时间</label>
                                    <input type="time" name="routes[0][departure_time]" class="form-control time-input" data-index="0">
                                </div>
                                <div class="col-md-3 col-6 arrival-time-col">
                                    <label class="form-label">到达时间 <small class="text-muted">(可选)</small></label>
                                    <input type="time" name="routes[0][arrival_time]" class="form-control">
                                </div>
                            </div>
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <label class="form-label">出发地点 <span class="text-danger">*</span></label>
                                    <!-- 下拉选择框 - 默认显示 -->
                                    <select name="routes[0][departure_location]" class="form-select departure-location-select" id="departure_select_0" required>
                                        <option value="">请选择出发地点</option>
                                        <?php foreach ($locations as $location): ?>
                                            <option value="<?php echo htmlspecialchars($location); ?>"><?php echo htmlspecialchars($location); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <!-- 自定义输入框 - 初始隐藏，用于混合交通安排模式 -->
                                    <input type="text" name="routes[0][departure_location_custom]" class="form-control departure-location-input d-none" id="departure_input_0" placeholder="请输入自定义出发地点">
                                </div>
                                <!-- 交换按钮列 - 只在点对点和混合交通安排模式下显示 -->
                                <div class="col-md-1 d-flex align-items-end justify-content-center" id="swap_button_col_0" style="display: none;">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="swapLocations(0)" title="交换出发地和目的地">
                                        <i class="bi bi-arrow-left-right"></i>
                                    </button>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">目的地点 <span class="text-danger">*</span></label>
                                    <!-- 下拉选择框 - 默认显示 -->
                                    <select name="routes[0][destination_location]" class="form-select destination-location-select" id="destination_select_0" required>
                                        <option value="">请选择目的地点</option>
                                        <?php foreach ($locations as $location): ?>
                                            <option value="<?php echo htmlspecialchars($location); ?>"><?php echo htmlspecialchars($location); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <!-- 自定义输入框 - 初始隐藏，用于混合交通安排模式 -->
                                    <input type="text" name="routes[0][destination_location_custom]" class="form-control destination-location-input d-none" id="destination_input_0" placeholder="请输入自定义目的地点">
                                </div>
                                <!-- 联系电话重新放在同一行 -->
                                <div class="col-md-3">
                                    <label class="form-label">联系电话</label>
                                    <input type="tel" name="routes[0][contact_phone]" class="form-control" placeholder="请输入联系电话">
                                </div>
                            </div>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label">车型需求 <small class="text-muted">可多选，可设置数量</small></label>
                                    <!-- 车型需求横向排列样式优化 - 改为横向布局 -->
                                    <div class="border rounded p-2 vehicle-requirements">
                                        <div class="d-flex flex-wrap gap-2">
                                            <?php
                                            $vehicle_types = [
                                                'car' => '轿车',
                                                'van' => '商务车',
                                                'minibus' => '中巴车',
                                                'bus' => '大巴车',
                                                'truck' => '货车',
                                                'other' => '其他'
                                            ];
                                            foreach ($vehicle_types as $key => $label): 
                                            ?>
                                            <div class="d-flex align-items-center">
                                                <div class="form-check mb-0 d-flex align-items-center">
                                                    <input class="form-check-input vehicle-type-checkbox" type="checkbox" 
                                                           name="routes[0][vehicle_requirements][<?php echo $key; ?>][type]" 
                                                           value="<?php echo $key; ?>" 
                                                           id="vehicle_req_0_<?php echo $key; ?>"
                                                           onchange="toggleVehicleQuantity(this, '<?php echo $key; ?>', 0)">
                                                    <label class="form-check-label small me-1 ms-1" for="vehicle_req_0_<?php echo $key; ?>">
                                                        <?php echo $label; ?>
                                                    </label>
                                                    <input type="number" 
                                                           name="routes[0][vehicle_requirements][<?php echo $key; ?>][quantity]" 
                                                           id="vehicle_quantity_0_<?php echo $key; ?>"
                                                           class="form-control form-control-sm" 
                                                           style="width: 40px; display: none; padding: 0.2rem 0.3rem; font-size: 0.75rem;" 
                                                           min="1" max="10" 
                                                           value="1"
                                                           placeholder="数量">
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">特殊要求</label>
                                    <input type="text" name="routes[0][special_requirements]" class="form-control" placeholder="如：需要儿童座椅、行李较多等">
                                </div>
                            </div>
                            <div class="row g-2">
                                <div class="col-12">
                                    <label class="form-label">选择乘客（来自人员库）：</label>
                                    <!-- 按部门选择功能优化 -->
                                    <div class="search-filter-row">
                                        <div class="row g-2">
                                            <div class="col-md-6">
                                                <input type="text" class="form-control form-control-sm personnel-search" placeholder="搜索人员姓名..." data-index="0">
                                            </div>
                                            <div class="col-md-6">
                                                <select class="form-select form-select-sm department-filter" data-index="0">
                                                    <option value="">全部部门</option>
                                                    <?php foreach ($departments as $dept): ?>
                                                        <option value="<?php echo htmlspecialchars($dept['name']); ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="border rounded" style="max-height: 140px; overflow-y: auto; padding: 0.5rem 0.3rem 0.5rem 2rem;">
                                        <!-- 人员多选多列排列优化 -->
                                        <div class="personnel-options row g-1" style="margin: 0 -0.5rem;">
                                            <?php 
                                            if (!empty($personnel)) {
                                                foreach ($personnel as $person) {
                                                    $dept_names = isset($person['departments']) ? $person['departments'] : '';
                                                    $dept_attr = $dept_names ? ' data-department="' . htmlspecialchars($dept_names) . '"' : ' data-department=""';
                                                    echo '<div class="col-6 col-md-4 col-lg-3 personnel-item"' . $dept_attr . '>';
                                                    echo '<div class="form-check small">';
                                                    echo '<input class="form-check-input personnel-checkbox" type="checkbox" name="routes[0][personnel_ids][]" value="' . $person['id'] . '" id="person_0_' . $person['id'] . '">';
                                                    echo '<label class="form-check-label" for="person_0_' . $person['id'] . '" style="font-size: 0.75rem; line-height: 1.2;">';
                                                    $position_names = isset($person['positions']) ? $person['positions'] : '';
                                                    echo '<div class="d-flex align-items-center mb-0">';
                                                    echo '<span class="fw-bold">' . htmlspecialchars($person['name']) . '</span>';
                                                    if ($position_names) {
                                                        echo '<span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-2 py-1 ms-1" style="font-size: 0.65rem;">' . htmlspecialchars($position_names) . '</span>';
                                                    }
                                                    echo '</div>';
                                                    if ($dept_names) {
                                                        echo '<div><small class="text-muted" style="font-size: 0.65rem; line-height: 1.2;">' . htmlspecialchars($dept_names) . '</small></div>';
                                                    }
                                                    echo '</label></div></div>';
                                                }
                                            } else {
                                                echo '<div class="col-12 text-center text-muted py-2"><small>暂无人员数据</small><br><a href="personnel.php" class="btn btn-sm btn-outline-primary mt-1">前往人员管理</a></div>';
                                            }
                                            ?>
                                        </div>
                                        <div class="mt-2 pt-2 border-top">
                                            <small class="text-muted">已选择：<span class="selected-count" data-index="0">0</span>人</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="addRoute()">
                            <i class="bi bi-plus"></i> 添加更多行程
                        </button>
                        <button type="submit" name="create_routes" class="btn btn-primary">
                            <i class="bi bi-check"></i> 批量创建行程
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 待分配行程 -->
        <?php if (!empty($pending_transports)): ?>
            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-person-plus"></i> 待分配人员行程
                        <span class="badge bg-warning text-dark ms-2"><?php echo count($pending_transports); ?></span>
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="row g-3">
                            <div class="col-lg-7">
                                <h6 class="mb-2">选择行程：</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="40"><input type="checkbox" id="selectAllTransports" class="form-check-input"></th>
                                                <th>日期</th>
                                                <th>路线</th>
                                                <th>时间</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($pending_transports as $transport): ?>
                                                <tr>
                                                    <td>
                                                        <input type="checkbox" name="transport_ids[]" value="<?php echo $transport['id']; ?>" class="form-check-input">
                                                    </td>
                                                    <td><small><?php echo $transport['travel_date']; ?></small></td>
                                                    <td>
                                                        <small class="text-truncate d-block" style="max-width: 200px;" title="<?php echo $transport['departure_location']; ?> → <?php echo $transport['destination_location']; ?>">
                                                            <?php echo $transport['departure_location']; ?> → <?php echo $transport['destination_location']; ?>
                                                        </small>
                                                    </td>
                                                    <td><small><?php echo $transport['departure_time'] ?? '-'; ?></small></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="col-lg-5">
                                <h6 class="mb-2">选择乘客（来自人员库）：</h6>
                                <div class="border rounded" style="max-height: 240px; overflow-y: auto; padding: 0.5rem 0.3rem 0.5rem 2rem;">
                                    <div class="search-filter-row mb-2">
                                        <div class="row g-2">
                                            <div class="col-12">
                                                <input type="text" id="personnelSearch" class="form-control form-control-sm" placeholder="搜索人员姓名...">
                                            </div>
                                            <div class="col-12">
                                                <select id="departmentFilter" class="form-select form-select-sm">
                                                    <option value="">全部部门</option>
                                                    <?php foreach ($departments as $dept): ?>
                                                        <option value="<?php echo htmlspecialchars($dept['name']); ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div id="personnelList">
                                        <?php foreach ($personnel as $person): 
                                            $dept_names = isset($person['departments']) ? $person['departments'] : '';
                                            $position_names = isset($person['positions']) ? $person['positions'] : '';
                                        ?>
                                        <div class="form-check personnel-item" data-name="<?php echo htmlspecialchars(strtolower($person['name'])); ?>" data-department="<?php echo htmlspecialchars($dept_names); ?>">
                                            <input class="form-check-input" type="checkbox" name="personnel_ids[]" value="<?php echo $person['id']; ?>" id="person_<?php echo $person['id']; ?>">
                                            <label class="form-check-label small" for="person_<?php echo $person['id']; ?>">
                                                <div class="d-flex align-items-center mb-1">
                                                    <span class="fw-bold"><?php echo htmlspecialchars($person['name']); ?></span>
                                                    <?php if ($position_names): ?>
                                                        <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-2 py-1 ms-1" style="font-size: 0.65rem;"><?php echo htmlspecialchars($position_names); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($dept_names): ?>
                                                    <div><small class="text-muted" style="font-size: 0.65rem; line-height: 1.2;"><?php echo htmlspecialchars($dept_names); ?></small></div>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($personnel)): ?>
                                        <div class="text-center text-muted py-3">
                                            <i class="bi bi-people"></i><br>
                                            <small>暂无人员数据</small><br>
                                            <a href="personnel.php" class="btn btn-sm btn-outline-primary mt-2">
                                                <i class="bi bi-plus"></i> 前往人员管理
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-2">
                                    <small class="text-muted">已选择：<span id="selectedCount">0</span>人</small>
                                </div>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-auto">
                                <button type="submit" name="assign_personnel" class="btn btn-primary">
                                    <i class="bi bi-people-fill"></i> 批量分配人员
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<script>
let routeIndex = 1;

// 将 PHP 地点数据转换为 JavaScript 变量
const transportLocations = <?php echo json_encode($transport_locations); ?>;
const venueLocations = <?php echo json_encode($venue_locations); ?>;
const allLocations = <?php echo json_encode($locations); ?>;

// 调试输出
console.log('交通地点（机场和高铁站）:', transportLocations);
console.log('场地地点（项目场地和酒店）:', venueLocations);
console.log('所有地点:', allLocations);

function addRoute() {
    // 生成人员选项HTML
    let personnelOptions = '';
    <?php 
    if (!empty($personnel)) {
        foreach ($personnel as $person): 
    ?>
        const deptNames<?php echo $person['id']; ?> = '<?php echo addslashes(isset($person['departments']) ? $person['departments'] : ""); ?>';
        const positionNames<?php echo $person['id']; ?> = '<?php echo addslashes(isset($person['positions']) ? $person['positions'] : ""); ?>';
        // 人员多选多列排列优化开始
        personnelOptions += `
            <div class="col-6 col-md-4 col-lg-3 personnel-item" data-department="<?php echo addslashes(isset($person['departments']) ? $person['departments'] : ''); ?>">
                <div class="form-check small">
                    <input class="form-check-input personnel-checkbox" type="checkbox" name="routes[${routeIndex}][personnel_ids][]" value="<?php echo $person['id']; ?>" id="person_${routeIndex}_<?php echo $person['id']; ?>">
                    <label class="form-check-label" for="person_${routeIndex}_<?php echo $person['id']; ?>" style="font-size: 0.75rem;">
                        <div class="d-flex align-items-center mb-1">
                            <span class="fw-bold"><?php echo addslashes($person['name']); ?></span>
                            ${positionNames<?php echo $person['id']; ?> ? '<span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-2 py-1 ms-1" style="font-size: 0.65rem;">' + positionNames<?php echo $person['id']; ?> + '</span>' : ''}
                        </div>
                        ${deptNames<?php echo $person['id']; ?> ? '<div><small class="text-muted" style="font-size: 0.65rem; line-height: 1.2;">' + deptNames<?php echo $person['id']; ?> + '</small></div>' : ''}
                    </label>
                </div>
            </div>
        `;
    <?php 
        endforeach; 
    } else {
    ?>
        // 人员数据为空时的显示
        personnelOptions = `
            <div class="col-12 text-center text-muted py-3">
                <i class="bi bi-people"></i><br>
                暂无人员数据<br>
                <a href="personnel.php" class="btn btn-sm btn-outline-primary mt-2">
                    <i class="bi bi-plus"></i> 前往人员管理
                </a>
            </div>
        `;
    <?php } ?>
    
    const container = document.getElementById('routesContainer');
    const newRoute = document.createElement('div');
    newRoute.className = 'route-row';
    newRoute.setAttribute('data-index', routeIndex);
    
    newRoute.innerHTML = `
        <div class="route-header">
            <div class="d-flex align-items-center">
                <span style="font-size: 1.1rem; font-weight: 600; color: #495057;">
                    <i class="bi bi-geo-alt"></i> 行程 ${routeIndex + 1}
                </span>
                <span class="badge bg-primary ms-2 passenger-display">
                    乘客 <strong class="selected-count-display" data-index="${routeIndex}">0</strong> 人
                </span>
            </div>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRoute(this)" title="删除此行程">
                <i class="bi bi-trash"></i>
            </button>
        </div>
        <!-- 乘客数量隐藏字段 -->
        <input type="hidden" name="routes[${routeIndex}][passenger_count]" class="passenger-count" value="0">
        <div class="row g-2">
            <div class="col-md-3 col-6">
                <label class="form-label">出行日期 <span class="text-danger">*</span></label>
                <input type="date" name="routes[${routeIndex}][travel_date]" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="col-md-3 col-6">
                <label class="form-label">交通类型</label>
                <select name="routes[${routeIndex}][travel_type]" class="form-select travel-type-select" data-index="${routeIndex}" onchange="updateLocationOptions(${routeIndex})">
                    <option value="接机/站">接机/站</option>
                    <option value="送机/站">送机/站</option>
                    <option value="混合交通安排（自定义）">混合交通安排（自定义）</option>
                    <option value="点对点">点对点</option>
                </select>
            </div>
            <div class="col-md-3 col-6">
                <label class="form-label time-label" data-index="${routeIndex}">出发时间</label>
                <input type="time" name="routes[${routeIndex}][departure_time]" class="form-control time-input" data-index="${routeIndex}">
            </div>
            <div class="col-md-3 col-6 arrival-time-col">
                <label class="form-label">到达时间 <small class="text-muted">(可选)</small></label>
                <input type="time" name="routes[${routeIndex}][arrival_time]" class="form-control">
            </div>
        </div>
        <div class="row g-2">
            <div class="col-md-4">
                <label class="form-label">出发地点 <span class="text-danger">*</span></label>
                <!-- 下拉选择框 - 默认显示 -->
                <select name="routes[${routeIndex}][departure_location]" class="form-select departure-location-select" id="departure_select_${routeIndex}" required>
                    <option value="">请选择出发地点</option>
                    <!-- 动态生成选项 -->
                </select>
                <!-- 自定义输入框 - 初始隐藏，用于混合交通安排模式 -->
                <input type="text" name="routes[${routeIndex}][departure_location_custom]" class="form-control departure-location-input d-none" id="departure_input_${routeIndex}" placeholder="请输入自定义出发地点">
            </div>
            <!-- 交换按钮列 - 只在点对点和混合交通安排模式下显示 -->
            <div class="col-md-1 d-flex align-items-end justify-content-center" id="swap_button_col_${routeIndex}" style="display: none;">
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="swapLocations(${routeIndex})" title="交换出发地和目的地">
                    <i class="bi bi-arrow-left-right"></i>
                </button>
            </div>
            <div class="col-md-4">
                <label class="form-label">目的地点 <span class="text-danger">*</span></label>
                <!-- 下拉选择框 - 默认显示 -->
                <select name="routes[${routeIndex}][destination_location]" class="form-select destination-location-select" id="destination_select_${routeIndex}" required>
                    <option value="">请选择目的地点</option>
                    <!-- 动态生成选项 -->
                </select>
                <!-- 自定义输入框 - 初始隐藏，用于混合交通安排模式 -->
                <input type="text" name="routes[${routeIndex}][destination_location_custom]" class="form-control destination-location-input d-none" id="destination_input_${routeIndex}" placeholder="请输入自定义目的地点">
            </div>
            <!-- 联系电话重新放在同一行 -->
            <div class="col-md-3">
                <label class="form-label">联系电话</label>
                <input type="tel" name="routes[${routeIndex}][contact_phone]" class="form-control" placeholder="请输入联系电话">
            </div>
        </div>
        <div class="row g-2">
            <div class="col-md-6">
                <label class="form-label">车型需求 <small class="text-muted">可多选，可设置数量</small></label>
                <div class="border rounded p-2 vehicle-requirements">
                    <div class="d-flex flex-wrap gap-2">
                        <?php
                        // 车型需求横向排列功能 - 动态生成横向车型选项
                        $vehicle_types = [
                            'car' => '轿车',
                            'van' => '商务车',
                            'minibus' => '中巴车',
                            'bus' => '大巴车',
                            'truck' => '货车',
                            'other' => '其他'
                        ];
                        foreach ($vehicle_types as $key => $label): 
                        ?>
                        <!-- 车型需求横向排列功能 - 动态模板横向布局 -->
                        <div class="d-flex align-items-center">
                            <div class="form-check mb-0 d-flex align-items-center">
                                <input class="form-check-input vehicle-type-checkbox" type="checkbox" 
                                       name="routes[${routeIndex}[vehicle_requirements][<?php echo $key; ?>][type]" 
                                       value="<?php echo $key; ?>" 
                                       id="vehicle_req_${routeIndex}_<?php echo $key; ?>"
                                       onchange="toggleVehicleQuantity(this, '<?php echo $key; ?>', ${routeIndex})">
                                <label class="form-check-label small me-1 ms-1" for="vehicle_req_${routeIndex}_<?php echo $key; ?>">
                                    <?php echo $label; ?>
                                </label>
                                <input type="number" 
                                       name="routes[${routeIndex}[vehicle_requirements][<?php echo $key; ?>][quantity]" 
                                       id="vehicle_quantity_${routeIndex}_<?php echo $key; ?>"
                                       class="form-control form-control-sm" 
                                       style="width: 40px; display: none; padding: 0.2rem 0.3rem; font-size: 0.75rem;" 
                                       min="1" max="10" 
                                       value="1"
                                       placeholder="数量">
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label">要求备注</label>
                <input type="text" name="routes[${routeIndex}][special_requirements]" class="form-control" placeholder="如：需要儿童座椅、行李较多等">
            </div>
        </div>
        <div class="row mt-3">
            <div class="col-12">
                <label class="form-label">选择乘客（来自人员库）：</label>
                <div class="border rounded" style="max-height: 200px; overflow-y: auto; padding: 0.5rem 0.3rem 0.5rem 2rem;">
                    <!-- 按部门选择功能开始 -->
                    <div class="row mb-2" style="margin: 0 -0.5rem;">
                        <div class="col-md-6">
                            <input type="text" class="form-control form-control-sm personnel-search" placeholder="搜索人员..." data-index="${routeIndex}">
                        </div>
                        <div class="col-md-6">
                            <select class="form-select form-select-sm department-filter" data-index="${routeIndex}">
                                <option value="">全部部门 - 点击可按部门筛选人员</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept['name']); ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <!-- 按部门选择功能结束 -->
                    <!-- 人员多选多列排列优化开始 -->
                    <div class="personnel-options row g-1" style="margin: 0 -0.5rem;">
                        ${personnelOptions}
                    </div>
                    <!-- 人员多选多列排列优化结束 -->
                    <small class="text-muted">已选择：<span class="selected-count" data-index="${routeIndex}">0</span>人</small>
                </div>
            </div>
        </div>
    `;
    
    container.appendChild(newRoute);
    routeIndex++;
    
    // 为新添加的行程绑定人员选择事件
    setupPersonnelSelection(newRoute, routeIndex - 1);
    // 初始化新行程的地点选项
    updateLocationOptions(routeIndex - 1);
}

function removeRoute(button) {
    const routeRow = button.closest('.route-row');
    routeRow.remove();
    
    // 重新编号
    const routes = document.querySelectorAll('.route-row');
    routes.forEach((route, index) => {
        const header = route.querySelector('.route-header > div');
        if (header) {
            header.innerHTML = `<i class="bi bi-geo-alt"></i> 行程 ${index + 1}
                <span class="badge bg-primary ms-2 passenger-display">
                    乘客 <strong class="selected-count-display" data-index="${index}">0</strong> 人
                </span>`;
        }
        
        // 更新删除按钮
        const deleteBtn = route.querySelector('.route-header .btn-outline-danger');
        if (deleteBtn) {
            deleteBtn.outerHTML = `<button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRoute(this)">
                <i class="bi bi-trash"></i>
            </button>`;
        }
    });
}

// 全选功能
const selectAllTransports = document.getElementById('selectAllTransports');
if (selectAllTransports) {
    selectAllTransports.addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('input[name="transport_ids[]"]');
        checkboxes.forEach(checkbox => {
            checkbox.checked = this.checked;
        });
    });
}

// 人员搜索和部门筛选功能
const personnelSearch = document.getElementById('personnelSearch');
const departmentFilter = document.getElementById('departmentFilter');
const personnelItems = document.querySelectorAll('.personnel-item');
const personnelCheckboxes = document.querySelectorAll('input[name="personnel_ids[]"]');

function filterPersonnelList() {
    if (!personnelItems || personnelItems.length === 0) return;
    
    const searchTerm = personnelSearch ? personnelSearch.value.toLowerCase() : '';
    const selectedDept = departmentFilter ? departmentFilter.value : ''; // 不转换大小写
    
    personnelItems.forEach(item => {
        const name = item.dataset.name || '';
        const dept = item.dataset.department || '';
        
        // 同时检查搜索词和部门筛选
        const matchesSearch = !searchTerm || name.includes(searchTerm) || dept.toLowerCase().includes(searchTerm);
        const matchesDept = !selectedDept || dept === selectedDept; // 精确匹配部门名称
        
        if (matchesSearch && matchesDept) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
}

if (personnelSearch) {
    personnelSearch.addEventListener('input', filterPersonnelList);
}

if (departmentFilter) {
    departmentFilter.addEventListener('change', filterPersonnelList);
}

// 更新已选择人数
function updateSelectedCount() {
    const selected = document.querySelectorAll('input[name="personnel_ids[]"]:checked');
    const countElement = document.getElementById('selectedCount');
    if (countElement) {
        countElement.textContent = selected.length;
    }
}

// 监听复选框变化
if (personnelCheckboxes.length > 0) {
    personnelCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateSelectedCount();
            updateCheckboxStyle(this);
        });
        // 初始化样式
        updateCheckboxStyle(checkbox);
    });
    
    // 初始化计数
    updateSelectedCount();
}

// 根据交通类型更新地点选项 - 实现 quick_transport.php 的逻辑
function updateLocationOptions(routeIndex) {
    console.log('更新地点选项，行程索引:', routeIndex);
    
    const travelTypeSelect = document.querySelector(`select[name="routes[${routeIndex}][travel_type]"]`);
    if (!travelTypeSelect) {
        console.log('未找到交通类型选择框');
        return;
    }
    
    const travelType = travelTypeSelect.value;
    console.log('当前交通类型:', travelType);
    const departureSelect = document.getElementById(`departure_select_${routeIndex}`);
    const destinationSelect = document.getElementById(`destination_select_${routeIndex}`);
    const departureInput = document.getElementById(`departure_input_${routeIndex}`);
    const destinationInput = document.getElementById(`destination_input_${routeIndex}`);
    const swapButtonCol = document.getElementById(`swap_button_col_${routeIndex}`);
    // 新增：时间标签与时间输入（用于切换到达时间样式）
    const timeLabel = document.querySelector(`.time-label[data-index="${routeIndex}"]`);
    const timeInput = document.querySelector(`.time-input[data-index="${routeIndex}"]`);
    
    console.log('元素查找结果:', {
        departureSelect: !!departureSelect,
        destinationSelect: !!destinationSelect,
        departureInput: !!departureInput,
        destinationInput: !!destinationInput,
        swapButtonCol: !!swapButtonCol
    });
    
    if (!departureSelect || !destinationSelect || !departureInput || !destinationInput || !swapButtonCol) {
        console.log('缺少必要的DOM元素，退出更新');
        return;
    }

    // 与 quick_transport 同步：根据交通类型更新时间标签与样式，仅切换显示文本与输入框外观，不改变提交字段名
    if (timeLabel && timeInput) {
        if (travelType === '接机/站') {
            timeLabel.textContent = '到达时间 *';
            timeLabel.style.color = '#fd7e14'; // 浅橘色
            timeInput.classList.add('arrival-time-input');
        } else {
            timeLabel.textContent = '出发时间 *';
            timeLabel.style.color = '';
            timeInput.classList.remove('arrival-time-input');
        }
    }
    
    // 保存当前选中的值
    const currentDeparture = departureSelect.value;
    const currentDestination = destinationSelect.value;
    
    // 根据交通类型控制交换按钮的显示/隐藏和列宽度调整
    if (travelType === '点对点' || travelType === '混合交通安排（自定义）') {
        // 显示交换按钮，使用 4-1-4-3 布局
        swapButtonCol.style.display = 'flex';
        swapButtonCol.style.visibility = 'visible';
        
        // 调整出发地和目的地列宽度
        const departureCol = departureSelect.closest('[class*="col-md-"]');
        const destinationCol = destinationSelect.closest('[class*="col-md-"]');
        if (departureCol) {
            departureCol.className = departureCol.className.replace(/col-md-\d+/, 'col-md-4');
        }
        if (destinationCol) {
            destinationCol.className = destinationCol.className.replace(/col-md-\d+/, 'col-md-4');
        }
        
        console.log('显示交换按钮 - 交通类型:', travelType);
    } else {
        // 隐藏交换按钮（接机/站 和 送机/站），调整为更紧凑布局
        swapButtonCol.style.display = 'none';
        swapButtonCol.style.visibility = 'hidden';
        
        // 调整出发地和目的地列宽度，保持4-4-4布局（联系电话仍然占col-md-3）
        const departureCol = departureSelect.closest('[class*="col-md-"]');
        const destinationCol = destinationSelect.closest('[class*="col-md-"]');
        if (departureCol) {
            departureCol.className = departureCol.className.replace(/col-md-\d+/, 'col-md-4');
        }
        if (destinationCol) {
            destinationCol.className = destinationCol.className.replace(/col-md-\d+/, 'col-md-4');
        }
        
        console.log('隐藏交换按钮 - 交通类型:', travelType);
    }
    
    if (travelType === '混合交通安排（自定义）') {
        // 混合交通安排：显示自定义输入框
        departureSelect.classList.add('d-none');
        destinationSelect.classList.add('d-none');
        departureInput.classList.remove('d-none');
        destinationInput.classList.remove('d-none');
        
        // 更新 name 和 required 属性以确保表单提交正确的字段
        departureSelect.removeAttribute('name');
        departureSelect.removeAttribute('required');
        destinationSelect.removeAttribute('name');
        destinationSelect.removeAttribute('required');
        departureInput.setAttribute('name', `routes[${routeIndex}][departure_location]`);
        departureInput.setAttribute('required', 'required');
        destinationInput.setAttribute('name', `routes[${routeIndex}][destination_location]`);
        destinationInput.setAttribute('required', 'required');
        
        return;
    } else {
        // 其他模式：显示下拉选择框
        departureSelect.classList.remove('d-none');
        destinationSelect.classList.remove('d-none');
        departureInput.classList.add('d-none');
        destinationInput.classList.add('d-none');
        
        // 更新 name 和 required 属性
        departureInput.removeAttribute('name');
        departureInput.removeAttribute('required');
        destinationInput.removeAttribute('name');
        destinationInput.removeAttribute('required');
        departureSelect.setAttribute('name', `routes[${routeIndex}][departure_location]`);
        departureSelect.setAttribute('required', 'required');
        destinationSelect.setAttribute('name', `routes[${routeIndex}][destination_location]`);
        destinationSelect.setAttribute('required', 'required');
    }
    
    // 清空选项
    departureSelect.innerHTML = '';
    destinationSelect.innerHTML = '';
    
    // 添加默认选项
    const departureDefault = document.createElement('option');
    departureDefault.value = '';
    departureDefault.textContent = '请选择出发地点';
    departureSelect.appendChild(departureDefault);
    
    const destinationDefault = document.createElement('option');
    destinationDefault.value = '';
    destinationDefault.textContent = '请选择目的地点';
    destinationSelect.appendChild(destinationDefault);
    
    // 根据交通类型动态添加地点选项
    if (travelType === '接机/站') {
        // 接机/站：出发地点为机场和高铁站，到达地点为项目场地和酒店
        transportLocations.forEach(location => {
            const depOption = document.createElement('option');
            depOption.value = location;
            depOption.textContent = location;
            departureSelect.appendChild(depOption);
        });
        
        venueLocations.forEach(location => {
            const destOption = document.createElement('option');
            destOption.value = location;
            destOption.textContent = location;
            destinationSelect.appendChild(destOption);
        });
        
    } else if (travelType === '送机/站') {
        // 送机/站：出发地点为项目场地和酒店，到达地点为机场和高铁站
        venueLocations.forEach(location => {
            const depOption = document.createElement('option');
            depOption.value = location;
            depOption.textContent = location;
            departureSelect.appendChild(depOption);
        });
        
        transportLocations.forEach(location => {
            const destOption = document.createElement('option');
            destOption.value = location;
            destOption.textContent = location;
            destinationSelect.appendChild(destOption);
        });
        
    } else if (travelType === '点对点') {
        // 点对点：仅显示项目场地和酒店，不显示高铁站和机场
        venueLocations.forEach(location => {
            const depOption = document.createElement('option');
            depOption.value = location;
            depOption.textContent = location;
            departureSelect.appendChild(depOption);
            
            const destOption = document.createElement('option');
            destOption.value = location;
            destOption.textContent = location;
            destinationSelect.appendChild(destOption);
        });
    }
    
    // 尝试恢复之前选中的值
    const newDepartureOptions = Array.from(departureSelect.options);
    const newDestinationOptions = Array.from(destinationSelect.options);
    
    const departureStillValid = newDepartureOptions.some(opt => opt.value === currentDeparture);
    const destinationStillValid = newDestinationOptions.some(opt => opt.value === currentDestination);
    
    departureSelect.value = departureStillValid ? currentDeparture : '';
    destinationSelect.value = destinationStillValid ? currentDestination : '';
}

// 交换出发和到达地点
function swapLocations(routeIndex) {
    const travelTypeSelect = document.querySelector(`select[name="routes[${routeIndex}][travel_type]"]`);
    if (!travelTypeSelect) return;
    
    const travelType = travelTypeSelect.value;
    
    if (travelType === '混合交通安排（自定义）') {
        // 自定义模式：交换文本输入框的值
        const departureInput = document.getElementById(`departure_input_${routeIndex}`);
        const destinationInput = document.getElementById(`destination_input_${routeIndex}`);
        
        if (departureInput && destinationInput) {
            const temp = departureInput.value;
            departureInput.value = destinationInput.value;
            destinationInput.value = temp;
        }
    } else {
        // 其他模式：交换下拉选择的值
        const departureSelect = document.getElementById(`departure_select_${routeIndex}`);
        const destinationSelect = document.getElementById(`destination_select_${routeIndex}`);
        
        if (departureSelect && destinationSelect) {
            const temp = departureSelect.value;
            departureSelect.value = destinationSelect.value;
            destinationSelect.value = temp;
        }
    }
}

// 为初始行程绑定人员选择事件
setupPersonnelSelection(document.querySelector('.route-row'), 0);

// 页面加载完成后初始化第一个行程的地点选项和交换按钮显示
document.addEventListener('DOMContentLoaded', function() {
    console.log('页面加载完成，开始初始化');
    // 等待一小段时间确保DOM完全渲染
    setTimeout(function() {
        updateLocationOptions(0);
        console.log('初始化第一个行程完成');
    }, 100);
    
    // 添加表单提交验证
    const routeForm = document.getElementById('routeForm');
    if (routeForm) {
        routeForm.addEventListener('submit', function(e) {
            // 验证每个行程是否至少选择了一个乘客
            const routeRows = document.querySelectorAll('.route-row');
            let hasError = false;
            let errorMessage = '';
            
            routeRows.forEach((row, index) => {
                const checkboxes = row.querySelectorAll('.personnel-checkbox');
                const checkedBoxes = Array.from(checkboxes).filter(cb => cb.checked);
                
                if (checkedBoxes.length === 0) {
                    hasError = true;
                    errorMessage = `请为行程 ${index + 1} 至少选择一个乘客！`;
                    return;
                }
            });
            
            if (hasError) {
                e.preventDefault();
                alert(errorMessage);
                return false;
            }
            
            return true;
        });
    }
});

// 车型需求数量功能 - 切换数量输入框显示/隐藏
function toggleVehicleQuantity(checkbox, vehicleType, routeIndex) {
    const quantityInput = document.getElementById(`vehicle_quantity_${routeIndex}_${vehicleType}`);
    if (checkbox.checked) {
        quantityInput.style.display = 'block';
    } else {
        quantityInput.style.display = 'none';
    }
}

// 人员选择和搜索功能
function setupPersonnelSelection(container, index) {
    const searchInput = container.querySelector('.personnel-search');
    const departmentFilter = container.querySelector('.department-filter');
    const checkboxes = container.querySelectorAll('.personnel-checkbox');
    const selectedCountSpan = container.querySelector('.selected-count');
    const passengerCountInput = container.querySelector('.passenger-count');

    // 搜索和部门筛选功能
    function filterPersonnel() {
        const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
        const selectedDept = departmentFilter ? departmentFilter.value : ''; // 不转换大小写，保持原样
        const items = container.querySelectorAll('.personnel-item');

        items.forEach(item => {
            const label = item.querySelector('label');
            const text = label.textContent.toLowerCase();
            const dept = item.dataset.department ? item.dataset.department : ''; // 不转换大小写
            
            // 同时检查搜索词和部门筛选
            const matchesSearch = !searchTerm || text.includes(searchTerm);
            const matchesDept = !selectedDept || dept === selectedDept; // 精确匹配部门名称
            
            if (matchesSearch && matchesDept) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
    }

    // 搜索功能
    if (searchInput) {
        searchInput.addEventListener('input', filterPersonnel);
    }

    // 部门筛选功能
    if (departmentFilter) {
        departmentFilter.addEventListener('change', filterPersonnel);
    }

    // 更新计数和乘客数量
    function updatePersonnelCount() {
        const selectedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
        if (selectedCountSpan) {
            selectedCountSpan.textContent = selectedCount;
        }
        if (passengerCountInput) {
            passengerCountInput.value = selectedCount || 0;
        }
        
        // 更新新的乘客数量显示
        const passengerDisplay = container.querySelector('.selected-count-display');
        if (passengerDisplay) {
            passengerDisplay.textContent = selectedCount || 0;
        }
    }

    // 绑定复选框事件
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updatePersonnelCount();
            updateCheckboxStyle(this);
        });
        // 初始化样式
        updateCheckboxStyle(checkbox);
    });

    // 初始化计数
    updatePersonnelCount();
}

// 更新复选框选中状态的视觉样式
function updateCheckboxStyle(checkbox) {
    const formCheck = checkbox.closest('.form-check');
    if (formCheck) {
        if (checkbox.checked) {
            formCheck.style.backgroundColor = '#e7f3ff';
            formCheck.style.borderColor = '#0d6efd';
            formCheck.style.borderWidth = '1px';
            formCheck.style.boxShadow = '0 0 0 1px rgba(13, 110, 253, 0.2)';
            const label = formCheck.querySelector('.form-check-label');
            if (label) {
                label.style.color = '#0d6efd';
                label.style.fontWeight = '500';
            }
        } else {
            formCheck.style.backgroundColor = '';
            formCheck.style.borderColor = 'transparent';
            formCheck.style.borderWidth = '1px';
            formCheck.style.boxShadow = '';
            const label = formCheck.querySelector('.form-check-label');
            if (label) {
                label.style.color = '';
                label.style.fontWeight = '';
            }
        }
    }
}
</script>

<?php include 'includes/footer.php'; ?>
