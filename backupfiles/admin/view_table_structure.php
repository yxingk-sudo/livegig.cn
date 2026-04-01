<?php
require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // 获取hotel_reports表结构
    $stmt = $db->query("DESCRIBE hotel_reports");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h2>hotel_reports表结构</h2>";
    echo "<table border='1'>";
    echo "<tr><th>字段名</th><th>类型</th><th>允许NULL</th><th>默认值</th><th>额外信息</th></tr>";
    
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td>" . $column['Field'] . "</td>";
        echo "<td>" . $column['Type'] . "</td>";
        echo "<td>" . $column['Null'] . "</td>";
        echo "<td>" . $column['Default'] . "</td>";
        echo "<td>" . $column['Extra'] . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    
    // 查看一些示例数据
    echo "<h2>示例数据</h2>";
    $stmt = $db->query("SELECT * FROM hotel_reports LIMIT 5");
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1'>";
    echo "<tr>";
    foreach (array_keys($data[0]) as $key) {
        echo "<th>" . $key . "</th>";
    }
    echo "</tr>";
    
    foreach ($data as $row) {
        echo "<tr>";
        foreach ($row as $value) {
            echo "<td>" . htmlspecialchars($value) . "</td>";
        }
        echo "</tr>";
    }
    
    echo "</table>";
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage();
}
?>