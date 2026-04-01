<?php
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_GET['personnel_id'])) {
    echo json_encode(['success' => false, 'error' => '缺少人员ID']);
    exit;
}

$personnel_id = (int)$_GET['personnel_id'];

try {
    $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASSWORD);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $db->prepare("SELECT 
        pdp.id,
        p.name as project_name,
        d.name as department_name,
        pdp.position,
        pdp.status,
        c.name as company_name
        FROM project_department_personnel pdp
        JOIN projects p ON pdp.project_id = p.id
        JOIN departments d ON pdp.department_id = d.id
        JOIN companies c ON p.company_id = c.id
        WHERE pdp.personnel_id = ?
        ORDER BY pdp.created_at DESC");
    
    $stmt->execute([$personnel_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'projects' => $projects]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>