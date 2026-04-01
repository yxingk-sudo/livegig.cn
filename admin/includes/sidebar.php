<?php
// 确保page_functions.php被包含
if (!function_exists('getCurrentPage')) {
    // 使用绝对路径或更可靠的相对路径包含page_functions.php
    $page_functions_path = __DIR__ . '/../page_functions.php';
    if (file_exists($page_functions_path)) {
        require_once $page_functions_path;
    } else {
        // 如果文件不存在，定义一个默认的getCurrentPage函数
        function getCurrentPage() {
            return basename($_SERVER['PHP_SELF']);
        }
    }
}

$current_page = getCurrentPage();
?>

<!-- 固定侧边栏样式 -->
<style>
/* 固定侧边栏容器 */
.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 250px;
    height: 100vh;
    background-color: #f8f9fa;
    border-right: 1px solid #dee2e6;
    z-index: 1000;
    overflow-y: auto;
    box-shadow: 2px 0 5px rgba(0,0,0,0.1);
    transform: translateX(0);
    transition: transform 0.3s ease-in-out;
}

/* 主内容区域偏移 */
.main-content {
    margin-left: 260px;
    min-height: 100vh;
}

/* 侧边栏菜单交互样式 */
.sidebar-menu, .sidebar .nav-link {
    transition: all 0.3s ease;
    border-radius: 8px;
    margin-bottom: 4px;
    font-weight: 500 !important;
    padding: 10px 15px !important;
    display: block;
    color: #212529;
    text-decoration: none;
}

.sidebar-menu:hover, .sidebar .nav-link:hover {
    background-color: #e3f2fd !important;
    color: #1976d2 !important;
    transform: translateX(5px);
    box-shadow: 0 2px 8px rgba(25, 118, 210, 0.15);
}

.sidebar-menu.active, .sidebar .nav-link.active {
    background-color: #1976d2 !important;
    color: white !important;
    box-shadow: 0 2px 12px rgba(25, 118, 210, 0.3);
}

.sidebar-menu.active:hover, .sidebar .nav-link.active:hover {
    background-color: #1565c0 !important;
    transform: translateX(3px);
}

.sidebar-menu i, .sidebar .nav-link i {
    transition: transform 0.3s ease;
}

.sidebar-menu:hover i, .sidebar .nav-link:hover i {
    transform: scale(1.1);
}

/* 菜单组标题 */
.menu-group-title {
    padding: 12px 15px;
    font-size: 1rem;
    font-weight: 600;
    color: #495057;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-radius: 8px;
    transition: all 0.3s ease;
    background-color: #e9ecef;
    margin: 8px 0 4px 0;
    border-left: 4px solid #1976d2;
}

.menu-group-title:hover {
    background-color: #dde0e3;
    color: #1976d2;
}

.menu-group-title i {
    transition: transform 0.3s ease;
    font-size: 0.8rem;
}

.menu-group-title.collapsed i {
    transform: rotate(-90deg);
}

/* 菜单组容器 */
.menu-group {
    transition: all 0.3s ease;
    padding: 0 0 0 10px;
}

.menu-group.collapsed {
    display: none;
}

/* 响应式设计 - 移动端汉堡菜单按钮 */
@media (max-width: 991.98px) {
    .sidebar {
        transform: translateX(-100%);
    }
    
    .sidebar.active {
        transform: translateX(0);
    }
    
    .main-content {
        margin-left: 0;
    }
    
    .sidebar-menu:hover, .sidebar .nav-link:hover {
        transform: none;
    }
    
    /* 汉堡菜单按钮 */
    .menu-toggle {
        position: fixed;
        top: 15px;
        left: 15px;
        z-index: 1001;
        display: block;
        background: #1976d2;
        color: white;
        border: none;
        border-radius: 5px;
        padding: 10px 15px;
        font-size: 1.2rem;
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        cursor: pointer;
    }
    
    .menu-toggle:focus {
        outline: none;
        box-shadow: 0 0 0 0.2rem rgba(25, 118, 210, 0.25);
    }
}

/* 大屏幕设备保持原有样式 */
@media (min-width: 992px) {
    .menu-toggle {
        display: none;
    }
}

/* 滚动条样式优化 */
.sidebar::-webkit-scrollbar {
    width: 6px;
}

.sidebar::-webkit-scrollbar-track {
    background: #f1f1f1;
}

.sidebar::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

.sidebar::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}
</style>

<!-- 汉堡菜单按钮 (仅在移动端显示) -->
<button class="menu-toggle d-lg-none" type="button" id="menuToggle" title="切换侧边栏菜单" aria-label="切换菜单">
    <i class="bi bi-list"></i>
</button>

