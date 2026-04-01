<?php
// 网站配置管理页面
require_once '../config/database.php';
// 更可靠地包含page_functions.php，如果文件不存在则定义默认函数
$page_functions_path = __DIR__ . '/page_functions.php';
if (file_exists($page_functions_path)) {
    require_once $page_functions_path;
} else {
    // 定义默认的getCurrentPage函数
    if (!function_exists('getCurrentPage')) {
        function getCurrentPage() {
            return basename($_SERVER['PHP_SELF']);
        }
    }
}

// 启动会话
session_start();

// 检查是否已登录（这里简化处理，实际应该检查session）
// if (!isset($_SESSION['admin_id'])) {
//     header("Location: login.php");
//     exit();
// }

// 初始化数据库
$database = new Database();
$db = $database->getConnection();

// 检查数据库连接是否成功
if (!$db) {
    die("数据库连接失败，请联系管理员");
}

// 添加调试日志文件
$debug_log = dirname(__FILE__) . '/site_config_debug.log';
file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] 页面加载，数据库连接状态: " . ($db ? "成功" : "失败") . "\n", FILE_APPEND);

// 确保site_config表存在并初始化
if (!$database->createSiteConfigTable()) {
    error_log("创建或初始化site_config表失败");
    file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] 创建或初始化site_config表失败\n", FILE_APPEND);
}

// 动态获取当前域名
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$currentDomain = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/';
file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] 当前域名: " . $currentDomain . "\n", FILE_APPEND);

// 设置默认值
$defaults = [
    'site_name' => '企业项目管理系统',
    'site_url' => 'http://local.livegig.cn/',
    'frontend_title' => '企业项目管理系统 - 前端',
    'admin_title' => '企业项目管理系统 - 管理后台',
    'meta_description' => '专业的企业项目管理系统，提供项目、人员、报餐、酒店、出行车管理功能',
    'meta_keywords' => '项目管理,企业系统,报餐管理,酒店管理,出行车管理',
    'footer_text' => '© 2024 企业项目管理系统. 版权所有.',
    'contact_email' => 'admin@example.com',
    'contact_phone' => '400-123-4567'
];

// 初始化配置数组
$currentConfig = [];
$config = $defaults; // 默认使用默认配置

// 处理表单提交
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] 收到POST请求，开始处理表单\n", FILE_APPEND);
    
    $configs = [
        'site_name' => $_POST['site_name'] ?? '',
        'site_url' => $_POST['site_url'] ?? '',
        'frontend_title' => $_POST['frontend_title'] ?? '',
        'admin_title' => $_POST['admin_title'] ?? '',
        'meta_description' => $_POST['meta_description'] ?? '',
        'meta_keywords' => $_POST['meta_keywords'] ?? '',
        'footer_text' => $_POST['footer_text'] ?? '',
        'contact_email' => $_POST['contact_email'] ?? '',
        'contact_phone' => $_POST['contact_phone'] ?? ''
    ];
    
    // 记录POST数据
    file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] POST数据: " . json_encode($configs) . "\n", FILE_APPEND);

    try {
        // 使用Database类中的方法更新配置
        $successCount = 0;
        
        // 开启事务确保配置更新的原子性
        $db->beginTransaction();
        
        foreach ($configs as $key => $value) {
            if ($database->updateSiteConfig($key, $value)) {
                $successCount++;
                error_log("成功更新配置项 '$key' 值为 '$value'");
                file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] 成功更新 '$key' 值为 '$value'\n", FILE_APPEND);
            } else {
                throw new Exception("更新配置项 '$key' 失败");
            }
        }
        
        // 提交事务
        $db->commit();
        
        if ($successCount == count($configs)) {
            // 强制从数据库重新加载配置以验证保存是否成功
            $reloadedConfig = $database->getAllSiteConfig();
            
            if (is_array($reloadedConfig) && !empty($reloadedConfig)) {
                file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] 从数据库重新加载配置成功\n", FILE_APPEND);
                $currentConfig = $reloadedConfig;
                $config = array_merge($defaults, $currentConfig);
                
                $message = '网站配置更新成功！已更新 ' . $successCount . ' 项配置';
                $message_type = 'success';
            } else {
                throw new Exception("配置保存后验证失败，未能从数据库重新加载配置");
            }
        }
    } catch (Exception $e) {
        // 发生错误时回滚事务
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        
        $message = '更新失败：' . $e->getMessage();
        $message_type = 'danger';
        error_log('配置更新错误: ' . $e->getMessage());
        file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] 更新错误: " . $e->getMessage() . "\n", FILE_APPEND);
        
        // 出错时尝试获取配置
        try {
            $currentConfig = $database->getAllSiteConfig() ?: [];
            $config = array_merge($defaults, is_array($currentConfig) ? $currentConfig : []);
        } catch (Exception $e) {
            error_log('获取配置错误: ' . $e->getMessage());
            file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] 出错时获取配置错误: " . $e->getMessage() . "\n", FILE_APPEND);
        }
    }
} else {
    // 当不是POST请求时，确保从数据库重新加载最新配置
    file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] 非POST请求，加载数据库配置\n", FILE_APPEND);
    try {
        $currentConfig = $database->getAllSiteConfig() ?: [];
        
        // 记录加载的配置
        file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] 加载的配置: " . json_encode($currentConfig) . "\n", FILE_APPEND);
        
        // 合并配置
        $config = array_merge($defaults, is_array($currentConfig) ? $currentConfig : []);
        file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] 合并后的配置: " . json_encode($config) . "\n", FILE_APPEND);
        
        // 如果数据库没有配置，使用默认值
        if (empty($currentConfig)) {
            error_log('数据库中没有配置记录，使用默认值');
            file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] 数据库中没有配置记录，使用默认值\n", FILE_APPEND);
        }
    } catch (Exception $e) {
        error_log('获取配置错误: ' . $e->getMessage());
        file_put_contents($debug_log, "[" . date('Y-m-d H:i:s') . "] 获取配置错误: " . $e->getMessage() . "\n", FILE_APPEND);
        $currentConfig = [];
    }
}

