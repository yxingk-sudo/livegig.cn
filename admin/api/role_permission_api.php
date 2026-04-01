<?php
/**
 * 角色权限管理API
 * 用于分配和管理角色的权限
 */

session_start();
require_once '../../config/database.php';
require_once '../../includes/PermissionMiddleware.php';

header('Content-Type: application/json');

try {
    $database = new Database();
    $db = $database->getConnection();
    $middleware = new PermissionMiddleware($db);
    $permissionManager = $middleware->getPermissionManager();
    
    // 验证登录状态
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        echo json_encode([
            'success' => false,
            'message' => '未登录'
        ]);
        exit;
    }
    
    $adminUserId = $_SESSION['admin_user_id'];
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    switch ($action) {
        case 'get_role_permissions':
            // 获取角色的权限列表
            $middleware->checkAdminApiPermission('backend:system:permission');
            
            $roleId = $_GET['role_id'] ?? 0;
            if (!$roleId) {
                echo json_encode(['success' => false, 'message' => '角色ID不能为空']);
                exit;
            }
            
            $permissions = $permissionManager->getRolePermissions($roleId);
            
            echo json_encode([
                'success' => true,
                'permissions' => $permissions
            ]);
            break;
            
        case 'assign_permissions':
            // 批量分配权限给角色
            $middleware->checkAdminApiPermission('backend:system:permission:assign');
            
            $roleId = $_POST['role_id'] ?? 0;
            $permissionIds = $_POST['permission_ids'] ?? [];
            
            if (!$roleId) {
                echo json_encode(['success' => false, 'message' => '角色ID不能为空']);
                exit;
            }
            
            if (!is_array($permissionIds)) {
                $permissionIds = json_decode($permissionIds, true) ?? [];
            }
            
            $result = $permissionManager->batchAssignPermissionsToRole(
                $roleId, 
                $permissionIds, 
                $adminUserId, 
                'admin'
            );
            
            echo json_encode($result);
            break;
            
        case 'assign_permission':
            // 分配单个权限给角色
            $middleware->checkAdminApiPermission('backend:system:permission:assign');
            
            $roleId = $_POST['role_id'] ?? 0;
            $permissionId = $_POST['permission_id'] ?? 0;
            
            if (!$roleId || !$permissionId) {
                echo json_encode(['success' => false, 'message' => '参数不完整']);
                exit;
            }
            
            $result = $permissionManager->assignPermissionToRole(
                $roleId, 
                $permissionId, 
                $adminUserId, 
                'admin'
            );
            
            echo json_encode($result);
            break;
            
        case 'revoke_permission':
            // 撤销角色权限
            $middleware->checkAdminApiPermission('backend:system:permission:assign');
            
            $roleId = $_POST['role_id'] ?? 0;
            $permissionId = $_POST['permission_id'] ?? 0;
            
            if (!$roleId || !$permissionId) {
                echo json_encode(['success' => false, 'message' => '参数不完整']);
                exit;
            }
            
            $result = $permissionManager->revokePermissionFromRole(
                $roleId, 
                $permissionId, 
                $adminUserId, 
                'admin'
            );
            
            echo json_encode($result);
            break;
            
        case 'get_all_permissions':
            // 获取所有权限列表
            $middleware->checkAdminApiPermission('backend:system:permission');
            
            $resourceType = $_GET['resource_type'] ?? null;
            $permissions = $permissionManager->getAllPermissions($resourceType);
            
            echo json_encode([
                'success' => true,
                'permissions' => $permissions
            ]);
            break;
            
        case 'get_all_roles':
            // 获取所有角色列表
            $middleware->checkAdminApiPermission('backend:system:role');
            
            $roleType = $_GET['role_type'] ?? null;
            $roles = $permissionManager->getAllRoles($roleType);
            
            echo json_encode([
                'success' => true,
                'roles' => $roles
            ]);
            break;
            
        case 'set_custom_permission':
            // 为用户设置自定义权限
            $middleware->checkAdminApiPermission('backend:system:permission:assign');
            
            $userId = $_POST['user_id'] ?? 0;
            $userType = $_POST['user_type'] ?? '';
            $permissionId = $_POST['permission_id'] ?? 0;
            $permissionAction = $_POST['permission_action'] ?? 'grant';
            
            if (!$userId || !$userType || !$permissionId) {
                echo json_encode(['success' => false, 'message' => '参数不完整']);
                exit;
            }
            
            $result = $permissionManager->setCustomPermission(
                $userId,
                $userType,
                $permissionId,
                $permissionAction,
                $adminUserId,
                'admin'
            );
            
            echo json_encode($result);
            break;
            
        case 'assign_user_role':
            // 为前台用户分配角色
            $userId = $_POST['user_id'] ?? 0;
            $roleId = $_POST['role_id'] ?? 0;
            $projectId = $_POST['project_id'] ?? 0;
            
            if (!$userId || !$roleId || !$projectId) {
                echo json_encode(['success' => false, 'message' => '参数不完整']);
                exit;
            }
            
            try {
                // 先删除原有角色
                $deleteQuery = "DELETE FROM user_roles WHERE user_id = :user_id AND project_id = :project_id";
                $deleteStmt = $db->prepare($deleteQuery);
                $deleteStmt->execute([
                    ':user_id' => $userId,
                    ':project_id' => $projectId
                ]);
                
                // 插入新角色
                $insertQuery = "INSERT INTO user_roles (user_id, role_id, project_id) VALUES (:user_id, :role_id, :project_id)";
                $insertStmt = $db->prepare($insertQuery);
                $insertStmt->execute([
                    ':user_id' => $userId,
                    ':role_id' => $roleId,
                    ':project_id' => $projectId
                ]);
                
                echo json_encode(['success' => true, 'message' => '角色分配成功']);
            } catch (Exception $e) {
                error_log("分配用户角色错误: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => '分配失败：' . $e->getMessage()]);
            }
            break;
            
        case 'get_projects_by_company':
            // 获取指定公司的项目列表
            $companyId = $_GET['company_id'] ?? 0;
            
            if (!$companyId) {
                echo json_encode(['success' => false, 'message' => '公司ID不能为空']);
                exit;
            }
            
            $query = "SELECT id, name FROM projects WHERE company_id = :company_id ORDER BY name ASC";
            $stmt = $db->prepare($query);
            $stmt->execute([':company_id' => $companyId]);
            $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'projects' => $projects
            ]);
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'message' => '无效的操作'
            ]);
            break;
    }
    
} catch (Exception $e) {
    error_log("角色权限管理API错误: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '操作失败：' . $e->getMessage()
    ]);
}
?>
