<?php
require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // 获取hotel_reports表结构
    $stmt = $db->query("DESCRIBE hotel_reports");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h2>hotel_reports表结构</h2>\n";
    echo "<table border='1'>\n";
    echo "<tr><th>字段名</th><th>类型</th><th>允许NULL</th><th>默认值</th><th>额外信息</th></tr>\n";
    
    foreach ($columns as $column) {
        echo "<tr>\n";
        echo "<td>" . $column['Field'] . "</td>\n";
        echo "<td>" . $column['Type'] . "</td>\n";
        echo "<td>" . $column['Null'] . "</td>\n";
        echo "<td>" . $column['Default'] . "</td>\n";
        echo "<td>" . $column['Extra'] . "</td>\n";
        echo "</tr>\n";
    }
    
    echo "</table>\n";
    
    // 查看一些示例数据
    echo "<h2>示例数据</h2>\n";
    $stmt = $db->query("SELECT * FROM hotel_reports LIMIT 5");
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($data)) {
        echo "<table border='1'>\n";
        echo "<tr>\n";
        foreach (array_keys($data[0]) as $key) {
            echo "<th>" . $key . "</th>\n";
        }
        echo "</tr>\n";
        
        foreach ($data as $row) {
            echo "<tr>\n";
            foreach ($row as $value) {
                echo "<td>" . htmlspecialchars($value) . "</td>\n";
            }
            echo "</tr>\n";
        }
        
        echo "</table>\n";
    } else {
        echo "<p>没有找到数据</p>\n";
    }
    
    // 查看共享房间信息的示例数据
    echo "<h2>共享房间信息示例</h2>\n";
    $stmt = $db->query("SELECT * FROM hotel_reports WHERE shared_room_info IS NOT NULL AND shared_room_info != '' LIMIT 5");
    $shared_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($shared_data)) {
        echo "<table border='1'>\n";
        echo "<tr>\n";
        foreach (array_keys($shared_data[0]) as $key) {
            echo "<th>" . $key . "</th>\n";
        }
        echo "</tr>\n";
        
        foreach ($shared_data as $row) {
            echo "<tr>\n";
            foreach ($row as $value) {
                echo "<td>" . htmlspecialchars($value) . "</td>\n";
            }
            echo "</tr>\n";
        }
        
        echo "</table>\n";
    } else {
        echo "<p>没有找到共享房间信息数据</p>\n";
    }
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage();
}
?>