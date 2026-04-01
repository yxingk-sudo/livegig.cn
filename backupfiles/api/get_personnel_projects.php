<?php
// 修复版人员项目API - 解决HTTP 0错误
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
$response = ['success' => false, 'error' => '', 'projects' => [], 'count' => 0];

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
    $checkTable = $db->query("SHOW TABLES LIKE 'project_department_personnel'");
    if ($checkTable->rowCount() == 0) {
        throw new Exception('project_department_personnel表不存在');
    }
    
    $checkTable = $db->query("SHOW TABLES LIKE 'companies'");
    if ($checkTable->rowCount() == 0) {
        throw new Exception('companies表不存在');
    }
    
    $checkTable = $db->query("SHOW TABLES LIKE 'projects'");
    if ($checkTable->rowCount() == 0) {
        throw new Exception('projects表不存在');
    }
    
    $checkTable = $db->query("SHOW TABLES LIKE 'departments'");
    if ($checkTable->rowCount() == 0) {
        throw new Exception('departments表不存在');
    }
    
    $debug[] = "数据表检查通过";

    // 查询人员项目信息 - 使用join_date字段
    $query = "SELECT 
        pdp.id,
        pdp.project_id,
        p2.company_id,
        c.name as company_name,
        p2.name as project_name,
        d.name as department_name,
        pdp.position,
        pdp.status,
        pdp.created_at,
        pdp.join_date
    FROM project_department_personnel pdp
    LEFT JOIN projects p2 ON pdp.project_id = p2.id
    LEFT JOIN companies c ON p2.company_id = c.id
    LEFT JOIN departments d ON pdp.department_id = d.id
    WHERE pdp.personnel_id = :personnel_id
    ORDER BY pdp.created_at DESC";
    
    $debug[] = "执行查询: $query";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':personnel_id', $personnel_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $projects = [];
    $debug[] = "开始获取数据";
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $projects[] = [
            'id' => $row['id'],
            'project_id' => $row['project_id'],
            'project_name' => $row['project_name'],
            'company_id' => $row['company_id'],
            'company_name' => $row['company_name'],
            'department_name' => $row['department_name'], // 单数形式匹配前端
            'position' => $row['position'],
            'start_date' => $row['join_date'] ?? $row['created_at'], // 优先使用join_date，否则使用created_at
            'end_date' => null, // 暂时设为null，因为没有结束日期字段
            'status' => $row['status']
        ];
    }
    
    $debug[] = "获取到 " . count($projects) . " 条记录";
    
    $response['success'] = true;
    $response['projects'] = $projects;
    $response['count'] = count($projects);
    $response['debug'] = $debug;

} catch (Exception $e) {
    $response['error'] = $e->getMessage();
    $response['debug'] = $debug;
}

// 确保纯JSON输出
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;