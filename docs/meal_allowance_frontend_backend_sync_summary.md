# 前端后端餐费补助明细功能同步修改总结

## 修改目标

使前端 [/user/meal_allowance.php](file:///www/wwwroot/livegig.cn/user/meal_allowance.php) 页面能够如实显示管理后端 [/admin/meal_allowance.php](file:///www/wwwroot/livegig.cn/admin/meal_allowance.php) 页面调整后的数据，确保前后端显示一致。

## 修改内容

### 1. 表格列标题同步

**修改内容：**
- 将"房间数"列标题改为"房晚数"，与后端保持一致

### 2. 数据显示逻辑同步

**修改内容：**
- 修改数据显示逻辑，显示实际的房晚数（天数 × 房间数）而不是房间数
- 对于没有酒店记录的人员，显示"-"替代空值

### 3. 查询逻辑同步

**修改内容：**
- 修改查询逻辑，使用 LEFT JOIN 替代 INNER JOIN，确保获取项目中的所有人员（包括没有酒店记录的人员）
- 添加数据处理逻辑，正确处理有酒店记录和无酒店记录的人员
- 添加去重处理，解决记录重复显示问题

### 4. 总计计算同步

**修改内容：**
- 确保总计计算与后端保持一致

## 技术实现细节

### 查询语句优化

```sql
SELECT DISTINCT
    p.id as personnel_id,
    p.name as personnel_name,
    p.meal_allowance,
    d.name as department_name,
    d.sort_order as department_sort_order,
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

添加了唯一标识符生成逻辑，确保每条记录的唯一性：
```php
$key = $record['personnel_id'] . '_' . ($record['check_in_date'] ?? 'no_date') . '_' . ($record['check_out_date'] ?? 'no_date');
```

## 文件修改列表

1. `/user/meal_allowance.php` - 主要修改文件
   - 修改了表格列标题（将"房间数"改为"房晚数"）
   - 修改了数据处理逻辑，使其与后端保持一致
   - 修改了数据显示逻辑，显示实际的房晚数
   - 修改了查询逻辑，支持没有酒店记录的人员

## 验证结果

- 语法检查通过：`php -l user/meal_allowance.php` 无错误
- 功能测试通过：能够显示所有项目人员的餐费补助记录
- 前后端显示保持一致
- 重复记录问题已解决
- 没有酒店记录的人员也能正确显示
- 现有功能保持完整