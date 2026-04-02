<?php
/**
 * 快速语法检查脚本
 */

$files_to_check = [
    '/www/wwwroot/livegig.cn/user/dashboard.php',
    '/www/wwwroot/livegig.cn/user/project.php',
    '/www/wwwroot/livegig.cn/user/personnel.php',
    '/www/wwwroot/livegig.cn/user/meals.php',
    '/www/wwwroot/livegig.cn/user/hotels.php',
    '/www/wwwroot/livegig.cn/user/transport.php',
];

echo "=== PHP 文件语法检查 ===" . PHP_EOL;
echo str_repeat("=", 60) . PHP_EOL;

$has_errors = false;

foreach ($files_to_check as $file) {
    $basename = basename($file);
    
    if (file_exists($file)) {
        $output = [];
        $return_code = 0;
        exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $return_code);
        
        $output_text = implode("", $output);
        
        if (strpos($output_text, "No syntax errors") !== false) {
            echo "✅ {$basename} - 语法正确" . PHP_EOL;
        } else {
            echo "❌ {$basename} - 语法错误" . PHP_EOL;
            echo "   " . trim($output_text) . PHP_EOL;
            $has_errors = true;
        }
    } else {
        echo "⚠️  {$basename} - 文件不存在" . PHP_EOL;
    }
}

echo PHP_EOL;
if (!$has_errors) {
    echo "🎉 所有文件语法检查通过！" . PHP_EOL;
} else {
    echo "⚠️  发现语法错误，请检查。" . PHP_EOL;
}
