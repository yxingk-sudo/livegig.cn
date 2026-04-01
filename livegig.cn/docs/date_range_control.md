# 报餐日期范围控制功能 - 使用说明

## 功能概述

为后台套餐管理页面添加了日期范围控制功能，管理员可以设置报餐管理的显示日期范围，前台页面将根据设置动态显示指定范围内的日期列。

## 数据库迁移

### 已添加的字段

在 `projects` 表中添加了 2 个新字段：

| 字段名 | 类型 | 允许 NULL | 说明 |
|--------|------|---------|------|
| meal_start_date | DATE | YES | 报餐管理开始日期 |
| meal_end_date | DATE | YES | 报餐管理结束日期 |

### 执行迁移

迁移脚本已自动执行，如需重新执行：
```bash
php /www/wwwroot/livegig.cn/migrate_date_range.php
```

## 后台管理功能

### 访问路径
```
/admin/meal_packages.php?project_id=您的项目 ID
```

### 位置
在项目选择后，"餐类型管理"卡片下方，会看到"报餐日期范围控制"卡片。

### 使用方法

1. **设置开始日期**
   - 在"开始日期"输入框中选择日期
   - 留空表示不限制开始日期（使用酒店入住记录）

2. **设置结束日期**
   - 在"结束日期"输入框中选择日期
   - 留空表示不限制结束日期（使用酒店入住记录）

3. **保存配置**
   - 点击"保存日期范围"按钮
   - 系统会立即更新项目配置

4. **重置配置**
   - 将两个日期都留空并保存，即可恢复为使用酒店入住记录

### 配置说明

卡片中提供了详细的使用说明：
- ✅ 如果设置了日期范围，前台将只显示该范围内的日期列
- ✅ 如果留空，将根据酒店入住记录自动显示
- ✅ 可以只设置开始日期或结束日期来限制单边范围

### 示例场景

#### 场景 1：完全限制日期范围
- 开始日期：2024-01-01
- 结束日期：2024-01-31
- 结果：只显示 2024 年 1 月 1 日到 1 月 31 日之间的日期

#### 场景 2：只限制开始日期
- 开始日期：2024-01-01
- 结束日期：（留空）
- 结果：显示 2024 年 1 月 1 日之后的所有日期

#### 场景 3：只限制结束日期
- 开始日期：（留空）
- 结束日期：2024-01-31
- 结果：显示 2024 年 1 月 31 日之前的所有日期

#### 场景 4：不限制（默认）
- 开始日期：（留空）
- 结束日期：（留空）
- 结果：根据酒店入住记录自动显示

## 前台页面显示

### 访问路径
```
/user/meal_management.php
```

### 日期范围过滤逻辑

前台页面会自动读取后台配置的日期范围，并按以下规则显示：

1. **有配置日期范围**
   - 只显示配置范围内的日期
   - 即使有酒店记录，范围外的日期也不显示

2. **未配置日期范围**
   - 根据酒店入住记录自动计算显示日期
   - 保持原有功能不变

### 视觉样式优化

#### 1. 日期大列边框样式

每个日期大列使用加粗蓝色边框进行视觉区分：
- 左边框：3px 蓝色实线
- 右边框：3px 蓝色实线
- 底部边框：2px 蓝色半透明线（通过 ::before 伪元素实现）
- 日期标签：加粗、大写、字母间距增加

#### 2. 餐类型小列边框样式

每个日期内部的餐类型小列使用细灰色边框：
- 右边框：1px 灰色实线
- 最后一个餐类型无边框（避免与下一个日期重叠）
- 背景色：浅灰色
- 字体：稍小、加粗

#### 3. 单元格边框样式

- 右边框：1px 灰色实线
- 下边框：1px 浅灰色实线
- 最后一个单元格无边框

### 层级关系

通过边框样式的明显差异，实现了清晰的视觉层级：

