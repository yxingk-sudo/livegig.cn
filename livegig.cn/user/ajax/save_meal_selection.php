<?php
// 报餐选择保存接口
// 功能：实时保存/取消餐次选择到 meal_reports 表
// 版本：2026-03-06-v1

require_once '../../config/database.php';
require_once '../../includes/functions.php';

session_start();

// 设置 JSON 响应头
header('Content-Type: application/json');

// 检查请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
    
    // 解析 JSON 输入
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        echo json_encode(['success' => false, 'message' => '无效的 JSON 数据']);
        exit;
    }
    
    // 获取参数
    $personnelId = $input['personnel_id'] ?? null;
    $mealDate = $input['meal_date'] ?? null;
    $mealType = $input['meal_type'] ?? null;
    $isSelected = $input['is_selected'] ?? false;
    $projectId = $input['project_id'] ?? $_SESSION['project_id'];
    
    // 验证必填字段
    if (empty($personnelId) || empty($mealDate) || empty($mealType)) {
        echo json_encode(['success' => false, 'message' => '缺少必填参数']);
        exit;
    }
    
    // 验证餐类型
    $validMealTypes = ['早餐', '午餐', '晚餐', '宵夜'];
    if (!in_array($mealType, $validMealTypes)) {
        echo json_encode(['success' => false, 'message' => '无效的餐类型']);
        exit;
    }
    
    // 验证日期格式
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $mealDate)) {
        echo json_encode(['success' => false, 'message' => '无效的日期格式']);
        exit;
    }
    
    // 检查项目餐类型配置
    try {
        $config_query = "SELECT breakfast_enabled, lunch_enabled, dinner_enabled, supper_enabled FROM projects WHERE id = :project_id";
        $config_stmt = $db->prepare($config_query);
        $config_stmt->execute([':project_id' => $projectId]);
        $config_row = $config_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($config_row) {
            $mealTypeEnabled = [
                '早餐' => (bool)$config_row['breakfast_enabled'],
                '午餐' => (bool)$config_row['lunch_enabled'],
                '晚餐' => (bool)$config_row['dinner_enabled'],
                '宵夜' => (bool)$config_row['supper_enabled']
            ];
            
            // 检查该餐类型是否启用
            if (isset($mealTypeEnabled[$mealType]) && !$mealTypeEnabled[$mealType]) {
                echo json_encode(['success' => false, 'message' => '该餐类型已被禁用，无法报餐']);
                exit;
            }
        }
    } catch (Exception $config_e) {
        // 如果查询失败，默认允许（兼容旧数据）
        error_log("获取餐类型配置失败：" . $config_e->getMessage());
    }
    
    // 开始事务
    $db->beginTransaction();
    
    if ($isSelected) {
        // 检查记录是否存在
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
            // 记录已存在，无需重复插入
            $db->commit();
            echo json_encode([
                'success' => true, 
                'message' => '记录已存在',
                'action' => 'exists'
            ]);
            exit;
        }
        
        // 插入新记录
        $insertQuery = "INSERT INTO meal_reports 
                       (project_id, personnel_id, meal_date, meal_type, meal_count, 
                        reported_by, created_at, updated_at) 
                       VALUES 
                       (:project_id, :personnel_id, :meal_date, :meal_type, 1, 
                        :reported_by, NOW(), NOW())";
        
        $insertStmt = $db->prepare($insertQuery);
        $result = $insertStmt->execute([
            ':project_id' => $projectId,
            ':personnel_id' => $personnelId,
            ':meal_date' => $mealDate,
            ':meal_type' => $mealType,
            ':reported_by' => $_SESSION['user_id']
        ]);
        
        if ($result) {
            $db->commit();
            echo json_encode([
                'success' => true, 
                'message' => '报餐成功',
                'action' => 'inserted'
            ]);
        } else {
            $db->rollback();
            echo json_encode([
                'success' => false, 
                'message' => '插入记录失败'
            ]);
        }
    } else {
        // 删除记录
        $deleteQuery = "DELETE FROM meal_reports 
                       WHERE project_id = :project_id 
                       AND personnel_id = :personnel_id 
                       AND meal_date = :meal_date 
                       AND meal_type = :meal_type";
        
        $deleteStmt = $db->prepare($deleteQuery);
        $result = $deleteStmt->execute([
            ':project_id' => $projectId,
            ':personnel_id' => $personnelId,
            ':meal_date' => $mealDate,
            ':meal_type' => $mealType
        ]);
        
        if ($result) {
            $db->commit();
            echo json_encode([
                'success' => true, 
                'message' => '取消报餐成功',
                'action' => 'deleted',
                'rows_affected' => $deleteStmt->rowCount()
            ]);
        } else {
            $db->rollback();
            echo json_encode([
                'success' => false, 
                'message' => '删除记录失败'
            ]);
        }
    }
    
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollback();
    }
    
    error_log("报餐保存失败：" . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => '数据库错误：' . $e->getMessage()
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollback();
    }
    
    error_log("报餐保存失败：" . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => '服务器错误：' . $e->getMessage()
    ]);
}
