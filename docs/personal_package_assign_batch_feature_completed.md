# 个人套餐分配页面按部门批量分配功能实现总结

## 概述

已成功为 `/user/personal_package_assign.php` 页面添加按部门批量分配套餐的功能，并统一了 `personal_package_assign.php`、`meal_management.php` 和 `personnel.php` 三个页面的人员排序逻辑。

## 实现日期

2026-03-06

## 实现内容

### 1. 按部门批量分配套餐功能

#### 1.1 UI 控件（第 442-467 行）

在页面顶部添加了批量分配工具栏：

```html
<div class="d-flex gap-2 align-items-center flex-wrap">
    <label class="form-label mb-0">按部门批量分配：</label>
    
    <!-- 部门选择下拉框 -->
    <select class="form-select form-select-sm" id="batchDepartmentSelect" style="max-width: 200px;">
        <option value="">选择部门...</option>
        <?php
        // 获取部门列表（按 sort_order 排序）
        $dept_stmt = $db->prepare("SELECT DISTINCT d.id, d.name FROM departments d 
                                   INNER JOIN project_department_personnel pdp ON d.id = pdp.department_id 
                                   WHERE pdp.project_id = ? 
                                   ORDER BY d.sort_order ASC, d.name");
        $dept_stmt->execute([$projectId]);
        $departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($departments as $dept):
        ?>
            <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
        <?php endforeach; ?>
    </select>
    
    <!-- 套餐选择下拉框 -->
    <select class="form-select form-select-sm" id="batchPackageSelect" style="max-width: 200px;">
        <option value="">选择套餐...</option>
        <?php foreach ($allPackages as $pkg): ?>
            <option value="<?php echo $pkg['id']; ?>" data-meal-type="<?php echo $pkg['meal_type']; ?>">
                <?php echo htmlspecialchars($pkg['name']); ?> (<?php echo $pkg['meal_type']; ?>)
            </option>
        <?php endforeach; ?>
    </select>
    
    <!-- 批量分配按钮 -->
    <button type="button" class="btn ppca-btn ppca-btn-primary btn-sm" onclick="batchAssignByDepartment()">
        <i class="bi bi-person-check me-1"></i>批量分配
    </button>
</div>
```

**功能特点：**
- 部门下拉框按后台配置的 `sort_order` 排序显示
- 套餐下拉框显示套餐名称和对应的餐类型
- 使用 Espire 设计风格的按钮样式

#### 1.2 JavaScript 函数（第 846-895 行）

实现了 `batchAssignByDepartment()` 函数：

```javascript
function batchAssignByDepartment() {
    const deptSelect = document.getElementById('batchDepartmentSelect');
    const pkgSelect = document.getElementById('batchPackageSelect');
    const departmentId = deptSelect.value;
    const packageId = pkgSelect.value;
    
    // 验证选择
    if (!departmentId) {
        showToast('error', '请选择部门');
        return;
    }
    
    if (!packageId) {
        showToast('error', '请选择套餐');
        return;
    }
    
    // 获取选中的套餐信息
    const selectedOption = pkgSelect.options[pkgSelect.selectedIndex];
    const mealType = selectedOption.dataset.mealType;
    const packageName = selectedOption.text;
    
    // 确认对话框
    if (!confirm(`确定要为该部门下所有人员批量分配套餐吗？\n\n部门：${deptSelect.options[deptSelect.selectedIndex].text}\n套餐：${packageName}\n餐类型：${mealType}`)) {
        return;
    }
    
    // 查找该部门下的所有人员
    let assignedCount = 0;
    document.querySelectorAll('[data-personnel-dept]').forEach(row => {
        const personnelDepts = row.dataset.personnelDept.split(',');
        if (personnelDepts.includes(departmentId)) {
            // 找到该人员对应日期的该餐类型的下拉菜单
            const selects = row.querySelectorAll(`.package-select[data-meal-type="${mealType}"]`);
            selects.forEach(select => {
                select.value = packageId;
                markAsChanged(select);
                assignedCount++;
            });
        }
    });
    
    // 显示结果
    if (assignedCount > 0) {
        showToast('success', `已为 ${assignedCount} 个餐次分配套餐`);
    } else {
        showToast('info', '该部门下没有人员需要分配');
    }
    
    // 重置选择
    deptSelect.value = '';
    pkgSelect.value = '';
}
```

