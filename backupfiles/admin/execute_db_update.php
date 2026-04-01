<?php
// 执行数据库更新脚本
require_once '../config/database.php';

$database = new Database();
$pdo = $database->getConnection();

if ($pdo === null) {
    die("数据库连接失败");
}

try {
    // 添加座位数字段
    $pdo->exec("ALTER TABLE fleet ADD COLUMN seats INT DEFAULT 5 AFTER driver_phone");
    echo "成功添加座位数字段<br>";
    
    // 为不同车辆类型设置默认座位数
    $updates = [
        'car' => 5,
        'van' => 7,
        'minibus' => 19,
        'bus' => 45,
        'truck' => 3,
        'other' => 5
    ];
    
    foreach ($updates as $type => $seats) {
        $stmt = $pdo->prepare("UPDATE fleet SET seats = ? WHERE vehicle_type = ?");
        $stmt->execute([$seats, $type]);
        echo "更新 {$type} 类型车辆的默认座位数为 {$seats}<br>";
    }
    
    echo "数据库更新完成！";
    
} catch (PDOException $e) {
    if ($e->getCode() == '42S21') { // 字段已存在
        echo "座位数字段已存在，跳过创建<br>";
    } else {
        echo "数据库更新失败: " . $e->getMessage();
    }
}

// 显示当前表结构
$stmt = $pdo->query("DESCRIBE fleet");
echo "<br>当前表结构：<br>";
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['Field'] . " - " . $row['Type'] . " - " . $row['Default'] . "<br>";
}
?>