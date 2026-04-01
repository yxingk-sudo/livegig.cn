<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$response = ['success' => false, 'projects' => []];

try {
    if (!isset($_GET['company_id'])) {
        throw new Exception('缺少公司ID参数');
    }

    $company_id = intval($_GET['company_id']);
    
    // 验证公司是否存在
    $check_query = "SELECT COUNT(*) FROM companies WHERE id = :id";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(':id', $company_id);
    $check_stmt->execute();
    
    if ($check_stmt->fetchColumn() == 0) {
        throw new Exception('公司不存在');
    }

    // 获取公司项目数据
    $query = "SELECT id, name, code, start_date, end_date, status, created_at 
              FROM projects 
              WHERE company_id = :company_id 
              ORDER BY created_at DESC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':company_id', $company_id);
    $stmt->execute();
    
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 处理日期格式
    foreach ($projects as &$project) {
        if ($project['start_date']) {
            $project['start_date'] = date('Y-m-d', strtotime($project['start_date']));
        } else {
            $project['start_date'] = null;
        }
        
        if ($project['end_date']) {
            $project['end_date'] = date('Y-m-d', strtotime($project['end_date']));
        } else {
            $project['end_date'] = null;
        }
    }
    
    $response = [
        'success' => true,
        'projects' => $projects,
        'count' => count($projects)
    ];

} catch (Exception $e) {
    $response = [
        'success' => false,
        'message' => $e->getMessage()
    ];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>