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

$database = new Database();
$db = $database->getConnection();

// 获取人员ID
$personnel_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// 获取人员信息
$person_query = "SELECT id, name, phone, email, id_card, created_at FROM personnel WHERE id = :id";
$person_stmt = $db->prepare($person_query);
$person_stmt->bindParam(':id', $personnel_id);
$person_stmt->execute();
$person = $person_stmt->fetch(PDO::FETCH_ASSOC);

// 检查人员是否存在
if (!$person) {
    die('人员不存在');
}

// 确保所有必要字段都存在，设置默认值
$person = array_merge([
    'id' => 0,
    'name' => '未知用户',
    'phone' => '',
    'email' => '',
    'id_card' => '',
    'created_at' => date('Y-m-d H:i:s')
], $person);

// 获取所有项目
$projects_query = "SELECT p.*, c.name as company_name FROM projects p 
                   JOIN companies c ON p.company_id = c.id 
                   WHERE p.status = 'active' 
                   ORDER BY c.name, p.name";
$projects_stmt = $db->prepare($projects_query);
$projects_stmt->execute();
$all_projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取人员当前的项目访问权限
$current_access_query = "SELECT pu.*, p.name as project_name, p.code as project_code, c.name as company_name 
                         FROM project_users pu 
                         JOIN personnel_project_users ppu ON pu.id = ppu.project_user_id 
                         JOIN projects p ON pu.project_id = p.id 
                         JOIN companies c ON p.company_id = c.id 
                         WHERE ppu.personnel_id = :personnel_id AND pu.is_active = 1";
$current_stmt = $db->prepare($current_access_query);
$current_stmt->bindParam(':personnel_id', $personnel_id);

