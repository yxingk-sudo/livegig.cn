<?php
/**
 * 批量保存餐次选择
 * 用于批量报餐功能，一次性保存多条记录
 */

require_once '../../includes/db.php';

header('Content-Type: application/json');

// 验证登录
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '请先登录']);
    exit;
}

// 获取 JSON 数据
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['records']) || !is_array($data['records'])) {
    echo json_encode(['success' => false, 'message' => '无效的数据格式']);
    exit;
}

$projectId = intval($data['project_id'] ?? 0);
$records = $data['records'];
$savedCount = 0;
$failedRecords = [];

try {
    // 开始事务
    $db->beginTransaction();
    
    foreach ($records as $record) {
        $personnelId = intval($record['personnel_id'] ?? 0);
        $mealDate = $record['meal_date'] ?? '';
        $mealType = $record['meal_type'] ?? '';
        
        if (!$personnelId || !$mealDate || !$mealType) {
            $failedRecords[] = [
                'record' => $record,
                'reason' => '缺少必填字段'
            ];
            continue;
        }
        
        // 检查是否已存在记录
        $checkQuery = "SELECT id FROM meal_reports 
                      WHERE project_id = :project_id 
                      AND personnel_id = :personnel_id 
                      AND meal_date = :meal_date 
                      AND meal_type = :meal_type";
        
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->execute([
            ':project_id' => $projectId,
            ':personnel_id' => $personnelId,
            ':meal_date' => $mealDate,
            ':meal_type' => $mealType
        ]);
        
        $existingRecord = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existingRecord) {
            // 已存在，跳过或更新状态
            $updateQuery = "UPDATE meal_reports 
                           SET status = 'pending', 
                               updated_at = NOW() 
                           WHERE id = :id";
            
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->execute([
                ':id' => $existingRecord['id']
            ]);
        } else {
            // 插入新记录
            $insertQuery = "INSERT INTO meal_reports 
                           (project_id, personnel_id, meal_date, meal_type, reported_by, status, created_at) 
                           VALUES 
                           (:project_id, :personnel_id, :meal_date, :meal_type, :reported_by, 'pending', NOW())";
            
            $insertStmt = $db->prepare($insertQuery);
            $insertStmt->execute([
                ':project_id' => $projectId,
                ':personnel_id' => $personnelId,
                ':meal_date' => $mealDate,
                ':meal_type' => $mealType,
                ':reported_by' => $_SESSION['user_id']
            ]);
        }
        
        $savedCount++;
    }
    
    // 提交事务
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => '批量保存成功',
        'saved_count' => $savedCount,
        'total_count' => count($records),
        'failed_count' => count($failedRecords),
        'failed_records' => $failedRecords
    ]);
    
} catch (PDOException $e) {
    // 回滚事务
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    error_log('批量保存餐次失败: ' . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => '数据库错误：' . $e->getMessage()
    ]);
} catch (Exception $e) {
    // 回滚事务
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    error_log('批量保存餐次失败: ' . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => '服务器错误：' . $e->getMessage()
    ]);
}
