<?php
// 详细诊断套餐分组问题
require_once '../config/database.php';
require_once '../includes/functions.php';

session_start();
requireLogin();
checkProjectPermission($_SESSION['project_id']);

$database = new Database();
$db = $database->getConnection();
$projectId = $_SESSION['project_id'];

echo "<h2>详细诊断 - 套餐分组数据</h2>";
echo "<p><strong>项目 ID:</strong> {$projectId}</p>";
echo "<hr>";

// 获取所有套餐
$packagesQuery = "SELECT * FROM meal_packages 
                   WHERE project_id = :project_id 
                   AND is_active = 1 
                   ORDER BY meal_type, id";
$packagesStmt = $db->prepare($packagesQuery);
$packagesStmt->bindParam(':project_id', $projectId);
$packagesStmt->execute();
$allPackages = $packagesStmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>1. 原始套餐数据（数据库）</h3>";
echo "<pre>";
print_r($allPackages);
echo "</pre>";

// 按餐类型分组
$packagesByType = [];
foreach ($allPackages as $pkg) {
    $type = $pkg['meal_type'];
    if (!isset($packagesByType[$type])) {
        $packagesByType[$type] = [];
    }
    $packagesByType[$type][] = $pkg;
}

echo "<h3>2. 分组后的套餐数据（\$packagesByType）</h3>";
echo "<pre>";
print_r($packagesByType);
echo "</pre>";

// 检查餐类型配置
$mealTypeConfig = [
    'breakfast_enabled' => true,
    'lunch_enabled' => true,
    'dinner_enabled' => true,
    'supper_enabled' => true
];

$config_query = "SELECT breakfast_enabled, lunch_enabled, dinner_enabled, supper_enabled FROM projects WHERE id = :project_id";
$config_stmt = $db->prepare($config_query);
$config_stmt->bindParam(':project_id', $projectId);
$config_stmt->execute();
$config_row = $config_stmt->fetch(PDO::FETCH_ASSOC);

if ($config_row) {
    $mealTypeConfig = [
        'breakfast_enabled' => (bool)$config_row['breakfast_enabled'],
        'lunch_enabled' => (bool)$config_row['lunch_enabled'],
        'dinner_enabled' => (bool)$config_row['dinner_enabled'],
        'supper_enabled' => (bool)$config_row['supper_enabled']
    ];
}

echo "<h3>3. 餐类型配置（\$mealTypeConfig）</h3>";
echo "<pre>";
print_r($mealTypeConfig);
echo "</pre>";

// 构建要遍历的餐类型数组
$mealTypes = [];
if ($mealTypeConfig['breakfast_enabled']) $mealTypes[] = '早餐';
if ($mealTypeConfig['lunch_enabled']) $mealTypes[] = '午餐';
if ($mealTypeConfig['dinner_enabled']) $mealTypes[] = '晚餐';
if ($mealTypeConfig['supper_enabled']) $mealTypes[] = '宵夜';

echo "<h3>4. 要遍历的餐类型数组（\$mealTypes）</h3>";
echo "<pre>";
print_r($mealTypes);
echo "</pre>";

// 检查每个餐类型是否有对应的套餐
echo "<h3>5. 匹配检查结果</h3>";
echo "<ul>";
foreach ($mealTypes as $mealType) {
    $hasPackages = isset($packagesByType[$mealType]) && !empty($packagesByType[$mealType]);
    $count = $hasPackages ? count($packagesByType[$mealType]) : 0;
    
    echo "<li>";
    echo "<strong>{$mealType}</strong>: ";
    if ($hasPackages) {
        echo "<span style='color:green;'>✓ 有 {$count} 个套餐</span>";
        echo "<ul>";
        foreach ($packagesByType[$mealType] as $pkg) {
            echo "<li>{$pkg['name']} (ID: {$pkg['id']})</li>";
        }
        echo "</ul>";
    } else {
        echo "<span style='color:red;'>✗ 没有套餐</span>";
    }
    echo "</li>";
}
echo "</ul>";

// 关键字符串对比
echo "<h3>6. 字符串对比分析</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>来源</th><th>餐类型字符串</th><th>长度</th><th>字节数</th></tr>";

// 从数据库读取的餐类型
if (!empty($allPackages)) {
    foreach ($allPackages as $pkg) {
        $type = $pkg['meal_type'];
        $length = strlen($type);
        $mbLength = mb_strlen($type, 'UTF-8');
        $bytes = unpack('H*', $type)[1];
        echo "<tr>";
        echo "<td>数据库 ({$pkg['name']})</td>";
        echo "<td>{$type}</td>";
        echo "<td>{$length}</td>";
        echo "<td>{$bytes} (HEX)</td>";
        echo "</tr>";
    }
}

// 代码中使用的餐类型
foreach ($mealTypes as $mealType) {
    $length = strlen($mealType);
    $mbLength = mb_strlen($mealType, 'UTF-8');
    $bytes = unpack('H*', $mealType)[1];
    echo "<tr>";
    echo "<td>代码遍历</td>";
    echo "<td>{$mealType}</td>";
    echo "<td>{$length}</td>";
    echo "<td>{$bytes} (HEX)</td>";
    echo "</tr>";
}

echo "</table>";

echo "<hr>";
echo "<p><em>诊断完成时间：" . date('Y-m-d H:i:s') . "</em></p>";
?>
