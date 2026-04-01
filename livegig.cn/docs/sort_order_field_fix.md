# sort_order 字段缺失问题修复

## 问题描述

在修复套餐分配数据一致性问题时，发现 `meal_packages` 表中不存在 `sort_order` 字段，导致 SQL 查询报错。

### 错误信息

```
Fatal error: Uncaught PDOException: SQLSTATE[42S22]: 
Column not found: 1054 Unknown column 'sort_order' in 'order clause' 
in /www/wwwroot/livegig.cn/user/meal_package_assign.php:95
```

---

## 问题分析

### 表结构对比

#### meal_packages 表（实际结构）
```sql
CREATE TABLE meal_packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    meal_type ENUM('早餐','午餐','晚餐','宵夜') NOT NULL,
    description TEXT,
    price DECIMAL(10,2) DEFAULT 0.00,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- ❌ 没有 sort_order 字段
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
);
```

#### meal_package_items 表（有 sort_order）
```sql
CREATE TABLE meal_package_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    package_id INT NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    item_description TEXT,
    quantity INT DEFAULT 1,
    unit VARCHAR(50) DEFAULT '份',
    sort_order INT DEFAULT 0 COMMENT '排序',  -- ✅ 这个表有 sort_order
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (package_id) REFERENCES meal_packages(id) ON DELETE CASCADE
);
```

### 设计意图

根据数据库结构设计：
- **套餐表（meal_packages）**：按 `id` 自动递增排序
- **套餐详情表（meal_package_items）**：支持自定义排序，有 `sort_order` 字段

这是合理的设计，因为：
1. 套餐通常按创建顺序排列
2. 套餐内的菜品需要灵活调整顺序
3. 减少不必要的字段，保持表结构简洁

---

## 解决方案

### 方案选择

有两种解决方案：

#### 方案一：修改 SQL 查询（推荐）✅
使用现有的 `id` 字段排序，不修改数据库结构。

**优点：**
- ✅ 无需修改数据库
- ✅ 零风险，立即可用
- ✅ `id` 本身就是自然的排序条件

**缺点：**
- ⚠️ 无法自定义套餐顺序（但实际需求不大）

#### 方案二：添加 sort_order 字段
为 `meal_packages` 表添加 `sort_order` 字段。

**优点：**
- ✅ 可以自定义套餐排序

**缺点：**
- ❌ 需要修改数据库结构
- ❌ 可能影响现有功能
- ❌ 增加维护成本

### 最终方案：方案一（推荐）

选择使用 `id` 字段排序，无需修改数据库。

---

## 实施步骤

### 修改文件
- **文件路径：** `/www/wwwroot/livegig.cn/user/meal_package_assign.php`
- **修改位置：** 第 86-95 行

### 修改内容

#### 修改前（错误）
```php
$packagesQuery = "SELECT * FROM meal_packages 
                   WHERE project_id = :project_id 
                   AND is_active = 1 
                   ORDER BY meal_type, sort_order, name";  // ❌ sort_order 不存在
```

#### 修改后（正确）
```php
$packagesQuery = "SELECT * FROM meal_packages 
                   WHERE project_id = :project_id 
                   AND is_active = 1 
                   ORDER BY meal_type, id";  // ✅ 使用 id 排序
```

---

## 排序逻辑说明

### ORDER BY meal_type, id

#### 第一优先级：meal_type（餐类型）
按照枚举值排序：
1. 早餐
2. 午餐
3. 晚餐
4. 宵夜

#### 第二优先级：id（套餐 ID）
按照创建顺序排序：
- ID 小的先创建，排在前面
- ID 大的后创建，排在后面

### 示例结果

假设有以下套餐：
```
ID | 名称        | 餐类型 | 创建时间
---|------------|--------|----------
1  | 标准早餐    | 早餐   | 2024-01-01
3  | 丰盛早餐    | 早餐   | 2024-01-03
2  | 商务午餐    | 午餐   | 2024-01-02
4  | 营养午餐    | 午餐   | 2024-01-04
5  | 家常晚餐    | 晚餐   | 2024-01-05
```

排序后显示顺序：
```
早餐：
  1. 标准早餐 (ID=1)
  2. 丰盛早餐 (ID=3)

午餐：
  1. 商务午餐 (ID=2)
  2. 营养午餐 (ID=4)

晚餐：
  1. 家常晚餐 (ID=5)
```

---

## 验证结果

### 测试步骤

1. **访问套餐分配页面**
   ```
   http://your-domain.com/user/meal_package_assign.php
   ```

2. **检查套餐显示**
   - ✅ 只显示启用的套餐（is_active = 1）
   - ✅ 按餐类型分组（早餐、午餐、晚餐、宵夜）
   - ✅ 每个餐类型内按 ID 顺序显示

