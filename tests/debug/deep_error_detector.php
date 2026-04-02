<?php
// 深度错误检测器 - 捕获所有可能的错误源
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><title>深度错误检测器</title></head><body>';
echo '<h1>深度错误检测器</h1>';
echo '<style>body{font-family:monospace;margin:20px}.section{margin:20px 0;padding:15px;border:1px solid #ccc}.error{background:#ffe8e8}.warning{background:#fff3cd}.success{background:#e8f5e8}</style>';

// 1. 检查PHP配置
echo '<div class="section"><h2>1. PHP配置检查</h2>';
echo '<p>display_errors: ' . ini_get('display_errors') . '</p>';
echo '<p>error_reporting: ' . error_reporting() . '</p>';
echo '<p>log_errors: ' . ini_get('log_errors') . '</p>';
echo '<p>error_log: ' . ini_get('error_log') . '</p>';
echo '</div>';

// 2. 检查文件系统
echo '<div class="section"><h2>2. 文件系统检查</h2>';
$files = [
    'config/database.php',
    'admin/get_personnel_details_clean.php',
    'admin/get_personnel_projects_strict.php',
    'admin/get_company_projects_strict.php',
    'api/get_personnel_details.php',
    'api/get_personnel_projects.php',
    'api/get_company_projects.php'
];

foreach ($files as $file) {
    $path = dirname(__DIR__) . '/' . $file;
    echo '<p>' . htmlspecialchars($file) . ': ';
    
    if (file_exists($path)) {
        echo '存在 (' . filesize($path) . ' 字节)';
        
        // 检查文件开头
        $content = file_get_contents($path);
        $first100 = substr($content, 0, 100);
        
        // 检查BOM
        if (substr($content, 0, 3) === pack('H*','EFBBBF')) {
            echo ' <span class="error">[发现BOM标记]</span>';
        }
        
        // 检查PHP标签前内容
        $phpPos = strpos($content, '<?php');
        if ($phpPos > 0) {
            $before = substr($content, 0, $phpPos);
            echo ' <span class="error">[PHP标签前有内容]</span>';
        }
        
        echo ' <small>' . htmlspecialchars(substr($first100, 0, 50)) . '...</small>';
    } else {
        echo '<span class="error">不存在</span>';
    }
    echo '</p>';
}
echo '</div>';

// 3. 模拟API调用并捕获输出
echo '<div class="section"><h2>3. API调用测试</h2>';

function testAPI($url, $label) {
    echo '<h3>' . htmlspecialchars($label) . '</h3>';
    
    // 使用输出缓冲捕获可能的错误
    ob_start();
    
    // 模拟GET参数
    $_GET = ['personnel_id' => 1];
    if (strpos($url, 'company') !== false) {
        $_GET = ['company_id' => 1];
    }
    
    try {
        include $url;
        $output = ob_get_clean();
        
        // 检查输出
        $isJson = json_decode($output) !== null;
        $hasHtml = preg_match('/<[^>]*>/', $output);
        
        echo '<p>输出长度: ' . strlen($output) . ' 字节</p>';
        
        if ($isJson && !$hasHtml) {
            echo '<div class="success">✅ 纯净JSON响应</div>';
        } else {
            echo '<div class="error">❌ 响应异常</div>';
            echo '<p>预览: ' . htmlspecialchars(substr($output, 0, 200)) . '</p>';
        }
        
    } catch (Exception $e) {
        $output = ob_get_clean();
        echo '<div class="error">异常: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// 测试各个端点
$testFiles = [
    'admin/api/personnel/get_personnel_details_clean.php' => '纯净版人员详情',
    'admin/get_personnel_projects_strict.php' => '严格版人员项目',
    'admin/get_company_projects_strict.php' => '严格版公司项目',
    'admin/api/personnel/get_personnel_details.php' => '原始API人员详情',
    'api/get_personnel_projects.php' => '原始API人员项目',
    'api/get_company_projects.php' => '原始API公司项目'
];

foreach ($testFiles as $file => $label) {
    $path = dirname(__DIR__) . '/' . $file;
    if (file_exists($path)) {
        testAPI($path, $label);
    } else {
        echo '<p>' . htmlspecialchars($file) . ': 文件不存在</p>';
    }
}

// 4. 检查数据库连接
echo '<div class="section"><h2>4. 数据库连接测试</h2>';
try {
    require_once dirname(__DIR__) . '/config/database.php';
    
    $database = new Database();
    $pdo = $database->getConnection();
    
    if ($pdo) {
        echo '<div class="success">✅ 数据库连接成功</div>';
        
        // 检查表是否存在
        $tables = ['companies', 'projects', 'personnel', 'project_department_personnel'];
        foreach ($tables as $table) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables 
                                WHERE table_schema = 'team_reception' AND table_name = '$table'");
            $exists = $stmt->fetchColumn() > 0;
            echo '<p>表 ' . $table . ': ' . ($exists ? '存在' : '不存在') . '</p>';
        }
        
    } else {
        echo '<div class="error">❌ 数据库连接失败</div>';
    }
} catch (Exception $e) {
    echo '<div class="error">异常: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
echo '</div>';

// 5. 检查PHP错误日志
echo '<div class="section"><h2>5. PHP错误日志检查</h2>';
$errorLog = ini_get('error_log');
if ($errorLog && file_exists($errorLog)) {
    echo '<p>错误日志位置: ' . htmlspecialchars($errorLog) . '</p>';
    echo '<p>文件大小: ' . filesize($errorLog) . ' 字节</p>';
    
    // 显示最后几行
    $lines = array_slice(file($errorLog), -20);
    echo '<h3>最近错误:</h3><pre>';
    foreach ($lines as $line) {
        echo htmlspecialchars($line) . "\n";
    }
    echo '</pre>';
} else {
    echo '<p>错误日志文件不存在或不可访问</p>';
}
echo '</div>';

// 6. 检查会话和缓存
echo '<div class="section"><h2>6. 会话和缓存检查</h2>';
echo '<p>会话状态: ' . (session_status() === PHP_SESSION_ACTIVE ? '已启动' : '未启动') . '</p>';
echo '<p>输出缓冲级别: ' . ob_get_level() . '</p>';
echo '</div>';

echo '</body></html>';