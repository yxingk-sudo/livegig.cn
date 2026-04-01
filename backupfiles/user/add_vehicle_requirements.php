<?php
require_once '../config/database.php';
// 添加车型需求字段到交通预订表

$database = new Database();
$db = $database->getConnection();

try {
    // 检查vehicle_requirements字段是否已存在
    $check_column = $db->prepare("SHOW COLUMNS FROM transportation_reports LIKE 'vehicle_requirements'");
    $check_column->execute();
    
    if ($check_column->fetch()) {
        echo "车型需求字段已存在，无需添加。\n";
    } else {
        // 添加车型需求字段
        $alter_query = "ALTER TABLE transportation_reports ADD COLUMN vehicle_requirements TEXT AFTER special_requirements";
        $db->exec($alter_query);
        echo "✅ 成功添加车型需求字段到交通预订表！\n";
    }
    
} catch (PDOException $e) {
    echo "❌ 添加字段失败: " . $e->getMessage() . "\n";
}
?>
