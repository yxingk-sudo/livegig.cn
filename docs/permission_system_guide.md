# 前后台分离多级权限管理系统 - 完整实施指南

## 目录
1. [系统概述](#系统概述)
2. [数据库初始化](#数据库初始化)
3. [核心组件说明](#核心组件说明)
4. [后台权限控制](#后台权限控制)
5. [前台权限控制](#前台权限控制)
6. [权限管理界面](#权限管理界面)
7. [实施步骤](#实施步骤)
8. [常见问题](#常见问题)

---

## 系统概述

### 权限体系架构

#### 后台权限体系（三级）
```
超级管理员 (super_admin)
├── 权限：所有后台功能的完全访问权限
├── 管理范围：可配置所有角色和权限
└── 数据范围：全局

管理员 (admin)
├── 权限：基于公司维度的权限控制
├── 管理范围：公司下所有项目
└── 数据范围：所属公司

项目管理员 (project_admin)
├── 权限：限定于指定项目
├── 管理范围：指定项目
└── 数据范围：授权项目
```

#### 前台权限体系（两级）
```
前台管理员 (user_admin)
├── 权限：配置前台一般用户权限
├── 管理范围：项目内所有用户
└── 数据范围：当前项目

前台用户 (user)
├── 权限：基于分配的权限集
├── 管理范围：无
└── 数据范围：个人或团队
```

---

## 数据库初始化

### 步骤1：执行SQL脚本

```bash
# 连接到MySQL数据库
mysql -u team_reception -p -h 43.160.193.67 team_reception

# 执行权限系统初始化脚本
source /www/wwwroot/livegig.cn/sql/permission_system.sql
```

### 步骤2：验证初始化

```sql
-- 检查表是否创建成功
SHOW TABLES LIKE '%role%';
SHOW TABLES LIKE '%permission%';

-- 检查默认数据
SELECT * FROM roles;
SELECT COUNT(*) FROM permissions;
SELECT * FROM admin_users WHERE username = 'admin';
```

### 初始账号

- **超级管理员账号**：`admin`
- **初始密码**：`admin123`
- **首次登录后请立即修改密码**

---

## 核心组件说明

### 1. PermissionManager 类
**位置**：`/includes/PermissionManager.php`

**主要功能**：
- 权限验证
- 权限获取
- 权限分配
- 日志记录

**核心方法**：
```php
// 检查权限
$permissionManager->hasPermission($userId, $userType, $permissionKey, $projectId);

// 获取用户权限列表
$permissions = $permissionManager->getUserPermissions($userId, $userType, $projectId);

// 获取菜单树
$menuTree = $permissionManager->getUserMenuTree($userId, $userType, $projectId);

// 为角色分配权限
$permissionManager->assignPermissionToRole($roleId, $permissionId, $operatorId, $operatorType);
```

### 2. PermissionMiddleware 类
**位置**：`/includes/PermissionMiddleware.php`

**主要功能**：
- 页面访问控制
- 功能权限验证
- API权限验证
- 数据范围控制

**核心方法**：
```php
// 后台页面权限验证
$middleware->checkAdminPagePermission($requiredPermissions);

// 前台页面权限验证
$middleware->checkUserPagePermission($requiredPermissions);

// 后台API权限验证
$middleware->checkAdminApiPermission($requiredPermissions);

// 检查项目访问权限
$middleware->checkAdminProjectAccess($projectId);
```

### 3. PermissionUI 前端库
**位置**：`/assets/js/permission-ui.js`

**主要功能**：
- 动态控制界面元素
- 权限状态显示
- 菜单生成

**使用示例**：
```javascript
// 检查权限
if (permissionUI.hasPermission('backend:user:edit')) {
    // 显示编辑按钮
}

// 验证操作权限
permissionUI.validateActionPermission('backend:user:delete');
```

---

## 后台权限控制

### 页面级权限控制

#### 方法1：使用辅助函数（推荐）

```php
<?php
// 在页面顶部
require_once '../includes/PermissionMiddleware.php';

// 要求单个权限
requireAdminPermission('backend:project:list');

// 要求多个权限之一
requireAdminPermission(['backend:project:list', 'backend:project:view'], false);

// 要求拥有所有权限
requireAdminPermission(['backend:project:list', 'backend:project:edit'], true);
?>
```

#### 方法2：使用中间件类

```php
<?php
session_start();
require_once '../config/database.php';
require_once '../includes/PermissionMiddleware.php';

$database = new Database();
$db = $database->getConnection();
$middleware = new PermissionMiddleware($db);

// 验证页面权限
$middleware->checkAdminPagePermission('backend:project:list');
?>
```

### 功能级权限控制

在HTML中控制按钮显示：

```php
<?php if (hasAdminPermission('backend:project:add')): ?>
    <button type="button" class="btn btn-primary">
        <i class="bi bi-plus"></i> 添加项目
    </button>
<?php endif; ?>

<?php if (hasAdminPermission('backend:project:edit')): ?>
    <a href="project_edit.php?id=<?php echo $project['id']; ?>" class="btn btn-sm btn-info">
        <i class="bi bi-pencil"></i> 编辑
    </a>
<?php endif; ?>

<?php if (hasAdminPermission('backend:project:delete')): ?>
    <button type="button" class="btn btn-sm btn-danger delete-btn" data-id="<?php echo $project['id']; ?>">
        <i class="bi bi-trash"></i> 删除
    </button>
<?php endif; ?>
```

使用前端权限属性：

```html
<!-- 无权限时隐藏 -->
<button class="btn btn-primary" 
        data-permission="backend:project:add" 
        data-permission-action="hide">
    添加项目
</button>

<!-- 无权限时禁用 -->
<button class="btn btn-danger" 
        data-permission-function="backend:project:delete" 
        data-permission-action="disable">
    删除
</button>

<!-- 需要多个权限之一 -->
<div data-permission="backend:project:view,backend:project:list" 
     data-permission-action="hide">
    项目列表内容
</div>

<!-- 需要所有权限 -->
<div data-permission="backend:project:view,backend:project:edit" 
     data-permission-all="true" 
     data-permission-action="hide">
    高级编辑功能
</div>
```

### API权限控制

```php
<?php
// api/project_api.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/PermissionMiddleware.php';

header('Content-Type: application/json');

$database = new Database();
$db = $database->getConnection();
$middleware = new PermissionMiddleware($db);

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'add_project':
        // 验证权限
        $middleware->checkAdminApiPermission('backend:project:add');
        
        // 执行操作
        // ...
        break;
        
    case 'delete_project':
        // 验证权限
        $middleware->checkAdminApiPermission('backend:project:delete');
        
        // 验证项目访问权限
        $projectId = $_POST['project_id'] ?? 0;
        if (!$middleware->checkAdminProjectAccess($projectId)) {
            echo json_encode(['success' => false, 'message' => '无权访问此项目']);
            exit;
        }
        
        // 执行删除
        // ...
        break;
}
?>
```

### 数据范围控制

```php
<?php
// 获取用户可访问的公司列表
$middleware = new PermissionMiddleware($db);
$companies = $middleware->getAdminAccessibleCompanies();

// 获取用户可访问的项目列表
$projects = $middleware->getAdminAccessibleProjects();

// 过滤特定公司的项目
$companyProjects = $middleware->getAdminAccessibleProjects($companyId);
?>
```

在SQL查询中应用范围限制：

```php
<?php
$scope = $permissionManager->getUserProjectScope($adminUserId);

if ($scope['type'] === 'all') {
    // 超级管理员 - 查询所有项目
    $query = "SELECT * FROM projects ORDER BY name";
    $stmt = $db->query($query);
    
} elseif ($scope['type'] === 'company') {
    // 管理员 - 查询公司下的项目
    $query = "SELECT * FROM projects WHERE company_id = :company_id ORDER BY name";
    $stmt = $db->prepare($query);
    $stmt->execute([':company_id' => $scope['company_id']]);
    
} elseif ($scope['type'] === 'projects') {
    // 项目管理员 - 查询指定项目
    $placeholders = implode(',', array_fill(0, count($scope['project_ids']), '?'));
    $query = "SELECT * FROM projects WHERE id IN ($placeholders) ORDER BY name";
    $stmt = $db->prepare($query);
    $stmt->execute($scope['project_ids']);
}

$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
```

---

## 前台权限控制

### 页面级权限控制

```php
<?php
// user/personnel.php
session_start();
require_once '../config/database.php';
require_once '../includes/PermissionMiddleware.php';

$database = new Database();
$db = $database->getConnection();
$middleware = new PermissionMiddleware($db);

// 验证前台页面权限
$middleware->checkUserPagePermission('frontend:personnel:list');

// 页面内容
?>
```

### 功能级权限控制

```php
<?php if (hasUserPermission('frontend:personnel:add')): ?>
    <a href="batch_add_personnel.php" class="btn btn-primary">
        <i class="bi bi-plus"></i> 添加人员
    </a>
<?php endif; ?>

<?php if (hasUserPermission('frontend:personnel:edit')): ?>
    <button class="btn btn-sm btn-info edit-btn">编辑</button>
<?php endif; ?>
```

### 前台管理员功能

检查是否为前台管理员：

```php
<?php
$middleware = new PermissionMiddleware($db);

if ($middleware->isUserAdmin()) {
    // 显示用户权限配置界面
    ?>
    <div class="admin-panel">
        <h4>用户权限管理</h4>
        <!-- 权限配置界面 -->
    </div>
    <?php
}
?>
```

---

## 权限管理界面

### 访问权限管理页面

1. **后台登录**：使用超级管理员账号登录
   - URL: `/admin/login.php`
   - 账号: `admin`
   - 密码: `admin123`

2. **访问权限管理**：
   - URL: `/admin/permission_management.php`
   - 菜单路径: 系统管理 → 权限管理

### 权限配置流程

#### 1. 选择角色
- 在角色下拉列表中选择要配置的角色
- 系统会显示该角色的当前权限配置

#### 2. 配置权限
- 展开权限树，查看所有可用权限
- 勾选要授予的权限
- 父节点会自动选中所有子节点
- 使用快速操作按钮：全选、取消全选、展开全部、折叠全部

#### 3. 保存配置
- 点击"保存权限配置"按钮
- 系统会保存权限配置并记录操作日志

### 用户管理与角色分配

```php
<?php
// 创建后台用户并分配角色
$query = "INSERT INTO admin_users (username, password, real_name, email, role_id, company_id, status) 
          VALUES (:username, MD5(:password), :real_name, :email, :role_id, :company_id, 1)";

$stmt = $db->prepare($query);
$stmt->execute([
    ':username' => 'manager',
    ':password' => 'password123',
    ':real_name' => '张经理',
    ':email' => 'manager@example.com',
    ':role_id' => 2, // 管理员角色ID
    ':company_id' => 1 // 所属公司ID
]);

// 为项目管理员分配项目
$adminUserId = $db->lastInsertId();
$projectIds = [1, 2, 3]; // 授权的项目ID

foreach ($projectIds as $projectId) {
    $query = "INSERT INTO admin_user_projects (admin_user_id, project_id) VALUES (:admin_id, :project_id)";
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':admin_id' => $adminUserId,
        ':project_id' => $projectId
    ]);
}
?>
```

### 前台用户权限管理

```php
<?php
// 为前台用户分配角色
$query = "INSERT INTO user_roles (user_id, role_id, project_id) VALUES (:user_id, :role_id, :project_id)";
$stmt = $db->prepare($query);
$stmt->execute([
    ':user_id' => $projectUserId,
    ':role_id' => 5, // 前台用户角色ID
    ':project_id' => $projectId
]);

// 为用户设置自定义权限（覆盖角色权限）
$permissionManager->setCustomPermission(
    $projectUserId,
    'project_user',
    $permissionId,
    'grant', // 或 'deny'
    $adminUserId,
    'admin'
);
?>
```

---

## 实施步骤

### 第一阶段：数据库初始化（必须）

```bash
# 1. 执行SQL脚本
mysql -u team_reception -p -h 43.160.193.67 team_reception < /www/wwwroot/livegig.cn/sql/permission_system.sql

# 2. 验证数据
mysql -u team_reception -p -h 43.160.193.67 team_reception -e "SELECT COUNT(*) FROM permissions; SELECT COUNT(*) FROM roles;"
```

### 第二阶段：更新现有页面（逐步实施）

#### 后台页面改造示例

**改造前**：
```php
<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
?>
```

**改造后**：
```php
<?php
session_start();
require_once '../config/database.php';
require_once '../includes/PermissionMiddleware.php';

$database = new Database();
$db = $database->getConnection();

// 页面权限验证
requireAdminPermission('backend:project:list');
?>
```

#### 前台页面改造示例

**改造前**：
```php
<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}
?>
```

**改造后**：
```php
<?php
session_start();
require_once '../config/database.php';
require_once '../includes/PermissionMiddleware.php';

$database = new Database();
$db = $database->getConnection();

// 页面权限验证
requireUserPermission('frontend:personnel:list');
?>
```

### 第三阶段：功能按钮权限控制

在页面中添加权限判断：

```php
<!-- 添加按钮 -->
<?php if (hasAdminPermission('backend:project:add')): ?>
<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
    <i class="bi bi-plus"></i> 添加项目
</button>
<?php endif; ?>

<!-- 编辑按钮 -->
<?php if (hasAdminPermission('backend:project:edit')): ?>
<a href="project_edit.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-info">
    <i class="bi bi-pencil"></i> 编辑
</a>
<?php endif; ?>

<!-- 删除按钮 -->
<?php if (hasAdminPermission('backend:project:delete')): ?>
<button type="button" class="btn btn-sm btn-danger delete-btn" data-id="<?php echo $row['id']; ?>">
    <i class="bi bi-trash"></i> 删除
</button>
<?php endif; ?>
```

### 第四阶段：API权限验证

改造API文件：

```php
<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/PermissionMiddleware.php';

header('Content-Type: application/json');

$database = new Database();
$db = $database->getConnection();
$middleware = new PermissionMiddleware($db);

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'add':
        // 验证权限
        $middleware->checkAdminApiPermission('backend:project:add');
        
        // 执行添加操作
        // ...
        break;
        
    case 'edit':
        // 验证权限
        $middleware->checkAdminApiPermission('backend:project:edit');
        
        // 验证数据访问权限
        $projectId = $_POST['id'] ?? 0;
        if (!$middleware->checkAdminProjectAccess($projectId)) {
            echo json_encode(['success' => false, 'message' => '无权访问']);
            exit;
        }
        
        // 执行编辑操作
        // ...
        break;
        
    case 'delete':
        // 验证权限
        $middleware->checkAdminApiPermission('backend:project:delete');
        
        // 验证数据访问权限
        $projectId = $_POST['id'] ?? 0;
        if (!$middleware->checkAdminProjectAccess($projectId)) {
            echo json_encode(['success' => false, 'message' => '无权访问']);
            exit;
        }
        
        // 执行删除操作
        // ...
        break;
}
?>
```

### 第五阶段：前端动态控制

在页面头部引入权限库：

```html
<!-- 在 header.php 或页面底部添加 -->
<script src="/assets/js/permission-ui.js"></script>
<script>
// 权限初始化完成后的回调
document.addEventListener('DOMContentLoaded', function() {
    permissionUI.init().then(() => {
        console.log('权限系统初始化完成');
    });
});
</script>
```

使用数据属性控制元素：

```html
<!-- 无权限时隐藏 -->
<div data-permission="backend:project:add" data-permission-action="hide">
    <button class="btn btn-primary">添加项目</button>
</div>

<!-- 无权限时禁用 -->
<button class="btn btn-danger" 
        data-permission-function="backend:project:delete" 
        data-permission-action="disable">
    删除项目
</button>

<!-- 需要多个权限 -->
<div data-permission="backend:project:view,backend:project:list" 
     data-permission-all="false">
    项目列表
</div>
```

---

## 常见问题

### Q1: 如何为新建的后台管理员分配角色？

```sql
-- 插入管理员用户（管理员角色）
INSERT INTO admin_users (username, password, real_name, email, role_id, company_id, status) 
VALUES ('manager1', MD5('password123'), '李经理', 'manager1@example.com', 
        (SELECT id FROM roles WHERE role_key = 'admin'), 
        1, -- 公司ID
        1);

-- 插入项目管理员用户
INSERT INTO admin_users (username, password, real_name, email, role_id, status) 
VALUES ('pm1', MD5('password123'), '王项目经理', 'pm1@example.com', 
        (SELECT id FROM roles WHERE role_key = 'project_admin'), 
        1);

-- 为项目管理员分配项目
INSERT INTO admin_user_projects (admin_user_id, project_id) 
VALUES (LAST_INSERT_ID(), 1), 
       (LAST_INSERT_ID(), 2);
```

### Q2: 如何为前台用户分配权限？

```sql
-- 方法1：分配角色（推荐）
INSERT INTO user_roles (user_id, role_id, project_id) 
VALUES (
    (SELECT id FROM project_users WHERE username = 'user1' LIMIT 1),
    (SELECT id FROM roles WHERE role_key = 'user'),
    1 -- 项目ID
);

-- 方法2：使用PHP代码设置自定义权限
<?php
$permissionManager->setCustomPermission(
    $userId,           // 用户ID
    'project_user',    // 用户类型
    $permissionId,     // 权限ID
    'grant',          // grant 或 deny
    $adminUserId,     // 操作者ID
    'admin'           // 操作者类型
);
?>
```

### Q3: 如何查看用户拥有的权限？

```php
<?php
// 后台用户
$permissions = $permissionManager->getUserPermissions($adminUserId, 'admin');

// 前台用户
$permissions = $permissionManager->getUserPermissions($userId, 'project_user', $projectId);

// 打印权限列表
foreach ($permissions as $perm) {
    echo $perm['permission_name'] . ' (' . $perm['permission_key'] . ')' . "\n";
}
?>
```

### Q4: 如何添加新的权限？

```sql
-- 添加父权限
INSERT INTO permissions (permission_name, permission_key, permission_type, resource_type, parent_id, resource_path, menu_icon, sort_order, description) 
VALUES ('财务管理', 'backend:finance', 'page', 'backend', 0, '', 'bi-currency-dollar', 80, '财务管理模块');

-- 添加子权限
SET @finance_id = LAST_INSERT_ID();

INSERT INTO permissions (permission_name, permission_key, permission_type, resource_type, parent_id, resource_path, sort_order) 
VALUES 
('财务列表', 'backend:finance:list', 'page', 'backend', @finance_id, 'finance_list.php', 1),
('财务添加', 'backend:finance:add', 'function', 'backend', @finance_id, '', 2),
('财务编辑', 'backend:finance:edit', 'function', 'backend', @finance_id, '', 3),
('财务删除', 'backend:finance:delete', 'function', 'backend', @finance_id, '', 4);

-- 为超级管理员分配新权限
INSERT INTO role_permissions (role_id, permission_id)
SELECT 
    (SELECT id FROM roles WHERE role_key = 'super_admin'),
    id
FROM permissions
WHERE permission_key LIKE 'backend:finance%';
```

### Q5: 如何查看权限操作日志？

```php
<?php
$logs = $permissionManager->getPermissionLogs(50, 0, [
    'operator_id' => $adminUserId,
    'action_type' => 'permission_grant'
]);

foreach ($logs as $log) {
    echo sprintf(
        "[%s] 操作者#%d 执行了 %s 操作，目标：%s #%d\n",
        $log['created_at'],
        $log['operator_id'],
        $log['action_type'],
        $log['target_type'],
        $log['target_id']
    );
}
?>
```

### Q6: 权限缓存如何管理？

```php
<?php
// 清除权限缓存（在修改权限后调用）
$permissionManager->clearCache();

// 前端刷新权限
?>
<script>
// JavaScript中刷新权限
permissionUI.refresh();
</script>
```

### Q7: 如何实现"不可见即无权限"？

结合后端验证和前端控制：

**后端**：
```php
<?php
// 页面级验证
requireAdminPermission('backend:project:list');

// 功能级验证
if (hasAdminPermission('backend:project:edit')) {
    // 显示编辑按钮
}
?>
```

**前端**：
```html
<!-- 使用data属性自动隐藏 -->
<button data-permission="backend:project:delete" data-permission-action="hide">
    删除
</button>

<!-- 或使用PHP条件渲染 -->
<?php if (hasAdminPermission('backend:project:delete')): ?>
<button>删除</button>
<?php endif; ?>
```

**API验证**：
```php
<?php
// API中验证权限
$middleware->checkAdminApiPermission('backend:project:delete');
?>
```

### Q8: 如何处理权限继承？

当前系统支持通过角色权限和自定义权限实现权限继承：

```php
<?php
// 1. 角色权限（基础权限）
// 用户从其角色继承所有权限

// 2. 自定义权限（覆盖角色权限）
// 可以为特定用户授予或拒绝某个权限，覆盖角色默认权限

// 示例：拒绝某个用户的删除权限（即使其角色有此权限）
$permissionManager->setCustomPermission(
    $userId,
    'admin',
    $deletePermissionId,
    'deny',  // 拒绝
    $operatorId,
    'admin'
);
?>
```

### Q9: 性能优化建议

1. **启用权限缓存**：
```php
<?php
// 在session中缓存用户权限
if (!isset($_SESSION['user_permissions'])) {
    $_SESSION['user_permissions'] = $permissionManager->getUserPermissions($userId, $userType, $projectId);
}
?>
```

2. **使用Redis缓存**（可选）：
```php
<?php
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$cacheKey = "user_permissions:{$userType}:{$userId}";
$permissions = $redis->get($cacheKey);

if (!$permissions) {
    $permissions = $permissionManager->getUserPermissions($userId, $userType, $projectId);
    $redis->setex($cacheKey, 3600, json_encode($permissions)); // 缓存1小时
} else {
    $permissions = json_decode($permissions, true);
}
?>
```

3. **减少数据库查询**：
- 在页面加载时一次性获取所有权限
- 使用JOIN查询减少数据库往返
- 合理使用索引

---

## 总结

本权限管理系统提供了完善的多级权限控制机制，支持：

✅ **前后台分离**：独立的权限体系
✅ **多级管理**：超级管理员、管理员、项目管理员三级后台，前台管理员、用户两级前台
✅ **细粒度控制**：页面、功能、数据三个维度
✅ **动态配置**：可视化权限配置界面
✅ **安全可靠**：前后端双重验证，防止越权访问
✅ **易于扩展**：模块化设计，支持自定义权限
✅ **操作审计**：完整的权限操作日志

**下一步行动**：
1. 执行数据库初始化脚本
2. 使用默认账号登录测试
3. 配置各角色权限
4. 逐步改造现有页面
5. 测试权限控制效果

如有问题，请查阅本文档或联系技术支持。
