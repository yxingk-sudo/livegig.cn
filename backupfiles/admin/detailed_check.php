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
    
    // 检查 personnel_project_users 表中的数据
    echo "<h2>personnel_project_users 表中的数据</h2>";
    $stmt = $db->query("SELECT * FROM personnel_project_users LIMIT 10");
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($results)) {
        echo "<p>表中没有数据</p>";
    } else {
        echo "<table border='1'>";
        echo "<tr>";
        foreach (array_keys($results[0]) as $column) {
            echo "<th>" . htmlspecialchars($column) . "</th>";
        }
        echo "</tr>";
        foreach ($results as $row) {
            echo "<tr>";
            foreach ($row as $value) {
                echo "<td>" . htmlspecialchars($value) . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    }
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage();
}
?>