```
┌─────────────────────────────────────────┐
│        日期大列 (3px 蓝色粗边框)          │
│  ┌───────┬───────┬───────┬───────┐      │
│  │早餐   │午餐   │晚餐   │宵夜   │      │
│  │(1px灰 │(1px灰 │(1px灰 │(1px灰 │      │
│  │色细边 │色细边 │色细边 │色细边 │      │
│  │框)    │框)    │框)    │框)    │      │
│  └───────┴───────┴───────┴───────┘      │
└─────────────────────────────────────────┘
```

## 技术实现

### 后台实现

1. **表单提交处理**
   ```php
   if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'update_date_range') {
       // 处理日期范围更新
       $query = "UPDATE projects SET 
                 meal_start_date = :meal_start_date,
                 meal_end_date = :meal_end_date
                 WHERE id = :project_id";
   }
   ```

2. **配置获取**
   ```php
   $dateRangeConfig = [
       'meal_start_date' => null,
       'meal_end_date' => null
   ];
   
   $date_config_query = "SELECT meal_start_date, meal_end_date FROM projects WHERE id = :project_id";
   ```

### 前台实现

1. **日期过滤逻辑**
   ```php
   if (!empty($dateRangeConfig['meal_start_date']) || !empty($dateRangeConfig['meal_end_date'])) {
       $filteredDates = [];
       foreach ($sortedDates as $date) {
           // 检查是否在开始日期之后
           if (!empty($dateRangeConfig['meal_start_date']) && $date < $dateRangeConfig['meal_start_date']) {
               continue;
           }
           // 检查是否在结束日期之前
           if (!empty($dateRangeConfig['meal_end_date']) && $date > $dateRangeConfig['meal_end_date']) {
               continue;
           }
           $filteredDates[$date] = true;
       }
       $sortedDates = array_keys($filteredDates);
   }
   ```

2. **CSS 样式增强**
   ```css
   .date-group-header {
       border-left: 3px solid #0d6efd !important;
       border-right: 3px solid #0d6efd !important;
       position: relative;
   }
   
   .date-group-header::before {
       content: '';
       position: absolute;
       bottom: 0;
       left: 0;
       right: 0;
       height: 2px;
       background-color: #0d6efd;
       opacity: 0.3;
   }
   ```

## 注意事项

1. **数据兼容性**
   - 如果项目中没有设置日期范围，系统会自动使用酒店入住记录
   - 不会影响现有功能和数据

2. **日期格式**
   - 使用 HTML5 的 date 输入类型
   - 存储格式：YYYY-MM-DD
   - 显示格式：根据浏览器语言设置

3. **性能优化**
   - 日期过滤在服务器端完成，减少前端渲染压力
   - 只在必要时进行过滤，不影响无配置的情况

4. **用户体验**
   - 提供清晰的说明文本
   - 支持单边限制（只设置开始或结束日期）
   - 视觉上清晰区分日期大列和餐类型小列

## 相关文件

- 迁移脚本：`/www/wwwroot/livegig.cn/migrate_date_range.php`
- 后台页面：`/www/wwwroot/livegig.cn/admin/meal_packages.php`
- 前台页面：`/www/wwwroot/livegig.cn/user/meal_management.php`
- 数据库配置：`/www/wwwroot/livegig.cn/sql/add_meal_type_settings.sql`

## 常见问题

### Q1: 设置了日期范围但前台没有变化？
**A:** 请检查：
1. 是否正确保存了配置
2. 清除浏览器缓存后刷新页面
3. 确认选择的日期范围内有酒店入住记录

### Q2: 如何恢复为使用酒店入住记录？
**A:** 将开始日期和结束日期都留空，然后保存即可。

### Q3: 可以只限制单日吗？
**A:** 可以，设置开始日期和结束日期为同一天即可。

### Q4: 边框样式不明显怎么办？
**A:** 可以在 `meal_management.php` 的 CSS 中调整：
- `.date-group-header` 的边框粗细和颜色
- `.meal-type-subheader` 的边框样式

---

**最后更新时间：** 2024-01-XX  
**版本：** v1.0
