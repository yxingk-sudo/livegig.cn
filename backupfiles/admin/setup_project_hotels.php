<?php
// 项目酒店关联表创建脚本
// 运行此脚本前请确保数据库连接配置正确

require_once '../config/database.php';

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 检查表是否已存在
    $check_query = "SHOW TABLES LIKE 'project_hotels'";
    $stmt = $db->prepare($check_query);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        echo "项目酒店关联表已存在！<br>";
    } else {
        // 创建项目酒店关联表
        $create_sql = "
        CREATE TABLE IF NOT EXISTS `project_hotels` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `project_id` int(11) NOT NULL,
            `hotel_id` int(11) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_project_hotel` (`project_id`, `hotel_id`),
            KEY `project_id` (`project_id`),
            KEY `hotel_id` (`hotel_id`),
            CONSTRAINT `project_hotels_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
            CONSTRAINT `project_hotels_ibfk_2` FOREIGN KEY (`hotel_id`) REFERENCES `hotels` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        $db->exec($create_sql);
        echo "项目酒店关联表创建成功！<br>";
        
        // 如果projects表中存在hotel_id字段，迁移现有数据
        $check_column = "SHOW COLUMNS FROM projects LIKE 'hotel_id'";
        $stmt = $db->prepare($check_column);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            echo "检测到projects表中存在hotel_id字段，开始迁移数据...<br>";
            
            // 迁移现有数据
            $migrate_sql = "
            INSERT INTO project_hotels (project_id, hotel_id)
            SELECT id, hotel_id FROM projects 
            WHERE hotel_id IS NOT NULL AND hotel_id > 0
            ON DUPLICATE KEY UPDATE hotel_id = VALUES(hotel_id)
            ";
            
            $count = $db->exec($migrate_sql);
            echo "成功迁移了 {$count} 条酒店关联数据！<br>";
            
            echo "<strong>建议：</strong>确认数据迁移成功后，可以执行以下SQL移除hotel_id字段：<br>";
            echo "<code>ALTER TABLE projects DROP COLUMN hotel_id;</code><br>";
        }
    }
    
    echo "<br><a href='projects.php'>返回项目列表</a>";
    
} catch (PDOException $e) {
    echo "错误: " . $e->getMessage() . "<br>";
    echo "<br><a href='projects.php'>返回项目列表</a>";
}
?>