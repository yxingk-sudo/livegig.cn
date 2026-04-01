<?php
require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception("数据库连接失败");
    }
    
    // 修改 personnel_project_users 表结构，添加自增主键
    $queries = [
        "ALTER TABLE personnel_project_users ADD COLUMN temp_id INT AUTO_INCREMENT PRIMARY KEY FIRST",
        "ALTER TABLE personnel_project_users DROP COLUMN id",
        "ALTER TABLE personnel_project_users CHANGE temp_id id INT AUTO_INCREMENT"
    ];
    
    foreach ($queries as $query) {
        try {
            $db->exec($query);
            echo "<p>执行成功: " . htmlspecialchars($query) . "</p>";
        } catch (PDOException $e) {
            echo "<p>执行失败: " . htmlspecialchars($query) . " - 错误: " . $e->getMessage() . "</p>";
        }
    }
    
    echo "<h2>修复完成</h2>";
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage();
}
?>