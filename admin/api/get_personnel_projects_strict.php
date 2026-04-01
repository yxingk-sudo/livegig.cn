<?php
// 超纯净版人员项目API - 零错误输出
error_reporting(0);
ini_set('display_errors', 0);

// 强制清理所有输出缓冲
while (ob_get_level()) {
    ob_end_clean();
}

// 设置严格的JSON响应
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

// 获取人员ID
$personnel_id = isset($_GET['personnel_id']) ? intval($_GET['personnel_id']) : 0;

if ($personnel_id <= 0) {
    echo json_encode(['success' => false, 'error' => '无效的人员ID']);
    exit;
}

try {
    require_once __DIR__ . '/../config/database.php';
    
    $database = new Database();
    $pdo = $database->getConnection();
    
    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => '数据库连接失败']);
        exit;
    }
    
    // 获取人员项目关联信息
    $stmt = $pdo->prepare("SELECT 
        p.id as project_id,
        p.name as project_name,
        p.code as project_code,
        p.start_date,
        p.end_date,
        p.status,
        d.name as department_name,
        pdp.position,
        c.name as company_name
    FROM project_department_personnel pdp
    JOIN projects p ON pdp.project_id = p.id
    JOIN departments d ON pdp.department_id = d.id
    JOIN companies c ON p.company_id = c.id
    WHERE pdp.personnel_id = ? AND pdp.status = 'active'
    ORDER BY p.start_date DESC");
    
    $stmt->execute([$personnel_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'projects' => $projects,
        'count' => count($projects)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => '查询失败']);
}

exit;