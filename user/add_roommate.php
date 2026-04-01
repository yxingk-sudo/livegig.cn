<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

// 检查用户是否已登录
session_start();
requireLogin();
checkProjectPermission($_SESSION['project_id']);

header('Content-Type: application/json');

// 只允许POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '只允许POST请求']);
    exit;
}

// 获取POST数据
$recordId = $_POST['record_id'] ?? '';
$roommateId = $_POST['roommate_id'] ?? '';
$sharedRoomInfo = $_POST['shared_room_info'] ?? '';

// 验证数据
if (empty($recordId) || empty($roommateId)) {
    echo json_encode(['success' => false, 'message' => '缺少必要参数']);
    exit;
}

// 验证ID是否为数字
if (!is_numeric($recordId) || !is_numeric($roommateId)) {
    echo json_encode(['success' => false, 'message' => '参数格式错误']);
    exit;
}

$projectId = $_SESSION['project_id'];

try {
    // 初始化数据库连接
    $database = new Database();
    $db = $database->getConnection();
    
    // 开始事务
    $db->beginTransaction();
    
    // 获取原始记录信息
    $query = "SELECT hr.*, p.name as personnel_name FROM hotel_reports hr 
              JOIN personnel p ON hr.personnel_id = p.id 
              WHERE hr.id = :id AND hr.project_id = :project_id";
    $stmt = $db->prepare($query);
    $stmt->execute([':id' => $recordId, ':project_id' => $projectId]);
    $originalRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$originalRecord) {
        throw new Exception('原始记录不存在或无权限访问');
    }
    
    // 检查是否为支持共享的房型
    $shareable_room_types = ['双床房', '双人房', '套房', '大床房', '总统套房', '副总统套房'];
    if (!in_array($originalRecord['room_type'], $shareable_room_types)) {
        throw new Exception('该房型不支持添加同住人');
    }
    
    // 获取要添加的人员信息
    $personQuery = "SELECT name FROM personnel WHERE id = :id";
    $personStmt = $db->prepare($personQuery);
    $personStmt->execute([':id' => $roommateId]);
    $person = $personStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$person) {
        throw new Exception('指定的人员不存在');
    }
    
    // 检查该共享房间是否已满员（房间最多2人）
    $countQuery = "SELECT COUNT(*) FROM hotel_reports 
                   WHERE project_id = :project_id 
                   AND hotel_name = :hotel_name 
                   AND check_in_date = :check_in_date 
                   AND check_out_date = :check_out_date 
                   AND room_type = :room_type 
                   AND shared_room_info = :shared_room_info";
    $countStmt = $db->prepare($countQuery);
    $countStmt->execute([
        ':project_id' => $projectId,
        ':hotel_name' => $originalRecord['hotel_name'],
        ':check_in_date' => $originalRecord['check_in_date'],
        ':check_out_date' => $originalRecord['check_out_date'],
        ':room_type' => $originalRecord['room_type'],
        ':shared_room_info' => $sharedRoomInfo
    ]);
    
    $currentCount = $countStmt->fetchColumn();
    if ($currentCount >= 2) {
        throw new Exception('该房间已满员（最多2人）');
    }
    
    // 检查要添加的人员是否已存在于该共享房间中
    $checkQuery = "SELECT COUNT(*) FROM hotel_reports 
                   WHERE project_id = :project_id 
                   AND hotel_name = :hotel_name 
                   AND check_in_date = :check_in_date 
                   AND check_out_date = :check_out_date 
                   AND room_type = :room_type 
                   AND shared_room_info = :shared_room_info 
                   AND personnel_id = :personnel_id";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->execute([
        ':project_id' => $projectId,
        ':hotel_name' => $originalRecord['hotel_name'],
        ':check_in_date' => $originalRecord['check_in_date'],
        ':check_out_date' => $originalRecord['check_out_date'],
        ':room_type' => $originalRecord['room_type'],
        ':shared_room_info' => $sharedRoomInfo,
        ':personnel_id' => $roommateId
    ]);
    
    if ($checkStmt->fetchColumn() > 0) {
        throw new Exception('该人员已存在于该共享房间中');
    }
    
    // 创建新的酒店预订记录，为新添加的同住人
    $insertQuery = "INSERT INTO hotel_reports (
                        project_id, 
                        personnel_id, 
                        check_in_date, 
                        check_out_date, 
                        hotel_name, 
                        room_type, 
                        room_count, 
                        special_requirements, 
                        shared_room_info, 
                        status, 
                        reported_by
                    ) VALUES (
                        :project_id, 
                        :personnel_id, 
                        :check_in_date, 
                        :check_out_date, 
                        :hotel_name, 
                        :room_type, 
                        :room_count, 
                        :special_requirements, 
                        :shared_room_info, 
                        :status, 
                        :reported_by
                    )";
    
    $insertStmt = $db->prepare($insertQuery);
    $insertResult = $insertStmt->execute([
        ':project_id' => $projectId,
        ':personnel_id' => $roommateId,
        ':check_in_date' => $originalRecord['check_in_date'],
        ':check_out_date' => $originalRecord['check_out_date'],
        ':hotel_name' => $originalRecord['hotel_name'],
        ':room_type' => $originalRecord['room_type'],
        ':room_count' => 1, // 每个记录的房间数量为1
        ':special_requirements' => $originalRecord['special_requirements'],
        ':shared_room_info' => $sharedRoomInfo,
        ':status' => $originalRecord['status'],
        ':reported_by' => $originalRecord['reported_by']
    ]);
    
    if (!$insertResult) {
        throw new Exception('创建新的酒店预订记录失败');
    }
    
    // 更新该共享房间所有记录的共享房间信息
    $updatedSharedRoomInfo = $sharedRoomInfo;
    // 使用正则表达式检查人员姓名是否已存在于共享房间信息中
    // 确保完整匹配人员姓名，避免部分匹配导致的误判
    $personNamePattern = '/(?:^|[、,() ]+)' . preg_quote($person['name'], '/') . '(?:[、,() ]+|$)/u';
    if (!preg_match($personNamePattern, $sharedRoomInfo)) {
        // 如果新人员姓名还不在共享房间信息中，则添加
        if (!empty($sharedRoomInfo)) {
            $updatedSharedRoomInfo = $sharedRoomInfo . '、' . $person['name'];
        } else {
            $updatedSharedRoomInfo = $person['name'];
        }
    }
    
    // 再次检查更新后的共享房间信息，确保没有重复的人员姓名
    $names = preg_split('/[、,() ]+/', $updatedSharedRoomInfo, -1, PREG_SPLIT_NO_EMPTY);
    $uniqueNames = array_unique($names);
    $updatedSharedRoomInfo = implode('、', $uniqueNames);
    
    // 更新该共享房间所有记录的共享房间信息
    // 使用更精确的条件：项目ID、酒店名称、入住日期、退房日期、房型和原始共享房间信息
    // 同时添加ID条件，确保只更新当前共享房间的记录，避免影响其他不相关的记录
    $updateQuery = "UPDATE hotel_reports SET 
                        shared_room_info = :shared_room_info,
                        updated_at = NOW()
                    WHERE project_id = :project_id 
                    AND hotel_name = :hotel_name 
                    AND check_in_date = :check_in_date 
                    AND check_out_date = :check_out_date 
                    AND room_type = :room_type 
                    AND shared_room_info = :original_shared_room_info
                    AND id IN (
                        SELECT id FROM (
                            SELECT id FROM hotel_reports 
                            WHERE project_id = :project_id_2
                            AND hotel_name = :hotel_name_2
                            AND check_in_date = :check_in_date_2
                            AND check_out_date = :check_out_date_2
                            AND room_type = :room_type_2
                            AND shared_room_info = :original_shared_room_info_2
                        ) AS subquery
                    )";
    $updateStmt = $db->prepare($updateQuery);
    $updateResult = $updateStmt->execute([
        ':shared_room_info' => $updatedSharedRoomInfo,
        ':project_id' => $projectId,
        ':hotel_name' => $originalRecord['hotel_name'],
        ':check_in_date' => $originalRecord['check_in_date'],
        ':check_out_date' => $originalRecord['check_out_date'],
        ':room_type' => $originalRecord['room_type'],
        ':original_shared_room_info' => $sharedRoomInfo,
        ':project_id_2' => $projectId,
        ':hotel_name_2' => $originalRecord['hotel_name'],
        ':check_in_date_2' => $originalRecord['check_in_date'],
        ':check_out_date_2' => $originalRecord['check_out_date'],
        ':room_type_2' => $originalRecord['room_type'],
        ':original_shared_room_info_2' => $sharedRoomInfo
    ]);
    
    if (!$updateResult) {
        throw new Exception('更新共享房间信息失败');
    }
    
    // 提交事务
    $db->commit();
    
    // 返回成功响应
    echo json_encode([
        'success' => true, 
        'message' => '同住人添加成功',
        'new_person_name' => $person['name'],
        'new_shared_room_info' => $updatedSharedRoomInfo
    ]);
    
} catch (Exception $e) {
    // 回滚事务
    if (isset($db) && $db->inTransaction()) {
        $db->rollback();
    }
    
    // 记录错误日志
    error_log("添加同住人失败: " . $e->getMessage());
    
    // 返回错误响应
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}
?>