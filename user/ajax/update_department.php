<?php
session_start();

// 检查用户是否已登录
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

// 检查是否为POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '无效的请求方法']);
    exit;
}

// 获取POST数据
$personnel_id = $_POST['personnel_id'] ?? null;
$department_id = $_POST['department_id'] ?? null;
$project_id = $_POST['project_id'] ?? null;

// 验证数据
if (!$personnel_id || !$project_id || !$department_id) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '缺少必要参数']);
    exit;
}

// 获取数据库连接
require_once __DIR__ . '/../../config/database.php';
$database = new Database();
$pdo = $database->getConnection();

try {
    // 更新人员的部门
    $update_stmt = $pdo->prepare("
        UPDATE project_department_personnel 
        SET department_id = :department_id 
        WHERE personnel_id = :personnel_id AND project_id = :project_id
    ");
    $result = $update_stmt->execute([
        'department_id' => $department_id,
        'personnel_id' => $personnel_id,
        'project_id' => $project_id
    ]);
    
    if ($result) {
        // 获取部门名称用于返回
        $dept_stmt = $pdo->prepare("SELECT name FROM departments WHERE id = :department_id");
        $dept_stmt->execute(['department_id' => $department_id]);
        $department = $dept_stmt->fetch(PDO::FETCH_ASSOC);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true, 
            'message' => '部门更新成功',
            'department_name' => $department['name'] ?? '未知部门'
        ]);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '更新失败']);
    }
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '数据库错误: ' . $e->getMessage()]);
}
?>