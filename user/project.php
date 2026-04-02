<?php
session_start();
// 引入数据库连接类
require_once '../config/database.php';
// 引入权限中间件进行权限验证
require_once '../includes/PermissionMiddleware.php';

$database = new Database();
$db = $database->getConnection();
$middleware = new PermissionMiddleware($db);
$middleware->checkUserPagePermission('frontend:project:view');

require_once '../includes/functions.php';
require_once '../includes/site_config.php';

$project_code = $_GET['code'] ?? '';

if (!$project_code) {
    header("Location: index.php");
    exit;
}

// 检查项目是否存在
$query = "SELECT p.*, c.name as company_name 
          FROM projects p 
          JOIN companies c ON p.company_id = c.id 
          WHERE p.code = :code";
$stmt = $db->prepare($query);
$stmt->bindParam(':code', $project_code);
$stmt->execute();
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    die("项目不存在");
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = sanitizeInput($_POST['username']);
    $password = $_POST['password'];
    
    $user = checkProjectAccess($project_code, $username, $password, $db);
    
    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['display_name'] = $user['display_name'];
        $_SESSION['project_id'] = $user['project_id'];
        $_SESSION['project_name'] = $user['project_name'];
        $_SESSION['role'] = $user['role'];
        
        header("Location: dashboard.php");
        exit;
    } else {
        $error = "用户名或密码错误";
    }
}

// 设置页面变量
$page_title = htmlspecialchars($project['name']) . ' - 项目登录';
$show_page_title = '项目登录';
$active_page = 'project_login';

// 包含统一头部文件
include 'includes/header.php';
?>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h4 class="text-center"><?php echo htmlspecialchars($project['name']); ?></h4>
                        <p class="text-center text-muted"><?php echo htmlspecialchars($project['company_name']); ?></p>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <div class="mb-3">
                                <label for="username" class="form-label">用户名</label>
                                <input type="text" class="form-control" id="username" name="username" required>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label">密码</label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">登录</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php include 'includes/footer.php'; ?>
</body>
</html>
