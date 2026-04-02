<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    die("数据库连接失败\n");
}

// 查询所有权限
$query = "SELECT id, permission_key, permission_name, permission_type, parent_id, resource_type, sort_order 
          FROM permissions 
          ORDER BY parent_id, sort_order";

$stmt = $db->query($query);
$permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== 系统权限列表 ===\n\n";

$currentParent = null;
foreach ($permissions as $perm) {
    if ($perm['parent_id'] == 0) {
        echo "\n【一级权限】{$perm['permission_name']} ({$perm['permission_key']})\n";
        echo str_repeat("-", 80) . "\n";
        $currentParent = $perm['id'];
    } else {
        if ($currentParent != $perm['parent_id']) {
            // 找到父级名称
            foreach ($permissions as $p) {
                if ($p['id'] == $perm['parent_id']) {
                    echo "  └── 【二级权限】{$p['permission_name']} > {$perm['permission_name']} ({$perm['permission_key']})\n";
                    break;
                }
            }
        } else {
            echo "  ├── {$perm['permission_name']} ({$perm['permission_key']})\n";
        }
    }
}

echo "\n\n=== 按资源类型统计 ===\n\n";

$resourceTypes = array_unique(array_column($permissions, 'resource_type'));
foreach ($resourceTypes as $type) {
    $count = count(array_filter($permissions, function($p) use ($type) {
        return $p['resource_type'] === $type;
    }));
    echo "$type: $count 个权限\n";
}

echo "\n总权限数：" . count($permissions) . "\n";