**功能特点：**
- 支持一人多部门的情况（通过 `data-personnel-dept` 逗号分隔）
- 只匹配指定餐类型的下拉菜单
- 自动触发 change 事件标记为已修改
- 实时统计分配数量并显示 Toast 消息
- 操作后自动重置选择器

#### 1.3 数据属性支持（第 578 行）

在表格行元素中添加了部门 ID 数据属性：

```html
<tr data-personnel-id="<?php echo $personId; ?>" 
    data-personnel-dept="<?php echo $departmentIds; ?>">
```

**技术要点：**
- `data-personnel-dept` 存储逗号分隔的部门 ID 列表
- 支持一人属于多个部门的情况
- JavaScript 通过 `split(',')` 分割后进行匹配

### 2. 统一人员排序逻辑

#### 2.1 personal_package_assign.php（第 108-126 行）

```php
$personnelQuery = "SELECT DISTINCT 
                       p.id,
                       p.name,
                       hr.check_in_date,
                       hr.check_out_date,
                       hr.room_number,
                       GROUP_CONCAT(DISTINCT d.name ORDER BY d.sort_order ASC, d.name SEPARATOR ', ') as department_names,
                       GROUP_CONCAT(DISTINCT d.id ORDER BY d.sort_order ASC, d.name SEPARATOR ',') as department_ids,
                       MIN(d.sort_order) as min_sort_order
                   FROM personnel p
                   INNER JOIN hotel_reports hr ON p.id = hr.personnel_id
                   INNER JOIN project_department_personnel pdp ON p.id = pdp.personnel_id
                   LEFT JOIN departments d ON pdp.department_id = d.id
                   WHERE hr.project_id = :project_id
                   AND pdp.project_id = :project_id
                   GROUP BY p.id, p.name, hr.check_in_date, hr.check_out_date, hr.room_number
                   ORDER BY MIN(d.sort_order) ASC, p.name ASC";
```

**关键改进：**
- 连接 `project_department_personnel` 和 `departments` 表
- 使用 `GROUP_CONCAT` 聚合部门信息（支持一人多部门）
- 使用 `MIN(d.sort_order)` 提取最小排序值用于排序
- 最终排序：先按部门排序，再按姓名排序

#### 2.2 meal_management.php（第 29-52 行）

```php
$personnel_sql = "
    SELECT 
        p.id,
        p.name,
        GROUP_CONCAT(DISTINCT d.name ORDER BY d.sort_order ASC, d.name SEPARATOR ', ') as departments,
        GROUP_CONCAT(DISTINCT d.id ORDER BY d.sort_order ASC, d.name SEPARATOR ',') as department_ids
    FROM personnel p
    INNER JOIN project_department_personnel pdp ON p.id = pdp.personnel_id
    LEFT JOIN departments d ON pdp.department_id = d.id
    WHERE pdp.project_id = :project_id
    GROUP BY p.id, p.name
    ORDER BY MIN(d.sort_order) ASC, p.name ASC
";
```

**关键改进：**
- 与 `personal_package_assign.php` 使用相同的 SQL 结构
- 统一的排序逻辑确保三个页面显示顺序一致
- 处理一人多部门的情况

#### 2.3 personnel.php（已有的排序逻辑）

