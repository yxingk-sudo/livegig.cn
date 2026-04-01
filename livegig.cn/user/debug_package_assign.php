<?php
// 诊断套餐分配问题
require_once '../config/database.php';
require_once '../includes/functions.php';

session_start();
requireLogin();
checkProjectPermission($_SESSION['project_id']);

$database = new Database();
$db = $database->getConnection();
$projectId = $_SESSION['project_id'];

echo "<h2>诊断报告 - 套餐分配问题</h2>";
echo "<p><strong>项目 ID:</strong> {$projectId}</p>";
echo "<p><strong>项目名称:</strong> " . ($_SESSION['project_name'] ?? 'N/A') . "</p>";
echo "<hr>";

// 1. 检查餐类型配置
echo "<h3>1. 餐类型配置</h3>";
$config_query = "SELECT breakfast_enabled, lunch_enabled, dinner_enabled, supper_enabled FROM projects WHERE id = :project_id";
$config_stmt = $db->prepare($config_query);
$config_stmt->bindParam(':project_id', $projectId);
$config_stmt->execute();
$config_row = $config_stmt->fetch(PDO::FETCH_ASSOC);

if ($config_row) {
    echo "<ul>";
    echo "<li>早餐启用：" . ($config_row['breakfast_enabled'] ? '是' : '否') . "</li>";
    echo "<li>午餐启用：" . ($config_row['lunch_enabled'] ? '是' : '否') . "</li>";
    echo "<li>晚餐启用：" . ($config_row['dinner_enabled'] ? '是' : '否') . "</li>";
    echo "<li>宵夜启用：" . ($config_row['supper_enabled'] ? '是' : '否') . "</li>";
    echo "</ul>";
} else {
    echo "<p style='color:red;'>未找到项目配置！</p>";
}

// 2. 检查选中的日期
echo "<h3>2. 项目选中日期</h3>";
$date_config_query = "SELECT selected_meal_dates FROM projects WHERE id = :project_id";
$date_config_stmt = $db->prepare($date_config_query);
$date_config_stmt->bindParam(':project_id', $projectId);
$date_config_stmt->execute();
$date_config_row = $date_config_stmt->fetch(PDO::FETCH_ASSOC);

$selectedMealDates = [];
if ($date_config_row && !empty($date_config_row['selected_meal_dates'])) {
    $decodedDates = json_decode($date_config_row['selected_meal_dates'], true);
    if (is_array($decodedDates)) {
        $selectedMealDates = $decodedDates;
        echo "<p>选中日期数量：" . count($selectedMealDates) . "</p>";
        echo "<p>日期范围：" . min($selectedMealDates) . " 至 " . max($selectedMealDates) . "</p>";
        echo "<p>包含 2024-07-26: " . (in_array('2024-07-26', $selectedMealDates) ? '是 ✓' : '否 ✗') . "</p>";
    }
} else {
    echo "<p style='color:red;'>未选择任何日期！</p>";
}

// 3. 检查酒店记录中的日期
echo "<h3>3. 酒店入住/退房日期</h3>";
$query = "SELECT DISTINCT check_in_date as date FROM hotel_reports WHERE project_id = :project_id
          UNION SELECT DISTINCT check_out_date as date FROM hotel_reports WHERE project_id = :project_id
          ORDER BY date";
$stmt = $db->prepare($query);
$stmt->bindParam(':project_id', $projectId);
$stmt->execute();
$dateRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$allDates = array_column($dateRows, 'date');

echo "<p>酒店记录日期数量：" . count($allDates) . "</p>";
if (!empty($allDates)) {
    echo "<p>日期范围：" . min($allDates) . " 至 " . max($allDates) . "</p>";
    echo "<p>包含 2024-07-26: " . (in_array('2024-07-26', $allDates) ? '是 ✓' : '否 ✗') . "</p>";
}

// 4. 过滤后的最终日期
echo "<h3>4. 最终显示的日期</h3>";
sort($allDates);
if (!empty($selectedMealDates)) {
    $filteredDates = [];
    foreach ($allDates as $date) {
        if (in_array($date, $selectedMealDates)) {
            $filteredDates[$date] = true;
        }
    }
    $allDates = array_keys($filteredDates);
}