// 处理表单提交
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'update_access') {
            try {
                $db->beginTransaction();
                
                // 先禁用所有现有权限
                $disable_query = "UPDATE project_users pu 
                                JOIN personnel_project_users ppu ON pu.id = ppu.project_user_id 
                                SET pu.is_active = 0 
                                WHERE ppu.personnel_id = :personnel_id";
                $disable_stmt = $db->prepare($disable_query);
                $disable_stmt->bindParam(':personnel_id', $personnel_id);
                $disable_stmt->execute();
                
                // 添加新的项目访问权限
                if (!empty($_POST['project_access'])) {
                    $accesses = $_POST['project_access'];
                    foreach ($accesses as $project_id => $access_data) {
                        if (isset($access_data['enabled'])) {
                            $project_id = intval($project_id);
                            $username = trim($access_data['username']);
                            $password = trim($access_data['password']);
                            $display_name = trim($access_data['display_name']);
                            $role = $access_data['role'] ?? 'user';
                            
                            if (!empty($username) && !empty($password)) {
                                // 检查是否已存在该用户
                                $check_query = "SELECT id FROM project_users WHERE project_id = :project_id AND username = :username";
                                $check_stmt = $db->prepare($check_query);
                                $check_stmt->bindParam(':project_id', $project_id);
                                $check_stmt->bindParam(':username', $username);
                                $check_stmt->execute();
                                $existing_user = $check_stmt->fetch(PDO::FETCH_ASSOC);
                                
                                if ($existing_user) {
                                    // 更新现有用户
                                    $user_id = $existing_user['id'];
                                    $update_query = "UPDATE project_users SET 
                                                   password = :password, display_name = :display_name, 
                                                   role = :role, is_active = 1 
                                                   WHERE project_id = :project_id AND username = :username";
                                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                                    $update_stmt = $db->prepare($update_query);
                                    $update_stmt->bindParam(':password', $hashed_password);
                                    $update_stmt->bindParam(':display_name', $display_name);
                                    $update_stmt->bindParam(':role', $role);
                                    $update_stmt->bindParam(':project_id', $project_id);
                                    $update_stmt->bindParam(':username', $username);
                                    $update_stmt->execute();
                                    
                                    // 同步更新前台权限角色（与project_users.php保持一致）
                                    try {
                                        // 映射项目角色到前台权限系统：admin→前台管理员(user_admin)，user→前台用户(user)
                                        $frontend_role_key = ($role == 'admin') ? 'user_admin' : 'user';
                                        
                                        // 先删除旧的角色关联
                                        $delete_role_query = "DELETE FROM user_roles WHERE user_id = :user_id AND project_id = :project_id";
                                        $delete_role_stmt = $db->prepare($delete_role_query);
                                        $delete_role_stmt->bindParam(':user_id', $user_id);
                                        $delete_role_stmt->bindParam(':project_id', $project_id);
                                        $delete_role_stmt->execute();
                                        
                                        // 获取前台权限角色ID
                                        $role_query = "SELECT id FROM roles WHERE role_key = :role_key AND role_type = 'frontend'";
                                        $role_stmt = $db->prepare($role_query);
                                        $role_stmt->bindParam(':role_key', $frontend_role_key);
                                        $role_stmt->execute();
                                        $role_result = $role_stmt->fetch(PDO::FETCH_ASSOC);
                                        
                                        if ($role_result) {
                                            $role_id = $role_result['id'];
                                            // 插入新的用户角色关联
                                            $insert_role_query = "INSERT INTO user_roles (user_id, role_id, project_id) VALUES (:user_id, :role_id, :project_id)";
                                            $insert_role_stmt = $db->prepare($insert_role_query);
                                            $insert_role_stmt->bindParam(':user_id', $user_id);
                                            $insert_role_stmt->bindParam(':role_id', $role_id);
                                            $insert_role_stmt->bindParam(':project_id', $project_id);
                                            $insert_role_stmt->execute();
                                        }
                                    } catch (Exception $e) {
                                        // 记录错误但不影响用户更新
                                        error_log("前台角色同步失败: " . $e->getMessage());
                                    }
                                } else {
                                    // 创建新用户
                                    $insert_query = "INSERT INTO project_users 
                                                   (project_id, username, password, display_name, role, is_active) 
                                                   VALUES (:project_id, :username, :password, :display_name, :role, 1)";
                                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                                    $insert_stmt = $db->prepare($insert_query);
                                    $insert_stmt->bindParam(':project_id', $project_id);
                                    $insert_stmt->bindParam(':username', $username);
                                    $insert_stmt->bindParam(':password', $hashed_password);
                                    $insert_stmt->bindParam(':display_name', $display_name);
                                    $insert_stmt->bindParam(':role', $role);
                                    $insert_stmt->execute();
                                    
                                    // 获取新创建用户的ID并建立关联
                                    $project_user_id = $db->lastInsertId();
                                    $link_query = "INSERT INTO personnel_project_users (personnel_id, project_user_id) 
                                                   VALUES (:personnel_id, :project_user_id)";
                                    $link_stmt = $db->prepare($link_query);
                                    $link_stmt->bindParam(':personnel_id', $personnel_id);
                                    $link_stmt->bindParam(':project_user_id', $project_user_id);
                                    $link_stmt->execute();
                                    
                                    // 同步创建前台权限角色（与project_users.php保持一致）
                                    try {
                                        // 映射项目角色到前台权限系统：admin→前台管理员(user_admin)，user→前台用户(user)
                                        $frontend_role_key = ($role == 'admin') ? 'user_admin' : 'user';
                                        
                                        // 获取前台权限角色ID
                                        $role_query = "SELECT id FROM roles WHERE role_key = :role_key AND role_type = 'frontend'";
                                        $role_stmt = $db->prepare($role_query);
                                        $role_stmt->bindParam(':role_key', $frontend_role_key);
                                        $role_stmt->execute();
                                        $role_result = $role_stmt->fetch(PDO::FETCH_ASSOC);
                                        
                                        if ($role_result) {
                                            $role_id = $role_result['id'];
                                            // 插入用户角色关联
                                            $insert_role_query = "INSERT INTO user_roles (user_id, role_id, project_id) VALUES (:user_id, :role_id, :project_id)";
                                            $insert_role_stmt = $db->prepare($insert_role_query);
                                            $insert_role_stmt->bindParam(':user_id', $project_user_id);
                                            $insert_role_stmt->bindParam(':role_id', $role_id);
                                            $insert_role_stmt->bindParam(':project_id', $project_id);
                                            $insert_role_stmt->execute();
                                        }
                                    } catch (Exception $e) {
                                        // 记录错误但不影响用户创建
                                        error_log("前台角色分配失败: " . $e->getMessage());
                                    }
                                }
                            }
                        }
                    }
                }
                
                $db->commit();
                $message = '项目访问权限更新成功！';
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = '更新失败：' . $e->getMessage();
            }
        }
    }
}

// 重新获取当前权限（在处理完表单后也需要获取）
$current_stmt->execute();
$current_access = $current_stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取人员关联的项目信息
$person_projects_query = "SELECT DISTINCT p.id, p.name, p.code, c.name as company_name 
                         FROM projects p 
                         JOIN companies c ON p.company_id = c.id 
                         JOIN project_department_personnel pdp ON p.id = pdp.project_id 
                         WHERE pdp.personnel_id = :personnel_id AND p.status = 'active'";
