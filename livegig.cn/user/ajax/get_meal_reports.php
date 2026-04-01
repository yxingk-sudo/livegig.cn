<?php
// 报餐记录查询接口
// 功能：获取指定日期范围内的已有报餐记录
// 版本：2026-03-06-v1

require_once '../../config/database.php';
require_once '../../includes/functions.php';

session_start();

// 设置 JSON 响应头
header('Content-Type: application/json');

// 检查请求方法（支持 GET 和 POST）
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '请求方法错误']);
    exit;
}

// 检查用户是否登录
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '请先登录']);
    exit;
}

// 检查项目权限
if (!isset($_SESSION['project_id'])) {
    echo json_encode(['success' => false, 'message' => '未选择项目']);
    exit;
}

try {
    // 获取数据库连接
    $database = new Database();
    $db = $database->getConnection();
    
    // 获取参数
    $projectId = $_SESSION['project_id'];
    $startDate = $_GET['start_date'] ?? $_POST['start_date'] ?? null;
    $endDate = $_GET['end_date'] ?? $_POST['end_date'] ?? null;
    $personnelId = $_GET['personnel_id'] ?? $_POST['personnel_id'] ?? null;
    
    // 构建查询条件
    $whereConditions = ["project_id = :project_id"];
    $params = [':project_id' => $projectId];
    
    if ($startDate) {
        // 验证日期格式
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            echo json_encode(['success' => false, 'message' => '开始日期格式无效']);
            exit;
        }
        $whereConditions[] = "meal_date >= :start_date";
        $params[':start_date'] = $startDate;
    }
    
    if ($endDate) {
        // 验证日期格式
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            echo json_encode(['success' => false, 'message' => '结束日期格式无效']);
            exit;
        }
        $whereConditions[] = "meal_date <= :end_date";
        $params[':end_date'] = $endDate;
    }
    
    if ($personnelId) {
        // 验证人员 ID
        if (!is_numeric($personnelId)) {
            echo json_encode(['success' => false, 'message' => '人员 ID 无效']);
            exit;
        }
        $whereConditions[] = "personnel_id = :personnel_id";
        $params[':personnel_id'] = $personnelId;
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // 执行查询
    $query = "SELECT id, personnel_id, meal_date, meal_type, meal_count, 
                     special_requirements, created_at
              FROM meal_reports
              WHERE {$whereClause}
              ORDER BY meal_date, personnel_id, meal_type";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 返回成功结果
    echo json_encode([
        'success' => true,
        'count' => count($reports),
        'reports' => $reports,
        'filters' => [
            'project_id' => $projectId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'personnel_id' => $personnelId
        ]
    ]);
    
} catch (PDOException $e) {
    error_log("查询报餐记录失败：" . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => '数据库错误：' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("查询报餐记录失败：" . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => '服务器错误：' . $e->getMessage()
    ]);
}
