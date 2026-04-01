<?php
/**
 * 详细检查 Artist/艺人部门的人员关联
 */

require_once '/www/wwwroot/livegig.cn/config/database.php';

$database = new Database();
$db = $database->getConnection();

echo "=== 详细检查 Artist/艺人部门 ===\n\n";

// 获取部门 ID
$query = "SELECT id FROM departments WHERE name = 'Artist/艺人'";
$stmt = $db->query($query);
$deptId = $stmt->fetchColumn();

if (!$deptId) {
    echo "未找到部门：Artist/艺人\n";
    exit(1);
}

echo "部门 ID: $deptId\n\n";

// 查询该部门的所有关联记录（包括所有项目）
$query = "SELECT pdp.project_id, pdp.department_id, pdp.personnel_id, p.name, pdp.status,
          COUNT(*) as cnt
          FROM project_department_personnel pdp
          JOIN personnel p ON pdp.personnel_id = p.id
          WHERE pdp.department_id = $deptId
          GROUP BY pdp.project_id, pdp.department_id, pdp.personnel_id, p.name, pdp.status
          ORDER BY pdp.personnel_id, pdp.project_id";

$stmt = $db->query($query);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "该部门的所有关联记录:\n\n";
foreach ($results as $row) {
    $duplicate = $row['cnt'] > 1 ? '⚠️ 重复' : '✓';
    echo "$duplicate 项目 {$row['project_id']}, 部门 {$row['department_id']}, 人员 {$row['personnel_id']} ({$row['name']}), 状态：{$row['status']}, 计数：{$row['cnt']}\n";
}

// 特别检查人员 534
echo "\n=== 特别检查人员 534 ===\n\n";
$query = "SELECT pdp.project_id, pdp.department_id, d.name as dept_name, 
          pdp.personnel_id, p.name as person_name, pdp.status
          FROM project_department_personnel pdp
          JOIN personnel p ON pdp.personnel_id = p.id
          JOIN departments d ON pdp.department_id = d.id
          WHERE pdp.personnel_id = 534
          ORDER BY pdp.project_id, pdp.department_id";

$stmt = $db->query($query);
$person534 = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($person534)) {
    echo "人员 534 (江海迦) 的关联情况:\n\n";
    foreach ($person534 as $row) {
        echo "项目 {$row['project_id']}, 部门 {$row['department_id']} ({$row['dept_name']}), 状态：{$row['status']}\n";
    }
} else {
    echo "人员 534 没有任何关联记录\n";
}

echo "\n=== 检查完成 ===\n";
?>
