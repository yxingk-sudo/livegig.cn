<?php
// 修复版人员详情API - 解决HTTP 0错误
header('Content-Type: application/json; charset=utf-8');

// 强制清理所有输出缓冲
while (ob_get_level() > 0) {
    ob_end_clean();
}

// 启用错误报告用于调试
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 设置缓存控制
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// 响应数组
$response = ['success' => false, 'error' => '', 'details' => null];

// 调试信息
$debug = [];

try {
    $debug[] = "开始处理请求";
    
    // 验证参数
    if (!isset($_GET['personnel_id']) || empty($_GET['personnel_id'])) {
        throw new Exception('人员ID不能为空');
    }
    
    $personnel_id = intval($_GET['personnel_id']);
    if ($personnel_id <= 0) {
        throw new Exception('无效的人员ID');
    }

    $debug[] = "人员ID: $personnel_id";
    
    // 检查数据库配置文件
    $dbConfigPath = __DIR__ . '/../config/database.php';
    if (!file_exists($dbConfigPath)) {
        throw new Exception('数据库配置文件不存在: ' . $dbConfigPath);
    }
    
    require_once $dbConfigPath;
    $debug[] = "数据库配置文件加载成功";
    
    // 创建数据库连接
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception('数据库连接失败');
    }
    
    $debug[] = "数据库连接成功";

    // 检查表是否存在
    $checkTable = $db->query("SHOW TABLES LIKE 'personnel'");
    if ($checkTable->rowCount() == 0) {
        throw new Exception('personnel表不存在');
    }
    
    $checkTable = $db->query("SHOW TABLES LIKE 'project_department_personnel'");
    if ($checkTable->rowCount() == 0) {
        throw new Exception('project_department_personnel表不存在');
    }
    
    $debug[] = "数据表检查通过";

    // 查询人员详情
    $query = "SELECT 
        p.id, p.name, p.email, p.phone, p.id_card, p.gender, p.created_at,
        pdp.project_id, pdp.company_id, pdp.department_ids, pdp.position, pdp.start_date, pdp.end_date, pdp.status,
        c.name as company_name,
        pr.name as project_name
    FROM personnel p
    LEFT JOIN project_department_personnel pdp ON p.id = pdp.personnel_id
    LEFT JOIN companies c ON pdp.company_id = c.id
    LEFT JOIN projects pr ON pdp.project_id = pr.id
    WHERE p.id = :personnel_id
    ORDER BY pdp.start_date DESC
    LIMIT 1";
    
    $debug[] = "执行查询: $query";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':personnel_id', $personnel_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        throw new Exception('未找到该人员信息');
    }
    
    $response['success'] = true;
    $response['details'] = [
        'id' => $result['id'],
        'name' => $result['name'],
        'email' => $result['email'],
        'phone' => $result['phone'],
        'id_card' => $result['id_card'],
        'gender' => $result['gender'],
        'position' => $result['position'],
        'company_id' => $result['company_id'],
        'company_name' => $result['company_name'],
        'project_id' => $result['project_id'],
        'project_name' => $result['project_name'],
        'department_ids' => $result['department_ids'],
        'start_date' => $result['start_date'],
        'end_date' => $result['end_date'],
        'status' => $result['status']
    ];

} catch (Exception $e) {
    $response['error'] = $e->getMessage();
    $response['debug'] = $debug;
}

// 确保纯JSON输出
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;