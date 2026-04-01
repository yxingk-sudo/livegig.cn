<?php
/**
 * 检查问题人员的详细信息
 */

require_once '/www/wwwroot/livegig.cn/config/database.php';

$database = new Database();
$db = $database->getConnection();

echo "=== 检查问题人员详情 ===\n\n";

// 出现问题的人员 ID 列表
$problemIds = [23, 26, 534, 522, 413, 192, 25];

echo "人员基本信息:\n";
$query = "SELECT id, name FROM personnel WHERE id IN (" . implode(',', $problemIds) . ")";
$stmt = $db->query($query);
$persons = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($persons as $p) {
    echo "  ID: {$p['id']}, 姓名：" . ($p['name'] ?? 'NULL') . "\n";
}

echo "\n=== 检查这些人员在各个项目的关联情况 ===\n\n";

foreach ($problemIds as $pid) {
    echo "--- 人员 ID: $pid ---\n";
    
    // 查询基本关联
    $query = "SELECT pdp.project_id, pdp.department_id, d.name as dept_name, 
              pdp.personnel_id, p.name as person_name, pdp.status
              FROM project_department_personnel pdp
              JOIN personnel p ON pdp.personnel_id = p.id
              LEFT JOIN departments d ON pdp.department_id = d.id
              WHERE pdp.personnel_id = $pid
              ORDER BY pdp.project_id";
    
    $stmt = $db->query($query);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($results)) {
        foreach ($results as $row) {
            echo "  项目{$row['project_id']}, 部门{$row['department_id']} ({$row['dept_name']}), ";
            echo "姓名：{$row['person_name']}, 状态：{$row['status']}\n";
        }
    } else {
        echo "  ❌ 没有任何关联记录！\n";
    }
    echo "\n";
}

echo "\n=== 检查当前用户所在的项目 ===\n\n";
// 假设用户在项目 4
$currentProjectId = 4;
echo "假设当前项目 ID: $currentProjectId\n\n";

// 查询项目 4 的所有部门和人员
$query = "SELECT d.id as dept_id, d.name as dept_name, p.id as person_id, p.name as person_name
          FROM project_department_personnel pdp
          JOIN departments d ON pdp.department_id = d.id
          JOIN personnel p ON pdp.personnel_id = p.id
          WHERE pdp.project_id = $currentProjectId
          AND pdp.status = 'active'
          ORDER BY d.name, p.name";

$stmt = $db->query($query);
$projectPersons = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "项目 4 的所有活跃人员关联:\n";
if (!empty($projectPersons)) {
    foreach ($projectPersons as $row) {
        $isProblem = in_array($row['person_id'], $problemIds) ? '⚠️' : '✓';
        echo "$isProblem 部门{$row['dept_id']} ({$row['dept_name']}), 人员{$row['person_id']} ({$row['person_name']})\n";
    }
} else {
    echo "  暂无数据\n";
}

?>
