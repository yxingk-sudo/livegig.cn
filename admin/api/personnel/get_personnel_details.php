<?php
session_start();
require_once '../../../config/database.php';

if (!isset($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => '缺少人员ID']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $person_id = intval($_GET['id']);
    
    // 获取人员基本信息
    $query = "SELECT * FROM personnel WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $person_id);
    $stmt->execute();
    $person = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$person) {
        echo json_encode(['success' => false, 'message' => '人员未找到']);
        exit;
    }
    
    // 获取项目部门关联
    $query = "SELECT 
                pdp.project_id,
                pdp.department_id,
                pdp.position,
                p2.name as project_name,
                d.name as department_name
              FROM project_department_personnel pdp
              LEFT JOIN projects p2 ON pdp.project_id = p2.id
              LEFT JOIN departments d ON pdp.department_id = d.id
              WHERE pdp.personnel_id = :personnel_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':personnel_id', $person_id);
    $stmt->execute();
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 获取用餐记录（最近5条）
    try {
        $query = "SELECT mr.*, p.name as project_name 
                  FROM meal_reports mr 
                  LEFT JOIN projects p ON mr.project_id = p.id 
                  WHERE mr.personnel_id = :personnel_id 
                  ORDER BY mr.meal_date DESC, mr.created_at DESC 
                  LIMIT 5";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':personnel_id', $person_id);
        $stmt->execute();
        $meal_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $meal_records = [];
    }
    
    // 获取住宿记录（最近5条）
    try {
        $query = "SELECT hr.*, hr.hotel_name as hotel_name 
                  FROM hotel_reports hr 
                  WHERE hr.personnel_id = :personnel_id 
                  ORDER BY hr.check_in_date DESC, hr.created_at DESC 
                  LIMIT 5";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':personnel_id', $person_id);
        $stmt->execute();
        $hotel_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $hotel_records = [];
    }
    
    // 获取交通记录（最近5条）
    try {
        $query = "SELECT tr.* 
                  FROM transportation_reports tr 
                  WHERE tr.personnel_id = :personnel_id 
                  ORDER BY tr.travel_date DESC, tr.created_at DESC 
                  LIMIT 5";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':personnel_id', $person_id);
        $stmt->execute();
        $transport_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $transport_records = [];
    }
    
    echo json_encode([
        'success' => true,
        'person' => $person,
        'assignments' => $assignments,
        'meal_records' => $meal_records,
        'hotel_records' => $hotel_records,
        'transport_records' => $transport_records
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '加载失败：' . $e->getMessage()]);
}
exit;
?>