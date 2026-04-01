<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// 设置错误报告
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// 设置响应头
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// 启动session
session_start();

// 检查登录和权限
if (!isset($_SESSION['user_id']) || !isset($_SESSION['project_id'])) {
    echo json_encode([
        'success' => false,
        'error' => '用户未登录或缺少项目权限'
    ]);
    exit;
}

$projectId = $_SESSION['project_id'];
$mealType = $_GET['meal_type'] ?? '';

if (empty($mealType)) {
    echo json_encode([
        'success' => false,
        'error' => '缺少餐类型参数'
    ]);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // 获取指定餐类型的启用套餐
    $query = "SELECT mp.id, mp.name, mp.description, mp.price,
                     GROUP_CONCAT(
                         CONCAT(mpi.item_name, 
                                CASE WHEN mpi.item_description IS NOT NULL AND mpi.item_description != '' 
                                     THEN CONCAT('(', mpi.item_description, ')') 
                                     ELSE '' END,
                                CASE WHEN mpi.quantity > 1 
                                     THEN CONCAT(' x', mpi.quantity, mpi.unit) 
                                     ELSE '' END
                               ) 
                         ORDER BY mpi.sort_order 
                         SEPARATOR ', '
                     ) as items_list
              FROM meal_packages mp
              LEFT JOIN meal_package_items mpi ON mp.id = mpi.package_id
              WHERE mp.project_id = :project_id 
              AND mp.meal_type = :meal_type 
              AND mp.is_active = 1
              GROUP BY mp.id
              ORDER BY mp.name";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':project_id', $projectId);
    $stmt->bindParam(':meal_type', $mealType);
    $stmt->execute();
    
    $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 格式化数据
    $result = [];
    foreach ($packages as $package) {
        $result[] = [
            'id' => $package['id'],
            'name' => $package['name'],
            'description' => $package['description'],
            'price' => number_format($package['price'], 2),
            'items' => $package['items_list'] ?: '暂无菜品详情'
        ];
    }
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'packages' => $result
    ]);
    
} catch (Exception $e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>