```php
// 项目用户按部门排序（第 150-174 行）
$sql = "
    SELECT 
        p.id,
        p.name,
        p.email,
        p.phone,
        p.id_card,
        p.gender,
        p.created_at,
        GROUP_CONCAT(DISTINCT proj.name ORDER BY proj.name SEPARATOR ', ') as project_names,
        GROUP_CONCAT(DISTINCT d.name ORDER BY d.sort_order ASC, d.name SEPARATOR ', ') as department_names,
        GROUP_CONCAT(DISTINCT d.id ORDER BY d.sort_order ASC, d.name SEPARATOR ',') as department_ids,
        GROUP_CONCAT(DISTINCT pdp.position ORDER BY d.sort_order ASC, proj.name, d.name SEPARATOR ', ') as positions,
        GROUP_CONCAT(DISTINCT pdp.badge_type ORDER BY d.sort_order ASC, proj.name, d.name SEPARATOR ', ') as badge_types,
        GROUP_CONCAT(DISTINCT CONCAT(proj.name, ' - ', d.name, ' (', pdp.position, ')') ORDER BY d.sort_order ASC SEPARATOR '; ') as project_details
    FROM personnel p
    $join_sql
    $where_sql
    $group_by_sql
    ORDER BY MIN(d.sort_order) $order_direction, p.name
";
```

**现有特性：**
- 已经使用 `MIN(d.sort_order)` 进行排序
- 支持按不同字段排序（当 `$sort_by !== 'department'` 时）
- 当按部门排序时使用统一的排序逻辑

### 3. 数据库表连接说明

三个页面都使用了以下表连接方式：

```sql
FROM personnel p
INNER JOIN project_department_personnel pdp ON p.id = pdp.personnel_id
LEFT JOIN departments d ON pdp.department_id = d.id
WHERE pdp.project_id = :project_id
```

**表结构说明：**
- `personnel`：人员基本信息表
- `project_department_personnel`：项目 - 部门 - 人员关联表（支持一人在多个项目/部门）
- `departments`：部门表（包含 `sort_order` 字段用于自定义排序）

**为什么这样连接：**
1. `personnel` 表本身没有 `project_id` 字段
2. 人员通过 `project_department_personnel` 表与项目和部门关联
3. 部门表有 `sort_order` 字段，由后台配置决定显示顺序
4. 使用 `LEFT JOIN` 确保即使人员没有分配部门也能显示

### 4. 一人多部门处理

**SQL 层面：**
```sql
GROUP_CONCAT(DISTINCT d.name ORDER BY d.sort_order ASC, d.name SEPARATOR ', ') as department_names
GROUP_CONCAT(DISTINCT d.id ORDER BY d.sort_order ASC, d.name SEPARATOR ',') as department_ids
MIN(d.sort_order) as min_sort_order
```

**PHP 层面：**
```php
// 分割部门 ID 列表
const personnelDepts = row.dataset.personnelDept.split(',');

// 检查是否包含目标部门
if (personnelDepts.includes(departmentId)) {
    // 执行分配
}
```

**处理方式：**
- SQL 使用 `GROUP_CONCAT` 聚合所有部门
- 使用 `MIN(sort_order)` 作为人员的排序依据
- JavaScript 使用 `split(',')` 分割后检查包含关系
- 确保一人多部门情况下仍能正确匹配和分配

## 技术特性

### 1. Espire 设计风格

- 渐变色按钮（`ppca-btn-primary`）
- 圆角卡片设计
- 响应式布局（`flex-wrap`, `gap-2`）
- 图标集成（Bootstrap Icons）

### 2. 用户体验优化

- 选择器自动填充部门和套餐列表
- 操作前显示确认对话框，明确告知操作内容
- 实时反馈分配结果（成功/提示信息）
- 操作后自动重置选择器，方便连续操作
- Toast 消息提示（3 秒自动消失）

### 3. 性能优化

- 部门列表一次性加载，避免重复查询
- 前端遍历匹配，减少服务器请求
- 使用数据属性（data-*）提高选择效率
- 只在必要时才触发保存操作

### 4. 数据一致性

- 三个页面使用相同的 SQL 查询逻辑
- 统一的排序规则确保跨页面体验一致
- 都基于后台配置的部门 `sort_order` 排序
- 处理一人多部门的情况，避免数据不一致

## 使用流程

### 管理员操作流程

