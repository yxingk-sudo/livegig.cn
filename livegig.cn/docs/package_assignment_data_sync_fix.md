# 套餐分配数据一致性修复

## 问题描述

用户反馈：套餐分配页面 (`/user/meal_package_assign.php`) 显示的套餐信息与后台套餐管理页面 (`/admin/meal_packages.php?action=update_meal_types&project_id=4`) 的套餐信息不一致。

## 问题分析

### 根本原因

两个页面获取套餐数据的 SQL 查询条件不同：

#### 后台页面（正确）
```sql
SELECT mp.*, p.name as project_name, ...
FROM meal_packages mp
LEFT JOIN projects p ON mp.project_id = p.id
LEFT JOIN meal_package_items mpi ON mp.id = mpi.package_id
WHERE mp.project_id = :project_id
AND mp.is_active = :is_active  -- ✅ 过滤了启用状态
ORDER BY mp.meal_type, mpi.sort_order
```

#### 前台页面（错误 - 修复前）
```sql
SELECT * FROM meal_packages 
WHERE project_id = :project_id  -- ❌ 没有过滤 is_active
ORDER BY meal_type, name
```

### 导致的问题

1. **显示已禁用的套餐**
   - 后台已禁用（`is_active = 0`）的套餐仍然在前台显示
   - 用户可能分配到不应该使用的套餐

2. **排序不一致**
   - 后台按 `sort_order` 排序
   - 前台按 `name` 字母顺序排序
   - 导致套餐显示顺序不同

3. **数据不同步**
   - 后台添加/修改/禁用套餐后，前台不能正确反映变化
   - 用户体验差，数据可信度降低

---

## 修复方案

### 修改文件
- **文件路径：** `/www/wwwroot/livegig.cn/user/meal_package_assign.php`
- **修改位置：** 第 86-93 行

### 修改内容

#### 修改前
```php
// 获取所有可用的套餐
$packagesQuery = "SELECT * FROM meal_packages WHERE project_id = :project_id ORDER BY meal_type, name";
$packagesStmt = $db->prepare($packagesQuery);
$packagesStmt->bindParam(':project_id', $projectId);
$packagesStmt->execute();
$allPackages = $packagesStmt->fetch(PDO::FETCH_ASSOC);
```

#### 修改后
```php
// 获取所有可用的套餐（只获取启用的套餐）
$packagesQuery = "SELECT * FROM meal_packages 
                   WHERE project_id = :project_id 
                   AND is_active = 1 
                   ORDER BY meal_type, sort_order, name";
$packagesStmt = $db->prepare($packagesQuery);
$packagesStmt->bindParam(':project_id', $projectId);
$packagesStmt->execute();
$allPackages = $packagesStmt->fetchAll(PDO::FETCH_ASSOC);
```

### 关键改进

1. **添加状态过滤**
   ```sql
   AND is_active = 1  -- 只获取启用的套餐
   ```
   - 确保只显示后台启用的套餐
   - 自动隐藏已禁用的套餐

2. **优化排序逻辑**
   ```sql
   ORDER BY meal_type, sort_order, name
   ```
   - 第一优先级：按餐类型分组（早餐、午餐、晚餐、宵夜）
   - 第二优先级：按自定义排序字段 `sort_order`
   - 第三优先级：按名称字母顺序（兜底策略）

3. **修正 fetch 方法**
   ```php
   // 修改前
   $allPackages = $packagesStmt->fetch(PDO::FETCH_ASSOC);  // ❌ 只获取一条
   
   // 修改后
   $allPackages = $packagesStmt->fetchAll(PDO::FETCH_ASSOC);  // ✅ 获取所有
   ```

---

## 验证结果

### 测试场景

#### 场景 1：后台禁用某个套餐
1. 在后台将"黯然滑蛋叉烧饭"设置为禁用（`is_active = 0`）
2. 刷新前台套餐分配页面
3. ✅ **预期结果：** "黯然滑蛋叉烧饭"不再显示

#### 场景 2：后台调整套餐排序
1. 在后台调整套餐的 `sort_order` 值
2. 刷新前台套餐分配页面
3. ✅ **预期结果：** 套餐显示顺序与后台一致

#### 场景 3：后台添加新套餐
1. 在后台添加新套餐并启用
2. 刷新前台套餐分配页面
3. ✅ **预期结果：** 新套餐出现在对应餐类型中

#### 场景 4：多个餐类型
1. 后台设置了早餐、午餐、晚餐套餐
2. 查看前台套餐分配页面
3. ✅ **预期结果：** 按早餐→午餐→晚餐的顺序显示

---

## 数据一致性对比

### 修改前

| 项目 | 后台页面 | 前台页面 | 是否一致 |
|------|---------|---------|---------|
| 启用的套餐 | ✅ 显示 | ✅ 显示 | ✅ 一致 |
| 禁用的套餐 | ❌ 不显示 | ✅ 显示 | ❌ **不一致** |
| 排序方式 | `sort_order` | `name` | ❌ **不一致** |
| 获取数量 | 全部 | 只有 1 条 | ❌ **严重 Bug** |

### 修改后

| 项目 | 后台页面 | 前台页面 | 是否一致 |
|------|---------|---------|---------|
| 启用的套餐 | ✅ 显示 | ✅ 显示 | ✅ 一致 |
| 禁用的套餐 | ❌ 不显示 | ❌ 不显示 | ✅ 一致 |
| 排序方式 | `sort_order` | `sort_order` | ✅ 一致 |
| 获取数量 | 全部 | 全部 | ✅ 一致 |

