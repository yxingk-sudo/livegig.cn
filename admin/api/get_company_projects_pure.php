<?php
// 超纯净版公司项目API - 零错误输出保证
error_reporting(0);
ini_set("display_errors", 0);

// 强制清理所有输出
while (ob_get_level()) {
    ob_end_clean();
}

// 设置严格的JSON响应
header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-cache");

// 获取参数
$company_id = isset($_GET["company_id"]) ? intval($_GET["company_id"]) : 0;

if ($company_id <= 0) {
    echo json_encode(["success" => false, "error" => "无效的公司ID"]);
    exit;
}

try {
    require_once dirname(__DIR__) . "/config/database.php";
    
    $database = new Database();
    $pdo = $database->getConnection();
    
    if (!$pdo) {
        echo json_encode(["success" => false, "error" => "数据库连接失败"]);
        exit;
    }
    
    // 验证公司是否存在
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM companies WHERE id = ?");
    $stmt->execute([$company_id]);
    if ($stmt->fetchColumn() == 0) {
        echo json_encode(["success" => false, "error" => "公司不存在"]);
        exit;
    }
    
    // 获取公司项目
    $stmt = $pdo->prepare("SELECT 
        id,
        name,
        code,
        start_date,
        end_date,
        status,
        description,
        created_at
    FROM projects 
    WHERE company_id = ? 
    ORDER BY created_at DESC");
    
    $stmt->execute([$company_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 格式化日期
    foreach ($projects as &$project) {
        $project["start_date"] = $project["start_date"] ? date("Y-m-d", strtotime($project["start_date"])) : null;
        $project["end_date"] = $project["end_date"] ? date("Y-m-d", strtotime($project["end_date"])) : null;
        $project["created_at"] = date("Y-m-d H:i", strtotime($project["created_at"]));
    }
    
    echo json_encode([
        "success" => true,
        "projects" => $projects,
        "count" => count($projects)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => "查询失败"]);
}

exit;