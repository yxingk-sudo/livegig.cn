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

if (!isset($data['transportation_id']) || !isset($data['fleet_id'])) {
    http_response_code(400);
    echo json_encode(['error' => '缺少必要参数：transportation_id 或 fleet_id']);
    exit();
}

$transportation_id = $data['transportation_id'];
$fleet_id = $data['fleet_id'];

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
    
    // 验证车辆是否属于本项目
    $stmt = $db->prepare("SELECT id FROM fleet WHERE id = :fleet_id AND project_id = :project_id AND status = 'available'");
    $stmt->execute([
        ':fleet_id' => $fleet_id,
        ':project_id' => $project_id
    ]);
    
    if ($stmt->rowCount() === 0) {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(['error' => '车辆不存在、不属于本项目或已被分配']);
        exit();
    }
    
    // 检查车辆是否已被分配
    $stmt = $db->prepare("SELECT COUNT(*) FROM transportation_fleet_assignments WHERE fleet_id = :fleet_id");
    $stmt->execute([':fleet_id' => $fleet_id]);
    
    if ($stmt->fetchColumn() > 0) {
        $db->rollBack();
        http_response_code(409);
        echo json_encode(['error' => '该车辆已被分配']);
        exit();
    }
    
    // 插入分配记录
    $stmt = $db->prepare("INSERT INTO transportation_fleet_assignments (transportation_report_id, fleet_id) VALUES (:transportation_report_id, :fleet_id)");
    $stmt->execute([
        ':transportation_report_id' => $transportation_id,
        ':fleet_id' => $fleet_id
    ]);
    
    // 更新车辆状态
    $stmt = $db->prepare("UPDATE fleet SET status = 'assigned' WHERE id = :fleet_id");
    $stmt->execute([':fleet_id' => $fleet_id]);
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => '车辆分配成功',
        'assignment_id' => $db->lastInsertId()
    ]);
    
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => '数据库错误：' . $e->getMessage()]);
}
?>