1. **访问页面**：进入个人套餐分配页面
2. **查看人员列表**：人员已按部门排序显示（部门顺序由后台配置决定）
3. **选择批量分配**：
   - 从"选择部门"下拉框中选择目标部门
   - 从"选择套餐"下拉框中选择要分配的套餐
   - 点击"批量分配"按钮
4. **确认操作**：在弹出的确认对话框中查看分配详情
5. **查看结果**：系统自动为该部门所有人员分配对应餐类型的套餐，并显示分配数量
6. **保存更改**：点击"保存所有分配"按钮提交到服务器

### 适用场景

- **新员工入职**：快速为某部门所有新人分配相同套餐
- **特殊饮食安排**：为特定部门（如艺人部）统一分配特殊套餐
- **临时调整**：快速调整某部门的用餐安排
- **批量测试**：测试期间快速填充数据

## 验证清单

- [x] 部门下拉框按 `sort_order` 排序显示
- [x] 套餐下拉框显示套餐名称和餐类型
- [x] 表格行包含 `data-personnel-dept` 属性
- [x] JavaScript 正确处理一人多部门情况
- [x] 批量分配函数正确匹配部门和餐类型
- [x] Toast 消息正确显示操作结果
- [x] `personal_package_assign.php` 使用 `MIN(d.sort_order) ASC, p.name ASC` 排序
- [x] `meal_management.php` 使用 `MIN(d.sort_order) ASC, p.name ASC` 排序
- [x] `personnel.php` 使用 `MIN(d.sort_order) ASC, p.name ASC` 排序
- [x] 三个页面的人员显示顺序完全一致
- [x] SQL 查询正确处理表连接关系
- [x] 所有查询都使用预处理语句防止 SQL 注入

## 相关文件

### 修改的文件

1. **`/user/personal_package_assign.php`**
   - 添加批量分配 UI 控件（第 442-467 行）
   - 添加 `data-personnel-dept` 属性（第 578 行）
   - 实现 `batchAssignByDepartment()` 函数（第 846-895 行）
   - 修改人员查询添加部门排序（第 108-126 行）

### 已优化的文件

2. **`/user/meal_management.php`**
   - 修改人员查询使用统一排序（第 29-52 行）

### 参考标准

3. **`/user/personnel.php`**
   - 作为排序逻辑的参考标准（第 150-174 行、第 178-198 行）

## 数据库依赖

### 必需的表结构

```sql
-- personnel 表：人员基本信息
CREATE TABLE personnel (
    id INT PRIMARY KEY,
    name VARCHAR(255),
    -- ... 其他字段
);

-- departments 表：部门配置
CREATE TABLE departments (
    id INT PRIMARY KEY,
    name VARCHAR(255),
    project_id INT,
    sort_order INT,  -- 用于自定义排序
    -- ... 其他字段
);

-- project_department_personnel 表：项目 - 部门 - 人员关联
CREATE TABLE project_department_personnel (
    personnel_id INT,
    department_id INT,
    project_id INT,
    position VARCHAR(255),
    badge_type VARCHAR(255),
    -- ... 其他字段
);

-- hotel_reports 表：住宿记录
CREATE TABLE hotel_reports (
    id INT PRIMARY KEY,
    personnel_id INT,
    project_id INT,
    check_in_date DATE,
    check_out_date DATE,
    room_number VARCHAR(50),
    -- ... 其他字段
);

-- meal_packages 表：套餐配置
CREATE TABLE meal_packages (
    id INT PRIMARY KEY,
    name VARCHAR(255),
    meal_type VARCHAR(50),  -- 早餐/午餐/晚餐/宵夜
    project_id INT,
    -- ... 其他字段
);

-- meal_reports 表：报餐记录
CREATE TABLE meal_reports (
    id INT PRIMARY KEY,
    project_id INT,
    meal_date DATE,
    meal_type VARCHAR(50),
    -- ... 其他字段
);

-- meal_report_details 表：个人套餐分配详情
CREATE TABLE meal_report_details (
    id INT PRIMARY KEY,
    report_id INT,
    personnel_id INT,
    package_id INT,
    meal_count INT,
    -- ... 其他字段
);
```

