<?php
session_start();

// 如果用户已登录，重定向到工作台
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';

// 处理登录表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = '请输入用户名和密码';
    } else {
        try {
            // 包含数据库配置文件
            require_once '../config/database.php';
            
            $database = new Database();
            $conn = $database->getConnection();
            
            if (!$conn) {
                $error = '数据库连接失败';
            } else {
                // 查询用户
                $stmt = $conn->prepare("SELECT id, name, username, password, role FROM personnel WHERE username = ? AND is_active = 1");
                $stmt->execute([$username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user && password_verify($password, $user['password'])) {
                    // 登录成功，设置会话
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['role'] = $user['role'];
                    
                    // 获取用户关联的项目信息
                    $project_stmt = $conn->prepare("SELECT project_id FROM project_users WHERE id = ?");
                    $project_stmt->execute([$user['id']]);
                    $project_user = $project_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($project_user) {
                        $_SESSION['project_id'] = $project_user['project_id'];
                    }
                    
                    // 重定向到工作台
                    header("Location: dashboard.php");
                    exit();
                } else {
                    $error = '用户名或密码错误';
                }
            }
        } catch (Exception $e) {
            $error = '登录失败，请稍后重试';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>用户登录 - 企业项目管理系统</title>
    <link href="../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }
        .login-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            overflow: hidden;
            width: 100%;
            max-width: 400px;
        }
        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        .login-form {
            padding: 2rem;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            width: 100%;
            padding: 0.75rem;
            font-weight: 600;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h3><i class="bi bi-building"></i> 企业项目管理系统</h3>
            <p class="mb-0">用户登录</p>
        </div>
        
        <div class="login-form">
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="login.php">
                <div class="mb-3">
                    <label for="username" class="form-label">
                        <i class="bi bi-person-fill"></i> 用户名
                    </label>
                    <input type="text" class="form-control" id="username" name="username" 
                           value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>" 
                           required autofocus>
                </div>
                
                <div class="mb-4">
                    <label for="password" class="form-label">
                        <i class="bi bi-lock-fill"></i> 密码
                    </label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-box-arrow-in-right"></i> 登录
                </button>
            </form>
            
            <hr class="my-4">
            
            <div class="text-center">
                <small class="text-muted">
                    <i class="bi bi-info-circle"></i> 管理员请访问 
                    <a href="admin/login.php" class="text-decoration-none">管理后台</a>
                </small>
            </div>
            
            <div class="text-center mt-3">
                <a href="index.php" class="text-decoration-none">
                    <i class="bi bi-house-fill"></i> 返回首页
                </a>
            </div>
        </div>
    </div>

    <?php include 'includes/footer.php'; ?>
</body>
</html>