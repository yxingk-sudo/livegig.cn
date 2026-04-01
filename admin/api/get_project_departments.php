<?php
// AJAX端点：根据项目id获取关联的部门列表
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';

try {
    $database = new Database();
    $pdo = $database->getConnection();
    
    if (!$pdo) {
        throw new Exception('数据库连接失败');
    }
    
    $project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
    
    if ($project_id > 0) {
        // 检查project_departments表是否存在
        $stmt = $pdo->query("SHOW TABLES LIKE 'project_departments'");
        $table_exists = $stmt->rowCount() > 0;
        
        $departments = [];
        
        if ($table_exists) {
            // 尝试使用project_departments关联表
            $stmt = $pdo->prepare("SELECT d.id, d.name, d.description 
                                 FROM departments d 
                                 JOIN project_departments pd ON d.id = pd.department_id 
                                 WHERE pd.project_id = ?
                                 ORDER BY d.name");
            $stmt->execute([$project_id]);
            $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // 如果关联表不存在或者没有数据，回退到departments表中的project_id字段
        if (empty($departments)) {
            $stmt = $pdo->prepare("SELECT id, name, description FROM departments WHERE project_id = ? ORDER BY name");
            $stmt->execute([$project_id]);
            $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        $departments = [];
    }
    
    echo json_encode(['success' => true, 'departments' => $departments]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>