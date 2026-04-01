# 报餐管理表格页面使用说明

## 功能概述

报餐管理表格页面 (`meal_management.php`) 提供了一个直观的表格界面，用于管理项目人员的餐次选择。该页面基于人员的酒店入住记录自动生成日期范围，支持实时保存餐次选择。

## 主要特性

### 1. 数据源
- **人员信息**：从 `personnel` 表和 `project_department_personnel` 表获取项目人员及其部门信息
- **住宿天数**：从 `hotel_reports` 表获取人员的入住日期（check_in_date）和退房日期（check_out_date）
- **已有报餐**：从 `meal_reports` 表读取已有的报餐记录并显示选中状态

### 2. 表格布局
```
| 序号 | 姓名 | 部门 | [日期 1] | [日期 1] | [日期 1] | [日期 2] | ...
|      |      |      | 早餐     | 午餐     | 晚餐     | 早餐     |
|------|------|------|----------|----------|----------|----------|
| 1    | 张三 | 技术部| ☑️       | ☐        | ☑️       | ☐        |
| 2    | 李四 | 设计部| ☐        | ☑️       | ☑️       | ☑️       |
```

- **固定列**：序号、姓名、部门列固定在左侧，横向滚动时保持可见
- **动态日期列**：根据所有人的入住日期自动生成，每周分组显示
- **餐次子列**：每个日期下分三个子列：早餐、午餐、晚餐
- **禁用状态**：如果某天不在人员入住范围内，对应单元格显示为灰色不可用

### 3. 交互功能

#### 3.1 点选功能
- 点击复选框即可选择/取消选择餐次
- **实时保存**：每次点击都会自动保存到数据库
- **防抖处理**：避免频繁 AJAX 请求（300ms 延迟）
- **视觉反馈**：保存过程中显示加载提示，成功后显示成功消息

#### 3.2 全选功能
- **全选按钮**：选中表格中所有可用（非禁用）的餐次
- **取消全选按钮**：取消所有已选的餐次
- 操作前会弹出确认对话框

#### 3.3 按部门选择
- 通过部门下拉框选择特定部门
- 点击后自动选中该部门下所有人员的可用餐次
- 操作前会弹出确认对话框
- 选择后自动重置下拉框

### 4. 统计信息
页面顶部实时显示三项统计：
- **总人数**：项目中的总人员数
- **总天数**：所有人涉及的不重复日期总数
- **已选餐次**：当前已选择的餐次数量

## 文件结构

```
user/
├── meal_management.php          # 主页面
└── ajax/
    ├── save_meal_selection.php  # 保存接口
    └── get_meal_reports.php     # 查询接口
```

## API 接口

### 1. save_meal_selection.php（保存餐次选择）

**请求方法**: POST  
**Content-Type**: application/json

**请求参数**:
```json
{
    "personnel_id": 16,
    "meal_date": "2025-08-11",
    "meal_type": "早餐",
    "is_selected": true,
    "project_id": 7
}
```

**响应格式**:
```json
{
    "success": true,
    "message": "报餐成功",
    "action": "inserted"
}
```

**action 说明**:
- `inserted`: 新插入记录
- `exists`: 记录已存在，无需重复插入
- `deleted`: 删除记录

### 2. get_meal_reports.php（查询报餐记录）

**请求方法**: GET 或 POST

**请求参数**:
- `start_date`: 开始日期（可选），格式：YYYY-MM-DD
- `end_date`: 结束日期（可选），格式：YYYY-MM-DD
- `personnel_id`: 人员 ID（可选）

**响应格式**:
```json
{
    "success": true,
    "count": 10,
    "reports": [
        {
            "id": 1,
            "personnel_id": 16,
            "meal_date": "2025-08-11",
            "meal_type": "早餐",
            "meal_count": 1,
            "special_requirements": "",
            "created_at": "2025-08-11 10:30:00"
        }
    ],
    "filters": {
        "project_id": 7,
        "start_date": "2025-08-11",
        "end_date": "2025-08-20",
        "personnel_id": null
    }
}
```

