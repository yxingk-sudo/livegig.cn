<?php
/**
 * 前台权限配置脚本
 * 
 * 功能：
 * 1. 添加所有前台权限记录
 * 2. 将前台权限分配给前台角色
 * 3. 验证配置结果
 * 
 * 使用方法：
 * php scripts/configure_frontend_permissions.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config/database.php';

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║          前台权限配置脚本                                 ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// 统计
$stats = [
    'permissions_added' => 0,
    'permissions_exists' => 0,
    'roles_assigned' => 0,
    'errors' => 0,
];

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // ============================================================
    // 第一步：添加前台权限记录
    // ============================================================
    echo "📋 第一步：添加前台权限记录\n";
    echo str_repeat('-', 60) . "\n";
    
    // 前台权限定义
    $frontendPermissions = [
        // 仪表盘
        ['key' => 'frontend:dashboard', 'name' => '仪表板', 'type' => 'page', 'order' => 1],
        
        // 人员管理
        ['key' => 'frontend:personnel', 'name' => '人员管理', 'type' => 'page', 'order' => 10],
        ['key' => 'frontend:personnel:list', 'name' => '查看人员列表', 'type' => 'page', 'parent' => 'frontend:personnel', 'order' => 11],
        ['key' => 'frontend:personnel:view', 'name' => '查看人员详情', 'type' => 'page', 'parent' => 'frontend:personnel', 'order' => 12],
        ['key' => 'frontend:personnel:edit', 'name' => '编辑人员信息', 'type' => 'function', 'parent' => 'frontend:personnel', 'order' => 13],
        ['key' => 'frontend:personnel:add', 'name' => '添加人员', 'type' => 'function', 'parent' => 'frontend:personnel', 'order' => 14],
        ['key' => 'frontend:personnel:delete', 'name' => '删除人员', 'type' => 'function', 'parent' => 'frontend:personnel', 'order' => 15],
        ['key' => 'frontend:personnel:batch_add', 'name' => '批量添加人员', 'type' => 'function', 'parent' => 'frontend:personnel', 'order' => 16],
        ['key' => 'frontend:personnel:export', 'name' => '导出人员信息', 'type' => 'function', 'parent' => 'frontend:personnel', 'order' => 17],
        ['key' => 'frontend:personnel:api', 'name' => '人员相关API', 'type' => 'data', 'parent' => 'frontend:personnel', 'order' => 18],
        
        // 用餐管理
        ['key' => 'frontend:meal', 'name' => '用餐管理', 'type' => 'page', 'order' => 20],
        ['key' => 'frontend:meal:list', 'name' => '查看用餐列表', 'type' => 'page', 'parent' => 'frontend:meal', 'order' => 21],
        ['key' => 'frontend:meal:order', 'name' => '订餐', 'type' => 'function', 'parent' => 'frontend:meal', 'order' => 22],
        ['key' => 'frontend:meal:batch_order', 'name' => '批量订餐', 'type' => 'function', 'parent' => 'frontend:meal', 'order' => 23],
        ['key' => 'frontend:meal:statistics', 'name' => '用餐统计', 'type' => 'page', 'parent' => 'frontend:meal', 'order' => 24],
        ['key' => 'frontend:meal:allowance', 'name' => '餐补管理', 'type' => 'function', 'parent' => 'frontend:meal', 'order' => 25],
        ['key' => 'frontend:meal:export', 'name' => '导出用餐信息', 'type' => 'function', 'parent' => 'frontend:meal', 'order' => 26],
        ['key' => 'frontend:meal:ajax', 'name' => '用餐AJAX操作', 'type' => 'data', 'parent' => 'frontend:meal', 'order' => 27],
        
        // 酒店管理
        ['key' => 'frontend:hotel', 'name' => '酒店管理', 'type' => 'page', 'order' => 30],
        ['key' => 'frontend:hotel:list', 'name' => '查看酒店列表', 'type' => 'page', 'parent' => 'frontend:hotel', 'order' => 31],
        ['key' => 'frontend:hotel:view', 'name' => '查看酒店详情', 'type' => 'page', 'parent' => 'frontend:hotel', 'order' => 32],
        ['key' => 'frontend:hotel:add', 'name' => '添加酒店', 'type' => 'function', 'parent' => 'frontend:hotel', 'order' => 33],
        ['key' => 'frontend:hotel:edit', 'name' => '编辑酒店', 'type' => 'function', 'parent' => 'frontend:hotel', 'order' => 34],
        ['key' => 'frontend:hotel:room_list', 'name' => '查看房间列表', 'type' => 'page', 'parent' => 'frontend:hotel', 'order' => 35],
        ['key' => 'frontend:hotel:statistics', 'name' => '酒店统计', 'type' => 'page', 'parent' => 'frontend:hotel', 'order' => 36],
        
        // 交通管理
        ['key' => 'frontend:transport', 'name' => '交通管理', 'type' => 'page', 'order' => 40],
        ['key' => 'frontend:transport:list', 'name' => '查看交通列表', 'type' => 'page', 'parent' => 'frontend:transport', 'order' => 41],
        ['key' => 'frontend:transport:view', 'name' => '查看交通详情', 'type' => 'page', 'parent' => 'frontend:transport', 'order' => 42],
        ['key' => 'frontend:transport:edit', 'name' => '编辑交通', 'type' => 'function', 'parent' => 'frontend:transport', 'order' => 43],
        ['key' => 'frontend:transport:add', 'name' => '添加交通', 'type' => 'function', 'parent' => 'frontend:transport', 'order' => 44],
        ['key' => 'frontend:transport:delete', 'name' => '删除交通', 'type' => 'function', 'parent' => 'frontend:transport', 'order' => 45],
        ['key' => 'frontend:transport:quick', 'name' => '快速交通安排', 'type' => 'function', 'parent' => 'frontend:transport', 'order' => 46],
        ['key' => 'frontend:transport:fleet', 'name' => '车队管理', 'type' => 'page', 'parent' => 'frontend:transport', 'order' => 47],
        ['key' => 'frontend:transport:export', 'name' => '导出交通信息', 'type' => 'function', 'parent' => 'frontend:transport', 'order' => 48],
        
        // 项目管理
        ['key' => 'frontend:project', 'name' => '项目管理', 'type' => 'page', 'order' => 50],
        ['key' => 'frontend:project:view', 'name' => '查看项目信息', 'type' => 'page', 'parent' => 'frontend:project', 'order' => 51],
        
        // 个人设置
        ['key' => 'frontend:profile', 'name' => '个人设置', 'type' => 'page', 'order' => 60],
        ['key' => 'frontend:profile:view', 'name' => '查看个人资料', 'type' => 'page', 'parent' => 'frontend:profile', 'order' => 61],
        ['key' => 'frontend:profile:edit', 'name' => '编辑个人资料', 'type' => 'function', 'parent' => 'frontend:profile', 'order' => 62],
        
        // 住宿管理
        ['key' => 'frontend:dormitory', 'name' => '住宿管理', 'type' => 'page', 'order' => 70],
        ['key' => 'frontend:dormitory:add_roommate', 'name' => '添加室友', 'type' => 'function', 'parent' => 'frontend:dormitory', 'order' => 71],
        
        // API 通用权限
        ['key' => 'frontend:api', 'name' => 'API接口', 'type' => 'data', 'order' => 80],
        ['key' => 'frontend:api:general', 'name' => '通用API', 'type' => 'data', 'parent' => 'frontend:api', 'order' => 81],
        ['key' => 'frontend:api:meal_allowance', 'name' => '餐补API', 'type' => 'data', 'parent' => 'frontend:api', 'order' => 82],
        ['key' => 'frontend:api:department_personnel', 'name' => '部门人员API', 'type' => 'data', 'parent' => 'frontend:api', 'order' => 83],
        
        // 通用权限
        ['key' => 'frontend:general', 'name' => '通用权限', 'type' => 'page', 'order' => 90],
        ['key' => 'frontend:general:view', 'name' => '查看', 'type' => 'page', 'parent' => 'frontend:general', 'order' => 91],
    ];
    
    // 构建 parent_id 映射
    $parentIdMap = [];
    
    // 首先插入顶级权限（没有 parent 的）
    foreach ($frontendPermissions as $perm) {
        if (!isset($perm['parent'])) {
            // 检查是否已存在
            $checkQuery = "SELECT id FROM permissions WHERE permission_key = :key";
            $stmt = $db->prepare($checkQuery);
            $stmt->execute([':key' => $perm['key']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$existing) {
                // 插入新权限
                $insertQuery = "INSERT INTO permissions (permission_name, permission_key, permission_type, resource_type, parent_id, sort_order, description) 
                               VALUES (:name, :key, :type, 'frontend', 0, :sort_order, :desc)";
                $stmt = $db->prepare($insertQuery);
                $stmt->execute([
                    ':name' => $perm['name'],
                    ':key' => $perm['key'],
                    ':type' => $perm['type'],
                    ':sort_order' => $perm['order'],
                    ':desc' => '前台权限：' . $perm['name']
                ]);
                
                $parentIdMap[$perm['key']] = $db->lastInsertId();
                $stats['permissions_added']++;
                echo "✅ 添加：{$perm['key']}\n";
            } else {
                $parentIdMap[$perm['key']] = $existing['id'];
                $stats['permissions_exists']++;
                echo "⏭️  已存在：{$perm['key']}\n";
            }
        }
    }
    
    // 然后插入子权限
    foreach ($frontendPermissions as $perm) {
        if (isset($perm['parent'])) {
            // 检查是否已存在
            $checkQuery = "SELECT id FROM permissions WHERE permission_key = :key";
            $stmt = $db->prepare($checkQuery);
            $stmt->execute([':key' => $perm['key']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $parentId = $parentIdMap[$perm['parent']] ?? 0;
            
            if (!$existing) {
                // 插入新权限
                $insertQuery = "INSERT INTO permissions (permission_name, permission_key, permission_type, resource_type, parent_id, sort_order, description) 
                               VALUES (:name, :key, :type, 'frontend', :parent_id, :sort_order, :desc)";
                $stmt = $db->prepare($insertQuery);
                $stmt->execute([
                    ':name' => $perm['name'],
                    ':key' => $perm['key'],
                    ':type' => $perm['type'],
                    ':parent_id' => $parentId,
                    ':sort_order' => $perm['order'],
                    ':desc' => '前台权限：' . $perm['name']
                ]);
                
                $parentIdMap[$perm['key']] = $db->lastInsertId();
                $stats['permissions_added']++;
                echo "✅ 添加：{$perm['key']}\n";
            } else {
                $parentIdMap[$perm['key']] = $existing['id'];
                $stats['permissions_exists']++;
                echo "⏭️  已存在：{$perm['key']}\n";
            }
        }
    }
    
    echo "\n";
    
    // ============================================================
    // 第二步：获取前台角色 ID
    // ============================================================
    echo "📋 第二步：获取前台角色\n";
    echo str_repeat('-', 60) . "\n";
    
    // 获取前台管理员角色
    $query = "SELECT id, role_name, role_key FROM roles WHERE role_type = 'frontend' AND status = 1";
    $stmt = $db->query($query);
    $frontendRoles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($frontendRoles as $role) {
        echo "✅ 角色：{$role['role_name']} (ID: {$role['id']}, Key: {$role['role_key']})\n";
    }
    
    echo "\n";
    
    // ============================================================
    // 第三步：分配权限给角色
    // ============================================================
    echo "📋 第三步：分配权限给角色\n";
    echo str_repeat('-', 60) . "\n";
    
    // 为前台管理员分配所有前台权限
    foreach ($frontendRoles as $role) {
        echo "\n处理角色：{$role['role_name']}\n";
        
        // 前台管理员获取所有前台权限
        if ($role['role_key'] === 'user_admin') {
            // 获取所有前台权限 ID
            $permQuery = "SELECT id FROM permissions WHERE resource_type = 'frontend'";
            $stmt = $db->query($permQuery);
            $permIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($permIds as $permId) {
                // 检查是否已分配
                $checkQuery = "SELECT COUNT(*) FROM role_permissions WHERE role_id = :role_id AND permission_id = :perm_id";
                $stmt = $db->prepare($checkQuery);
                $stmt->execute([':role_id' => $role['id'], ':perm_id' => $permId]);
                
                if ($stmt->fetchColumn() == 0) {
                    // 分配权限
                    $insertQuery = "INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :perm_id)";
                    $stmt = $db->prepare($insertQuery);
                    $stmt->execute([':role_id' => $role['id'], ':perm_id' => $permId]);
                    $stats['roles_assigned']++;
                    echo "  ✅ 分配权限 ID: {$permId}\n";
                }
            }
        }
        
        // 普通前台用户获取基础权限
        if ($role['role_key'] === 'user') {
            // 基础权限
            $basicPerms = [
                'frontend:dashboard',
                'frontend:personnel:list',
                'frontend:personnel:view',
                'frontend:meal:list',
                'frontend:meal:order',
                'frontend:hotel:list',
                'frontend:hotel:view',
                'frontend:transport:list',
                'frontend:transport:view',
                'frontend:project:view',
                'frontend:profile:view',
            ];
            
            foreach ($basicPerms as $permKey) {
                if (isset($parentIdMap[$permKey])) {
                    $permId = $parentIdMap[$permKey];
                    
                    // 检查是否已分配
                    $checkQuery = "SELECT COUNT(*) FROM role_permissions WHERE role_id = :role_id AND permission_id = :perm_id";
                    $stmt = $db->prepare($checkQuery);
                    $stmt->execute([':role_id' => $role['id'], ':perm_id' => $permId]);
                    
                    if ($stmt->fetchColumn() == 0) {
                        $insertQuery = "INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :perm_id)";
                        $stmt = $db->prepare($insertQuery);
                        $stmt->execute([':role_id' => $role['id'], ':perm_id' => $permId]);
                        $stats['roles_assigned']++;
                        echo "  ✅ 分配权限：{$permKey}\n";
                    }
                }
            }
        }
    }
    
    echo "\n";
    
    // ============================================================
    // 第四步：验证结果
    // ============================================================
    echo "📋 第四步：验证配置结果\n";
    echo str_repeat('-', 60) . "\n";
    
    // 统计前台权限数量
    $query = "SELECT COUNT(*) FROM permissions WHERE resource_type = 'frontend'";
    $stmt = $db->query($query);
    $frontendPermCount = $stmt->fetchColumn();
    echo "✅ 前台权限总数：{$frontendPermCount}\n";
    
    // 统计角色权限分配
    foreach ($frontendRoles as $role) {
        $query = "SELECT COUNT(*) FROM role_permissions rp 
                  INNER JOIN roles r ON rp.role_id = r.id 
                  INNER JOIN permissions p ON rp.permission_id = p.id 
                  WHERE r.id = :role_id AND p.resource_type = 'frontend'";
        $stmt = $db->prepare($query);
        $stmt->execute([':role_id' => $role['id']]);
        $permCount = $stmt->fetchColumn();
        echo "✅ {$role['role_name']} 已分配权限数：{$permCount}\n";
    }
    
} catch (Exception $e) {
    echo "❌ 错误：" . $e->getMessage() . "\n";
    $stats['errors']++;
}

// 输出统计
echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║                      配置完成                              ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

echo "统计结果：\n";
echo "  ✅ 新增权限：{$stats['permissions_added']}\n";
echo "  ⏭️  已存在权限：{$stats['permissions_exists']}\n";
echo "  ✅ 分配角色权限：{$stats['roles_assigned']}\n";
echo "  ❌ 发生错误：{$stats['errors']}\n\n";

echo "✅ 下一步：访问权限管理页面进行配置\n";
echo "   URL: https://livegig.cn/admin/permission_management.php\n\n";
?>
