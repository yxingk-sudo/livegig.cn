<?php
/**
 * 修复项目部门人员关联表中的重复记录
 */

require_once '/www/wwwroot/livegig.cn/config/database.php';
require_once '/www/wwwroot/livegig.cn/includes/functions.php';

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    echo "数据库连接失败\n";
    exit(1);
}

echo "=== 检查并修复 project_department_personnel 表的重复记录 ===\n\n";

// 查找重复记录
$query = "SELECT project_id, department_id, personnel_id, COUNT(*) as cnt
          FROM project_department_personnel
          GROUP BY project_id, department_id, personnel_id
          HAVING cnt > 1";

$stmt = $db->query($query);
$duplicates = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($duplicates)) {
    echo "发现 " . count($duplicates) . " 条重复记录:\n\n";
    
    foreach ($duplicates as $dup) {
        echo "项目 ID: {$dup['project_id']}, 部门 ID: {$dup['department_id']}, 人员 ID: {$dup['personnel_id']}, 重复次数：{$dup['cnt']}\n";
        
        // 删除重复记录，只保留一条
        $deleteQuery = "DELETE FROM project_department_personnel 
                       WHERE project_id = :project_id 
                       AND department_id = :department_id 
                       AND personnel_id = :personnel_id 
                       LIMIT " . ($dup['cnt'] - 1);
        
        $deleteStmt = $db->prepare($deleteQuery);
        $deleteStmt->execute([
            ':project_id' => $dup['project_id'],
            ':department_id' => $dup['department_id'],
            ':personnel_id' => $dup['personnel_id']
        ]);
        
        echo "  → 已删除 " . ($dup['cnt'] - 1) . " 条重复记录\n\n";
    }
    
    echo "✓ 所有重复记录已清理完成\n";
} else {
    echo "✓ 未发现重复记录\n";
}

// 再次检查 Artist/艺人部门的人员
echo "\n=== 复查 Artist/艺人部门的人员 ===\n\n";
$query = "SELECT p.id, p.name 
          FROM personnel p
          JOIN project_department_personnel pdp ON p.id = pdp.personnel_id
          JOIN departments d ON pdp.department_id = d.id
          WHERE d.name = 'Artist/艺人'
          AND pdp.status = 'active'
          ORDER BY p.id";

$stmt = $db->query($query);
$deptPersonnel = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($deptPersonnel)) {
    echo "Artist/艺人部门有 " . count($deptPersonnel) . " 人:\n\n";
    foreach ($deptPersonnel as $person) {
        echo "✓ ID: {$person['id']}, 姓名：" . $person['name'] . "\n";
    }
} else {
    echo "该部门暂无人员\n";
}

echo "\n=== 修复完成 ===\n";
?>