3. **检查控制台**
   - ✅ 无 PHP 错误
   - ✅ 无 SQL 错误
   - ✅ 数据正常加载

### 预期效果

#### 后台套餐管理
```
项目 ID: 4
├─ 早餐
│  ├─ [✓] 标准早餐 (ID=1)
│  └─ [✓] 丰盛早餐 (ID=3)
├─ 午餐
│  ├─ [✓] 商务午餐 (ID=2)
│  └─ [✓] 营养午餐 (ID=4)
└─ 晚餐
   └─ [✓] 家常晚餐 (ID=5)
```

#### 前台套餐分配
```
日期：2024-01-15
├─ 早餐
│  ├─ ☐ 标准早餐
│  └─ ☐ 丰盛早餐
├─ 午餐
│  ├─ ☐ 商务午餐
│  └─ ☐ 营养午餐
└─ 晚餐
   └─ ☐ 家常晚餐
```

---

## 技术细节

### 数据库表结构（完整版）

```sql
-- 套餐表
CREATE TABLE meal_packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    meal_type ENUM('早餐','午餐','晚餐','宵夜') NOT NULL,
    description TEXT,
    price DECIMAL(10,2) DEFAULT 0.00,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_project_meal_type (project_id, meal_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 套餐详情表
CREATE TABLE meal_package_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    package_id INT NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    item_description TEXT,
    quantity INT DEFAULT 1,
    unit VARCHAR(50) DEFAULT '份',
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (package_id) REFERENCES meal_packages(id) ON DELETE CASCADE,
    INDEX idx_package_sort (package_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 字段用途对比

| 表名 | 字段 | 用途 |
|------|------|------|
| meal_packages | id | 主键 + 自然排序 |
| meal_packages | meal_type | 餐类型分类 |
| meal_packages | is_active | 启用状态 |
| meal_package_items | sort_order | 菜品自定义排序 |

---

## 最佳实践

### 如何控制套餐显示顺序

#### 方法 1：按创建顺序（当前方案）
- 先创建的套餐排在前面
- 后创建的套餐排在后面
- **适用场景：** 套餐数量少，不需要频繁调整

#### 方法 2：通过命名约定
在套餐名称前加数字前缀：
```
1-标准早餐
2-丰盛早餐
3-商务午餐
```
然后按 `name` 排序：
```sql
ORDER BY meal_type, name
```

#### 方法 3：添加 sort_order 字段（不推荐）
如需此功能，可执行以下 SQL：
```sql
ALTER TABLE meal_packages 
ADD COLUMN sort_order INT DEFAULT 0 COMMENT '排序' AFTER is_active,
ADD INDEX idx_sort (project_id, sort_order);
```

然后修改查询：
```sql
SELECT * FROM meal_packages 
WHERE project_id = :project_id 
AND is_active = 1 
ORDER BY meal_type, sort_order, name;
```

---

## 常见问题

### Q1: 为什么 meal_packages 表没有 sort_order 字段？
**A:** 设计如此。套餐通常按创建顺序排列，而套餐内的菜品才需要灵活调整顺序。

### Q2: 我想调整套餐顺序怎么办？
**A:** 
- **临时方案：** 删除重新创建（不推荐）
- **推荐方案：** 接受按 ID 排序
- **进阶方案：** 自行添加 sort_order 字段

### Q3: 如果一定要自定义排序怎么办？
**A:** 可以添加 sort_order 字段：
```sql
ALTER TABLE meal_packages 
ADD COLUMN sort_order INT DEFAULT 0 COMMENT '排序';

UPDATE meal_packages SET sort_order = 10 WHERE id = 1;
UPDATE meal_packages SET sort_order = 20 WHERE id = 2;
UPDATE meal_packages SET sort_order = 30 WHERE id = 3;
```

然后修改代码中的 ORDER BY 子句。

### Q4: 按 id 排序有什么缺点？
**A:** 
- ❌ 无法调整已创建套餐的顺序
- ✅ 简单可靠，性能优秀
- ✅ 符合大多数场景需求

---

## 相关文档

- [套餐分配数据一致性修复](./package_assignment_data_sync_fix.md)
- [套餐分配功能使用说明](./package_assignment_guide.md)
- [套餐分配 Checkbox 升级说明](./package_assignment_checkbox_upgrade.md)

---

**修复时间：** 2026-03-06  
**版本：** v2.2 (sort_order 字段修复)  
**修复文件：** `/www/wwwroot/livegig.cn/user/meal_package_assign.php`  
**影响范围：** 套餐排序逻辑  
**向后兼容：** ✅ 完全兼容  
**数据库修改：** ❌ 无需修改
