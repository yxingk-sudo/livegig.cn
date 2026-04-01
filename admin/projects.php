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

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();

// 处理表单提交
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'delete' && isset($_POST['id'])) {
            // 删除项目
            $id = intval($_POST['id']);
            
            // 检查是否有人员关联
            $check_query = "SELECT COUNT(*) FROM project_department_personnel WHERE project_id = :project_id";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':project_id', $id);
            $check_stmt->execute();
            $personnel_count = $check_stmt->fetchColumn();
            
            if ($personnel_count > 0) {
                $error = '该项目下有人员，无法删除！';
            } else {
                $query = "DELETE FROM projects WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $id);
                
                if ($stmt->execute()) {
                    $message = '项目删除成功！';
                } else {
                    $error = '删除项目失败，请重试！';
                }
            }
        }
    }
}

// 获取项目列表 - 智能检测project_hotels表存在性
$projects = [];
try {
    // 检查project_hotels表是否存在
    $check_table_query = "SHOW TABLES LIKE 'project_hotels'";
    $check_stmt = $db->prepare($check_table_query);
    $check_stmt->execute();
    $table_exists = ($check_stmt->rowCount() > 0);
    
    if ($table_exists) {
        // 表存在，使用完整查询获取酒店信息
        $projects_query = "SELECT p.*, c.name as company_name, 
                          GROUP_CONCAT(h.hotel_name_cn ORDER BY h.hotel_name_cn SEPARATOR ', ') as hotel_names
                          FROM projects p 
                          LEFT JOIN companies c ON p.company_id = c.id 
                          LEFT JOIN project_hotels ph ON p.id = ph.project_id
                          LEFT JOIN hotels h ON ph.hotel_id = h.id
                          GROUP BY p.id
                          ORDER BY p.created_at DESC";
    } else {
        // 表不存在，使用基础查询
        $projects_query = "SELECT p.*, c.name as company_name 
                          FROM projects p 
                          LEFT JOIN companies c ON p.company_id = c.id 
                          ORDER BY p.created_at DESC";
    }
    
    $projects_stmt = $db->prepare($projects_query);
    $projects_stmt->execute();
    $projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 为所有项目添加酒店相关字段的默认值（兼容性处理）
    if (!$table_exists) {
        foreach ($projects as &$project) {
            $project['hotel_names'] = null;
        }
    }
    
} catch (PDOException $e) {
    // 如果查询失败，使用最基础查询
    $projects_query = "SELECT p.*, c.name as company_name 
                      FROM projects p 
                      LEFT JOIN companies c ON p.company_id = c.id 
                      ORDER BY p.created_at DESC";
    $projects_stmt = $db->prepare($projects_query);
    $projects_stmt->execute();
    $projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 添加酒店相关字段的默认值
    foreach ($projects as &$project) {
        $project['hotel_names'] = null;
    }
}

// 处理编辑请求
$edit_project = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $edit_id = intval($_GET['id']);
    $query = "SELECT * FROM projects WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $edit_id);
    $stmt->execute();
    $edit_project = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 处理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// 状态映射
$status_map = [
    'active' => ['label' => '活跃', 'class' => 'success'],
    'inactive' => ['label' => '非活跃', 'class' => 'secondary'],
    'completed' => ['label' => '已完成', 'class' => 'info']
];
?>

<?php include 'includes/header.php'; ?>

<!-- 引入项目管理页面优化样式 -->
<link href="assets/css/projects-optimized.css" rel="stylesheet">

<div class="container-fluid">
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i> <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- 项目列表 -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">项目列表</h5>
            <a href="project_add.php" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> 新增项目
            </a>
        </div>
        <div class="card-body">
            <?php if (empty($projects)): ?>
                <div class="text-center py-4">
                    <i class="bi bi-inbox display-1 text-muted"></i>
                    <h5 class="text-muted">暂无项目</h5>
                    <p class="text-muted">请先添加项目</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>项目名称/代码</th>
                                <th>所属公司</th>
                                <th>项目场地</th>
                                <th>指定酒店</th>
                                <th>开始日期</th>
                                <th>结束日期</th>
                                <th>状态</th>
                                <th>创建时间</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($projects as $project): ?>
                                <tr>
                                    <td>
                                        <div><?php echo htmlspecialchars($project['name']); ?></div>
                                        <div><small class="text-muted">代码: <code><?php echo htmlspecialchars($project['code']); ?></code></small></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($project['company_name']); ?></td>
                                    <td><?php echo htmlspecialchars($project['location']); ?></td>
                                    <td>
                                        <?php if (!empty($project['hotel_names'])): ?>
                                            <?php 
                                            // 将逗号分隔的酒店名称分行显示
                                            $hotels = explode(',', $project['hotel_names']);
                                            foreach ($hotels as $index => $hotel): ?>
                                                <div><?php echo htmlspecialchars(trim($hotel)); ?></div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="text-muted">未指定</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('Y-m-d', strtotime($project['start_date'])); ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($project['end_date'])); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $status_map[$project['status']]['class']; ?>">
                                            <?php echo $status_map[$project['status']]['label']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('Y-m-d', strtotime($project['created_at'])); ?></td>
                                    <td>
                                        <a href="project_edit.php?id=<?php echo $project['id']; ?>" 
                                           class="btn btn-sm btn-warning">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('确定要删除该项目吗？此操作不可恢复！');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $project['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>