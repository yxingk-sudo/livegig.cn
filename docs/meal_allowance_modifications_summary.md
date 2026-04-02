# 餐费补助明细功能修改总结

## 修改历史

### 1. 支持显示没有酒店记录的人员并修复重复记录问题
**文件修改：**
- [/admin/meal_allowance.php](file:///www/wwwroot/livegig.cn/admin/meal_allowance.php)
- [/user/meal_allowance.php](file:///www/wwwroot/livegig.cn/user/meal_allowance.php)

**修改内容：**
1. 使用 LEFT JOIN 替代 INNER JOIN 获取所有项目人员
2. 添加数据处理逻辑，处理没有酒店记录的人员
3. 添加去重处理，解决重复记录问题

### 2. 房间数列改为房晚数列
**文件修改：**
- [/admin/meal_allowance.php](file:///www/wwwroot/livegig.cn/admin/meal_allowance.php)
- [/user/meal_allowance.php](file:///www/wwwroot/livegig.cn/user/meal_allowance.php)

**修改内容：**
1. 将"房间数"列改为"房晚数"列
2. 显示项目人员的实际房晚数（天数 × 房间数）

### 3. 日期筛选默认值设置及首次加载不应用过滤
**文件修改：**
- [/admin/meal_allowance.php](file:///www/wwwroot/livegig.cn/admin/meal_allowance.php)
- [/user/meal_allowance.php](file:///www/wwwroot/livegig.cn/user/meal_allowance.php)

**修改内容：**
1. 筛选条件的开始日期和结束日期默认设置为当前所选项目的开始日期和结束日期
2. 页面首次加载时，餐费补助明细表默认显示该项目的所有人员数据，不应用日期范围进行过滤

### 4. 修复PDO参数绑定错误
**文件修改：**
- [/admin/meal_allowance.php](file:///www/wwwroot/livegig.cn/admin/meal_allowance.php)
- [/user/meal_allowance.php](file:///www/wwwroot/livegig.cn/user/meal_allowance.php)

**修改内容：**
1. 修复了PDO参数绑定错误，只有当日期筛选条件都存在且不为空时才绑定日期参数

### 5. 用户填写天数后不自动创建酒店记录
**文件修改：**
- [/admin/ajax_create_hotel_report.php](file:///www/wwwroot/livegig.cn/admin/ajax_create_hotel_report.php)

**修改内容：**
1. 在用户填写天数后不自动创建酒店记录（入住日期、退房日期、默认酒店）

### 6. 支持将天数设置为0
**文件修改：**
- [/admin/ajax_update_hotel_report_days.php](file:///www/wwwroot/livegig.cn/admin/ajax_update_hotel_report_days.php)

**修改内容：**
1. 允许将天数设置为0，但仍阻止负数输入

## 修改效果

1. 在 [/admin/meal_allowance.php](file:///www/wwwroot/livegig.cn/admin/meal_allowance.php) 页面中，即使项目人员没有酒店入住记录，也会显示在餐费补助明细中
2. 修复了餐费补助明细表格最后两条记录重复显示的问题
3. 将"房间数"列改为"房晚数"列，显示项目人员的实际房晚数（天数 × 房间数）
4. 前端 [/user/meal_allowance.php](file:///www/wwwroot/livegig.cn/user/meal_allowance.php) 如实显示管理后端 [/admin/meal_allowance.php](file:///www/wwwroot/livegig.cn/admin/meal_allowance.php) 调整的数据
5. 筛选条件的开始日期和结束日期默认设置为当前所选项目的开始日期和结束日期
6. 页面首次加载时，餐费补助明细表默认显示该项目的所有人员数据，不应用日期范围进行过滤
7. 修复了 PDO 参数绑定错误
8. 在用户填写天数后不自动创建酒店记录（入住日期、退房日期、默认酒店）
9. 支持将天数设置为0

## 技术实现细节

### 数据查询优化
使用 LEFT JOIN 查询确保即使没有酒店记录的人员也能显示在结果中：

```sql
SELECT DISTINCT
    p.id as personnel_id,
    p.name as personnel_name,
    p.meal_allowance,
    d.name as department_name,
    d.sort_order as department_sort_order,
    hr.id as report_id,
    hr.check_in_date,
    hr.check_out_date,
    hr.hotel_name,
    hr.room_type,
    CASE 
        WHEN hr.check_in_date IS NOT NULL AND hr.check_out_date IS NOT NULL THEN
            DATEDIFF(
                LEAST(hr.check_out_date, :date_to),
                GREATEST(hr.check_in_date, :date_from)
            ) + 1
        ELSE 0
    END AS days_count,
    CASE 
        WHEN hr.room_type IN ('双床房', '双人房', '套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' 
        THEN 1 
        ELSE COALESCE(hr.room_count, 1)
    END as effective_room_count
FROM personnel p
LEFT JOIN project_department_personnel pdp ON (p.id = pdp.personnel_id AND pdp.project_id = :project_id)
LEFT JOIN departments d ON pdp.department_id = d.id
LEFT JOIN hotel_reports hr ON (p.id = hr.personnel_id AND hr.project_id = :project_id AND 
      hr.check_in_date <= :date_to AND hr.check_out_date >= :date_from)
WHERE pdp.project_id = :project_id_param
ORDER BY d.sort_order ASC, p.name, hr.check_in_date
```

### 去重处理
通过创建唯一标识符来解决重复记录问题：

```php
// 创建唯一标识符
$key = $record['personnel_id'] . '_' . ($record['report_id'] ?? 'no_report') . '_' . 
       ($record['check_in_date'] ?? 'no_date') . '_' . ($record['check_out_date'] ?? 'no_date');

if (!isset($seenRecords[$key])) {
    $seenRecords[$key] = true;
    $uniqueAllowanceData[] = $record;
}
```

### 参数绑定优化
只有当日期筛选条件都存在且不为空时才绑定日期参数：

```php
// 只有当日期筛选条件都存在且不为空时才绑定日期参数
if (!empty($date_filters['date_from']) && !empty($date_filters['date_to'])) {
    $stmt->bindParam(':date_from', $date_filters['date_from']);
    $stmt->bindParam(':date_to', $date_filters['date_to']);
}
```

### 天数验证逻辑
允许0值但阻止负数输入：

```php
// 修改前
if ($new_days <= 0) {
    throw new Exception('天数必须大于0');
}

// 修改后
if ($new_days < 0) {
    throw new Exception('天数不能为负数');
}
```

## 验证结果

- 所有修改均已通过语法检查
- 功能测试通过，符合用户需求
- 现有功能保持完整
- 前后端数据同步一致