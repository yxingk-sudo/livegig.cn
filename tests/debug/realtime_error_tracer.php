<?php
// 实时错误追踪器 - 模拟实际使用场景
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><title>实时错误追踪器</title></head><body>';
echo '<h1>实时错误追踪器</h1>';
echo '<style>body{font-family:monospace;margin:20px}.log{margin:10px 0;padding:10px;border:1px solid #ccc}.error{background:#ffe8e8}.warning{background:#fff3cd}.success{background:#e8f5e8}</style>';

// 创建一个函数来追踪错误
function traceError($url, $label) {
    echo '<div class="log"><h3>' . htmlspecialchars($label) . '</h3>';
    echo '<p>URL: ' . htmlspecialchars($url) . '</p>';
    
    // 设置错误处理
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        echo '<div class="error">错误: ' . htmlspecialchars($errstr) . ' 在 ' . htmlspecialchars($errfile) . ':' . $errline . '</div>';
    });
    
    // 使用cURL获取响应
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    curl_setopt($ch, CURLOPT_STDERR, fopen('php://temp', 'w+'));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    
    if ($response === false) {
        echo '<div class="error">cURL错误: ' . curl_error($ch) . '</div>';
        curl_close($ch);
        echo '</div>';
        return;
    }
    
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    
    echo '<p>HTTP状态: ' . $httpCode . '</p>';
    echo '<p>Content-Type: ' . htmlspecialchars($contentType) . '</p>';
    echo '<p>响应长度: ' . strlen($body) . ' 字节</p>';
    
    // 分析响应
    $isJson = json_decode($body) !== null;
    $hasHtml = preg_match('/<[^>]*>/', $body);
    $hasBr = strpos($body, '<br') !== false;
    
    if ($hasBr) {
        echo '<div class="error">发现 &lt;br&gt; 标签</div>';
    }
    
    if ($hasHtml && !$isJson) {
        echo '<div class="error">发现HTML内容</div>';
        echo '<pre>' . htmlspecialchars(substr($body, 0, 500)) . '</pre>';
    } elseif ($isJson) {
        echo '<div class="success">JSON格式正确</div>';
    } else {
        echo '<div class="warning">非JSON响应</div>';
        echo '<pre>' . htmlspecialchars(substr($body, 0, 500)) . '</pre>';
    }
    
    curl_close($ch);
    echo '</div>';
}

// 测试各种场景
$baseUrl = 'http://localhost';

// 测试实际使用场景
$testCases = [
    $baseUrl . '/admin/api/personnel/get_personnel_details_clean.php?personnel_id=1' => '人员详情API',
    $baseUrl . '/admin/get_personnel_projects_strict.php?personnel_id=1' => '人员项目API',
    $baseUrl . '/admin/get_company_projects_strict.php?company_id=1' => '公司项目API',
    $baseUrl . '/admin/api/personnel/get_personnel_details.php?personnel_id=1' => '原始人员详情API',
    $baseUrl . '/api/get_personnel_projects.php?personnel_id=1' => '原始人员项目API',
    $baseUrl . '/api/projects/api_get_company.php?company_id=1' => '原始公司项目API'
];

echo '<h2>测试实际API端点</h2>';
foreach ($testCases as $url => $label) {
    traceError($url, $label);
}

// 检查PHP错误日志
echo '<h2>PHP错误日志</h2>';
$errorLog = ini_get('error_log');
if ($errorLog && file_exists($errorLog)) {
    echo '<p>错误日志: ' . htmlspecialchars($errorLog) . '</p>';
    $lines = array_slice(file($errorLog), -10);
    echo '<pre>';
    foreach ($lines as $line) {
        echo htmlspecialchars($line) . "\n";
    }
    echo '</pre>';
}

echo '</body></html>';