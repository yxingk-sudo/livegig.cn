<?php
// 获取所有可用人员列表
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

session_start();

if (!isset($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'error' => '未登录']);
    exit;
}

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    // 调试：检查personnel表是否存在
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'personnel'");
    if ($tableCheck->rowCount() === 0) {
        echo json_encode(['success' => false, 'error' => 'personnel表不存在']);
        exit;
    }
    
    // 获取所有人员信息，包含部门信息
    $query = "
        SELECT 
            p.id,
            p.name,
            GROUP_CONCAT(DISTINCT d.name) as departments
        FROM personnel p
        LEFT JOIN project_department_personnel pdp ON p.id = pdp.personnel_id
        LEFT JOIN departments d ON pdp.department_id = d.id
        GROUP BY p.id, p.name
        ORDER BY p.name ASC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $personnel = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 格式化数据
    $result = array_map(function($person) {
        return [
            'id' => $person['id'],
            'name' => $person['name'],
            'department' => $person['departments'] ?: '未分配部门',
            'company' => '未知公司',
            'contact_phone' => ''
        ];
    }, $personnel);
    
    echo json_encode(['success' => true, 'data' => $result]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => '数据库错误: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => '系统错误: ' . $e->getMessage()]);
}
?>