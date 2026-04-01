<?php
/**
 * 管理员范围配置API
 * 用于管理项目管理员和管理员的公司/项目访问范围
 */

session_start();
require_once '../../config/database.php';
require_once '../../includes/PermissionMiddleware.php';

header('Content-Type: application/json');

try {
    $database = new Database();
    $db = $database->getConnection();
    $middleware = new PermissionMiddleware($db);
    $permissionManager = $middleware->getPermissionManager();
    
    // 验证登录状态
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        echo json_encode([
            'success' => false,
            'message' => '未登录'
        ]);
        exit;
    }
    
    $adminUserId = $_SESSION['admin_user_id'];
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    switch ($action) {
        case 'get_admin_scope':
            // 获取管理员范围配置
            $middleware->checkAdminApiPermission('backend:system:permission');
            
            $adminUserId = $_GET['admin_user_id'] ?? 0;
            if (!$adminUserId) {
                echo json_encode(['success' => false, 'message' => '管理员ID不能为空']);
                exit;
            }
            
            // 获取管理员角色信息
            $query = "SELECT au.company_id, r.role_key, r.role_name
                      FROM admin_users au
                      INNER JOIN roles r ON au.role_id = r.id
                      WHERE au.id = :admin_user_id";
            $stmt = $db->prepare($query);
            $stmt->execute([':admin_user_id' => $adminUserId]);
            $adminInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$adminInfo) {
                echo json_encode(['success' => false, 'message' => '管理员不存在']);
                exit;
            }
            
            $result = [
                'success' => true,
                'role_key' => $adminInfo['role_key'],
                'role_name' => $adminInfo['role_name'],
                'company_id' => $adminInfo['company_id']
            ];
            
            // 获取项目列表（所有角色都支持配置项目范围）
            $query = "SELECT project_id FROM admin_user_projects WHERE admin_user_id = :admin_user_id";
            $stmt = $db->prepare($query);
            $stmt->execute([':admin_user_id' => $adminUserId]);
            $projectIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $result['project_ids'] = $projectIds;

            echo json_encode($result);
            break;
            
        case 'save_admin_scope':
            // 保存管理员范围配置
            $middleware->checkAdminApiPermission('backend:system:permission');
            
            $adminUserId = $_POST['admin_user_id'] ?? 0;
            $projectIds = $_POST['project_ids'] ?? [];
            
            // 调试日志
            error_log("save_admin_scope - 接收数据: admin_user_id={$adminUserId}, project_ids_raw=" . print_r($projectIds, true));
            
            if (!$adminUserId) {
                echo json_encode(['success' => false, 'message' => '管理员ID不能为空']);
                exit;
            }
            
            if (!is_array($projectIds)) {
                $projectIds = json_decode($projectIds, true) ?? [];
            }
            
            error_log("save_admin_scope - 解析后 project_ids=" . print_r($projectIds, true));
            
            // 验证项目ID是否存在
            if (!empty($projectIds)) {
                // 将项目ID转换为整数（安全转换，不使用empty）
                $projectIds = array_map(function($id) {
                    return intval($id);
                }, $projectIds);

                // 移除重复和0值
                $projectIds = array_unique($projectIds);
                $projectIds = array_filter($projectIds, function($id) {
                    return $id > 0;
                });

                // 如果过滤后为空，跳过验证
                if (empty($projectIds)) {
                    $projectIds = [];
                } else {
                    $placeholders = str_repeat('?,', count($projectIds) - 1) . '?';
                    $validateQuery = "SELECT id FROM projects WHERE id IN ($placeholders)";
                    $validateStmt = $db->prepare($validateQuery);
                    $validateStmt->execute(array_values($projectIds));
                    $validProjectIds = $validateStmt->fetchAll(PDO::FETCH_COLUMN);

                    // 检查是否有无效的项目ID
                    $invalidIds = array_diff($projectIds, $validProjectIds);
                    if (!empty($invalidIds)) {
                        echo json_encode([
                            'success' => false,
                            'message' => '包含无效的项目ID: ' . implode(', ', $invalidIds)
                        ]);
                        exit;
                    }

                    // 使用验证通过的有效项目ID（重置数组键）
                    $projectIds = array_values($validProjectIds);
                }
            }

            error_log("save_admin_scope - 最终待插入 project_ids=" . print_r($projectIds, true));

            try {
                $db->beginTransaction();

                // 获取管理员角色信息
                $query = "SELECT r.role_key FROM admin_users au
                          INNER JOIN roles r ON au.role_id = r.id
                          WHERE au.id = :admin_user_id";
                $stmt = $db->prepare($query);
                $stmt->execute([':admin_user_id' => $adminUserId]);
                $roleKey = $stmt->fetchColumn();

                if (!$roleKey) {
                    throw new Exception('管理员不存在');
                }

                // 删除原有项目权限
                $deleteQuery = "DELETE FROM admin_user_projects WHERE admin_user_id = :admin_user_id";
                $deleteStmt = $db->prepare($deleteQuery);
                $deleteStmt->execute([':admin_user_id' => $adminUserId]);

                // 插入新项目权限
                if (!empty($projectIds)) {
                    $insertQuery = "INSERT INTO admin_user_projects (admin_user_id, project_id) VALUES (:admin_user_id, :project_id)";
                    $insertStmt = $db->prepare($insertQuery);

                    foreach ($projectIds as $projectId) {
                        error_log("插入项目权限 - admin_user_id: {$adminUserId}, project_id: {$projectId}");
                        try {
                            $insertStmt->execute([
                                ':admin_user_id' => $adminUserId,
                                ':project_id' => $projectId
                            ]);
                        } catch (Exception $e) {
                            error_log("插入项目权限失败 - admin_user_id: {$adminUserId}, project_id: {$projectId}, 错误: " . $e->getMessage());
                            throw new Exception("项目ID {$projectId} 插入失败: " . $e->getMessage());
                        }
                    }
                }
                
                $db->commit();
                
                // 记录日志
                $logQuery = "INSERT INTO permission_logs (operator_id, operator_type, action_type, target_type, target_id, action_detail, ip_address) 
                            VALUES (:operator_id, 'admin', 'user_update', 'admin_user', :target_id, :action_detail, :ip_address)";
                $logStmt = $db->prepare($logQuery);
                $logStmt->execute([
                    ':operator_id' => $adminUserId,
                    ':target_id' => $adminUserId,
                    ':action_detail' => json_encode([
                        'project_ids' => $projectIds,
                        'action' => 'save_admin_scope'
                    ]),
                    ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
                ]);
                
                echo json_encode([
                    'success' => true,
                    'message' => '范围配置保存成功'
                ]);
                
            } catch (Exception $e) {
                $db->rollBack();
                error_log("保存管理员范围配置错误: " . $e->getMessage());
                echo json_encode([
                    'success' => false,
                    'message' => '保存失败：' . $e->getMessage()
                ]);
            }
            break;
            
        case 'get_company_projects':
            // 获取公司下的项目列表
            $companyId = $_GET['company_id'] ?? 0;

            if (!$companyId) {
                echo json_encode(['success' => false, 'message' => '公司ID不能为空']);
                exit;
            }

            $query = "SELECT id, name FROM projects WHERE company_id = :company_id ORDER BY name ASC";
            $stmt = $db->prepare($query);
            $stmt->execute([':company_id' => $companyId]);
            $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'projects' => $projects
            ]);
            break;

        case 'get_all_projects':
            // 获取所有项目列表（带公司名称）
            $query = "SELECT p.id, p.name, c.name as company_name 
                      FROM projects p 
                      INNER JOIN companies c ON p.company_id = c.id 
                      ORDER BY c.name ASC, p.name ASC";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'projects' => $projects
            ]);
            break;

        default:
            echo json_encode([
                'success' => false,
                'message' => '无效的操作'
            ]);
            break;
    }
    
} catch (Exception $e) {
    error_log("管理员范围配置API错误: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '操作失败：' . $e->getMessage()
    ]);
}
?>