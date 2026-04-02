<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

$database = new Database();
$db = $database->getConnection();

session_start();

// 引入基础控制器进行权限验证
require_once '../includes/BaseAdminController.php';

// 检查是否为管理员
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

// 处理清理密码session的请求
if (isset($_GET['clear_password'])) {
    unset($_SESSION['new_admin_password']);
    // 通过AJAX请求，不需要重定向
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        exit;
    }
    // 普通请求重定向
    header("Location: add_admin_user.php");
    exit;
}

// 检查权限（需要是超级管理员或管理员）
if ($_SESSION['admin_role_key'] != 'super_admin' && $_SESSION['admin_role_key'] != 'admin') {
    header("Location: index.php");
    exit;
}

$message = '';
$error = '';

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $real_name = isset($_POST['real_name']) ? trim($_POST['real_name']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $role_id = isset($_POST['role_id']) ? intval($_POST['role_id']) : 0;
    $company_id = isset($_POST['company_id']) ? intval($_POST['company_id']) : null;
    
    // 验证输入
    if (empty($username)) {
        $error = '用户名不能为空';
    } elseif (empty($email)) {
        $error = '邮箱不能为空';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '邮箱格式不正确';
    } elseif ($role_id <= 0) {
        $error = '请选择角色';
    } else {
        // 检查用户名是否已存在
        $check_query = "SELECT COUNT(*) FROM admin_users WHERE username = :username";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':username', $username);
        $check_stmt->execute();
        
        if ($check_stmt->fetchColumn() > 0) {
            $error = '该用户名已存在';
        } else {
            // 检查邮箱是否已存在
            $check_email_query = "SELECT COUNT(*) FROM admin_users WHERE email = :email";
            $check_email_stmt = $db->prepare($check_email_query);
            $check_email_stmt->bindParam(':email', $email);
            $check_email_stmt->execute();
            
            if ($check_email_stmt->fetchColumn() > 0) {
                $error = '该邮箱已被注册';
            } else {
                // 生成随机密码
                $password = generateRandomPassword();
                $hashed_password = password_hash($password, PASSWORD_DEFAULT); // 使用bcrypt加密
                
                try {
                    // 获取角色的role_key以判断是否需要company_id
                    $role_query = "SELECT role_key FROM roles WHERE id = :role_id";
                    $role_stmt = $db->prepare($role_query);
                    $role_stmt->bindParam(':role_id', $role_id);
                    $role_stmt->execute();
                    $role_info = $role_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // 如果是管理员角色，必须选择公司
                    if ($role_info && $role_info['role_key'] == 'admin' && empty($company_id)) {
                        $error = '管理员角色必须选择所属公司';
                    } else {
                        // 插入新管理员用户
                        $insert_query = "INSERT INTO admin_users (username, password, email, real_name, phone, role_id, company_id, status) 
                                       VALUES (:username, :password, :email, :real_name, :phone, :role_id, :company_id, 1)";
                        $insert_stmt = $db->prepare($insert_query);
                        $insert_stmt->bindParam(':username', $username);
                        $insert_stmt->bindParam(':password', $hashed_password);
                        $insert_stmt->bindParam(':email', $email);
                        $insert_stmt->bindParam(':real_name', $real_name);
                        $insert_stmt->bindParam(':phone', $phone);
                        $insert_stmt->bindParam(':role_id', $role_id);
                        $insert_stmt->bindParam(':company_id', $company_id);
                        
                        if ($insert_stmt->execute()) {
                            $user_id = $db->lastInsertId();
                            $_SESSION['new_admin_password'] = [
                                'id' => $user_id,
                                'username' => $username,
                                'password' => $password,
                                'email' => $email
                            ];
                            
                            // 记录权限操作日志
                            try {
                                $log_query = "INSERT INTO permission_logs (operator_id, operator_type, action_type, target_type, target_id, action_detail, ip_address) 
                                            VALUES (:operator_id, 'admin', 'user_create', 'admin_user', :target_id, :action_detail, :ip_address)";
                                $log_stmt = $db->prepare($log_query);
                                $log_stmt->bindParam(':operator_id', $_SESSION['admin_user_id']);
                                $log_stmt->bindParam(':target_id', $user_id);
                                $log_stmt->bindValue(':action_detail', json_encode(['username' => $username, 'role_id' => $role_id]));
                                $log_stmt->bindValue(':ip_address', $_SERVER['REMOTE_ADDR']);
                                $log_stmt->execute();
                            } catch (Exception $e) {
                                error_log("权限日志记录失败: " . $e->getMessage());
                            }
                            
                            $message = "管理员添加成功！初始密码：{$password}";
                            
                            // 重定向到显示密码页面
                            header("Location: add_admin_user.php?show_password=1");
                            exit;
                        } else {
                            $error = '添加管理员失败，请稍后重试';
                        }
                    }
                } catch (Exception $e) {
                    $error = '添加管理员出错：' . $e->getMessage();
                }
            }
        }
    }
}

