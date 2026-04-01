<?php
require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception("数据库连接失败");
    }
    
    // 检查 personnel_project_users 表是否存在
    $stmt = $db->query("SHOW TABLES LIKE 'personnel_project_users'");
    $table_exists = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($table_exists) {
        echo "<h2>personnel_project_users 表存在</h2>";
        // 检查表结构
        $stmt = $db->query("SHOW CREATE TABLE personnel_project_users");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<pre>" . htmlspecialchars($result['Create Table']) . "</pre>";
    } else {
        echo "<h2>personnel_project_users 表不存在</h2>";
        
        // 检查是否有类似的表
        $stmt = $db->query("SHOW TABLES LIKE '%project%user%'");
        $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($tables)) {
            echo "<h3>类似的表:</h3>";
            foreach ($tables as $table) {
                echo "<p>" . htmlspecialchars(implode(', ', $table)) . "</p>";
            }
        }
    }
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage();
}
?>