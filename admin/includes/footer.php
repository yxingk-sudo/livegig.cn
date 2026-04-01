        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <p class="mb-0">&copy; <?php echo date('Y'); ?> 项目管理系统 - 管理后台</p>
            <span>
                <small class="text-muted">
                    <i class="bi bi-clock"></i> 页面加载时间: <?php echo round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3); ?>秒
                </small>
            </span>
        </div>
    </footer>

    <!-- jQuery -->
    <script src="assets/js/jquery.min.js"></script>
    
    <!-- Bootstrap JS -->
    <script src="assets/js/app.min.js"></script>
    
    <!-- ApexCharts JS -->
    <script src="assets/js/apexcharts.min.js"></script>

    <!-- 侧边栏切换按钮样式 -->
    <style>
    /* 侧边栏切换按钮 */
    .sidebar-toggle-btn {
        position: fixed;
        top: 50%;
        left: 250px;
        transform: translateY(-50%);
        z-index: 1500; /* 提高z-index确保可点击 */
        width: 30px;
        height: 60px;
        background: linear-gradient(135deg, #1976d2, #42a5f5);
        border: none;
        border-radius: 0 8px 8px 0;
        color: white;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        box-shadow: 2px 0 10px rgba(25, 118, 210, 0.3);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        user-select: none;
        outline: none;
    }

    .sidebar-toggle-btn:hover {
        background: linear-gradient(135deg, #1565c0, #1976d2);
        box-shadow: 2px 0 15px rgba(25, 118, 210, 0.4);
        transform: translateY(-50%) translateX(3px);
    }

    .sidebar-toggle-btn:active {
        transform: translateY(-50%) translateX(1px) scale(0.95);
    }

    .sidebar-toggle-btn i {
        transition: transform 0.3s ease;
    }

    /* 侧边栏收起状态 */
    .sidebar-collapsed .sidebar {
        width: 60px !important;
        overflow-y: auto; /* 确保收起状态下也能滚动 */
        overflow-x: visible;
    }

    .sidebar-collapsed .sidebar .nav-link {
        padding: 10px 5px !important;
        text-align: center;
        justify-content: center;
        position: relative;
        display: flex !important; /* 确保链接显示 */
        align-items: center;
    }

    .sidebar-collapsed .sidebar .nav-link span {
        display: none;
    }

    .sidebar-collapsed .sidebar .nav-link i {
        margin-right: 0 !important;
        font-size: 18px;
        display: inline-block !important; /* 确保图标显示 */
    }

    .sidebar-collapsed .sidebar .menu-group-title {
        display: none;
    }

    .sidebar-collapsed .sidebar > div:first-child {
        text-align: center;
        padding: 1rem 0.5rem !important;
    }

    .sidebar-collapsed .sidebar > div:first-child h5 {
        display: none;
    }

    .sidebar-collapsed .sidebar > div:first-child small {
        display: none;
    }

    /* 主内容区域自适应 */
    .sidebar-collapsed .main-content {
        margin-left: 80px !important;
    }

    /* 切换按钮位置调整 */
    .sidebar-collapsed .sidebar-toggle-btn {
        left: 60px;
        z-index: 1500; /* 确保按钮始终在最上层 */
    }

    .sidebar-collapsed .sidebar-toggle-btn i {
        transform: rotate(180deg);
    }

    /* 修复主内容区域在不同状态下的样式 */
    @media (min-width: 992px) {
        .main-content {
            margin-left: 270px !important; /* 确保为侧边栏留出足够空间 */
        }
        
        .sidebar-collapsed .main-content {
            margin-left: 80px !important;
        }
    }

    /* 工具提示 */
    .sidebar-collapsed .sidebar .nav-link:hover::after {
        content: attr(data-tooltip);
        position: absolute;
        left: calc(100% + 10px); /* 使用calc避免重叠 */
        top: 50%;
        transform: translateY(-50%);
        background: #333;
        color: white;
        padding: 8px 12px;
        border-radius: 4px;
        font-size: 12px;
        white-space: nowrap;
        z-index: 2000; /* 确保tooltip在最上层 */
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        pointer-events: none; /* 避免干扰点击事件 */
    }

    .sidebar-collapsed .sidebar .nav-link:hover::before {
        content: '';
        position: absolute;
        left: calc(100% + 5px);
        top: 50%;
        transform: translateY(-50%);
        border: 5px solid transparent;
        border-right-color: #333;
        z-index: 2000;
        pointer-events: none;
    }

    /* 响应式处理 */
    @media (max-width: 991.98px) {
        .sidebar-toggle-btn {
            display: none !important;
        }
    }

    /* 平滑过渡动画 */
    .sidebar, 
    .main-content,
    .sidebar-toggle-btn {
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .sidebar .nav-link,
    .sidebar .nav-link i,
    .sidebar .nav-link span {
        transition: all 0.3s ease;
    }
    </style>

    <!-- 侧边栏切换按钮 HTML -->
    <button class="sidebar-toggle-btn" id="sidebarToggleBtn" title="切换侧边栏">
        <i class="bi bi-chevron-left"></i>
    </button>

    <!-- 自定义脚本 -->
    <script>
    // 页面加载完成后的通用功能
    document.addEventListener('DOMContentLoaded', function() {
        // 侧边栏切换功能
        initSidebarToggle();
        
        // 移动端菜单切换功能
        const mobileToggle = document.getElementById('mobileToggle');
        const sideNav = document.getElementById('sideNav');
        
        if (mobileToggle && sideNav) {
            // 使用Bootstrap原生事件监听器
            mobileToggle.addEventListener('click', function(e) {
                sideNav.classList.toggle('active');
            });
        }

        // 自动隐藏警告消息
        const alerts = document.querySelectorAll('.alert:not(.alert-permanent)');
        alerts.forEach(alert => {
            setTimeout(() => {
                if (alert.parentNode) {
                    alert.classList.add('fade');
                    setTimeout(() => alert.remove(), 150);
                }
            }, 5000);
        });

        // 为所有表格添加悬停效果
        const tables = document.querySelectorAll('.table');
        tables.forEach(table => {
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                row.addEventListener('mouseenter', () => {
                    row.style.backgroundColor = '#f8f9fa';
                });
                row.addEventListener('mouseleave', () => {
                    row.style.backgroundColor = '';
                });
            });
        });
        
        // 确保Bootstrap下拉菜单正常工作
        // 不再添加自定义事件监听器，让Bootstrap自行处理
    });

    // 侧边栏切换功能初始化
    function initSidebarToggle() {
        const toggleBtn = document.getElementById('sidebarToggleBtn');
        const body = document.body;
        
        if (!toggleBtn) return;
        
        // 从localStorage恢复侧边栏状态
        const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        if (isCollapsed) {
            body.classList.add('sidebar-collapsed');
        }
        
        // 为侧边栏链接添加tooltip属性
        addTooltipsToSidebarLinks();
        
        // 使用Bootstrap原生事件监听器
        toggleBtn.addEventListener('click', function(e) {
            const wasCollapsed = body.classList.contains('sidebar-collapsed');
            
            if (wasCollapsed) {
                expandSidebar();
            } else {
                collapseSidebar();
            }
            
            // 保存状态到localStorage
            localStorage.setItem('sidebarCollapsed', !wasCollapsed);
        });
        
        // 键盘快捷键支持（Ctrl + B）
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'b') {
                toggleBtn.click();
            }
        });
    }
    
    // 展开侧边栏
    function expandSidebar() {
        const body = document.body;
        const toggleBtn = document.getElementById('sidebarToggleBtn');
        
        body.classList.remove('sidebar-collapsed');
        
        // 更新按钮文本和图标
        if (toggleBtn) {
            toggleBtn.title = '收起侧边栏';
            const icon = toggleBtn.querySelector('i');
            if (icon) {
                icon.className = 'bi bi-chevron-left';
            }
        }
        
        // 触发自定义事件
        window.dispatchEvent(new CustomEvent('sidebarExpanded'));
    }
    
    // 收起侧边栏
    function collapseSidebar() {
        const body = document.body;
        const toggleBtn = document.getElementById('sidebarToggleBtn');
        
        body.classList.add('sidebar-collapsed');
        
        // 更新按钮文本和图标
        if (toggleBtn) {
            toggleBtn.title = '展开侧边栏';
            const icon = toggleBtn.querySelector('i');
            if (icon) {
                icon.className = 'bi bi-chevron-right';
            }
        }
        
        // 触发自定义事件
        window.dispatchEvent(new CustomEvent('sidebarCollapsed'));
    }
    
    // 为侧边栏链接添加tooltip属性
    function addTooltipsToSidebarLinks() {
        const sidebarLinks = document.querySelectorAll('.sidebar .nav-link');
        sidebarLinks.forEach(link => {
            const textContent = link.textContent.trim();
            if (textContent) {
                link.setAttribute('data-tooltip', textContent);
            }
        });
    }

    // 通用工具函数
    function showToast(message, type = 'success') {
        const toast = document.createElement('div');
        toast.className = `position-fixed top-0 start-50 translate-middle-x mt-3 alert alert-${type} alert-dismissible fade show shadow-lg`;
        toast.style.zIndex = '9999';
        toast.innerHTML = `
            <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}-fill me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            if (toast.parentNode) {
                toast.remove();
            }
        }, 3000);
    }

    // 复制到剪贴板功能
    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(() => {
            showToast('已复制到剪贴板');
        }).catch(err => {
            console.error('复制失败:', err);
            showToast('复制失败，请手动复制', 'danger');
        });
    }
    </script>
</body>
</html>