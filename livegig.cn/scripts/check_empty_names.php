<?php
/**
 * 检查并修复人员姓名为空的问题
 */

require_once '/www/wwwroot/livegig.cn/config/database.php';
require_once '/www/wwwroot/livegig.cn/includes/functions.php';

session_start();

// 如果不是从 admin 目录访问，需要包含完整路径
if (!isset($_SESSION['user_id'])) {
    // 命令行运行时设置 session
    $_SESSION['user_id'] = 1;
    $_SESSION['project_id'] = 1;
}

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    echo "数据库连接失败\n";
    exit(1);
}

echo "=== 检查人员姓名为空的记录 ===\n\n";

// 查询 ID 为 534 的人员记录
$query = "SELECT id, name FROM personnel WHERE id = 534";
$stmt = $db->query($query);
$person = $stmt->fetch(PDO::FETCH_ASSOC);

if ($person) {
    echo "找到人员 ID 534:\n";
    echo "  - 当前姓名：" . ($person['name'] ?? 'NULL') . "\n";
    echo "  - 姓名长度：" . strlen($person['name'] ?? '') . "\n";
    echo "  - 是否为空：" . (empty($person['name']) ? '是' : '否') . "\n\n";
    
    if (empty($person['name'])) {
        echo "⚠️  发现姓名为空！\n";
        echo "建议执行以下 SQL 更新：\n\n";
        echo "UPDATE personnel SET name = '待完善姓名' WHERE id = 534;\n\n";
    } else {
        echo "✓ 姓名不为空\n";
    }
} else {
    echo "❌ 未找到人员 ID 534\n\n";
}

// 查询所有姓名为空或格式异常的人员
echo "=== 检查所有姓名异常的人员记录 ===\n\n";
$query = "SELECT id, name FROM personnel 
          WHERE name IS NULL 
             OR name = '' 
             OR name LIKE '人员%' 
             OR name LIKE '未知人员%'
          ORDER BY id";
$stmt = $db->query($query);
$abnormal = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($abnormal)) {
    echo "发现 " . count($abnormal) . " 条姓名异常记录:\n\n";
    foreach ($abnormal as $row) {
        echo "ID: {$row['id']}, 姓名：" . ($row['name'] ?? 'NULL') . "\n";
    }
    
    echo "\n建议批量更新 SQL:\n\n";
    foreach ($abnormal as $row) {
        echo "UPDATE personnel SET name = '待完善姓名_{$row['id']}' WHERE id = {$row['id']};\n";
    }
} else {
    echo "✓ 未发现姓名异常记录\n";
}

// 查询 Artist/艺人部门的所有人员
echo "\n=== 查询 Artist/艺人部门的人员 ===\n\n";
$query = "SELECT p.id, p.name 
          FROM personnel p
          JOIN project_department_personnel pdp ON p.id = pdp.personnel_id
          JOIN departments d ON pdp.department_id = d.id
          WHERE d.name = 'Artist/艺人'
          AND pdp.status = 'active'";
$stmt = $db->query($query);
$deptPersonnel = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($deptPersonnel)) {
    echo "Artist/艺人部门有 " . count($deptPersonnel) . " 人:\n\n";
    foreach ($deptPersonnel as $person) {
        $status = empty($person['name']) ? '⚠️ 姓名为空' : '✓';
        echo "$status ID: {$person['id']}, 姓名：" . ($person['name'] ?? 'NULL') . "\n";
    }
} else {
    echo "该部门暂无人员\n";
}

echo "\n=== 检查完成 ===\n";
?>
