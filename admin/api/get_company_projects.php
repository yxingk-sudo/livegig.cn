<?php
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_GET['company_id'])) {
    echo json_encode(['success' => false, 'error' => '缺少公司ID']);
    exit;
}

$company_id = (int)$_GET['company_id'];

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception('数据库连接失败');
    }
    
    $stmt = $db->prepare("SELECT id, name FROM projects WHERE company_id = ? ORDER BY name");
    $stmt->execute([$company_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'projects' => $projects]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>