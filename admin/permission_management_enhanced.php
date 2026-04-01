<?php
/**
 * 增强版角色权限管理页面
 * 用于管理系统角色和分配权限，支持项目和公司级别的细粒度权限控制
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

// 获取所有公司
$companies_query = "SELECT id, name FROM companies ORDER BY name ASC";
$companies_stmt = $db->prepare($companies_query);
$companies_stmt->execute();
$companies = $companies_stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取所有项目
$projects_query = "SELECT id, name, company_id FROM projects ORDER BY name ASC";
$projects_stmt = $db->prepare($projects_query);
$projects_stmt->execute();
$projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);

// 按公司分组项目
$projectsByCompany = [];
foreach ($projects as $project) {
    if (!isset($projectsByCompany[$project['company_id']])) {
        $projectsByCompany[$project['company_id']] = [];
    }
    $projectsByCompany[$project['company_id']][] = $project;
}

// 设置页面变量
$page_title = '权限查看';
$active_page = 'permission_management';

// 引入头部
require_once 'includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-eye"></i> 权限查看
                    </h5>
                </div>
                <div class="card-body">
                    <!-- 功能说明 -->
                    <div class="alert alert-warning mb-4">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>此页面仅供查看角色权限，不可编辑。</strong><br>
                        如需配置角色权限，请前往 <a href="permission_management.php" class="alert-link">角色权限管理</a> 页面。<br>
                        系统管理员默认拥有所有后台权限，无需配置。
                    </div>
                    <!-- 角色选择 -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">选择角色</label>
                            <select class="form-select" id="roleSelect">
                                <option value="">请选择角色...</option>
                                <?php foreach ($roles as $role): ?>
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
                            <a href="permission_management.php" class="btn btn-primary me-2">
                                <i class="bi bi-pencil"></i> 前往配置权限
                            </a>
                            <button type="button" class="btn btn-info" id="refreshBtn">
                                <i class="bi bi-arrow-clockwise"></i> 刷新
                            </button>
                        </div>
                    </div>

                    <!-- 角色信息 -->
                    <div id="roleInfo" class="alert alert-info d-none mb-4">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <strong>角色描述：</strong> <span id="roleDescription"></span>
                            </div>
                            <div id="roleScopeInfo"></div>
                        </div>
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
    
    let currentRoleId = null;
    let currentRoleKey = null;
    let currentRoleType = null;
    let allPermissions = [];
    let rolePermissions = [];
    
    // 角色选择变化
    roleSelect.addEventListener('change', function() {
        currentRoleId = this.value;
        const selectedOption = this.options[this.selectedIndex];
        
        if (currentRoleId) {
            currentRoleKey = selectedOption.getAttribute('data-role-key');
            currentRoleType = selectedOption.getAttribute('data-role-type');
            
            // 显示角色信息
            roleDescription.textContent = selectedOption.textContent;
            roleInfo.classList.remove('d-none');
            noRoleSelected.classList.add('d-none');
            
            // 显示权限树
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
                                   ${isChecked ? 'checked' : ''} disabled>
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
                               ${isChecked ? 'checked' : ''} disabled>
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

#projectScopeSelect {
    height: auto !important;
    min-height: 120px;
}
</style>

<?php require_once 'includes/footer.php'; ?>