<?php
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

// 获取POST数据
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['id'])) {
    echo json_encode(['success' => false, 'error' => '缺少关联ID']);
    exit;
}

$association_id = (int)$input['id'];

try {
    $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASSWORD);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 获取人员ID用于后续检查
    $stmt = $db->prepare("SELECT personnel_id FROM project_department_personnel WHERE id = ?");
    $stmt->execute([$association_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        echo json_encode(['success' => false, 'error' => '关联不存在']);
        exit;
    }
    
    $personnel_id = $result['personnel_id'];
    
    // 删除关联
    $stmt = $db->prepare("DELETE FROM project_department_personnel WHERE id = ?");
    $stmt->execute([$association_id]);
    
    // 检查该人员是否还有其他项目关联
    $stmt = $db->prepare("SELECT COUNT(*) FROM project_department_personnel WHERE personnel_id = ?");
    $stmt->execute([$personnel_id]);
    $count = $stmt->fetchColumn();
    
    // 如果没有其他项目关联，移除项目用户标记
    if ($count == 0) {
        $stmt = $db->prepare("DELETE FROM personnel_project_users WHERE personnel_id = ?");
        $stmt->execute([$personnel_id]);
    }
    
    echo json_encode(['success' => true]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>