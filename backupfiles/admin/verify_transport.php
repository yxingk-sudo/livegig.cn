<?php
// 简单的交通地点验证脚本 - 直接在浏览器中运行
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

echo '<!DOCTYPE html><html><head><title>交通地点验证</title></head><body>';
echo '<h2>交通地点字段验证</h2>';

// 1. 检查表结构
try {
    $stmt = $db->query("DESCRIBE projects");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo '<h3>项目表结构：</h3>';
    echo '<table border="1" cellpadding="5"><tr><th>字段名</th><th>类型</th><th>可空</th></tr>';
    
    $transport_fields = ['arrival_airport', 'arrival_railway_station', 'departure_airport', 'departure_railway_station'];
    
    foreach ($columns as $column) {
        $field = $column['Field'];
        $highlight = in_array($field, $transport_fields) ? 'style="background: yellow"' : '';
        echo "<tr {$highlight}><td>{$field}</td><td>{$column['Type']}</td><td>{$column['Null']}</td></tr>";
    }
    echo '</table>';
    
    // 2. 检查是否有数据
    $stmt = $db->query("SELECT id, name, arrival_airport, arrival_railway_station, departure_airport, departure_railway_station FROM projects LIMIT 5");
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo '<h3>最近5个项目交通地点数据：</h3>';
    if ($projects) {
        echo '<table border="1" cellpadding="5"><tr><th>ID</th><th>项目名称</th><th>机场1</th><th>高铁站1</th><th>机场2</th><th>高铁站2</th></tr>';
        foreach ($projects as $project) {
            echo '<tr>';
            echo '<td>' . ($project['id'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($project['name'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($project['arrival_airport'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($project['arrival_railway_station'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($project['departure_airport'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($project['departure_railway_station'] ?? '') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    } else {
        echo '<p>暂无项目数据</p>';
    }
    
    // 3. 测试插入一条带交通地点的数据
    echo '<h3>测试插入数据：</h3>';
    try {
        $test_name = '测试项目_' . date('His');
        $insert_sql = "INSERT INTO projects (name, code, company_id, location, arrival_airport, arrival_railway_station, departure_airport, departure_railway_station, status) 
                      VALUES (:name, :code, 1, '测试场地', :arrival_airport, :arrival_railway_station, :departure_airport, :departure_railway_station, 'active')";
        
        $stmt = $db->prepare($insert_sql);
        $stmt->execute([
            ':name' => $test_name,
            ':code' => 'TEST_' . time(),
            ':arrival_airport' => '测试机场1',
            ':arrival_railway_station' => '测试高铁站1', 
            ':departure_airport' => '测试机场2',
            ':departure_railway_station' => '测试高铁站2'
        ]);
        
        $test_id = $db->lastInsertId();
        echo '<p style="color: green">✓ 测试数据插入成功，ID: ' . $test_id . '</p>';
        
        // 验证插入的数据
        $stmt = $db->prepare("SELECT * FROM projects WHERE id = :id");
        $stmt->execute([':id' => $test_id]);
        $test_project = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo '<p>验证数据：';
        echo '机场1: ' . htmlspecialchars($test_project['arrival_airport']) . ' | ';
        echo '高铁站1: ' . htmlspecialchars($test_project['arrival_railway_station']) . ' | ';
        echo '机场2: ' . htmlspecialchars($test_project['departure_airport']) . ' | ';
        echo '高铁站2: ' . htmlspecialchars($test_project['departure_railway_station']);
        echo '</p>';
        
        // 清理测试数据
        $db->prepare("DELETE FROM projects WHERE id = :id")->execute([':id' => $test_id]);
        echo '<p style="color: green">✓ 测试数据已清理</p>';
        
    } catch (Exception $e) {
        echo '<p style="color: red">✗ 测试插入失败: ' . $e->getMessage() . '</p>';
    }
    
} catch (Exception $e) {
    echo '<p style="color: red">错误: ' . $e->getMessage() . '</p>';
}

echo '<hr>';
echo '<a href="projects.php">返回项目管理</a>';
echo '</body></html>';
?>