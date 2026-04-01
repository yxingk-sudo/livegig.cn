<?php
// 开始输出缓冲，确保不会有任何意外输出
ob_start();

session_start();
require_once '../../../config/database.php';

// 清除任何可能的输出
ob_clean();

if (!isset($_SESSION['admin_logged_in'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => '缺少报餐记录ID']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        echo json_encode(['success' => false, 'message' => '数据库连接失败']);
        exit;
    }
    
    $report_id = intval($_GET['id']);
    
    // 获取报餐记录详情
    $query = "SELECT mr.*, p.name as project_name, p.code as project_code, 
                     pr.name as personnel_name, pu.username as reporter_name,
                     mp.name as package_name, mp.description as package_description
              FROM meal_reports mr
              JOIN projects p ON mr.project_id = p.id
              JOIN personnel pr ON mr.personnel_id = pr.id
              JOIN project_users pu ON mr.reported_by = pu.id
              LEFT JOIN meal_packages mp ON mr.package_id = mp.id
              WHERE mr.id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $report_id);
    $stmt->execute();
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$report) {
        echo json_encode(['success' => false, 'message' => '报餐记录未找到']);
        exit;
    }
    
    // 如果有套餐，获取套餐项目
    $package_items = [];
    if ($report['package_id']) {
        $items_query = "SELECT * FROM meal_package_items WHERE package_id = :package_id ORDER BY sort_order";
        $items_stmt = $db->prepare($items_query);
        $items_stmt->bindParam(':package_id', $report['package_id']);
        $items_stmt->execute();
        $package_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo json_encode([
        'success' => true,
        'report' => $report,
        'package_items' => $package_items
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '加载失败：' . $e->getMessage()]);
}

// 确保脚本结束
exit;
?>