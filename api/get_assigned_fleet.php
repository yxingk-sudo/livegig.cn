<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
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
$transportation_id = isset($_GET['transportation_id']) ? intval($_GET['transportation_id']) : null;

try {
    require_once __DIR__ . '/../config/database.php';
    
    if ($transportation_id) {
        // 获取特定交通记录的已分配车辆
        $query = "
            SELECT f.*, tfa.assigned_date
            FROM transportation_fleet_assignments tfa
            JOIN fleet f ON tfa.fleet_id = f.id
            JOIN transportation_reports tr ON tfa.transportation_report_id = tr.id
            WHERE tr.id = :transportation_id AND tr.project_id = :project_id
        ";
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':transportation_id' => $transportation_id,
            ':project_id' => $project_id
        ]);
    } else {
        // 获取项目所有已分配车辆
        $query = "
            SELECT f.*, tr.id as transportation_id, tr.travel_date, p.name as personnel_name
            FROM transportation_fleet_assignments tfa
            JOIN fleet f ON tfa.fleet_id = f.id
            JOIN transportation_reports tr ON tfa.transportation_report_id = tr.id
            JOIN personnel p ON tr.personnel_id = p.id
            WHERE tr.project_id = :project_id
            ORDER BY tr.travel_date DESC, tfa.assigned_date DESC
        ";
        $stmt = $db->prepare($query);
        $stmt->execute([':project_id' => $project_id]);
    }
    
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $assignments,
        'count' => count($assignments)
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => '数据库错误：' . $e->getMessage()]);
}
?>