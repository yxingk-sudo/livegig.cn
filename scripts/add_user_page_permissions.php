<?php
/**
 * 批量为前台业务页面添加权限验证代码
 * 
 * 使用方法:
 * php scripts/add_user_page_permissions.php --dry-run    # 预览模式
 * php scripts/add_user_page_permissions.php              # 实际执行
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// 配置
$baseDir = __DIR__ . '/../user';
$outputFile = __DIR__ . '/user_page_permission_report.md';

// 权限映射表（根据页面功能自动映射）
$permissionMapping = [
    'dashboard' => 'frontend:dashboard:view',
    'personnel' => 'frontend:personnel:list',
    'personnel_edit' => 'frontend:personnel:edit',
    'personnel_view' => 'frontend:personnel:view',
    'meals' => 'frontend:meal:list',
    'meals_new' => 'frontend:meal:list',
    'meals_statistics' => 'frontend:meal:statistics',
    'meal_allowance' => 'frontend:meal:allowance',
    'batch_meal_order' => 'frontend:meal:batch_order',
    'hotels' => 'frontend:hotel:list',
    'hotel_add' => 'frontend:hotel:add',
    'hotel_room_list' => 'frontend:hotel:room_list',
    'hotel_statistics' => 'frontend:hotel:statistics',
    'transport' => 'frontend:transport:list',
    'transport_enhanced' => 'frontend:transport:list',
    'transport_list' => 'frontend:transport:list',
    'quick_transport' => 'frontend:transport:quick',
    'project_fleet' => 'frontend:transport:fleet',
    'project_transport' => 'frontend:transport:list',
    'edit_transport' => 'frontend:transport:edit',
    'batch_add_personnel' => 'frontend:personnel:batch_add',
    'batch_add_personnel_step2' => 'frontend:personnel:batch_add',
    'add_roommate' => 'frontend:dormitory:add_roommate',
    'profile' => 'frontend:profile:view',
    'project' => 'frontend:project:view',
    'export_personnel' => 'frontend:personnel:export',
    'export_transport_html' => 'frontend:transport:export',
    'export_meal_allowance' => 'frontend:meal:export',
    'get_department_personnel' => 'frontend:personnel:api',
    'ajax_update_meal_allowance' => 'frontend:meal:ajax',
];

// 需要跳过的文件（认证相关、系统文件等）
$skipFiles = [
    'login.php',
    'logout.php',
    'project_login.php',
    'user_permission_management.php', // 已有权限验证
    'team_reception.sql',
];

// 统计
$stats = [
    'total' => 0,
    'modified' => 0,
    'skipped' => 0,
    'already_has' => 0,
    'errors' => 0,
];

// 预览模式
$dryRun = in_array('--dry-run', $argv) || in_array('-n', $argv);

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║     批量为前台业务页面添加权限验证代码                      ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

if ($dryRun) {
    echo "👁️  预览模式 - 不会实际修改文件\n\n";
}

$report = "# 前台业务页面权限验证批量添加报告\n\n";
$report .= "**执行时间**: " . date('Y-m-d H:i:s') . "\n";
$report .= "**执行模式**: " . ($dryRun ? '预览' : '实际执行') . "\n\n";
$report .= "---\n\n";

// 获取所有 PHP 文件
$files = glob($baseDir . '/*.php');
$stats['total'] = count($files);

foreach ($files as $file) {
    $filename = basename($file);
    
    // 跳过不需要处理
    if (in_array($filename, $skipFiles)) {
        $stats['skipped']++;
        $report .= "⏭️  跳过：**{$filename}** (系统文件)\n";
        echo "⏭️  跳过：{$filename}\n";
        continue;
    }
    
    // 读取文件内容
    $content = file_get_contents($file);
    if ($content === false) {
        $stats['errors']++;
        $report .= "❌ 错误：**{$filename}** - 无法读取文件\n";
        echo "❌ 错误：{$filename}\n";
        continue;
    }
    
    // 检查是否已有权限验证
    if (strpos($content, 'requireUserPermission') !== false ||
        strpos($content, 'checkUserPagePermission') !== false ||
        strpos($content, 'PermissionMiddleware') !== false) {
        $stats['already_has']++;
        $report .= "✅ 已有：**{$filename}** - 已包含权限验证\n";
        echo "✅ 已有：{$filename}\n";
        continue;
    }
    
    // 确定权限标识
    $baseName = pathinfo($filename, PATHINFO_FILENAME);
    $permissionKey = $permissionMapping[$baseName] ?? 'frontend:general:view';
    
    // 构建权限验证代码
    $permissionCode = <<<PHP

// 引入权限中间件进行权限验证
require_once '../includes/PermissionMiddleware.php';
\$database = new Database();
\$db = \$database->getConnection();
\$middleware = new PermissionMiddleware(\$db);
\$middleware->checkUserPagePermission('{$permissionKey}');

PHP;
    
    // 查找插入位置（在 session_start() 之后）
    $insertPosition = false;
    if (preg_match('/session_start\(\s*\)\s*;/', $content, $matches, PREG_OFFSET_CAPTURE)) {
        $insertPosition = $matches[0][1] + strlen($matches[0][0]);
    }
    
    if ($insertPosition === false) {
        $stats['errors']++;
        $report .= "❌ 错误：**{$filename}** - 未找到 session_start()\n";
        echo "❌ 错误：{$filename} (未找到 session_start)\n";
        continue;
    }
    
    // 插入权限验证代码
    $newContent = substr_replace($content, $permissionCode, $insertPosition, 0);
    
    // 确保引入了 database.php
    if (strpos($newContent, "require_once '../config/database.php'") === false) {
        // 在第一个 require_once 之前添加
        if (preg_match('/(<\?php\s*)require_once/', $newContent, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[1][1] + strlen($m[1][0]);
            $newContent = substr_replace($newContent, "\nrequire_once '../config/database.php';", $pos, 0);
        }
    }
    
    if ($dryRun) {
        $stats['modified']++;
        $report .= "📝 计划修改：**{$filename}** → 权限：`{$permissionKey}`\n";
        echo "📝 计划修改：{$filename} → {$permissionKey}\n";
    } else {
        // 保存文件
        $saveResult = file_put_contents($file, $newContent);
        if ($saveResult !== false) {
            $stats['modified']++;
            $report .= "✅ 已修改：**{$filename}** → 权限：`{$permissionKey}`\n";
            echo "✅ 已修改：{$filename} → {$permissionKey}\n";
        } else {
            $stats['errors']++;
            $report .= "❌ 错误：**{$filename}** - 保存失败\n";
            echo "❌ 错误：{$filename} (保存失败)\n";
        }
    }
}

// 生成报告
$report .= "\n---\n\n";
$report .= "## 📊 统计结果\n\n";
$report .= "| 类别 | 数量 |\n|------|------|\n";
$report .= "| 总文件数 | {$stats['total']} |\n";
$report .= "| 已修改 | {$stats['modified']} |\n";
$report .= "| 已跳过 | {$stats['skipped']} |\n";
$report .= "| 已有权限 | {$stats['already_has']} |\n";
$report .= "| 发生错误 | {$stats['errors']} |\n\n";

if (!$dryRun && $stats['modified'] > 0) {
    $report .= "## ⚠️ 重要提示\n\n";
    $report .= "1. **语法检查**: 请运行以下命令检查语法错误\n";
    $report .= "   ```bash\n";
    $report .= "   cd /www/wwwroot/livegig.cn\n";
    $report .= "   for file in user/*.php; do php -l \"\$file\"; done\n";
    $report .= "   ```\n\n";
    $report .= "2. **功能测试**: 请测试各个页面是否能正常访问\n\n";
    $report .= "3. **权限调整**: 如需调整特定页面的权限标识，请参考权限映射表\n\n";
}

// 保存报告
file_put_contents($outputFile, $report);

echo "\n╔════════════════════════════════════════════════════════════╗\n";
echo "║                        处理完成                            ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

echo "统计结果:\n";
echo "  总文件数：{$stats['total']}\n";
echo "  ✅ 已修改：{$stats['modified']}\n";
echo "  ⏭️  已跳过：{$stats['skipped']}\n";
echo "  ✅ 已有权限：{$stats['already_has']}\n";
echo "  ❌ 发生错误：{$stats['errors']}\n\n";

echo "详细报告已保存至：{$outputFile}\n\n";

if ($dryRun) {
    echo "💡 提示：确认无误后，请运行以下命令实际执行：\n";
    echo "   php scripts/add_user_page_permissions.php\n\n";
} else {
    echo "✅ 下一步：请运行语法检查\n";
    echo "   php -l user/dashboard.php\n";
    echo "   php -l user/personnel.php\n";
    echo "   ...\n\n";
}
?>
