<?php
// 修复版profile.php - 添加详细的错误处理和调试信息
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 所有PHP逻辑必须在任何输出之前完成
session_start();

// 检查用户是否登录
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// 添加调试信息
$debug_info = [];
$debug_info[] = "Session started - user_id: " . $_SESSION['user_id'];

// 数据库连接已包含在header.php中
$debug_info[] = "Database config included successfully";

// 获取数据库连接
try {
    $database = new Database();
    $pdo = $database->getConnection();
    $debug_info[] = "Database connection established";
} catch (Exception $e) {
    $_SESSION['error'] = "数据库连接失败: " . $e->getMessage();
    header("Location: dashboard.php");
    exit();
}

// 初始化变量
$user = null;
$errors = [];
$success_message = null;

// 获取用户信息 - 添加错误处理
try {
    $stmt = $pdo->prepare("SELECT u.*, p.name as project_name FROM project_users u LEFT JOIN projects p ON u.project_id = p.id WHERE u.id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        $_SESSION['error'] = "用户不存在，ID: " . $_SESSION['user_id'];
        header("Location: dashboard.php");
        exit();
    }
    $debug_info[] = "User data loaded successfully";
} catch (PDOException $e) {
    $_SESSION['error'] = "获取用户信息失败: " . $e->getMessage();
    header("Location: dashboard.php");
    exit();
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // 验证输入
    if (empty($username)) {
        $errors[] = "用户名不能为空";
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "请输入有效的邮箱地址";
    }
    
    // 检查用户名和邮箱是否已存在
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM project_users WHERE username = ? AND id != ?");
        $stmt->execute([$username, $_SESSION['user_id']]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "用户名已存在";
        }
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM project_users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $_SESSION['user_id']]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "邮箱已存在";
        }
    } catch (PDOException $e) {
        $errors[] = "验证用户信息失败: " . $e->getMessage();
    }
    
    // 处理密码修改
    if (!empty($new_password)) {
        if (empty($current_password)) {
            $errors[] = "修改密码需要输入当前密码";
        } else {
            // 验证当前密码
            $stmt = $pdo->prepare("SELECT password FROM project_users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $stored_password = $stmt->fetchColumn();
            
            if (!password_verify($current_password, $stored_password)) {
                $errors[] = "当前密码错误";
            }
            
            if ($new_password !== $confirm_password) {
                $errors[] = "两次输入的新密码不一致";
            }
            
            if (strlen($new_password) < 6) {
                $errors[] = "新密码长度至少6位";
            }
        }
    }
    
    // 如果没有错误，更新用户信息
    if (empty($errors)) {
        try {
            $update_sql = "UPDATE project_users SET username = ?, email = ?, updated_at = NOW()";
            $params = [$username, $email];
            
            if (!empty($new_password)) {
                $update_sql .= ", password = ?";
                $params[] = password_hash($new_password, PASSWORD_DEFAULT);
            }
            
            $update_sql .= " WHERE id = ?";
            $params[] = $_SESSION['user_id'];
            
            $stmt = $pdo->prepare($update_sql);
            $stmt->execute($params);
            
            $_SESSION['success'] = "个人资料更新成功";
            $_SESSION['username'] = $username;
            
            header("Location: profile.php");
            exit();
        } catch (PDOException $e) {
            $errors[] = "更新失败: " . $e->getMessage();
        }
    }
}

// 设置页面变量
$page_title = '个人资料';
$active_page = 'profile';

// 检查是否有会话消息
if (isset($_SESSION['success'])) {
    $success_message = $_SESSION['success'];
    unset($_SESSION['success']);
}

// 现在可以安全地包含头部文件了
ob_start();
try {
    include 'includes/hand.php';
    $debug_info[] = "Hand.php included successfully";
} catch (Exception $e) {
    die("包含hand.php失败: " . $e->getMessage());
}
$header_output = ob_get_clean();

// 显示调试信息（开发环境）
if (isset($_GET['debug'])) {
    echo "<div style='background: #f5f5f5; padding: 10px; margin: 10px; border: 1px solid #ddd;'>";
    echo "<h3>调试信息</h3>";
    foreach ($debug_info as $info) {
        echo "<p>" . htmlspecialchars($info) . "</p>";
    }
    echo "</div>";
}

// 显示成功或错误消息
if ($success_message) {
    echo '<div class="alert alert-success alert-dismissible fade show" role="alert">';
    echo htmlspecialchars($success_message);
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
}

if (!empty($errors)) {
    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">';
    echo '<ul class="mb-0">';
    foreach ($errors as $error) {
        echo "<li>" . htmlspecialchars($error) . "</li>";
    }
    echo '</ul>';
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
}
?>

