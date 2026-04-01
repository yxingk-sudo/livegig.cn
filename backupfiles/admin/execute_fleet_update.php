<?php
// 直接执行车队管理数据库更新
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>车队管理更新</title>';
echo '<link href="assets/css/app.min.css" rel="stylesheet">';
echo '</head><body><div class="container mt-5">';

try {
    echo '<h2>开始更新数据库表结构...</h2>';
    
    // 检查表是否存在
    $checkTable = $db->query("SHOW TABLES LIKE 'transportation_reports'");
    if ($checkTable->rowCount() == 0) {
        throw new Exception("transportation_reports 表不存在");
    }
    
    // 逐个添加字段
    $fieldsToAdd = [
        'fleet_number' => "VARCHAR(50) DEFAULT NULL COMMENT '车队编号'",
        'driver_name' => "VARCHAR(100) DEFAULT NULL COMMENT '驾驶员姓名'",
        'driver_phone' => "VARCHAR(20) DEFAULT NULL COMMENT '驾驶员电话'",
        'license_plate' => "VARCHAR(20) DEFAULT NULL COMMENT '车牌号码'",
        'vehicle_model' => "VARCHAR(50) DEFAULT NULL COMMENT '具体车型'"
    ];
    
    foreach ($fieldsToAdd as $field => $definition) {
        // 检查字段是否已存在
        $checkField = $db->prepare("SHOW COLUMNS FROM transportation_reports LIKE ?");
        $checkField->execute([$field]);
        
        if ($checkField->rowCount() == 0) {
            // 字段不存在，添加它
            $sql = "ALTER TABLE transportation_reports ADD COLUMN {$field} {$definition}";
            $db->exec($sql);
            echo "✅ 添加字段成功: {$field}<br>";
        } else {
            echo "⚠ 字段已存在: {$field}<br>";
        }
    }
    
    // 更新车辆类型枚举
    try {
        $db->exec("ALTER TABLE transportation_reports 
                   MODIFY COLUMN vehicle_type 
                   ENUM('car', 'van', 'minibus', 'bus', 'truck', 'other') 
                   NOT NULL DEFAULT 'other'");
        echo "✅ 更新车辆类型枚举成功<br>";
    } catch (Exception $e) {
        echo "⚠ 更新车辆类型枚举失败: " . $e->getMessage() . "<br>";
    }
    
    // 添加索引
    $indexesToAdd = [
        'idx_transport_fleet' => 'fleet_number',
        'idx_transport_plate' => 'license_plate'
    ];
    
    foreach ($indexesToAdd as $indexName => $column) {
        try {
            $checkIndex = $db->query("SHOW INDEX FROM transportation_reports WHERE Key_name = '{$indexName}'");
            if ($checkIndex->rowCount() == 0) {
                $db->exec("CREATE INDEX {$indexName} ON transportation_reports({$column})");
                echo "✅ 添加索引成功: {$indexName}<br>";
            } else {
                echo "⚠ 索引已存在: {$indexName}<br>";
            }
        } catch (Exception $e) {
            echo "⚠ 添加索引失败: " . $e->getMessage() . "<br>";
        }
    }
    
    echo '<div class="alert alert-success mt-4">';
    echo '<h4>✅ 车队管理功能更新完成！</h4>';
    echo '<p>现在您可以：</p>';
    echo '<ul>';
    echo '<li>在报出行车管理页面查看新的车队信息列</li>';
    echo '<li>使用编辑功能为每条记录添加车队编号、车牌、驾驶员等信息</li>';
    echo '<li>支持的车辆类型：轿车、商务车、中巴车、大巴车、货车</li>';
    echo '</ul>';
    echo '<a href="transportation_reports.php" class="btn btn-primary">前往报出行车管理页面</a>';
    echo '</div>';
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">';
    echo '<h4>❌ 更新失败</h4>';
    echo '<p>错误信息：' . $e->getMessage() . '</p>';
    echo '<a href="transportation_reports.php" class="btn btn-secondary">返回</a>';
    echo '</div>';
}

echo '</div></body></html>';
?>