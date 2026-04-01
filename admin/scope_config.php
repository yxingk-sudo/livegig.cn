<?php
/**
 * 配置权限范围页面
 * 用于配置管理员的项目权限范围
 */

session_start();
require_once '../config/database.php';
require_once '../includes/PermissionMiddleware.php';

$database = new Database();
$db = $database->getConnection();
$middleware = new PermissionMiddleware($db);

// 页面权限验证
$middleware->checkAdminPagePermission('backend:system:permission');

// 获取参数
$adminId = $_GET['admin_id'] ?? 0;
if (!$adminId) {
    header('Location: admin_management.php?error=' . urlencode('管理员ID不能为空'));
    exit;
}

// 获取管理员信息
$query = "SELECT au.*, r.role_key, r.role_name 
          FROM admin_users au
          INNER JOIN roles r ON au.role_id = r.id
          WHERE au.id = :admin_id";
$stmt = $db->prepare($query);
$stmt->execute([':admin_id' => $adminId]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    header('Location: admin_management.php?error=' . urlencode('管理员不存在'));
    exit;
}

// 获取所有项目
$projectsQuery = "SELECT p.id, p.name, c.name as company_name 
                  FROM projects p 
                  INNER JOIN companies c ON p.company_id = c.id 
                  ORDER BY c.name ASC, p.name ASC";
$projectsStmt = $db->prepare($projectsQuery);
$projectsStmt->execute();
$projects = $projectsStmt->fetchAll(PDO::FETCH_ASSOC);

// 获取当前管理员已配置的项目
$scopeQuery = "SELECT project_id FROM admin_user_projects WHERE admin_user_id = :admin_id";
$scopeStmt = $db->prepare($scopeQuery);
$scopeStmt->execute([':admin_id' => $adminId]);
$selectedProjectIds = $scopeStmt->fetchAll(PDO::FETCH_COLUMN);

// 设置角色说明
$roleDescription = '';
switch ($admin['role_key']) {
    case 'super_admin':
        $roleDescription = '系统管理员拥有系统所有权限，可管理所有项目';
        break;
    case 'admin':
        $roleDescription = '管理员可管理指定的项目';
        break;
    case 'project_admin':
        $roleDescription = '项目管理员仅可管理指定的项目';
        break;
    default:
        $roleDescription = '普通管理员';
}

// 设置页面变量
$page_title = '配置权限范围 - ' . htmlspecialchars($admin['username']);
$active_page = 'admin_management';

// 引入头部
require_once 'includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <!-- 页面标题 -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h4 class="mb-1">配置权限范围</h4>
                    <p class="text-muted mb-0">为管理员 <strong><?php echo htmlspecialchars($admin['username']); ?></strong> 配置可管理的项目</p>
                </div>
                <a href="admin_management.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left me-1"></i>返回管理员列表
                </a>
            </div>

            <!-- 角色信息 -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>角色信息</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <p class="mb-1"><strong>用户名：</strong><?php echo htmlspecialchars($admin['username']); ?></p>
                        </div>
                        <div class="col-md-4">
                            <p class="mb-1"><strong>真实姓名：</strong><?php echo htmlspecialchars($admin['real_name'] ?? '-'); ?></p>
                        </div>
                        <div class="col-md-4">
                            <p class="mb-1"><strong>角色：</strong><span class="badge bg-primary"><?php echo htmlspecialchars($admin['role_name']); ?></span></p>
                        </div>
                    </div>
                    <hr class="my-3">
                    <p class="mb-0 text-muted"><?php echo $roleDescription; ?></p>
                </div>
            </div>

            <!-- 项目选择 -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-list-check me-2"></i>项目范围配置</h5>
                </div>
                <div class="card-body">
                    <form id="scopeConfigForm">
                        <input type="hidden" name="admin_id" value="<?php echo $adminId; ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">选择可管理的项目（可多选）</label>
                            <div class="mb-2">
                                <span class="badge bg-success me-2"><i class="bi bi-check-circle-fill me-1"></i>已配置</span>
                                <span class="badge bg-light text-dark border"><i class="bi bi-circle me-1"></i>未配置</span>
                            </div>
                            <select class="form-select" id="projectSelect" name="project_ids[]" multiple size="15">
                                <?php foreach ($projects as $project): ?>
                                    <?php $isSelected = in_array($project['id'], $selectedProjectIds); ?>
                                    <option value="<?php echo $project['id']; ?>"
                                            <?php echo $isSelected ? 'selected' : ''; ?>
                                            <?php echo $isSelected ? 'style="background-color: #d1e7dd; font-weight: 500;"' : 'style="background-color: #f8f9fa;"'; ?>
                                            data-was-selected="<?php echo $isSelected ? 'true' : 'false'; ?>">
                                        <?php echo $isSelected ? '✓ ' : '○ '; ?>[<?php echo htmlspecialchars($project['company_name']); ?>] <?php echo htmlspecialchars($project['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">
                                <i class="bi bi-info-circle me-1"></i>提示：按住 Ctrl 键（Windows）或 Command 键（Mac）可多选项目；<span class="text-success fw-bold">绿色背景</span>为已配置项目
                            </small>
                        </div>

                        <div class="d-flex justify-content-between">
                            <div>
                                <button type="button" class="btn btn-outline-secondary" onclick="selectAll()">
                                    <i class="bi bi-check-all me-1"></i>全选
                                </button>
                                <button type="button" class="btn btn-outline-secondary" onclick="deselectAll()">
                                    <i class="bi bi-x-lg me-1"></i>取消全选
                                </button>
                            </div>
                            <div>
                                <a href="admin_management.php" class="btn btn-secondary me-2">取消</a>
                                <button type="button" class="btn btn-primary" onclick="saveScopeConfig()">
                                    <i class="bi bi-save me-1"></i>保存配置
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// 全选
function selectAll() {
    const select = document.getElementById('projectSelect');
    for (let i = 0; i < select.options.length; i++) {
        select.options[i].selected = true;
    }
}

// 取消全选
function deselectAll() {
    const select = document.getElementById('projectSelect');
    for (let i = 0; i < select.options.length; i++) {
        select.options[i].selected = false;
    }
}

// 保存配置
function saveScopeConfig() {
    const form = document.getElementById('scopeConfigForm');
    const formData = new FormData(form);
    
    // 获取选中的项目ID（转换为整数）
    const projectSelect = document.getElementById('projectSelect');
    const projectIds = Array.from(projectSelect.selectedOptions).map(option => parseInt(option.value, 10)).filter(id => id > 0);
    
    // 构建提交数据
    const submitData = new FormData();
    submitData.append('action', 'save_admin_scope');
    submitData.append('admin_user_id', formData.get('admin_id'));
    submitData.append('project_ids', JSON.stringify(projectIds));
    
    fetch('api/admin_scope_api.php', {
        method: 'POST',
        body: submitData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('范围配置保存成功！');
            window.location.href = 'admin_management.php';
        } else {
            alert('保存失败：' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('保存失败：' + (error.message || '网络错误'));
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>
