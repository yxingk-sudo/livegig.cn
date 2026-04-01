<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

// 添加安全响应头
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

session_start();

// 如果已登录，跳转到管理后台
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    
    try {
        // 连接数据库
        $database = new Database();
        $db = $database->getConnection();
        
        // 查询管理员用户
        $query = "SELECT au.*, r.role_key, r.role_name 
                  FROM admin_users au 
                  INNER JOIN roles r ON au.role_id = r.id 
                  WHERE au.username = :username AND au.status = 1";
        $stmt = $db->prepare($query);
        $stmt->execute([':username' => $username]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 验证密码（MD5加密）
        if ($admin && $admin['password'] === md5($password)) {
            // 设置会话变量
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['admin_user_id'] = $admin['id'];
            $_SESSION['admin_real_name'] = $admin['real_name'];
            $_SESSION['admin_role_key'] = $admin['role_key'];
            $_SESSION['admin_role_name'] = $admin['role_name'];
            $_SESSION['admin_company_id'] = $admin['company_id'];
            
            // 更新最后登录时间和IP
            $updateQuery = "UPDATE admin_users SET last_login_time = NOW(), last_login_ip = :ip WHERE id = :id";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->execute([
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ':id' => $admin['id']
            ]);
            
            // 重定向到管理后台
            header("Location: index.php");
            exit;
        } else {
            $error = '用户名或密码错误！';
        }
    } catch (Exception $e) {
        error_log("登录错误: " . $e->getMessage());
        $error = '登录失败，请稍后重试。';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理后台登录 - 团队接待处理系统</title>
    <link href="assets/css/app.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .login-card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 1rem 3rem rgba(0, 0, 0, 0.175);
            max-width: 400px;
            width: 100%;
        }
        .login-card .card-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 1rem 1rem 0 0;
            text-align: center;
            padding: 2rem;
        }
        .login-card .card-header i {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="card-header">
                <i class="bi bi-shield-lock"></i>
                <h3 class="mb-0">管理后台登录</h3>
                <p class="mb-0 opacity-75">团队接待处理系统</p>
            </div>
            <div class="card-body p-4">
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="mb-3">
                        <label for="username" class="form-label">
                            <i class="bi bi-person-fill"></i> 用户名
                        </label>
                        <input type="text" class="form-control" id="username" name="username" 
                               placeholder="请输入管理员用户名" required autofocus>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">
                            <i class="bi bi-key-fill"></i> 密码
                        </label>
                        <input type="password" class="form-control" id="password" name="password" 
                               placeholder="请输入管理员密码" required>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-box-arrow-in-right"></i> 登录
                        </button>
                    </div>
                </form>
                
                <hr class="my-4">
                
                <div class="text-center">
                    <p class="text-muted mb-2">默认管理员账号：</p>
                    <p class="text-muted mb-0">
                        <strong>用户名：</strong>admin<br>
                        <strong>密码：</strong>admin123
                    </p>
                </div>
                
                <div class="text-center mt-3">
                    <a href="../index.php" class="text-decoration-none">
                        <i class="bi bi-house-fill"></i> 返回首页
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/app.min.js"></script>
</body>
</html>