<?php
/**
 * 数据库迁移脚本：将日期范围字段改为多选日期存储
 * 用途：将 meal_start_date 和 meal_end_date 替换为 selected_meal_dates（TEXT 类型，JSON 格式）
 * 执行方式：在浏览器访问此文件或在命令行运行 php migrate_selected_dates.php
 */

require_once 'config/database.php';

echo "===========================================\n";
echo "数据库迁移：日期范围改为多选日期\n";
echo "===========================================\n\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception("数据库连接失败");
    }
    
    echo "✓ 数据库连接成功\n\n";
    
    // 检查新字段是否已存在
    $checkQuery = "SHOW COLUMNS FROM projects LIKE 'selected_meal_dates'";
    $stmt = $db->query($checkQuery);
    $existingColumn = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingColumn) {
        echo "⚠ 警告：多选日期配置字段已存在，无需重复添加\n";
        exit(0);
    }
    
    echo "开始执行迁移...\n\n";
    
    // 第一步：添加新字段
    echo "步骤 1: 添加 selected_meal_dates 字段...\n";
    $addQuery = "ALTER TABLE projects ADD COLUMN selected_meal_dates TEXT NULL COMMENT '选中的报餐日期 (JSON 格式)' AFTER meal_end_date";
    $db->exec($addQuery);
    echo "✓ 成功添加 selected_meal_dates 字段\n\n";
    
    // 第二步：迁移旧数据到新字段
    echo "步骤 2: 迁移旧数据到新字段...\n";
    $migrateQuery = "UPDATE projects 
                     SET selected_meal_dates = CONCAT('[\"', meal_start_date, '\",\"', meal_end_date, '\"]')
                     WHERE meal_start_date IS NOT NULL AND meal_end_date IS NOT NULL";
    $db->exec($migrateQuery);
    echo "✓ 完成旧数据迁移\n\n";
    
    // 第三步：删除旧字段
    echo "步骤 3: 删除旧的 meal_start_date 和 meal_end_date 字段...\n";
    $dropQuery = "ALTER TABLE projects DROP COLUMN meal_start_date, DROP COLUMN meal_end_date";
    $db->exec($dropQuery);
    echo "✓ 成功删除旧字段\n\n";
    
    // 验证字段已添加
    $verifyQuery = "SHOW COLUMNS FROM projects WHERE Field = 'selected_meal_dates'";
    $verifyStmt = $db->query($verifyQuery);
    $columns = $verifyStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "✓ 字段验证:\n";
    foreach ($columns as $column) {
        echo sprintf("  - %s (%s) - 允许 NULL: %s\n", 
            $column['Field'], 
            $column['Type'], 
            $column['Null']
        );
    }
    
    echo "\n✓ 迁移完成！所有项目默认不限制日期（NULL 表示全部选中）。\n";
    echo "\n提示：您可以访问 /admin/meal_packages.php?project_id=X 来管理各项目的选中日期。\n\n";
    
} catch (PDOException $e) {
    echo "\n✗ 数据库错误：" . $e->getMessage() . "\n\n";
    
    // 提供更详细的错误信息
    if (strpos($e->getMessage(), "Table 'team_reception.projects'") !== false) {
        echo "提示：projects 表不存在，请检查数据库是否正确初始化。\n";
    }
    
    exit(1);
} catch (Exception $e) {
    echo "\n✗ 错误：" . $e->getMessage() . "\n\n";
    exit(1);
}

echo "===========================================\n";
echo "迁移成功完成\n";
echo "===========================================\n";
