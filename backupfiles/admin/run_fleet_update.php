<?php
// 数据库更新脚本 - 添加车队管理功能
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

try {
    echo "<h2>开始更新数据库表结构...</h2>";
    
    // 检查并添加新字段
    $queries = [
        "ALTER TABLE transportation_reports 
         ADD COLUMN IF NOT EXISTS fleet_number VARCHAR(50) DEFAULT NULL COMMENT '车队编号'",
        
        "ALTER TABLE transportation_reports 
         ADD COLUMN IF NOT EXISTS driver_name VARCHAR(100) DEFAULT NULL COMMENT '驾驶员姓名'",
        
        "ALTER TABLE transportation_reports 
         ADD COLUMN IF NOT EXISTS driver_phone VARCHAR(20) DEFAULT NULL COMMENT '驾驶员电话'",
        
        "ALTER TABLE transportation_reports 
         ADD COLUMN IF NOT EXISTS license_plate VARCHAR(20) DEFAULT NULL COMMENT '车牌号码'",
        
        "ALTER TABLE transportation_reports 
         ADD COLUMN IF NOT EXISTS vehicle_model VARCHAR(50) DEFAULT NULL COMMENT '具体车型'",
        
        "ALTER TABLE transportation_reports 
         MODIFY COLUMN vehicle_type ENUM('car', 'van', 'minibus', 'bus', 'truck', 'other') 
         NOT NULL DEFAULT 'other' COMMENT '车辆类型：car=轿车, van=商务车, minibus=中巴车, bus=大巴车, truck=货车'",
        
        "CREATE INDEX IF NOT EXISTS idx_transport_fleet ON transportation_reports(fleet_number)",
        
        "CREATE INDEX IF NOT EXISTS idx_transport_plate ON transportation_reports(license_plate)"
    ];
    
    foreach ($queries as $query) {
        try {
            $db->exec($query);
            echo "✓ 执行成功: " . substr($query, 0, 50) . "...<br>";
        } catch (PDOException $e) {
            echo "⚠ 执行跳过: " . $e->getMessage() . "<br>";
        }
    }
    
    echo "<h3 class='text-success'>✅ 车队管理功能表结构更新完成！</h3>";
    echo "<p>现在您可以：</p>";
    echo "<ul>";
    echo "<li>在报出行车管理页面查看新的车队信息列</li>";
    echo "<li>使用编辑功能为每条记录添加车队编号、车牌、驾驶员等信息</li>";
    echo "<li>支持的车辆类型：轿车、商务车、中巴车、大巴车、货车</li>";
    echo "</ul>";
    echo "<a href='transportation_reports.php' class='btn btn-primary'>前往报出行车管理页面</a>";
    
} catch (Exception $e) {
    echo "<h3 class='text-danger'>❌ 更新失败</h3>";
    echo "<p>错误信息：" . $e->getMessage() . "</p>";
    echo "<a href='transportation_reports.php' class='btn btn-secondary'>返回</a>";
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>数据库更新 - 车队管理功能</title>
    <link href="assets/css/app.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <?php
        // PHP代码已在上面执行
        ?>
    </div>
</body>
</html>