## 使用流程

### 步骤 1：访问页面
在浏览器中访问：`http://your-domain/user/meal_management.php`

### 步骤 2：查看表格
- 页面自动加载项目人员及其入住日期范围
- 已有的报餐记录会自动显示为选中状态

### 步骤 3：选择餐次
- **单个选择**：直接点击复选框
- **批量选择**：使用"全选"或"按部门选择"功能
- **取消选择**：使用"取消全选"功能或逐个取消

### 步骤 4：验证结果
- 观察右上角"已选餐次"计数器
- 刷新页面，已选餐次应保持选中状态

## 性能优化

### 1. 防抖处理
- 避免频繁 AJAX 请求，设置 300ms 延迟
- 连续点击时只发送最后一次请求

### 2. 固定列
- 使用 CSS `position: sticky` 实现固定列
- 支持大量日期列的横向滚动

### 3. 数据库索引建议
为提高查询性能，建议在以下字段上创建索引：

```sql
-- meal_reports 表
CREATE INDEX idx_project_personnel_date ON meal_reports(project_id, personnel_id, meal_date);
CREATE INDEX idx_meal_date ON meal_reports(meal_date);

-- hotel_reports 表
CREATE INDEX idx_project_personnel ON hotel_reports(project_id, personnel_id);
CREATE INDEX idx_check_in_date ON hotel_reports(check_in_date);
```

## 注意事项

### 1. 权限要求
- 用户必须登录且有项目权限
- 未登录或未选择项目会返回错误提示

### 2. 数据验证
- 日期格式必须为 YYYY-MM-DD
- 餐类型必须是：早餐、午餐、晚餐、宵夜
- 人员 ID 必须是有效数字

### 3. 兼容性
- 现代浏览器（Chrome、Firefox、Safari、Edge）
- 不支持 IE11（由于使用了 ES6+ 特性）

### 4. 已知限制
- 如果日期超过 60 天，表格可能会比较宽，建议配合横向滚动使用
- 如果人员超过 200 人，建议考虑分页或虚拟滚动优化

## 常见问题

### Q1: 为什么某些单元格是灰色的？
A: 灰色单元格表示该人员在该日期没有住宿记录，因此不能选择餐次。

### Q2: 点击后没有反应怎么办？
A: 
1. 检查浏览器控制台是否有错误
2. 确认网络连接正常
3. 确认有项目权限
4. 检查 PHP 错误日志

### Q3: 如何修改餐类型？
A: 目前支持早餐、午餐、晚餐、宵夜四种类型。如需添加其他类型，需要：
1. 修改 `meal_reports` 表的枚举类型
2. 更新页面中的 `$validMealTypes` 数组
3. 更新表格生成逻辑

### Q4: 可以自定义表格样式吗？
A: 可以，修改 `<style>` 标签中的 CSS 代码即可。建议使用 Espire 设计风格。

## 开发者调试

### 开启调试模式
在浏览器控制台执行：
```javascript
localStorage.setItem('debug', 'true');
```

### 查看网络请求
打开浏览器开发者工具 → Network 标签 → 筛选 `save_meal_selection.php`

### 查看数据库记录
```sql
SELECT * FROM meal_reports 
WHERE project_id = 7 
AND meal_date BETWEEN '2025-08-11' AND '2025-08-20'
ORDER BY meal_date, personnel_id;
```

## 版本历史

### v1.0 (2026-03-06)
- ✅ 初始版本发布
- ✅ 表格布局展示
- ✅ 实时保存功能
- ✅ 全选/部门选功能
- ✅ 已有记录加载
- ✅ 统计信息显示
- ✅ 防抖优化
- ✅ 固定列支持

## 技术支持

如有问题或建议，请联系开发团队。
