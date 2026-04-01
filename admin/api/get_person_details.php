<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => '缺少人员ID']);
    exit;
}

$database = new Database();
$db = $database->getConnection();

try {
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
    
    // 获取人员参与的项目详情
    $project_query = "
        SELECT 
            p.name as project_name,
            d.name as department_name,
            pdp.position
        FROM project_department_personnel pdp
        LEFT JOIN projects p ON pdp.project_id = p.id
        LEFT JOIN departments d ON pdp.department_id = d.id
        WHERE pdp.personnel_id = :personnel_id
        ORDER BY p.name";
    $project_stmt = $db->prepare($project_query);
    $project_stmt->bindParam(':personnel_id', $person_id);
    $project_stmt->execute();
    $projects = $project_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 格式化项目详情
    $project_details = '';
    if (!empty($projects)) {
        $project_info = [];
        foreach ($projects as $project) {
            $info = $project['project_name'] . ' - ' . $project['department_name'];
            if (!empty($project['position'])) {
                $info .= ' (' . $project['position'] . ')';
            }
            $project_info[] = $info;
        }
        $project_details = implode('; ', $project_info);
    }
    
    echo json_encode([
        'success' => true,
        'person' => $person,
        'project_details' => $project_details
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '获取人员详情失败: ' . $e->getMessage()]);
}