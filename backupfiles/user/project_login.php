<?php

session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// 检查是否已登录
if (isset($_SESSION['user_id']) && isset($_SESSION['project_id'])) {
    header("Location: dashboard.php");
    exit;
}

$project_code = $_GET['code'] ?? '';

if (!$project_code) {
    header("Location: index.php");
    exit;
}

// 获取项目信息
$query = "SELECT p.*, c.name as company_name 
          FROM projects p 
          JOIN companies c ON p.company_id = c.id 
          WHERE p.code = :code AND p.status = 'active'";
$stmt = $db->prepare($query);
$stmt->bindParam(':code', $project_code);
$stmt->execute();
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    die("项目不存在或已停用");
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error = '用户名和密码不能为空';
    } else {
        // 验证用户
        $query = "SELECT * FROM project_users 
                  WHERE project_id = :project_id AND username = :username AND is_active = 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':project_id', $project['id']);
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['display_name'] = $user['display_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['project_id'] = $project['id'];
            $_SESSION['project_name'] = $project['name'];
            $_SESSION['project_code'] = $project['code'];
            
            // 更新最后登录时间（如果字段存在）
            try {
                $update_query = "UPDATE project_users SET last_login = CURRENT_TIMESTAMP WHERE id = :id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(':id', $user['id']);
                $update_stmt->execute();
            } catch (PDOException $e) {
                // 如果last_login字段不存在，忽略此错误
                error_log("无法更新最后登录时间: " . $e->getMessage());
            }
            
            header("Location: dashboard.php");
            exit;
        } else {
            $error = '用户名或密码错误';
        }
    }
}

// 获取网站配置
$site_name = '企业项目管理系统';
$site_config = new Database();
if ($site_config->getConnection()) {
    $site_name = $site_config->getSiteConfig('site_name', '企业项目管理系统');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($project['name']); ?> - 项目登录</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-size: 14px;
        }
        .login-container {
            max-width: 400px;
            width: 100%;
        }
        .login-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .login-form {
            padding: 40px 30px;
        }
        .form-control {
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            padding: 12px 15px;
            transition: all 0.3s ease;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 10px;
            padding: 12px;
            width: 100%;
            color: white;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        .project-info {
            text-align: center;
            margin-bottom: 20px;
        }
        .company-name {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 5px;
        }
        .project-name {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 5px;
        }
        .project-code {
            font-size: 12px;
            opacity: 0.7;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="project-info">
                    <div class="company-name"><?php echo htmlspecialchars($project['company_name']); ?></div>
                    <div class="project-name"><?php echo htmlspecialchars($project['name']); ?></div>
                    <div class="project-code">项目代码：<?php echo htmlspecialchars($project['code']); ?></div>
                </div>
                <h4>项目登录</h4>
            </div>
            
            <div class="login-form">
                <?php if (isset($error) && $error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <form method="POST">
                    <div class="mb-3">
                        <label for="username" class="form-label">
                            <i class="bi bi-person"></i> 用户名
                        </label>
                        <input type="text" class="form-control" id="username" name="username" 
                               placeholder="请输入用户名" required autofocus>
                    </div>
                    
                    <div class="mb-4">
                        <label for="password" class="form-label">
                            <i class="bi bi-lock"></i> 密码
                        </label>
                        <input type="password" class="form-control" id="password" name="password" 
                               placeholder="请输入密码" required>
                    </div>
                    
                    <button type="submit" class="btn btn-login">
                        <i class="bi bi-box-arrow-in-right"></i> 登录
                    </button>
                </form>
                
                <hr>
                
                <div class="text-center">
                    <small class="text-muted">
                        忘记密码？请联系项目管理员重置密码
                    </small>
                </div>
            </div>
        </div>
    </div>

<?php include 'includes/footer.php'; ?>
</body>
</html>