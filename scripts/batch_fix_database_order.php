<?php
/**
 * 批量检查和修复 Database 类引入顺序问题
 * 
 * 问题模式：
 * ❌ 错误：先使用后引入
 *   require_once '../includes/PermissionMiddleware.php';
 *   $database = new Database();  // Error!
 *   require_once '../config/database.php';  // 太晚
 * 
 * ✅ 正确：先引入后使用
 *   require_once '../config/database.php';  // 先引入
 *   require_once '../includes/PermissionMiddleware.php';
 *   $database = new Database();  // 后使用
 */

echo "=== Database 类引入顺序检查与修复工具 ===" . PHP_EOL;
echo str_repeat("=", 70) . PHP_EOL;

// 需要检查的 user 目录下的文件列表
$files_to_check = [
    '/www/wwwroot/livegig.cn/user/dashboard.php',
    '/www/wwwroot/livegig.cn/user/project.php',
    '/www/wwwroot/livegig.cn/user/personnel.php',
    '/www/wwwroot/livegig.cn/user/meals.php',
    '/www/wwwroot/livegig.cn/user/meals_new.php',
    '/www/wwwroot/livegig.cn/user/meals_statistics.php',
    '/www/wwwroot/livegig.cn/user/hotels.php',
    '/www/wwwroot/livegig.cn/user/hotel_add.php',
    '/www/wwwroot/livegig.cn/user/hotel_room_list.php',
    '/www/wwwroot/livegig.cn/user/hotel_room_list_2.php',
    '/www/wwwroot/livegig.cn/user/hotel_statistics.php',
    '/www/wwwroot/livegig.cn/user/transport.php',
    '/www/wwwroot/livegig.cn/user/transport_enhanced.php',
    '/www/wwwroot/livegig.cn/user/transport_list.php',
    '/www/wwwroot/livegig.cn/user/quick_transport.php',
    '/www/wwwroot/livegig.cn/user/edit_transport.php',
    '/www/wwwroot/livegig.cn/user/project_fleet.php',
    '/www/wwwroot/livegig.cn/user/project_transport.php',
    '/www/wwwroot/livegig.cn/user/personnel_edit.php',
    '/www/wwwroot/livegig.cn/user/personnel_view.php',
    '/www/wwwroot/livegig.cn/user/profile.php',
    '/www/wwwroot/livegig.cn/user/meal_allowance.php',
    '/www/wwwroot/livegig.cn/user/batch_add_personnel.php',
    '/www/wwwroot/livegig.cn/user/batch_add_personnel_step2.php',
    '/www/wwwroot/livegig.cn/user/batch_meal_order.php',
    '/www/wwwroot/livegig.cn/user/add_roommate.php',
    '/www/wwwroot/livegig.cn/user/get_department_personnel.php',
    '/www/wwwroot/livegig.cn/user/ajax_update_meal_allowance.php',
    '/www/wwwroot/livegig.cn/user/export_meal_allowance.php',
    '/www/wwwroot/livegig.cn/user/export_personnel.php',
    '/www/wwwroot/livegig.cn/user/export_transport_html.php',
    '/www/wwwroot/livegig.cn/user/user_permission_management.php',
];

$problems_found = [];
$problems_fixed = [];
$files_checked = 0;

