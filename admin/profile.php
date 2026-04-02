<?php
// 启动会话
session_start();

// 引入基础控制器进行权限验证
require_once '../includes/BaseAdminController.php';

// 检查管理员是否已登录
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

// 确保admin_id存在（与login.php保持一致）
if (!isset($_SESSION['admin_id'])) {
    $_SESSION['admin_id'] = 1; // 默认管理员ID为1
}

// 设置页面标题
$page_title = "个人资料";

// 包含数据库连接
require_once '../config/database.php';

// 获取数据库连接
$database = new Database();
$pdo = $database->getConnection();

if (!$pdo) {
    $_SESSION['error'] = "数据库连接失败";
    header("Location: index.php");
    exit();
}

// 获取管理员信息
try {
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
    $stmt->execute([$_SESSION['admin_id']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin) {
        $_SESSION['error'] = "管理员不存在";
        header("Location: index.php");
        exit();
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "获取管理员信息失败: " . $e->getMessage();
    header("Location: index.php");
    exit();
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // 验证输入
    $errors = [];
    
    if (empty($username)) {
        $errors[] = "用户名不能为空";
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "请输入有效的邮箱地址";
    }
    
    // 检查用户名和邮箱是否已存在
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE username = ? AND id != ?");
        $stmt->execute([$username, $_SESSION['admin_id']]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "用户名已存在";
        }
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM admins WHERE email = ? AND id != ?");
        $stmt->execute([$email, $_SESSION['admin_id']]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "邮箱已存在";
        }
    } catch (PDOException $e) {
        $errors[] = "验证管理员信息失败";
    }
    
    // 处理密码修改
    if (!empty($new_password)) {
        if (empty($current_password)) {
            $errors[] = "修改密码需要输入当前密码";
        } else {
            // 验证当前密码 (使用bcrypt加密)
            $stmt = $pdo->prepare("SELECT password FROM admins WHERE id = ?");
            $stmt->execute([$_SESSION['admin_id']]);
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
    
    // 如果没有错误，更新管理员信息
    if (empty($errors)) {
        try {
            $update_sql = "UPDATE admins SET username = ?, email = ?, updated_at = NOW()";
            $params = [$username, $email];
            
            if (!empty($new_password)) {
                $update_sql .= ", password = ?";
                $params[] = password_hash($new_password, PASSWORD_DEFAULT); // 使用bcrypt加密
            }
            
            $update_sql .= " WHERE id = ?";
            $params[] = $_SESSION['admin_id'];
            
            $stmt = $pdo->prepare($update_sql);
            $stmt->execute($params);
            
            $_SESSION['success'] = "个人资料更新成功";
            
            // 更新会话中的用户名
            $_SESSION['username'] = $username;
            
            header("Location: profile.php");
            exit();
        } catch (PDOException $e) {
            $errors[] = "更新失败: " . $e->getMessage();
        }
    }
}

// 包含头部文件
include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <!-- 主要内容 -->
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">个人资料</h1>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo $error; ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-person-circle me-2"></i>管理员资料
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="profileForm">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="username" class="form-label">用户名</label>
                                            <input type="text" class="form-control" id="username" name="username" 
                                                   value="<?php echo htmlspecialchars($admin['username']); ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="email" class="form-label">邮箱</label>
                                            <input type="email" class="form-control" id="email" name="email" 
                                                   value="<?php echo htmlspecialchars($admin['email']); ?>" required>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="role" class="form-label">角色</label>
                                            <input type="text" class="form-control" id="role" 
                                                   value="<?php echo $admin['role'] === 'super_admin' ? '系统管理员' : '普通管理员'; ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="created_at" class="form-label">创建时间</label>
                                            <input type="text" class="form-control" id="created_at" 
                                                   value="<?php echo date('Y-m-d H:i', strtotime($admin['created_at'])); ?>" readonly>
                                        </div>
                                    </div>
                                </div>
                                
                                <hr>
                                
                                <h6 class="text-muted mb-3">修改密码（可选）</h6>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="current_password" class="form-label">当前密码</label>
                                            <div class="input-group">
                                                <input type="password" class="form-control" id="current_password" name="current_password" autocomplete="current-password">
                                                <button type="button" class="btn btn-outline-secondary" onclick="togglePasswordVisibility('current_password')">
                                                    <i class="bi bi-eye" id="current_password_icon"></i>
                                                </button>
                                            </div>
                                            <small class="form-text text-muted">仅修改密码时需要输入</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="new_password" class="form-label">新密码</label>
                                            <div class="input-group">
                                                <input type="password" class="form-control" id="new_password" name="new_password" autocomplete="new-password">
                                                <button type="button" class="btn btn-outline-secondary" onclick="togglePasswordVisibility('new_password')">
                                                    <i class="bi bi-eye" id="new_password_icon"></i>
                                                </button>
                                            </div>
                                            <small class="form-text text-muted">至少6位字符</small>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">确认新密码</label>
                                    <div class="input-group">
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" autocomplete="new-password">
                                        <button type="button" class="btn btn-outline-secondary" onclick="togglePasswordVisibility('confirm_password')">
                                            <i class="bi bi-eye" id="confirm_password_icon"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                    <button type="button" class="btn btn-secondary" onclick="window.location.href='index.php'">
                                        <i class="bi bi-arrow-left me-1"></i>返回
                                    </button>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-check-circle me-1"></i>保存修改
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
document.getElementById('profileForm').addEventListener('submit', function(e) {
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    
    if (newPassword && newPassword !== confirmPassword) {
        e.preventDefault();
        alert('两次输入的新密码不一致');
        return false;
    }
});

// 实时验证密码匹配
const newPasswordInput = document.getElementById('new_password');
const confirmPasswordInput = document.getElementById('confirm_password');

function validatePasswords() {
    const newPassword = newPasswordInput.value;
    const confirmPassword = confirmPasswordInput.value;
    
    if (newPassword && confirmPassword && newPassword !== confirmPassword) {
        confirmPasswordInput.setCustomValidity('两次输入的密码不一致');
    } else {
        confirmPasswordInput.setCustomValidity('');
    }
}

newPasswordInput.addEventListener('input', validatePasswords);
confirmPasswordInput.addEventListener('input', validatePasswords);

// 切换密码可见性
function togglePasswordVisibility(inputId) {
    const input = document.getElementById(inputId);
    const icon = document.getElementById(inputId + '_icon');
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
    }
}
</script>

<?php
// 包含页脚文件
require_once 'includes/footer.php';
?>