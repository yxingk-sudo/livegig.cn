<?php
require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception("数据库连接失败");
    }
    
    // 检查 project_users 表结构
    echo "<h2>project_users 表结构</h2>";
    $stmt = $db->query("SHOW CREATE TABLE project_users");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<pre>" . htmlspecialchars($result['Create Table']) . "</pre>";
    
    // 检查 personnel_project_users 表结构
    echo "<h2>personnel_project_users 表结构</h2>";
    $stmt = $db->query("SHOW CREATE TABLE personnel_project_users");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<pre>" . htmlspecialchars($result['Create Table']) . "</pre>";
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage();
}
?>