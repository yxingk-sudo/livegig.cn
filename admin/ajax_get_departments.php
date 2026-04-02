<?php
session_start();

// 引入基础控制器进行权限验证
require_once '../includes/BaseAdminController.php';
require_once '../config/database.php';

header('Content-Type: application/json; charset=utf-8');

// 验证管理员登录
if (!isset($_SESSION['admin_logged_in'])) {
    echo json_encode(['error' => '未授权访问']);
    exit;
}

// 验证请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['error' => '无效的请求方法']);
    exit;
}

// 验证项目ID
if (!isset($_GET['project_id']) || !is_numeric($_GET['project_id'])) {
    echo json_encode(['error' => '项目ID无效']);
    exit;
}

$project_id = intval($_GET['project_id']);

$database = new Database();
$db = $database->getConnection();

try {
    // 查询指定项目的部门
    $query = "SELECT id, name FROM departments WHERE project_id = :project_id ORDER BY name";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':project_id', $project_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($departments) {
        echo json_encode([
            'success' => true,
            'departments' => $departments
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'departments' => [],
            'message' => '该项目暂无部门'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'error' => '查询部门失败：' . $e->getMessage()
    ]);
}
?>