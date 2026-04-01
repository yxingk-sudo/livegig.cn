<?php
session_start();

header('Content-Type: application/json');

// 检查用户是否已登录
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

// 检查是否为POST请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '无效的请求方法']);
    exit;
}

// 同时支持 JSON 和 form-urlencoded 两种传参方式
$content_type = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($content_type, 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
    $personnel_id = $input['personnel_id'] ?? null;
    $position     = $input['position'] ?? null;
    $project_id   = $input['project_id'] ?? $_SESSION['project_id'] ?? null;
} else {
    $personnel_id = $_POST['personnel_id'] ?? null;
    $position     = $_POST['position'] ?? null;
    $project_id   = $_POST['project_id'] ?? $_SESSION['project_id'] ?? null;
}

// 验证必要参数
if (!$personnel_id) {
    echo json_encode(['success' => false, 'message' => '缺少人员ID']);
    exit;
}

$personnel_id = intval($personnel_id);
$position     = trim($position ?? '');

// 获取数据库连接
require_once __DIR__ . '/../../config/database.php';
$database = new Database();
$pdo = $database->getConnection();

if (!$pdo) {
    echo json_encode(['success' => false, 'message' => '数据库连接失败']);
    exit;
}

try {
    if ($project_id) {
        // 项目上下文：更新 project_department_personnel 表的 position 字段
        $project_id = intval($project_id);
        $stmt = $pdo->prepare("
            UPDATE project_department_personnel 
            SET position = :position 
            WHERE personnel_id = :personnel_id AND project_id = :project_id
        ");
        $result = $stmt->execute([
            'position'     => $position !== '' ? $position : null,
            'personnel_id' => $personnel_id,
            'project_id'   => $project_id,
        ]);
        $affected = $stmt->rowCount();
    } else {
        // 无项目上下文（管理员视角）：更新所有关联记录的 position
        $stmt = $pdo->prepare("
            UPDATE project_department_personnel 
            SET position = :position 
            WHERE personnel_id = :personnel_id
        ");
        $result = $stmt->execute([
            'position'     => $position !== '' ? $position : null,
            'personnel_id' => $personnel_id,
        ]);
        $affected = $stmt->rowCount();
    }

    if ($result) {
        echo json_encode([
            'success'  => true,
            'message'  => '职位更新成功',
            'position' => $position,
            'affected' => $affected,
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '更新失败，请重试']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '数据库错误: ' . $e->getMessage()]);
}
?>
