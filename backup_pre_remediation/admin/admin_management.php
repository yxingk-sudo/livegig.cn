<?php
/**
 * 管理员管理页面
 * 用于管理后台管理员用户及其权限范围配置
 */

session_start();
require_once '../config/database.php';
require_once '../includes/PermissionMiddleware.php';

$database = new Database();
$db = $database->getConnection();

// 页面权限验证
$middleware = new PermissionMiddleware($db);
$middleware->checkAdminPagePermission('backend:system:user');

$permissionManager = $middleware->getPermissionManager();

// 获取所有公司
$companies_query = "SELECT id, name FROM companies ORDER BY name ASC";
$companies_stmt = $db->prepare($companies_query);
$companies_stmt->execute();
$companies = $companies_stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取所有角色（仅后台角色）
$roles_query = "SELECT id, role_name, role_key FROM roles WHERE role_type = 'backend' ORDER BY id ASC";
$roles_stmt = $db->prepare($roles_query);
$roles_stmt->execute();
$roles = $roles_stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取所有管理员用户
$admins_query = "SELECT au.*, r.role_name, r.role_key, c.name as company_name 
                 FROM admin_users au
                 LEFT JOIN roles r ON au.role_id = r.id
                 LEFT JOIN companies c ON au.company_id = c.id
                 ORDER BY au.created_at DESC";
$admins_stmt = $db->prepare($admins_query);
$admins_stmt->execute();
$admins = $admins_stmt->fetchAll(PDO::FETCH_ASSOC);

// 设置页面变量
$page_title = '管理员管理';
$active_page = 'admin_management';

