<?php
/**
 * 套餐分配页面完整诊断工具
 * 功能：检查所有可能导致不显示套餐的原因
 */

require_once '../config/database.php';
require_once '../includes/functions.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    die(json_encode(['error' => '未登录'], JSON_UNESCAPED_UNICODE));
}

$projectId = $_SESSION['project_id'];
$database = new Database();
$db = $database->getConnection();

header('Content-Type: application/json; charset=utf-8');

try {
    // 1. 获取项目配置
    $configQuery = "SELECT id, name, breakfast_enabled, lunch_enabled, dinner_enabled, supper_enabled, selected_meal_dates FROM projects WHERE id = :project_id";
    $configStmt = $db->prepare($configQuery);
    $configStmt->bindParam(':project_id', $projectId);
    $configStmt->execute();
    $config = $configStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        throw new Exception("未找到项目配置");
    }
    
    // 2. 获取套餐数据
    $packagesQuery = "SELECT id, name, meal_type, is_active, price, description 
                      FROM meal_packages 
                      WHERE project_id = :project_id 
                      ORDER BY meal_type, id";
    $packagesStmt = $db->prepare($packagesQuery);
    $packagesStmt->bindParam(':project_id', $projectId);
    $packagesStmt->execute();
    $allPackages = $packagesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 按餐类型分组
    $packagesByType = [];
    foreach ($allPackages as $pkg) {
        if ($pkg['is_active']) {
            $type = $pkg['meal_type'];
            if (!isset($packagesByType[$type])) {
                $packagesByType[$type] = [];
            }
            $packagesByType[$type][] = $pkg;
        }
    }
    
    // 3. 获取日期数据
    $dateQuery = "SELECT DISTINCT check_in_date as date FROM hotel_reports WHERE project_id = :project_id
                  UNION SELECT DISTINCT check_out_date as date FROM hotel_reports WHERE project_id = :project_id
                  ORDER BY date";
    $dateStmt = $db->prepare($dateQuery);
    $dateStmt->bindParam(':project_id', $projectId);
    $dateStmt->execute();
    $allDates = array_column($dateStmt->fetchAll(PDO::FETCH_ASSOC), 'date');
    
    // 4. 解析选中的日期
    $selectedMealDates = [];
    if (!empty($config['selected_meal_dates'])) {
        $decodedDates = json_decode($config['selected_meal_dates'], true);
        if (is_array($decodedDates)) {
            $selectedMealDates = $decodedDates;
        }
    }
    
    // 5. 过滤日期
    if (!empty($selectedMealDates)) {
        $filteredDates = [];
        foreach ($allDates as $date) {
            if (in_array($date, $selectedMealDates)) {
                $filteredDates[$date] = true;
            }
        }
        $allDates = array_keys($filteredDates);
    }
    
    // 6. 获取已分配的套餐
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
    
    // 7. 生成诊断报告
    $report = [
        'timestamp' => date('Y-m-d H:i:s'),
        'project' => [
            'id' => $config['id'],
            'name' => $config['name'],
            'meal_types' => [
                '早餐' => ['enabled' => (bool)$config['breakfast_enabled'], 'has_packages' => !empty($packagesByType['早餐'])],
                '午餐' => ['enabled' => (bool)$config['lunch_enabled'], 'has_packages' => !empty($packagesByType['午餐'])],
                '晚餐' => ['enabled' => (bool)$config['dinner_enabled'], 'has_packages' => !empty($packagesByType['晚餐'])],
                '宵夜' => ['enabled' => (bool)$config['supper_enabled'], 'has_packages' => !empty($packagesByType['宵夜'])],
            ]
        ],
        'dates' => [
            'total' => count($allDates),
            'list' => $allDates,
            'selected_config' => $selectedMealDates ?: null
        ],
        'packages' => [
            'total' => count($allPackages),
            'active' => array_sum(array_map(function($arr) { return count($arr); }, $packagesByType)),
            'by_type' => array_map(function($pkgs) {
                return array_map(function($pkg) {
                    return ['id' => $pkg['id'], 'name' => $pkg['name']];
                }, $pkgs);
            }, $packagesByType)
        ],
        'assignments' => [
            'total_records' => count($assignments ?? []),
            'details' => $assignedPackages
        ],
        'diagnosis' => []
    ];
    
    // 8. 生成诊断结论
    $issues = [];
    
    // 检查各餐类型
    foreach (['早餐', '午餐', '晚餐', '宵夜'] as $mealType) {
        $enabled = $report['project']['meal_types'][$mealType]['enabled'];
        $hasPackages = $report['project']['meal_types'][$mealType]['has_packages'];
        
        if (!$enabled) {
            $issues[] = "⚠️ {$mealType}: 项目配置中已禁用";
        } elseif (!$hasPackages) {
            $issues[] = "❌ {$mealType}: 没有可用的套餐数据，请在后台添加套餐";
        }
    }
    
    // 检查日期
    if (empty($allDates)) {
        $issues[] = "❌ 没有可用的日期数据（从酒店报表获取）";
    }
    
    // 模拟每个日期和餐类型的显示情况
    $displayPreview = [];
    foreach ($allDates as $date) {
        $dateObj = new DateTime($date);
        $weekday = $dateObj->format('l');
        $weekdayMap = ['Monday' => '一', 'Tuesday' => '二', 'Wednesday' => '三', 'Thursday' => '四', 'Friday' => '五', 'Saturday' => '六', 'Sunday' => '日'];
        $weekdayChar = $weekdayMap[$weekday] ?? '';
        
        foreach (['早餐', '午餐', '晚餐', '宵夜'] as $mealType) {
            if ($report['project']['meal_types'][$mealType]['enabled'] && isset($packagesByType[$mealType])) {
                $key = $date . '_' . $mealType;
                $assignedIds = $assignedPackages[$key] ?? [];
                $displayPreview[$date][$mealType] = [
                    'should_show' => true,
                    'package_count' => count($packagesByType[$mealType]),
                    'assigned_count' => count($assignedIds),
                    'status' => empty($assignedIds) ? '未分配' : '已分配 (' . count($assignedIds) . '个)'
                ];
            } else {
                $displayPreview[$date][$mealType] = [
                    'should_show' => false,
                    'reason' => !$report['project']['meal_types'][$mealType]['enabled'] ? '餐类禁用' : '无套餐数据'
                ];
            }
        }
    }
    
    $report['display_preview'] = $displayPreview;
    $report['issues'] = $issues;
    $report['summary'] = empty($issues) ? '✅ 未发现明显问题' : '发现 ' . count($issues) . ' 个问题';
    
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>