---

## 影响范围

### 正面影响

1. **数据准确性** ✅
   - 前台只显示后台启用的套餐
   - 避免分配到已禁用的套餐

2. **用户体验** ✅
   - 套餐显示顺序与后台一致
   - 减少用户困惑

3. **系统可靠性** ✅
   - 修复了只获取 1 条套餐的严重 Bug
   - 所有套餐都能正确显示

### 潜在风险

1. **无影响** ✅
   - 只是增加了过滤条件
   - 不影响已有功能
   - 向后兼容

---

## 技术细节

### 数据库表结构

```sql
CREATE TABLE meal_packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    meal_type ENUM('早餐', '午餐', '晚餐', '宵夜') NOT NULL,
    description TEXT,
    price DECIMAL(10,2),
    is_active TINYINT(1) DEFAULT 1,  -- ← 关键字段
    sort_order INT DEFAULT 0,         -- ← 排序字段
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### is_active 字段说明

| 值 | 含义 | 前台显示 |
|----|------|---------|
| `1` | 启用 | ✅ 显示 |
| `0` | 禁用 | ❌ 不显示 |

### sort_order 字段说明

- **值越小，排序越靠前**
- **默认值：** `0`
- **用途：** 允许管理员自定义套餐显示顺序

---

## 最佳实践建议

### 1. 管理员操作流程

```
1. 访问后台套餐管理
   /admin/meal_packages.php?project_id=4

2. 添加/编辑套餐
   - 设置套餐名称、类型
   - 调整排序顺序（sort_order）
   - 设置启用状态（is_active）

3. 保存后自动生效
   - 前台立即同步显示
   - 无需手动刷新缓存
```

### 2. 套餐管理策略

#### 推荐做法 ✅
- 使用 `is_active` 控制套餐显示
- 不要删除历史套餐，改为禁用
- 通过 `sort_order` 调整热门套餐到前面

#### 不推荐做法 ❌
- 直接删除套餐记录
- 通过修改名称来隐藏套餐
- 忽略排序字段

### 3. 数据维护

```sql
-- 查看某个项目的所有套餐（包括禁用）
SELECT id, name, meal_type, is_active, sort_order
FROM meal_packages
WHERE project_id = 4
ORDER BY meal_type, sort_order;

-- 批量禁用某个餐类型的套餐
UPDATE meal_packages
SET is_active = 0
WHERE project_id = 4 AND meal_type = '宵夜';

-- 调整套餐排序
UPDATE meal_packages
SET sort_order = 10  -- 值越大越靠后
WHERE id = 123;
```

---

## 故障排查

### 如果前台仍然看不到套餐

#### 检查步骤

1. **确认套餐已启用**
   ```sql
   SELECT id, name, is_active 
   FROM meal_packages 
   WHERE project_id = 4;
   ```
   确保 `is_active = 1`

2. **确认项目 ID 正确**
   ```sql
   SELECT id, name, code 
   FROM projects 
   WHERE id = 4;
   ```

3. **清除浏览器缓存**
   ```
   Ctrl + F5 (强制刷新)
   ```

4. **检查 PHP 会话**
   ```php
   // 在浏览器控制台查看
   console.log(sessionStorage);
   ```

### 如果排序不正确

1. **检查 sort_order 值**
   ```sql
   SELECT id, name, sort_order
   FROM meal_packages
   WHERE project_id = 4
   ORDER BY meal_type, sort_order;
   ```

2. **重新设置排序**
   ```sql
   UPDATE meal_packages SET sort_order = 1 WHERE id = 1;
   UPDATE meal_packages SET sort_order = 2 WHERE id = 2;
   UPDATE meal_packages SET sort_order = 3 WHERE id = 3;
   ```

---

## 常见问题

### Q1: 为什么后台能看到某个套餐，前台看不到？
**A:** 可能是因为该套餐的 `is_active = 0`（已禁用）。请在后台将该套餐设置为启用状态。

### Q2: 如何快速切换套餐的启用/禁用状态？
**A:** 
1. 访问后台套餐管理页面
2. 找到对应套餐
3. 点击"启用/禁用"开关
4. 或者编辑套餐，勾选/取消勾选"启用"复选框

### Q3: 排序字段 sort_order 的作用是什么？
**A:** `sort_order` 允许您自定义套餐的显示顺序。数值越小，显示越靠前。如果不设置，默认为 0。

### Q4: 可以同时显示多个套餐吗？
**A:** 可以！前台使用 checkbox 复选框，支持同时选择多个套餐。

### Q5: 修改后需要清除缓存吗？
**A:** 不需要。每次访问前台页面都会实时从数据库读取最新数据。

---

## 相关文档

- [套餐分配功能使用说明](./package_assignment_guide.md)
- [套餐分配 Checkbox 升级说明](./package_assignment_checkbox_upgrade.md)
- [后台套餐管理操作指南](./meal_packages_admin_guide.md)

---

**修复时间：** 2026-03-06  
**版本：** v2.1 (数据一致性修复)  
**修复文件：** `/www/wwwroot/livegig.cn/user/meal_package_assign.php`  
**影响范围：** 套餐分配页面数据显示  
**向后兼容：** ✅ 完全兼容
