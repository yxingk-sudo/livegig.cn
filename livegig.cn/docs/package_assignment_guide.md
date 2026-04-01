# 套餐分配功能 - 使用说明

## 功能概述

套餐分配功能允许前台管理员根据后台设置的可用套餐，为每个日期和餐类型分配一个或多个套餐。例如：周五午餐可以分配"有米双拼饭"和"黯然滑蛋叉烧饭"，周六分配"白切胡须鸡饭"等。

该功能与报餐管理系统无缝集成，可以统计套餐分配与实际报餐数量的关联数据。

## 数据库结构

### 表名：`meal_package_assignments`

存储每日每餐的套餐分配信息。

#### 字段说明

| 字段名 | 类型 | 说明 |
|--------|------|------|
| `id` | INT | 主键 ID，自增 |
| `project_id` | INT | 项目 ID（外键） |
| `meal_date` | DATE | 用餐日期 |
| `meal_type` | ENUM('早餐','午餐','晚餐','宵夜') | 餐类型 |
| `package_id` | INT | 套餐 ID（外键） |
| `created_at` | TIMESTAMP | 创建时间 |
| `updated_at` | TIMESTAMP | 更新时间 |

#### 索引设计

- `idx_project_date`: (project_id, meal_date) - 项目和日期查询优化
- `idx_meal_type`: (meal_type) - 餐类型查询优化
- `idx_package`: (package_id) - 套餐查询优化

#### 外键约束

- `project_id` → `projects(id)` ON DELETE CASCADE
- `package_id` → `meal_packages(id)` ON DELETE CASCADE

## 文件结构

### 主要文件

1. **主页面**
   - 路径：`/www/wwwroot/livegig.cn/user/meal_package_assign.php`
   - 功能：显示套餐分配表格，支持多选操作

2. **AJAX 接口**
   - 路径：`/www/wwwroot/livegig.cn/user/ajax/save_package_assignment.php`
   - 功能：保存套餐分配数据到数据库

3. **导航菜单**
   - 修改文件：`/www/wwwroot/livegig.cn/user/includes/header.php`
   - 添加位置："报餐"下拉菜单 → "套餐分配"

4. **数据库迁移**
   - 路径：`/www/wwwroot/livegig.cn/migrate_package_assignments.php`
   - 功能：创建套餐分配表

## 使用方法

### 前置条件

1. ✅ 已在后台 (`/admin/meal_packages.php`) 添加至少一个套餐
2. ✅ 已有酒店入住记录（用于生成日期列表）
3. ✅ 已设置报餐日期范围（可选，用于过滤显示的日期）

### 访问路径

```
http://your-domain.com/user/meal_package_assign.php
```

或通过导航菜单：
1. 登录系统
2. 点击顶部导航栏的 **"📋 报餐"**
3. 选择 **"🧺 套餐分配"**

### 操作步骤

#### 方式一：手动分配

1. **打开套餐分配页面**
   - 页面显示按日期分组的表格
   - 行：早餐、午餐、晚餐、宵夜
   - 列：各个日期

2. **为每个餐次选择套餐**
   - 点击单元格中的下拉框
   - 按住 `Ctrl` 键可多选（例如同时选择两个套餐）
   - 每个餐次可以选择 0 个或多个套餐

3. **保存分配**
   - 点击"保存所有分配"按钮
   - 系统自动保存所有更改
   - 显示保存结果提示

#### 方式二：复制前一天

1. **点击"复制前一天"按钮**
   - 系统自动将前一天的套餐分配复制到下一天
   - 例如：将周五的分配复制到周六

2. **调整分配**
   - 根据需要修改复制后的分配
   - 点击"保存所有分配"

### 界面元素说明

#### 1. 顶部英雄区
```
┌─────────────────────────────────────┐
│ 🍽️ 套餐分配                         │
│ 📍 项目名称                          │
│ [← 返回报餐管理]                    │
└─────────────────────────────────────┘
```

#### 2. 操作控制区
```
┌─────────────────────────────────────┐
│ ⚙️ 操作控制                         │
│ [✓ 保存所有分配] [📋 复制前一天]    │
│                                     │
│ 总天数：7    套餐数：12    已分配：8│
└─────────────────────────────────────┘
```

#### 3. 分配表格
```
┌───┬──────┬──────────┬──────────┬──────────┐
│序 │餐次  │01-15(一) │01-16(二) │01-17(三) │
│号 │      │          │          │          │
├───┼──────┼──────────┼──────────┼──────────┤
│1  │早餐  │[下拉框]  │[下拉框]  │[下拉框]  │
├───┼──────┼──────────┼──────────┼──────────┤
│2  │午餐  │[下拉框]  │[下拉框]  │[下拉框]  │
├───┼──────┼──────────┼──────────┼──────────┤
│3  │晚餐  │[下拉框]  │[下拉框]  │[下拉框]  │
└───┴──────┴──────────┴──────────┴──────────┘
```

#### 4. 下拉框多选
```
┌─────────────────────┐
│ -- 请选择套餐 --    │
│ ✓ 有米双拼饭        │  ← 已选
│ ✓ 黯然滑蛋叉烧饭    │  ← 已选
│   白切胡须鸡饭      │
│   桶子豉油鸡饭      │
└─────────────────────┘
```

## 技术实现

### 数据存储格式

每个单元格的多个选择存储为多条记录：

```sql
-- 示例：2024-01-15 午餐分配了两个套餐
INSERT INTO meal_package_assignments 
(project_id, meal_date, meal_type, package_id) 
VALUES 
(4, '2024-01-15', '午餐', 1),  -- 有米双拼饭
(4, '2024-01-15', '午餐', 2);  -- 黯然滑蛋叉烧饭
```

