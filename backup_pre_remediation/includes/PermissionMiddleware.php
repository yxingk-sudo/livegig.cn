<?php
/**
 * 权限中间件类
 * 负责在页面访问和API调用时进行权限验证
 */

require_once __DIR__ . '/PermissionManager.php';

class PermissionMiddleware {
    private $permissionManager;
    private $db;
    
    public function __construct($database) {
        $this->db = $database;
        $this->permissionManager = new PermissionManager($database);
    }
    
    // ============================================================
    // 后台权限验证
    // ============================================================
    
    /**
     * 后台页面权限验证
     * @param string|array $requiredPermissions 必需的权限（单个或多个）
     * @param bool $requireAll 是否需要全部权限（false表示只需其中之一）
     * @return bool
     */
    public function checkAdminPagePermission($requiredPermissions, $requireAll = false) {
        // 确保会话已启动
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // 检查是否已登录
        if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
            $this->redirectToLogin('admin');
            return false;
        }
        
        $adminUserId = $_SESSION['admin_user_id'] ?? null;
        
        if (!$adminUserId) {
            $this->redirectToLogin('admin');
            return false;
        }
        
        // 转换为数组
        if (!is_array($requiredPermissions)) {
            $requiredPermissions = [$requiredPermissions];
        }
        
        // 检查权限
        if ($requireAll) {
            $hasPermission = $this->permissionManager->hasAllPermissions(
                $adminUserId, 
                'admin', 
                $requiredPermissions
            );
        } else {
            $hasPermission = $this->permissionManager->hasAnyPermission(
                $adminUserId, 
                'admin', 
                $requiredPermissions
            );
        }
        
        if (!$hasPermission) {
            $this->showAccessDenied();
            return false;
        }
        
