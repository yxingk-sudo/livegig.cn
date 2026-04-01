<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();

if (!isset($_SESSION['project_id'])) {
    http_response_code(401);
    echo json_encode(['error' => '未登录或会话已过期']);
    exit();
}

$project_id = $_SESSION['project_id'];
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['transportation_id'])) {
    http_response_code(400);
    echo json_encode(['error' => '缺少必要参数：transportation_id']);
    exit();
}

$transportation_id = $data['transportation_id'];

try {
    require_once __DIR__ . '/../config/database.php';
    
    $db->beginTransaction();
    
    // 验证交通记录是否属于本项目
    $stmt = $db->prepare("SELECT id FROM transportation_reports WHERE id = :transportation_id AND project_id = :project_id");
    $stmt->execute([
        ':transportation_id' => $transportation_id,
        ':project_id' => $project_id
    ]);
    
    if ($stmt->rowCount() === 0) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(['error' => '交通记录不存在或不属于本项目']);
        exit();
    }
    
    // 获取已分配的车辆ID
    $stmt = $db->prepare("SELECT fleet_id FROM transportation_fleet_assignments WHERE transportation_report_id = :transportation_report_id");
    $stmt->execute([':transportation_report_id' => $transportation_id]);
    $assigned_fleets = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($assigned_fleets)) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(['error' => '该交通记录未分配车辆']);
        exit();
    }
    
    // 删除分配记录
    $stmt = $db->prepare("DELETE FROM transportation_fleet_assignments WHERE transportation_report_id = :transportation_report_id");
    $stmt->execute([':transportation_report_id' => $transportation_id]);
    
    // 更新车辆状态为可用
    $placeholders = implode(',', array_fill(0, count($assigned_fleets), '?'));
    $stmt = $db->prepare("UPDATE fleet SET status = 'available' WHERE id IN ($placeholders)");
    $stmt->execute($assigned_fleets);
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => '车辆分配已取消',
        'affected_vehicles' => count($assigned_fleets)
    ]);
    
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => '数据库错误：' . $e->getMessage()]);
}
?>