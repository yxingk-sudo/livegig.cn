<?php
/**
 * 前台用户权限管理页面
 * 仅供前台管理员使用
 */

session_start();
require_once '../config/database.php';
require_once '../includes/PermissionMiddleware.php';

// 检查是否登录
if (!isset($_SESSION['user_id']) || !isset($_SESSION['project_id'])) {
    header('Location: ../index.php');
    exit;
}

$database = new Database();
$db = $database->getConnection();

// 只有前台管理员可以访问此页面
$middleware = new PermissionMiddleware($db);
if (!$middleware->isUserAdmin()) {
    header('Location: dashboard.php');
    exit;
}

$permissionManager = $middleware->getPermissionManager();
$userId = $_SESSION['user_id'];
$projectId = $_SESSION['project_id'];

// 获取所有前台角色
$roles = $permissionManager->getAllRoles('frontend');

// 获取当前项目的所有用户
$query = "SELECT pu.*, 
          (SELECT role_name FROM user_roles ur 
           INNER JOIN roles r ON ur.role_id = r.id 
           WHERE ur.user_id = pu.id AND ur.project_id = :project_id 
           LIMIT 1) as role_name
          FROM project_users pu
          WHERE pu.is_active = 1
          ORDER BY pu.username";
$stmt = $db->prepare($query);
$stmt->execute([':project_id' => $projectId]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 设置页面变量
$page_title = '用户权限管理';
$active_page = 'user_permission_management';
$show_page_title = '用户权限管理';

// 引入头部
require_once 'includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                <strong>说明：</strong>作为前台管理员,您可以为本项目的用户分配角色和权限。
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-people"></i> 用户列表
                    </h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>用户名</th>
                                    <th>显示名称</th>
                                    <th>当前角色</th>
                                    <th>状态</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo $user['id']; ?></td>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['display_name'] ?? '-'); ?></td>
                                    <td>
                                        <?php if ($user['role_name']): ?>
                                            <span class="badge bg-info"><?php echo htmlspecialchars($user['role_name']); ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">未分配</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($user['is_active']): ?>
                                            <span class="badge bg-success">启用</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">禁用</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-primary assign-role-btn" 
                                                data-user-id="<?php echo $user['id']; ?>"
                                                data-username="<?php echo htmlspecialchars($user['username']); ?>">
                                            <i class="bi bi-shield-check"></i> 分配角色
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 分配角色模态框 -->
<div class="modal fade" id="assignRoleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">分配角色</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>用户：<strong id="modalUsername"></strong></p>
                <div class="mb-3">
                    <label class="form-label">选择角色</label>
                    <select class="form-select" id="roleSelect">
                        <option value="">请选择角色...</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?php echo $role['id']; ?>">
                                <?php echo htmlspecialchars($role['role_name']); ?>
                                - <?php echo htmlspecialchars($role['description']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" id="saveRoleBtn">保存</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const assignRoleButtons = document.querySelectorAll('.assign-role-btn');
    const assignRoleModal = new bootstrap.Modal(document.getElementById('assignRoleModal'));
    const modalUsername = document.getElementById('modalUsername');
    const roleSelect = document.getElementById('roleSelect');
    const saveRoleBtn = document.getElementById('saveRoleBtn');
    let currentUserId = null;
    
    // 打开分配角色对话框
    assignRoleButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            currentUserId = this.getAttribute('data-user-id');
            const username = this.getAttribute('data-username');
            modalUsername.textContent = username;
            roleSelect.value = '';
            assignRoleModal.show();
        });
    });
    
    // 保存角色分配
    saveRoleBtn.addEventListener('click', async function() {
        const roleId = roleSelect.value;
        
        if (!roleId) {
            alert('请选择角色');
            return;
        }
        
        try {
            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>保存中...';
            
            const formData = new FormData();
            formData.append('action', 'assign_user_role');
            formData.append('user_id', currentUserId);
            formData.append('role_id', roleId);
            formData.append('project_id', <?php echo $projectId; ?>);
            
            const response = await fetch('../admin/api/role_permission_api.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                alert('角色分配成功！');
                location.reload();
            } else {
                throw new Error(data.message || '分配失败');
            }
            
        } catch (error) {
            console.error('分配角色失败:', error);
            alert('分配角色失败：' + error.message);
        } finally {
            this.disabled = false;
            this.innerHTML = '保存';
        }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
