<?php
require_once '../config/database.php';
// 获取指定部门的人员列表 - AJAX接口

// 设置JSON响应头
header('Content-Type: application/json; charset=utf-8');

// 检查是否登录
session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => '未登录']);
    exit;
}

// 获取项目ID - 优先使用URL参数，其次使用会话
$projectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : ($_SESSION['project_id'] ?? 0);
if ($projectId <= 0) {
    echo json_encode(['success' => false, 'error' => '无效的项目ID']);
    exit;
}

// 获取部门ID
$departmentId = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
if ($departmentId <= 0) {
    echo json_encode(['success' => false, 'error' => '无效的部门ID']);
    exit;
}

try {
    // 获取部门人员
    $personnel = getDepartmentPersonnel($projectId, $departmentId, $db);
    
    // 格式化数据
    $result = [];
    foreach ($personnel as $person) {
        $result[] = [
            'id' => $person['id'],
            'name' => $person['name'],
            'gender' => $person['gender'],
            'position' => $person['position'] ?? ''
        ];
    }
    
    echo json_encode([
        'success' => true,
        'personnel' => $result,
        'count' => count($result)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => '数据库查询失败: ' . $e->getMessage()
    ]);
}
?>
