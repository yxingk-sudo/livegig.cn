<?php
/**
 * 测试 AJAX API - 直接调用并输出结果
 */

require_once '/www/wwwroot/livegig.cn/config/database.php';

$database = new Database();
$db = $database->getConnection();

echo "=== 测试 get_dept_personnel_map.php API ===\n\n";

// 模拟请求参数（假设选择了部门 62 和另一个部门）
$deptIds = [62, 100]; // Artist/艺人 和 主办单位

echo "测试部门 IDs: " . implode(', ', $deptIds) . "\n\n";

// 执行查询
$projectId = 4; // 假设当前项目
$placeholders = str_repeat('?,', count($deptIds) - 1) . '?';
$query = "SELECT DISTINCT p.id, p.name 
          FROM personnel p
          JOIN project_department_personnel pdp ON p.id = pdp.personnel_id
          WHERE pdp.project_id = ? 
          AND pdp.department_id IN ($placeholders) 
          AND pdp.status = 'active'";

echo "SQL 查询:\n$query\n\n";

$stmt = $db->prepare($query);
$params = array_merge([$projectId], $deptIds);
$stmt->execute($params);

$personnelList = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "查询结果:\n";
echo "找到 " . count($personnelList) . " 名人员\n\n";

foreach ($personnelList as $person) {
    echo "ID: {$person['id']}, 姓名：" . ($person['name'] ?? 'NULL') . "\n";
}

echo "\n构建的映射数组:\n";
$personnelNames = [];
foreach ($personnelList as $person) {
    $personId = intval($person['id']);
    $personName = !empty($person['name']) ? $person['name'] : "未知人员{$personId}";
    $personnelNames[$personId] = $personName;
    echo "  [$personId] => $personName\n";
}

echo "\nJSON 输出:\n";
echo json_encode([
    'success' => true,
    'personnel_ids' => array_map('intval', array_column($personnelList, 'id')),
    'personnel_names' => $personnelNames,
    'count' => count($personnelList)
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

?>
