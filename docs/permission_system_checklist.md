# 权限管理系统 - 快速实施检查清单

## ✅ 第一步：数据库初始化

```bash
# 执行以下命令初始化权限系统数据库
mysql -u team_reception -p -h 43.160.193.67 team_reception < /www/wwwroot/livegig.cn/sql/permission_system.sql
```

**验证结果**：
- [ ] roles表已创建，包含5个默认角色
- [ ] permissions表已创建，包含所有权限数据
- [ ] admin_users表已创建，包含默认超级管理员账号
- [ ] 其他相关表已创建（role_permissions, user_roles等）

**默认账号信息**：
- 用户名：`admin`
- 密码：`admin123`
- 角色：超级管理员

---

## ✅ 第二步：验证核心文件

确认以下文件已创建：

**后端核心文件**：
- [ ] `/www/wwwroot/livegig.cn/includes/PermissionManager.php`
- [ ] `/www/wwwroot/livegig.cn/includes/PermissionMiddleware.php`

**前端文件**：
- [ ] `/www/wwwroot/livegig.cn/assets/js/permission-ui.js`

**API文件**：
- [ ] `/www/wwwroot/livegig.cn/api/get_user_permissions.php`
- [ ] `/www/wwwroot/livegig.cn/admin/api/role_permission_api.php`

**管理界面**：
- [ ] `/www/wwwroot/livegig.cn/admin/permission_management.php`

**文档**：
- [ ] `/www/wwwroot/livegig.cn/docs/permission_system_guide.md`
- [ ] `/www/wwwroot/livegig.cn/sql/permission_system.sql`

---

## ✅ 第三步：登录测试

1. **登录后台**：
   - 访问：`http://your-domain/admin/login.php`
   - 使用默认账号登录
   - [ ] 成功登录

2. **访问权限管理页面**：
   - 访问：`http://your-domain/admin/permission_management.php`
   - [ ] 页面正常显示
   - [ ] 能看到角色列表
   - [ ] 能展开权限树

3. **测试权限配置**：
   - 选择一个角色
   - 修改权限配置
   - 点击保存
   - [ ] 保存成功

---

## ✅ 第四步：页面权限改造示例

### 后台页面改造

**修改前**（`/admin/projects.php`）：
```php
<?php
session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
?>
```

**修改后**：
```php
<?php
session_start();
require_once '../config/database.php';
require_once '../includes/PermissionMiddleware.php';

$database = new Database();
$db = $database->getConnection();

// 验证页面权限
requireAdminPermission('backend:project:list');
?>
```

### 前台页面改造

**修改前**（`/user/personnel.php`）：
```php
<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}
?>
```

**修改后**：
```php
<?php
session_start();
require_once '../config/database.php';
require_once '../includes/PermissionMiddleware.php';

$database = new Database();
$db = $database->getConnection();

// 验证页面权限
requireUserPermission('frontend:personnel:list');
?>
```

**改造检查清单**：
- [ ] 已改造至少1个后台页面
- [ ] 已改造至少1个前台页面
- [ ] 测试无权限访问被正确拦截

---

## ✅ 第五步：功能按钮权限控制

在页面中添加权限判断：

```php
<!-- 添加按钮 -->
<?php if (hasAdminPermission('backend:project:add')): ?>
<button class="btn btn-primary">添加项目</button>
<?php endif; ?>

<!-- 编辑按钮 -->
<?php if (hasAdminPermission('backend:project:edit')): ?>
<button class="btn btn-info">编辑</button>
<?php endif; ?>

<!-- 删除按钮 -->
<?php if (hasAdminPermission('backend:project:delete')): ?>
<button class="btn btn-danger">删除</button>
<?php endif; ?>
```

**检查清单**：
- [ ] 已在至少1个页面添加按钮权限控制
- [ ] 测试无权限用户看不到按钮
- [ ] 测试有权限用户能看到按钮

---

## ✅ 第六步：API权限验证

改造API文件示例：

```php
<?php
// /admin/api/project_api.php
session_start();
require_once '../../config/database.php';
require_once '../../includes/PermissionMiddleware.php';

header('Content-Type: application/json');

$database = new Database();
$db = $database->getConnection();
$middleware = new PermissionMiddleware($db);

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'delete':
        // 验证权限
        if (!$middleware->checkAdminApiPermission('backend:project:delete')) {
            exit; // 权限验证失败会自动返回错误
        }
        
        // 执行删除操作
        // ...
        break;
}
?>
```

**检查清单**：
- [ ] 已改造至少1个API文件
- [ ] 测试无权限用户调用API被拒绝
- [ ] 测试有权限用户调用API成功

---

## ✅ 第七步：用户与角色管理

### 创建管理员用户