<!-- 固定侧边栏 -->
<div class="sidebar" id="sidebar">
    <div class="mb-4 p-3">
        <h5 class="text-primary mb-0">
            <i class="bi bi-grid-3x3-gap-fill"></i>
            <span>管理后台</span>
        </h5>
        <small class="text-muted"><span>项目管理系统</span></small>
    </div>
    
    <ul class="nav nav-pills flex-column mb-auto px-2">
        <li class="nav-item mb-1">
            <a href="index.php" class="nav-link sidebar-menu <?php echo $current_page == 'index.php' ? 'active' : 'text-dark'; ?>">
                <i class="bi bi-speedometer2 me-2"></i><span>控制台</span>
            </a>
        </li>
        
        <!-- 项目组 -->
        <li class="menu-group-title" data-target="project-menu">
            <span><i class="bi bi-folder me-2"></i>项目</span>
            <i class="bi bi-chevron-down"></i>
        </li>
        <div class="menu-group" id="project-menu">
            <li class="nav-item mb-1">
                <a href="companies.php" class="nav-link sidebar-menu <?php echo $current_page == 'companies.php' ? 'active' : 'text-dark'; ?>">
                    <i class="bi bi-building me-2"></i><span>公司管理</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a href="projects.php" class="nav-link sidebar-menu <?php echo $current_page == 'projects.php' ? 'active' : 'text-dark'; ?>">
                    <i class="bi bi-folder me-2"></i><span>项目管理</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a href="personnel_enhanced.php" class="nav-link sidebar-menu <?php echo $current_page == 'personnel_enhanced.php' ? 'active' : 'text-dark'; ?>">
                    <i class="bi bi-people me-2"></i><span>人员管理</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a href="personnel_statistics.php" class="nav-link sidebar-menu <?php echo $current_page == 'personnel_statistics.php' ? 'active' : 'text-dark'; ?>">
                    <i class="bi bi-bar-chart me-2"></i><span>人员统计</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a href="departments_enhanced.php" class="nav-link sidebar-menu <?php echo $current_page == 'departments_enhanced.php' ? 'active' : 'text-dark'; ?>">
                    <i class="bi bi-diagram-3 me-2"></i><span>部门管理</span>
                </a>
            </li>
        </div>
        
        <!-- 用餐组 -->
        <li class="menu-group-title" data-target="meal-menu">
            <span><i class="bi bi-cup-hot me-2"></i>用餐</span>
            <i class="bi bi-chevron-down"></i>
        </li>
        <div class="menu-group" id="meal-menu">
            <li class="nav-item mb-1">
                <a href="meal_reports.php" class="nav-link sidebar-menu <?php echo $current_page == 'meal_reports.php' ? 'active' : 'text-dark'; ?>">
                    <i class="bi bi-cup-hot me-2"></i><span>用餐管理</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a href="meal_packages.php" class="nav-link sidebar-menu <?php echo $current_page == 'meal_packages.php' ? 'active' : 'text-dark'; ?>">
                    <i class="bi bi-box-seam me-2"></i><span>套餐管理</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a href="meal_statistics.php" class="nav-link sidebar-menu <?php echo $current_page == 'meal_statistics.php' ? 'active' : 'text-dark'; ?>">
                    <i class="bi bi-bar-chart me-2"></i><span>用餐统计</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a href="meal_allowance.php" class="nav-link sidebar-menu <?php echo $current_page == 'meal_allowance.php' ? 'active' : 'text-dark'; ?>">
                    <i class="bi bi-cash me-2"></i><span>餐费补助明细</span>
                </a>
            </li>
        </div>
        
        <!-- 酒店组 -->
        <li class="menu-group-title" data-target="hotel-menu">
            <span><i class="bi bi-building me-2"></i>酒店</span>
            <i class="bi bi-chevron-down"></i>
        </li>
        <div class="menu-group" id="hotel-menu">
            <li class="nav-item mb-1">
                <a href="hotel_management.php" class="nav-link sidebar-menu <?php echo $current_page == 'hotel_management.php' ? 'active' : 'text-dark'; ?>">
                    <i class="bi bi-building me-2"></i><span>酒店管理</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a href="hotel_reports_new.php" class="nav-link sidebar-menu <?php echo $current_page == 'hotel_reports_new.php' ? 'active' : 'text-dark'; ?>">
                    <i class="bi bi-calendar-check me-2"></i><span>酒店预订管理</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a href="hotel_statistics_admin.php" class="nav-link sidebar-menu <?php echo $current_page == 'hotel_statistics_admin.php' ? 'active' : 'text-dark'; ?>">
                    <i class="bi bi-bar-chart me-2"></i><span>酒店统计管理</span>
                </a>
            </li>
        </div>
        
        <!-- 交通组 -->
        <li class="menu-group-title" data-target="transport-menu">
            <span><i class="bi bi-car-front me-2"></i>交通</span>
            <i class="bi bi-chevron-down"></i>
        </li>
        <div class="menu-group" id="transport-menu">
            <li class="nav-item mb-1">
                <a href="transportation_reports.php" class="nav-link sidebar-menu <?php echo $current_page == 'transportation_reports.php' ? 'active' : 'text-dark'; ?>">
                    <i class="bi bi-car-front me-2"></i><span>交通管理</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a href="transportation_statistics.php" class="nav-link sidebar-menu <?php echo $current_page == 'transportation_statistics.php' ? 'active' : 'text-dark'; ?>">
                    <i class="bi bi-bar-chart-line me-2"></i><span>交通统计</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a href="fleet_management.php" class="nav-link sidebar-menu <?php echo $current_page == 'fleet_management.php' ? 'active' : 'text-dark'; ?>">
                    <i class="bi bi-truck me-2"></i><span>车队管理</span>
                </a>
            </li>
        </div>
        
        <!-- 系统配置组 -->
        <li class="menu-group-title" data-target="system-menu">
            <span><i class="bi bi-gear me-2"></i>系统配置</span>
            <i class="bi bi-chevron-down"></i>
        </li>
        <div class="menu-group" id="system-menu">
            <li class="nav-item mb-1">
                <a href="admin_management.php" class="nav-link sidebar-menu <?php echo $current_page == 'admin_management.php' ? 'active' : 'text-dark'; ?>">
                    <i class="bi bi-people me-2"></i><span>管理员管理</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a href="permission_management_enhanced.php" class="nav-link sidebar-menu <?php echo $current_page == 'permission_management_enhanced.php' ? 'active' : 'text-dark'; ?>">
                    <i class="bi bi-shield-fill-check me-2"></i><span>增强权限管理</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a href="project_access.php" class="nav-link sidebar-menu <?php echo $current_page == 'project_access.php' ? 'active' : 'text-dark'; ?>">
                    <i class="bi bi-key me-2"></i><span>项目访问管理</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a href="site_config.php" class="nav-link sidebar-menu <?php echo $current_page == 'site_config.php' ? 'active' : 'text-dark'; ?>">
                    <i class="bi bi-gear me-2"></i><span>网站配置</span>
                </a>
            </li>
            <li class="nav-item mb-1">
                <a href="backup_management.php" class="nav-link sidebar-menu <?php echo $current_page == 'backup_management.php' ? 'active' : 'text-dark'; ?>">
                    <i class="bi bi-cloud-arrow-up me-2"></i><span>备份管理</span>
                </a>
            </li>
        </div>
    </ul>
