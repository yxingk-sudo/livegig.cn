# 餐类型启用/禁用功能 - 数据库迁移说明

## 问题原因
访问 `meal_management.php` 时出现错误：
```
Fatal error: Uncaught PDOException: SQLSTATE[42S22]: Column not found: 1054 Unknown column 'breakfast_enabled' in 'field list'
```

这是因为代码已经更新，但数据库中的 `projects` 表还没有添加新的配置字段。

## 解决方案

### 方法一：使用迁移脚本（推荐）

已经创建了自动迁移脚本 `migrate_meal_types.php`，它会自动：
1. 检查字段是否已存在
2. 执行 ALTER TABLE 添加 4 个字段
3. 验证字段是否正确创建
4. 显示当前所有项目的配置状态

**执行方式：**

在服务器命令行执行：
```bash
php /www/wwwroot/livegig.cn/migrate_meal_types.php
```

或者在浏览器访问：
```
http://your-domain/migrate_meal_types.php
```

### 方法二：手动执行 SQL

也可以直接执行 SQL 文件：
```bash
mysql -h 43.160.193.67 -u team_reception -p team_reception < /www/wwwroot/livegig.cn/sql/add_meal_type_settings.sql
```

输入密码：`team_reception`

## 迁移结果

成功执行后，`projects` 表将添加 4 个新字段：

| 字段名 | 类型 | 默认值 | 说明 |
|--------|------|--------|------|
| breakfast_enabled | TINYINT(1) | 1 | 早餐是否启用 |
| lunch_enabled | TINYINT(1) | 1 | 午餐是否启用 |
| dinner_enabled | TINYINT(1) | 1 | 晚餐是否启用 |
| supper_enabled | TINYINT(1) | 1 | 宵夜是否启用 |

所有项目默认启用所有餐类型（值为 1）。

## 验证迁移是否成功

### 方式 1：查看数据库
```sql
SELECT id, name, breakfast_enabled, lunch_enabled, dinner_enabled, supper_enabled 
FROM projects;
```

### 方式 2：运行迁移脚本
再次运行迁移脚本会显示"字段已存在"的提示，并列出所有项目的当前配置。

### 方式 3：访问前端页面
访问 `/user/meal_management.php`，如果能正常显示表格，说明迁移成功。

## 使用新功能

### 后台管理
访问后台套餐管理页面：
```
/admin/meal_packages.php?project_id=你的项目 ID
```

在项目选择后会看到"餐类型管理"卡片，可以：
- 切换开关启用/禁用各个餐类型
- 点击"保存配置"按钮保存设置
- 点击"重置"按钮恢复所有餐类型为启用状态

### 前台展示
访问报餐管理页面：
```
/user/meal_management.php
```

表格会根据后台配置动态显示/隐藏餐类型列：
- 启用的餐类型会显示对应的列
- 禁用的餐类型不会显示
- 如果所有餐类型都禁用，日期列也不会显示

## 注意事项

1. **数据清理**：当禁用某个餐类型时，系统会自动删除该餐类型未来日期的报餐记录，但会保留历史记录。

2. **兼容性**：代码做了兼容处理，即使查询配置失败，也会默认启用所有餐类型。

3. **权限**：确保数据库用户有 ALTER TABLE 权限。

4. **备份**：虽然迁移脚本很安全，但建议在执行前备份数据库。

## 故障排查

### 问题：仍然报错"Unknown column"

**解决方案：**
1. 确认迁移脚本已成功执行
2. 清除浏览器缓存
3. 重启 PHP-FPM 服务：`systemctl restart php-fpm`

### 问题：迁移脚本报错"Duplicate column name"

**说明：** 字段已经存在，无需重复执行。可以直接使用功能。

### 问题：迁移脚本报错"Table doesn't exist"

**说明：** projects 表不存在，需要检查数据库是否正确初始化。

## 相关文件

- 迁移脚本：`/www/wwwroot/livegig.cn/migrate_meal_types.php`
- SQL 文件：`/www/wwwroot/livegig.cn/sql/add_meal_type_settings.sql`
- 后台页面：`/www/wwwroot/livegig.cn/admin/meal_packages.php`
- 前台页面：`/www/wwwroot/livegig.cn/user/meal_management.php`
- API 接口：`/www/wwwroot/livegig.cn/user/ajax/save_meal_selection.php`

---

**最后更新时间：** 2024-01-XX  
**版本：** v1.0
