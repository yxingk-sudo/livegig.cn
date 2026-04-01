<?php
// 完整的数据库设置和修复脚本

// 数据库配置
$host = "localhost";
$dbname = "team_reception";
$username = "team_reception";
$password = "team_reception";

try {
    echo "🚀 开始数据库设置和修复...\n\n";
    
    // 连接数据库
    $db = new PDO("mysql:host=$host;dbname=$dbname", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    echo "✅ 数据库连接成功！\n\n";
    
    // 设置UTF8
    $db->exec("SET NAMES utf8mb4");
    
    // 检查transportation_reports表是否存在
    $stmt = $db->query("SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.TABLES 
                       WHERE TABLE_SCHEMA = '$dbname' 
                       AND TABLE_NAME = 'transportation_reports'");
    $result = $stmt->fetch();
    
    if ($result['count'] == 0) {
        echo "⚠️ transportation_reports表不存在，正在创建...\n";
        
        // 创建transportation_reports表
        $createTableSQL = "
        CREATE TABLE IF NOT EXISTS transportation_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            personnel_id INT NOT NULL,
            vehicle_type ENUM('car', 'van', 'bus', 'truck', 'other') NOT NULL,
            departure_location VARCHAR(255) NOT NULL,
            destination_location VARCHAR(255) NOT NULL,
            travel_date DATE NOT NULL,
            trip_time TIME NOT NULL,
            passenger_count INT NOT NULL DEFAULT 1,
            special_requirements TEXT,
            status ENUM('pending', 'confirmed', 'cancelled') DEFAULT 'pending',
            reported_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (personnel_id) REFERENCES personnel(id) ON DELETE CASCADE,
            FOREIGN KEY (reported_by) REFERENCES project_users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $db->exec($createTableSQL);
        echo "✅ transportation_reports表已创建！\n";
    } else {
        echo "✅ transportation_reports表已存在，检查列结构...\n";
        
        // 检查并修复列名
        $stmt = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                          WHERE TABLE_SCHEMA = '$dbname' 
                          AND TABLE_NAME = 'transportation_reports'");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (in_array('transportation_type', $columns)) {
            echo "⚠️ 发现旧的transportation_type列，正在修复...\n";
            $db->exec("ALTER TABLE transportation_reports 
                       CHANGE COLUMN transportation_type vehicle_type ENUM('car', 'van', 'bus', 'truck', 'other') NOT NULL");
            echo "✅ 列名已修复为vehicle_type！\n";
        } elseif (in_array('vehicle_type', $columns)) {
            echo "✅ vehicle_type列已正确配置！\n";
        } else {
            echo "⚠️ 缺少vehicle_type列，正在添加...\n";
            $db->exec("ALTER TABLE transportation_reports 
                       ADD COLUMN vehicle_type ENUM('car', 'van', 'bus', 'truck', 'other) NOT NULL");
            echo "✅ vehicle_type列已添加！\n";
        }
    }
    
    // 检查fleet表是否存在（用于车辆分配）
    $stmt = $db->query("SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.TABLES 
                       WHERE TABLE_SCHEMA = '$dbname' 
                       AND TABLE_NAME = 'fleet'");
    $result = $stmt->fetch();
    
    if ($result['count'] == 0) {
        echo "⚠️ fleet表不存在，正在创建...\n";
        
        $createFleetSQL = "
        CREATE TABLE IF NOT EXISTS fleet (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fleet_number VARCHAR(50) NOT NULL UNIQUE,
            vehicle_type ENUM('car', 'van', 'minibus', 'bus', 'truck', 'other') NOT NULL DEFAULT 'car',
            vehicle_model VARCHAR(100),
            seats INT NOT NULL DEFAULT 5,
            driver_name VARCHAR(100),
            driver_phone VARCHAR(50),
            status ENUM('available', 'assigned', 'maintenance') DEFAULT 'available',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $db->exec($createFleetSQL);
        echo "✅ fleet表已创建！\n";
    }
    
    // 检查transportation_fleet_assignments表是否存在
    $stmt = $db->query("SELECT COUNT(*) as count FROM INFORMATION_SCHEMA.TABLES 
                       WHERE TABLE_SCHEMA = '$dbname' 
                       AND TABLE_NAME = 'transportation_fleet_assignments'");
    $result = $stmt->fetch();
    
    if ($result['count'] == 0) {
        echo "⚠️ transportation_fleet_assignments表不存在，正在创建...\n";
        
        $createAssignmentSQL = "
        CREATE TABLE IF NOT EXISTS transportation_fleet_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transportation_report_id INT NOT NULL,
            fleet_id INT NOT NULL,
            assigned_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (transportation_report_id) REFERENCES transportation_reports(id) ON DELETE CASCADE,
            FOREIGN KEY (fleet_id) REFERENCES fleet(id) ON DELETE CASCADE,
            UNIQUE KEY unique_assignment (transportation_report_id, fleet_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $db->exec($createAssignmentSQL);
        echo "✅ transportation_fleet_assignments表已创建！\n";
    }
    
    // 添加索引优化查询性能
    echo "\n正在添加索引优化...\n";
    
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_transport_project ON transportation_reports(project_id)",
        "CREATE INDEX IF NOT EXISTS idx_transport_personnel ON transportation_reports(personnel_id)",
        "CREATE INDEX IF NOT EXISTS idx_transport_date ON transportation_reports(travel_date)",
        "CREATE INDEX IF NOT EXISTS idx_transport_status ON transportation_reports(status)",
        "CREATE INDEX IF NOT EXISTS idx_fleet_status ON fleet(status)",
        "CREATE INDEX IF NOT EXISTS idx_fleet_type ON fleet(vehicle_type)"
    ];
    
    foreach ($indexes as $indexSQL) {
        try {
            $db->exec($indexSQL);
        } catch (PDOException $e) {
            // 忽略已存在的索引错误
        }
    }
    
    echo "✅ 索引优化完成！\n\n";
    
    // 验证修复结果
    echo "📊 最终验证：\n";
    
    // 显示transportation_reports表结构
    $stmt = $db->query("DESCRIBE transportation_reports");
    $columns = $stmt->fetchAll();
    echo "transportation_reports表结构：\n";
    foreach ($columns as $column) {
        echo "- " . $column['Field'] . " (" . $column['Type'] . ")\n";
    }
    
    echo "\n🎉 数据库设置和修复完成！\n";
    echo "现在可以安全访问 admin/transportation_statistics.php\n";
    
} catch (PDOException $e) {
    echo "❌ 数据库错误：" . $e->getMessage() . "\n";
    echo "请检查：\n";
    echo "1. MySQL服务是否运行\n";
    echo "2. 数据库team_reception是否存在\n";
    echo "3. 用户名密码是否正确\n";
    echo "4. 数据库用户是否有足够权限\n";
} catch (Exception $e) {
    echo "❌ 错误：" . $e->getMessage() . "\n";
}
?>