</div>

<script>
// 移动端菜单切换功能
document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    
    // 确保元素存在
    if (menuToggle && sidebar) {
        // 移除可能已存在的事件监听器，避免重复绑定
        const newMenuToggle = menuToggle.cloneNode(true);
        menuToggle.parentNode.replaceChild(newMenuToggle, menuToggle);
        
        // 使用Bootstrap原生事件监听器
        newMenuToggle.addEventListener('click', function(e) {
            sidebar.classList.toggle('active');
        });
        
        // 点击菜单项后自动隐藏侧边栏
        const menuItems = sidebar.querySelectorAll('.sidebar-menu');
        menuItems.forEach(function(item) {
            item.addEventListener('click', function() {
                sidebar.classList.remove('active');
            });
        });
        
        // 点击侧边栏外部区域隐藏侧边栏
        document.addEventListener('click', function(e) {
            // 检查点击是否在侧边栏或菜单按钮之外
            if (sidebar.classList.contains('active') && 
                !sidebar.contains(e.target) && 
                e.target !== newMenuToggle &&
                !newMenuToggle.contains(e.target)) {
                sidebar.classList.remove('active');
            }
        });
    } else {
        console.log('未找到菜单按钮或侧边栏元素');
    }
    
    // 菜单组折叠功能
    const menuGroupTitles = document.querySelectorAll('.menu-group-title');
    menuGroupTitles.forEach(function(title) {
        title.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const targetMenu = document.getElementById(targetId);
            const icon = this.querySelector('i.bi-chevron-down');
            
            if (targetMenu && icon) {
                targetMenu.classList.toggle('collapsed');
                this.classList.toggle('collapsed');
            }
        });
    });
    
    // 默认展开当前页面所在的菜单组
    const activeMenu = document.querySelector('.sidebar-menu.active');
    if (activeMenu) {
        // 找到包含当前活动菜单的菜单组
        let parent = activeMenu.parentElement;
        while (parent && !parent.classList.contains('menu-group')) {
            parent = parent.parentElement;
        }
        
        if (parent && parent.id) {
            // 展开对应的菜单组
            parent.classList.remove('collapsed');
            
            // 更新对应的标题样式
            const title = document.querySelector(`.menu-group-title[data-target="${parent.id}"]`);
            if (title) {
                title.classList.remove('collapsed');
            }
        }
    }
});
</script>