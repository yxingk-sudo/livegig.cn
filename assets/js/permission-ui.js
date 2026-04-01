/**
 * 前端权限管理库
 * 用于根据用户权限动态控制界面元素的显示和交互
 */

class PermissionUI {
    constructor() {
        this.userPermissions = [];
        this.initialized = false;
    }
    
    /**
     * 初始化权限系统
     */
    async init() {
        if (this.initialized) {
            return;
        }
        
        try {
            // 从服务器获取当前用户的权限列表
            const response = await fetch('/api/get_user_permissions.php');
            const data = await response.json();
            
            if (data.success) {
                this.userPermissions = data.permissions || [];
                this.initialized = true;
                
                // 应用权限控制
                this.applyPermissionControl();
            } else {
                console.error('获取权限失败:', data.message);
            }
        } catch (error) {
            console.error('初始化权限系统错误:', error);
        }
    }
    
    /**
     * 检查是否有指定权限
     */
    hasPermission(permissionKey) {
        return this.userPermissions.some(p => p.permission_key === permissionKey);
    }
    
    /**
     * 检查是否有任一权限
     */
    hasAnyPermission(permissionKeys) {
        return permissionKeys.some(key => this.hasPermission(key));
    }
    
    /**
     * 检查是否有所有权限
     */
    hasAllPermissions(permissionKeys) {
        return permissionKeys.every(key => this.hasPermission(key));
    }
    
    /**
     * 应用权限控制到页面元素
     */
    applyPermissionControl() {
        // 处理带有 data-permission 属性的元素
        document.querySelectorAll('[data-permission]').forEach(element => {
            const permission = element.getAttribute('data-permission');
            const requireAll = element.getAttribute('data-permission-all') === 'true';
            const permissions = permission.split(',').map(p => p.trim());
            
            let hasAccess = false;
            if (requireAll) {
                hasAccess = this.hasAllPermissions(permissions);
            } else {
                hasAccess = this.hasAnyPermission(permissions);
            }
            
            if (!hasAccess) {
                const action = element.getAttribute('data-permission-action') || 'hide';
                this.applyAction(element, action);
            }
        });
        
        // 处理带有 data-permission-function 属性的按钮
        document.querySelectorAll('[data-permission-function]').forEach(element => {
            const permission = element.getAttribute('data-permission-function');
            
            if (!this.hasPermission(permission)) {
                const action = element.getAttribute('data-permission-action') || 'disable';
                this.applyAction(element, action);
            }
        });
    }
    
    /**
     * 对元素应用权限动作
     */
    applyAction(element, action) {
        switch (action) {
            case 'hide':
                element.style.display = 'none';
                break;
            case 'disable':
                element.disabled = true;
                element.classList.add('disabled');
                element.style.opacity = '0.5';
                element.style.cursor = 'not-allowed';
                // 移除点击事件
                element.onclick = function(e) {
                    e.preventDefault();
                    return false;
                };
                break;
            case 'readonly':
                element.readOnly = true;
                element.classList.add('readonly');
                break;
            case 'remove':
                element.remove();
                break;
        }
    }
    
    /**
     * 显示权限不足提示
     */
    showPermissionDenied(message = '您没有权限执行此操作') {
        // 使用Bootstrap的Toast或Alert显示提示
        if (typeof bootstrap !== 'undefined' && bootstrap.Toast) {
            const toastHTML = `
                <div class="toast align-items-center text-white bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true">
                    <div class="d-flex">
                        <div class="toast-body">
                            <i class="bi bi-shield-x me-2"></i>${message}
                        </div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                </div>
            `;
            
            const toastContainer = document.getElementById('toastContainer') || this.createToastContainer();
            toastContainer.insertAdjacentHTML('beforeend', toastHTML);
            
            const toastElement = toastContainer.lastElementChild;
            const toast = new bootstrap.Toast(toastElement);
            toast.show();
            
            // 自动移除
            toastElement.addEventListener('hidden.bs.toast', () => {
                toastElement.remove();
            });
        } else {
            alert(message);
        }
    }
    
    /**
     * 创建Toast容器
     */
    createToastContainer() {
        const container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'toast-container position-fixed top-0 end-0 p-3';
        container.style.zIndex = '9999';
        document.body.appendChild(container);
        return container;
    }
    
    /**
     * 检查并验证表单提交权限
     */
    validateFormPermission(form, permissionKey) {
        if (!this.hasPermission(permissionKey)) {
            this.showPermissionDenied();
            return false;
        }
        return true;
    }
    
    /**
     * 检查并验证操作权限
     */
    validateActionPermission(permissionKey, showError = true) {
        const hasAccess = this.hasPermission(permissionKey);
        
        if (!hasAccess && showError) {
            this.showPermissionDenied();
        }
        
        return hasAccess;
    }
    
    /**
     * 根据权限动态生成菜单
     */
    generateMenu(menuConfig, containerSelector) {
        const container = document.querySelector(containerSelector);
        
        if (!container) {
            console.error('菜单容器不存在:', containerSelector);
            return;
        }
        
        const menuHTML = this.buildMenuHTML(menuConfig);
        container.innerHTML = menuHTML;
    }
    
    /**
     * 构建菜单HTML
     */
    buildMenuHTML(items, level = 0) {
        let html = '';
        
        items.forEach(item => {
            // 检查权限
            if (item.permission && !this.hasPermission(item.permission)) {
                return;
            }
            
            const hasChildren = item.children && item.children.length > 0;
            const icon = item.icon ? `<i class="${item.icon}"></i> ` : '';
            
            if (hasChildren) {
                // 过滤有权限的子菜单
                const accessibleChildren = item.children.filter(child => 
                    !child.permission || this.hasPermission(child.permission)
                );
                
                // 如果没有可访问的子菜单，跳过此菜单项
                if (accessibleChildren.length === 0) {
                    return;
                }
                
                // 下拉菜单
                html += `
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            ${icon}${item.label}
                        </a>
                        <ul class="dropdown-menu">
                            ${this.buildDropdownItems(accessibleChildren)}
                        </ul>
                    </li>
                `;
            } else {
                // 单个菜单项
                html += `
                    <li class="nav-item">
                        <a class="nav-link" href="${item.url || '#'}">
                            ${icon}${item.label}
                        </a>
                    </li>
                `;
            }
        });
        
        return html;
    }
    
    /**
     * 构建下拉菜单项
     */
    buildDropdownItems(items) {
        let html = '';
        
        items.forEach(item => {
            const icon = item.icon ? `<i class="${item.icon}"></i> ` : '';
            html += `
                <li>
                    <a class="dropdown-item" href="${item.url || '#'}">
                        ${icon}${item.label}
                    </a>
                </li>
            `;
        });
        
        return html;
    }
    
    /**
     * 刷新权限（重新获取）
     */
    async refresh() {
        this.initialized = false;
        this.userPermissions = [];
        await this.init();
    }
}

// 创建全局实例
const permissionUI = new PermissionUI();

// DOM加载完成后自动初始化
document.addEventListener('DOMContentLoaded', function() {
    permissionUI.init();
});

// 导出为全局变量
window.PermissionUI = PermissionUI;
window.permissionUI = permissionUI;
