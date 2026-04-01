<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

session_start();

// 检查是否为管理员
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

// 获取网站配置
$database = new Database();
$db_config = $database->getConnection();
$site_config = [];
try {
    $query = "SELECT config_key, config_value FROM site_config WHERE config_key = 'site_url'";
    $stmt = $db_config->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $site_url = $result ? $result['config_value'] : 'http://localhost';
} catch (Exception $e) {
    $site_url = 'http://localhost'; // 默认值
}

// 获取所有项目
$query = "SELECT p.*, c.name as company_name, 
          COUNT(pu.id) as user_count,
          COUNT(CASE WHEN pu.is_active = 1 THEN 1 END) as active_user_count,
          GROUP_CONCAT(DISTINCT CONCAT(pu.username, ' (', pu.display_name, CASE WHEN pu.is_active = 1 THEN '' ELSE ' [禁用]' END, ')') SEPARATOR ', ') as users
          FROM projects p 
          JOIN companies c ON p.company_id = c.id 
          LEFT JOIN project_users pu ON p.id = pu.project_id
          WHERE p.status = 'active'
          GROUP BY p.id
          ORDER BY p.created_at DESC";
$stmt = $db->prepare($query);
$stmt->execute();
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<?php include 'includes/header.php'; ?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12 ms-sm-auto px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center py-4 border-bottom">
                <div>
                    <h1 class="h2 mb-0">
                        <i class="bi bi-key-fill text-primary"></i> 项目访问管理
                    </h1>
                    <p class="text-muted mb-0 mt-1">管理所有项目的独立访问权限</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="projects.php" class="btn btn-outline-primary">
                        <i class="bi bi-plus-lg"></i> 新建项目
                    </a>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom-0 pb-0">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-list-ul text-primary"></i> 项目访问信息总览
                        </h5>
                        <span class="badge bg-light text-dark">
                            <?php echo count($projects); ?> 个项目
                        </span>
                    </div>
                </div>
                <div class="card-body pt-3">
                    
                    <?php if (empty($projects)): ?>
                        <div class="text-center py-5">
                            <div class="mb-4">
                                <i class="bi bi-inbox display-1 text-muted opacity-50"></i>
                            </div>
                            <h5 class="text-muted mb-2">暂无活跃项目</h5>
                            <p class="text-muted mb-4">请先创建项目，然后为项目添加访问用户</p>
                            <a href="projects.php" class="btn btn-primary">
                                <i class="bi bi-plus-lg"></i> 创建新项目
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="border-0">项目信息</th>
                                        <th class="border-0">访问地址</th>
                                        <th class="border-0">用户账户</th>
                                        <th class="border-0 text-end">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($projects as $index => $project): ?>
                                        <tr class="border-bottom">
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="bg-primary bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                                                        <i class="bi bi-folder2-open text-primary"></i>
                                                    </div>
                                                    <div>
                                                        <div class="fw-semibold"><?php echo htmlspecialchars($project['name']); ?></div>
                                                        <div class="text-muted small"><?php echo htmlspecialchars($project['company_name']); ?></div>
                                                        <div class="text-muted small">代码: <code class="bg-light px-1 rounded"><?php echo htmlspecialchars($project['code']); ?></code></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="position-relative">
                                                    <code class="bg-light text-primary p-2 rounded d-block small" style="font-size: 0.75rem;">
                                                        <?php echo rtrim($site_url, '/'); ?>/user/project_login.php?code=<?php echo htmlspecialchars($project['code']); ?>
                                                    </code>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($project['user_count'] > 0): ?>
                                                    <div class="d-flex align-items-center">
                                                        <span class="badge bg-success-subtle text-success me-2">
                                                            <i class="bi bi-people-fill"></i> 
                                                            <?php echo $project['active_user_count'] . '/' . $project['user_count']; ?>
                                                        </span>
                                                        <div class="text-truncate" style="max-width: 200px;">
                                                            <small class="text-muted"><?php echo htmlspecialchars($project['users']); ?></small>
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="badge bg-warning-subtle text-warning">
                                                        <i class="bi bi-exclamation-circle"></i> 无用户
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <button class="btn btn-outline-primary btn-sm" onclick="copyUrl('<?php echo htmlspecialchars($project['code']); ?>')" title="复制访问地址">
                                                        <i class="bi bi-clipboard"></i>
                                                    </button>
                                                    <a href="project_users.php?project_id=<?php echo $project['id']; ?>" class="btn btn-outline-success btn-sm" title="管理用户">
                                                        <i class="bi bi-gear"></i>
                                                    </a>
                                                    <a href="<?php echo rtrim($site_url, '/'); ?>/user/project_login.php?code=<?php echo htmlspecialchars($project['code']); ?>" class="btn btn-outline-info btn-sm" title="访问项目" target="_blank">
                                                <i class="bi bi-box-arrow-up-right"></i>
                                            </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 快速添加用户 -->
            <div class="card border-0 shadow-sm mt-4">
                <div class="card-header bg-white border-bottom-0 pb-0">
                    <h5 class="mb-0">
                        <i class="bi bi-lightning-charge-fill text-warning"></i> 快速添加项目用户
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php foreach ($projects as $project): ?>
                            <div class="col-md-4">
                                <div class="card border h-100 hover-shadow-sm transition-all">
                                    <div class="card-header bg-light border-0 py-2">
                                        <div class="d-flex align-items-center">
                                            <div class="bg-primary bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 28px; height: 28px;">
                                                <i class="bi bi-folder2-open text-primary" style="font-size: 0.875rem;"></i>
                                            </div>
                                            <div>
                                                <h6 class="mb-0 small fw-semibold"><?php echo htmlspecialchars($project['name']); ?></h6>
                                                <small class="text-muted"><?php echo htmlspecialchars($project['company_name']); ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card-body py-2">
                                        <div class="mb-2">
                                            <small class="text-muted d-block">项目代码</small>
                                            <code class="bg-light text-primary px-2 py-1 rounded small d-block"><?php echo htmlspecialchars($project['code']); ?></code>
                                        </div>
                                        <div class="mb-3">
                                            <small class="text-muted d-block">当前用户</small>
                                            <span class="badge bg-<?php echo $project['active_user_count'] > 0 ? 'success-subtle text-success' : 'warning-subtle text-warning'; ?> border">
                                                <i class="bi bi-<?php echo $project['active_user_count'] > 0 ? 'people-fill' : 'person-x'; ?> me-1"></i>
                                                <?php echo $project['active_user_count'] . '/' . $project['user_count']; ?> 个
                                            </span>
                                        </div>
                                        <div class="d-grid gap-1">
                                            <a href="project_users.php?project_id=<?php echo $project['id']; ?>" class="btn btn-primary btn-sm">
                                                <i class="bi bi-plus-lg"></i> 添加用户
                                            </a>
                                            <button class="btn btn-outline-secondary btn-sm" onclick="copyUrl('<?php echo htmlspecialchars($project['code']); ?>')" title="复制访问地址">
                                                <i class="bi bi-clipboard"></i> 复制地址
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- 使用说明 -->
            <div class="card mt-4">
                <div class="card-header">
                    <h5 class="mb-0">使用说明</h5>
                </div>
                <div class="card-body">
                    <h6>如何为项目创建独立登录：</h6>
                    <ol>
                        <li>确保项目已创建且状态为"活跃"</li>
                        <li>在项目访问管理页面找到对应项目</li>
                        <li>点击"管理用户"为项目添加用户账户</li>
                        <li>系统会自动生成随机密码，管理员可查看或重置</li>
                        <li>复制项目访问地址发送给用户</li>
                        <li>用户可通过该地址直接登录对应项目</li>
                    </ol>
                    
                    <h6 class="mt-3">项目登录地址格式：</h6>
                    <p><code>http://localhost/user/project_login.php?code=项目代码</code></p>
                    
                    <h6 class="mt-3">用户权限说明：</h6>
                    <ul>
                        <li><strong>项目管理员</strong>：可以管理项目内的所有数据</li>
                        <li><strong>普通用户</strong>：可以查看和添加报餐、酒店、交通等信息</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyUrl(projectCode) {
        const url = `<?php echo rtrim($site_url, '/'); ?>/user/project_login.php?code=${projectCode}`;
        navigator.clipboard.writeText(url).then(() => {
            // 创建临时提示
            const toast = document.createElement('div');
            toast.className = 'position-fixed top-0 start-50 translate-middle-x mt-3';
            toast.style.zIndex = '9999';
            toast.innerHTML = `
                <div class="alert alert-success alert-dismissible fade show shadow-lg" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    项目访问地址已复制到剪贴板
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            document.body.appendChild(toast);
            
            // 3秒后自动移除
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.remove();
                }
            }, 3000);
        }).catch(err => {
            console.error('复制失败:', err);
            alert('复制失败，请手动复制');
        });
    }

    function copyUrlWithUser(projectCode, username, password) {
        const url = `<?php echo rtrim($site_url, '/'); ?>/user/project_login.php?code=${projectCode}`;
        const text = `项目访问地址：${url}\n用户名：${username}\n密码：${password}`;
        navigator.clipboard.writeText(text).then(() => {
            // 创建临时提示
            const toast = document.createElement('div');
            toast.className = 'position-fixed top-0 start-50 translate-middle-x mt-3';
            toast.style.zIndex = '9999';
            toast.innerHTML = `
                <div class="alert alert-success alert-dismissible fade show shadow-lg" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    项目访问信息已复制到剪贴板
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.remove();
                }
            }, 3000);
        }).catch(err => {
            console.error('复制失败:', err);
            alert('复制失败，请手动复制');
        });
    }

// 页面加载完成后的动画效果
document.addEventListener('DOMContentLoaded', function() {
    // 为表格行添加悬停效果
    const rows = document.querySelectorAll('tbody tr');
    rows.forEach((row, index) => {
        row.style.opacity = '0';
        row.style.transform = 'translateY(10px)';
        row.style.transition = 'all 0.3s ease';
        
        setTimeout(() => {
            row.style.opacity = '1';
            row.style.transform = 'translateY(0)';
        }, index * 50);
    });
});
</script>

<?php include 'includes/footer.php'; ?>