        return true;
    }
    
    /**
     * 后台功能权限验证（用于按钮、操作等）
     */
    public function hasAdminFunctionPermission($permissionKey) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $adminUserId = $_SESSION['admin_user_id'] ?? null;
        
        if (!$adminUserId) {
            return false;
        }
        
        return $this->permissionManager->hasPermission($adminUserId, 'admin', $permissionKey);
    }
    
    /**
     * 检查后台用户是否有公司访问权限
     */
    public function checkAdminCompanyAccess($companyId) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $adminUserId = $_SESSION['admin_user_id'] ?? null;
        
        if (!$adminUserId) {
            return false;
        }
        
        $scope = $this->permissionManager->getUserCompanyScope($adminUserId);
        
        if (!$scope) {
            return false;
        }
        
        // 超级管理员可以访问所有公司
        if ($scope['type'] === 'all') {
            return true;
        }
        
        // 检查是否匹配用户的公司
        return $scope['company_id'] == $companyId;
    }
    
    /**
     * 检查后台用户是否有项目访问权限
     */
    public function checkAdminProjectAccess($projectId) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $adminUserId = $_SESSION['admin_user_id'] ?? null;
        
        if (!$adminUserId) {
            return false;
        }
        
        $scope = $this->permissionManager->getUserProjectScope($adminUserId);
        
        if (!$scope) {
            return false;
        }
        
        // 超级管理员可以访问所有项目
        if ($scope['type'] === 'all') {
            return true;
        }
        
        // 管理员可以访问其公司下的所有项目
        if ($scope['type'] === 'company') {
            $query = "SELECT COUNT(*) FROM projects WHERE id = :project_id AND company_id = :company_id";
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                ':project_id' => $projectId,
                ':company_id' => $scope['company_id']
            ]);
            return $stmt->fetchColumn() > 0;
        }
        
        // 项目管理员只能访问指定的项目
        if ($scope['type'] === 'projects') {
            return in_array($projectId, $scope['project_ids']);
        }
        
        return false;
    }
    
    /**
     * 获取后台用户可访问的公司列表
     */
    public function getAdminAccessibleCompanies() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $adminUserId = $_SESSION['admin_user_id'] ?? null;
        
        if (!$adminUserId) {
            return [];
        }
        
        $scope = $this->permissionManager->getUserCompanyScope($adminUserId);
        
        if (!$scope) {
            return [];
        }
        
        // 超级管理员可以访问所有公司
        if ($scope['type'] === 'all') {
            $query = "SELECT * FROM companies ORDER BY name ASC";
            $stmt = $this->db->query($query);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // 其他角色只能访问其所属公司
        $query = "SELECT * FROM companies WHERE id = :company_id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':company_id' => $scope['company_id']]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 获取后台用户可访问的项目列表
     */
    public function getAdminAccessibleProjects($companyId = null) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $adminUserId = $_SESSION['admin_user_id'] ?? null;
        
        if (!$adminUserId) {
            return [];
        }
        
        $scope = $this->permissionManager->getUserProjectScope($adminUserId);
        
        if (!$scope) {
            return [];
        }
        
        // 超级管理员可以访问所有项目
        if ($scope['type'] === 'all') {
            $query = "SELECT * FROM projects";
            $params = [];
            
            if ($companyId) {
                $query .= " WHERE company_id = :company_id";
                $params[':company_id'] = $companyId;
            }
            
            $query .= " ORDER BY name ASC";
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // 管理员可以访问其公司下的所有项目
        if ($scope['type'] === 'company') {
            $query = "SELECT * FROM projects WHERE company_id = :company_id ORDER BY name ASC";
            $stmt = $this->db->prepare($query);
            $stmt->execute([':company_id' => $scope['company_id']]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // 项目管理员只能访问指定的项目
        if ($scope['type'] === 'projects' && !empty($scope['project_ids'])) {
            $placeholders = implode(',', array_fill(0, count($scope['project_ids']), '?'));
            $query = "SELECT * FROM projects WHERE id IN ($placeholders) ORDER BY name ASC";
            $stmt = $this->db->prepare($query);
            $stmt->execute($scope['project_ids']);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return [];
    }
    
    // ============================================================
    // 前台权限验证
    // ============================================================
    
    /**
     * 前台页面权限验证
     */
    public function checkUserPagePermission($requiredPermissions, $requireAll = false) {
        // 确保会话已启动
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // 检查是否已登录
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['project_id'])) {
            $this->redirectToLogin('user');
            return false;
        }
        
        $userId = $_SESSION['user_id'];
        $projectId = $_SESSION['project_id'];
        
        // 转换为数组
        if (!is_array($requiredPermissions)) {
            $requiredPermissions = [$requiredPermissions];
        }
        
        // 检查权限
        if ($requireAll) {
            $hasPermission = $this->permissionManager->hasAllPermissions(
                $userId, 
                'project_user', 
                $requiredPermissions,
                $projectId
            );
        } else {
            $hasPermission = $this->permissionManager->hasAnyPermission(
                $userId, 
                'project_user', 
                $requiredPermissions,
                $projectId
            );
        }
        
        if (!$hasPermission) {
            $this->showAccessDenied();
            return false;
        }
        
        return true;
    }
    
    /**
     * 前台功能权限验证
     */
    public function hasUserFunctionPermission($permissionKey) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $userId = $_SESSION['user_id'] ?? null;
        $projectId = $_SESSION['project_id'] ?? null;
        
        if (!$userId || !$projectId) {
            return false;
        }
        
        return $this->permissionManager->hasPermission($userId, 'project_user', $permissionKey, $projectId);
    }
    
    /**
     * 检查前台用户是否为管理员
     */
    public function isUserAdmin() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $userId = $_SESSION['user_id'] ?? null;
        $projectId = $_SESSION['project_id'] ?? null;
        
        if (!$userId || !$projectId) {
            return false;
        }
        
        $role = $this->permissionManager->getUserRole($userId, 'project_user');
        
        return $role && $role['role_key'] === 'user_admin';
    }
    
    // ============================================================
    // API权限验证
    // ============================================================
    
    /**
     * API权限验证（后台）
     */
    public function checkAdminApiPermission($requiredPermissions, $requireAll = false) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
            $this->sendJsonResponse([
                'success' => false,
                'message' => '未登录或登录已过期'
            ], 401);
            return false;
        }
        
        $adminUserId = $_SESSION['admin_user_id'] ?? null;
        
        if (!$adminUserId) {
            $this->sendJsonResponse([
                'success' => false,
                'message' => '用户信息无效'
            ], 401);
            return false;
        }
        
        // 转换为数组
        if (!is_array($requiredPermissions)) {
            $requiredPermissions = [$requiredPermissions];
        }
        
        // 检查权限
        if ($requireAll) {
            $hasPermission = $this->permissionManager->hasAllPermissions(
                $adminUserId, 
                'admin', 
                $requiredPermissions
            );
        } else {
            $hasPermission = $this->permissionManager->hasAnyPermission(
                $adminUserId, 
                'admin', 
                $requiredPermissions
            );
        }
        
        if (!$hasPermission) {
            $this->sendJsonResponse([
                'success' => false,
                'message' => '权限不足'
            ], 403);
            return false;
        }
        
        return true;
    }
    
    /**
     * API权限验证（前台）
     */
    public function checkUserApiPermission($requiredPermissions, $requireAll = false) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['project_id'])) {
            $this->sendJsonResponse([
                'success' => false,
                'message' => '未登录或登录已过期'
            ], 401);
            return false;
        }
        
        $userId = $_SESSION['user_id'];
        $projectId = $_SESSION['project_id'];
        
        // 转换为数组
        if (!is_array($requiredPermissions)) {
            $requiredPermissions = [$requiredPermissions];
        }
        
        // 检查权限
        if ($requireAll) {
            $hasPermission = $this->permissionManager->hasAllPermissions(
                $userId, 
                'project_user', 
                $requiredPermissions,
                $projectId
            );
        } else {
            $hasPermission = $this->permissionManager->hasAnyPermission(
                $userId, 
                'project_user', 
                $requiredPermissions,
                $projectId
            );
        }
        
        if (!$hasPermission) {
            $this->sendJsonResponse([
                'success' => false,
                'message' => '权限不足'
            ], 403);
            return false;
        }
        
        return true;
    }
    
    // ============================================================
    // 辅助方法
    // ============================================================
    
    /**
     * 重定向到登录页面
     */
    private function redirectToLogin($type) {
        $loginUrl = $type === 'admin' ? '/admin/login.php' : '/user/login.php';
        
        // 保存原始请求URL
        if (!headers_sent()) {
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';
            header('Location: ' . $loginUrl);
            exit;
        }
    }
    
    /**
     * 显示访问拒绝页面
     */
    private function showAccessDenied() {
        http_response_code(403);
        
        if (!headers_sent()) {
            echo '<!DOCTYPE html>
            <html lang="zh-CN">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>访问被拒绝</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                <style>
                    .access-denied-container {
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        min-height: 100vh;
                        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    }
                    .access-denied-box {
                        background: white;
                        padding: 3rem;
                        border-radius: 1rem;
                        box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                        text-align: center;
                        max-width: 500px;
                    }
                    .access-denied-icon {
                        font-size: 5rem;
                        color: #dc3545;
                        margin-bottom: 1rem;
                    }
                </style>
            </head>
            <body>
                <div class="access-denied-container">
                    <div class="access-denied-box">
                        <div class="access-denied-icon">
                            <i class="bi bi-shield-x"></i>
                        </div>
                        <h1 class="mb-3">访问被拒绝</h1>
                        <p class="text-muted mb-4">您没有权限访问此页面或执行此操作。</p>
                        <div class="d-grid gap-2">
                            <a href="javascript:history.back()" class="btn btn-primary">返回上一页</a>
                            <a href="index.php" class="btn btn-outline-secondary">返回首页</a>
                        </div>
                    </div>
                </div>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
            </body>
            </html>';
            exit;
        }
    }
    
    /**
     * 发送JSON响应
     */
    private function sendJsonResponse($data, $statusCode = 200) {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json');
            echo json_encode($data, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    /**
     * 获取权限管理器实例
     */
    public function getPermissionManager() {
        return $this->permissionManager;
    }
}

// ============================================================
// 全局辅助函数
// ============================================================

/**
 * 检查当前用户是否有指定权限（后台）
 */
function hasAdminPermission($permissionKey) {
    global $db;
    
    if (!$db) {
        return false;
    }
    
    $middleware = new PermissionMiddleware($db);
    return $middleware->hasAdminFunctionPermission($permissionKey);
}

/**
 * 检查当前用户是否有指定权限（前台）
 */
function hasUserPermission($permissionKey) {
    global $db;
    
    if (!$db) {
        return false;
    }
    
    $middleware = new PermissionMiddleware($db);
    return $middleware->hasUserFunctionPermission($permissionKey);
}

/**
 * 要求后台页面权限
 */
function requireAdminPermission($permissions, $requireAll = false) {
    global $db;
    
    if (!$db) {
        die('数据库连接失败');
    }
    
    $middleware = new PermissionMiddleware($db);
    return $middleware->checkAdminPagePermission($permissions, $requireAll);
}

/**
 * 要求前台页面权限
 */
function requireUserPermission($permissions, $requireAll = false) {
    global $db;
    
    if (!$db) {
        die('数据库连接失败');
    }
    
    $middleware = new PermissionMiddleware($db);
    return $middleware->checkUserPagePermission($permissions, $requireAll);
}
?>
