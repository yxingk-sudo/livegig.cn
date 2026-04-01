<?php
session_start();
require_once '../config/database.php';
// 更可靠地包含page_functions.php，如果文件不存在则定义默认函数
$page_functions_path = __DIR__ . '/page_functions.php';
if (file_exists($page_functions_path)) {
    require_once $page_functions_path;
} else {
    // 定义默认的getCurrentPage函数
    if (!function_exists('getCurrentPage')) {
        function getCurrentPage() {
            return basename($_SERVER['PHP_SELF']);
        }
    }
}

// 检查管理员是否已登录
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();

// 处理表单提交
$message = '';
$error = '';

// 获取标准部门列表
$standard_departments = [
    '导演组' => '负责项目整体创意和艺术指导',
    '制片组' => '负责项目预算、进度和资源协调',
    '摄影组' => '负责项目拍摄工作',
    '灯光组' => '负责项目灯光布设',
    '录音组' => '负责项目录音工作',
    '美术组' => '负责项目美术设计和布景',
    '化妆组' => '负责演员化妆和造型',
    '服装组' => '负责演员服装设计和管理',
    '道具组' => '负责项目道具准备和管理',
    '场务组' => '负责现场秩序和后勤保障',
    '后期组' => '负责项目后期制作',
    '宣传组' => '负责项目宣传推广',
    '餐饮组' => '负责项目餐饮服务',
    '交通组' => '负责项目交通安排'
];

// 获取所有项目及其公司信息
$stmt = $db->prepare("SELECT p.id, p.name, c.name as company_name 
                     FROM projects p 
                     LEFT JOIN companies c ON p.company_id = c.id 
                     ORDER BY c.name, p.name");
$stmt->execute();
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取每个项目的现有部门
$project_departments = [];
foreach ($projects as $project) {
    $stmt = $db->prepare("SELECT name FROM departments WHERE project_id = ? ORDER BY name");
    $stmt->execute([$project['id']]);
    $depts = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $project_departments[$project['id']] = $depts;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_add'])) {
    try {
        $db->beginTransaction();
        
        foreach ($projects as $project) {
            $existing = $project_departments[$project['id']] ?? [];
            $missing = array_diff(array_keys($standard_departments), $existing);
            
            foreach ($missing as $dept_name) {
                // 检查部门是否已存在（防止重复添加）
                $check_stmt = $db->prepare("SELECT id FROM departments WHERE project_id = ? AND name = ?");
                $check_stmt->execute([$project['id'], $dept_name]);
                
                if (!$check_stmt->fetch()) {
                    // 添加缺失的标准部门
                    $insert_stmt = $db->prepare("INSERT INTO departments (project_id, name, description) VALUES (?, ?, ?)");
                    $insert_stmt->execute([$project['id'], $dept_name, $standard_departments[$dept_name]]);
                }
            }
        }
        
        $db->commit();
        $message = '已成功为所有项目添加缺失的标准部门！';
    } catch (Exception $e) {
        $db->rollBack();
        $error = '操作失败：' . $e->getMessage();
    }
}

include 'includes/header.php';
?>

<style>
    .department-checklist {
        background-color: #ffffff;
        border: 1px solid #dee2e6;
        border-radius: 0.375rem;
        padding: 1rem;
        margin-bottom: 1rem;
    }
    .project-card {
        background-color: #ffffff;
        border: 1px solid #e9ecef;
        border-radius: 0.25rem;
        padding: 0.75rem;
        margin-bottom: 0.5rem;
    }
    .missing-dept {
        color: #dc3545;
        font-weight: bold;
    }
    .existing-dept {
        color: #198754;
    }
</style>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <h2 class="mb-4">
                <i class="bi bi-plus-circle me-2"></i>批量添加标准部门
            </h2>

            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="department-checklist">
                <h5 class="mb-3">标准部门列表</h5>
                <div class="row">
                    <?php foreach ($standard_departments as $dept_name => $description): ?>
                        <div class="col-md-6 col-lg-3 mb-2">
                            <div class="border p-2 rounded">
                                <strong><?php echo htmlspecialchars($dept_name); ?></strong>
                                <br>
                                <small class="text-muted"><?php echo htmlspecialchars($description); ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">项目部门状态</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($projects)): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-2"></i>当前没有项目，请先创建项目。
                        </div>
                    <?php else: ?>
                        <form method="post" onSubmit="return confirm('确定要为所有项目添加缺失的标准部门吗？')">
                            <div class="row">
                                <?php foreach ($projects as $project): ?>
                                    <div class="col-md-6 col-lg-4 mb-3">
                                        <div class="project-card">
                                            <h6 class="mb-2">
                                                <?php echo htmlspecialchars($project['company_name'] . ' - ' . $project['name']); ?>
                                            </h6>
                                            <div class="mb-2">
                                                <small>已有部门：</small>
                                                <?php 
                                                $existing = $project_departments[$project['id']] ?? [];
                                                $missing = array_diff(array_keys($standard_departments), $existing);
                                                ?>
                                                
                                                <?php if (!empty($existing)): ?>
                                                    <?php foreach ($existing as $dept): ?>
                                                        <span class="badge bg-success existing-dept me-1"><?php echo htmlspecialchars($dept); ?></span>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">暂无标准部门</span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <?php if (!empty($missing)): ?>
                                                <div class="mb-2">
                                                    <small>缺失部门：</small>
                                                    <?php foreach ($missing as $dept): ?>
                                                        <span class="badge bg-danger missing-dept me-1"><?php echo htmlspecialchars($dept); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="text-success">
                                                    <small><i class="bi bi-check-circle me-1"></i>所有标准部门已齐全</small>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="text-center mt-4">
                                <button type="submit" name="confirm_add" class="btn btn-primary btn-lg">
                                    <i class="bi bi-plus-circle me-2"></i>为所有项目添加缺失的标准部门
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="text-center mt-3">
                <a href="departments_enhanced.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left me-2"></i>返回部门管理
                </a>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>