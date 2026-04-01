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
    
    // 获取所有项目和部门信息
    $projects_query = "SELECT id, name FROM projects ORDER BY name";
    $projects_stmt = $db->prepare($projects_query);
    $projects_stmt->execute();
    $all_projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $departments_query = "SELECT id, name, project_id FROM departments ORDER BY name";
    $departments_stmt = $db->prepare($departments_query);
    $departments_stmt->execute();
    $all_departments = $departments_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'person' => $person,
        'assignments' => $assignments,
        'all_projects' => $all_projects,
        'all_departments' => $all_departments
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '获取人员数据失败: ' . $e->getMessage()]);
}