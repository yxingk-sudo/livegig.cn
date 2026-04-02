<?php
// PHP错误模拟器 - 检查PHP配置和错误处理
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');

echo '<!DOCTYPE html><html><head><title>PHP错误模拟器</title></head><body>';
echo '<h1>PHP错误模拟器</h1>';
echo '<pre>';

// 检查PHP配置
echo "=== PHP配置检查 ===\n";
echo "display_errors: " . ini_get('display_errors') . "\n";
echo "error_reporting: " . ini_get('error_reporting') . "\n";
echo "log_errors: " . ini_get('log_errors') . "\n";
echo "error_log: " . ini_get('error_log') . "\n";

// 检查是否有错误日志文件
$error_log_path = ini_get('error_log');
if ($error_log_path && file_exists($error_log_path)) {
    echo "错误日志文件大小: " . filesize($error_log_path) . " 字节\n";
    echo "最后10条错误日志:\n";
    $lines = array_slice(file($error_log_path), -10);
    foreach ($lines as $line) {
        echo htmlspecialchars($line);
    }
}

// 模拟常见的错误情况
echo "\n=== 模拟错误情况 ===\n";

// 1. 数据库连接错误
if (isset($_GET['test_db_error'])) {
    echo "模拟数据库连接错误...\n";
    try {
        $db = new PDO('mysql:host=invalid_host;dbname=invalid_db', 'invalid_user', 'invalid_pass');
    } catch (PDOException $e) {
        echo "PDO错误信息: " . $e->getMessage() . "\n";
    }
}

// 2. 文件包含错误
if (isset($_GET['test_include_error'])) {
    echo "模拟文件包含错误...\n";
    include 'non_existent_file.php';
}

// 3. 检查实际API文件
$api_files = [
    __DIR__ . '/get_personnel_details_clean.php',
    __DIR__ . '/get_personnel_projects_strict.php',
    __DIR__ . '/get_company_projects_strict.php',
    __DIR__ . '/../config/database.php'
];

echo "\n=== API文件检查 ===\n";
foreach ($api_files as $file) {
    echo basename($file) . ": " . (file_exists($file) ? '存在' : '不存在') . "\n";
    if (file_exists($file)) {
        echo "  大小: " . filesize($file) . " 字节\n";
        echo "  修改时间: " . date('Y-m-d H:i:s', filemtime($file)) . "\n";
    }
}

echo '</pre>';

echo '<h2>测试链接</h2>';
echo '<ul>';
echo '<li><a href="?test_db_error=1">测试数据库错误</a></li>';
echo '<li><a href="?test_include_error=1">测试文件包含错误</a></li>';
echo '<li><a href="api/personnel/get_personnel_details_clean.php?personnel_id=1" target="_blank">直接访问人员详情API</a></li>';
echo '<li><a href="get_personnel_projects_strict.php?personnel_id=1" target="_blank">直接访问人员项目API</a></li>';
echo '<li><a href="get_company_projects_strict.php?company_id=1" target="_blank">直接访问公司项目API</a></li>';
echo '</ul>';

echo '</body></html>';