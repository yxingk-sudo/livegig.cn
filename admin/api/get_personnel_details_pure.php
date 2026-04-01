<?php
// 超纯净版人员详情API - 零错误输出保证
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
$personnel_id = isset($_GET["personnel_id"]) ? intval($_GET["personnel_id"]) : 0;

if ($personnel_id <= 0) {
    echo json_encode(["success" => false, "error" => "无效的人员ID"]);
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
    
    // 获取人员信息
    $stmt = $pdo->prepare("SELECT id, name, phone, email, id_card, gender FROM personnel WHERE id = ?");
    $stmt->execute([$personnel_id]);
    $personnel = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$personnel) {
        echo json_encode(["success" => false, "error" => "人员不存在"]);
        exit;
    }
    
    echo json_encode(["success" => true, "data" => $personnel], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => "查询失败"]);
}

exit;