foreach ($files_to_check as $file) {
    if (!file_exists($file)) {
        continue;
    }
    
    $files_checked++;
    $basename = basename($file);
    $content = file_get_contents($file);
    $lines = file($file);
    
    // 查找 new Database() 的行号
    $db_usage_line = null;
    foreach ($lines as $line_num => $line) {
        if (preg_match('/\$database\s*=\s*new\s+Database\(\)/', $line)) {
            $db_usage_line = $line_num + 1; // 行号从1开始
            break;
        }
    }
    
    // 如果没有使用 Database 类，跳过
    if ($db_usage_line === null) {
        continue;
    }
    
    // 查找 require_once '../config/database.php' 的行号
    $db_require_line = null;
    foreach ($lines as $line_num => $line) {
        if (preg_match("/require.*database\.php/i", $line)) {
            $db_require_line = $line_num + 1;
            break;
        }
    }
    
    // 检查问题：使用在引入之前
    if ($db_usage_line !== null && $db_require_line !== null) {
        if ($db_usage_line < $db_require_line) {
            $problems_found[] = [
                'file' => $file,
                'basename' => $basename,
                'usage_line' => $db_usage_line,
                'require_line' => $db_require_line,
            ];
            
            echo "⚠️  {$basename}" . PHP_EOL;
            echo "   问题：new Database() 在第 {$db_usage_line} 行" . PHP_EOL;
            echo "   require 在第 {$db_require_line} 行" . PHP_EOL;
            
            // 尝试修复
            $fixed = fix_database_require_order($file, $basename);
            if ($fixed) {
                $problems_fixed[] = $file;
                echo "   ✅ 已修复" . PHP_EOL;
            } else {
                echo "   ❌ 修复失败" . PHP_EOL;
            }
            echo PHP_EOL;
        }
    }
}

echo str_repeat("=", 70) . PHP_EOL;
echo "检查完成！" . PHP_EOL;
echo "总计检查文件：{$files_checked}" . PHP_EOL;
echo "发现问题文件：" . count($problems_found) . PHP_EOL;
echo "成功修复文件：" . count($problems_fixed) . PHP_EOL;
echo str_repeat("=", 70) . PHP_EOL;

/**
 * 修复 Database 类引入顺序
 */
function fix_database_require_order($file, $basename) {
    $content = file_get_contents($file);
    $lines = file($file);
    
    // 查找 require_once '../config/database.php' 的行
    $db_require_pattern = "/require_once\\s+['\"]\\.\\.\\/config\\/database\\.php['\"]\\s*;/";
    $db_require_line_num = null;
    $db_require_line_content = null;
    
    foreach ($lines as $num => $line) {
        if (preg_match($db_require_pattern, $line)) {
            $db_require_line_num = $num;
            $db_require_line_content = $line;
            break;
        }
    }
    
    // 如果没找到，尝试其他模式
    if ($db_require_line_num === null) {
        foreach ($lines as $num => $line) {
            if (stripos($line, 'database.php') !== false && preg_match('/require/i', $line)) {
                $db_require_line_num = $num;
                $db_require_line_content = $line;
                break;
            }
        }
    }
    
    if ($db_require_line_num === null) {
        echo "   ⚠️  未找到 database.php 引入语句" . PHP_EOL;
        return false;
    }
    
    // 找到 PermissionMiddleware.php 引入的位置（通常在 database.php 之前或之后）
    $pm_require_line_num = null;
    $pm_require_line_content = null;
    $pm_pattern = "/require_once\\s+['\"]\\.\\.\\/includes\\/PermissionMiddleware\\.php['\"]\\s*;/";
    
    foreach ($lines as $num => $line) {
        if (preg_match($pm_pattern, $line)) {
            $pm_require_line_num = $num;
            $pm_require_line_content = $line;
            break;
        }
    }
    
    if ($pm_require_line_num === null) {
        // 尝试其他模式
        foreach ($lines as $num => $line) {
            if (stripos($line, 'PermissionMiddleware.php') !== false && preg_match('/require/i', $line)) {
                $pm_require_line_num = $num;
                $pm_require_line_content = $line;
                break;
            }
        }
    }
    
    // 如果找到了 PermissionMiddleware 但在 database.php 之前，需要交换
    if ($pm_require_line_num !== null && $pm_require_line_num < $db_require_line_num) {
        // 移除 PermissionMiddleware 的引入
        unset($lines[$pm_require_line_num]);
        
        // 在 database.php 引入之后插入 PermissionMiddleware
        $new_lines = [];
        foreach ($lines as $num => $line) {
            $new_lines[] = $line;
            // 在 database.php 引入之后添加 PermissionMiddleware
            if (trim($line) === trim($db_require_line_content)) {
                $new_lines[] = "\n";
                $new_lines[] = $pm_require_line_content;
            }
        }
        
        // 重新索引数组
        $new_lines = array_values($new_lines);
        
        // 写回文件
        $result = file_put_contents($file, implode('', $new_lines));
        
        return $result !== false;
    }
    
    return false;
}
