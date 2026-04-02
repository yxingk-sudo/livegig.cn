<?php
/**
 * 权限配置完整性检查报告
 *
 * 功能说明：
 * - 检查系统中所有页面的权限配置完整性
 * - 对比数据库中的权限配置与实际页面
 * - 分析权限树结构
 * - 检查权限键值命名规范
 *
 * 使用方法：
 * php tests/tools/permission_audit_report.php
 *
 * 依赖项：
 * - config/database.php - 数据库配置
 * - permissions 表 - 权限配置表
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

// 引入数据库配置
$dbConfigPath = IS_MOVED ? PROJECT_ROOT . '/config/database.php' : __DIR__ . '/config/database.php';
if (!file_exists($dbConfigPath)) {
    die("❌ 错误：找不到数据库配置文件 {$dbConfigPath}\n");
}
require_once $dbConfigPath;

// 初始化数据库连接
try {
    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        throw new Exception("数据库连接失败");
    }
} catch (Exception $e) {
    die("❌ 数据库连接错误：" . $e->getMessage() . "\n");
}

/**
 * 扫描指定目录下的所有 PHP 文件
 *
 * @param string $dir 目录名称（相对于项目根目录）
 * @return array 文件路径数组
 */
function scanPages($dir) {
    global $PROJECT_ROOT;
    $files = [];
    $fullPath = $PROJECT_ROOT . '/' . $dir;

    if (!is_dir($fullPath)) {
        return $files;
    }

    // 排除的目录列表
    $excluded = ['includes', 'api', 'ajax', 'backup', 'backups'];

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->isFile() &&
                pathinfo($file, PATHINFO_EXTENSION) === 'php' &&
                !in_array(basename($file->getPath()), $excluded)) {
                $files[] = $file->getPathname();
            }
        }
    } catch (Exception $e) {
        echo "⚠️  扫描目录时出错：{$e->getMessage()}\n";
    }

    return $files;
}

/**
 * 检查权限键值格式是否符合规范
 *
 * @param string $key 权限键值
 * @return bool 是否符合规范
 */
function isValidPermissionKey($key) {
    // 权限键值规范：类型:模块:操作 或 类型:模块:子模块:操作
    return preg_match('/^(frontend|backend):[a-z_]+(:[a-z_]+)*$/', $key);
}

// ============================================================================
// 主程序开始
// ============================================================================

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                    权限配置完整性检查报告                                      ║\n";
echo "╠══════════════════════════════════════════════════════════════════════════════╣\n";
echo "║ 生成时间：" . date('Y-m-d H:i:s') . "                                              ║\n";
echo "║ 运行环境：PHP " . PHP_VERSION . "                                                       ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n\n";

// 1. 获取所有权限配置
echo "📊 正在加载权限配置...\n";
try {
    $query = "SELECT id, permission_key, permission_name, permission_type, parent_id, resource_type
              FROM permissions
              WHERE status = 1
              ORDER BY parent_id, sort_order";
    $stmt = $db->query($query);
    $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "✅ 成功加载 " . count($permissions) . " 条权限配置\n\n";
} catch (PDOException $e) {
    die("❌ 查询权限配置失败：" . $e->getMessage() . "\n");
}

// 2. 扫描所有页面文件
echo "🔍 正在扫描系统页面...\n";
$adminPages = scanPages('admin');
$userPages = scanPages('user');
echo "✅ 扫描完成\n\n";

echo "📊 系统页面统计\n";
echo str_repeat("═", 80) . "\n";
echo "├─ 管理后台页面数：" . count($adminPages) . " 个\n";
echo "├─ 前台用户页面数：" . count($userPages) . " 个\n";
echo "└─ 总页面数：" . (count($adminPages) + count($userPages)) . " 个\n\n";

// 3. 检查每个页面的权限配置
echo "📋 权限配置详情\n";
echo str_repeat("═", 80) . "\n\n";

$missingPermissions = [];
$configuredPermissions = [];
$invalidPermissionKeys = [];
$missingInDb = [];

// 3.1 检查 admin 目录页面
echo "【管理后台 (admin) 页面检查】\n";
echo str_repeat("-", 80) . "\n";