// 处理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

?>
<?php include 'includes/header.php'; ?>

<div class="container-fluid">
    <!-- 页面标题 -->
    <div class="top-bar d-flex justify-content-between align-items-center">
        <h1 class="mb-0">
            <i class="bi bi-gear me-2 text-primary"></i>站点配置
        </h1>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="bi bi-sliders"></i> 网站基本信息配置
            </h5>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="site_name" class="form-label">网站名称</label>
                            <input type="text" class="form-control" id="site_name" name="site_name" 
                                   value="<?php echo htmlspecialchars($config['site_name']); ?>" required>
                            <small class="form-text text-muted">显示在页面标题和页脚</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="site_url" class="form-label">网站访问地址</label>
                            <input type="url" class="form-control" id="site_url" name="site_url" 
                                   value="<?php echo htmlspecialchars($config['site_url']); ?>" required>
                            <small class="form-text text-muted">当前域名：<?php echo htmlspecialchars($currentDomain); ?></small>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="frontend_title" class="form-label">前端网页标题</label>
                            <input type="text" class="form-control" id="frontend_title" name="frontend_title" 
                                   value="<?php echo htmlspecialchars($config['frontend_title']); ?>" required>
                            <small class="form-text text-muted">首页浏览器标题</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="admin_title" class="form-label">管理后台标题</label>
                            <input type="text" class="form-control" id="admin_title" name="admin_title" 
                                   value="<?php echo htmlspecialchars($config['admin_title']); ?>" required>
                            <small class="form-text text-muted">后台管理页面标题</small>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="meta_description" class="form-label">网站描述</label>
                    <textarea class="form-control" id="meta_description" name="meta_description" rows="2"><?php echo htmlspecialchars($config['meta_description']); ?></textarea>
                    <small class="form-text text-muted">用于SEO优化</small>
                </div>

                <div class="mb-3">
                    <label for="meta_keywords" class="form-label">网站关键词</label>
                    <input type="text" class="form-control" id="meta_keywords" name="meta_keywords" 
                           value="<?php echo htmlspecialchars($config['meta_keywords']); ?>">
                    <small class="form-text text-muted">多个关键词用逗号分隔</small>
                </div>

                <div class="mb-3">
                    <label for="footer_text" class="form-label">页脚文本</label>
                    <input type="text" class="form-control" id="footer_text" name="footer_text" 
                           value="<?php echo htmlspecialchars($config['footer_text']); ?>">
                    <small class="form-text text-muted">显示在页面底部</small>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="contact_email" class="form-label">联系邮箱</label>
                            <input type="email" class="form-control" id="contact_email" name="contact_email" 
                                   value="<?php echo htmlspecialchars($config['contact_email']); ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="contact_phone" class="form-label">联系电话</label>
                            <input type="tel" class="form-control" id="contact_phone" name="contact_phone" 
                                   value="<?php echo htmlspecialchars($config['contact_phone']); ?>">
                        </div>
                    </div>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> 保存配置
                    </button>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> 返回控制台
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="bi bi-info-circle"></i> 当前配置预览
            </h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <strong>网站名称：</strong> <?php echo htmlspecialchars($config['site_name']); ?><br>
                    <strong>网站地址：</strong> <a href="<?php echo htmlspecialchars($config['site_url']); ?>" target="_blank"><?php echo htmlspecialchars($config['site_url']); ?></a><br>
                    <strong>前端标题：</strong> <?php echo htmlspecialchars($config['frontend_title']); ?><br>
                    <strong>后台标题：</strong> <?php echo htmlspecialchars($config['admin_title']); ?>
                </div>
                <div class="col-md-6">
                    <strong>联系邮箱：</strong> <?php echo htmlspecialchars($config['contact_email']); ?><br>
                    <strong>联系电话：</strong> <?php echo htmlspecialchars($config['contact_phone']); ?><br>
                    <strong>页脚文本：</strong> <?php echo htmlspecialchars($config['footer_text']); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>