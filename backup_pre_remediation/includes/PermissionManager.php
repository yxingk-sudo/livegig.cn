<?php
/**
 * 权限管理类
 * 负责处理所有权限相关的操作，包括权限验证、权限获取、权限分配等
 */

class PermissionManager {
    private $db;
    private $cache = []; // 权限缓存
    
    public function __construct($database) {
        $this->db = $database;
    }
    
    // ============================================================
    // 权限验证相关方法
    // ============================================================
    
    /**
     * 检查用户是否有指定权限
     * @param int $userId 用户ID
     * @param string $userType 用户类型：admin-后台用户，project_user-前台用户
     * @param string $permissionKey 权限标识
     * @param int $projectId 项目ID（前台用户必需）
     * @return bool
     */
    public function hasPermission($userId, $userType, $permissionKey, $projectId = null) {
        // 生成缓存键
        $cacheKey = "{$userType}_{$userId}_{$permissionKey}_" . ($projectId ?? 'all');
        
        // 检查缓存
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        try {
            // 1. 检查自定义权限（优先级最高）
            $customPermission = $this->getCustomPermission($userId, $userType, $permissionKey);
            if ($customPermission !== null) {
                $this->cache[$cacheKey] = ($customPermission === 'grant');
                return $this->cache[$cacheKey];
            }
            
            // 2. 检查角色权限
            $hasRolePermission = false;
            
            if ($userType === 'admin') {
                $hasRolePermission = $this->checkAdminPermission($userId, $permissionKey);
            } else {
                $hasRolePermission = $this->checkProjectUserPermission($userId, $permissionKey, $projectId);
            }
            
            $this->cache[$cacheKey] = $hasRolePermission;
            return $hasRolePermission;
            
        } catch (Exception $e) {
            error_log("权限检查错误: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 检查后台用户权限
     */
    private function checkAdminPermission($adminUserId, $permissionKey) {
        $query = "SELECT COUNT(*) FROM admin_users au
                  INNER JOIN roles r ON au.role_id = r.id
                  INNER JOIN role_permissions rp ON r.id = rp.role_id
                  INNER JOIN permissions p ON rp.permission_id = p.id
                  WHERE au.id = :user_id 
                  AND p.permission_key = :permission_key
                  AND p.status = 1
                  AND r.status = 1
                  AND au.status = 1";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':user_id' => $adminUserId,
            ':permission_key' => $permissionKey
        ]);
        
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * 检查前台用户权限
     */
    private function checkProjectUserPermission($userId, $permissionKey, $projectId) {
        if (!$projectId) {
            return false;
        }
        
        $query = "SELECT COUNT(*) FROM project_users pu
                  INNER JOIN user_roles ur ON pu.id = ur.user_id
                  INNER JOIN roles r ON ur.role_id = r.id
                  INNER JOIN role_permissions rp ON r.id = rp.role_id
                  INNER JOIN permissions p ON rp.permission_id = p.id
                  WHERE pu.id = :user_id 
                  AND ur.project_id = :project_id
                  AND p.permission_key = :permission_key
                  AND p.status = 1
                  AND r.status = 1
                  AND pu.is_active = 1";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':user_id' => $userId,
            ':project_id' => $projectId,
            ':permission_key' => $permissionKey
        ]);
        
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * 获取用户自定义权限
     */
    private function getCustomPermission($userId, $userType, $permissionKey) {
        $query = "SELECT ucp.permission_action 
                  FROM user_custom_permissions ucp
                  INNER JOIN permissions p ON ucp.permission_id = p.id
                  WHERE ucp.user_id = :user_id 
                  AND ucp.user_type = :user_type
                  AND p.permission_key = :permission_key";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':user_id' => $userId,
            ':user_type' => $userType,
            ':permission_key' => $permissionKey
        ]);
        
