<?php
require_once 'db_config.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->prepare("SELECT id, name FROM personnel LIMIT 10");
    $stmt->execute();
    
    echo "<h2>人员列表</h2>";
    echo "<ul>";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<li>ID: " . $row['id'] . " - 姓名: " . $row['name'] . "</li>";
    }
    echo "</ul>";
} catch (PDOException $e) {
    echo "数据库连接失败: " . $e->getMessage();
}
?>