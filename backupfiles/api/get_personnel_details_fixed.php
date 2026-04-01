<?php
// 修复版人员详情API
header('Content-Type: application/json; charset=utf-8');

$response = ['success' => false, 'error' => '', 'details' => null];

try {
    if (!isset($_GET['personnel_id'])) {
        throw new Exception('缺少人员ID参数');
    }
    
    $personnel_id = intval($_GET['personnel_id']);
    
    // 使用配置文件连接数据库
    require_once __DIR__ . '/../config/database.php';
    
    $database = new Database();
    $pdo = $database->getConnection();
    
    if (!$pdo) {
        throw new Exception('数据库连接失败');
    }
    
    // 查询人员基本信息
    $stmt = $pdo->prepare("SELECT * FROM personnel WHERE id = ?");
    $stmt->execute([$personnel_id]);
    $person = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$person) {
        throw new Exception('人员不存在');
    }
    
    // 查询人员项目关联
    $stmt = $pdo->prepare("SELECT 
        pp.id,
        pp.project_id,
        pp.company_id,
        pp.department_ids,
        pp.position,
        pp.status,
        c.name as company_name,
        p.name as project_name
    FROM personnel_projects pp
    LEFT JOIN projects p ON pp.project_id = p.id
    LEFT JOIN companies c ON pp.company_id = c.id
    WHERE pp.personnel_id = ?
    ORDER BY pp.created_at DESC
    LIMIT 1");
    $stmt->execute([$personnel_id]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($project) {
        $response['details'] = [
            'id' => $person['id'],
            'name' => $person['name'],
            'email' => $person['email'],
            'phone' => $person['phone'],
            'id_card' => $person['id_card'],
            'gender' => $person['gender'],
            'company_id' => $project['company_id'],
            'company_name' => $project['company_name'],
            'project_id' => $project['project_id'],
            'project_name' => $project['project_name'],
            'department_ids' => $project['department_ids'] ?? '',
            'position' => $project['position'] ?? '',
            'status' => $project['status'] ?? 'active'
        ];
    } else {
        $response['details'] = [
            'id' => $person['id'],
            'name' => $person['name'],
            'email' => $person['email'],
            'phone' => $person['phone'],
            'id_card' => $person['id_card'],
            'gender' => $person['gender'],
            'company_id' => '',
            'company_name' => '',
            'project_id' => '',
            'project_name' => '',
            'department_ids' => '',
            'position' => '',
            'status' => ''
        ];
    }
    
    $response['success'] = true;
    
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>