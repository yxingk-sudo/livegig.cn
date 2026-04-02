<?php
require_once '../config/database.php';
// 更可靠地包含page_functions.php，如果文件不存在则定义默认函数
$page_functions_path = __DIR__ . '/page_functions.php';
if (file_exists($page_functions_path)) {
    require_once $page_functions_path;
} else {
    // 定义默认的getCurrentPage函数
    if (!function_exists('getCurrentPage')) {
        function getCurrentPage() {
            return basename($_SERVER['PHP_SELF']);
        }
    }
}

session_start();

// 引入基础控制器进行权限验证
require_once '../includes/BaseAdminController.php';

// 检查是否登录
if (!isset($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未授权访问']);
    exit;
}

// 只允许 POST 请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '只允许 POST 请求']);
    exit;
}

// 获取数据库连接
$database = new Database();
$db = $database->getConnection();

try {
    // 获取并验证输入数据
    $personnel_id = isset($_POST['personnel_id']) ? intval($_POST['personnel_id']) : 0;
    $meal_allowance = isset($_POST['meal_allowance']) ? floatval($_POST['meal_allowance']) : 0;
    
    if ($personnel_id <= 0) {
        throw new Exception('无效的人员ID');
    }
    
    if ($meal_allowance < 0) {
        throw new Exception('餐费补助金额不能为负数');
    }
    
    // 更新人员的餐费补助金额
    $query = "UPDATE personnel SET meal_allowance = :meal_allowance WHERE id = :personnel_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':meal_allowance', $meal_allowance);
    $stmt->bindParam(':personnel_id', $personnel_id);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true, 
            'message' => '餐费补助金额更新成功',
            'personnel_id' => $personnel_id,
            'meal_allowance' => $meal_allowance
        ]);
    } else {
        throw new Exception('更新失败');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}