// 获取所有后台角色
$roles_query = "SELECT id, role_name, role_key FROM roles WHERE role_type = 'backend' ORDER BY id ASC";
$roles_stmt = $db->prepare($roles_query);
$roles_stmt->execute();
$roles = $roles_stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取所有公司
$companies_query = "SELECT id, name FROM companies ORDER BY name ASC";
$companies_stmt = $db->prepare($companies_query);
$companies_stmt->execute();
$companies = $companies_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<?php include 'includes/header.php'; ?>

<div class="container-fluid">
    <?php if (isset($message) && !isset($_GET['show_password'])): ?>
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

    <?php if (isset($_GET['show_password']) && isset($_SESSION['new_admin_password'])): ?>
        <!-- 密码显示页面 -->
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-check-circle"></i> 管理员添加成功</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <h6 class="mb-3">请妥善保管以下管理员账号信息：</h6>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label"><strong>用户名</strong></label>
                                <div class="input-group">
                                    <input type="text" class="form-control font-monospace" value="<?php echo htmlspecialchars($_SESSION['new_admin_password']['username']); ?>" readonly>
                                    <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard('<?php echo htmlspecialchars($_SESSION['new_admin_password']['username']); ?>')">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label"><strong>邮箱</strong></label>
                                <div class="input-group">
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($_SESSION['new_admin_password']['email']); ?>" readonly>
                                    <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard('<?php echo htmlspecialchars($_SESSION['new_admin_password']['email']); ?>')">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label"><strong>初始密码</strong></label>
                                <div class="input-group">
                                    <input type="text" class="form-control font-monospace text-danger fw-bold" id="password-field" value="<?php echo htmlspecialchars($_SESSION['new_admin_password']['password']); ?>" readonly>
                                    <button class="btn btn-outline-danger" type="button" onclick="togglePasswordVisibility()">
                                        <i class="bi bi-eye" id="toggle-icon"></i>
                                    </button>
                                    <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard('<?php echo htmlspecialchars($_SESSION['new_admin_password']['password']); ?>')">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                </div>
                                <small class="text-warning">
                                    <i class="bi bi-exclamation-triangle"></i> 
                                    请妥善保管此密码，用户首次登录后应立即修改密码
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-md-between mt-4">
                    <a href="add_admin_user.php" class="btn btn-primary">
                        <i class="bi bi-plus"></i> 继续添加
                    </a>
                    <div>
                        <a href="admin_users.php" class="btn btn-secondary" onclick="clearPassword()">
                            <i class="bi bi-arrow-left"></i> 返回管理员列表
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- 添加管理员表单 -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-person-plus"></i> 添加新管理员</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="username" class="form-label">用户名 *</label>
                                <input type="text" class="form-control" id="username" name="username" 
                                       placeholder="输入用户名（唯一）" required>
                                <small class="text-muted">用户登录时使用的用户名</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="email" class="form-label">邮箱 *</label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       placeholder="输入邮箱地址" required>
                                <small class="text-muted">用于接收系统通知和密码重置</small>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="real_name" class="form-label">真实姓名</label>
                                <input type="text" class="form-control" id="real_name" name="real_name" 
                                       placeholder="输入真实姓名（可选）">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="phone" class="form-label">手机号</label>
                                <input type="tel" class="form-control" id="phone" name="phone" 
                                       placeholder="输入手机号码（可选）">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="role_id" class="form-label">角色 *</label>
                                <select class="form-select" id="role_id" name="role_id" required onchange="updateCompanyField()">
                                    <option value="">-- 请选择角色 --</option>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?php echo $role['id']; ?>" data-role-key="<?php echo $role['role_key']; ?>">
                                            <?php echo htmlspecialchars($role['role_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">选择管理员的权限级别</small>
                            </div>
                        </div>
                        <div class="col-md-6" id="company-field">
                            <div class="mb-3">
                                <label for="company_id" class="form-label">所属公司 <span class="text-danger" id="company-required">*</span></label>
                                <select class="form-select" id="company_id" name="company_id">
                                    <option value="">-- 不选择公司 --</option>
                                    <?php foreach ($companies as $company): ?>
                                        <option value="<?php echo $company['id']; ?>">
                                            <?php echo htmlspecialchars($company['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted" id="company-help">
                                    仅管理员角色必须选择公司
                                </small>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-info mb-4">
                        <h6><i class="bi bi-info-circle"></i> 说明</h6>
                        <ul class="mb-0">
                            <li><strong>系统管理员</strong>：拥有系统所有权限，不受公司限制</li>
                            <li><strong>管理员</strong>：在指定公司范围内拥有管理权限</li>
                            <li><strong>项目管理员</strong>：仅限于指定项目的管理权限</li>
                        </ul>
                    </div>

                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <a href="permission_management.php" class="btn btn-secondary">取消</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-person-plus"></i> 添加管理员
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 现有管理员列表 -->
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-list"></i> 管理员列表</h5>
            </div>
            <div class="card-body">
                <?php
                // 获取管理员列表
                $list_query = "SELECT a.*, r.role_name, c.name as company_name 
                             FROM admin_users a
                             LEFT JOIN roles r ON a.role_id = r.id
                             LEFT JOIN companies c ON a.company_id = c.id
                             ORDER BY a.created_at DESC";
                $list_stmt = $db->prepare($list_query);
                $list_stmt->execute();
                $admin_users = $list_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($admin_users)):
                ?>
                    <div class="text-center py-4">
                        <i class="bi bi-inbox display-1 text-muted"></i>
                        <h5 class="text-muted">暂无管理员</h5>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th style="min-width: 120px; width: 15%;">用户名</th>
                                    <th style="min-width: 150px; width: 20%;">邮箱</th>
                                    <th style="min-width: 100px; width: 10%;">真实姓名</th>
                                    <th style="min-width: 100px; width: 10%;">角色</th>
                                    <th style="min-width: 120px; width: 15%;">所属公司</th>
                                    <th style="min-width: 80px; width: 8%;">状态</th>
                                    <th style="min-width: 120px; width: 12%;">最后登录</th>
                                    <th style="min-width: 80px; width: 10%;">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($admin_users as $admin): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($admin['username']); ?></td>
                                        <td><?php echo htmlspecialchars($admin['email']); ?></td>
                                        <td><?php echo htmlspecialchars($admin['real_name'] ?? '-'); ?></td>
                                        <td>
                                            <span class="badge bg-primary">
                                                <?php echo htmlspecialchars($admin['role_name']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($admin['company_name'] ?? '-'); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo $admin['status'] ? 'success' : 'secondary'; ?>">
                                                <?php echo $admin['status'] ? '启用' : '禁用'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            if ($admin['last_login_time']) {
                                                echo date('Y-m-d H:i', strtotime($admin['last_login_time']));
                                            } else {
                                                echo '<span class="text-muted">未登录</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="#" class="btn btn-outline-primary" title="编辑" 
                                                   onclick="editAdmin(<?php echo $admin['id']; ?>); return false;">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <button type="button" class="btn btn-outline-danger" title="删除"
                                                        onclick="deleteAdmin(<?php echo $admin['id']; ?>, '<?php echo htmlspecialchars($admin['username']); ?>')">
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
    <?php endif; ?>
</div>

<script>
function updateCompanyField() {
    const roleSelect = document.getElementById('role_id');
    const selectedOption = roleSelect.options[roleSelect.selectedIndex];
    const roleKey = selectedOption.getAttribute('data-role-key');
    const companyField = document.getElementById('company-field');
    const companySelect = document.getElementById('company_id');
    const companyRequired = document.getElementById('company-required');
    const companyHelp = document.getElementById('company-help');
    
    if (roleKey === 'admin') {
        // 管理员角色必须选择公司
        companySelect.setAttribute('required', 'required');
        companyRequired.style.display = 'inline';
        companyHelp.textContent = '管理员角色必须选择所属公司';
        companyField.style.display = 'block';
    } else {
        // 其他角色公司是可选的
        companySelect.removeAttribute('required');
        companyRequired.style.display = 'none';
        companyHelp.textContent = '选择此管理员所属的公司（可选）';
        companyField.style.display = 'block';
    }
}

function togglePasswordVisibility() {
    const passwordField = document.getElementById('password-field');
    const toggleIcon = document.getElementById('toggle-icon');
    
    if (passwordField.type === 'password') {
        passwordField.type = 'text';
        toggleIcon.classList.remove('bi-eye');
        toggleIcon.classList.add('bi-eye-slash');
    } else {
        passwordField.type = 'password';
        toggleIcon.classList.remove('bi-eye-slash');
        toggleIcon.classList.add('bi-eye');
    }
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        alert('已复制到剪贴板');
    }).catch(err => {
        console.error('复制失败:', err);
        alert('复制失败，请手动复制');
    });
}

function clearPassword() {
    // 清除session中的密码
    fetch('add_admin_user.php?clear_password=1', {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    }).then(() => {
        // 清除成功后重定向到管理员列表
        window.location.href = 'admin_users.php';
    });
}

function editAdmin(adminId) {
    alert('编辑功能开发中');
}

function deleteAdmin(adminId, username) {
    if (confirm(`确定要删除管理员 "${username}" 吗？此操作不可恢复！`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'delete_admin_user.php';
        form.innerHTML = `
            <input type="hidden" name="id" value="${adminId}">
            <input type="hidden" name="action" value="delete">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// 初始化页面时更新公司字段状态
document.addEventListener('DOMContentLoaded', function() {
    updateCompanyField();
});
</script>

<?php include 'includes/footer.php'; ?>
