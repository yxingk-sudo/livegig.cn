<?php
/**
 * 数据库迁移脚本：添加日期范围控制字段
 * 用途：为 projects 表添加报餐管理的日期范围控制字段
 * 执行方式：在浏览器访问此文件或在命令行运行 php migrate_date_range.php
 */

require_once 'config/database.php';

echo "===========================================\n";
echo "数据库迁移：添加日期范围控制字段\n";
echo "===========================================\n\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception("数据库连接失败");
    }
    
    echo "✓ 数据库连接成功\n\n";
    
    // 检查字段是否已存在
    $checkQuery = "SHOW COLUMNS FROM projects LIKE 'meal_start_date'";
    $stmt = $db->query($checkQuery);
    $existingColumn = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingColumn) {
        echo "⚠ 警告：日期范围配置字段已存在，无需重复添加\n";
        echo "   如果确定要重新执行，请先手动删除这些字段\n\n";
        
        // 显示现有字段值
        echo "当前项目的日期范围配置:\n";
        $configQuery = "SELECT id, name, meal_start_date, meal_end_date FROM projects";
        $configStmt = $db->query($configQuery);
        $projects = $configStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($projects as $project) {
            echo sprintf(
                "  - %s (ID: %d): 开始=%s, 结束=%s\n",
                $project['name'],
                $project['id'],
                $project['meal_start_date'] ?? '未设置',
                $project['meal_end_date'] ?? '未设置'
            );
        }
        exit(0);
    }
    
    echo "开始执行迁移...\n\n";
    
    // 执行 ALTER TABLE
    $alterQuery = "ALTER TABLE projects 
                   ADD COLUMN meal_start_date DATE NULL COMMENT '报餐管理开始日期',
                   ADD COLUMN meal_end_date DATE NULL COMMENT '报餐管理结束日期'";
    
    $db->exec($alterQuery);
    
    echo "✓ 成功添加 2 个日期范围配置字段:\n";
    echo "  - meal_start_date (报餐管理开始日期)\n";
    echo "  - meal_end_date (报餐管理结束日期)\n\n";
    
    // 验证字段已添加
    $verifyQuery = "SHOW COLUMNS FROM projects WHERE Field IN ('meal_start_date', 'meal_end_date')";
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
    
    echo "\n✓ 迁移完成！所有项目默认不限制日期范围（NULL 表示使用酒店记录）。\n";
    echo "\n提示：您可以访问 /admin/meal_packages.php?project_id=X 来管理各项目的日期范围。\n\n";
    
} catch (PDOException $e) {
    echo "\n✗ 数据库错误：" . $e->getMessage() . "\n\n";
    
    // 提供更详细的错误信息
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "提示：字段可能已经存在，请检查 projects 表结构。\n";
    } elseif (strpos($e->getMessage(), "Table 'team_reception.projects'") !== false) {
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
