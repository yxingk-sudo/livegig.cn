<?php
/**
 * 数据库迁移脚本：创建套餐分配表
 * 用途：存储每日每餐的套餐分配信息
 */

require_once 'config/database.php';

echo "===========================================\n";
echo "数据库迁移：创建套餐分配表\n";
echo "===========================================\n\n";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception("数据库连接失败");
    }
    
    echo "✓ 数据库连接成功\n\n";
    
    // 检查表是否已存在
    $checkQuery = "SHOW TABLES LIKE 'meal_package_assignments'";
    $stmt = $db->query($checkQuery);
    $existingTable = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingTable) {
        echo "⚠ 警告：套餐分配表已存在，无需重复创建\n";
        exit(0);
    }
    
    echo "开始创建套餐分配表...\n\n";
    
    // 创建套餐分配表
    $createTableQuery = "CREATE TABLE meal_package_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY COMMENT '主键 ID',
        project_id INT NOT NULL COMMENT '项目 ID',
        meal_date DATE NOT NULL COMMENT '用餐日期',
        meal_type ENUM('早餐', '午餐', '晚餐', '宵夜') NOT NULL COMMENT '餐类型',
        package_id INT NOT NULL COMMENT '套餐 ID',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        INDEX idx_project_date (project_id, meal_date),
        INDEX idx_meal_type (meal_type),
        INDEX idx_package (package_id),
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY (package_id) REFERENCES meal_packages(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='套餐分配表'";
    
    $db->exec($createTableQuery);
    
    echo "✓ 成功创建 meal_package_assignments 表\n\n";
    
    // 验证表已创建
    $verifyQuery = "SHOW TABLES LIKE 'meal_package_assignments'";
    $verifyStmt = $db->query($verifyQuery);
    $tables = $verifyStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "✓ 表验证:\n";
    foreach ($tables as $table) {
        echo sprintf("  - %s\n", reset($table));
    }
    
    echo "\n✓ 迁移完成！套餐分配表创建成功。\n";
    echo "\n提示：此表用于存储每日每餐的套餐分配信息。\n\n";
    
} catch (PDOException $e) {
    echo "\n✗ 数据库错误：" . $e->getMessage() . "\n\n";
    exit(1);
} catch (Exception $e) {
    echo "\n✗ 错误：" . $e->getMessage() . "\n\n";
    exit(1);
}

echo "===========================================\n";
echo "迁移成功完成\n";
echo "===========================================\n";
