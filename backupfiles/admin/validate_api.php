<?php
// API验证脚本
error_reporting(E_ALL);
ini_set('display_errors', 0);

function validateJson($url) {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => 'Accept: application/json'
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        return ['valid' => false, 'error' => '无法访问URL'];
    }
    
    // 检查响应内容
    if (strpos($response, '<br') !== false || strpos($response, '<html') !== false) {
        return ['valid' => false, 'error' => '响应包含HTML标签', 'content' => substr($response, 0, 200)];
    }
    
    // 验证JSON
    json_decode($response);
    if (json_last_error() === JSON_ERROR_NONE) {
        return ['valid' => true, 'data' => json_decode($response, true)];
    } else {
        return ['valid' => false, 'error' => json_last_error_msg(), 'content' => substr($response, 0, 200)];
    }
}

// 测试URL
$baseUrl = 'http://localhost';
$tests = [
    $baseUrl . '/api/get_personnel_projects.php?personnel_id=1',
    $baseUrl . '/admin/api/personnel/get_personnel_details_clean.php?personnel_id=1',
    $baseUrl . '/admin/api/personnel/get_personnel_details.php?personnel_id=1'
];

echo "<!DOCTYPE html><html><head><title>API验证</title></head><body>";
echo "<h1>API端点验证结果</h1>";

echo "<table border='1' cellpadding='10'>";
echo "<tr><th>URL</th><th>状态</th><th>结果</th></tr>";

foreach ($tests as $url) {
    $result = validateJson($url);
    
    echo "<tr>";
    echo "<td><a href='{$url}' target='_blank'>{$url}</a></td>";
    echo "<td style='color: " . ($result['valid'] ? 'green' : 'red') . "'>";
    echo $result['valid'] ? '✓ 有效' : '✗ 无效';
    echo "</td>";
    echo "<td>";
    if ($result['valid']) {
        echo "JSON格式正确";
    } else {
        echo "错误: " . htmlspecialchars($result['error']);
        if (isset($result['content'])) {
            echo "<br><small>" . htmlspecialchars($result['content']) . "...</small>";
        }
    }
    echo "</td>";
    echo "</tr>";
}

echo "</table>";
echo "</body></html>";
?>