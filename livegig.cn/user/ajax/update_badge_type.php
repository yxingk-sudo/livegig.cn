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

// 支持 JSON 和 form-urlencoded 两种传参方式
$content_type = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($content_type, 'application/json') !== false) {
    $input        = json_decode(file_get_contents('php://input'), true);
    $personnel_id = $input['personnel_id'] ?? null;
    $badge_type   = $input['badge_type']   ?? null;
    $project_id   = $input['project_id']   ?? $_SESSION['project_id'] ?? null;
} else {
    $personnel_id = $_POST['personnel_id'] ?? null;
    $badge_type   = $_POST['badge_type']   ?? null;
    $project_id   = $_POST['project_id']   ?? $_SESSION['project_id'] ?? null;
}

// 验证必要参数
if (!$personnel_id) {
    echo json_encode(['success' => false, 'message' => '缺少人员ID']);
    exit;
}

$personnel_id = intval($personnel_id);
$badge_type   = trim($badge_type ?? '');

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
        // 项目上下文：更新 project_department_personnel 表的 badge_type 字段
        $project_id = intval($project_id);
        $stmt = $pdo->prepare("
            UPDATE project_department_personnel
            SET badge_type = :badge_type
            WHERE personnel_id = :personnel_id AND project_id = :project_id
        ");
        $result = $stmt->execute([
            'badge_type'   => $badge_type !== '' ? $badge_type : null,
            'personnel_id' => $personnel_id,
            'project_id'   => $project_id,
        ]);
    } else {
        // 无项目上下文（管理员）：更新所有关联记录
        $stmt = $pdo->prepare("
            UPDATE project_department_personnel
            SET badge_type = :badge_type
            WHERE personnel_id = :personnel_id
        ");
        $result = $stmt->execute([
            'badge_type'   => $badge_type !== '' ? $badge_type : null,
            'personnel_id' => $personnel_id,
        ]);
    }

    if ($result) {
        echo json_encode([
            'success'    => true,
            'message'    => '工作证类型更新成功',
            'badge_type' => $badge_type,
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '更新失败，请重试']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '数据库错误: ' . $e->getMessage()]);
}
?>
