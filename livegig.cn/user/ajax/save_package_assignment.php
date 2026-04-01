<?php
/**
 * AJAX 接口：保存套餐分配
 * 用途：保存每日每餐的套餐分配信息
 */

header('Content-Type: application/json');
require_once '../../config/database.php';
require_once '../../includes/functions.php';

session_start();

// 验证登录状态
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '未登录或会话已过期']);
    exit;
}

// 验证请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '无效的请求方法']);
    exit;
}

try {
    // 获取数据库连接
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception("数据库连接失败");
    }
    
    // 解析 JSON 输入
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!is_array($input)) {
        throw new Exception("无效的输入数据");
    }
    
    $projectId = $input['project_id'] ?? $_SESSION['project_id'] ?? 0;
    $assignments = $input['assignments'] ?? [];
    
    if (empty($projectId)) {
        throw new Exception("项目 ID 无效");
    }
    
    if (empty($assignments)) {
        echo json_encode(['success' => false, 'message' => '没有需要保存的分配数据']);
        exit;
    }
    
    // 开始事务
    $db->beginTransaction();
    
    try {
        $savedCount = 0;
        $deletedCount = 0;
        
        // 处理每个分配
        foreach ($assignments as $assignment) {
            $mealDate = $assignment['meal_date'] ?? null;
            $mealType = $assignment['meal_type'] ?? null;
            $packageIds = $assignment['package_ids'] ?? [];
            
            if (empty($mealDate) || empty($mealType) || empty($packageIds)) {
                continue;
            }
            
            // 删除该日期和餐类型的旧分配
            $deleteQuery = "DELETE FROM meal_package_assignments 
                           WHERE project_id = :project_id 
                           AND meal_date = :meal_date 
                           AND meal_type = :meal_type";
            $deleteStmt = $db->prepare($deleteQuery);
            $deleteStmt->execute([
                ':project_id' => $projectId,
                ':meal_date' => $mealDate,
                ':meal_type' => $mealType
            ]);
            $deletedCount += $deleteStmt->rowCount();
            
            // 插入新的分配
            $insertQuery = "INSERT INTO meal_package_assignments 
                           (project_id, meal_date, meal_type, package_id, created_at, updated_at) 
                           VALUES (:project_id, :meal_date, :meal_type, :package_id, NOW(), NOW())";
            $insertStmt = $db->prepare($insertQuery);
            
            foreach ($packageIds as $packageId) {
                $insertStmt->execute([
                    ':project_id' => $projectId,
                    ':meal_date' => $mealDate,
                    ':meal_type' => $mealType,
                    ':package_id' => $packageId
                ]);
                $savedCount++;
            }
        }
        
        // 提交事务
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => '保存成功',
            'data' => [
                'saved_count' => $savedCount,
                'deleted_count' => $deletedCount
            ]
        ]);
        
    } catch (Exception $e) {
        // 回滚事务
        $db->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    // 记录错误日志
    error_log("套餐分配保存失败：" . $e->getMessage());
    error_log("堆栈跟踪：" . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'message' => '保存失败：' . $e->getMessage()
    ]);
    exit;
}
