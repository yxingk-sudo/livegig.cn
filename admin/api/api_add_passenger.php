<?php
session_start();
header('Content-Type: application/json');
error_reporting(0);

// 验证登录
if (!isset($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // 验证必需参数 - 确保所有参数都有值，为空时设置默认值
    $required_params = ['personnel_id', 'project_id', 'travel_date', 'travel_type', 
                       'departure_time', 'departure_location', 'destination_location', 
                       'status', 'passenger_count', 'contact_phone'];
    
    foreach ($required_params as $param) {
        if (!isset($_POST[$param])) {
            echo json_encode([
                'success' => false, 
                'message' => '缺少参数: ' . $param,
                'received_params' => $_POST,
                'missing_param' => $param
            ]);
            exit;
        }
    }
    
    // 设置默认值处理空值参数
    $vehicle_type = $_POST['vehicle_type'] ?? '未知';
    if (empty($vehicle_type)) {
        $vehicle_type = '未知';
    }
    
    $contact_phone = $_POST['contact_phone'] ?? '';
    if (empty($contact_phone)) {
        $contact_phone = '无联系方式';
    }
    
    $special_requirements = $_POST['special_requirements'] ?? '';
    if (empty($special_requirements)) {
        $special_requirements = '无特殊要求';
    }
    
    $personnel_id = intval($_POST['personnel_id']);
    $project_id = intval($_POST['project_id']);
    $passenger_count = intval($_POST['passenger_count']);
    
    // 引入容量验证器
    require_once '../includes/capacity_validator.php';
    
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
        ':project_id' => $project_id,
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
    
    // 检查车辆容量限制
    $validator = new CapacityValidator($db);
    $isValid = $validator->checkCapacity([
        'travel_date' => $_POST['travel_date'],
        'travel_type' => $_POST['travel_type'],
        'departure_time' => $_POST['departure_time'],
        'arrival_time' => $_POST['arrival_time'] ?? null,
        'departure_location' => $_POST['departure_location'],
        'destination_location' => $_POST['destination_location'],
        'project_id' => $project_id
    ], $passenger_count);
    
    if (!$isValid['success']) {
        echo json_encode([
            'success' => false, 
            'message' => $isValid['message'],
            'error_type' => $isValid['error_type'] ?? 'capacity_error',
            'details' => [
                'current_count' => $isValid['current_count'] ?? 0,
                'max_capacity' => $isValid['max_capacity'] ?? 0,
                'remaining_seats' => $isValid['remaining_seats'] ?? 0
            ]
        ]);
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
    $result = $insert_stmt->execute([
        ':personnel_id' => $personnel_id,
        ':project_id' => $project_id,
        ':travel_date' => $_POST['travel_date'],
        ':travel_type' => $_POST['travel_type'],
        ':departure_time' => $_POST['departure_time'],
        ':departure_location' => $_POST['departure_location'],
        ':destination_location' => $_POST['destination_location'],
        ':status' => $_POST['status'],
        ':vehicle_type' => $vehicle_type,
        ':passenger_count' => $passenger_count,
        ':contact_phone' => $contact_phone,
        ':special_requirements' => $special_requirements,
        ':reported_by' => $admin_id
    ]);
    
    if ($result) {
        echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
    } else {
        echo json_encode(['success' => false, 'message' => '插入失败']);
    }
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => '数据库错误: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '系统错误: ' . $e->getMessage()]);
}
?>