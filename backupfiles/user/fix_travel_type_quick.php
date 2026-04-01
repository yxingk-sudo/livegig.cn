<?php
// 修复travel_type字段以支持新的交通类型值
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

try {
    // 修改travel_type字段为支持新值的ENUM
    $sql = "ALTER TABLE transportation_reports 
             MODIFY COLUMN travel_type ENUM('接机/站', '送机/站', '混合交通安排（自定义）', '点对点', '接站', '送站', '混合交通安排') NOT NULL";
    
    $db->exec($sql);
    echo "✅ travel_type字段已成功更新，支持新的交通类型值！\n";
    
    // 验证修改
    $stmt = $db->query("SHOW COLUMNS FROM transportation_reports LIKE 'travel_type'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "新的字段定义: " . $column['Type'] . "\n";
    
} catch (PDOException $e) {
    echo "❌ 更新失败: " . $e->getMessage() . "\n";
}
?>