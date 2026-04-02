<?php
/**
 * 批量为前台 API 接口添加权限验证代码
 * 
 * 使用方法:
 * php scripts/add_user_api_permissions.php --dry-run    # 预览模式
 * php scripts/add_user_api_permissions.php              # 实际执行
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// 配置
$apiDir = __DIR__ . '/../user/ajax';
$outputFile = __DIR__ . '/user_api_permission_report.md';

// API 权限映射表
$apiPermissionMapping = [
    // ajax 目录下的文件
    'get_personnel_details' => 'frontend:api:get_personnel',
    'update_meal_selection' => 'frontend:api:update_meal',
    'save_transport_info' => 'frontend:api:save_transport',
    'delete_transport' => 'frontend:api:delete_transport',
    'get_hotel_rooms' => 'frontend:api:get_hotel',
    'assign_room' => 'frontend:api:assign_room',
    'batch_operations' => 'frontend:api:batch_operation',
    'export_data' => 'frontend:api:export',
    'get_statistics' => 'frontend:api:get_statistics',
    'upload_file' => 'frontend:api:upload',
    'validate_form' => 'frontend:api:validate',
    'search_user' => 'frontend:api:search',
    
    // user 根目录下的 API 类文件
    'ajax_update_meal_allowance' => 'frontend:api:meal_allowance',
    'get_department_personnel' => 'frontend:api:department_personnel',
    'api_diagnose_packages' => 'frontend:api:diagnose',
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
echo "║     批量为前台 API 接口添加权限验证代码                      ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

if ($dryRun) {
    echo "👁️  预览模式 - 不会实际修改文件\n\n";
}

$report = "# 前台 API 接口权限验证批量添加报告\n\n";
$report .= "**执行时间**: " . date('Y-m-d H:i:s') . "\n";
$report .= "**执行模式**: " . ($dryRun ? '预览' : '实际执行') . "\n\n";
$report .= "---\n\n";

// 检查 ajax 目录
if (is_dir($apiDir)) {
    echo "📂 扫描 ajax 目录...\n";
    $ajaxFiles = glob($apiDir . '/*.php');
    $stats['total'] += count($ajaxFiles);
    
    foreach ($ajaxFiles as $file) {
        processApiFile($file, $apiPermissionMapping, $stats, $report, $dryRun);
    }
}

// 扫描 user 根目录下的 API 文件
$userDir = __DIR__ . '/../user';
$userApiFiles = glob($userDir . '/*api*.php');
$stats['total'] += count($userApiFiles);

foreach ($userApiFiles as $file) {
    processApiFile($file, $apiPermissionMapping, $stats, $report, $dryRun);
}

// 也处理 ajax_update_*.php 和 get_*.php 类型的文件
$otherApiFiles = array_merge(
    glob($userDir . '/ajax_*.php'),
    glob($userDir . '/get_*.php')
);

foreach ($otherApiFiles as $file) {
    // 避免重复处理
    if (in_array($file, $userApiFiles)) {
        continue;
    }
    
    processApiFile($file, $apiPermissionMapping, $stats, $report, $dryRun);
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
    $report .= "   for file in user/ajax/*.php user/*api*.php user/ajax_*.php user/get_*.php; do php -l \"\$file\"; done\n";
    $report .= "   ```\n\n";
    $report .= "2. **功能测试**: 请使用 Postman 或浏览器开发者工具测试 API\n\n";
    $report .= "3. **权限调整**: 如需调整特定 API 的权限标识，请参考权限映射表\n\n";
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
    echo "   php scripts/add_user_api_permissions.php\n\n";
} else {
    echo "✅ 下一步：请运行语法检查\n";
    echo "   php -l user/ajax/*.php\n";
    echo "   php -l user/ajax_update_meal_allowance.php\n";
    echo "   ...\n\n";
}

/**
 * 处理单个 API 文件
 */
function processApiFile($file, &$mapping, &$stats, &$report, $dryRun) {
    global $argv;
    
    $filename = basename($file);
    
    // 读取文件内容
    $content = file_get_contents($file);
    if ($content === false) {
        $stats['errors']++;
        $report .= "❌ 错误：**{$filename}** - 无法读取文件\n";
        echo "❌ 错误：{$filename}\n";
        return;
    }
    
    // 检查是否已有权限验证
    if (strpos($content, 'checkUserApiPermission') !== false ||
        strpos($content, 'checkAdminApiPermission') !== false) {
        $stats['already_has']++;
        $report .= "✅ 已有：**{$filename}** - 已包含 API 权限验证\n";
        echo "✅ 已有：{$filename}\n";
        return;
    }
    
    // 确定权限标识
    $baseName = pathinfo($filename, PATHINFO_FILENAME);
    $permissionKey = $mapping[$baseName] ?? 'frontend:api:general';
    
    // 构建 API 权限验证代码
    $apiPermissionCode = <<<PHP

// API 权限验证
require_once '../includes/PermissionMiddleware.php';
\$database = new Database();
\$db = \$database->getConnection();
\$middleware = new PermissionMiddleware(\$db);
\$middleware->checkUserApiPermission('{$permissionKey}');

PHP;
    
    // 查找插入位置（在 session_start 或 header 之后）
    $insertPosition = false;
    
    // 优先在 session_start 之后
    if (preg_match('/session_start\(\s*\)\s*;/', $content, $matches, PREG_OFFSET_CAPTURE)) {
        $insertPosition = $matches[0][1] + strlen($matches[0][0]);
    }
    // 如果没有 session_start，在 header 之后
    elseif (preg_match('/header\s*\(\s*[\'"]Content-Type:', $content, $matches, PREG_OFFSET_CAPTURE)) {
        $insertPosition = $matches[0][1] + strlen($matches[0][0]);
    }
    
    if ($insertPosition === false) {
        // 尝试在第一个 require_once 之后
        if (preg_match('/require_once.*?;/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
            $insertPosition = $matches[0][1] + strlen($matches[0][0]);
        } else {
            $stats['errors']++;
            $report .= "❌ 错误：**{$filename}** - 未找到合适的插入位置\n";
            echo "❌ 错误：{$filename} (未找到插入位置)\n";
            return;
        }
    }
    
    // 插入权限验证代码
    $newContent = substr_replace($content, $apiPermissionCode, $insertPosition, 0);
    
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
        $report .= "📝 计划修改：**{$filename}** → API 权限：`{$permissionKey}`\n";
        echo "📝 计划修改：{$filename} → {$permissionKey}\n";
    } else {
        // 保存文件
        $saveResult = file_put_contents($file, $newContent);
        if ($saveResult !== false) {
            $stats['modified']++;
            $report .= "✅ 已修改：**{$filename}** → API 权限：`{$permissionKey}`\n";
            echo "✅ 已修改：{$filename} → {$permissionKey}\n";
        } else {
            $stats['errors']++;
            $report .= "❌ 错误：**{$filename}** - 保存失败\n";
            echo "❌ 错误：{$filename} (保存失败)\n";
        }
    }
}
?>
