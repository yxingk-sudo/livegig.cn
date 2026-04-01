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
    // 查询项目专属的车队信息
    $query = "SELECT 
                f.id,
                f.fleet_number,
                f.license_plate,
                f.vehicle_type,
                f.vehicle_model,
                f.seats,
                f.driver_name,
                f.driver_phone,
                f.status,
                CASE 
                    WHEN f.status = 'available' THEN '空闲'
                    WHEN f.status = 'assigned' THEN '已分配'
                    ELSE f.status
                END as status_text,
                p.name as project_name,
                p.code as project_code,
                CASE 
                    WHEN EXISTS (
                        SELECT 1 
                        FROM transportation_fleet_assignments tfa
                        JOIN transportation_reports tr ON tfa.transportation_report_id = tr.id
                        WHERE tfa.fleet_id = f.id 
                        AND tr.travel_date = :travel_date
                        AND tr.status != 'cancelled'
                    ) THEN 'busy'
                    ELSE 'available'
                END as availability_status
              FROM fleet f
              LEFT JOIN projects p ON f.project_id = p.id
              WHERE f.project_id = :project_id
              AND f.status IN ('available', 'assigned')";

    $query .= " ORDER BY f.vehicle_type, f.seats DESC";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':project_id', $project_id);
    $stmt->bindParam(':travel_date', $date);
    
    $stmt->execute();
    $fleet = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 按车型分组
    $grouped_fleet = [];
    $available_count = 0;
    
    foreach ($fleet as $vehicle) {
        $type = $vehicle['vehicle_type'];
        if (!isset($grouped_fleet[$type])) {
            $grouped_fleet[$type] = [];
        }
        
        // 标记可用性
        if ($vehicle['availability_status'] === 'available') {
            $vehicle['is_available'] = true;
            $available_count++;
        } else {
            $vehicle['is_available'] = false;
        }
        
        $grouped_fleet[$type][] = $vehicle;
    }

    echo json_encode([
        'success' => true,
        'data' => $fleet,
        'grouped' => $grouped_fleet,
        'total' => count($fleet),
        'available' => $available_count,
        'project' => [
            'id' => $project_id,
            'name' => $fleet[0]['project_name'] ?? null,
            'code' => $fleet[0]['project_code'] ?? null
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => '获取车队信息失败',
        'message' => $e->getMessage()
    ]);
}
?>