// 引入头部
require_once 'includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-people"></i> 管理员管理
                    </h5>
                    <button type="button" class="btn btn-light" data-bs-toggle="modal" data-bs-target="#addAdminModal">
                        <i class="bi bi-person-plus"></i> 添加管理员
                    </button>
                </div>
                <div class="card-body">
                    <!-- 管理员列表 -->
                    <?php if (empty($admins)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-people fs-1 text-muted"></i>
                            <h5 class="text-muted mt-3">暂无管理员用户</h5>
                            <p class="text-muted">点击右上角按钮添加管理员</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive" style="overflow-x: auto; width: 100%;">
                            <table class="table table-hover" style="font-size: 0.95rem; border-collapse: collapse; border: 1px solid #dee2e6; width: 100%;">
                                <thead>
                                    <tr>
                                        <th style="border: 1px solid #dee2e6; white-space: nowrap; width: auto;">用户名</th>
                                        <th style="border: 1px solid #dee2e6; white-space: nowrap;">真实姓名</th>
                                        <th style="border: 1px solid #dee2e6; white-space: normal; word-wrap: break-word; max-width: 250px;">邮箱</th>
                                        <th style="border: 1px solid #dee2e6; white-space: nowrap;">角色</th>
                                        <th style="border: 1px solid #dee2e6; white-space: nowrap;">最后登录</th>
                                        <th style="border: 1px solid #dee2e6; white-space: nowrap;">操作</th>
                                        <th style="border: 1px solid #dee2e6; white-space: nowrap;">状态</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($admins as $admin): ?>
                                        <tr>
                                            <td style="border: 1px solid #dee2e6; white-space: nowrap; width: auto;"><?php echo htmlspecialchars($admin['username']); ?></td>
                                            <td style="border: 1px solid #dee2e6; white-space: nowrap;"><?php echo htmlspecialchars($admin['real_name'] ?? '-'); ?></td>
                                            <td style="border: 1px solid #dee2e6; white-space: normal; word-wrap: break-word; max-width: 250px;"><?php echo htmlspecialchars($admin['email'] ?? '-'); ?></td>
                                            <td style="border: 1px solid #dee2e6; white-space: nowrap;">
                                                <span class="badge bg-primary">
                                                    <?php echo htmlspecialchars($admin['role_name']); ?>
                                                </span>
                                            </td>
                                            <td style="border: 1px solid #dee2e6; white-space: nowrap;">
                                                <?php
                                                if ($admin['last_login_time']) {
                                                    echo date('Y-m-d H:i', strtotime($admin['last_login_time']));
                                                } else {
                                                    echo '<span class="text-muted">未登录</span>';
                                                }
                                                ?>
                                            </td>
                                            <td style="border: 1px solid #dee2e6; white-space: nowrap;">
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-primary"
                                                            onclick="editAdmin(<?php echo $admin['id']; ?>)"
                                                            title="编辑">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <a href="scope_config.php?admin_id=<?php echo $admin['id']; ?>" 
                                                       class="btn btn-outline-info"
                                                       title="配置权限范围">
                                                        <i class="bi bi-shield-lock"></i>
                                                    </a>
                                                    <button type="button" class="btn btn-outline-danger"
                                                            onclick="deleteAdmin(<?php echo $admin['id']; ?>, '<?php echo htmlspecialchars($admin['username']); ?>')"
                                                            title="删除">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                            <td style="border: 1px solid #dee2e6; white-space: nowrap;">
                                                <span class="badge bg-<?php echo $admin['status'] ? 'success' : 'secondary'; ?>">
                                                    <?php echo $admin['status'] ? '启用' : '禁用'; ?>
                                                </span>
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
    </div>
</div>

<!-- 添加管理员模态框 -->
<div class="modal fade" id="addAdminModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">添加管理员</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="addAdminForm">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">用户名 *</label>
                                <input type="text" class="form-control" name="username" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">邮箱 *</label>
                                <input type="email" class="form-control" name="email" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">真实姓名</label>
                                <input type="text" class="form-control" name="real_name">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">手机号</label>
                                <input type="text" class="form-control" name="phone">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">角色 *</label>
                                <select class="form-select" name="role_id" required onchange="updateCompanyField(this.value)">
                                    <option value="">请选择角色</option>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?php echo $role['id']; ?>" 
                                                data-role-key="<?php echo $role['role_key']; ?>">
                                            <?php echo htmlspecialchars($role['role_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3" id="companyField" style="display: none;">
                                <label class="form-label">所属公司 *</label>
                                <select class="form-select" name="company_id">
                                    <option value="">请选择公司</option>
                                    <?php foreach ($companies as $company): ?>
                                        <option value="<?php echo $company['id']; ?>">
                                            <?php echo htmlspecialchars($company['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="saveAdmin()">保存</button>
            </div>
        </div>
    </div>
</div>

<!-- 编辑管理员模态框 -->
<div class="modal fade" id="editAdminModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">编辑管理员</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editAdminForm">
                    <input type="hidden" id="edit_admin_id" name="admin_user_id">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">用户名</label>
                                <input type="text" class="form-control" id="edit_username" disabled>
                                <small class="text-muted">用户名不可修改</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">邮箱 *</label>
                                <input type="email" class="form-control" id="edit_email" name="email" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">真实姓名</label>
                                <input type="text" class="form-control" id="edit_real_name" name="real_name">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">手机号</label>
                                <input type="text" class="form-control" id="edit_phone" name="phone">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">角色 *</label>
                                <select class="form-select" id="edit_role_id" name="role_id" required>
                                    <option value="">请选择角色</option>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?php echo $role['id']; ?>" 
                                                data-role-key="<?php echo $role['role_key']; ?>">
                                            <?php echo htmlspecialchars($role['role_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">状态</label>
                                <select class="form-select" id="edit_status" name="status">
                                    <option value="1">启用</option>
                                    <option value="0">禁用</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">操作</label>
                                <button type="button" class="btn btn-warning w-100" onclick="resetPassword()">
                                    <i class="bi bi-key"></i> 重置密码
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" onclick="updateAdmin()">保存修改</button>
            </div>
        </div>
    </div>
</div>



<script>
// 更新公司字段显示
function updateCompanyField(roleId) {
    const roleSelect = document.querySelector(`select[name="role_id"]`);
    const selectedOption = roleSelect.options[roleSelect.selectedIndex];
    const roleKey = selectedOption.getAttribute('data-role-key');
    const companyField = document.getElementById('companyField');
    
    if (roleKey === 'admin' || roleKey === 'project_admin') {
        companyField.style.display = 'block';
    } else {
        companyField.style.display = 'none';
    }
}

// 保存管理员
function saveAdmin() {
    const form = document.getElementById('addAdminForm');
    const formData = new FormData(form);
    formData.append('action', 'add_admin_user');
    
    // 验证必填字段
    if (!formData.get('username') || !formData.get('email') || !formData.get('role_id')) {
        alert('请填写必填字段');
        return;
    }
    
    // 验证角色是否需要公司
    const roleId = formData.get('role_id');
    const roleSelect = document.querySelector(`select[name="role_id"]`);
    const selectedOption = roleSelect.options[roleSelect.selectedIndex];
    const roleKey = selectedOption.getAttribute('data-role-key');
    const companyField = document.getElementById('companyField');
    
    if ((roleKey === 'admin' || roleKey === 'project_admin') && companyField.style.display !== 'none' && !formData.get('company_id')) {
        alert('请选择所属公司');
        return;
    }
    
    fetch('api/admin_management_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('管理员添加成功！');
            location.reload();
        } else {
            alert('添加失败：' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('添加失败：' + error.message);
    });
}

// 编辑管理员
function editAdmin(adminId) {
    // 获取管理员信息
    fetch(`api/admin_management_api.php?action=get_admin&admin_user_id=${adminId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const admin = data.admin;
                
                // 填充表单
                document.getElementById('edit_admin_id').value = admin.id;
                document.getElementById('edit_username').value = admin.username;
                document.getElementById('edit_email').value = admin.email;
                document.getElementById('edit_real_name').value = admin.real_name || '';
                document.getElementById('edit_phone').value = admin.phone || '';
                document.getElementById('edit_role_id').value = admin.role_id;
                document.getElementById('edit_status').value = admin.status;

                // 显示模态框 - 先获取或创建实例
                const modalElement = document.getElementById('editAdminModal');
                let modal = bootstrap.Modal.getInstance(modalElement);
                if (!modal) {
                    modal = new bootstrap.Modal(modalElement);
                }
                modal.show();
            } else {
                alert('获取管理员信息失败：' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('获取管理员信息失败：' + error.message);
        });
}

// 保存管理员修改
function updateAdmin() {
    const form = document.getElementById('editAdminForm');
    const formData = new FormData(form);
    formData.append('action', 'update_admin_user');

    // 验证必填字段
    if (!formData.get('email') || !formData.get('role_id')) {
        alert('请填写必填字段');
        return;
    }

    fetch('api/admin_management_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('管理员更新成功！');
            location.reload();
        } else {
            alert('更新失败：' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('更新失败：' + error.message);
    });
}

// 重置密码
function resetPassword() {
    const adminId = document.getElementById('edit_admin_id').value;
    const username = document.getElementById('edit_username').value;
    
    if (confirm(`确定要重置管理员 "${username}" 的密码吗？`)) {
        const formData = new FormData();
        formData.append('action', 'reset_admin_password');
        formData.append('admin_user_id', adminId);
        
        fetch('api/admin_management_api.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showPasswordModal(data.password, username);
            } else {
                alert('密码重置失败：' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('密码重置失败：' + error.message);
        });
    }
}

// 显示密码模态框并提供复制功能
function showPasswordModal(password, username) {
    // 创建模态框HTML
    const modalHTML = `
        <div class="modal fade" id="passwordModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title">
                            <i class="bi bi-check-circle-fill me-2"></i>密码重置成功
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning mb-3">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <strong>重要提示：</strong>此密码仅显示一次，请立即复制保存！
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">管理员账号</label>
                            <div class="input-group">
                                <input type="text" class="form-control" value="${username}" readonly>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">新密码</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="newPasswordInput" value="${password}" readonly ondblclick="this.select()">
                                <button class="btn btn-primary" type="button" onclick="copyPassword()">
                                    <i class="bi bi-clipboard me-1"></i>复制密码
                                </button>
                            </div>
                            <small class="text-muted">
                                <i class="bi bi-info-circle me-1"></i>点击按钮复制，或双击密码框选中后按 Ctrl+C 手动复制
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">我已保存密码</button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // 移除旧的密码模态框（如果存在）
    const oldModal = document.getElementById('passwordModal');
    if (oldModal) {
        oldModal.remove();
    }
    
    // 添加新模态框到页面
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    
    // 显示模态框
    const passwordModal = new bootstrap.Modal(document.getElementById('passwordModal'));
    passwordModal.show();
    
    // 模态框关闭后移除DOM元素
    document.getElementById('passwordModal').addEventListener('hidden.bs.modal', function () {
        this.remove();
    });
}

// 复制密码到剪贴板
function copyPassword() {
    const passwordInput = document.getElementById('newPasswordInput');
    
    // 先尝试选中文本
    passwordInput.focus();
    passwordInput.select();
    
    try {
        // 方法1: 使用现代 Clipboard API
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(passwordInput.value)
                .then(() => {
                    showCopySuccess();
                })
                .catch(err => {
                    console.log('Clipboard API 失败，尝试传统方法:', err);
                    fallbackCopyPassword();
                });
        } else {
            // 方法2: 传统的 execCommand 方法
            fallbackCopyPassword();
        }
    } catch (err) {
        console.error('复制过程出错:', err);
        fallbackCopyPassword();
    }
}

// 降级复制方法（兼容旧浏览器）
function fallbackCopyPassword() {
    const passwordInput = document.getElementById('newPasswordInput');
    
    try {
        // 确保输入框可见且可操作
        passwordInput.style.position = 'static';
        passwordInput.style.opacity = '1';
        passwordInput.removeAttribute('readonly');
        passwordInput.focus();
        passwordInput.select();
        
        // 对于移动端
        if (navigator.userAgent.match(/ipad|ipod|iphone/i)) {
            const range = document.createRange();
            range.selectNodeContents(passwordInput);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            passwordInput.setSelectionRange(0, 999999);
        } else {
            passwordInput.setSelectionRange(0, 999999);
        }
        
        // 执行复制命令
        const successful = document.execCommand('copy');
        
        // 恢复只读状态
        passwordInput.setAttribute('readonly', 'readonly');
        
        if (successful) {
            showCopySuccess();
        } else {
            showCopyManualPrompt(passwordInput.value);
        }
    } catch (err) {
        console.error('传统复制方法也失败:', err);
        showCopyManualPrompt(passwordInput.value);
    }
}

// 显示手动复制提示
function showCopyManualPrompt(password) {
    const passwordInput = document.getElementById('newPasswordInput');
    passwordInput.select();
    
    // 创建一个更友好的提示
    const btn = event.target.closest('button');
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-exclamation-circle me-1"></i>请手动选择复制';
    btn.classList.remove('btn-primary');
    btn.classList.add('btn-warning');
    
    // 添加视觉提示
    passwordInput.style.backgroundColor = '#fff3cd';
    passwordInput.style.border = '2px solid #ffc107';
    
    setTimeout(() => {
        btn.innerHTML = originalHTML;
        btn.classList.remove('btn-warning');
        btn.classList.add('btn-primary');
        passwordInput.style.backgroundColor = '';
        passwordInput.style.border = '';
    }, 5000);
    
    // 显示提示信息
    alert('自动复制失败，密码已选中，请按 Ctrl+C (Mac: Cmd+C) 手动复制');
}

// 显示复制成功提示
function showCopySuccess() {
    const btn = event.target.closest('button');
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>已复制';
    btn.classList.remove('btn-primary');
    btn.classList.add('btn-success');
    
    setTimeout(() => {
        btn.innerHTML = originalHTML;
        btn.classList.remove('btn-success');
        btn.classList.add('btn-primary');
    }, 2000);
}

// 删除管理员
function deleteAdmin(adminId, username) {
    if (confirm(`确定要删除管理员 "${username}" 吗？此操作不可恢复！`)) {
        const formData = new FormData();
        formData.append('action', 'delete_admin_user');
        formData.append('admin_user_id', adminId);
        
        fetch('api/admin_management_api.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('管理员删除成功！');
                location.reload();
            } else {
                alert('删除失败：' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('删除失败：' + error.message);
        });
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>