foreach ($adminPages as $page) {
    $content = @file_get_contents($page);
    if ($content === false) {
        echo "⚠️  无法读取文件：{$page}\n";
        continue;
    }

    $relativePath = str_replace(PROJECT_ROOT . '/', '', $page);

    // 查找权限验证
    if (preg_match('/checkAdminPagePermission\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
        $permissionKey = $matches[1];
        $configuredPermissions[$relativePath] = $permissionKey;

        // 检查该权限是否存在于数据库
        $exists = false;
        foreach ($permissions as $perm) {
            if ($perm['permission_key'] === $permissionKey) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            echo "❌ {$relativePath}\n";
            echo "   └─ 权限 '{$permissionKey}' 在数据库中不存在\n";
            $missingInDb[] = ['page' => $relativePath, 'key' => $permissionKey];
        }
    } else {
        // 检查是否有 session_start 但未进行权限验证
        if (strpos($content, 'session_start()') !== false &&
            basename($page) !== 'login.php' &&
            basename($page) !== 'logout.php' &&
            basename($page) !== 'index.php') {
            $missingPermissions['admin'][] = $relativePath;
        }
    }
}

// 3.2 检查 user 目录页面
echo "\n【前台用户 (user) 页面检查】\n";
echo str_repeat("-", 80) . "\n";

foreach ($userPages as $page) {
    $content = @file_get_contents($page);
    if ($content === false) {
        echo "⚠️  无法读取文件：{$page}\n";
        continue;
    }

    $relativePath = str_replace(PROJECT_ROOT . '/', '', $page);

    // 查找权限验证
    if (preg_match('/check(?:User|Project)PagePermission\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
        $permissionKey = $matches[1];
        $configuredPermissions[$relativePath] = $permissionKey;

        // 检查该权限是否存在于数据库
        $exists = false;
        foreach ($permissions as $perm) {
            if ($perm['permission_key'] === $permissionKey) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            echo "❌ {$relativePath}\n";
            echo "   └─ 权限 '{$permissionKey}' 在数据库中不存在\n";
            $missingInDb[] = ['page' => $relativePath, 'key' => $permissionKey];
        }
    } else {
        // 检查是否有 session_start 但未进行权限验证
        if (strpos($content, 'session_start()') !== false &&
            basename($page) !== 'login.php' &&
            basename($page) !== 'logout.php' &&
            basename($page) !== 'project_login.php') {
            $missingPermissions['user'][] = $relativePath;
        }
    }
}

// 3.3 显示统计信息
echo "\n";
echo "📈 检查结果统计\n";
echo str_repeat("═", 80) . "\n";
echo "├─ 已配置权限的页面：" . count($configuredPermissions) . " 个\n";
echo "├─ 数据库中缺少的权限配置：" . count($missingInDb) . " 个\n";
echo "├─ 缺少权限配置的 admin 页面：" . (isset($missingPermissions['admin']) ? count($missingPermissions['admin']) : 0) . " 个\n";
echo "└─ 缺少权限配置的 user 页面：" . (isset($missingPermissions['user']) ? count($missingPermissions['user']) : 0) . " 个\n";

// 4. 显示缺少权限配置的页面
if (!empty($missingPermissions)) {
    echo "\n";
    echo "⚠️  缺少权限配置的页面\n";
    echo str_repeat("═", 80) . "\n";

    if (isset($missingPermissions['admin']) && !empty($missingPermissions['admin'])) {
        echo "\n【管理后台 (admin)】\n";
        foreach ($missingPermissions['admin'] as $page) {
            echo "  • {$page}\n";
        }
    }

    if (isset($missingPermissions['user']) && !empty($missingPermissions['user'])) {
        echo "\n【前台用户 (user)】\n";
        foreach ($missingPermissions['user'] as $page) {
            echo "  • {$page}\n";
        }
    }
}

// 5. 分析权限树结构
echo "\n\n";
echo "🌳 权限树结构分析\n";
echo str_repeat("═", 80) . "\n";

$parentPermissions = array_filter($permissions, function($p) {
    return $p['parent_id'] == 0 || $p['parent_id'] === null;
});

$childPermissions = array_filter($permissions, function($p) {
    return $p['parent_id'] > 0;
});

echo "├─ 一级权限（父级）：" . count($parentPermissions) . " 个\n";
echo "└─ 二级权限（子级）：" . count($childPermissions) . " 个\n\n";

$index = 1;
foreach ($parentPermissions as $parent) {
    $children = array_filter($childPermissions, function($child) use ($parent) {
        return $child['parent_id'] == $parent['id'];
    });

    $prefix = ($index == count($parentPermissions)) ? "└─" : "├─";
    echo "{$prefix} 【{$parent['permission_name']}】({$parent['permission_key']})\n";

    if (empty($children)) {
        echo "   └─ ⚠️  警告：此一级权限下没有子权限\n";
    } else {
        $childIndex = 1;
        foreach ($children as $child) {
            $childPrefix = ($childIndex == count($children)) ? "   └─" : "   ├─";
            echo "{$childPrefix} {$child['permission_name']} ({$child['permission_key']})\n";
            $childIndex++;
        }
    }
    $index++;
}

// 6. 检查权限键值规范
echo "\n\n";
echo "🔍 权限键值规范性检查\n";
echo str_repeat("═", 80) . "\n";

$invalidKeys = [];
foreach ($permissions as $perm) {
    if (!isValidPermissionKey($perm['permission_key'])) {
        $invalidKeys[] = $perm;
    }
}

if (empty($invalidKeys)) {
    echo "✅ 所有权限键值格式规范\n";
    echo "   规范格式：类型:模块:操作 (如：backend:user:view)\n";
} else {
    echo "❌ 以下 " . count($invalidKeys) . " 个权限键值格式不规范：\n";
    foreach ($invalidKeys as $perm) {
        echo "  • {$perm['permission_name']} ({$perm['permission_key']})\n";
    }
    echo "\n   规范格式：类型:模块:操作 (如：backend:user:view)\n";
}

// 7. 建议和改进措施
echo "\n\n";
echo "💡 建议和改进措施\n";
echo str_repeat("═", 80) . "\n";

$totalIssues = count($missingPermissions['admin'] ?? []) + count($missingPermissions['user'] ?? []) + count($missingInDb);

if ($totalIssues > 0) {
    echo "📌 发现 {$totalIssues} 个需要处理的问题：\n\n";

    if (!empty($missingPermissions['admin'])) {
        echo "1️⃣  为以下 " . count($missingPermissions['admin']) . " 个管理后台页面添加权限验证：\n";
        foreach (array_slice($missingPermissions['admin'], 0, 5) as $page) {
            echo "   • {$page}\n";
        }
        if (count($missingPermissions['admin']) > 5) {
            echo "   ... 还有 " . (count($missingPermissions['admin']) - 5) . " 个页面\n";
        }
        echo "\n";
    }

    if (!empty($missingPermissions['user'])) {
        echo "2️⃣  为以下 " . count($missingPermissions['user']) . " 个前台页面添加权限验证：\n";
        foreach (array_slice($missingPermissions['user'], 0, 5) as $page) {
            echo "   • {$page}\n";
        }
        if (count($missingPermissions['user']) > 5) {
            echo "   ... 还有 " . (count($missingPermissions['user']) - 5) . " 个页面\n";
        }
        echo "\n";
    }

    if (!empty($missingInDb)) {
        echo "3️⃣  在数据库中添加以下缺失的权限配置：\n";
        foreach (array_slice($missingInDb, 0, 5) as $item) {
            echo "   • {$item['page']} → {$item['key']}\n";
        }
        if (count($missingInDb) > 5) {
            echo "   ... 还有 " . (count($missingInDb) - 5) . " 个权限\n";
        }
        echo "\n";
    }
}

echo "4️⃣  添加权限验证代码的方法：\n";
echo "   在页面文件开头（session_start() 之后）添加：\n";
echo "   ┌────────────────────────────────────────\n";
echo "   │ \$middleware = new PermissionMiddleware();\n";
echo "   │ \$middleware->checkAdminPagePermission('your:permission:key');\n";
echo "   └────────────────────────────────────────\n\n";

echo "5️⃣  新增权限时的注意事项：\n";
echo "   • 在 permissions 表中添加对应的记录\n";
echo "   • permission_key 遵循命名规范（类型:模块:操作）\n";
echo "   • 正确设置 parent_id 以维护权限树结构\n";
echo "   • 确保在角色权限管理页面中可以配置这些权限\n\n";

echo "═══════════════════════════════════════════════════════════════════════════════\n";
echo "✅ 权限配置完整性检查报告生成完成\n";
echo "═══════════════════════════════════════════════════════════════════════════════\n\n";