        $result = $stmt->fetchColumn();
        return $result ? $result : null;
    }
    
    /**
     * 批量检查权限
     */
    public function hasAnyPermission($userId, $userType, $permissionKeys, $projectId = null) {
        foreach ($permissionKeys as $key) {
            if ($this->hasPermission($userId, $userType, $key, $projectId)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * 检查是否拥有所有权限
     */
    public function hasAllPermissions($userId, $userType, $permissionKeys, $projectId = null) {
        foreach ($permissionKeys as $key) {
            if (!$this->hasPermission($userId, $userType, $key, $projectId)) {
                return false;
            }
        }
        return true;
    }
    
    // ============================================================
    // 权限获取相关方法
    // ============================================================
    
    /**
     * 获取用户的所有权限
     */
    public function getUserPermissions($userId, $userType, $projectId = null) {
        try {
            if ($userType === 'admin') {
                return $this->getAdminPermissions($userId);
            } else {
                return $this->getProjectUserPermissions($userId, $projectId);
            }
        } catch (Exception $e) {
            error_log("获取用户权限错误: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取后台用户权限列表
     */
    private function getAdminPermissions($adminUserId) {
        $query = "SELECT DISTINCT p.* 
                  FROM admin_users au
                  INNER JOIN roles r ON au.role_id = r.id
                  INNER JOIN role_permissions rp ON r.id = rp.role_id
                  INNER JOIN permissions p ON rp.permission_id = p.id
                  WHERE au.id = :user_id 
                  AND p.status = 1
                  AND r.status = 1
                  AND au.status = 1
                  ORDER BY p.sort_order ASC";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([':user_id' => $adminUserId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 获取前台用户权限列表
     */
    private function getProjectUserPermissions($userId, $projectId) {
        if (!$projectId) {
            return [];
        }
        
        $query = "SELECT DISTINCT p.* 
                  FROM project_users pu
                  INNER JOIN user_roles ur ON pu.id = ur.user_id
                  INNER JOIN roles r ON ur.role_id = r.id
                  INNER JOIN role_permissions rp ON r.id = rp.role_id
                  INNER JOIN permissions p ON rp.permission_id = p.id
                  WHERE pu.id = :user_id 
                  AND ur.project_id = :project_id
                  AND p.status = 1
                  AND r.status = 1
                  AND pu.is_active = 1
                  ORDER BY p.sort_order ASC";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':user_id' => $userId,
            ':project_id' => $projectId
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 获取用户菜单权限（树形结构）
     */
    public function getUserMenuTree($userId, $userType, $projectId = null) {
        $permissions = $this->getUserPermissions($userId, $userType, $projectId);
        
        // 过滤出页面类型权限
        $pagePermissions = array_filter($permissions, function($p) {
            return $p['permission_type'] === 'page';
        });
        
        // 构建树形结构
        return $this->buildPermissionTree($pagePermissions);
    }
    
    /**
     * 构建权限树
     */
    private function buildPermissionTree($permissions, $parentId = 0) {
        $tree = [];
        
        foreach ($permissions as $permission) {
            if ($permission['parent_id'] == $parentId) {
                $children = $this->buildPermissionTree($permissions, $permission['id']);
                if ($children) {
                    $permission['children'] = $children;
                }
                $tree[] = $permission;
            }
        }
        
        return $tree;
    }
    
    /**
     * 获取用户角色信息
     */
    public function getUserRole($userId, $userType) {
        try {
            if ($userType === 'admin') {
                $query = "SELECT r.* FROM admin_users au
                          INNER JOIN roles r ON au.role_id = r.id
                          WHERE au.id = :user_id AND au.status = 1";
            } else {
                $query = "SELECT r.* FROM project_users pu
                          INNER JOIN user_roles ur ON pu.id = ur.user_id
                          INNER JOIN roles r ON ur.role_id = r.id
                          WHERE pu.id = :user_id AND pu.is_active = 1
                          LIMIT 1";
            }
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([':user_id' => $userId]);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("获取用户角色错误: " . $e->getMessage());
            return null;
        }
    }
    
    // ============================================================
    // 权限管理相关方法（仅供超级管理员和管理员使用）
    // ============================================================
    
    /**
     * 为角色分配权限
     */
    public function assignPermissionToRole($roleId, $permissionId, $operatorId, $operatorType) {
        try {
            // 检查是否已存在
            $checkQuery = "SELECT COUNT(*) FROM role_permissions 
                          WHERE role_id = :role_id AND permission_id = :permission_id";
            $checkStmt = $this->db->prepare($checkQuery);
            $checkStmt->execute([':role_id' => $roleId, ':permission_id' => $permissionId]);
            
            if ($checkStmt->fetchColumn() > 0) {
                return ['success' => false, 'message' => '权限已存在'];
            }
            
            // 插入权限
            $query = "INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)";
            $stmt = $this->db->prepare($query);
            $stmt->execute([':role_id' => $roleId, ':permission_id' => $permissionId]);
            
            // 记录日志
            $this->logPermissionAction($operatorId, $operatorType, 'permission_grant', 'role', $roleId, [
                'permission_id' => $permissionId
            ]);
            
            // 清除缓存
            $this->cache = [];
            
            return ['success' => true, 'message' => '权限分配成功'];
        } catch (Exception $e) {
            error_log("分配权限错误: " . $e->getMessage());
            return ['success' => false, 'message' => '权限分配失败：' . $e->getMessage()];
        }
    }
    
    /**
     * 撤销角色权限
     */
    public function revokePermissionFromRole($roleId, $permissionId, $operatorId, $operatorType) {
        try {
            $query = "DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id";
            $stmt = $this->db->prepare($query);
            $stmt->execute([':role_id' => $roleId, ':permission_id' => $permissionId]);
            
            // 记录日志
            $this->logPermissionAction($operatorId, $operatorType, 'permission_revoke', 'role', $roleId, [
                'permission_id' => $permissionId
            ]);
            
            // 清除缓存
            $this->cache = [];
            
            return ['success' => true, 'message' => '权限撤销成功'];
        } catch (Exception $e) {
            error_log("撤销权限错误: " . $e->getMessage());
            return ['success' => false, 'message' => '权限撤销失败：' . $e->getMessage()];
        }
    }
    
    /**
     * 批量分配权限给角色
     */
    public function batchAssignPermissionsToRole($roleId, $permissionIds, $operatorId, $operatorType) {
        try {
            $this->db->beginTransaction();
            
            // 先删除原有权限
            $deleteQuery = "DELETE FROM role_permissions WHERE role_id = :role_id";
            $deleteStmt = $this->db->prepare($deleteQuery);
            $deleteStmt->execute([':role_id' => $roleId]);
            
            // 插入新权限
            if (!empty($permissionIds)) {
                $insertQuery = "INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)";
                $insertStmt = $this->db->prepare($insertQuery);
                
                foreach ($permissionIds as $permissionId) {
                    $insertStmt->execute([':role_id' => $roleId, ':permission_id' => $permissionId]);
                }
            }
            
            $this->db->commit();
            
            // 记录日志
            $this->logPermissionAction($operatorId, $operatorType, 'permission_grant', 'role', $roleId, [
                'permission_ids' => $permissionIds,
                'action' => 'batch_assign'
            ]);
            
            // 清除缓存
            $this->cache = [];
            
            return ['success' => true, 'message' => '批量权限分配成功'];
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("批量分配权限错误: " . $e->getMessage());
            return ['success' => false, 'message' => '批量权限分配失败：' . $e->getMessage()];
        }
    }
    
    /**
     * 为用户设置自定义权限
     */
    public function setCustomPermission($userId, $userType, $permissionId, $action, $operatorId, $operatorType) {
        try {
            // 先删除原有自定义权限
            $deleteQuery = "DELETE FROM user_custom_permissions 
                           WHERE user_id = :user_id AND user_type = :user_type AND permission_id = :permission_id";
            $deleteStmt = $this->db->prepare($deleteQuery);
            $deleteStmt->execute([
                ':user_id' => $userId,
                ':user_type' => $userType,
                ':permission_id' => $permissionId
            ]);
            
            // 插入新权限
            $insertQuery = "INSERT INTO user_custom_permissions (user_id, user_type, permission_id, permission_action) 
                           VALUES (:user_id, :user_type, :permission_id, :action)";
            $insertStmt = $this->db->prepare($insertQuery);
            $insertStmt->execute([
                ':user_id' => $userId,
                ':user_type' => $userType,
                ':permission_id' => $permissionId,
                ':action' => $action
            ]);
            
            // 记录日志
            $this->logPermissionAction($operatorId, $operatorType, 
                $action === 'grant' ? 'permission_grant' : 'permission_revoke', 
                'user', $userId, [
                'permission_id' => $permissionId,
                'user_type' => $userType
            ]);
            
            // 清除缓存
            $this->cache = [];
            
            return ['success' => true, 'message' => '自定义权限设置成功'];
        } catch (Exception $e) {
            error_log("设置自定义权限错误: " . $e->getMessage());
            return ['success' => false, 'message' => '自定义权限设置失败：' . $e->getMessage()];
        }
    }
    
    /**
     * 获取角色的所有权限
     */
    public function getRolePermissions($roleId) {
        try {
            $query = "SELECT p.* FROM role_permissions rp
                      INNER JOIN permissions p ON rp.permission_id = p.id
                      WHERE rp.role_id = :role_id
                      ORDER BY p.sort_order ASC";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([':role_id' => $roleId]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("获取角色权限错误: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取所有权限列表
     */
    public function getAllPermissions($resourceType = null) {
        try {
            $query = "SELECT * FROM permissions WHERE status = 1";
            $params = [];
            
            if ($resourceType) {
                $query .= " AND resource_type = :resource_type";
                $params[':resource_type'] = $resourceType;
            }
            
            $query .= " ORDER BY parent_id ASC, sort_order ASC";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("获取权限列表错误: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * 获取所有角色列表
     */
    public function getAllRoles($roleType = null) {
        try {
            $query = "SELECT * FROM roles WHERE status = 1";
            $params = [];
            
            if ($roleType) {
                $query .= " AND role_type = :role_type";
                $params[':role_type'] = $roleType;
            }
            
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("获取角色列表错误: " . $e->getMessage());
            return [];
        }
    }
    
    // ============================================================
    // 日志记录方法
    // ============================================================
    
    /**
     * 记录权限操作日志
     */
    private function logPermissionAction($operatorId, $operatorType, $actionType, $targetType, $targetId, $detail = []) {
        try {
            $query = "INSERT INTO permission_logs 
                      (operator_id, operator_type, action_type, target_type, target_id, action_detail, ip_address) 
                      VALUES (:operator_id, :operator_type, :action_type, :target_type, :target_id, :detail, :ip)";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                ':operator_id' => $operatorId,
                ':operator_type' => $operatorType,
                ':action_type' => $actionType,
                ':target_type' => $targetType,
                ':target_id' => $targetId,
                ':detail' => json_encode($detail),
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
        } catch (Exception $e) {
            error_log("记录权限日志错误: " . $e->getMessage());
        }
    }
    
    /**
     * 获取权限操作日志
     */
    public function getPermissionLogs($limit = 100, $offset = 0, $filters = []) {
        try {
            $query = "SELECT * FROM permission_logs WHERE 1=1";
            $params = [];
            
            if (!empty($filters['operator_id'])) {
                $query .= " AND operator_id = :operator_id";
                $params[':operator_id'] = $filters['operator_id'];
            }
            
            if (!empty($filters['action_type'])) {
                $query .= " AND action_type = :action_type";
                $params[':action_type'] = $filters['action_type'];
            }
            
            $query .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
            $params[':limit'] = $limit;
            $params[':offset'] = $offset;
            
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("获取权限日志错误: " . $e->getMessage());
            return [];
        }
    }
    
    // ============================================================
    // 辅助方法
    // ============================================================
    
    /**
     * 清除权限缓存
     */
    public function clearCache() {
        $this->cache = [];
    }
    
    /**
     * 检查用户是否为超级管理员
     */
    public function isSuperAdmin($userId, $userType) {
        if ($userType !== 'admin') {
            return false;
        }
        
        try {
            $query = "SELECT r.role_key FROM admin_users au
                      INNER JOIN roles r ON au.role_id = r.id
                      WHERE au.id = :user_id AND au.status = 1";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([':user_id' => $userId]);
            
            $roleKey = $stmt->fetchColumn();
            return $roleKey === 'super_admin';
        } catch (Exception $e) {
            error_log("检查超级管理员错误: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 检查后台用户的公司权限范围
     */
    public function getUserCompanyScope($adminUserId) {
        try {
            $query = "SELECT au.company_id, r.role_key 
                      FROM admin_users au
                      INNER JOIN roles r ON au.role_id = r.id
                      WHERE au.id = :user_id AND au.status = 1";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([':user_id' => $adminUserId]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result) {
                return null;
            }
            
            // 超级管理员可以访问所有公司
            if ($result['role_key'] === 'super_admin') {
                return ['type' => 'all', 'company_id' => null];
            }
            
            // 管理员和项目管理员限定公司范围
            return ['type' => 'company', 'company_id' => $result['company_id']];
        } catch (Exception $e) {
            error_log("获取用户公司权限范围错误: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 检查后台用户的项目权限范围
     */
    public function getUserProjectScope($adminUserId) {
        try {
            $role = $this->getUserRole($adminUserId, 'admin');
            
            if (!$role) {
                return [];
            }
            
            // 超级管理员可以访问所有项目
            if ($role['role_key'] === 'super_admin') {
                return ['type' => 'all', 'project_ids' => []];
            }
            
            // 管理员可以访问其公司下的所有项目
            if ($role['role_key'] === 'admin') {
                $query = "SELECT company_id FROM admin_users WHERE id = :user_id";
                $stmt = $this->db->prepare($query);
                $stmt->execute([':user_id' => $adminUserId]);
                $companyId = $stmt->fetchColumn();
                
                return ['type' => 'company', 'company_id' => $companyId];
            }
            
            // 项目管理员只能访问指定的项目
            if ($role['role_key'] === 'project_admin') {
                $query = "SELECT project_id FROM admin_user_projects WHERE admin_user_id = :user_id";
                $stmt = $this->db->prepare($query);
                $stmt->execute([':user_id' => $adminUserId]);
                
                $projectIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                return ['type' => 'projects', 'project_ids' => $projectIds];
            }
            
            return [];
        } catch (Exception $e) {
            error_log("获取用户项目权限范围错误: " . $e->getMessage());
            return [];
        }
    }
}
?>
