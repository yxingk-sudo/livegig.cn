<?php
// 详细诊断表格渲染逻辑
require_once '../config/database.php';
require_once '../includes/functions.php';

session_start();
requireLogin();
checkProjectPermission($_SESSION['project_id']);

$database = new Database();
$db = $database->getConnection();
$projectId = $_SESSION['project_id'];

echo "<h2>详细诊断 - 表格渲染逻辑</h2>";
echo "<p><strong>项目 ID:</strong> {$projectId}</p>";
echo "<hr>";

// 获取选中的日期
$selectedMealDates = [];
$date_config_query = "SELECT selected_meal_dates FROM projects WHERE id = :project_id";
$date_config_stmt = $db->prepare($date_config_query);
$date_config_stmt->bindParam(':project_id', $projectId);
$date_config_stmt->execute();
$date_config_row = $date_config_stmt->fetch(PDO::FETCH_ASSOC);

if ($date_config_row && !empty($date_config_row['selected_meal_dates'])) {
    $decodedDates = json_decode($date_config_row['selected_meal_dates'], true);
    if (is_array($decodedDates)) {
        $selectedMealDates = $decodedDates;
    }
}

// 获取酒店记录日期
$allDates = [];
$query = "SELECT DISTINCT check_in_date as date FROM hotel_reports WHERE project_id = :project_id
          UNION SELECT DISTINCT check_out_date as date FROM hotel_reports WHERE project_id = :project_id
          ORDER BY date";
$stmt = $db->prepare($query);
$stmt->bindParam(':project_id', $projectId);
$stmt->execute();
$dateRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$allDates = array_column($dateRows, 'date');

sort($allDates);

// 过滤日期
if (!empty($selectedMealDates)) {
    $filteredDates = [];
    foreach ($allDates as $date) {
        if (in_array($date, $selectedMealDates)) {
            $filteredDates[$date] = true;
        }
    }
    $allDates = array_keys($filteredDates);
}

echo "<h3>1. 最终显示的日期列表</h3>";
echo "<ul>";
foreach ($allDates as $index => $date) {
    $dateObj = new DateTime($date);
    $weekday = $dateObj->format('l');
    $weekdayMap = [
        'Monday' => '一', 'Tuesday' => '二', 'Wednesday' => '三',
        'Thursday' => '四', 'Friday' => '五', 'Saturday' => '六', 'Sunday' => '日'
    ];
    $weekdayChar = $weekdayMap[$weekday] ?? '';
    echo "<li><strong>[索引 {$index}]</strong> {$date} (周{$weekdayChar})</li>";
}
echo "</ul>";

// 获取餐类型配置
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

// 构建要遍历的餐类型数组
$mealTypes = [];
if ($mealTypeConfig['breakfast_enabled']) $mealTypes[] = '早餐';
if ($mealTypeConfig['lunch_enabled']) $mealTypes[] = '午餐';
if ($mealTypeConfig['dinner_enabled']) $mealTypes[] = '晚餐';
if ($mealTypeConfig['supper_enabled']) $mealTypes[] = '宵夜';

echo "<h3>2. 要遍历的餐类型</h3>";
echo "<pre>" . print_r($mealTypes, true) . "</pre>";

// 获取套餐数据
$packagesQuery = "SELECT * FROM meal_packages 
                   WHERE project_id = :project_id 
                   AND is_active = 1 
                   ORDER BY meal_type, id";
$packagesStmt = $db->prepare($packagesQuery);
$packagesStmt->bindParam(':project_id', $projectId);
$packagesStmt->execute();
$allPackages = $packagesStmt->fetchAll(PDO::FETCH_ASSOC);

// 按餐类型分组
$packagesByType = [];
foreach ($allPackages as $pkg) {
    $type = $pkg['meal_type'];
    if (!isset($packagesByType[$type])) {
        $packagesByType[$type] = [];
    }
    $packagesByType[$type][] = $pkg;
}

echo "<h3>3. 每个餐类型的套餐数量</h3>";
echo "<ul>";
foreach ($packagesByType as $type => $packages) {
    echo "<li><strong>{$type}:</strong> " . count($packages) . " 个套餐</li>";
}
echo "</ul>";

// 模拟表格渲染逻辑
echo "<h3>4. 模拟表格渲染（以 2024-07-26 周五为例）</h3>";

$targetDate = '2024-07-26';
$targetDateIndex = array_search($targetDate, $allDates);

if ($targetDateIndex === false) {
    echo "<p style='color:red; font-weight:bold;'>⚠️ 2024-07-26 不在日期列表中！</p>";
} else {
    echo "<p>✅ 2024-07-26 在日期列表中，索引为：<strong>{$targetDateIndex}</strong></p>";
    
    // 检查每个餐类型
    foreach ($mealTypes as $mealType) {
        echo "<hr>";
        echo "<h4>餐类型：<strong>{$mealType}</strong></h4>";
        
        // 检查是否有该餐类型的套餐
        $hasPackages = isset($packagesByType[$mealType]) && !empty($packagesByType[$mealType]);
        
        if (!$hasPackages) {
            echo "<p style='color:orange;'>⚠️ 没有{$mealType}的套餐</p>";
            continue;
        }
        
        echo "<p>✅ 有 " . count($packagesByType[$mealType]) . " 个{$mealType}套餐</p>";
        echo "<ul>";
        foreach ($packagesByType[$mealType] as $pkg) {
            echo "<li>{$pkg['name']} (ID: {$pkg['id']})</li>";
        }
        echo "</ul>";
        
        // 关键测试：检查代码逻辑
        echo "<p><strong>代码逻辑测试：</strong></p>";
        echo "<pre>";
        echo "检查条件：isset(\$packagesByType['{$mealType}'])\n";
        $testResult = isset($packagesByType[$mealType]);
        echo "结果：" . ($testResult ? 'TRUE ✓' : 'FALSE ✗') . "\n\n";
        
        if ($testResult) {
            echo "遍历 packagesByType['{$mealType}']:\n";
            foreach ($packagesByType[$mealType] as $pkg) {
                echo "  - {$pkg['name']} (ID: {$pkg['id']})\n";
            }
        } else {
            echo "⚠️ 条件不满足，不会显示套餐选项！\n";
        }
        echo "</pre>";
    }
}

// 检查已分配的数据
echo "<h3>5. 2024-07-26 的已分配套餐</h3>";
$key = $targetDate . '_午餐';
$assignedPackages = [];

if (!empty($allDates)) {
    $datePlaceholders = str_repeat('?,', count($allDates) - 1) . '?';
    $assignQuery = "SELECT meal_date, meal_type, package_id FROM meal_package_assignments
                    WHERE project_id = ? AND meal_date IN ($datePlaceholders)";
    $assignStmt = $db->prepare($assignQuery);
    $params = array_merge([$projectId], $allDates);
    $assignStmt->execute($params);
    $assignments = $assignStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($assignments as $assign) {
        $assignKey = $assign['meal_date'] . '_' . $assign['meal_type'];
        if (!isset($assignedPackages[$assignKey])) {
            $assignedPackages[$assignKey] = [];
        }
        $assignedPackages[$assignKey][] = $assign['package_id'];
    }
}

echo "<p>已分配记录总数：" . count($assignments) . "</p>";
echo "<pre>";
print_r($assignedPackages);
echo "</pre>";

echo "<hr>";
echo "<p><em>诊断完成时间：" . date('Y-m-d H:i:s') . "</em></p>";
?>
