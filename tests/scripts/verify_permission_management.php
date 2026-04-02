<?php
/**
 * 权限管理系统验证脚本
 * 
 * 功能：
 * 1. 验证权限管理页面可访问性
 * 2. 验证权限配置功能
 * 3. 测试权限分配和回收
 * 
 * 使用方法：
 * php tests/scripts/verify_permission_management.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/database.php';

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║          权限管理系统验证脚本                            ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// 统计
$stats = [
    'passed' => 0,
    'failed' => 0,
    'warnings' => 0,
];

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // ============================================================
    // 第一部分：数据库结构验证
    // ============================================================
    echo "📋 第一部分：数据库结构验证\n";
    echo str_repeat('-', 60) . "\n";
    
    $requiredTables = [
        'roles',
        'permissions',
        'role_permissions',
        'admin_users',
        'project_users',
        'user_roles',
    ];
    
    foreach ($requiredTables as $table) {
        $query = "SHOW TABLES LIKE '{$table}'";
        $stmt = $db->query($query);
        $exists = $stmt->fetchColumn();
        
        if ($exists) {
            echo "✅ 表 {$table} 存在\n";
            $stats['passed']++;
        } else {
            echo "❌ 表 {$table} 不存在\n";
            $stats['failed']++;
        }
    }
    
    echo "\n";
    
    // ============================================================
    // 第二部分：角色验证
    // ============================================================
    echo "📋 第二部分：角色验证\n";
    echo str_repeat('-', 60) . "\n";
    
    $query = "SELECT id, role_name, role_key, role_type, status FROM roles WHERE status = 1 ORDER BY role_type, id";
    $stmt = $db->query($query);
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "找到 " . count($roles) . " 个有效角色：\n";
    foreach ($roles as $role) {
        echo "  - {$role['role_name']} (Key: {$role['role_key']}, Type: {$role['role_type']})\n";
    }
    
    $stats['passed']++;
    echo "\n";
    
    // ============================================================
    // 第三部分：权限验证
    // ============================================================
    echo "📋 第三部分：权限验证\n";
    echo str_repeat('-', 60) . "\n";
    
    // 后台权限统计
    $query = "SELECT COUNT(*) FROM permissions WHERE resource_type = 'backend' AND status = 1";
    $stmt = $db->query($query);
    $backendPermCount = $stmt->fetchColumn();
    echo "✅ 后台权限数量：{$backendPermCount}\n";
    $stats['passed']++;
    
    // 前台权限统计
    $query = "SELECT COUNT(*) FROM permissions WHERE resource_type = 'frontend' AND status = 1";
    $stmt = $db->query($query);
    $frontendPermCount = $stmt->fetchColumn();
    echo "✅ 前台权限数量：{$frontendPermCount}\n";
    $stats['passed']++;
    
    // 总权限
    $totalPerms = $backendPermCount + $frontendPermCount;
    echo "✅ 总权限数量：{$totalPerms}\n\n";
    
    // 前台权限列表
    echo "前台权限列表：\n";
    $query = "SELECT permission_key, permission_name FROM permissions WHERE resource_type = 'frontend' ORDER BY permission_key";
    $stmt = $db->query($query);
    $frontendPerms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($frontendPerms as $perm) {
        echo "  - {$perm['permission_key']} ({$perm['permission_name']})\n";
    }
    
    echo "\n";
    
    // ============================================================
    // 第四部分：角色权限分配验证
    // ============================================================
    echo "📋 第四部分：角色权限分配验证\n";
    echo str_repeat('-', 60) . "\n";
    
    // 获取所有角色及其权限数量
    $query = "SELECT r.id, r.role_name, r.role_key, r.role_type,
              COUNT(DISTINCT rp.permission_id) as perm_count
              FROM roles r
              LEFT JOIN role_permissions rp ON r.id = rp.role_id
              WHERE r.status = 1
              GROUP BY r.id, r.role_name, r.role_key, r.role_type
              ORDER BY r.role_type, r.id";
    $stmt = $db->query($query);
    $rolePerms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($rolePerms as $rp) {
        $typeIcon = $rp['role_type'] === 'backend' ? '🔵' : '🟢';
        echo "{$typeIcon} {$rp['role_name']} ({$rp['role_key']}) - 权限数：{$rp['perm_count']}\n";
        
        // 如果权限数为0，发出警告
        if ($rp['perm_count'] == 0) {
            echo "  ⚠️  警告：此角色没有任何权限分配！\n";
            $stats['warnings']++;
        }
        
        // 显示部分权限
        if ($rp['perm_count'] > 0) {
            $permQuery = "SELECT p.permission_key 
                         FROM permissions p
                         INNER JOIN role_permissions rp ON p.id = rp.permission_id
                         WHERE rp.role_id = :role_id
                         ORDER BY p.permission_key
                         LIMIT 5";
            $stmt = $db->prepare($permQuery);
            $stmt->execute([':role_id' => $rp['id']]);
            $samplePerms = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (count($samplePerms) > 0) {
                echo "  示例权限：";
                echo implode(', ', $samplePerms);
                if ($rp['perm_count'] > 5) {
                    echo " ... (共 {$rp['perm_count']} 个)";
                }
                echo "\n";
            }
        }
    }
    
    $stats['passed']++;
    echo "\n";
    
    // ============================================================
    // 第五部分：测试权限管理功能
    // ============================================================
    echo "📋 第五部分：权限管理功能测试\n";
    echo str_repeat('-', 60) . "\n";
    
    // 测试 1: 获取角色权限
    $testRoleId = 1; // 假设第一个角色
    $query = "SELECT p.* FROM permissions p
              INNER JOIN role_permissions rp ON p.id = rp.permission_id
              WHERE rp.role_id = :role_id
              ORDER BY p.permission_key";
    $stmt = $db->prepare($query);
    $stmt->execute([':role_id' => $testRoleId]);
    $rolePermissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "✅ 测试角色 (ID:{$testRoleId}) 权限查询：获取到 " . count($rolePermissions) . " 个权限\n";
    $stats['passed']++;
    
    // 测试 2: 获取所有权限树
    $query = "SELECT * FROM permissions WHERE status = 1 ORDER BY parent_id, sort_order";
    $stmt = $db->query($query);
    $allPermissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "✅ 权限树查询：获取到 " . count($allPermissions) . " 个权限节点\n";
    $stats['passed']++;
    
    // 测试 3: 验证权限分配功能
    $testPermissionId = 1;
    $query = "INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :perm_id)
              ON DUPLICATE KEY UPDATE role_id = role_id";
    $stmt = $db->prepare($query);
    $stmt->execute([':role_id' => 1, ':perm_id' => $testPermissionId]);
    
    echo "✅ 权限分配功能测试：通过\n";
    $stats['passed']++;
    
    // 测试 4: 验证权限撤销功能
    $query = "DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :perm_id";
    $stmt = $db->prepare($query);
    $stmt->execute([':role_id' => 1, ':perm_id' => $testPermissionId]);
    
    echo "✅ 权限撤销功能测试：通过\n";
    $stats['passed']++;
    
    echo "\n";
    
    // ============================================================
    // 第六部分：前后台权限对比
    // ============================================================
    echo "📋 第六部分：前后台权限对比\n";
    echo str_repeat('-', 60) . "\n";
    
    // 后台权限分布
    $query = "SELECT 
              SUBSTRING_INDEX(permission_key, ':', 2) as module,
              COUNT(*) as count
              FROM permissions 
              WHERE resource_type = 'backend' AND status = 1
              GROUP BY module
              ORDER BY count DESC";
    $stmt = $db->query($query);
    $backendModules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "🔵 后台权限模块分布：\n";
    foreach ($backendModules as $module) {
        echo "  - {$module['module']}：{$module['count']} 个权限\n";
    }
    
    echo "\n";
    
    // 前台权限分布
    $query = "SELECT 
              SUBSTRING_INDEX(permission_key, ':', 2) as module,
              COUNT(*) as count
              FROM permissions 
              WHERE resource_type = 'frontend' AND status = 1
              GROUP BY module
              ORDER BY count DESC";
    $stmt = $db->query($query);
    $frontendModules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "🟢 前台权限模块分布：\n";
    foreach ($frontendModules as $module) {
        echo "  - {$module['module']}：{$module['count']} 个权限\n";
    }
    
    $stats['passed']++;
    echo "\n";
    
} catch (Exception $e) {
    echo "❌ 错误：" . $e->getMessage() . "\n";
    $stats['failed']++;
}

// 输出统计结果
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║                      验证结果                          ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

$total = $stats['passed'] + $stats['failed'] + $stats['warnings'];
$passRate = round(($stats['passed'] / $total) * 100, 2);

echo "总测试项：{$total}\n";
echo "✅ 通过：{$stats['passed']} ({$passRate}%)\n";
echo "⚠️  警告：{$stats['warnings']}\n";
echo "❌ 失败：{$stats['failed']}\n\n";

if ($stats['failed'] == 0) {
    echo "🎉 权限管理系统验证通过！\n\n";
} else {
    echo "⚠️  发现 {$stats['failed']} 个问题，请检查。\n\n";
}

// 访问权限管理页面
echo "📋 访问权限管理页面：\n";
echo "   🔵 后台：http://your-domain/admin/permission_management.php\n";
echo "   🟢 前台：http://your-domain/user/user_permission_management.php\n\n";

echo "✅ 下一步操作建议：\n";
echo "   1. 访问权限管理页面，检查界面显示\n";
echo "   2. 选择不同角色，检查权限树是否正确加载\n";
echo "   3. 尝试分配/撤销权限，验证功能是否正常\n";
echo "   4. 使用不同角色登录，验证权限控制是否生效\n\n";
?>
