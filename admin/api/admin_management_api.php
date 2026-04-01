<?php
/**
 * 管理员管理API
 * 用于添加、编辑、删除管理员用户
 */

session_start();
require_once '../../config/database.php';
require_once '../../includes/PermissionMiddleware.php';
require_once '../../includes/functions.php';

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
        case 'add_admin_user':
            // 添加管理员用户
            $middleware->checkAdminApiPermission('backend:system:user:add');
            
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $realName = trim($_POST['real_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $roleId = intval($_POST['role_id'] ?? 0);
            
            // 验证输入
            if (empty($username)) {
                echo json_encode(['success' => false, 'message' => '用户名不能为空']);
                exit;
            }
            
            if (empty($email)) {
                echo json_encode(['success' => false, 'message' => '邮箱不能为空']);
                exit;
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => '邮箱格式不正确']);
                exit;
            }
            
            if (!$roleId) {
                echo json_encode(['success' => false, 'message' => '请选择角色']);
                exit;
            }
            
            // 检查用户名是否已存在
            $checkQuery = "SELECT COUNT(*) FROM admin_users WHERE username = :username";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->execute([':username' => $username]);
            
            if ($checkStmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => '该用户名已存在']);
                exit;
            }
            
            // 检查邮箱是否已存在
            $checkEmailQuery = "SELECT COUNT(*) FROM admin_users WHERE email = :email";
            $checkEmailStmt = $db->prepare($checkEmailQuery);
            $checkEmailStmt->execute([':email' => $email]);
            
            if ($checkEmailStmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => '该邮箱已被注册']);
                exit;
            }
            
            // 获取角色信息
            $roleQuery = "SELECT role_key FROM roles WHERE id = :role_id";
            $roleStmt = $db->prepare($roleQuery);
            $roleStmt->execute([':role_id' => $roleId]);
            $roleInfo = $roleStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$roleInfo) {
                echo json_encode(['success' => false, 'message' => '角色不存在']);
                exit;
            }
            
            // 生成随机密码
            $password = generateRandomPassword();
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT); // 使用bcrypt加密

            try {
                $db->beginTransaction();

                // 插入新管理员（系统采用项目级权限管理，不处理company_id）
                $insertQuery = "INSERT INTO admin_users (username, password, email, real_name, phone, role_id, status)
                               VALUES (:username, :password, :email, :real_name, :phone, :role_id, 1)";
                $insertStmt = $db->prepare($insertQuery);
                $insertStmt->execute([
                    ':username' => $username,
                    ':password' => $hashedPassword,
                    ':email' => $email,
                    ':real_name' => $realName,
                    ':phone' => $phone,
                    ':role_id' => $roleId
                ]);
                
                $newAdminId = $db->lastInsertId();
                
                $db->commit();
                
                // 记录日志
                $logQuery = "INSERT INTO permission_logs (operator_id, operator_type, action_type, target_type, target_id, action_detail, ip_address)
                            VALUES (:operator_id, 'admin', 'user_create', 'admin_user', :target_id, :action_detail, :ip_address)";
                $logStmt = $db->prepare($logQuery);
                $logStmt->execute([
                    ':operator_id' => $adminUserId,
                    ':target_id' => $newAdminId,
                    ':action_detail' => json_encode([
                        'username' => $username,
                        'email' => $email,
                        'role_id' => $roleId
                    ]),
                    ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
                ]);
                
                echo json_encode([
                    'success' => true,
                    'message' => '管理员添加成功',
                    'admin_id' => $newAdminId,
                    'password' => $password
                ]);
                
            } catch (Exception $e) {
                $db->rollBack();
                error_log("添加管理员错误: " . $e->getMessage());
                echo json_encode([
                    'success' => false,
                    'message' => '添加失败：' . $e->getMessage()
                ]);
            }
            break;
            
        case 'delete_admin_user':
            // 删除管理员用户
            $middleware->checkAdminApiPermission('backend:system:user:delete');
            
            $adminUserIdToDelete = intval($_POST['admin_user_id'] ?? 0);
            
            // 验证输入
            if (!$adminUserIdToDelete) {
                echo json_encode(['success' => false, 'message' => '管理员ID不能为空']);
                exit;
            }
            
            // 检查是否是当前登录用户（不能删除自己）
            if ($adminUserIdToDelete == $adminUserId) {
                echo json_encode(['success' => false, 'message' => '不能删除当前登录用户']);
                exit;
            }
            
            // 检查管理员是否存在
            $checkQuery = "SELECT id, username FROM admin_users WHERE id = :admin_user_id";
            $checkStmt = $db->prepare($checkQuery);
            $checkStmt->execute([':admin_user_id' => $adminUserIdToDelete]);
            $adminInfo = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$adminInfo) {
                echo json_encode(['success' => false, 'message' => '管理员不存在']);
                exit;
            }
            
            try {
                $db->beginTransaction();
                
                // 删除管理员项目权限（如果有的话）
                $deleteProjectsQuery = "DELETE FROM admin_user_projects WHERE admin_user_id = :admin_user_id";
                $deleteProjectsStmt = $db->prepare($deleteProjectsQuery);
                $deleteProjectsStmt->execute([':admin_user_id' => $adminUserIdToDelete]);
                
                // 删除管理员
                $deleteQuery = "DELETE FROM admin_users WHERE id = :admin_user_id";
                $deleteStmt = $db->prepare($deleteQuery);
                $deleteStmt->execute([':admin_user_id' => $adminUserIdToDelete]);
                
                $db->commit();
                
                // 记录日志
                $logQuery = "INSERT INTO permission_logs (operator_id, operator_type, action_type, target_type, target_id, action_detail, ip_address) 
                            VALUES (:operator_id, 'admin', 'user_delete', 'admin_user', :target_id, :action_detail, :ip_address)";
                $logStmt = $db->prepare($logQuery);
                $logStmt->execute([
                    ':operator_id' => $adminUserId,
                    ':target_id' => $adminUserIdToDelete,
                    ':action_detail' => json_encode([
                        'username' => $adminInfo['username']
                    ]),
                    ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
                ]);
                
                echo json_encode([
                    'success' => true,
                    'message' => '管理员删除成功'
                ]);
                
            } catch (Exception $e) {
                $db->rollBack();
                error_log("删除管理员错误: " . $e->getMessage());
                echo json_encode([
                    'success' => false,
                    'message' => '删除失败：' . $e->getMessage()
                ]);
            }
            break;
            
        case 'get_admin':
            // 获取管理员详细信息
            $middleware->checkAdminApiPermission('backend:system:user:edit');
            
            $adminUserIdToGet = intval($_GET['admin_user_id'] ?? 0);
            
            if (!$adminUserIdToGet) {
                echo json_encode([
                    'success' => false,
                    'message' => '缺少管理员ID'
                ]);
                break;
            }
            
            $stmt = $db->prepare('
                SELECT au.id, au.username, au.email, au.real_name, au.phone, 
                       au.role_id, au.company_id, au.status, r.role_name, r.role_key,
                       c.name as company_name
                FROM admin_users au
                LEFT JOIN roles r ON au.role_id = r.id
                LEFT JOIN companies c ON au.company_id = c.id
                WHERE au.id = ?
            ');
            $stmt->execute([$adminUserIdToGet]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$admin) {
                echo json_encode([
                    'success' => false,
                    'message' => '管理员不存在'
                ]);
                break;
            }
            
            echo json_encode([
                'success' => true,
                'admin' => $admin
            ]);
            break;
            
        case 'update_admin_user':
            // 更新管理员信息
            $middleware->checkAdminApiPermission('backend:system:user:edit');
            
            $adminUserIdToUpdate = intval($_POST['admin_user_id'] ?? 0);
            $email = trim($_POST['email'] ?? '');
            $realName = trim($_POST['real_name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $roleId = intval($_POST['role_id'] ?? 0);
            $status = isset($_POST['status']) ? intval($_POST['status']) : 1;
            
            // 验证必填字段
            if (!$adminUserIdToUpdate) {
                echo json_encode([
                    'success' => false,
                    'message' => '缺少管理员ID'
                ]);
                break;
            }
            
            if (!$email || !$roleId) {
                echo json_encode([
                    'success' => false,
                    'message' => '邮箱和角色为必填项'
                ]);
                break;
            }
            
            // 验证邮箱格式
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode([
                    'success' => false,
                    'message' => '邮箱格式不正确'
                ]);
                break;
            }
            
            // 获取角色信息
            $stmt = $db->prepare('SELECT role_key FROM roles WHERE id = ?');
            $stmt->execute([$roleId]);
            $roleInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$roleInfo) {
                echo json_encode([
                    'success' => false,
                    'message' => '角色不存在'
                ]);
                break;
            }
            
            // 检查邮箱是否已被其他管理员使用
            $stmt = $db->prepare('SELECT id FROM admin_users WHERE email = ? AND id != ?');
            $stmt->execute([$email, $adminUserIdToUpdate]);
            if ($stmt->fetch()) {
                echo json_encode([
                    'success' => false,
                    'message' => '该邮箱已被其他管理员使用'
                ]);
                break;
            }

            try {
                // 更新管理员信息（系统采用项目级权限管理，不处理company_id）
                $stmt = $db->prepare('
                    UPDATE admin_users
                    SET email = ?, real_name = ?, phone = ?,
                        role_id = ?, status = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ');

                $stmt->execute([
                    $email,
                    $realName,
                    $phone,
                    $roleId,
                    $status,
                    $adminUserIdToUpdate
                ]);
                
                echo json_encode([
                    'success' => true,
                    'message' => '管理员信息更新成功'
                ]);
                
            } catch (PDOException $e) {
                echo json_encode([
                    'success' => false,
                    'message' => '更新失败：' . $e->getMessage()
                ]);
            }
            break;
            
        case 'reset_admin_password':
            // 重置管理员密码
            $middleware->checkAdminApiPermission('backend:system:user:edit');
            
            $adminUserIdToReset = intval($_POST['admin_user_id'] ?? 0);
            
            if (!$adminUserIdToReset) {
                echo json_encode([
                    'success' => false,
                    'message' => '缺少管理员ID'
                ]);
                break;
            }
            
            // 生成随机密码
            $newPassword = generateRandomPassword();
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT); // 使用bcrypt加密
            
            try {
                $stmt = $db->prepare('
                    UPDATE admin_users 
                    SET password = ?, updated_at = NOW()
                    WHERE id = ?
                ');
                $stmt->execute([$hashedPassword, $adminUserIdToReset]);
                
                echo json_encode([
                    'success' => true,
                    'message' => '密码重置成功',
                    'password' => $newPassword
                ]);
                
            } catch (PDOException $e) {
                echo json_encode([
                    'success' => false,
                    'message' => '密码重置失败：' . $e->getMessage()
                ]);
            }
            break;
                    
                default:
            echo json_encode([
                'success' => false,
                'message' => '无效的操作'
            ]);
            break;
    }
    
} catch (Exception $e) {
    error_log("管理员管理API错误: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '操作失败：' . $e->getMessage()
    ]);
}
?>