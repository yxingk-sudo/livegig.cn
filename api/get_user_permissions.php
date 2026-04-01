<?php
/**
 * 获取用户权限API
 * 返回当前登录用户的所有权限列表
 */

session_start();
require_once '../../config/database.php';
require_once '../../includes/PermissionManager.php';

header('Content-Type: application/json');

try {
    // 判断是后台用户还是前台用户
    $isAdmin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
    $isUser = isset($_SESSION['user_id']) && isset($_SESSION['project_id']);
    
    if (!$isAdmin && !$isUser) {
        echo json_encode([
            'success' => false,
            'message' => '未登录'
        ]);
        exit;
    }
    
    $database = new Database();
    $db = $database->getConnection();
    $permissionManager = new PermissionManager($db);
    
    if ($isAdmin) {
        // 后台用户
        $userId = $_SESSION['admin_user_id'];
        $permissions = $permissionManager->getUserPermissions($userId, 'admin');
        $menuTree = $permissionManager->getUserMenuTree($userId, 'admin');
        $role = $permissionManager->getUserRole($userId, 'admin');
        
    } else {
        // 前台用户
        $userId = $_SESSION['user_id'];
        $projectId = $_SESSION['project_id'];
        $permissions = $permissionManager->getUserPermissions($userId, 'project_user', $projectId);
        $menuTree = $permissionManager->getUserMenuTree($userId, 'project_user', $projectId);
        $role = $permissionManager->getUserRole($userId, 'project_user');
    }
    
    echo json_encode([
        'success' => true,
        'permissions' => $permissions,
        'menu_tree' => $menuTree,
        'role' => $role
    ]);
    
} catch (Exception $e) {
    error_log("获取用户权限错误: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '获取权限失败：' . $e->getMessage()
    ]);
}
?>
