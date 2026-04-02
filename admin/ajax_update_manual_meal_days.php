<?php
require_once '../config/database.php';
session_start();

// 引入基础控制器进行权限验证
require_once '../includes/BaseAdminController.php';

// 检查是否登录
if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未授权访问']);
    exit;
}

// 只允许 POST 请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '只允许 POST 请求']);
    exit;
}

// 获取数据库连接
$database = new Database();
$db = $database->getConnection();

try {
    // 获取并验证输入数据
    $personnel_id = isset($_POST['personnel_id']) ? intval($_POST['personnel_id']) : 0;
    $project_id = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;
    $days = isset($_POST['days']) ? intval($_POST['days']) : 0;
    
    if ($personnel_id <= 0) {
        throw new Exception('无效的人员ID');
    }
    
    if ($project_id <= 0) {
        throw new Exception('无效的项目ID');
    }
    
    if ($days < 0) {
        throw new Exception('天数不能为负数');
    }
    
    // 检查记录是否存在
    $checkQuery = "SELECT id FROM manual_meal_allowance_days 
                   WHERE personnel_id = :personnel_id AND project_id = :project_id";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(':personnel_id', $personnel_id);
    $checkStmt->bindParam(':project_id', $project_id);
    $checkStmt->execute();
    
    if ($checkStmt->rowCount() > 0) {
        // 记录存在，更新
        $updateQuery = "UPDATE manual_meal_allowance_days 
                        SET days = :days 
                        WHERE personnel_id = :personnel_id AND project_id = :project_id";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindParam(':days', $days);
        $updateStmt->bindParam(':personnel_id', $personnel_id);
        $updateStmt->bindParam(':project_id', $project_id);
        
        if ($updateStmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => '天数更新成功',
                'personnel_id' => $personnel_id,
                'project_id' => $project_id,
                'days' => $days
            ]);
        } else {
            throw new Exception('更新失败');
        }
    } else {
        // 记录不存在，插入新记录
        $insertQuery = "INSERT INTO manual_meal_allowance_days (personnel_id, project_id, days) 
                        VALUES (:personnel_id, :project_id, :days)";
        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->bindParam(':personnel_id', $personnel_id);
        $insertStmt->bindParam(':project_id', $project_id);
        $insertStmt->bindParam(':days', $days);
        
        if ($insertStmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => '天数设置成功',
                'personnel_id' => $personnel_id,
                'project_id' => $project_id,
                'days' => $days
            ]);
        } else {
            throw new Exception('插入失败');
        }
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
