<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

// 获取参数
$project_id = $_GET['project_id'] ?? null;
$date = $_GET['date'] ?? null;

if (!$project_id) {
    http_response_code(400);
    echo json_encode(['error' => '缺少项目ID参数']);
    exit;
}

try {
    // 查询项目的出行记录和车辆分配情况
    $query = "SELECT 
                tr.id,
                tr.travel_type,
                tr.departure_location,
                tr.destination_location,
                tr.travel_date,
                tr.departure_time,
                tr.passenger_count,
                tr.status,
                pr.name as personnel_name,
                f.id as fleet_id,
                f.fleet_number,
                f.license_plate,
                f.vehicle_type,
                f.vehicle_model,
                f.seats,
                f.driver_name,
                f.driver_phone,
                p.name as project_name,
                p.code as project_code
              FROM transportation_reports tr
              JOIN projects p ON tr.project_id = p.id
              JOIN personnel pr ON tr.personnel_id = pr.id
              LEFT JOIN transportation_fleet_assignments tfa ON tr.id = tfa.transportation_report_id
              LEFT JOIN fleet f ON tfa.fleet_id = f.id
              WHERE tr.project_id = :project_id
              AND tr.status != 'cancelled'";

    // 如果提供了日期，筛选特定日期的记录
    if ($date) {
        $query .= " AND tr.travel_date = :travel_date";
    }

    $query .= " ORDER BY tr.travel_date ASC, tr.departure_time ASC";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':project_id', $project_id);
    
    if ($date) {
        $stmt->bindParam(':travel_date', $date);
    }
    
    $stmt->execute();
    $transportation = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 按日期分组
    $grouped_by_date = [];
    foreach ($transportation as $record) {
        $travel_date = $record['travel_date'];
        if (!isset($grouped_by_date[$travel_date])) {
            $grouped_by_date[$travel_date] = [];
        }
        $grouped_by_date[$travel_date][] = $record;
    }

    // 统计信息
    $stats_query = "SELECT 
                       COUNT(DISTINCT tr.id) as total_records,
                       COUNT(DISTINCT f.id) as assigned_vehicles,
                       SUM(tr.passenger_count) as total_passengers
                     FROM transportation_reports tr
                     LEFT JOIN transportation_fleet_assignments tfa ON tr.id = tfa.transportation_report_id
                     LEFT JOIN fleet f ON tfa.fleet_id = f.id
                     WHERE tr.project_id = :project_id
                     AND tr.status != 'cancelled'";

    if ($date) {
        $stats_query .= " AND tr.travel_date = :travel_date";
    }

    $stats_stmt = $db->prepare($stats_query);
    $stats_stmt->bindParam(':project_id', $project_id);
    
    if ($date) {
        $stats_stmt->bindParam(':travel_date', $date);
    }
    
    $stats_stmt->execute();
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $transportation,
        'grouped_by_date' => $grouped_by_date,
        'stats' => $stats,
        'project' => [
            'id' => $project_id,
            'name' => $transportation[0]['project_name'] ?? null,
            'code' => $transportation[0]['project_code'] ?? null
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => '获取出行记录失败',
        'message' => $e->getMessage()
    ]);
}
?>