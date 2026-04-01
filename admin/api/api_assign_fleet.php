<?php
// API: 分配车辆
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// 检查是否登录
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit();
}

$database = new Database();
$pdo = $database->getConnection();

if ($pdo === null) {
    echo json_encode(['success' => false, 'message' => '数据库连接失败']);
    exit();
}

$project_id = isset($_POST['project_id']) ? (int)$_POST['project_id'] : 0;
$transportation_id = isset($_POST['transportation_id']) ? (int)$_POST['transportation_id'] : 0;
$fleet_id = isset($_POST['fleet_id']) ? (int)$_POST['fleet_id'] : 0;

if (!$project_id || !$transportation_id || !$fleet_id) {
    echo json_encode(['success' => false, 'message' => '参数不完整']);
    exit();
}

try {
    // 检查出行记录是否存在
    $stmt = $pdo->prepare("SELECT passenger_count FROM transportation_reports WHERE id = ? AND project_id = ?");
    $stmt->execute([$transportation_id, $project_id]);
    $transportation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$transportation) {
        echo json_encode(['success' => false, 'message' => '出行记录不存在']);
        exit();
    }

    $passenger_count = $transportation['passenger_count'];

    // 检查车辆是否存在
    $stmt = $pdo->prepare("SELECT seats FROM fleet WHERE id = ?");
    $stmt->execute([$fleet_id]);
    $fleet = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$fleet) {
        echo json_encode(['success' => false, 'message' => '车辆不存在']);
        exit();
    }

    $vehicle_seats = $fleet['seats'];

    // 检查车辆是否已分配
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fleet_assignments WHERE fleet_id = ? AND transportation_id = ?");
    $stmt->execute([$fleet_id, $transportation_id]);
    $is_assigned = $stmt->fetchColumn();

    if ($is_assigned) {
        echo json_encode(['success' => false, 'message' => '车辆已分配']);
        exit();
    }

    // 分配车辆
    $stmt = $pdo->prepare("INSERT INTO fleet_assignments (fleet_id, transportation_id, assigned_at) VALUES (?, ?, NOW())");
    $stmt->execute([$fleet_id, $transportation_id]);

    echo json_encode(['success' => true, 'message' => '车辆分配成功']);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => '数据库错误: ' . $e->getMessage()]);
}