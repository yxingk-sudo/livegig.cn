<?php
// 修复交通地点字段的完整脚本
require_once '../config/database.php';

echo '<!DOCTYPE html><html><head><title>修复交通地点字段</title></head><body>';
echo '<h2>交通地点字段修复工具</h2>';

$database = new Database();
$db = $database->getConnection();

try {
    // 1. 检查现有字段
    echo '<h3>1. 检查现有字段...</h3>';
    $stmt = $db->query("DESCRIBE projects");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $required_fields = [
        'arrival_airport' => "VARCHAR(255) COMMENT '到达机场'",
        'arrival_railway_station' => "VARCHAR(255) COMMENT '到达高铁站'",
        'departure_airport' => "VARCHAR(255) COMMENT '出发机场'",
        'departure_railway_station' => "VARCHAR(255) COMMENT '出发高铁站'"
    ];
    
    $missing_fields = [];
    foreach ($required_fields as $field => $definition) {
        if (!in_array($field, $columns)) {
            $missing_fields[$field] = $definition;
            echo "<p style='color: orange'>缺少字段: {$field}</p>";
        } else {
            echo "<p style='color: green'>✓ 已存在: {$field}</p>";
        }
    }
    
    // 2. 添加缺失字段
    if (!empty($missing_fields)) {
        echo '<h3>2. 添加缺失字段...</h3>';
        foreach ($missing_fields as $field => $definition) {
            try {
                $alter_sql = "ALTER TABLE projects ADD COLUMN {$field} {$definition}";
                $db->exec($alter_sql);
                echo "<p style='color: green'>✓ 成功添加字段: {$field}</p>";
            } catch (Exception $e) {
                echo "<p style='color: red'>✗ 添加字段失败 {$field}: " . $e->getMessage() . "</p>";
            }
        }
    } else {
        echo '<p>所有交通地点字段都已存在</p>';
    }
    
    // 2.5 添加updated_at字段（如果需要）
    echo '<h3>2.5 检查updated_at字段...</h3>';
    if (!in_array('updated_at', $columns)) {
        try {
            $alter_sql = "ALTER TABLE projects ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
            $db->exec($alter_sql);
            echo "<p style='color: green'>✓ 成功添加updated_at字段</p>";
        } catch (Exception $e) {
            echo "<p style='color: red'>✗ 添加updated_at字段失败: " . $e->getMessage() . "</p>";
        }
    } else {
        echo '<p>updated_at字段已存在</p>';
    }
    
    // 3. 验证修复结果
    echo '<h3>3. 验证修复结果...</h3>';
    $stmt = $db->query("DESCRIBE projects");
    $updated_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $all_good = true;
    foreach ($required_fields as $field => $definition) {
        if (!in_array($field, $updated_columns)) {
            echo "<p style='color: red'>✗ 字段仍然缺失: {$field}</p>";
            $all_good = false;
        }
    }
    
    if ($all_good) {
        echo '<p style="color: green; font-size: 18px">✓ 所有交通地点字段已修复完成！</p>';
    }
    
    // 4. 显示当前表结构
    echo '<h3>4. 当前项目表结构：</h3>';
    $stmt = $db->query("DESCRIBE projects");
    $structure = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo '<table border="1" cellpadding="5"><tr><th>字段名</th><th>类型</th><th>可空</th><th>默认值</th><th>注释</th></tr>';
    foreach ($structure as $col) {
        $highlight = in_array($col['Field'], array_keys($required_fields)) ? 'style="background: #ffffcc"' : '';
        echo "<tr {$highlight}>";
        echo "<td>{$col['Field']}</td>";
        echo "<td>{$col['Type']}</td>";
        echo "<td>{$col['Null']}</td>";
        echo "<td>{$col['Default'] ?? 'NULL'}</td>";
        echo "<td>{$col['Comment'] ?? ''}</td>";
        echo '</tr>';
    }
    echo '</table>';
    
} catch (Exception $e) {
    echo '<p style="color: red">错误: ' . $e->getMessage() . '</p>';
}

echo '<hr>';
echo '<a href="verify_transport.php">验证数据保存功能</a> | ';
echo '<a href="projects.php">返回项目管理</a>';
echo '</body></html>';
?>