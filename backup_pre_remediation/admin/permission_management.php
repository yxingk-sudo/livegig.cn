<?php
/**
 * 角色权限管理页面
 * 用于管理系统角色和分配权限
 */

session_start();
require_once '../config/database.php';
require_once '../includes/PermissionMiddleware.php';

$database = new Database();
$db = $database->getConnection();

// 页面权限验证
$middleware = new PermissionMiddleware($db);
$middleware->checkAdminPagePermission('backend:system:permission');

$permissionManager = $middleware->getPermissionManager();

// 获取所有角色
$roles = $permissionManager->getAllRoles();

// 设置页面变量
$page_title = '角色权限管理';
$active_page = 'permission_management';

// 引入头部
require_once 'includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-shield-lock"></i> 角色权限管理
                    </h5>
                </div>
                <div class="card-body">
                    <!-- 系统管理员提示 -->
                    <div class="alert alert-info mb-4">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>系统管理员</strong> 默认拥有所有后台权限，无需在此配置。
                        其他角色需要通过下方下拉框选择后配置权限。
                    </div>

                    <!-- 角色选择 -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">选择角色</label>
                            <select class="form-select" id="roleSelect">
                                <option value="">请选择角色...</option>
                                <?php foreach ($roles as $role): ?>
                                    <?php 
                                    // 系统管理员不在此处配置，默认拥有所有权限
                                    if ($role['role_key'] === 'super_admin') continue;
                                    ?>
                                    <option value="<?php echo $role['id']; ?>" 
                                            data-role-key="<?php echo htmlspecialchars($role['role_key']); ?>"
                                            data-role-type="<?php echo htmlspecialchars($role['role_type']); ?>">
                                        <?php echo htmlspecialchars($role['role_name']); ?>
                                        (<?php echo $role['role_type'] === 'backend' ? '后台' : '前台'; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <button type="button" class="btn btn-success me-2" id="savePermissionsBtn">
                                <i class="bi bi-save"></i> 保存权限配置
                            </button>
                            <button type="button" class="btn btn-info" id="refreshBtn">
                                <i class="bi bi-arrow-clockwise"></i> 刷新
                            </button>
                        </div>
                    </div>

                    <!-- 角色信息 -->
                    <div id="roleInfo" class="alert alert-info d-none mb-4">
                        <strong>角色描述：</strong> <span id="roleDescription"></span>
                    </div>

                    <!-- 权限树 -->
                    <div id="permissionTreeContainer" class="d-none">
                        <h6 class="mb-3 fw-bold">权限配置</h6>
                        
                        <!-- 快速操作 -->
                        <div class="mb-3">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="selectAllBtn">
                                <i class="bi bi-check-all"></i> 全选
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="deselectAllBtn">
                                <i class="bi bi-x-lg"></i> 取消全选
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-info" id="expandAllBtn">
                                <i class="bi bi-arrows-expand"></i> 展开全部
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-info" id="collapseAllBtn">
                                <i class="bi bi-arrows-collapse"></i> 折叠全部
                            </button>
                        </div>

                        <!-- 权限树形结构 -->
                        <div id="permissionTree" class="border rounded p-3" style="max-height: 600px; overflow-y: auto;">
                            <div class="text-center text-muted py-5">
                                <i class="bi bi-hourglass-split fs-3"></i>
                                <p>加载中...</p>
                            </div>
                        </div>
                    </div>

                    <!-- 未选择角色提示 -->
                    <div id="noRoleSelected" class="text-center text-muted py-5">
                        <i class="bi bi-shield-lock fs-1"></i>
                        <p class="mt-3">请在上方选择一个角色以配置权限</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Bootstrap Icons CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">

<script>
document.addEventListener('DOMContentLoaded', function() {
    const roleSelect = document.getElementById('roleSelect');
    const roleInfo = document.getElementById('roleInfo');
    const roleDescription = document.getElementById('roleDescription');
    const permissionTreeContainer = document.getElementById('permissionTreeContainer');
    const permissionTree = document.getElementById('permissionTree');
    const noRoleSelected = document.getElementById('noRoleSelected');
    const savePermissionsBtn = document.getElementById('savePermissionsBtn');
    
    let currentRoleId = null;
    let currentRoleType = null;
    let allPermissions = [];
    let rolePermissions = [];
    
    // 角色选择变化
    roleSelect.addEventListener('change', function() {
        currentRoleId = this.value;
        
        if (currentRoleId) {
            const selectedOption = this.options[this.selectedIndex];
            currentRoleType = selectedOption.getAttribute('data-role-type');
            
            // 显示角色信息
            roleDescription.textContent = selectedOption.textContent;
            roleInfo.classList.remove('d-none');
            noRoleSelected.classList.add('d-none');
            permissionTreeContainer.classList.remove('d-none');
            
            // 加载权限
            loadPermissions();
        } else {
            roleInfo.classList.add('d-none');
            permissionTreeContainer.classList.add('d-none');
            noRoleSelected.classList.remove('d-none');
        }
    });
    
    // 加载权限
    async function loadPermissions() {
        try {
            // 获取所有权限
            const allPermsResponse = await fetch(`api/role_permission_api.php?action=get_all_permissions&resource_type=${currentRoleType}`);
            const allPermsData = await allPermsResponse.json();
            
            if (!allPermsData.success) {
                throw new Error(allPermsData.message);
            }
            
            allPermissions = allPermsData.permissions;
            
            // 获取角色已有权限
            const rolePermsResponse = await fetch(`api/role_permission_api.php?action=get_role_permissions&role_id=${currentRoleId}`);
            const rolePermsData = await rolePermsResponse.json();
            
            if (!rolePermsData.success) {
                throw new Error(rolePermsData.message);
            }
            
            rolePermissions = rolePermsData.permissions;
            
            // 构建权限树
            buildPermissionTree();
            
        } catch (error) {
            console.error('加载权限失败:', error);
            alert('加载权限失败：' + error.message);
        }
    }
    
    // 构建权限树
    function buildPermissionTree() {
        const tree = buildTree(allPermissions, 0);
        permissionTree.innerHTML = renderTree(tree);
        
        // 绑定复选框事件
        bindCheckboxEvents();
    }
    
    // 构建树形结构
    function buildTree(permissions, parentId) {
        return permissions
            .filter(p => p.parent_id == parentId)
            .map(p => ({
                ...p,
                children: buildTree(permissions, p.id)
            }));
    }
    
    // 渲染树形HTML
    function renderTree(nodes, level = 0) {
        if (!nodes || nodes.length === 0) return '';
        
        let html = '<ul class="list-unstyled ms-' + (level * 3) + '">';
        
        nodes.forEach(node => {
            const isChecked = rolePermissions.some(p => p.id === node.id);
            const hasChildren = node.children && node.children.length > 0;
            
            html += '<li class="mb-2">';
            
            if (hasChildren) {
                html += `
                    <div class="d-flex align-items-center mb-1">
                        <button class="btn btn-sm btn-link text-decoration-none p-0 me-2 toggle-btn" data-target="children-${node.id}">
                            <i class="bi bi-chevron-right"></i>
                        </button>
                        <div class="form-check">
                            <input class="form-check-input permission-checkbox parent-checkbox" 
                                   type="checkbox" 
                                   value="${node.id}" 
                                   id="perm-${node.id}"
                                   data-permission-key="${node.permission_key}"
                                   ${isChecked ? 'checked' : ''}>
                            <label class="form-check-label fw-bold" for="perm-${node.id}">
                                ${getPermissionIcon(node.permission_type)} ${node.permission_name}
                                <small class="text-muted">(${getPermissionTypeName(node.permission_type)})</small>
                            </label>
                        </div>
                    </div>
                    <div id="children-${node.id}" class="collapse">
                        ${renderTree(node.children, level + 1)}
                    </div>
                `;
            } else {
                html += `
                    <div class="form-check">
                        <input class="form-check-input permission-checkbox" 
                               type="checkbox" 
                               value="${node.id}" 
                               id="perm-${node.id}"
                               data-permission-key="${node.permission_key}"
                               ${isChecked ? 'checked' : ''}>
                        <label class="form-check-label" for="perm-${node.id}">
                            ${getPermissionIcon(node.permission_type)} ${node.permission_name}
                            <small class="text-muted">(${getPermissionTypeName(node.permission_type)})</small>
                        </label>
                    </div>
                `;
            }
            
            html += '</li>';
        });
        
        html += '</ul>';
        return html;
    }
    
    // 获取权限类型图标
    function getPermissionIcon(type) {
        switch (type) {
            case 'page': return '<i class="bi bi-file-text text-primary"></i>';
            case 'function': return '<i class="bi bi-gear text-success"></i>';
            case 'data': return '<i class="bi bi-database text-info"></i>';
            default: return '<i class="bi bi-circle"></i>';
        }
    }
    
    // 获取权限类型名称
    function getPermissionTypeName(type) {
        switch (type) {
            case 'page': return '页面';
            case 'function': return '功能';
            case 'data': return '数据';
            default: return '未知';
        }
    }
    
    // 绑定复选框事件
    function bindCheckboxEvents() {
        // 展开/折叠按钮
        document.querySelectorAll('.toggle-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
                const target = document.getElementById(targetId);
                const icon = this.querySelector('i');
                
                if (target.classList.contains('show')) {
                    target.classList.remove('show');
                    icon.classList.remove('bi-chevron-down');
                    icon.classList.add('bi-chevron-right');
                } else {
                    target.classList.add('show');
                    icon.classList.remove('bi-chevron-right');
                    icon.classList.add('bi-chevron-down');
                }
            });
        });
        
        // 父节点复选框自动选中/取消子节点
        document.querySelectorAll('.parent-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const permId = this.value;
                const childrenContainer = document.getElementById(`children-${permId}`);
                
                if (childrenContainer) {
                    const childCheckboxes = childrenContainer.querySelectorAll('.permission-checkbox');
                    childCheckboxes.forEach(child => {
                        child.checked = this.checked;
                    });
                }
            });
        });
    }
    
    // 全选
    document.getElementById('selectAllBtn').addEventListener('click', function() {
        document.querySelectorAll('.permission-checkbox').forEach(cb => cb.checked = true);
    });
    
    // 取消全选
    document.getElementById('deselectAllBtn').addEventListener('click', function() {
        document.querySelectorAll('.permission-checkbox').forEach(cb => cb.checked = false);
    });
    
    // 展开全部
    document.getElementById('expandAllBtn').addEventListener('click', function() {
        document.querySelectorAll('.collapse').forEach(el => {
            el.classList.add('show');
        });
        document.querySelectorAll('.toggle-btn i').forEach(icon => {
            icon.classList.remove('bi-chevron-right');
            icon.classList.add('bi-chevron-down');
        });
    });
    
    // 折叠全部
    document.getElementById('collapseAllBtn').addEventListener('click', function() {
        document.querySelectorAll('.collapse').forEach(el => {
            el.classList.remove('show');
        });
        document.querySelectorAll('.toggle-btn i').forEach(icon => {
            icon.classList.remove('bi-chevron-down');
            icon.classList.add('bi-chevron-right');
        });
    });
    
    // 保存权限配置
    savePermissionsBtn.addEventListener('click', async function() {
        if (!currentRoleId) {
            alert('请先选择角色');
            return;
        }
        
        const selectedPermissionIds = Array.from(
            document.querySelectorAll('.permission-checkbox:checked')
        ).map(cb => parseInt(cb.value));
        
        try {
            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>保存中...';
            
            const formData = new FormData();
            formData.append('action', 'assign_permissions');
            formData.append('role_id', currentRoleId);
            formData.append('permission_ids', JSON.stringify(selectedPermissionIds));
            
            const response = await fetch('api/role_permission_api.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                alert('权限配置保存成功！');
                loadPermissions(); // 重新加载
            } else {
                throw new Error(data.message);
            }
            
        } catch (error) {
            console.error('保存权限失败:', error);
            alert('保存权限失败：' + error.message);
        } finally {
            this.disabled = false;
            this.innerHTML = '<i class="bi bi-save"></i> 保存权限配置';
        }
    });
    
    // 刷新
    document.getElementById('refreshBtn').addEventListener('click', function() {
        if (currentRoleId) {
            loadPermissions();
        }
    });
});
</script>

<style>
.collapse.show {
    display: block !important;
}

.toggle-btn {
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.toggle-btn:hover {
    background-color: rgba(0, 0, 0, 0.05);
    border-radius: 4px;
}

.permission-checkbox {
    cursor: pointer;
}

.form-check-label {
    cursor: pointer;
    user-select: none;
}

#permissionTree ul {
    padding-left: 1.5rem;
}
</style>

<?php require_once 'includes/footer.php'; ?>
