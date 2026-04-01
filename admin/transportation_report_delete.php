<?php
session_start();
require_once '../config/database.php';

// 检查登录状态
if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未登录或会话已过期']);
    exit;
}

// 设置响应头
header('Content-Type: application/json');

// 检查请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支持的请求方法']);
    exit;
}

// 获取要删除的记录ID
$id = isset($_POST['id']) ? intval($_POST['id']) : 0;

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => '无效的ID参数']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // 开始事务
    $db->beginTransaction();
    
    // 首先检查记录是否存在
    $check_query = "SELECT id FROM transportation_reports WHERE id = :id";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->execute(['id' => $id]);
    
    if (!$check_stmt->fetch()) {
        $db->rollback();
        echo json_encode(['success' => false, 'message' => '未找到该行程记录']);
        exit;
    }
    
    // 删除车辆分配记录
    $delete_assign_query = "DELETE FROM transportation_fleet_assignments WHERE transportation_report_id = :report_id";
    $delete_assign_stmt = $db->prepare($delete_assign_query);
    $delete_assign_stmt->execute(['report_id' => $id]);
    
    // 删除乘客关联记录（忽略不存在的表）
    try {
        $delete_passengers_query = "DELETE FROM transportation_passengers WHERE transportation_report_id = :report_id";
        $delete_passengers_stmt = $db->prepare($delete_passengers_query);
        $delete_passengers_stmt->execute(['report_id' => $id]);
    } catch (Exception $e) {
        // 表可能不存在，继续执行
    }
    
    // 删除行程记录
    $delete_query = "DELETE FROM transportation_reports WHERE id = :id";
    $delete_stmt = $db->prepare($delete_query);
    $delete_stmt->execute(['id' => $id]);
    
    // 提交事务
    $db->commit();
    
    echo json_encode(['success' => true, 'message' => '行程记录已成功删除']);
    
} catch (Exception $e) {
    // 回滚事务
    if (isset($db)) {
        $db->rollback();
    }
    
    echo json_encode(['success' => false, 'message' => '删除失败：' . $e->getMessage()]);
}