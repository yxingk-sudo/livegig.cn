<?php
// 获取人员完整项目关联信息
error_reporting(0);
ini_set('display_errors', 0);

ob_clean();
header('Content-Type: application/json; charset=utf-8');
session_write_close();

$personnel_id = isset($_GET['personnel_id']) ? (int)$_GET['personnel_id'] : 0;

if (!$personnel_id) {
    echo json_encode(['success' => false, 'error' => '人员ID不能为空']);
    exit;
}

try {
    require_once dirname(__DIR__) . '/config/database.php';
    
    $database = new Database();
    $pdo = $database->getConnection();
    
    if (!$pdo) {
        echo json_encode(['success' => false, 'error' => '数据库连接失败']);
        exit;
    }
    
    // 获取人员基本信息
    $stmt = $pdo->prepare("SELECT id, name, email, phone FROM personnel WHERE id = ?");
    $stmt->execute([$personnel_id]);
    $personnel = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$personnel) {
        echo json_encode(['success' => false, 'error' => '人员不存在']);
        exit;
    }
    
    // 获取人员关联的所有项目和部门信息
    $stmt = $pdo->prepare("
        SELECT 
            pdp.project_id,
            pdp.department_id,
            pdp.position,
            p.name as project_name,
            p.code as project_code,
            c.id as company_id,
            c.name as company_name,
            d.name as department_name,
            pdp.join_date,
            pdp.status as relation_status
        FROM project_department_personnel pdp
        JOIN projects p ON pdp.project_id = p.id
        JOIN companies c ON p.company_id = c.id
        JOIN departments d ON pdp.department_id = d.id
        WHERE pdp.personnel_id = ?
        ORDER BY c.name, p.name, d.name
    ");
    $stmt->execute([$personnel_id]);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 按项目分组部门
    $groupedProjects = [];
    foreach ($projects as $project) {
        $key = $project['project_id'];
        if (!isset($groupedProjects[$key])) {
            $groupedProjects[$key] = [
                'project_id' => $project['project_id'],
                'project_name' => $project['project_name'],
                'project_code' => $project['project_code'],
                'company_id' => $project['company_id'],
                'company_name' => $project['company_name'],
                'position' => $project['position'],
                'departments' => []
            ];
        }
        $groupedProjects[$key]['departments'][] = [
            'department_id' => $project['department_id'],
            'department_name' => $project['department_name']
        ];
    }
    
    // 获取最新项目信息用于编辑
    $latestProject = null;
    if (!empty($projects)) {
        $latest = end($projects);
        $department_ids = [];
        foreach ($projects as $p) {
            if ($p['project_id'] == $latest['project_id']) {
                $department_ids[] = $p['department_id'];
            }
        }
        $latestProject = [
            'project_id' => $latest['project_id'],
            'company_id' => $latest['company_id'],
            'department_ids' => implode(',', $department_ids),
            'position' => $latest['position']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'personnel' => $personnel,
        'projects' => array_values($groupedProjects),
        'latest_project' => $latestProject,
        'total_count' => count($projects)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => '获取数据失败']);
}

exit;
?>