echo "<p>最终日期数量：" . count($allDates) . "</p>";
if (!empty($allDates)) {
    echo "<p>日期列表：</p>";
    echo "<ul>";
    foreach ($allDates as $date) {
        $dateObj = new DateTime($date);
        $weekday = $dateObj->format('l');
        $weekdayMap = [
            'Monday' => '一', 'Tuesday' => '二', 'Wednesday' => '三',
            'Thursday' => '四', 'Friday' => '五', 'Saturday' => '六', 'Sunday' => '日'
        ];
        $weekdayChar = $weekdayMap[$weekday] ?? '';
        $highlight = ($date >= '2024-07-26') ? "style='background-color: yellow;'" : "";
        echo "<li {$highlight}>{$date} (周{$weekdayChar})</li>";
    }
    echo "</ul>";
}

// 5. 检查套餐数据
echo "<h3>5. 可用套餐数据</h3>";
$packagesQuery = "SELECT * FROM meal_packages 
                   WHERE project_id = :project_id 
                   AND is_active = 1 
                   ORDER BY meal_type, id";
$packagesStmt = $db->prepare($packagesQuery);
$packagesStmt->bindParam(':project_id', $projectId);
$packagesStmt->execute();
$allPackages = $packagesStmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>可用套餐数量：" . count($allPackages) . "</p>";

if (empty($allPackages)) {
    echo "<p style='color:red; font-weight:bold;'>⚠️ 没有任何启用的套餐！请先添加套餐。</p>";
} else {
    // 按餐类型分组
    $packagesByType = [];
    foreach ($allPackages as $pkg) {
        $type = $pkg['meal_type'];
        if (!isset($packagesByType[$type])) {
            $packagesByType[$type] = [];
        }
        $packagesByType[$type][] = $pkg;
    }
    
    echo "<h4>按餐类型分组：</h4>";
    echo "<ul>";
    foreach ($packagesByType as $type => $packages) {
        echo "<li><strong>{$type}:</strong> " . count($packages) . " 个套餐";
        echo "<ul>";
        foreach ($packages as $pkg) {
            echo "<li>{$pkg['name']} (ID: {$pkg['id']})</li>";
        }
        echo "</ul></li>";
    }
    echo "</ul>";
}

// 6. 检查已分配的套餐
echo "<h3>6. 已分配的套餐（2024-07-26 及之后）</h3>";
if (!empty($allDates) && !empty($allPackages)) {
    $datePlaceholders = str_repeat('?,', count($allDates) - 1) . '?';
    $assignQuery = "SELECT meal_date, meal_type, package_id FROM meal_package_assignments
                    WHERE project_id = ? AND meal_date IN ($datePlaceholders)
                    AND meal_date >= '2024-07-26'
                    ORDER BY meal_date, meal_type";
    $assignStmt = $db->prepare($assignQuery);
    $params = array_merge([$projectId], $allDates);
    $assignStmt->execute($params);
    $assignments = $assignStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>已分配记录数：" . count($assignments) . "</p>";
    
    if (empty($assignments)) {
        echo "<p style='color:orange;'>⚠️ 2024-07-26 及之后的日期没有任何分配记录</p>";
    } else {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>日期</th><th>餐类型</th><th>套餐 ID</th></tr>";
        foreach ($assignments as $assign) {
            echo "<tr>";
            echo "<td>{$assign['meal_date']}</td>";
            echo "<td>{$assign['meal_type']}</td>";
            echo "<td>{$assign['package_id']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
}

// 7. 关键问题分析
echo "<h3>7. 问题诊断总结</h3>";
echo "<ul>";

// 检查 2024-07-26 是否在最终日期列表中
$hasJuly26 = in_array('2024-07-26', $allDates);
echo "<li>2024-07-26 是否在显示列表中：<strong>" . ($hasJuly26 ? '是 ✓' : '否 ✗') . "</strong></li>";

if (!$hasJuly26) {
    echo "<li style='color:red; font-weight:bold;'>问题原因：2024-07-26 不在项目选中的日期范围内！</li>";
    echo "<li>解决方案：请在项目设置中添加 2024-07-26 及之后的日期到用餐日期范围。</li>";
} else {
    echo "<li>2024-07-26 在日期范围内，应该能正常显示套餐选项。</li>";
}

echo "<li>可用套餐总数：<strong>" . count($allPackages) . "</strong></li>";
if (empty($allPackages)) {
    echo "<li style='color:red; font-weight:bold;'>⚠️ 没有可用套餐，这是导致不显示套餐的原因！</li>";
}

echo "</ul>";

echo "<hr>";
echo "<p><em>诊断完成时间：" . date('Y-m-d H:i:s') . "</em></p>";
?>
