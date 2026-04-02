<?php
require_once '../config/database.php';
session_start();

// 引入基础控制器进行权限验证
require_once '../includes/BaseAdminController.php';

// 检查是否登录
if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未授权访问']);
    exit;
}

// 获取人员ID
$personnel_id = isset($_GET['personnel_id']) ? intval($_GET['personnel_id']) : 0;

if ($personnel_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '无效的人员ID']);
    exit;
}

// 获取数据库连接
$database = new Database();
$db = $database->getConnection();

try {
    // 获取人员基本信息
    $person_query = "SELECT id, name, phone, email FROM personnel WHERE id = :personnel_id";
    $person_stmt = $db->prepare($person_query);
    $person_stmt->bindParam(':personnel_id', $personnel_id);
    $person_stmt->execute();
    $person = $person_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$person) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '人员不存在']);
        exit;
    }
    
    // 获取网站配置
    $site_config_query = "SELECT config_value FROM site_config WHERE config_key = 'site_url'";
    $site_config_stmt = $db->prepare($site_config_query);
    $site_config_stmt->execute();
    $site_config = $site_config_stmt->fetch(PDO::FETCH_ASSOC);
    $site_url = $site_config ? $site_config['config_value'] : 'http://localhost';
    
    // 获取人员的所有项目账户信息
    $access_query = "SELECT 
                        pu.id as account_id,
                        pu.username,
                        pu.display_name,
                        pu.role,
                        pu.is_active,
                        pu.created_at,
                        pu.updated_at,
                        p.id as project_id,
                        p.name as project_name,
                        p.code as project_code,
                        c.name as company_name
                     FROM project_users pu
                     JOIN personnel_project_users ppu ON pu.id = ppu.project_user_id
                     JOIN projects p ON pu.project_id = p.id
                     JOIN companies c ON p.company_id = c.id
                     WHERE ppu.personnel_id = :personnel_id
                     ORDER BY p.name, pu.created_at DESC";
    
    $access_stmt = $db->prepare($access_query);
    $access_stmt->bindParam(':personnel_id', $personnel_id);
    $access_stmt->execute();
    $access_list = $access_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 格式化数据
    $accounts = [];
    foreach ($access_list as $access) {
        $accounts[] = [
            'account_id' => $access['account_id'],
            'username' => $access['username'],
            'display_name' => $access['display_name'],
            'role' => $access['role'],
            'role_text' => $access['role'] === 'admin' ? '前台管理员' : '前台用户',
            'is_active' => (bool)$access['is_active'],
            'status_text' => $access['is_active'] ? '正常' : '已禁用',
            'created_at' => $access['created_at'],
            'updated_at' => $access['updated_at'],
            'project_id' => $access['project_id'],
            'project_name' => $access['project_name'],
            'project_code' => $access['project_code'],
            'company_name' => $access['company_name'],
            'access_url' => rtrim($site_url, '/') . '/user/project_login.php?code=' . $access['project_code']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'personnel' => [
            'id' => $person['id'],
            'name' => $person['name'],
            'phone' => $person['phone'],
            'email' => $person['email']
        ],
        'accounts' => $accounts,
        'total_count' => count($accounts)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '获取数据失败: ' . $e->getMessage()]);
}
