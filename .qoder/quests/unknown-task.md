# 酒店报告系统SQL错误修复设计文档

## 1. 概述

### 1.1 问题描述
在访问酒店报告页面时，出现以下致命错误：
```
Fatal error: Uncaught PDOException: SQLSTATE[HY000]: General error: 1111 Invalid use of group function in D:\phpstudy_pro\WWW\admin\hotel_reports_new.php:255
```

### 1.2 错误分析
错误代码1111表示在SQL查询中不当地使用了聚合函数（如SUM、COUNT、MIN等）。这通常发生在：
1. 在SELECT子句中使用了聚合函数，但在GROUP BY子句中没有包含所有非聚合列
2. 在WHERE子句中直接使用聚合函数而不是在HAVING子句中使用
3. 在GROUP BY子句中使用聚合函数

此外，根据项目历史记录，该系统可能受到MySQL的`ONLY_FULL_GROUP_BY`模式影响，该模式要求在使用GROUP BY时，SELECT列表中的所有列要么是聚合函数，要么包含在GROUP BY子句中。

## 2. 系统架构

### 2.1 技术栈
- PHP 7.x+
- MySQL数据库
- PDO数据库连接
- Bootstrap前端框架

### 2.2 相关文件
- `admin/hotel_reports_new.php` - 主要出错文件
- `config/database.php` - 数据库配置
- `includes/db.php` - 数据库连接封装

## 3. 错误定位与分析

### 3.1 错误位置
根据错误信息，问题出现在`hotel_reports_new.php`文件的第255行，该行执行了SQL查询的execute方法。

### 3.2 问题SQL查询
通过代码分析，第255行执行的是酒店统计查询，具体为`$hotelStatsSql`变量中的SQL语句。

### 3.3 问题根源
在子查询中的GROUP BY子句中使用了聚合函数MIN()：
```sql
GROUP BY 
    CASE 
        WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1)
        ELSE hr.hotel_name
    END,
    h.hotel_name_en,
    hr.room_type,
    hr.check_in_date,
    hr.check_out_date,
    hr.room_count,
    CASE 
        WHEN hr.room_type IN ('双床房','双人房') AND MIN(hr.shared_room_info) IS NOT NULL AND MIN(hr.shared_room_info) != '' THEN MIN(hr.shared_room_info)
        ELSE 'individual'
    END
```

问题出在GROUP BY子句中的`MIN(hr.shared_room_info)`，这是不被允许的。

## 4. 修复方案

### 4.1 方案一：修改GROUP BY子句（推荐）

将GROUP BY子句中的聚合函数移除，改为使用原始字段：

```sql
GROUP BY 
    CASE 
        WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1)
        ELSE hr.hotel_name
    END,
    h.hotel_name_en,
    hr.room_type,
    hr.check_in_date,
    hr.check_out_date,
    hr.room_count,
    CASE 
        WHEN hr.room_type IN ('双床房','双人房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' THEN hr.shared_room_info
        ELSE 'individual'
    END
```

### 4.2 方案二：重构查询逻辑

如果需要保持原有的业务逻辑，可以考虑重构查询，将条件判断移到HAVING子句中。

## 5. 实施步骤

### 5.1 修改hotel_reports_new.php文件
1. 定位到getStatistics函数中的$hotelStatsSql查询
2. 修改GROUP BY子句，移除其中的MIN()函数调用
3. 测试修复后的查询是否能正常执行

### 5.2 验证修复
1. 访问酒店报告页面，确认错误是否解决
2. 检查统计结果是否正确
3. 验证其他功能是否正常

## 6. 数据模型与ORM映射

### 6.1 相关数据表
- `hotel_reports` - 酒店预订记录表
- `hotels` - 酒店信息表
- `projects` - 项目信息表

### 6.2 关键字段说明
- `hotel_reports.hotel_name` - 酒店名称
- `hotel_reports.room_type` - 房间类型
- `hotel_reports.shared_room_info` - 共享房间信息
- `hotel_reports.room_count` - 房间数量

## 7. 业务逻辑层

### 7.1 酒店统计逻辑
1. 根据酒店名称进行分组统计
2. 处理共享房间的特殊情况（双床房/双人房）
3. 计算实际房间数和房晚数

### 7.2 数据去重逻辑
使用GROUP BY子句对重复的预订记录进行去重处理，确保统计准确性。

## 8. 测试方案

### 8.1 单元测试
1. 测试修复后的SQL查询是否能正确执行
2. 验证统计结果的准确性
3. 测试边界条件（空数据、异常数据等）

### 8.2 集成测试
1. 测试整个酒店报告页面的功能
2. 验证与其他模块的集成是否正常
3. 性能测试，确保查询效率

## 9. 风险与回滚方案

### 9.1 潜在风险
1. 修改GROUP BY逻辑可能影响统计结果的准确性
2. 可能影响其他依赖此查询的模块

### 9.2 回滚方案
1. 备份原始文件
2. 如发现问题可快速恢复到原始版本
3. 准备替代查询方案