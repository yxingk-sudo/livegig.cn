<?php
// 只有在会话未启动时才启动会话
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 修复数据库配置文件路径
require_once '../../../config/database.php';

// 检查管理员是否已登录
if (!isset($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'message' => '未授权访问']);
    exit;
}

// 获取公司ID
$company_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($company_id <= 0) {
    echo json_encode(['success' => false, 'message' => '无效的公司ID']);
    exit;
}

try {
    // 连接数据库
    $database = new Database();
    $db = $database->getConnection();
    
    if ($db === null) {
        echo json_encode(['success' => false, 'message' => '数据库连接失败']);
        exit;
    }
    
    // 查询公司信息
    $query = "SELECT * FROM companies WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $company_id);
    $stmt->execute();
    
    $company = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($company) {
        echo json_encode(['success' => true, 'company' => $company]);
    } else {
        echo json_encode(['success' => false, 'message' => '未找到指定的公司']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '数据库查询错误: ' . $e->getMessage()]);
}
?>