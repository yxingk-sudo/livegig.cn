<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

session_start();
requireLogin();

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
    $meal_allowance = isset($_POST['meal_allowance']) ? floatval($_POST['meal_allowance']) : 0;
    
    if ($personnel_id <= 0) {
        throw new Exception('无效的人员ID');
    }
    
    if ($meal_allowance < 0) {
        throw new Exception('餐费补助金额不能为负数');
    }
    
    // 更新人员的餐费补助金额
    $query = "UPDATE personnel SET meal_allowance = :meal_allowance WHERE id = :personnel_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':meal_allowance', $meal_allowance);
    $stmt->bindParam(':personnel_id', $personnel_id);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true, 
            'message' => '餐费补助金额更新成功',
            'personnel_id' => $personnel_id,
            'meal_allowance' => $meal_allowance
        ]);
    } else {
        throw new Exception('更新失败');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}