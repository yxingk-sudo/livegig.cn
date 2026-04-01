<?php
// 所有PHP逻辑必须在任何输出之前完成
session_start();

require_once __DIR__ . '/../config/database.php';

// 检查用户是否登录
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// 获取数据库连接
$database = new Database();
$pdo = $database->getConnection();

if (!$pdo) {
    $_SESSION['error'] = "数据库连接失败";
    header("Location: dashboard.php");
    exit();
}

// 初始化变量
$user = null;
$errors = [];
$success_message = null;

// 检查是否有会话错误消息并添加到错误数组
if (isset($_SESSION['error'])) {
    $errors[] = $_SESSION['error'];
    unset($_SESSION['error']);
}

// 获取用户信息
try {
    $stmt = $pdo->prepare("SELECT u.*, p.name as project_name FROM project_users u LEFT JOIN projects p ON u.project_id = p.id WHERE u.id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        $_SESSION['error'] = "用户不存在";
        header("Location: dashboard.php");
        exit();
    }
    
    // 查询关联的后台人员信息
    $stmt = $pdo->prepare("SELECT p.* FROM personnel p 
                           JOIN personnel_project_users ppu ON p.id = ppu.personnel_id 
                           WHERE ppu.project_user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $personnel = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 如果有关联的后台人员信息，保存人员信息以便显示
    if ($personnel) {
        // 保存人员信息到用户数组中，但不覆盖原始用户名
        $user['personnel_id'] = $personnel['id'];
        $user['personnel_name'] = $personnel['name'];
        $user['personnel_phone'] = $personnel['phone'];
        $user['personnel_email'] = $personnel['email'];
        
        // 如果项目用户没有填写电话，可以使用后台人员的电话
        if (empty($user['phone'])) {
            $user['phone'] = $personnel['phone'];
        }
        
        // 如果项目用户没有填写邮箱，可以使用后台人员的邮箱
        if (empty($user['email'])) {
            $user['email'] = $personnel['email'];
        }
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "获取用户信息失败: " . $e->getMessage();
    header("Location: dashboard.php");
    exit();
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $phone = trim($_POST['phone']);
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // 验证输入
    if (empty($username)) {
        $errors[] = "用户名不能为空";
    }
    
    // 检查用户名是否实际发生了变化
    $username_changed = (isset($user['username']) && $username !== $user['username']);
    
    // 只有当用户名发生变化时，才进行唯一性校验
    if ($username_changed) {
        try {
            // 如果用户有关联后台人员，可以允许用户名在不同项目中重复
            // 只需要确保在同一项目内用户名唯一即可
            if (isset($user['personnel_id'])) {
                // 只检查同一项目内的用户名唯一性
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM project_users WHERE username = ? AND id != ? AND project_id = ?");
                $stmt->execute([$username, $_SESSION['user_id'], $user['project_id']]);
            } else {
                // 对于没有关联后台人员的用户，保持原有逻辑（检查全局唯一性）
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM project_users WHERE username = ? AND id != ?");
                $stmt->execute([$username, $_SESSION['user_id']]);
            }
            
            if ($stmt->fetchColumn() > 0) {
                $errors[] = "用户名已存在";
            }
        } catch (PDOException $e) {
            $errors[] = "验证用户信息失败";
        }
    }
    
    // 检查是否有任何字段发生变化
    $any_change = false;
    
    // 检查用户名是否变化
    if ($username_changed) {
        $any_change = true;
    }
    
    // 检查电话是否变化
    $phone_changed = (isset($user['phone']) && $phone !== $user['phone']) || (!isset($user['phone']) && !empty($phone));
    if ($phone_changed) {
        $any_change = true;
    }
    
    // 检查是否修改密码
    if (!empty($new_password)) {
        $any_change = true;
    }
    
    // 如果有任何变更，要求输入当前密码并验证
    if ($any_change) {
        if (empty($current_password)) {
            $errors[] = "变更任何资料都需要输入当前密码";
        } else {
            // 验证当前密码
            $stmt = $pdo->prepare("SELECT password FROM project_users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $stored_password = $stmt->fetchColumn();
            
            if (!password_verify($current_password, $stored_password)) {
                $errors[] = "当前密码错误";
            }
            
            // 处理密码修改相关验证
            if (!empty($new_password)) {
                if ($new_password !== $confirm_password) {
                    $errors[] = "两次输入的新密码不一致";
                }
                
                if (strlen($new_password) < 6) {
                    $errors[] = "新密码长度至少6位";
                }
            }
        }
    }
    
    // 如果没有错误，更新用户信息
    if (empty($errors)) {
        try {
            // 安全检查：确保用户ID存在且有效
            if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
                throw new Exception("无效的用户ID，无法更新个人资料");
            }
            
            // 记录操作日志便于调试
            error_log("用户尝试更新资料: 用户ID={$_SESSION['user_id']}, 用户名={$username}");
            
            // 准备更新参数
            $update_sql = "UPDATE project_users SET updated_at = NOW()";
            $params = [];
            
            // 跟踪是否更新了用户名
            $username_updated = false;
            
            // 跟踪是否有实际修改
            $changes_made = false;
            
            // 只有在没有关联后台人员或明确需要更新时才更新用户名
            if (!isset($user['personnel_id']) || !empty($current_password)) {
                // 只有当用户名实际变化时才更新
                if ($username !== $user['username']) {
                    $update_sql .= ", username = :username";
                    $params[':username'] = $username;
                    $username_updated = true;
                    $changes_made = true;
                }
            } else {
                // 如果有关联后台人员，保留原始用户名
                error_log("用户有关联的后台人员ID={$user['personnel_id']}，不更新用户名");
            }
            
            // 检查邮箱和电话是否实际变化
            $email_changed = false; // 设置为false，因为我们不再处理邮箱
            $phone_changed = (isset($user['phone']) && $phone !== $user['phone']) || (!isset($user['phone']) && !empty($phone));
            
            if ($phone_changed) {
                $update_sql .= ", phone = :phone";
                $params[':phone'] = $phone;
                $changes_made = true;
            }
            
            // 处理密码更新
            if (!empty($new_password)) {
                $update_sql .= ", password = :password";
                $params[':password'] = password_hash($new_password, PASSWORD_DEFAULT);
                $changes_made = true;
            }
            
            // 关键部分：确保WHERE条件正确，只更新当前用户
            $update_sql .= " WHERE id = :user_id";
            $params[':user_id'] = $_SESSION['user_id'];
            
            $stmt = $pdo->prepare($update_sql);
            $stmt->execute($params);
            
            // 检查受影响的行数
            $affected_rows = $stmt->rowCount();
            error_log("更新操作完成: 受影响的行数={$affected_rows}");
            
            if ($affected_rows === 0) {
                throw new Exception("未找到匹配的用户记录，更新失败");
            }
            
            // 新增：同步更新后台人员的电话信息（如果有关联后台人员且电话有变动）
            if (isset($user['personnel_id']) && $phone_changed) {
                try {
                    $stmt = $pdo->prepare("UPDATE personnel SET phone = :phone, updated_at = NOW() WHERE id = :personnel_id");
                    $stmt->execute([':phone' => $phone, ':personnel_id' => $user['personnel_id']]);
                    
                    $personnel_affected_rows = $stmt->rowCount();
                    error_log("同步更新后台人员电话完成: 人员ID={$user['personnel_id']}, 受影响的行数={$personnel_affected_rows}");
                } catch (PDOException $e) {
                    // 记录错误但不中断主流程
                    error_log("同步更新后台人员电话失败: " . $e->getMessage());
                }
            }
            
            // 只有在实际进行了修改时才设置成功消息
            if ($changes_made) {
                $_SESSION['success'] = "个人资料更新成功";
            }
            
            // 只有在实际更新了数据库中的用户名时，才更新会话中的用户名
            if ($username_updated) {
                $_SESSION['username'] = $username;
            }            
            header("Location: profile.php");
            exit();
        } catch (Exception $e) {
            // 记录详细错误信息便于调试
            error_log("更新个人资料失败: " . $e->getMessage());
            $errors[] = "更新失败: " . $e->getMessage();
            
            // 为确保消息显示，也设置到会话中
            $_SESSION['error'] = "更新失败: " . $e->getMessage();
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
include('includes/header.php');

// 显示成功消息
if (!empty($success_message)) {
    echo '<div class="alert alert-success alert-dismissible fade show" role="alert">';
    echo $success_message;
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
}

// 显示错误消息（从数组或会话中获取）
if (!empty($errors)) {
    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">';
    echo '<ul class="mb-0">';
    foreach ($errors as $error) {
        echo "<li>{$error}</li>";
    }
    echo '</ul>';
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
}

// 添加调试信息以检查邮箱显示问题
if (isset($_GET['debug'])) {
    echo '<div class="alert alert-info mb-4">';
    echo '<h4>调试信息:</h4>';
    echo '<p><strong>用户ID:</strong> ' . $_SESSION['user_id'] . '</p>';
    echo '<p><strong>数据库查询获取的完整用户数据:</strong></p>';
    echo '<pre>' . print_r($user, true) . '</pre>';
    echo '<p><strong>Email字段检查:</strong></p>';
    echo '<ul>';
    echo '<li>Email是否存在: ' . (isset($user['email']) ? '是' : '否') . '</li>';
    echo '<li>Email值: ' . ($user['email'] ?? '空') . '</li>';
    echo '<li>Email类型: ' . gettype($user['email'] ?? null) . '</li>';
    echo '<li>Email长度: ' . (isset($user['email']) ? strlen($user['email']) : 0) . '</li>';
    echo '</ul>';
    echo '</div>';
}

// 添加额外的调试信息以检查消息传递
if (isset($_GET['debug'])) {
    echo '<div class="alert alert-warning mb-4">';
    echo '<h4>消息调试:</h4>';
    echo '<p><strong>成功消息:</strong> ' . ($success_message ?? '无') . '</p>';
    echo '<p><strong>错误消息数组:</strong></p>';
    echo '<pre>' . print_r($errors, true) . '</pre>';
    echo '<p><strong>会话中的success:</strong> ' . (isset($_SESSION['success']) ? $_SESSION['success'] : '无') . '</p>';
    echo '<p><strong>会话中的error:</strong> ' . (isset($_SESSION['error']) ? $_SESSION['error'] : '无') . '</p>';
    echo '</div>';
}
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="bi bi-person-circle me-2"></i>个人资料
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" id="profileForm">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="username" class="form-label">用户名</label>
                                <input type="text" class="form-control" id="username" name="username" 
                                       value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" required>
                                <?php if (isset($user['personnel_id'])): ?>
                                <div class="mt-2">
                                    <small class="form-text text-muted">关联的后台人员信息: 
                                        <strong><?php echo htmlspecialchars($user['personnel_name']); ?></strong>
                                        <?php if (!empty($user['personnel_phone'])): ?>
                                            (电话: <?php echo htmlspecialchars($user['personnel_phone']); ?>)
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="phone" class="form-label">联系电话</label>
                                <input type="tel" class="form-control" id="phone" name="phone" 
                                       value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">所属项目</label>
                                <p class="form-control-plaintext"><?php echo htmlspecialchars($user['project_name'] ?? '未分配'); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">角色</label>
                                <p class="form-control-plaintext"><?php echo $user['role'] === 'admin' ? '管理员' : '普通用户'; ?></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">注册时间</label>
                                <p class="form-control-plaintext"><?php echo date('Y-m-d H:i', strtotime($user['created_at'])); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <h6 class="text-muted mb-3">修改密码（可选）</h6>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="current_password" class="form-label">当前密码</label>
                                <input type="password" class="form-control" id="current_password" name="current_password">
                                <small class="form-text text-danger">变更资料需要输入当前密码</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="new_password" class="form-label">新密码</label>
                                <input type="password" class="form-control" id="new_password" name="new_password">
                                <small class="form-text text-muted">至少6位字符</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">确认新密码</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                    </div>
                    
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <button type="button" class="btn btn-secondary" onclick="window.location.href='dashboard.php'">
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

<script>
        // 保存原始值用于前端变更检测
        const originalUsername = '<?php echo htmlspecialchars($user['username'] ?? ''); ?>';
        const originalPhone = '<?php echo htmlspecialchars($user['phone'] ?? ''); ?>';
document.getElementById('profileForm').addEventListener('submit', function(e) {
    const username = document.getElementById('username').value;
    const phone = document.getElementById('phone').value;
    const currentPassword = document.getElementById('current_password').value;
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    
    // 检查是否有任何字段发生变化
    // 注意：这里只是简单的前端检查，后端仍会进行验证
    const hasChanges = username !== originalUsername || 
                       phone !== originalPhone || 
                       newPassword !== '';
    
    // 如果有变更但没有输入当前密码，阻止提交
    if (hasChanges && currentPassword === '') {
        e.preventDefault();
        alert('变更任何资料都需要输入当前密码');
        return false;
    }
    
    // 验证新密码匹配
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
</script>

<?php
// 包含页脚文件
include 'includes/footer.php';;
?>