### AJAX 保存逻辑

```javascript
// 收集所有分配数据
const assignments = [];
document.querySelectorAll('.package-select').forEach(select => {
    const mealDate = select.dataset.mealDate;
    const mealType = select.dataset.mealType;
    const selectedPackageIds = Array.from(select.selectedOptions)
        .filter(opt => opt.value !== '')
        .map(opt => parseInt(opt.value));
    
    if (selectedPackageIds.length > 0) {
        assignments.push({
            meal_date: mealDate,
            meal_type: mealType,
            package_ids: selectedPackageIds
        });
    }
});

// 发送到服务器
fetch('ajax/save_package_assignment.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
        project_id: projectId,
        assignments: assignments
    })
});
```

### 服务器端处理

```php
// 1. 删除旧数据
DELETE FROM meal_package_assignments 
WHERE project_id = :project_id 
AND meal_date = :meal_date 
AND meal_type = :meal_type;

// 2. 插入新数据
INSERT INTO meal_package_assignments 
(project_id, meal_date, meal_type, package_id) 
VALUES (:project_id, :meal_date, :meal_type, :package_id);
```

## 数据统计与集成

### 与报餐管理的关联

套餐分配数据可以与报餐管理页面的数据进行关联统计：

#### 查询示例

```sql
-- 查询某日期的套餐分配和实际报餐数量
SELECT 
    mpa.meal_date,
    mpa.meal_type,
    mp.name AS package_name,
    COUNT(mr.id) AS meal_report_count
FROM meal_package_assignments mpa
LEFT JOIN meal_packages mp ON mpa.package_id = mp.id
LEFT JOIN meal_reports mr 
    ON mr.project_id = mpa.project_id
    AND mr.meal_date = mpa.meal_date
    AND mr.meal_type = mpa.meal_type
WHERE mpa.project_id = 4
AND mpa.meal_date = '2024-01-15'
GROUP BY mpa.meal_date, mpa.meal_type, mp.name;
```

### 未来扩展功能

1. **套餐用量统计**
   - 根据报餐人数和分配的套餐，计算每种套餐的预计用量
   - 生成采购清单

2. **套餐满意度调查**
   - 收集用户对每个套餐的评价
   - 优化套餐分配策略

3. **自动推荐**
   - 根据历史数据推荐套餐组合
   - 避免重复分配相同套餐

## 注意事项

### 1. 权限要求
- ✅ 必须是已登录用户
- ✅ 必须有项目访问权限
- ✅ 建议管理员权限

### 2. 数据一致性
- 套餐必须在后台已启用
- 如果套餐被删除，相关分配记录会自动删除（外键级联）
- 项目删除时，所有分配记录会自动删除

### 3. 多选限制
- 使用 HTML5 原生 `<select multiple>` 实现多选
- 用户需要按住 `Ctrl` 键（Windows）或 `Command` 键（Mac）进行多选
- 建议每个餐次不超过 3 个套餐

### 4. 性能考虑
- 大量日期时建议使用日期范围过滤
- 定期清理过期的分配记录
- 为数据库表添加适当的索引

## 常见问题

### Q1: 为什么看不到套餐选项？
**A:** 可能是因为：
1. 后台没有添加套餐
2. 套餐未启用
3. 套餐的餐类型与当前行不匹配

**解决方法：**
- 访问 `/admin/meal_packages.php?project_id=X` 添加套餐

### Q2: 如何修改已分配的套餐？
**A:** 
1. 找到对应的日期和餐次
2. 在下拉框中重新选择套餐
3. 点击"保存所有分配"

### Q3: 能否只分配一个套餐？
**A:** 可以！多选是可选的，您也可以只选择一个套餐。

### Q4: "复制前一天"功能如何使用？
**A:** 
1. 确保前一天已经有分配
2. 点击"复制前一天"按钮
3. 系统会自动将前一天的分配复制到后续日期
4. 可以根据需要调整
5. 点击"保存所有分配"

### Q5: 如何查看某个套餐被分配了哪些天？
**A:** 可以通过 SQL 查询：
```sql
SELECT meal_date, meal_type 
FROM meal_package_assignments 
WHERE package_id = X 
ORDER BY meal_date;
```

## 开发指南

### 添加新功能

#### 1. 批量分配周末
```php
// 为所有周末分配相同的套餐
function assignWeekendPackages($weekendPackageIds) {
    // 获取所有周末日期
    $weekendDates = getWeekendDates();
    
    foreach ($weekendDates as $date) {
        assignPackage($date, '午餐', $weekendPackageIds);
    }
}
```

#### 2. 套餐冲突检测
```php
// 检测同一天是否有重复套餐
function detectPackageConflicts($date, $packageIds) {
    // 检查是否有重复
    $duplicates = array_diff_assoc($packageIds, array_unique($packageIds));
    
    if (!empty($duplicates)) {
        return false;
    }
    
    return true;
}
```

### API 扩展示例

#### 获取套餐分配统计
```php
// GET /api/get_package_stats.php?date=2024-01-15
$response = [
    'success' => true,
    'data' => [
        'total_packages' => 12,
        'assigned_meals' => 8,
        'packages_by_type' => [
            '早餐' => 2,
            '午餐' => 3,
            '晚餐' => 3
        ]
    ]
];
```

---

**最后更新时间：** 2026-03-06  
**版本：** v1.0  
**作者：** AI Assistant  
**相关文件：**
- `/www/wwwroot/livegig.cn/user/meal_package_assign.php`
- `/www/wwwroot/livegig.cn/user/ajax/save_package_assignment.php`
- `/www/wwwroot/livegig.cn/migrate_package_assignments.php`