$person_projects_stmt = $db->prepare($person_projects_query);
$person_projects_stmt->bindParam(':personnel_id', $personnel_id);
$person_projects_stmt->execute();
$person_projects = $person_projects_stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<?php
// 页面标题
$page_title = '项目访问管理';

// 引入header
include 'includes/header.php';
?>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="h2 mb-1">
                            <i class="bi bi-key-fill text-primary"></i> 项目访问管理
                        </h1>
                            <p class="text-muted mb-0">为 <strong><?php echo htmlspecialchars($person['name']); ?></strong> 配置项目访问权限</p>
                            <div class="text-sm text-muted mt-1">
                                <?php if (!empty($person['phone'])): ?>
                                <span class="me-3"><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($person['phone']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($person['email'])): ?>
                                <span><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($person['email']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div>
                            <?php if (!empty($person_projects)): ?>
                                <?php if (count($person_projects) == 1): ?>
                                    <!-- 只有一个项目时，直接返回该项目 -->
                                    <a href="personnel_enhanced.php?project_id=<?php echo $person_projects[0]['id']; ?>" class="btn btn-outline-secondary">
                                        <i class="bi bi-arrow-left"></i> 返回人员列表
                                    </a>
                                <?php else: ?>
                                    <!-- 有多个项目时，提供下拉选择 -->
                                    <div class="btn-group">
                                        <button type="button" class="btn btn-outline-secondary dropdown-toggle" id="backToProjectBtn">
                                            <i class="bi bi-arrow-left"></i> 返回人员列表
                                        </button>
                                        <ul class="dropdown-menu" id="backToProjectDropdown">
                                            <?php foreach ($person_projects as $project): ?>
                                                <li><a class="dropdown-item" href="personnel_enhanced.php?project_id=<?php echo $project['id']; ?>">
                                                    <?php echo htmlspecialchars($project['name']); ?>
                                                </a></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                    
                                    <script>
                                        // 智能下拉菜单处理逻辑，避免超出屏幕范围
                                        document.addEventListener('DOMContentLoaded', function() {
                                            const btn = document.getElementById('backToProjectBtn');
                                            const dropdown = document.getElementById('backToProjectDropdown');
                                              
                                            if (btn && dropdown) {
                                                // 默认隐藏下拉菜单
                                                dropdown.style.display = 'none';
                                                dropdown.style.position = 'absolute';
                                                dropdown.style.zIndex = '1000';
                                                dropdown.style.left = '0';
                                                dropdown.style.marginTop = '0.125rem';
                                                dropdown.style.backgroundColor = 'white';
                                                dropdown.style.border = '1px solid rgba(0,0,0,.15)';
                                                dropdown.style.borderRadius = '0.25rem';
                                                dropdown.style.boxShadow = '0 0.5rem 1rem rgba(0,0,0,.175)';
                                                dropdown.style.padding = '0.5rem 0';
                                                dropdown.style.minWidth = '10rem';
                                                dropdown.style.listStyle = 'none';
                                                dropdown.style.textAlign = 'right';
                                                dropdown.style.fontSize = '12px';
                                                // 设置最大高度和溢出滚动
                                                dropdown.style.maxHeight = '300px';
                                                dropdown.style.overflowY = 'auto';

                                                // 智能定位函数：根据屏幕位置调整下拉菜单的显示方向
                                                function positionDropdown() {
                                                    const btnRect = btn.getBoundingClientRect();
                                                    const windowHeight = window.innerHeight;
                                                    const dropdownHeight = Math.min(300, 20 * 30); // 估算高度，20个项目每个30px
                                                     
                                                    // 如果下方空间不足，就向上显示
                                                    if (btnRect.bottom + dropdownHeight > windowHeight) {
                                                        dropdown.style.top = 'auto';
                                                        dropdown.style.bottom = '100%';
                                                        dropdown.style.marginTop = '0';
                                                        dropdown.style.marginBottom = '0.125rem';
                                                    } else {
                                                        // 否则向下显示
                                                        dropdown.style.top = '100%';
                                                        dropdown.style.bottom = 'auto';
                                                        dropdown.style.marginTop = '0.125rem';
                                                        dropdown.style.marginBottom = '0';
                                                    }
                                                }

                                                // 点击按钮切换下拉菜单显示/隐藏
                                                btn.addEventListener('click', function(e) {
                                                    e.stopPropagation();
                                                    
                                                    if (dropdown.style.display === 'block') {
                                                        dropdown.style.display = 'none';
                                                    } else {
                                                        // 显示前重新计算位置
                                                        positionDropdown();
                                                        dropdown.style.display = 'block';
                                                    }
                                                });

                                                // 窗口大小改变时重新计算位置
                                                window.addEventListener('resize', function() {
                                                    if (dropdown.style.display === 'block') {
                                                        positionDropdown();
                                                    }
                                                });
                                                
                                                // 点击页面其他地方关闭下拉菜单
                                                document.addEventListener('click', function() {
                                                    dropdown.style.display = 'none';
                                                });
                                                
                                                // 防止点击下拉菜单内部时关闭
                                                dropdown.addEventListener('click', function(e) {
                                                    e.stopPropagation();
                                                });
                                            }
                                        });
                                    </script>
                                <?php endif; ?>
                            <?php else: ?>
                                <!-- 没有关联项目时，返回普通人员列表 -->
                                <a href="personnel_enhanced.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-left"></i> 返回人员列表
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($message): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="bi bi-check-circle"></i> <?php echo $message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- 当前访问权限 -->
                    <?php if (!empty($current_access)): ?>
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-shield-check"></i> 当前项目访问权限</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <?php foreach ($current_access as $access): ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="border rounded p-3 bg-light">
                                                <h6 class="text-primary mb-2">
                                                    <i class="bi bi-folder2-open"></i> <?php echo htmlspecialchars($access['project_name']); ?>
                                                </h6>
                                                <p class="mb-1"><strong>用户名:</strong> <?php echo htmlspecialchars($access['username']); ?></p>
                                                <p class="mb-1"><strong>显示名:</strong> <?php echo htmlspecialchars($access['display_name']); ?></p>
                                                <p class="mb-1"><strong>角色:</strong> 
                                                    <span class="badge bg-<?php echo $access['role'] === 'admin' ? 'danger' : 'info'; ?>">
                                                        <?php echo $access['role'] === 'admin' ? '前台管理员' : '前台用户'; ?>
                                                    </span>
                                                </p>
                                                <p class="mb-0"><strong>访问地址:</strong><br>
                                                    <code class="small"><?php echo rtrim($site_url, '/'); ?>/user/project_login.php?code=<?php echo htmlspecialchars($access['project_code']); ?></code>
                                                </p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- 配置项目访问权限 -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-gear"></i> 配置项目访问权限</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="action" value="update_access">
                                
                                <div class="mb-3">
                                    <h6>关联的项目（基于人员当前的项目分配）</h6>
                                    <?php if (!empty($person_projects)): ?>
                                        <div class="row">
                                            <?php foreach ($person_projects as $project): ?>
                                                <div class="col-md-6 mb-3">
                                                    <div class="project-card p-3">
                                                        <div class="form-check mb-2">
                                                            <input class="form-check-input" type="checkbox" 
                                                                   name="project_access[<?php echo $project['id']; ?>][enabled]" 
                                                                   id="project_<?php echo $project['id']; ?>" 
                                                                   value="1"
                                                                   <?php echo in_array($project['id'], array_column($current_access, 'project_id')) ? 'checked' : ''; ?>>
                                                            <label class="form-check-label fw-bold" for="project_<?php echo $project['id']; ?>">
                                                                <i class="bi bi-folder2-open text-primary"></i> 
                                                                <?php echo htmlspecialchars($project['name']); ?>
                                                                <br><small class="text-muted"><?php echo htmlspecialchars($project['company_name']); ?></small>
                                                            </label>
                                                        </div>
                                                        
                                                        <div class="row">
                                                            <div class="col-md-6 mb-2">
                                                                <label class="form-label small">用户名</label>
                                                                <input type="text" class="form-control form-control-sm" 
                                                                       name="project_access[<?php echo $project['id']; ?>][username]" 
                                                                       value="<?php 
                                                                           // 查找该项目已保存的用户名
                                                                           $saved_username = '';
                                                                           foreach ($current_access as $access_item) {
                                                                               if ($access_item['project_id'] == $project['id']) {
                                                                                   $saved_username = $access_item['username'];
                                                                                   break;
                                                                               }
                                                                           }
                                                                           // 如果有已保存的用户名则显示它，否则显示生成的用户名
                                                                           if (!empty($saved_username)) {
                                                                               echo htmlspecialchars($saved_username);
                                                                           } else {
                                                                               echo htmlspecialchars(strtolower($person['name']) . '_' . $project['code']);
                                                                           }
                                                                       ?>"
                                                                       placeholder="用户名">
                                                            </div>
                                                            <div class="col-md-6 mb-2">
                                                                <label class="form-label small">密码</label>
                                                                <input type="text" class="form-control form-control-sm" 
                                                                       name="project_access[<?php echo $project['id']; ?>][password]" 
                                                                       value="<?php 
                                                                           // 安全地获取密码默认值，优先使用身份证后六位，否则使用默认密码
                                                                           $default_password = '123456';
                                                                           if (!empty($person['id_card']) && strlen($person['id_card']) >= 6) {
                                                                               $default_password = substr($person['id_card'], -6);
                                                                           } else if (!empty($person['phone']) && strlen($person['phone']) >= 6) {
                                                                               $default_password = substr($person['phone'], -6);
                                                                           }
                                                                           echo htmlspecialchars($default_password);
                                                                       ?>"
                                                                       placeholder="密码">
                                                            </div>
                                                        </div>
                                                        <div class="mb-2">
                                                            <label class="form-label small">显示名</label>
                                                            <input type="text" class="form-control form-control-sm" 
                                                                   name="project_access[<?php echo $project['id']; ?>][display_name]" 
                                                                   value="<?php echo htmlspecialchars($person['name']); ?>"
                                                                   placeholder="显示名">
                                                        </div>
                                                        <div class="mb-0">
                                                            <label class="form-label small">角色</label>
                                                            <select class="form-select form-select-sm" 
                                                                    name="project_access[<?php echo $project['id']; ?>][role]">
                                                                <option value="user"<?php echo (in_array($project['id'], array_column(array_filter($current_access, function($item) { return $item['role'] === 'user'; }), 'project_id'))) ? ' selected' : ''; ?>>前台用户</option>
                                                                <option value="admin"<?php echo (in_array($project['id'], array_column(array_filter($current_access, function($item) { return $item['role'] === 'admin'; }), 'project_id'))) ? ' selected' : ''; ?>>前台管理员</option>
                                                            </select>
                                                        </div>
                                                        
                                                        <div class="mt-2">
                                                            <small class="text-muted">访问地址:</small><br>
                                                            <code class="access-url"><?php echo rtrim($site_url, '/'); ?>/user/project_login.php?code=<?php echo htmlspecialchars($project['code']); ?></code>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-info">
                                            <i class="bi bi-info-circle"></i> 该人员尚未分配到任何项目，请先为其分配项目。
                                            <a href="personnel_enhanced.php" class="alert-link">返回人员管理</a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (!empty($person_projects)): ?>
                                    <div class="text-center">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check-circle"></i> 保存项目访问权限
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/app.min.js"></script>
    <script>
        // 启用/禁用项目卡片样式
        document.querySelectorAll('.form-check-input').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const card = this.closest('.project-card');
                if (this.checked) {
                    card.classList.add('enabled');
                } else {
                    card.classList.remove('enabled');
                }
            });
        });

        function copyUrl(projectCode) {
            const url = `<?php echo rtrim($site_url, '/'); ?>/user/project_login.php?code=${projectCode}`;
            navigator.clipboard.writeText(url).then(() => {
                // 创建临时提示
                const toast = document.createElement('div');
                toast.className = 'position-fixed top-0 end-0 m-3 alert alert-success';
                toast.style.zIndex = '9999';
                toast.textContent = '已复制项目访问地址';
                document.body.appendChild(toast);
                
                setTimeout(() => {
                    document.body.removeChild(toast);
                }, 2000);
            });
        }

        function copyUrlWithUser(projectCode, username, password) {
            const url = `<?php echo rtrim($site_url, '/'); ?>/user/project_login.php?code=${projectCode}`;
            const text = `项目访问地址：${url}\n用户名：${username}\n密码：${password}`;
            navigator.clipboard.writeText(text).then(() => {
                // 创建临时提示
                const toast = document.createElement('div');
                toast.className = 'position-fixed top-0 end-0 m-3 alert alert-success';
                toast.style.zIndex = '9999';
                toast.textContent = '已复制访问信息';
                document.body.appendChild(toast);
                
                setTimeout(() => {
                    document.body.removeChild(toast);
                }, 2000);
            });
        }
    </script>
    <?php include 'includes/footer.php'; ?>