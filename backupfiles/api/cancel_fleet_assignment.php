<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

// 获取POST数据
$data = json_decode(file_get_contents('php://input'), true);

$transportation_id = $data['transportation_id'] ?? null;

if (!$transportation_id) {
    http_response_code(400);
    echo json_encode(['error' => '缺少必要参数']);
    exit;
}

try {
    // 开始事务
    $db->beginTransaction();
    
    // 验证记录是否存在
    $check_query = "SELECT id FROM transportation_reports 
                   WHERE id = :transportation_id";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(':transportation_id', $transportation_id);
    $check_stmt->execute();
    
    if ($check_stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => '未找到对应的出行记录']);
        exit;
    }
    
    // 删除车辆分配记录
    $delete_query = "DELETE FROM transportation_fleet_assignments 
                   WHERE transportation_report_id = :transportation_id";
    $delete_stmt = $db->prepare($delete_query);
    $delete_stmt->bindParam(':transportation_id', $transportation_id);
    $delete_stmt->execute();
    
    // 获取受影响的车辆数量
    $affected_rows = $delete_stmt->rowCount();
    
    // 提交事务
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => '车辆分配已成功取消',
        'affected_vehicles' => $affected_rows
    ]);

} catch (Exception $e) {
    // 回滚事务
    $db->rollback();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => '取消车辆分配失败',
        'message' => $e->getMessage()
    ]);
}
?>