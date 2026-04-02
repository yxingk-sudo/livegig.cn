<?php
/**
 * 系统页面扫描与权限对比工具
 *
 * 功能说明：
 * - 扫描 admin 和 user 目录下的所有 PHP 页面
 * - 检测页面是否配置了权限验证
 * - 识别缺少权限验证的页面
 * - 生成权限配置对比报告
 *
 * 使用方法：
 * php tests/tools/scan_pages.php
 *
 * 依赖项：
 * - 无需数据库连接（纯文件扫描）
 *
 * 作者：Qoder
 * 日期：2026-04-02
 */

// 错误报告设置
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 定义项目根目录（兼容移动后的路径）
define('PROJECT_ROOT', dirname(__DIR__));
define('IS_MOVED', strpos(__DIR__, '/tests/tools') !== false);

// ============================================================================
// 配置区域
// ============================================================================

// 定义要扫描的目录
$directories = [
    'admin' => '管理后台',
    'user' => '前台用户',
];

// 排除的目录列表
$excludedDirs = ['includes', 'api', 'ajax', 'vendor', 'assets', 'backup', 'backups', 'logs', 'sql', 'config', '.'];

// 排除的文件（登录页面等）
$excludedFiles = ['login.php', 'logout.php', 'index.php', 'project_login.php'];

// ============================================================================
// 辅助函数
// ============================================================================

/**
 * 递归扫描目录下的所有 PHP 文件
 *
 * @param string $dir 目录路径
 * @param array $excluded 排除的目录列表
 * @return array 文件路径数组
 */
function scanDirectory($dir, $excluded = []) {
    $files = [];

    if (!is_dir($dir)) {
        return $files;
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }

            // 检查是否在排除目录中
            $pathParts = explode(DIRECTORY_SEPARATOR, $file->getPath());
            foreach ($pathParts as $part) {
                if (in_array($part, $excluded)) {
                    continue 2;
                }
            }

            // 检查文件路径是否包含排除的目录
            $filePath = $file->getPathname();
            $excludedPatterns = ['/includes/', '/api/', '/ajax/', '/vendor/', '/assets/', '/backup/'];
            foreach ($excludedPatterns as $pattern) {
                if (strpos($filePath, $pattern) !== false) {
                    continue 2;
                }
            }

            $files[] = $filePath;
        }
    } catch (Exception $e) {
        echo "⚠️  扫描目录时出错：{$e->getMessage()}\n";
    }

    return $files;
}

/**
 * 从页面内容中提取权限键
 *
 * @param string $content 文件内容
 * @return string|null 权限键或 null
 */
function extractPermissionKey($content) {
    // 支持多种权限验证方式
    $patterns = [
        '/checkAdminPagePermission\s*\(\s*[\'"]([^\'"]+)[\'"]/',           // 后台页面权限
        '/checkUserPagePermission\s*\(\s*[\'"]([^\'"]+)[\'"]/',             // 前台页面权限
        '/checkProjectPagePermission\s*\(\s*[\'"]([^\'"]+)[\'"]/',          // 项目页面权限
        '/requirePermission\s*\(\s*[\'"]([^\'"]+)[\'"]/',                   // 通用权限
        '/PermissionMiddleware.*?->.*?Permission\s*\(\s*[\'"]([^\'"]+)[\'"]/', // 中间件权限
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $content, $matches)) {
            return $matches[1];
        }
    }

    return null;
}

/**
 * 检查页面是否有 session 验证
 *
 * @param string $content 文件内容
 * @return bool
 */
function hasSessionCheck($content) {
    return strpos($content, 'session_start()') !== false ||
           strpos($content, 'session_start') !== false ||
           strpos($content, '$_SESSION') !== false;
}

/**
 * 格式化文件路径为相对路径
 *
 * @param string $filePath 完整文件路径
 * @return string 相对路径
 */
function formatRelativePath($filePath) {
    return str_replace(PROJECT_ROOT . '/', '', $filePath);
}

// ============================================================================
// 主程序
// ============================================================================

echo "\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "                 系统页面扫描与权限对比工具                                    \n";
echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "扫描时间：" . date('Y-m-d H:i:s') . "\n";
echo "PHP 版本：" . PHP_VERSION . "\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n\n";

// 存储扫描结果
$allPages = [];
$summary = [
    'total' => 0,
    'with_permission' => 0,
    'without_permission' => 0,
    'login_pages' => 0,
];

// 统计信息
$stats = [
    'admin' => ['total' => 0, 'with_permission' => 0, 'without_permission' => 0],
    'user' => ['total' => 0, 'with_permission' => 0, 'without_permission' => 0],
];

