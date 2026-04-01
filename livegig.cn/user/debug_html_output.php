<?php
// 检查实际页面的 HTML 输出
require_once '../config/database.php';
require_once '../includes/functions.php';

session_start();
requireLogin();
checkProjectPermission($_SESSION['project_id']);

$database = new Database();
$db = $database->getConnection();
$projectId = $_SESSION['project_id'];

echo "<h2>检查实际页面 HTML 输出</h2>";
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

// 获取已分配的套餐
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
        $key = $assign['meal_date'] . '_' . $assign['meal_type'];
        if (!isset($assignedPackages[$key])) {
            $assignedPackages[$key] = [];
        }
        $assignedPackages[$key][] = $assign['package_id'];
    }
}

echo "<h3>模拟实际页面的表格行输出（以 2024-07-26 周五为例）</h3>";

// 模拟页面代码的输出逻辑
$serialNumber = 1;
foreach ($mealTypes as $mealType):
    echo "<hr>";
    echo "<h4>第 {$serialNumber} 行 - 餐类型：<strong>{$mealType}</strong></h4>";
    $serialNumber++;
    
    // 这是页面代码的核心逻辑
    echo "<p><strong>步骤 1: 检查 packagesByType 数组</strong></p>";
    $hasPackages = isset($packagesByType[$mealType]);
    echo "<pre>";
    echo "isset(\$packagesByType['{$mealType}']): " . ($hasPackages ? 'TRUE ✓' : 'FALSE ✗') . "\n";
    if ($hasPackages) {
        echo "packagesByType['{$mealType}'] 的内容:\n";
        print_r($packagesByType[$mealType]);
    } else {
        echo "⚠️ 该餐类型没有套餐！\n";
    }
    echo "</pre>";
    
    // 检查第一个日期单元格
    $dateIndex = 0;
    $date = $allDates[$dateIndex];
    echo "<p><strong>步骤 2: 检查第一个日期单元格 ({$date})</strong></p>";
    
    $key = $date . '_' . $mealType;
    $assignedPackageIds = $assignedPackages[$key] ?? [];
    
    echo "<pre>";
    echo "键值：{$key}\n";
    echo "已分配套餐 ID: " . json_encode($assignedPackageIds) . "\n";
    echo "</pre>";
    
    echo "<p><strong>步骤 3: 生成的 HTML 代码</strong></p>";
    echo "<div style='background:#f8f9fa; padding:10px; border:1px solid #dee2e6;'>";
    echo "<strong>单元格内容：</strong><br>";
    
    if (isset($packagesByType[$mealType])):
        echo "<div class='package-options' style='display:flex; flex-direction:column; gap:5px;'>";
        foreach ($packagesByType[$mealType] as $pkg):
            $isSelected = in_array($pkg['id'], $assignedPackageIds);
            $checkedClass = $isSelected ? 'checked' : '';
            $checkedAttr = $isSelected ? 'checked' : '';
            
            echo "<div class='package-checkbox {$checkedClass}' style='padding:5px; background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px;'>";
            echo "<input type='checkbox' ";
            echo "id='pkg_{$pkg['id']}_{$date}_{$mealType}' ";
            echo "value='{$pkg['id']}' ";
            echo "data-meal-date='{$date}' ";
            echo "data-meal-type='{$mealType}' ";
            echo "data-package-name='" . htmlspecialchars($pkg['name']) . "' ";
            echo "{$checkedAttr}> ";
            echo "<label for='pkg_{$pkg['id']}_{$date}_{$mealType}'>";
            echo htmlspecialchars($pkg['name']);
            echo "</label>";
            echo "</div>";
        endforeach;
        echo "</div>";
    else:
        echo "<div class='text-muted small'>暂无可用套餐</div>";
    endif;
    
    echo "</div>";
    echo "<hr>";
endforeach;

echo "<h3>总结</h3>";
echo "<p>根据代码逻辑，每个餐类型的每一列都应该显示对应的套餐复选框。</p>";
echo "<p>如果页面上没有显示，可能的原因：</p>";
echo "<ol>";
echo "<li><strong>CSS样式问题：</strong>套餐选项可能被隐藏或覆盖了</li>";
echo "<li><strong>JavaScript 错误：</strong>可能有 JS 错误导致 DOM 操作失败</li>";
echo "<li><strong>浏览器缓存：</strong>浏览器可能缓存了旧版本的页面</li>";
echo "<li><strong>PHP 短标签问题：</strong>检查服务器是否支持 PHP 短标签</li>";
echo "</ol>";

echo "<hr>";
echo "<p><em>诊断完成时间：" . date('Y-m-d H:i:s') . "</em></p>";
?>
