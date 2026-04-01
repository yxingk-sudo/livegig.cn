<?php
// 简化版人员详情API
header('Content-Type: application/json; charset=utf-8');

$response = ['success' => false, 'error' => '', 'details' => null];

try {
    if (!isset($_GET['personnel_id'])) {
        throw new Exception('缺少人员ID参数');
    }
    
    $personnel_id = intval($_GET['personnel_id']);
    
    // 直接连接数据库
    $host = 'localhost';
    $dbname = 'team_reception';
    $username = 'team_reception';
    $password = 'team_reception';
    
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 查询人员基本信息
    $stmt = $pdo->prepare("SELECT * FROM personnel WHERE id = ?");
    $stmt->execute([$personnel_id]);
    $person = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$person) {
        throw new Exception('人员不存在');
    }
    
    // 查询人员项目关联 - 获取所有关联项目
    $stmt = $pdo->prepare("SELECT 
        pdp.*, 
        c.name as company_name,
        pr.name as project_name,
        GROUP_CONCAT(d.id) as department_ids
    FROM project_department_personnel pdp
    LEFT JOIN projects pr ON pdp.project_id = pr.id
    LEFT JOIN companies c ON pr.company_id = c.id
    LEFT JOIN departments d ON pdp.department_id = d.id
    WHERE pdp.personnel_id = ?
    GROUP BY pdp.id
    ORDER BY pdp.created_at DESC
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
            'department_ids' => $project['department_id'],
            'position' => $project['position'],
            'status' => $project['status']
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