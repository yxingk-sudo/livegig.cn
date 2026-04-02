# 新页面开发权限验证检查清单

## 📋 创建新页面时必须完成的权限配置

### ✅ 第一步：选择正确的实现方式

**方式 A：使用 BaseAdminController（推荐）**

```php
<?php
require_once '../includes/BaseAdminController.php';

class YourPageController extends BaseAdminController {
    protected $permissionKey = 'backend:module:function'; // 【必填】设置权限标识
    
    public function init() {
        parent::init(); // 【必填】调用父类方法进行权限验证
        // 页面初始化逻辑
    }
}
```

**方式 B：手动添加权限验证（仅适用于简单页面）**

```php
<?php
session_start();
require_once '../config/database.php';
require_once '../includes/PermissionMiddleware.php';

$database = new Database();
$db = $database->getConnection();
$middleware = new PermissionMiddleware($db);

// 【必填】在引入 header.php 前进行权限验证
$middleware->checkAdminPagePermission('backend:module:function');
```

---

### ✅ 第二步：确定 permissionKey

根据页面功能选择正确的权限标识：

#### 人员管理模块 (backend:personnel)
- [ ] `backend:personnel:list` - 人员列表
- [ ] `backend:personnel:add` - 人员添加
- [ ] `backend:personnel:edit` - 人员编辑
- [ ] `backend:personnel:delete` - 人员删除
- [ ] `backend:personnel:statistics` - 人员统计

#### 项目管理模块 (backend:project)
- [ ] `backend:project:list` - 项目列表
- [ ] `backend:project:add` - 项目添加
- [ ] `backend:project:edit` - 项目编辑
- [ ] `backend:project:delete` - 项目删除
- [ ] `backend:project:department` - 部门管理

#### 报餐管理模块 (backend:meal)
- [ ] `backend:meal:list` - 报餐记录
- [ ] `backend:meal:statistics` - 报餐统计
- [ ] `backend:meal:allowance` - 餐补管理
- [ ] `backend:meal:package` - 套餐管理
- [ ] `backend:meal:approve` - 报餐审核
- [ ] `backend:meal:delete` - 报餐删除

#### 酒店管理模块 (backend:hotel)
- [ ] `backend:hotel:list` - 酒店记录
- [ ] `backend:hotel:statistics` - 酒店统计
- [ ] `backend:hotel:manage` - 酒店管理
- [ ] `backend:hotel:edit` - 酒店编辑
- [ ] `backend:hotel:delete` - 酒店删除

#### 交通管理模块 (backend:transport)
- [ ] `backend:transport:list` - 交通记录
- [ ] `backend:transport:statistics` - 交通统计
- [ ] `backend:transport:fleet` - 车队管理
- [ ] `backend:transport:assign` - 车辆分配
- [ ] `backend:transport:edit` - 交通编辑
- [ ] `backend:transport:delete` - 交通删除

#### 备份管理模块 (backend:backup)
- [ ] `backend:backup:view` - 备份查看
- [ ] `backend:backup:create` - 备份创建
- [ ] `backend:backup:download` - 备份下载
- [ ] `backend:backup:delete` - 备份删除
- [ ] `backend:backup:restore` - 备份恢复

#### 系统管理模块 (backend:system)
- [ ] `backend:system:user` - 用户管理
- [ ] `backend:system:role` - 角色管理
- [ ] `backend:system:permission` - 权限管理
- [ ] `backend:system:config` - 系统配置
- [ ] `backend:system:log` - 操作日志

---

### ✅ 第三步：验证代码

运行以下命令检查新页面是否包含权限验证：

```bash
# 检查是否包含权限验证代码
grep -n "checkAdminPagePermission\|BaseAdminController" admin/your_page.php
```

**预期输出：**
- 应该显示包含权限验证代码的行号

---

### ✅ 第四步：测试验证

1. **未登录访问测试**
   - 清除浏览器缓存
   - 直接访问新页面 URL
   - 预期结果：重定向到登录页

2. **无权限访问测试**
   - 使用普通管理员账号登录
   - 访问新页面
   - 预期结果：显示无权限提示

3. **正常访问测试**
   - 使用拥有对应权限的管理员账号登录
   - 访问新页面
   - 预期结果：正常显示页面内容

---

### ⚠️ 常见错误示例

#### ❌ 错误 1：忘记设置 permissionKey
```php
class YourPageController extends BaseAdminController {
    // 错误：没有设置 permissionKey
    public function init() {
        parent::init();
    }
}
```

#### ❌ 错误 2：忘记调用 parent::init()
```php
class YourPageController extends BaseAdminController {
    protected $permissionKey = 'backend:module:function';
    
    public function init() {
        // 错误：没有调用 parent::init()
        $this->loadData();
    }
}
```

#### ❌ 错误 3：权限验证顺序错误
```php
<?php
require_once 'includes/header.php'; // 错误：先引入了 header
require_once '../includes/PermissionMiddleware.php';
$middleware->checkAdminPagePermission('...'); // 后验证权限
```

正确顺序应该是：
```php
<?php
require_once '../includes/PermissionMiddleware.php';
$middleware->checkAdminPagePermission('...'); // 先验证权限
require_once 'includes/header.php'; // 后引入 header
```

---

### 📝 附录：完整示例代码

```php
<?php
/**
 * 人员列表页面示例
 */

// 1. 引入基础控制器
require_once '../includes/BaseAdminController.php';

// 2. 创建页面控制器类
class PersonnelListPage extends BaseAdminController {
    
    // 3. 【必填】设置权限标识
    protected $permissionKey = 'backend:personnel:list';
    
    // 4. 【必填】调用父类 init 方法
    public function init() {
        parent::init();
        
        // 5. 页面初始化逻辑
        $this->loadPersonnel();
    }
    
    // 6. 加载数据
    private function loadPersonnel() {
        global $personnelList;
        
        $query = "SELECT * FROM personnel ORDER BY created_at DESC";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $personnelList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 7. 渲染页面
    public function render() {
        global $page_title, $active_page;
        
        $page_title = '人员列表';
        $active_page = 'personnel_list';
        
        require_once 'includes/header.php';
        ?>
        
        <div class="container-fluid py-4">
            <!-- 页面内容 -->
        </div>
        
        <?php
        require_once 'includes/footer.php';
    }
}

// 8. 运行页面
$page = new PersonnelListPage();
$page->init();
$page->render();
```

---

### 🎯 快速参考

| 场景 | 解决方案 |
|------|---------|
| 创建新页面 | 复制 `page_template.php` |
| 不确定 permissionKey | 查看 `permissions` 表或咨询项目负责人 |
| AJAX 接口需要权限验证 | 同样使用 `BaseAdminController` |
| 特殊页面不需要权限 | 设置 `$requirePermission = false` |
| 需要多个权限 | 在 `init()` 中手动调用多次 `checkAdminPagePermission()` |

---

**最后更新**: 2026-04-02  
**维护者**: 系统开发团队
