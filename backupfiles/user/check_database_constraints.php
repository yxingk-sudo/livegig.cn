<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
$database = new Database();
$pdo = $database->getConnection();

echo "<h2>数据库约束检查</h2>";

try {
    // 检查personnel表的约束
    echo "<h3>personnel表约束：</h3>";
    $stmt = $pdo->query("SHOW CREATE TABLE personnel");
    $create_table = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<pre>" . htmlspecialchars($create_table['Create Table']) . "</pre>";
    
    // 检查索引
    echo "<h3>personnel表索引：</h3>";
    $stmt = $pdo->query("SHOW INDEX FROM personnel");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>索引名</th><th>字段名</th><th>是否唯一</th><th>类型</th></tr>";
    foreach ($indexes as $index) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($index['Key_name']) . "</td>";
        echo "<td>" . htmlspecialchars($index['Column_name']) . "</td>";
        echo "<td>" . ($index['Non_unique'] == 0 ? '是' : '否') . "</td>";
        echo "<td>" . htmlspecialchars($index['Index_type']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 检查project_department_personnel表约束
    echo "<h3>project_department_personnel表约束：</h3>";
    $stmt = $pdo->query("SHOW CREATE TABLE project_department_personnel");
    $create_table = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<pre>" . htmlspecialchars($create_table['Create Table']) . "</pre>";
    
    // 检查project_department_personnel索引
    echo "<h3>project_department_personnel表索引：</h3>";
    $stmt = $pdo->query("SHOW INDEX FROM project_department_personnel");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>索引名</th><th>字段名</th><th>是否唯一</th><th>类型</th></tr>";
    foreach ($indexes as $index) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($index['Key_name']) . "</td>";
        echo "<td>" . htmlspecialchars($index['Column_name']) . "</td>";
        echo "<td>" . ($index['Non_unique'] == 0 ? '是' : '否') . "</td>";
        echo "<td>" . htmlspecialchars($index['Index_type']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 检查数据示例
    echo "<h3>personnel表现有数据：</h3>";
    $stmt = $pdo->query("SELECT id, name, id_card, gender FROM personnel WHERE id_card NOT LIKE '%[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]' ORDER BY id DESC");
    $personnel = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($personnel) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>姓名</th><th>证件号</th><th>性别</th></tr>";
        foreach ($personnel as $person) {
            echo "<tr>";
            echo "<td>" . $person['id'] . "</td>";
            echo "<td>" . htmlspecialchars($person['name']) . "</td>";
            echo "<td>" . htmlspecialchars($person['id_card']) . "</td>";
            echo "<td>" . htmlspecialchars($person['gender']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>没有找到非身份证类型的证件数据</p>";
    }
    
} catch (Exception $e) {
    echo "<div style='color: red;'>错误: " . htmlspecialchars($e->getMessage()) . "</div>";
}
?>