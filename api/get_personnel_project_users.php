<?php
// 获取人员的项目用户状态信息
header('Content-Type: application/json; charset=utf-8');

// 清理输出缓冲
while (ob_get_level() > 0) {
    ob_end_clean();
}

// 启用错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

$response = ['success' => false, 'error' => '', 'project_users' => []];

try {
    // 验证参数
    if (!isset($_GET['personnel_id']) || empty($_GET['personnel_id'])) {
        throw new Exception('人员ID不能为空');
    }
    
    $personnel_id = intval($_GET['personnel_id']);
    if ($personnel_id <= 0) {
        throw new Exception('无效的人员ID');
    }

    // 检查数据库配置文件
    $dbConfigPath = __DIR__ . '/../config/database.php';
    if (!file_exists($dbConfigPath)) {
        throw new Exception('数据库配置文件不存在');
    }
    
    require_once $dbConfigPath;
    
    // 创建数据库连接
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception('数据库连接失败');
    }

    // 查询该人员的所有项目用户
    $query = "SELECT 
        pu.id,
        pu.project_id,
        pu.username,
        pu.display_name,
        pu.role,
        pu.is_active,
        pu.created_at,
        p.name as project_name,
        p.code as project_code,
        c.name as company_name
    FROM project_users pu
    JOIN projects p ON pu.project_id = p.id
    JOIN companies c ON p.company_id = c.id
    JOIN personnel_project_users ppu ON pu.id = ppu.project_user_id
    WHERE ppu.personnel_id = :personnel_id
    ORDER BY pu.created_at DESC";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':personnel_id', $personnel_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $project_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $response['success'] = true;
    $response['project_users'] = $project_users;
    $response['total_count'] = count($project_users);

} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;