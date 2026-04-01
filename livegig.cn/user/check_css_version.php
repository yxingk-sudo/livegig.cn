<?php
/**
 * CSS 版本检查工具
 * 用于验证 CSS 修改是否生效
 */

$filePath = __DIR__ . '/../user/meal_package_assign.php';
$content = file_get_contents($filePath);

// 检查是否包含 nth-of-type
$hasNthOfType = strpos($content, 'nth-of-type') !== false;
$hasNthChild = preg_match('/\.date-group-header:nth-child\(/', $content);

echo "<h2>CSS 选择器状态检查</h2>";
echo "<ul>";
echo "<li>文件：" . $filePath . "</li>";
echo "<li>包含 nth-of-type: " . ($hasNthOfType ? '✅ 是' : '❌ 否') . "</li>";
echo "<li>仍包含 nth-child: " . ($hasNthChild ? '❌ 是（需要删除）' : '✅ 否') . "</li>";
echo "</ul>";

// 提取相关 CSS
preg_match_all('/\.date-group-header:nth-of-type\([^)]+\)\s*\{[^}]+\}/', $content, $matches);
if (!empty($matches[0])) {
    echo "<h3>找到的 CSS 规则：</h3>";
    echo "<pre style='background:#f5f5f5;padding:10px;'>";
    foreach ($matches[0] as $match) {
        echo htmlspecialchars($match) . "\n\n";
    }
    echo "</pre>";
} else {
    echo "<p style='color:red;'>❌ 未找到 .date-group-header:nth-of-type() 规则</p>";
}

// 检查 tbody 的 CSS
preg_match_all('/tbody tr td:nth-of-type\([^)]+\)\s*\{[^}]+\}/', $content, $tbodyMatches);
if (!empty($tbodyMatches[0])) {
    echo "<h3>Tbody CSS 规则：</h3>";
    echo "<pre style='background:#f5f5f5;padding:10px;'>";
    foreach ($tbodyMatches[0] as $match) {
        echo htmlspecialchars($match) . "\n\n";
    }
    echo "</pre>";
} else {
    echo "<p style='color:red;'>❌ 未找到 tbody tr td:nth-of-type() 规则</p>";
}

echo "<hr>";
echo "<p><a href='../user/meal_package_assign.php'>← 返回套餐分配页面</a></p>";
?>

<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    h1, h2, h3 { color: #333; }
    pre { border-left: 4px solid #f5576c; }
    a { color: #f5576c; }
</style>
