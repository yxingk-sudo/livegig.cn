<?php
/**
 * 获取部门人员映射
 * 返回指定部门的所有人员 ID 列表
 */

require_once '../../config/database.php';
require_once '../../includes/functions.php';

session_start();
requireLogin();
checkProjectPermission($_SESSION['project_id']);

header('Content-Type: application/json');

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $projectId = $_SESSION['project_id'];
    
    // 获取部门 ID 列表
    $deptIds = isset($_GET['dept_ids']) ? explode(',', $_GET['dept_ids']) : [];
    $deptIds = array_map('intval', $deptIds);
    $deptIds = array_filter($deptIds); // 移除空值
    
    if (empty($deptIds)) {
        echo json_encode(['success' => true, 'personnel_ids' => []]);
        exit;
    }
    
    // 查询这些部门下的所有人员
    $placeholders = str_repeat('?,', count($deptIds) - 1) . '?';
    $query = "SELECT DISTINCT p.id, p.name 
              FROM personnel p
              JOIN project_department_personnel pdp ON p.id = pdp.personnel_id
              WHERE pdp.project_id = ? 
              AND pdp.department_id IN ($placeholders) 
              AND pdp.status = 'active'";
    
    $stmt = $db->prepare($query);
    
    // 绑定参数：项目 ID + 部门 IDs
    $params = array_merge([$projectId], $deptIds);
    $stmt->execute($params);
    
    $personnelList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 构建人员 ID 列表和姓名映射
    $personnelIds = array_column($personnelList, 'id');
    $personnelNames = [];
    foreach ($personnelList as $person) {
        // 确保 name 不为空
        $personId = intval($person['id']);
        $personName = !empty($person['name']) ? $person['name'] : "未知人员{$personId}";
        $personnelNames[$personId] = $personName;
    }
    
    // 调试日志（开发环境可启用）
    error_log('部门 ID: ' . json_encode($deptIds));
    error_log('查询结果数：' . count($personnelList));
    error_log('人员 IDs: ' . json_encode($personnelIds));
    error_log('人员姓名映射：' . json_encode($personnelNames));
    
    echo json_encode([
        'success' => true,
        'personnel_ids' => array_map('intval', $personnelIds),
        'personnel_names' => $personnelNames,
        'count' => count($personnelIds)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
