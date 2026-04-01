<?php
session_start();
require_once '../../../config/database.php';

if (!isset($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => '缺少人员ID']);
    exit;
}

$database = new Database();
$db = $database->getConnection();

try {
    $person_id = intval($_GET['id']);
    
    // 获取人员基本信息
    $query = "SELECT * FROM personnel WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $person_id);
    $stmt->execute();
    $person = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($person) {
        echo json_encode([
            'success' => true,
            'person' => $person
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '人员未找到']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '获取人员数据失败: ' . $e->getMessage()]);
}