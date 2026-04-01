<?php
/**
 * 数据库迁移脚本：添加餐类型配置字段
 * 用途：为 projects 表添加早餐、午餐、晚餐、宵夜的启用/禁用控制字段
 * 执行方式：在浏览器访问此文件或在命令行运行 php migrate_meal_types.php
 */

require_once 'config/database.php';

echo "===========================================\n";
echo "数据库迁移：添加餐类型配置字段\n";
echo "===========================================\n\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception("数据库连接失败");
    }
    
    echo "✓ 数据库连接成功\n\n";
    
    // 检查字段是否已存在
    $checkQuery = "SHOW COLUMNS FROM projects LIKE 'breakfast_enabled'";
    $stmt = $db->query($checkQuery);
    $existingColumn = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingColumn) {
        echo "⚠ 警告：餐类型配置字段已存在，无需重复添加\n";
        echo "   如果确定要重新执行，请先手动删除这些字段\n\n";
        
        // 显示现有字段值
        echo "当前项目的餐类型配置:\n";
        $configQuery = "SELECT id, name, breakfast_enabled, lunch_enabled, dinner_enabled, supper_enabled FROM projects";
        $configStmt = $db->query($configQuery);
        $projects = $configStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($projects as $project) {
            echo sprintf(
                "  - %s (ID: %d): 早餐=%d, 午餐=%d, 晚餐=%d, 宵夜=%d\n",
                $project['name'],
                $project['id'],
                $project['breakfast_enabled'],
                $project['lunch_enabled'],
                $project['dinner_enabled'],
                $project['supper_enabled']
            );
        }
        exit(0);
    }
    
    echo "开始执行迁移...\n\n";
    
    // 执行 ALTER TABLE
    $alterQuery = "ALTER TABLE projects 
                   ADD COLUMN breakfast_enabled TINYINT(1) DEFAULT 1 COMMENT '早餐是否启用',
                   ADD COLUMN lunch_enabled TINYINT(1) DEFAULT 1 COMMENT '午餐是否启用',
                   ADD COLUMN dinner_enabled TINYINT(1) DEFAULT 1 COMMENT '晚餐是否启用',
                   ADD COLUMN supper_enabled TINYINT(1) DEFAULT 1 COMMENT '宵夜是否启用'";
    
    $db->exec($alterQuery);
    
    echo "✓ 成功添加 4 个餐类型配置字段:\n";
    echo "  - breakfast_enabled (早餐是否启用)\n";
    echo "  - lunch_enabled (午餐是否启用)\n";
    echo "  - dinner_enabled (晚餐是否启用)\n";
    echo "  - supper_enabled (宵夜是否启用)\n\n";
    
    // 验证字段已添加
    $verifyQuery = "SHOW COLUMNS FROM projects WHERE Field IN ('breakfast_enabled', 'lunch_enabled', 'dinner_enabled', 'supper_enabled')";
    $verifyStmt = $db->query($verifyQuery);
    $columns = $verifyStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "✓ 字段验证:\n";
    foreach ($columns as $column) {
        echo sprintf("  - %s (%s) - 默认值：%s\n", 
            $column['Field'], 
            $column['Type'], 
            $column['Default']
        );
    }
    
    echo "\n✓ 迁移完成！所有项目默认启用所有餐类型。\n";
    echo "\n提示：您可以访问 /admin/meal_packages.php?project_id=X 来管理各项目的餐类型配置。\n\n";
    
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
