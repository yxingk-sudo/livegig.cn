<?php
// 保存个人套餐分配的 AJAX 接口
header("Content-Type: application/json");
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once '../../config/database.php';
require_once '../../includes/functions.php';

session_start();

// 验证登录
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '未登录或会话已过期']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // 验证项目权限
    if (!isset($_SESSION['project_id'])) {
        echo json_encode(['success' => false, 'message' => '无效的项目 ID']);
        exit;
    }
    
    $projectId = $_SESSION['project_id'];
    
    // 读取 JSON 输入
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['assignments']) || !is_array($input['assignments'])) {
        echo json_encode(['success' => false, 'message' => '无效的分配数据']);
        exit;
    }
    
    $assignments = $input['assignments'];
    $savedCount = 0;
    
    // 开始事务
    $db->beginTransaction();
    
    try {
        foreach ($assignments as $assign) {
            $personnelId = $assign['personnel_id'] ?? null;
            $mealType = $assign['meal_type'] ?? null;
            $packageId = $assign['package_id'] ?? null;
            $mealDate = $assign['meal_date'] ?? null; // 新增：获取日期
            
            if (!$personnelId || !$mealType || !$packageId || !$mealDate) {
                continue;
            }
            
            // 查找现有的 meal_report 记录
            $reportCheckQuery = "SELECT id FROM meal_reports 
                                WHERE project_id = :project_id 
                                AND meal_date = :meal_date 
                                AND meal_type = :meal_type 
                                LIMIT 1";
            
            $reportCheckStmt = $db->prepare($reportCheckQuery);
            $reportCheckStmt->execute([
                ':project_id' => $projectId,
                ':meal_date' => $mealDate,
                ':meal_type' => $mealType
            ]);
            
            $reportRecord = $reportCheckStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$reportRecord) {
                // 创建新的 meal_report 记录
                // 注意：meal_reports 表需要 personnel_id 和 reported_by，但我们按日期 + 餐类型分组
                // 所以使用 0 作为默认值，表示这是一个汇总报告
                $insertReportQuery = "INSERT INTO meal_reports (project_id, personnel_id, meal_date, meal_type, reported_by, created_at) 
                                     VALUES (:project_id, 0, :meal_date, :meal_type, :reported_by, NOW())";
                
                $insertReportStmt = $db->prepare($insertReportQuery);
                $insertReportStmt->execute([
                    ':project_id' => $projectId,
                    ':meal_date' => $mealDate,
                    ':meal_type' => $mealType,
                    ':reported_by' => $_SESSION['user_id'] ?? 1 // 使用当前登录用户 ID
                ]);
                
                $reportId = $db->lastInsertId();
            } else {
                $reportId = $reportRecord['id'];
            }
            
            // 查询现有的 meal_report_details 记录
            $checkQuery = "SELECT mrd.id 
                          FROM meal_report_details mrd
                          WHERE mrd.report_id = :report_id 
                          AND mrd.personnel_id = :personnel_id 
                          LIMIT 1";
            
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->execute([
                ':report_id' => $reportId,
                ':personnel_id' => $personnelId
            ]);
            
            $existingRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingRecord) {
                // 更新现有记录
                $updateQuery = "UPDATE meal_report_details 
                               SET package_id = :package_id 
                               WHERE id = :id";
                
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->execute([
                    ':package_id' => $packageId,
                    ':id' => $existingRecord['id']
                ]);
            } else {
                // 创建新记录
                $insertQuery = "INSERT INTO meal_report_details (report_id, personnel_id, package_id, meal_count, created_at) 
                               VALUES (:report_id, :personnel_id, :package_id, 1, NOW())";
                
                $insertStmt = $db->prepare($insertQuery);
                $insertStmt->execute([
                    ':report_id' => $reportId,
                    ':personnel_id' => $personnelId,
                    ':package_id' => $packageId
                ]);
            }
            
            $savedCount++;
        }
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => '保存成功',
            'data' => [
                'saved_count' => $savedCount
            ]
        ]);
        
    } catch (PDOException $e) {
        $db->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => '服务器错误：' . $e->getMessage()
    ]);
}
