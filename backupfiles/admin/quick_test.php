<?php
// 快速测试修复效果

// 数据库配置
$host = "localhost";
$dbname = "team_reception";
$username = "team_reception";
$password = "team_reception";

try {
    echo "🚀 快速测试修复效果...\n\n";
    
    // 连接数据库
    $db = new PDO("mysql:host=$host;dbname=$dbname", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    
    echo "✅ 数据库连接成功\n";
    
    // 检查表结构
    $stmt = $db->query("DESCRIBE transportation_reports");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $hasCost = false;
    $hasStartLocation = false;
    $hasEndLocation = false;
    
    echo "\n📊 表结构检查：\n";
    foreach ($columns as $col) {
        echo "   - " . $col['Field'] . " (" . $col['Type'] . ")\n";
        
        if ($col['Field'] === 'cost') $hasCost = true;
        if ($col['Field'] === 'start_location') $hasStartLocation = true;
        if ($col['Field'] === 'end_location') $hasEndLocation = true;
    }
    
    echo "\n🔍 必需列检查：\n";
    echo "   cost: " . ($hasCost ? "✅ 存在" : "❌ 缺失") . "\n";
    echo "   start_location: " . ($hasStartLocation ? "✅ 存在" : "❌ 缺失") . "\n";
    echo "   end_location: " . ($hasEndLocation ? "✅ 存在" : "❌ 缺失") . "\n";
    
    // 测试查询
    echo "\n🧪 查询测试：\n";
    try {
        $stmt = $db->query("
            SELECT 
                tr.id,
                tr.travel_date,
                tr.travel_type,
                tr.departure_location,
                tr.destination_location,
                COALESCE(tr.cost, 0) as cost,
                p.name as project_name
            FROM transportation_reports tr
            LEFT JOIN projects p ON tr.project_id = p.id
            LIMIT 3
        ");
        
        $results = $stmt->fetchAll();
        echo "   ✅ 查询成功，返回 " . count($results) . " 条记录\n";
        
        if ($results) {
            echo "\n   示例数据：\n";
            foreach ($results as $i => $row) {
                echo "   " . ($i + 1) . ". " . $row['travel_date'] . " - " . 
                     ($row['project_name'] ?? '未指定') . " - " . 
                     $row['travel_type'] . " - ¥" . $row['cost'] . "\n";
            }
        }
    } catch (Exception $e) {
        echo "   ❌ 查询失败：" . $e->getMessage() . "\n";
    }
    
    echo "\n🎉 测试完成！\n";
    
    if ($hasCost && $hasStartLocation && $hasEndLocation) {
        echo "✅ 所有必需列已就绪，可以正常访问交通统计页面\n";
    } else {
        echo "⚠️ 缺少必需列，请运行 run_complete_fix.bat 进行修复\n";
    }
    
} catch (Exception $e) {
    echo "❌ 错误：" . $e->getMessage() . "\n";
}
?>