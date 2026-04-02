<?php
/**
 * 管理后台页面标准模板
 * 
 * 使用说明:
 * 1. 复制此模板创建新页面
 * 2. 修改 $permissionKey 为对应的权限标识
 * 3. 在 PageController 类中实现页面逻辑
 * 
 * 必填项检查清单:
 * □ 设置正确的 $permissionKey (参考 permissions 表)
 * □ 调用 parent::init() 进行权限验证
 * □ 设置 $page_title 页面标题
 * □ 设置 $active_page 激活的菜单项
 */

// 引入基础控制器（自动加载数据库和中间件）
require_once '../includes/BaseAdminController.php';

/**
 * 页面控制器
 */
class PageController extends BaseAdminController {
    /**
     * 【必填】当前页面的权限标识
     * 示例：'backend:personnel:list' - 人员列表
     * 示例：'backend:project:add' - 项目添加
     */
    protected $permissionKey = 'backend:your-module:function';
    
    /**
     * 页面初始化
     */
    public function init() {
        // 【重要】必须调用父类 init 方法进行权限验证
        parent::init();
        
        // TODO: 在此处添加页面初始化逻辑
        // 例如：获取数据、处理表单提交等
        
        $this->loadData();
    }
    
    /**
     * 加载页面数据
     */
    private function loadData() {
        // TODO: 从数据库加载数据
        // 示例:
        // $query = "SELECT * FROM your_table ORDER BY created_at DESC";
        // $stmt = $this->db->prepare($query);
        // $stmt->execute();
        // $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * 获取页面内容
     */
    public function render() {
        global $page_title, $active_page;
        
        // 设置页面变量
        $page_title = '您的页面标题';
        $active_page = 'your-page'; // 与 sidebar.php 中的菜单项对应
        
        // 引入页面头部
        require_once 'includes/header.php';
        ?>
        
        <!-- 页面内容区域 -->
        <div class="container-fluid py-4">
            <div class="row">
                <div class="col-12">
                    <div class="card shadow-sm">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">
                                <i class="bi bi-your-icon"></i> 
                                <?php echo htmlspecialchars($page_title); ?>
                            </h5>
                        </div>
                        <div class="card-body">
                            <!-- TODO: 在此处编写页面 HTML 内容 -->
                            
                            <p>页面内容...</p>
                            
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 页面脚本 -->
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // TODO: 在此处添加 JavaScript 逻辑
            console.log('页面已加载');
        });
        </script>
        
        <?php
        // 引入页面底部
        require_once 'includes/footer.php';
    }
}

// 创建并运行页面
$page = new PageController();
$page->init();
$page->render();
