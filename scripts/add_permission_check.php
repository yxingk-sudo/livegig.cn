#!/usr/bin/env php
<?php
/**
 * 批量为现有页面添加权限验证代码
 * 
 * 使用方法:
 * php add_permission_check.php [--dry-run]
 * 
 * --dry-run: 仅预览不实际修改
 */

// 是否仅为预览模式
$dryRun = in_array('--dry-run', $argv);

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║          批量添加权限验证代码工具                              ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

if ($dryRun) {
    echo "📋 当前为【预览模式】- 不会实际修改任何文件\n\n";
} else {
    echo "⚠️  警告：此操作将修改多个文件，建议先备份！\n";
    echo "按 Enter 键继续，或 Ctrl+C 取消...\n";
    fgets(STDIN);
    echo "\n";
}

// 定义模块映射关系（文件名前缀 => permissionKey）
$moduleMapping = [
    'personnel' => 'backend:personnel:list',
    'project' => 'backend:project:list',
    'meal' => 'backend:meal:list',
    'hotel' => 'backend:hotel:list',
    'transport' => 'backend:transport:list',
    'fleet' => 'backend:transport:fleet',
    'backup' => 'backend:backup:view',
    'company' => 'backend:company:list',
    'department' => 'backend:project:department',
    'admin_user' => 'backend:system:user',
    'role' => 'backend:system:role',
    'permission' => 'backend:system:permission',
    'site_config' => 'backend:system:config',
];

// 需要处理的文件列表
$filesToProcess = glob(__DIR__ . '/../admin/*.php');
$excludedFiles = ['login.php', 'logout.php', 'index.php', 'page_template.php'];

$modifiedCount = 0;
$skippedCount = 0;
$errorCount = 0;

foreach ($filesToProcess as $file) {
    $fileName = basename($file);
    
    // 跳过排除的文件
    if (in_array($fileName, $excludedFiles)) {
        echo "⏭️  跳过：$fileName\n";
        $skippedCount++;
        continue;
    }
    
    $content = file_get_contents($file);
    
    // 检查是否已有权限验证
    if (strpos($content, 'checkAdminPagePermission') !== false ||
        strpos($content, 'BaseAdminController') !== false) {
        echo "✅ 已存在权限验证：$fileName\n";
        $skippedCount++;
        continue;
    }
    
    // 确定 permissionKey
    $permissionKey = determinePermissionKey($fileName, $moduleMapping);
    
    // 生成新的代码
    $newContent = generateNewContent($content, $permissionKey, $fileName);
    
    if ($dryRun) {
        echo "📝 将修改：$fileName -> 权限：$permissionKey\n";
        $modifiedCount++;
    } else {
        // 写入文件
        if (file_put_contents($file, $newContent)) {
            echo "✅ 已修改：$fileName -> 权限：$permissionKey\n";
            $modifiedCount++;
        } else {
            echo "❌ 修改失败：$fileName\n";
            $errorCount++;
        }
    }
}

echo "\n";
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║                        处理完成                                ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "统计结果:\n";
echo "  ✅ 成功修改：$modifiedCount 个文件\n";
echo "  ⏭️  已跳过：$skippedCount 个文件\n";
echo "  ❌ 发生错误：$errorCount 个文件\n";
echo "\n";

if ($dryRun && $modifiedCount > 0) {
    echo "💡 提示：预览完成。移除 --dry-run 参数后再次运行以实际修改文件。\n";
    echo "   命令：php add_permission_check.php\n";
}

/**
 * 根据文件名确定 permissionKey
 */
function determinePermissionKey($fileName, $mapping) {
    $baseName = strtolower(str_replace('.php', '', $fileName));
    
    // 尝试匹配模块
    foreach ($mapping as $prefix => $permissionKey) {
        if (strpos($baseName, $prefix) === 0) {
            return $permissionKey;
        }
    }
    
    // 默认权限
    return 'backend:system:config';
}

/**
 * 生成包含权限验证的新代码
 */
function generateNewContent($content, $permissionKey, $fileName) {
    // 查找 session_start() 的位置
    $sessionPattern = '/session_start\s*\(\s*\)\s*;?/';
    
    if (!preg_match($sessionPattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
        // 如果没有找到 session_start，在文件开头添加
        $insertCode = "<?php\nrequire_once '../includes/BaseAdminController.php';\n\n";
        $content = $insertCode . $content;
    } else {
        // 在 session_start 后添加基础控制器引入
        $matchOffset = $matches[0][1];
        $matchLength = strlen($matches[0][0]);
        
        $beforeSession = substr($content, 0, $matchOffset + $matchLength);
        $afterSession = substr($content, $matchOffset + $matchLength);
        
        $content = $beforeSession . "\n\n// 引入基础控制器进行权限验证\nrequire_once '../includes/BaseAdminController.php';" . $afterSession;
    }
    
    // 如果文件使用了 class，则转换为继承 BaseAdminController
    if (preg_match('/class\s+(\w+)\s+extends/', $content)) {
        // 已经是类结构，无需特殊处理
    } else {
        // 简单的过程式页面，在 header 引入前添加权限检查
        $headerPattern = "/(require_once\s+['\"]includes\/header\.php['\"]\s*;?)/";
        $replacement = "// 权限验证\n\$database = new Database();\n\$db = \$database->getConnection();\n\$middleware = new PermissionMiddleware(\$db);\n\$middleware->checkAdminPagePermission('$permissionKey');\n\n\$1";
        $content = preg_replace($headerPattern, $replacement, $content);
    }
    
    return $content;
}
