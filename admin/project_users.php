<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

$database = new Database();
$db = $database->getConnection();

session_start();

// 检查是否为管理员
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

// 获取网站配置
$database = new Database();
$db_config = $database->getConnection();
$site_config = [];
try {
    $query = "SELECT config_key, config_value FROM site_config WHERE config_key = 'site_url'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $site_url = $result ? $result['config_value'] : 'http://localhost';
} catch (Exception $e) {
    $site_url = 'http://localhost'; // 默认值
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$project_id = $_GET['project_id'] ?? $_POST['project_id'] ?? 0;

// 获取项目信息
$project = null;
if ($project_id) {
    $query = "SELECT * FROM projects WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $project_id);
    $stmt->execute();
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 处理清理密码session的请求
if (isset($_GET['clear_passwords'])) {
    unset($_SESSION['new_passwords']);
    header("Location: ?project_id={$project_id}");
    exit;
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $post_action = $_POST['action'] ?? $action;
    if ($post_action == 'add' || $post_action == 'edit') {
        $username = isset($_POST['username']) ? trim($_POST['username']) : '';
        $display_name = isset($_POST['display_name']) ? trim($_POST['display_name']) : '';
        $role = isset($_POST['role']) ? $_POST['role'] : 'user';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // 验证输入
        if (empty($username)) {
            $error = '用户名不能为空';
        } elseif (empty($display_name)) {
            $error = '显示名称不能为空';
        } else {
            if ($post_action == 'add') {
                // 检查用户名是否已存在
                $check_query = "SELECT COUNT(*) FROM project_users WHERE project_id = :project_id AND username = :username";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->bindParam(':project_id', $project_id);
                $check_stmt->bindParam(':username', $username);
                $check_stmt->execute();
                
                if ($check_stmt->fetchColumn() > 0) {
                    $error = '该用户名在当前项目中已存在';
                } else {
                    // 生成随机密码
                    $password = generateRandomPassword();
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    
                    $query = "INSERT INTO project_users (project_id, username, password, display_name, role, is_active) 
                              VALUES (:project_id, :username, :password, :display_name, :role, :is_active)";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':project_id', $project_id);
                    $stmt->bindParam(':username', $username);
                    $stmt->bindParam(':password', $hashed_password);
                    $stmt->bindParam(':display_name', $display_name);
                    $stmt->bindParam(':role', $role);
                    $stmt->bindParam(':is_active', $is_active);
                    
                    if ($stmt->execute()) {
                        // 将明文密码存储到session中以便显示
                        $user_id = $db->lastInsertId();
                        if (!isset($_SESSION['new_passwords'])) {
                            $_SESSION['new_passwords'] = [];
                        }
                        $_SESSION['new_passwords'][$user_id] = $password;
                        
                        // 处理前台权限角色分配（基于项目角色自动映射）
                        try {
                            // 映射项目角色到前台权限系统
                            $frontend_role_key = ($role == 'admin') ? 'user_admin' : 'user';
                            
                            // 获取前台权限角色ID
                            $role_query = "SELECT id FROM roles WHERE role_key = :role_key AND role_type = 'frontend'";
                            $role_stmt = $db->prepare($role_query);
                            $role_stmt->bindParam(':role_key', $frontend_role_key);
                            $role_stmt->execute();
                            $role_result = $role_stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($role_result) {
                                $role_id = $role_result['id'];
                                // 插入用户角色关联
                                $user_role_query = "INSERT INTO user_roles (user_id, role_id, project_id) VALUES (:user_id, :role_id, :project_id)";
                                $user_role_stmt = $db->prepare($user_role_query);
                                $user_role_stmt->bindParam(':user_id', $user_id);
                                $user_role_stmt->bindParam(':role_id', $role_id);
                                $user_role_stmt->bindParam(':project_id', $project_id);
                                $user_role_stmt->execute();
                            }
                        } catch (Exception $e) {
                            // 记录错误但不影响用户创建
                            error_log("前台角色分配失败: " . $e->getMessage());
                        }
                        
                        $message = "用户添加成功！初始密码：{$password}";
                        $action = 'list';
                    } else {
                        $error = '添加用户失败';
                    }
                }
            } elseif ($post_action == 'edit' && isset($_POST['id'])) {
                $id = intval($_POST['id']);
                
                $query = "UPDATE project_users SET username = :username, display_name = :display_name, 
                          role = :role, is_active = :is_active WHERE id = :id AND project_id = :project_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':username', $username);
                $stmt->bindParam(':display_name', $display_name);
                $stmt->bindParam(':role', $role);
                $stmt->bindParam(':is_active', $is_active);
                $stmt->bindParam(':id', $id);
                $stmt->bindParam(':project_id', $project_id);
                
                if ($stmt->execute()) {
                    // 处理前台权限角色分配（基于项目角色自动映射）
                    try {
                        // 映射项目角色到前台权限系统
                        $frontend_role_key = ($role == 'admin') ? 'user_admin' : 'user';
                        
                        // 先删除旧的角色关联
                        $delete_query = "DELETE FROM user_roles WHERE user_id = :user_id AND project_id = :project_id";
                        $delete_stmt = $db->prepare($delete_query);
                        $delete_stmt->bindParam(':user_id', $id);
                        $delete_stmt->bindParam(':project_id', $project_id);
                        $delete_stmt->execute();
                        
                        // 获取前台权限角色ID
                        $role_query = "SELECT id FROM roles WHERE role_key = :role_key AND role_type = 'frontend'";
                        $role_stmt = $db->prepare($role_query);
                        $role_stmt->bindParam(':role_key', $frontend_role_key);
                        $role_stmt->execute();
                        $role_result = $role_stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($role_result) {
                            $role_id = $role_result['id'];
                            // 插入新的用户角色关联
                            $user_role_query = "INSERT INTO user_roles (user_id, role_id, project_id) VALUES (:user_id, :role_id, :project_id)";
                            $user_role_stmt = $db->prepare($user_role_query);
                            $user_role_stmt->bindParam(':user_id', $id);
                            $user_role_stmt->bindParam(':role_id', $role_id);
                            $user_role_stmt->bindParam(':project_id', $project_id);
                            $user_role_stmt->execute();
                        }
                    } catch (Exception $e) {
                        // 记录错误但不影响用户更新
                        error_log("前台角色分配失败: " . $e->getMessage());
                    }
                    
                    $message = '用户更新成功！';
                    $action = 'list';
                } else {
                    $error = '更新用户失败';
                }
            }
        }
    } elseif ($post_action == 'reset_password' && isset($_POST['id'])) {
        $id = intval($_POST['id']);
        
        // 生成新密码
        $new_password = generateRandomPassword();
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        $query = "UPDATE project_users SET password = :password WHERE id = :id AND project_id = :project_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':password', $hashed_password);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':project_id', $project_id);
        
        if ($stmt->execute()) {
            // 将明文密码存储到session中以便显示
            if (!isset($_SESSION['new_passwords'])) {
                $_SESSION['new_passwords'] = [];
            }
            $_SESSION['new_passwords'][$id] = $new_password;
            
            $message = "密码重置成功！新密码：{$new_password}";
            // 更新用户列表中的密码显示
            $query = "SELECT * FROM project_users WHERE id = :id AND project_id = :project_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->bindParam(':project_id', $project_id);
            $stmt->execute();
            $updated_user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($updated_user) {
                // 重新获取所有用户以确保密码同步
                $query = "SELECT * FROM project_users WHERE project_id = :project_id ORDER BY created_at DESC";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':project_id', $project_id);
                $stmt->execute();
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } else {
            $error = '密码重置失败';
        }
        $action = 'list';
    } elseif ($post_action == 'delete' && isset($_POST['id'])) {
        $id = intval($_POST['id']);
        
        $query = "DELETE FROM project_users WHERE id = :id AND project_id = :project_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':project_id', $project_id);
        
        if ($stmt->execute()) {
            $message = '用户删除成功！';
        } else {
            $error = '删除用户失败';
        }
        $action = 'list';
    }
}

// 获取用户列表
$users = [];
if ($project_id) {
    $query = "SELECT * FROM project_users WHERE project_id = :project_id ORDER BY created_at DESC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':project_id', $project_id);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 获取所有项目
$projects_query = "SELECT p.*, c.name as company_name 
                   FROM projects p 
                   JOIN companies c ON p.company_id = c.id 
                   ORDER BY p.created_at DESC";
$projects_stmt = $db->prepare($projects_query);
$projects_stmt->execute();
$all_projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取编辑用户信息
$edit_user = null;
if ($action == 'edit' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $query = "SELECT * FROM project_users WHERE id = :id AND project_id = :project_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $id);
    $stmt->bindParam(':project_id', $project_id);
    $stmt->execute();
    $edit_user = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<?php include 'includes/header.php'; ?>

<div class="container-fluid">
    <?php if (isset($message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!$project_id): ?>
        <!-- 项目选择页面 -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">选择项目</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">请选择一个项目来管理用户</p>
                <div class="list-group">
                    <?php foreach ($all_projects as $proj): ?>
                        <a href="?project_id=<?php echo $proj['id']; ?>" class="list-group-item list-group-item-action">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1"><?php echo htmlspecialchars($proj['name']); ?></h6>
                                <small><?php echo htmlspecialchars($proj['company_name']); ?></small>
                            </div>
                            <p class="mb-1">项目代码：<?php echo htmlspecialchars($proj['code']); ?></p>
                            <small class="text-muted">状态：<?php echo $proj['status']; ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- 用户管理页面 -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><?php echo htmlspecialchars($project['name']); ?> - 用户列表</h5>
                <div>
                    <?php if (!empty($_SESSION['new_passwords'])): ?>
                    <a href="?project_id=<?php echo $project_id; ?>&clear_passwords=1" class="btn btn-warning btn-sm me-2" onclick="return confirm('确定要清除所有显示的密码吗？')">
                        <i class="bi bi-eye-slash"></i> 隐藏密码
                    </a>
                    <?php endif; ?>
                    <a href="?action=add&project_id=<?php echo $project_id; ?>" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus"></i> 添加用户
                    </a>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($users)): ?>
                    <div class="text-center py-4">
                        <i class="bi bi-inbox display-1 text-muted"></i>
                        <h5 class="text-muted">暂无用户</h5>
                        <p class="text-muted">请先添加项目用户</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>用户名</th>
                                    <th>显示名称</th>
                                    <th>密码</th>
                                    <th>角色</th>
                                    <th>状态</th>
                                    <th>创建时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <tr id="user-row-<?php echo $user['id']; ?>">
                                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                                        <td><?php echo htmlspecialchars($user['display_name']); ?></td>
                                        <td>
                                            <span class="password-display font-monospace text-danger fw-bold" id="password-<?php echo $user['id']; ?>">
                                                <?php 
                                                if (isset($_SESSION['new_passwords'][$user['id']])) {
                                                    echo htmlspecialchars($_SESSION['new_passwords'][$user['id']]);
                                                } else {
                                                    echo '<span class="text-muted">已设置</span>';
                                                }
                                                ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $user['role'] == 'admin' ? 'danger' : 'primary'; ?>">
                                                <?php echo $user['role'] == 'admin' ? '管理员' : '普通用户'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $user['is_active'] ? 'success' : 'secondary'; ?>">
                                                <?php echo $user['is_active'] ? '启用' : '禁用'; ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($user['created_at'])); ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="?action=edit&project_id=<?php echo $project_id; ?>&id=<?php echo $user['id']; ?>" 
                                                   class="btn btn-outline-primary" title="编辑">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <button type="button" class="btn btn-outline-warning" 
                                                        onclick="resetPassword(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" 
                                                        title="重置密码">
                                                    <i class="bi bi-key"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-danger" 
                                                        onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" 
                                                        title="删除">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 项目访问信息 -->
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0">项目访问信息</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>项目登录地址：</h6>
                        <code><?php echo rtrim($site_url, '/'); ?>/user/project_login.php?code=<?php echo htmlspecialchars($project['code']); ?></code>
                          <button class="btn btn-sm btn-outline-primary ms-2" onclick="copyToClipboard('<?php echo rtrim($site_url, '/'); ?>/user/project_login.php?code=<?php echo htmlspecialchars($project['code']); ?>')">
                            <i class="bi bi-clipboard"></i> 复制
                        </button>
                    </div>
                    <div class="col-md-6">
                        <h6>项目代码：</h6>
                        <code><?php echo htmlspecialchars($project['code']); ?></code>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($action == 'add' || $action == 'edit'): ?>
            <!-- 用户表单 -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0"><?php echo $action == 'add' ? '添加新用户' : '编辑用户'; ?></h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="<?php echo $action; ?>">
                        <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                        <?php if ($action == 'edit'): ?>
                        <input type="hidden" name="id" value="<?php echo $edit_user['id']; ?>">
                        <?php endif; ?>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="username" class="form-label">用户名 *</label>
                                    <input type="text" class="form-control" id="username" name="username" 
                                           value="<?php echo $edit_user ? htmlspecialchars($edit_user['username']) : ''; ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="display_name" class="form-label">显示名称 *</label>
                                    <input type="text" class="form-control" id="display_name" name="display_name" 
                                           value="<?php echo $edit_user ? htmlspecialchars($edit_user['display_name']) : ''; ?>" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="role" class="form-label">前台权限</label>
                                    <select class="form-select" id="role" name="role">
                                        <option value="user" <?php echo $edit_user && $edit_user['role'] == 'user' ? 'selected' : ''; ?>>前台用户（普通用户）</option>
                                        <option value="admin" <?php echo $edit_user && $edit_user['role'] == 'admin' ? 'selected' : ''; ?>>前台管理员</option>
                                    </select>
                                    <small class="text-muted">选择用户在前台系统中的权限级别</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">状态</label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                               value="1" <?php echo !$edit_user || $edit_user['is_active'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_active">
                                            启用账户
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="?project_id=<?php echo $project_id; ?>" class="btn btn-secondary">取消</a>
                            <button type="submit" class="btn btn-primary">
                                <?php echo $action == 'add' ? '添加用户' : '更新用户'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
function resetPassword(userId, username) {
    if (confirm(`确定要重置用户 "${username}" 的密码吗？`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="id" value="${userId}">
            <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function deleteUser(userId, username) {
    if (confirm(`确定要删除用户 "${username}" 吗？此操作不可恢复！`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="${userId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        alert('已复制到剪贴板：' + text);
    }).catch(err => {
        console.error('复制失败:', err);
        alert('复制失败，请手动复制');
    });
}
</script>

<?php include 'includes/footer.php'; ?>