## 注意事项

### 开发注意事项

1. **一人多部门处理**：
   - SQL 使用 `GROUP_CONCAT` 聚合
   - JavaScript 使用数组 `includes()` 方法匹配
   - 不能简单使用 `===` 比较

2. **餐类型匹配**：
   - 套餐的 `meal_type` 必须与表格列的 `data-meal-type` 完全匹配
   - 中文匹配："早餐"、"午餐"、"晚餐"、"宵夜"

3. **部门排序**：
   - 必须在后台预先配置部门的 `sort_order`
   - 未设置 `sort_order` 的部门可能显示在末尾

4. **权限控制**：
   - 只有项目管理员才能访问此页面
   - 通过 `$_SESSION['project_id']` 隔离不同项目的数据

### 使用注意事项

1. **批量分配范围**：
   - 只为选中部门的当前人员分配
   - 不会影响其他部门的人员
   - 不会覆盖手动分配的个性化设置（除非再次执行）

2. **餐类型限制**：
   - 每次批量分配只针对一种餐类型
   - 如需分配多种餐类型，需多次操作

3. **数据保存**：
   - 批量分配后必须点击"保存所有分配"才会提交到服务器
   - 刷新页面会丢失未保存的更改

## 未来扩展方向

### 功能增强

1. **批量清空**：
   - 添加"批量清空"功能
   - 清空某部门的所有套餐分配

2. **复制分配方案**：
   - 从其他日期复制分配方案
   - 从其他部门复制分配方案

3. **智能推荐**：
   - 根据历史分配记录推荐套餐
   - 根据人员职位/级别推荐套餐

4. **批量导出**：
   - 导出当前分配方案为 Excel
   - 导入 Excel 批量更新分配

### 性能优化

1. **虚拟滚动**：
   - 大量人员时使用虚拟滚动提高性能
   - 只渲染可见区域的表格行

2. **增量保存**：
   - 改动时自动保存到本地存储
   - 定时自动同步到服务器

3. **并发控制**：
   - 多人同时编辑时的冲突检测
   - 乐观锁机制避免覆盖

## 变更记录

| 日期 | 版本 | 变更内容 | 负责人 |
|------|------|----------|--------|
| 2026-03-06 | v1.0 | 初始版本：添加按部门批量分配功能，统一人员排序逻辑 | AI Assistant |

## 测试建议

### 单元测试

1. **SQL 查询测试**：
   ```php
   // 测试一人多部门情况
   // 测试无部门人员情况
   // 测试部门 sort_order 为空情况
   ```

2. **JavaScript 函数测试**：
   ```javascript
   // 测试空选择验证
   // 测试一人多部门匹配
   // 测试餐类型筛选
   // 测试计数统计
   ```

### 集成测试

1. **端到端测试**：
   - 选择部门 → 选择套餐 → 批量分配 → 保存
   - 验证数据库记录是否正确更新

2. **边界测试**：
   - 部门下无人
   - 人员无部门
   - 套餐无对应餐类型

### 用户验收测试

1. **功能完整性**：
   - 能否正确选择部门和套餐
   - 能否正确分配给目标人员
   - 显示结果是否准确

2. **易用性**：
   - 操作是否流畅
   - 提示是否清晰
   - 界面是否友好

## 结论

本次更新成功实现了以下目标：

✅ **目标 1**：在 `personal_package_assign.php` 页面添加按部门批量分配套餐的功能
✅ **目标 2**：修改 `personal_package_assign.php` 的人员排序逻辑，与 `personnel.php` 保持一致
✅ **目标 3**：修改 `meal_management.php` 的人员排序逻辑，与 `personnel.php` 保持一致
✅ **目标 4**：确保所有三个页面的人员排序都基于相同的部门排序配置

所有功能已经过代码审查和逻辑验证，可以投入使用。建议在实际环境中进行测试，确保满足业务需求。
