<?php
/**
 * 后台页面基础控制器
 * 所有管理后台页面应继承此类，自动获得权限验证保护
 * 
 * 使用示例:
 * ```php
 * <?php
 * require_once '../includes/BaseAdminController.php';
 * 
 * class PersonnelPage extends BaseAdminController {
 *     protected $permissionKey = 'backend:personnel:list';
 *     
 *     public function init() {
 *         parent::init();
 *         // 页面初始化逻辑
 *     }
 * }
 * 
 * $page = new PersonnelPage();
 * $page->init();
 * ?>
 * ```
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/PermissionManager.php';
require_once __DIR__ . '/PermissionMiddleware.php';

abstract class BaseAdminController {
    protected $db;
    protected $middleware;
    protected $permissionManager;
    protected $adminUserId;
    protected $adminUsername;
    
    /**
     * 当前页面的权限标识
     * 子类必须覆盖此属性
     */
    protected $permissionKey = null;
    
    /**
     * 是否需要强制验证权限（默认 true）
     * 特殊页面（如登录页）可设置为 false
     */
    protected $requirePermission = true;
    
    /**
     * 构造函数：初始化数据库和中间件
     */
    public function __construct() {
        try {
            $database = new Database();
            $this->db = $database->getConnection();
            
            if (!$this->db) {
                throw new Exception('数据库连接失败');
            }
            
            $this->middleware = new PermissionMiddleware($this->db);
            $this->permissionManager = $this->middleware->getPermissionManager();
            
        } catch (Exception $e) {
            error_log("BaseAdminController 初始化错误：" . $e->getMessage());
            die("系统初始化失败，请联系管理员");
        }
    }
    
    /**
     * 初始化方法：执行权限验证
     * 子类必须在 init() 中调用 parent::init()
     */
    public function init() {
        // 1. 检查是否需要权限验证
        if (!$this->requirePermission) {
            return;
        }
        
        // 2. 检查用户是否已登录
        $this->checkAuthentication();
        
        // 3. 检查页面访问权限
        $this->checkPagePermission();
        
        // 4. 设置通用变量
        $this->setCommonVariables();
    }
    
    /**
     * 检查用户是否已登录
     */
    protected function checkAuthentication() {
        if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
            // 如果是 AJAX 请求，返回 JSON 错误
            if ($this->isAjaxRequest()) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => '未登录或登录已过期',
                    'redirect' => 'login.php'
                ]);
                exit;
            }
            
            // 重定向到登录页
            header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
            exit;
        }
        
        // 设置用户信息
        $this->adminUserId = $_SESSION['admin_user_id'] ?? null;
        $this->adminUsername = $_SESSION['admin_username'] ?? null;
        
        if (!$this->adminUserId) {
            session_destroy();
            $this->redirectToLogin('会话无效，请重新登录');
        }
    }
    
    /**
     * 检查页面访问权限
     */
    protected function checkPagePermission() {
        // 如果没有设置 permissionKey，跳过权限检查
        if (!$this->permissionKey) {
            error_log("警告：页面 " . $_SERVER['PHP_SELF'] . " 未设置 permissionKey");
            return;
        }
        
        try {
            // 使用中间件检查权限
            $hasPermission = $this->middleware->checkAdminPagePermission($this->permissionKey);
            
            if (!$hasPermission) {
                // 如果是 AJAX 请求，返回 403
                if ($this->isAjaxRequest()) {
                    header('HTTP/1.1 403 Forbidden');
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => false,
                        'message' => '无权访问此功能',
                        'code' => 'PERMISSION_DENIED'
                    ]);
                    exit;
                }
                
                // 重定向到无权限提示页
                $this->redirectToNoPermission();
            }
            
        } catch (Exception $e) {
            error_log("权限检查错误：" . $e->getMessage());
            $this->redirectToNoPermission($e->getMessage());
        }
    }
    
    /**
     * 设置通用变量供页面使用
     */
    protected function setCommonVariables() {
        // 设置全局变量供页面模板使用
        global $adminUserId, $adminUsername;
        $adminUserId = $this->adminUserId;
        $adminUsername = $this->adminUsername;
    }
    
    /**
     * 判断是否为 AJAX 请求
     */
    protected function isAjaxRequest() {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
    
    /**
     * 重定向到登录页
     */
    protected function redirectToLogin($message = '请先登录') {
        if ($this->isAjaxRequest()) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => $message,
                'redirect' => 'login.php'
            ]);
            exit;
        }
        
        $_SESSION['flash_message'] = $message;
        header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
    
    /**
     * 重定向到无权限提示页
     */
    protected function redirectToNoPermission($message = '') {
        if ($this->isAjaxRequest()) {
            header('HTTP/1.1 403 Forbidden');
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => $message ?: '您没有访问此页面的权限',
                'code' => 'PERMISSION_DENIED'
            ]);
            exit;
        }
        
        // 显示错误页面
        http_response_code(403);
        include __DIR__ . '/../admin/includes/no_permission.php';
        exit;
    }
    
    /**
     * 获取当前管理员 ID
     */
    public function getAdminUserId() {
        return $this->adminUserId;
    }
    
    /**
     * 获取当前管理员用户名
     */
    public function getAdminUsername() {
        return $this->adminUsername;
    }
    
    /**
     * 获取权限管理器实例
     */
    public function getPermissionManager() {
        return $this->permissionManager;
    }
}
