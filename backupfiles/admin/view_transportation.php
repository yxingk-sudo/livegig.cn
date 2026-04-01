<?php
require_once '../config/database.php';

$transportation_id = isset($_GET['id']) ? intval($_GET['id']) : 9;

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // 检查记录
    $stmt = $db->prepare("SELECT * FROM transportation_reports WHERE id = :id");
    $stmt->execute([':id' => $transportation_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($record) {
        echo "<h2>记录详情 (ID: {$transportation_id})</h2>";
        echo "<table border='1'>";
        foreach ($record as $key => $value) {
            echo "<tr><td><strong>{$key}</strong></td><td>" . htmlspecialchars($value) . "</td></tr>";
        }
        echo "</table>";
        
        echo "<h3>vehicle_type值检查</h3>";
        echo "vehicle_type: " . ($record['vehicle_type'] ?? 'NULL') . "<br>";
        echo "vehicle_type长度: " . strlen($record['vehicle_type'] ?? '') . "<br>";
        echo "vehicle_type为空: " . (empty($record['vehicle_type']) ? '是' : '否') . "<br>";
        
    } else {
        echo "未找到ID为{$transportation_id}的记录";
    }
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage();
}
?>