// 扫描每个目录
foreach ($directories as $dirKey => $dirName) {
    $dirPath = PROJECT_ROOT . '/' . $dirKey;

    echo "┌─────────────────────────────────────────────────────────────────────────────┐\n";
    echo "│ 📂 正在扫描 {$dirName} 目录 ({$dirKey}/)                           │\n";
    echo "└─────────────────────────────────────────────────────────────────────────────┘\n";

    $files = scanDirectory($dirPath, $excludedDirs);
    $allPages[$dirKey] = $files;

    if (empty($files)) {
        echo "   ⚠️  未找到任何 PHP 文件\n\n";
        continue;
    }

    echo "   找到 " . count($files) . " 个 PHP 文件\n\n";

    // 显示文件列表和权限状态
    echo "   📋 文件权限配置详情：\n";
    echo "   " . str_repeat("─", 74) . "\n";

    foreach ($files as $file) {
        $relativePath = formatRelativePath($file);
        $fileName = basename($file);
        $content = @file_get_contents($file);

        $stats[$dirKey]['total']++;
        $summary['total']++;

        // 检查是否是登录页面
        if (in_array($fileName, $excludedFiles)) {
            echo "   ✓ {$relativePath}";
            echo str_repeat(" ", max(0, 60 - strlen($relativePath))) . " [登录页面]\n";
            $summary['login_pages']++;
            continue;
        }

        // 检查权限配置
        $permissionKey = extractPermissionKey($content);
        $hasSession = hasSessionCheck($content);

        if ($permissionKey) {
            echo "   ✅ {$relativePath}";
            echo str_repeat(" ", max(0, 50 - strlen($relativePath))) . "→ {$permissionKey}\n";
            $stats[$dirKey]['with_permission']++;
            $summary['with_permission']++;
        } elseif ($hasSession) {
            echo "   ⚠️  {$relativePath}";
            echo str_repeat(" ", max(0, 50 - strlen($relativePath))) . "→ 缺少权限验证\n";
            $stats[$dirKey]['without_permission']++;
            $summary['without_permission']++;
        } else {
            echo "   ℹ️  {$relativePath}";
            echo str_repeat(" ", max(0, 50 - strlen($relativePath))) . "→ 无 session 检查\n";
        }
    }

    echo "\n";
}

// 显示缺少权限配置的页面
echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "                         缺少权限配置的页面                                    \n";
echo "═══════════════════════════════════════════════════════════════════════════════\n\n";

$missingPages = [
    'admin' => [],
    'user' => [],
];

foreach ($directories as $dirKey => $dirName) {
    foreach ($allPages[$dirKey] as $file) {
        $fileName = basename($file);
        if (in_array($fileName, $excludedFiles)) {
            continue;
        }

        $content = @file_get_contents($file);
        $permissionKey = extractPermissionKey($content);
        $hasSession = hasSessionCheck($content);

        if (!$permissionKey && $hasSession) {
            $missingPages[$dirKey][] = formatRelativePath($file);
        }
    }
}

if (empty($missingPages['admin']) && empty($missingPages['user'])) {
    echo "🎉 所有页面都已配置权限验证！\n\n";
} else {
    if (!empty($missingPages['admin'])) {
        echo "⚠️  管理后台 ({$dirKey}/) 缺少权限验证的页面：\n";
        foreach ($missingPages['admin'] as $page) {
            echo "   • {$page}\n";
        }
        echo "\n";
    }

    if (!empty($missingPages['user'])) {
        echo "⚠️  前台用户 ({$dirKey}/) 缺少权限验证的页面：\n";
        foreach ($missingPages['user'] as $page) {
            echo "   • {$page}\n";
        }
        echo "\n";
    }
}

// 显示统计信息
echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "                              统计信息                                        \n";
echo "═══════════════════════════════════════════════════════════════════════════════\n\n";

echo "┌───────────────────────────────────────────────────────────────┐\n";
echo "│                         总览                                   │\n";
echo "├───────────────────────────────────────────────────────────────┤\n";
echo "│ 总页面数：{$summary['total']}                                               │\n";
echo "│ 已配置权限：" . str_pad($summary['with_permission'], 28) . "│\n";
echo "│ 缺少权限验证：" . str_pad($summary['without_permission'], 27) . "│\n";
echo "│ 登录页面：" . str_pad($summary['login_pages'], 31) . "│\n";
echo "└───────────────────────────────────────────────────────────────┘\n\n";

echo "┌──────────────────────┬────────────────┬──────────────────────────────┐\n";
echo "│ 目录                │ 总页面数       │ 已配置      │ 缺少验证      │\n";
echo "├──────────────────────┼────────────────┼──────────────────────────────┤\n";
echo "│ admin/ (管理后台)    │ " . str_pad($stats['admin']['total'], 12) . " │ ";
echo str_pad($stats['admin']['with_permission'], 10) . " │ ";
echo str_pad($stats['admin']['without_permission'], 10) . "         │\n";
echo "│ user/ (前台用户)    │ " . str_pad($stats['user']['total'], 12) . " │ ";
echo str_pad($stats['user']['with_permission'], 10) . " │ ";
echo str_pad($stats['user']['without_permission'], 10) . "         │\n";
echo "└──────────────────────┴────────────────┴──────────────────────────────┘\n\n";

// 显示建议
if ($summary['without_permission'] > 0) {
    echo "═══════════════════════════════════════════════════════════════════════════════\n";
    echo "                              建议                                                \n";
    echo "═══════════════════════════════════════════════════════════════════════════════\n\n";

    echo "💡 为缺少权限验证的页面添加权限验证：\n\n";
    echo "   1. 在页面文件开头（session_start() 之后）添加权限中间件：\n";
    echo "   ┌──────────────────────────────────────────────\n";
    echo "   │ require_once PROJECT_ROOT . '/includes/PermissionMiddleware.php';\n";
    echo "   │ \$middleware = new PermissionMiddleware();\n";
    echo "   │ \$middleware->checkAdminPagePermission('your:permission:key');\n";
    echo "   └──────────────────────────────────────────────\n\n";

    echo "   2. 权限键命名规范：\n";
    echo "      • 后台：backend:模块:操作 (如：backend:user:view)\n";
    echo "      • 前台：frontend:模块:操作 (如：frontend:meal:order)\n\n";

    echo "   3. 查看完整的权限配置报告：\n";
    echo "      php tests/tools/permission_audit_report.php\n\n";
}

echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "✅ 页面扫描完成\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n\n";