<!-- 修复版HTML结构 -->
<div class="container-fluid mt-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="bi bi-person-circle me-2"></i>个人资料
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="profileForm" novalidate>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="username" class="form-label">用户名 <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="username" name="username" 
                                           value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="email" class="form-label">邮箱地址 <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">所属项目</label>
                                    <p class="form-control-plaintext text-muted">
                                        <?php echo htmlspecialchars($user['project_name'] ?? '未分配'); ?>
                                    </p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">用户角色</label>
                                    <p class="form-control-plaintext text-muted">
                                        <?php echo ($user['role'] ?? 'user') === 'admin' ? '管理员' : '普通用户'; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">注册时间</label>
                                    <p class="form-control-plaintext text-muted">
                                        <?php echo isset($user['created_at']) ? date('Y-m-d H:i', strtotime($user['created_at'])) : '未知'; ?>
                                    </p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">最后更新</label>
                                    <p class="form-control-plaintext text-muted">
                                        <?php echo isset($user['updated_at']) ? date('Y-m-d H:i', strtotime($user['updated_at'])) : '从未'; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        
                        <hr class="my-4">
                        
                        <h6 class="text-muted mb-3">
                            <i class="bi bi-lock me-2"></i>修改密码（可选）
                        </h6>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="current_password" class="form-label">当前密码</label>
                                    <input type="password" class="form-control" id="current_password" name="current_password" 
                                           placeholder="修改密码时需要">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="new_password" class="form-label">新密码</label>
                                    <input type="password" class="form-control" id="new_password" name="new_password" 
                                           placeholder="至少6位字符">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">确认新密码</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                           placeholder="再次输入新密码">
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                            <button type="button" class="btn btn-outline-secondary" onclick="window.location.href='dashboard.php'">
                                <i class="bi bi-arrow-left me-1"></i>返回
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save me-1"></i>保存修改
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 增强版JavaScript验证 -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('profileForm');
    const newPassword = document.getElementById('new_password');
    const confirmPassword = document.getElementById('confirm_password');
    const currentPassword = document.getElementById('current_password');

    // 表单提交验证
    form.addEventListener('submit', function(e) {
        const errors = [];
        
        // 验证必填字段
        if (!document.getElementById('username').value.trim()) {
            errors.push('用户名不能为空');
        }
        
        if (!document.getElementById('email').value.trim()) {
            errors.push('邮箱不能为空');
        }
        
        // 密码验证
        if (newPassword.value) {
            if (!currentPassword.value) {
                errors.push('修改密码需要输入当前密码');
            }
            
            if (newPassword.value.length < 6) {
                errors.push('新密码长度至少6位');
            }
            
            if (newPassword.value !== confirmPassword.value) {
                errors.push('两次输入的新密码不一致');
            }
        }
        
        if (errors.length > 0) {
            e.preventDefault();
            alert(errors.join('\n'));
            return false;
        }
    });

    // 实时密码匹配验证
    function validatePasswords() {
        const newPass = newPassword.value;
        const confirmPass = confirmPassword.value;
        
        if (newPass && confirmPass) {
            if (newPass === confirmPass) {
                confirmPassword.setCustomValidity('');
                confirmPassword.classList.remove('is-invalid');
                confirmPassword.classList.add('is-valid');
            } else {
                confirmPassword.setCustomValidity('两次输入的密码不一致');
                confirmPassword.classList.remove('is-valid');
                confirmPassword.classList.add('is-invalid');
            }
        } else {
            confirmPassword.setCustomValidity('');
            confirmPassword.classList.remove('is-valid', 'is-invalid');
        }
    }

    newPassword.addEventListener('input', validatePasswords);
    confirmPassword.addEventListener('input', validatePasswords);

    // 密码强度提示
    newPassword.addEventListener('input', function() {
        const value = this.value;
        const strength = document.getElementById('passwordStrength');
        
        if (value.length > 0) {
            let strengthText = '';
            let strengthClass = '';
            
            if (value.length < 6) {
                strengthText = '密码强度：弱';
                strengthClass = 'text-danger';
            } else if (value.length < 8) {
                strengthText = '密码强度：中';
                strengthClass = 'text-warning';
            } else {
                strengthText = '密码强度：强';
                strengthClass = 'text-success';
            }
            
            if (!strength) {
                const div = document.createElement('div');
                div.id = 'passwordStrength';
                div.className = 'form-text ' + strengthClass;
                div.textContent = strengthText;
                this.parentNode.appendChild(div);
            } else {
                strength.textContent = strengthText;
                strength.className = 'form-text ' + strengthClass;
            }
        }
    });
});
</script>

<?php
// 包含页脚文件
include 'includes/footer.php';;
?>