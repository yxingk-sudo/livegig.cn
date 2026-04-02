<?php
/**
 * 更新交通地点 AJAX 处理文件
 * 用于添加、更新、删除项目的交通地点
 */

session_start();

// 引入基础控制器进行权限验证
require_once '../includes/BaseAdminController.php';
require_once '../config/database.php';
require_once '../includes/PermissionMiddleware.php';

header('Content-Type: application/json');

// 验证登录状态
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

// 获取数据库连接
$database = new Database();
$db = $database->getConnection();

// 获取请求数据
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

try {
    switch ($action) {
        case 'delete':
            // 删除交通地点
            $locationId = intval($input['location_id'] ?? 0);

            if (!$locationId) {
                echo json_encode(['success' => false, 'message' => '地点ID不能为空']);
                exit;
            }

            // 检查地点是否存在
            $checkStmt = $db->prepare("SELECT id FROM transportation_locations WHERE id = ?");
            $checkStmt->execute([$locationId]);
            if (!$checkStmt->fetch()) {
                echo json_encode(['success' => false, 'message' => '地点不存在']);
                exit;
            }

            // 删除地点
            $deleteStmt = $db->prepare("DELETE FROM transportation_locations WHERE id = ?");
            $deleteStmt->execute([$locationId]);

            echo json_encode(['success' => true, 'message' => '删除成功']);
            break;

        case 'update':
            // 更新交通地点
            $locationId = intval($input['location_id'] ?? 0);
            $locationName = trim($input['location_name'] ?? '');
            $locationType = trim($input['location_type'] ?? '');

            if (!$locationId || empty($locationName)) {
                echo json_encode(['success' => false, 'message' => '地点ID和名称不能为空']);
                exit;
            }

            // 更新地点
            $updateStmt = $db->prepare("
                UPDATE transportation_locations
                SET name = ?, type = ?
                WHERE id = ?
            ");
            $updateStmt->execute([$locationName, $locationType, $locationId]);

            echo json_encode(['success' => true, 'message' => '更新成功']);
            break;

        case 'add':
            // 添加交通地点
            $projectId = intval($input['project_id'] ?? 0);
            $locationName = trim($input['location_name'] ?? '');
            $locationType = trim($input['location_type'] ?? '');

            if (!$projectId || empty($locationName)) {
                echo json_encode(['success' => false, 'message' => '项目ID和地点名称不能为空']);
                exit;
            }

            // 插入地点
            $insertStmt = $db->prepare("
                INSERT INTO transportation_locations (project_id, name, type, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $insertStmt->execute([$projectId, $locationName, $locationType]);

            $newId = $db->lastInsertId();
            echo json_encode(['success' => true, 'message' => '添加成功', 'location_id' => $newId]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => '无效的操作']);
    }
} catch (Exception $e) {
    error_log("更新交通地点错误: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '操作失败：' . $e->getMessage()]);
}
