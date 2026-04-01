<?php
// 运行时错误捕捉器 - 模拟实际调用场景
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');

echo '<!DOCTYPE html><html><head><title>运行时错误捕捉器</title></head><body>';
echo '<h1>运行时错误捕捉器</h1>';
echo '<pre>';

// 模拟personnel.php页面加载时的API调用
$test_scenarios = [
    'personnel_list_load' => [
        'description' => '人员列表页面加载',
        'apis' => [
            'http://localhost/admin/get_personnel_details_clean.php?personnel_id=1',
            'http://localhost/admin/get_personnel_projects_strict.php?personnel_id=1'
        ]
    ],
    'personnel_detail_load' => [
        'description' => '人员详情页面加载',
        'apis' => [
            'http://localhost/admin/api/personnel/get_personnel_details_clean.php?personnel_id=1',
            'http://localhost/admin/get_personnel_projects_strict.php?personnel_id=1'
        ]
    ],
    'company_detail_load' => [
        'description' => '公司详情页面加载',
        'apis' => [
            'http://localhost/admin/get_company_projects_strict.php?company_id=1'
        ]
    ]
];

function capture_raw_response($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    
    return [
        'response' => $response,
        'error' => $error,
        'info' => $info
    ];
}

foreach ($test_scenarios as $scenario_key => $scenario) {
    echo "=== 场景: {$scenario['description']} ===\n\n";
    
    foreach ($scenario['apis'] as $api_url) {
        echo "API: {$api_url}\n";
        
        $result = capture_raw_response($api_url);
        
        if ($result['error']) {
            echo "❌ cURL错误: {$result['error']}\n";
            continue;
        }
        
        echo "HTTP状态: {$result['info']['http_code']}\n";
        echo "Content-Type: {$result['info']['content_type']}\n";
        
        // 检查响应内容
        $full_response = $result['response'];
        
        // 分离头部和正文
        $parts = explode("\r\n\r\n", $full_response, 2);
        if (count($parts) >= 2) {
            $body = $parts[count($parts) - 1];
        } else {
            $body = $full_response;
        }
        
        echo "响应长度: " . strlen($body) . " 字符\n";
        
        // 检查HTML标签
        if (preg_match_all('/<[^>]+>/', $body, $matches)) {
            echo "⚠️  发现HTML标签: " . implode(', ', array_slice($matches[0], 0, 5)) . "\n";
            echo "完整响应前500字符:\n" . htmlspecialchars(substr($body, 0, 500)) . "\n";
        } else {
            echo "✅ 未发现HTML标签\n";
            
            // 验证JSON
            $json = json_decode($body, true);
            if ($json === null) {
                echo "❌ 无效的JSON格式\n";
                echo "原始响应: " . htmlspecialchars(substr($body, 0, 200)) . "\n";
            } else {
                echo "✅ 有效的JSON响应\n";
                echo "success: " . ($json['success'] ? 'true' : 'false') . "\n";
            }
        }
        
        echo "\n";
    }
    
    echo "\n";
}

echo '</pre>';
echo '<p><a href="/admin/personnel.php">直接测试人员管理页面</a></p>';
echo '<script>';
echo 'setTimeout(() => {';
echo '  console.log("运行错误捕捉完成");';
echo '}, 1000);';
echo '</script>';
echo '</body></html>';