```sql
-- 创建公司管理员
INSERT INTO admin_users (username, password, real_name, email, role_id, company_id, status) 
VALUES (
    'manager1', 
    MD5('password123'), 
    '张经理', 
    'manager@example.com',
    (SELECT id FROM roles WHERE role_key = 'admin'),
    1, -- 公司ID
    1
);

-- 创建项目管理员
INSERT INTO admin_users (username, password, real_name, email, role_id, status) 
VALUES (
    'pm1', 
    MD5('password123'), 
    '李项目经理', 
    'pm@example.com',
    (SELECT id FROM roles WHERE role_key = 'project_admin'),
    1
);

-- 为项目管理员分配项目
INSERT INTO admin_user_projects (admin_user_id, project_id) 
VALUES 
    (LAST_INSERT_ID(), 1),
    (LAST_INSERT_ID(), 2);
```

### 创建前台用户角色

```sql
-- 为前台用户分配角色
INSERT INTO user_roles (user_id, role_id, project_id) 
VALUES (
    1, -- 前台用户ID
    (SELECT id FROM roles WHERE role_key = 'user'),
    1  -- 项目ID
);
```

**检查清单**：
- [ ] 已创建至少1个管理员用户
- [ ] 已创建至少1个项目管理员用户
- [ ] 已为前台用户分配角色
- [ ] 测试不同角色登录后看到不同菜单

---

## ✅ 第八步：测试完整流程

### 测试场景1：超级管理员

1. [ ] 使用admin账号登录
2. [ ] 能访问所有后台页面
3. [ ] 能看到所有功能按钮
4. [ ] 能配置其他角色的权限
5. [ ] 能查看权限操作日志

### 测试场景2：管理员

1. [ ] 创建一个管理员账号
2. [ ] 为其分配公司
3. [ ] 配置管理员角色权限
4. [ ] 使用该账号登录
5. [ ] 只能看到本公司的项目
6. [ ] 能管理本公司的项目和用户

### 测试场景3：项目管理员

1. [ ] 创建一个项目管理员账号
2. [ ] 为其分配1-2个项目
3. [ ] 使用该账号登录
4. [ ] 只能看到被分配的项目
5. [ ] 不能访问其他项目

### 测试场景4：前台用户

1. [ ] 创建前台用户账号
2. [ ] 分配前台用户角色
3. [ ] 配置该角色的权限
4. [ ] 使用该账号登录
5. [ ] 只能看到有权限的菜单和功能
6. [ ] 无权限的菜单被隐藏

### 测试场景5：前台管理员

1. [ ] 创建前台管理员账号
2. [ ] 分配前台管理员角色
3. [ ] 使用该账号登录
4. [ ] 能配置其他前台用户的权限
5. [ ] 能看到用户权限管理界面

---

## ✅ 第九步：性能优化（可选）

### 启用会话缓存

```php
<?php
// 在用户登录后缓存权限到session
if (!isset($_SESSION['user_permissions'])) {
    $_SESSION['user_permissions'] = $permissionManager->getUserPermissions(
        $_SESSION['admin_user_id'], 
        'admin'
    );
}

// 在权限修改后清除缓存
if ($permissionsUpdated) {
    unset($_SESSION['user_permissions']);
    $permissionManager->clearCache();
}
?>
```

**检查清单**：
- [ ] 已启用权限缓存
- [ ] 权限修改后能正确清除缓存
- [ ] 页面加载速度提升

---

## ✅ 第十步：生产环境部署

### 安全检查

- [ ] 修改默认超级管理员密码
- [ ] 删除或禁用测试账号
- [ ] 检查所有API文件都有权限验证
- [ ] 检查所有页面都有权限控制
- [ ] 启用HTTPS（生产环境）
- [ ] 配置会话超时时间
- [ ] 启用操作日志审计

### 备份与回滚

- [ ] 备份原有数据库
- [ ] 备份原有代码文件
- [ ] 准备回滚脚本
- [ ] 测试回滚流程

### 文档与培训

- [ ] 阅读完整实施指南
- [ ] 培训系统管理员
- [ ] 培训项目管理员
- [ ] 准备用户手册

---

## 常见问题快速解决

### Q: 无法访问权限管理页面
**解决**：检查是否使用超级管理员账号登录，或检查权限配置

### Q: 权限修改不生效
**解决**：清除权限缓存，重新登录

### Q: API返回权限不足
**解决**：检查API文件是否添加了权限验证，检查用户角色是否有对应权限

### Q: 前端按钮不自动隐藏
**解决**：检查是否引入了permission-ui.js，检查data-permission属性是否正确

---

## 完成情况统计

- [ ] 数据库初始化完成
- [ ] 核心文件验证完成
- [ ] 登录测试完成
- [ ] 页面改造完成（至少1个后台页面，1个前台页面）
- [ ] 功能按钮控制完成
- [ ] API权限验证完成
- [ ] 用户角色管理完成
- [ ] 完整流程测试完成
- [ ] 性能优化完成（可选）
- [ ] 生产部署准备完成

**完成度**：___/10

**遇到的问题**：
_________________________________
_________________________________
_________________________________

**解决方案**：
_________________________________
_________________________________
_________________________________

---

## 技术支持

如遇到问题，请：
1. 查阅完整实施指南：`/docs/permission_system_guide.md`
2. 检查错误日志：PHP错误日志、数据库日志
3. 使用浏览器开发者工具查看网络请求
4. 联系技术支持团队

**祝实施顺利！** 🎉
