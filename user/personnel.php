<?php
// 启动会话
session_start();
// 引入数据库连接类
require_once '../config/database.php';
// 引入权限中间件进行权限验证
require_once '../includes/PermissionMiddleware.php';

$database = new Database();
$db = $database->getConnection();
$middleware = new PermissionMiddleware($db);
$middleware->checkUserPagePermission('frontend:personnel:list');


// 检查用户是否已登录
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 检查是否是管理员会话（来自用户系统）
$is_user_admin = strtolower(trim($_SESSION['role'] ?? '')) === 'admin';

// 设置页面特定变量
$page_title = '人员管理';
$active_page = 'personnel';
$show_page_title = '人员管理';
$page_icon = 'people';

// 启动输出缓冲
ob_start();

// 包含统一头部文件
include 'includes/header.php';

// 获取数据库连接
$database = new Database();
$pdo = $database->getConnection();

// 获取搜索和筛选参数
    $search = $_GET['search'] ?? '';
    $gender = $_GET['gender'] ?? '';
    $department_filter = $_GET['department'] ?? '';
    // 设置默认排序为按部门排序
    $sort_by = 'department'; // 默认按部门排序
    $sort_order = 'ASC'; // 默认升序

// 构建查询条件
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(p.name LIKE :search OR p.email LIKE :search2 OR p.phone LIKE :search3)";
    $params['search'] = "%$search%";
    $params['search2'] = "%$search%";
    $params['search3'] = "%$search%";
}

if (!empty($gender)) {
    $where_conditions[] = "p.gender = :gender";
    $params['gender'] = $gender;
}

if (!empty($department_filter)) {
    if (isset($_SESSION['project_id'])) {
        $where_conditions[] = "pdp.department_id = :department_filter";
    } else {
        $where_conditions[] = "p.department_id = :department_filter";
    }
    $params['department_filter'] = $department_filter;
}

// 根据实际数据库结构构建查询 - 对接后台personnel_enhanced.php系统
$join_sql = "";
$group_by_sql = "GROUP BY p.id, p.name, p.gender, p.phone, p.email, p.id_card, p.created_at";

// 如果是项目用户，只显示该项目的人员（对接后台project_department_personnel表）
if (isset($_SESSION['project_id'])) {
    $join_sql = "
        INNER JOIN project_department_personnel pdp ON p.id = pdp.personnel_id
        INNER JOIN projects proj ON pdp.project_id = proj.id
        LEFT JOIN departments d ON pdp.department_id = d.id
    ";
    $where_conditions[] = "pdp.project_id = :project_id";
    $params['project_id'] = $_SESSION['project_id'];
} else {
    // 管理员：直接连接部门表
    $join_sql = "
        LEFT JOIN departments d ON p.department_id = d.id
    ";
}

$where_sql = $where_conditions ? "WHERE " . implode(" AND ", $where_conditions) : "";

// 获取统计数据
try {
    // 总人数
    $count_sql = "
        SELECT COUNT(DISTINCT p.id) as total 
        FROM personnel p 
        $join_sql 
        $where_sql
    ";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_personnel = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // 在职人数 - 简化为总人数
    $active_personnel = $total_personnel;

    // 性别统计
    $gender_sql = "
        SELECT p.gender, COUNT(DISTINCT p.id) as count 
        FROM personnel p 
        $join_sql 
        $where_sql 
        GROUP BY p.gender
    ";
    $stmt = $pdo->prepare($gender_sql);
    $stmt->execute($params);
    $gender_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 获取部门列表用于批量修改
    if (isset($_SESSION['project_id'])) {
        // 项目用户：只显示该项目的部门，按部门排序显示
        $stmt = $pdo->prepare("SELECT DISTINCT d.id, d.name FROM departments d WHERE d.project_id = ? ORDER BY d.sort_order ASC, d.name");
        $stmt->execute([$_SESSION['project_id']]);
    } else {
        // 管理员：显示所有部门，按部门排序显示
        $stmt = $pdo->query("SELECT DISTINCT id, name FROM departments ORDER BY sort_order ASC, name");
    }
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 构建排序字段映射（防止SQL注入）
    $allowed_sort_fields = [
        'created_at' => 'p.created_at',
        'name' => 'p.name',
        'department' => 'department_names',  // 按部门名称排序
        'gender' => 'p.gender',
        'id' => 'p.id'
    ];
    
    $order_by_field = $allowed_sort_fields[$sort_by] ?? 'p.created_at';
    $order_direction = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';
    
    // 获取项目的工作证类型（如果是在项目上下文中）
    $project_badge_types = [];
    if (isset($_SESSION['project_id'])) {
        $badge_stmt = $pdo->prepare("SELECT badge_types FROM projects WHERE id = ?");
        $badge_stmt->execute([$_SESSION['project_id']]);
        $project_result = $badge_stmt->fetch(PDO::FETCH_ASSOC);
        if ($project_result && !empty($project_result['badge_types'])) {
            $project_badge_types = explode(',', $project_result['badge_types']);
            $project_badge_types = array_map('trim', $project_badge_types);
        }
    }
    
    // 获取人员列表 - 对接后台personnel_enhanced.php系统，包含项目部门信息
    // 如果是按部门排序，需要特殊处理以确保按部门排序显示
    if ($sort_by === 'department') {
        // 按部门排序时，需要连接部门表并按部门排序字段排序
        if (isset($_SESSION['project_id'])) {
            // 项目用户：按项目部门的sort_order排序
            // 为了正确处理一个人属于多个部门的情况，我们使用部门名称的排序来确定人员的排序
            $sql = "
                SELECT 
                    p.id,
                    p.name,
                    p.email,
                    p.phone,
                    p.id_card,
                    p.gender,
                    p.created_at,
                    GROUP_CONCAT(DISTINCT proj.name ORDER BY proj.name SEPARATOR ', ') as project_names,
                    GROUP_CONCAT(DISTINCT d.name ORDER BY d.sort_order ASC, d.name SEPARATOR ', ') as department_names,
                    GROUP_CONCAT(DISTINCT d.id ORDER BY d.sort_order ASC, d.name SEPARATOR ',') as department_ids,
                    GROUP_CONCAT(DISTINCT pdp.position ORDER BY d.sort_order ASC, proj.name, d.name SEPARATOR ', ') as positions,
                    GROUP_CONCAT(DISTINCT pdp.badge_type ORDER BY d.sort_order ASC, proj.name, d.name SEPARATOR ', ') as badge_types,
                    GROUP_CONCAT(DISTINCT CONCAT(proj.name, ' - ', d.name, ' (', pdp.position, ')') ORDER BY d.sort_order ASC SEPARATOR '; ') as project_details
                FROM personnel p
                $join_sql
                $where_sql
                $group_by_sql
                ORDER BY MIN(d.sort_order) $order_direction, p.name
            ";
        } else {
            // 管理员：按所有部门的sort_order排序
            $sql = "
                SELECT 
                    p.id,
                    p.name,
                    p.email,
                    p.phone,
                    p.id_card,
                    p.gender,
                    p.created_at,
                    GROUP_CONCAT(DISTINCT proj.name ORDER BY proj.name SEPARATOR ', ') as project_names,
                    GROUP_CONCAT(DISTINCT d.name ORDER BY d.sort_order ASC, d.name SEPARATOR ', ') as department_names,
                    GROUP_CONCAT(DISTINCT d.id ORDER BY d.sort_order ASC, d.name SEPARATOR ',') as department_ids,
                    GROUP_CONCAT(DISTINCT pdp.position ORDER BY d.sort_order ASC, proj.name, d.name SEPARATOR ', ') as positions,
                    GROUP_CONCAT(DISTINCT pdp.badge_type ORDER BY d.sort_order ASC, proj.name, d.name SEPARATOR ', ') as badge_types,
                    GROUP_CONCAT(DISTINCT CONCAT(proj.name, ' - ', d.name, ' (', pdp.position, ')') ORDER BY d.sort_order ASC SEPARATOR '; ') as project_details
                FROM personnel p
                $join_sql
                $where_sql
                $group_by_sql
                ORDER BY MIN(d.sort_order) $order_direction, p.name
            ";
        }
    } else {
        // 其他排序方式保持原有逻辑
        $sql = "
            SELECT 
                p.id,
                p.name,
                p.email,
                p.phone,
                p.id_card,
                p.gender,
                p.created_at,
                GROUP_CONCAT(DISTINCT proj.name ORDER BY proj.name SEPARATOR ', ') as project_names,
                GROUP_CONCAT(DISTINCT d.name ORDER BY d.sort_order ASC, d.name SEPARATOR ', ') as department_names,
                GROUP_CONCAT(DISTINCT d.id ORDER BY d.sort_order ASC, d.name SEPARATOR ',') as department_ids,
                GROUP_CONCAT(DISTINCT pdp.position ORDER BY d.sort_order ASC, proj.name, d.name SEPARATOR ', ') as positions,
                GROUP_CONCAT(DISTINCT pdp.badge_type ORDER BY d.sort_order ASC, proj.name, d.name SEPARATOR ', ') as badge_types,
                GROUP_CONCAT(DISTINCT CONCAT(proj.name, ' - ', d.name, ' (', pdp.position, ')') ORDER BY d.sort_order ASC SEPARATOR '; ') as project_details
            FROM personnel p
            $join_sql
            $where_sql
            $group_by_sql
            ORDER BY $order_by_field $order_direction
        ";
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $personnel = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    $error_message = "查询数据时出错: " . $e->getMessage();
    $personnel = [];
    $total_personnel = 0;
    $active_personnel = 0;
    $gender_stats = [];
    $departments = [];
}

// 计算性别统计
$male_count = 0;
$female_count = 0;
foreach ($gender_stats as $stat) {
    if ($stat['gender'] === '男') {
        $male_count = $stat['count'];
    } elseif ($stat['gender'] === '女') {
        $female_count = $stat['count'];
    }
}

// 处理批量修改部门请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_update_department'])) {
    $selected_personnel = $_POST['selected_personnel'] ?? [];
    $department_id = $_POST['department_id'] ?? '';
    
    if (!empty($selected_personnel) && !empty($department_id)) {
        try {
            $pdo->beginTransaction();
            
            // 获取当前项目ID（如果是项目用户）
            $project_id = $_SESSION['project_id'] ?? null;
            
            if ($project_id) {
                // 项目用户：更新project_department_personnel表
                $update_sql = "
                    UPDATE project_department_personnel 
                    SET department_id = :department_id 
                    WHERE personnel_id = :personnel_id AND project_id = :project_id
                ";
                $stmt = $pdo->prepare($update_sql);
                
                foreach ($selected_personnel as $personnel_id) {
                    $stmt->execute([
                        'department_id' => $department_id,
                        'personnel_id' => $personnel_id,
                        'project_id' => $project_id
                    ]);
                }
            } else {
                // 管理员：更新personnel表的department_id字段
                $update_sql = "
                    UPDATE personnel 
                    SET department_id = :department_id 
                    WHERE id = :personnel_id
                ";
                $stmt = $pdo->prepare($update_sql);
                
                foreach ($selected_personnel as $personnel_id) {
                    $stmt->execute([
                        'department_id' => $department_id,
                        'personnel_id' => $personnel_id
                    ]);
                }
            }
            
            $pdo->commit();
            
            $_SESSION['success_message'] = '批量修改部门成功！';
            
            // 重定向回当前页面，避免重复提交
            $redirect_url = 'personnel.php';
            if (!empty($_GET)) {
                $redirect_url .= '?' . http_build_query($_GET);
            }
            header("Location: $redirect_url");
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = '批量修改部门失败: ' . htmlspecialchars($e->getMessage());
            $redirect_url = 'personnel.php';
            if (!empty($_GET)) {
                $redirect_url .= '?' . http_build_query($_GET);
            }
            header("Location: $redirect_url");
            exit;
        }
    }
}

// 处理批量修改性别请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_update_gender'])) {
    $selected_personnel = $_POST['selected_personnel'] ?? [];
    $gender = $_POST['gender'] ?? '';
    
    if (!empty($selected_personnel) && !empty($gender)) {
        try {
            $pdo->beginTransaction();
            
            // 更新personnel表的gender字段
            $update_sql = "
                UPDATE personnel 
                SET gender = :gender 
                WHERE id = :personnel_id
            ";
            $stmt = $pdo->prepare($update_sql);
            
            foreach ($selected_personnel as $personnel_id) {
                $stmt->execute([
                    'gender' => $gender,
                    'personnel_id' => $personnel_id
                ]);
            }
            
            $pdo->commit();
            
            $_SESSION['success_message'] = '批量修改性别成功！';
            
            // 重定向回当前页面，避免重复提交
            $redirect_url = 'personnel.php';
            if (!empty($_GET)) {
                $redirect_url .= '?' . http_build_query($_GET);
            }
            header("Location: $redirect_url");
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = '批量修改性别失败: ' . htmlspecialchars($e->getMessage());
            $redirect_url = 'personnel.php';
            if (!empty($_GET)) {
                $redirect_url .= '?' . http_build_query($_GET);
            }
            header("Location: $redirect_url");
            exit;
        }
    }
}

// 处理批量修改工作证类型请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['batch_update_badge_type'])) {
    $selected_personnel = $_POST['selected_personnel'] ?? [];
    $badge_type = $_POST['badge_type'] ?? '';
    
    if (!empty($selected_personnel)) {
        try {
            $pdo->beginTransaction();
            
            // 获取当前项目ID（如果是项目用户）
            $project_id = $_SESSION['project_id'] ?? null;
            
            if ($project_id) {
                // 项目用户：更新project_department_personnel表的badge_type字段
                $update_sql = "
                    UPDATE project_department_personnel 
                    SET badge_type = :badge_type 
                    WHERE personnel_id = :personnel_id AND project_id = :project_id
                ";
                $stmt = $pdo->prepare($update_sql);
                
                foreach ($selected_personnel as $personnel_id) {
                    $stmt->execute([
                        'badge_type' => $badge_type,
                        'personnel_id' => $personnel_id,
                        'project_id' => $project_id
                    ]);
                }
            }
            
            $pdo->commit();
            
            $_SESSION['success_message'] = '批量修改工作证类型成功！';
            
            // 重定向回当前页面，避免重复提交
            $redirect_url = 'personnel.php';
            if (!empty($_GET)) {
                $redirect_url .= '?' . http_build_query($_GET);
            }
            header("Location: $redirect_url");
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = '批量修改工作证类型失败: ' . htmlspecialchars($e->getMessage());
            $redirect_url = 'personnel.php';
            if (!empty($_GET)) {
                $redirect_url .= '?' . http_build_query($_GET);
            }
            header("Location: $redirect_url");
            exit;
        }
    }
}

// 处理删除人员请求（仅管理员）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_personnel'])) {
    // 检查是否为管理员
    if ($is_user_admin) {
        $personnel_id = $_POST['personnel_id'] ?? '';
        
        if (!empty($personnel_id)) {
            try {
                $pdo->beginTransaction();
                
                // 不论任何情况，都只删除人员在当前项目的分配
                if (isset($_SESSION['project_id'])) {
                    // 删除人员在当前项目的分配
                    $delete_pdp_sql = "DELETE FROM project_department_personnel WHERE personnel_id = ? AND project_id = ?";
                    $stmt = $pdo->prepare($delete_pdp_sql);
                    $stmt->execute([$personnel_id, $_SESSION['project_id']]);
                    
                    // 检查该人员是否还有其他项目分配
                    $check_sql = "SELECT COUNT(*) as count FROM project_department_personnel WHERE personnel_id = ?";
                    $stmt = $pdo->prepare($check_sql);
                    $stmt->execute([$personnel_id]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // 只有当人员在其他项目中也没有分配时才删除该人员的基本信息
                    if ($result['count'] == 0) {
                        $delete_personnel_sql = "DELETE FROM personnel WHERE id = ?";
                        $stmt = $pdo->prepare($delete_personnel_sql);
                        $stmt->execute([$personnel_id]);
                    }
                } else {
                    // 即使是管理员，也只删除人员在当前项目的分配（这里假设是所有项目）
                    // 检查该人员是否还有项目分配
                    $check_sql = "SELECT COUNT(*) as count FROM project_department_personnel WHERE personnel_id = ?";
                    $stmt = $pdo->prepare($check_sql);
                    $stmt->execute([$personnel_id]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // 删除所有项目部门关系
                    $delete_pdp_sql = "DELETE FROM project_department_personnel WHERE personnel_id = ?";
                    $stmt = $pdo->prepare($delete_pdp_sql);
                    $stmt->execute([$personnel_id]);
                    
                    // 只有当人员在其他项目中也没有分配时才删除该人员的基本信息
                    if ($result['count'] == 0) {
                        $delete_personnel_sql = "DELETE FROM personnel WHERE id = ?";
                        $stmt = $pdo->prepare($delete_personnel_sql);
                        $stmt->execute([$personnel_id]);
                    }
                }
                
                $pdo->commit();
                
                // 重定向回当前页面
                $redirect_url = 'personnel.php';
                if (!empty($_GET)) {
                    $redirect_url .= '?' . http_build_query($_GET);
                }
                header("Location: $redirect_url");
                exit;
                
            } catch (Exception $e) {
                $pdo->rollBack();
                echo '<div class="alert alert-danger">删除人员失败: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
    } else {
        echo '<div class="alert alert-danger">您没有权限删除人员，只有管理员才能删除人员</div>';
    }
}
?>

<!-- 统计卡片 -->
<div class="row justify-content-center mb-4">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title"><?php echo $total_personnel; ?></h4>
                        <p class="card-text">总人数</p>
                    </div>
                    <div>
                        <i class="bi bi-people-fill fs-1"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 男性人数统计卡片 - 开始 -->
    <div class="col-md-3">
        <div class="card text-white" style="background-color: #dc3545;">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title"><?php echo $male_count; ?></h4>
                        <p class="card-text">男性人数</p>
                    </div>
                    <div>
                        <i class="bi bi-gender-male fs-1"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- 男性人数统计卡片 - 结束 -->
    
    <!-- 女性人数统计卡片 - 开始 -->
    <div class="col-md-3">
        <div class="card text-white" style="background-color: #6f42c1;">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <div>
                        <h4 class="card-title"><?php echo $female_count; ?></h4>
                        <p class="card-text">女性人数</p>
                    </div>
                    <div>
                        <i class="bi bi-gender-female fs-1"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- 女性人数统计卡片 - 结束 -->
</div>

<!-- 搜索和筛选 -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title mb-0">搜索和筛选</h5>
    </div>
    <div class="card-body">
        <form method="GET" action="personnel.php">
            <div class="row">
                <div class="col-md-3">
                    <label for="search" class="form-label">搜索</label>
                    <input type="text" class="form-control" id="search" name="search" 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="姓名、电话、身份证">
                </div>
                <div class="col-md-2">
                    <label for="gender" class="form-label">性别</label>
                    <select class="form-select" id="gender" name="gender">
                        <option value="">全部</option>
                        <option value="男" <?php echo $gender === '男' ? 'selected' : ''; ?>>男</option>
                        <option value="女" <?php echo $gender === '女' ? 'selected' : ''; ?>>女</option>
                        <option value="其他" <?php echo $gender === '其他' ? 'selected' : ''; ?>>其他</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="department" class="form-label">部门</label>
                    <select class="form-select" id="department" name="department">
                        <option value="">全部部门 - 点击可按部门筛选人员</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>" 
                                    <?php echo isset($_GET['department']) && $_GET['department'] == $dept['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">&nbsp;</label>
                    <div>
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-search"></i> 搜索
                        </button>
                        <a href="personnel.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-clockwise"></i> 重置
                        </a>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- 人员列表 -->
<div class="card">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div class="d-flex align-items-center">
                <h5 class="card-title mb-0 me-3">人员列表</h5>
                <a href="batch_add_personnel.php" class="btn btn-success">
                    <i class="bi bi-people-fill"></i> 批量添加人员
                </a>
            </div>
            <!-- 添加导出按钮 -->
            <div class="d-flex align-items-center">
                <a href="export_personnel.php" class="btn btn-primary" target="_blank">
                    <i class="bi bi-download"></i> 导出表格
                </a>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle"></i> <?php echo $_SESSION['success_message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle"></i> <?php echo $_SESSION['error_message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>
        
        <?php if (empty($personnel)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> 暂无人员数据
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <!-- 批量操作区域 -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="card-title mb-0">批量操作</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <!-- 批量修改部门 -->
                            <div class="col-md-4">
                                <form id="batchDepartmentForm" method="POST" action="personnel.php">
                                    <div class="input-group">
                                        <select class="form-select" id="batchDepartment" name="department_id" required>
                                            <option value="">选择部门...</option>
                                            <?php foreach ($departments as $dept): ?>
                                                <option value="<?php echo $dept['id']; ?>">
                                                    <?php echo htmlspecialchars($dept['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" name="batch_update_department" class="btn btn-warning" id="batchUpdateDepartmentBtn" disabled>
                                            <i class="bi bi-building"></i> 修改部门
                                        </button>
                                    </div>
                                    <!-- 动态添加选中的人员ID -->
                                    <div id="departmentPersonnelInputs"></div>
                                </form>
                            </div>
                            
                            <!-- 批量修改性别 -->
                            <div class="col-md-4">
                                <form id="batchGenderForm" method="POST" action="personnel.php">
                                    <div class="input-group">
                                        <select class="form-select" id="batchGender" name="gender" required>
                                            <option value="">选择性别...</option>
                                            <option value="男">男</option>
                                            <option value="女">女</option>
                                            <option value="其他">其他</option>
                                        </select>
                                        <button type="submit" name="batch_update_gender" class="btn btn-info" id="batchUpdateGenderBtn" disabled>
                                            <i class="bi bi-gender-ambiguous"></i> 修改性别
                                        </button>
                                    </div>
                                    <!-- 动态添加选中的人员ID -->
                                    <div id="genderPersonnelInputs"></div>
                                </form>
                            </div>
                            
                            <!-- 批量修改工作证类型 -->
                            <div class="col-md-4">
                                <form id="batchBadgeTypeForm" method="POST" action="personnel.php">
                                    <div class="input-group">
                                        <select class="form-select" id="batchBadgeType" name="badge_type">
                                            <option value="">选择工作证类型...</option>
                                            <?php if (!empty($project_badge_types)): ?>
                                                <?php foreach ($project_badge_types as $badge): ?>
                                                    <option value="<?php echo htmlspecialchars($badge); ?>">
                                                        <?php echo htmlspecialchars($badge); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </select>
                                        <button type="submit" name="batch_update_badge_type" class="btn btn-success" id="batchUpdateBadgeTypeBtn" disabled>
                                            <i class="bi bi-card-checklist"></i> 修改工作证
                                        </button>
                                    </div>
                                    <!-- 动态添加选中的人员ID -->
                                    <div id="badgeTypePersonnelInputs"></div>
                                </form>
                            </div>
                            
                            <!-- 取消选择按钮 -->
                            <div class="col-md-12">
                                <button type="button" class="btn btn-secondary btn-sm" id="clearSelectionBtn" style="display: none;">
                                    <i class="bi bi-x-circle"></i> 取消选择
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 人员列表表格 -->
                <table class="table table-hover table-bordered" id="personnelTable" style="border-style: dashed;">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="selectAll" class="form-check-input"></th>
                            <th>序号</th>
                            <th>姓名</th>
                            <th>性别</th>
                            <th>部门</th>
                            <th>职位</th>
                            <th>工作证</th>
                            <th>身份证</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $serial_number = 1; foreach ($personnel as $person): ?>
                            <tr>
                                <td><input type="checkbox" name="selected_personnel[]" value="<?php echo $person['id']; ?>" class="form-check-input personnel-checkbox"></td>
                                <td><?php echo $serial_number++; ?></td>
                                <td>
                                    <?php 
                                    // 检查是否为艺人或嘉宾
                                    $isArtistOrGuest = false;
                                    $person_departments = explode(',', $person['department_names'] ?? '');
                                    foreach ($person_departments as $dept) {
                                        $dept = trim($dept);
                                        if (strpos($dept, '艺人') !== false || strpos($dept, '嘉宾') !== false) {
                                            $isArtistOrGuest = true;
                                            break;
                                        }
                                    }
                                    
                                    if ($isArtistOrGuest): ?>
                                        <span style="color: red; font-weight: bold;"><?php echo htmlspecialchars($person['name']); ?></span>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($person['name']); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    // 性别显示样式设置 - 开始
                                    // 男：红色背景 (#dc3545)，女：紫色背景 (#6f42c1)，其他：灰色背景
                                    if ($person['gender'] === 'male' || $person['gender'] === '男') {
                                        echo '<span class="badge" style="background-color: #dc3545; color: white;">男</span>';
                                    } elseif ($person['gender'] === 'female' || $person['gender'] === '女') {
                                        echo '<span class="badge" style="background-color: #6f42c1; color: white;">女</span>';
                                    } else {
                                        echo '<span class="badge bg-secondary">其他</span>';
                                    }
                                    // 性别显示样式设置 - 结束
                                    ?>
                                </td>
                                <td title="<?php echo htmlspecialchars($person['project_details'] ?? ''); ?>">
                                    <?php 
                                    // 显示部门选择下拉框
                                    if (!empty($departments)): ?>
                                        <select class="form-select form-select-sm department-select" data-personnel-id="<?php echo $person['id']; ?>">
                                            <option value="">请选择部门</option>
                                            <?php foreach ($departments as $dept): ?>
                                                <option value="<?php echo $dept['id']; ?>"
                                                        <?php echo (isset($person['department_ids']) && in_array($dept['id'], explode(',', $person['department_ids']))) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($dept['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="position-cell" data-personnel-id="<?php echo $person['id']; ?>">
                                    <div class="position-display" onclick="editPosition(<?php echo $person['id']; ?>, this)">
                                        <?php 
                                        if (!empty($person['positions']) && $person['positions'] !== 'NULL') {
                                            echo '<span class="position-text">' . htmlspecialchars($person['positions']) . '</span>';
                                        } else {
                                            echo '<span class="position-text text-muted">未设置</span>';
                                        }
                                        ?>
                                        <i class="bi bi-pencil-square edit-icon ms-2" style="cursor: pointer; opacity: 0.7;"></i>
                                    </div>
                                    <div class="position-edit" style="display: none;">
                                        <input type="text" class="form-control form-control-sm position-input" 
                                               value="<?php echo htmlspecialchars($person['positions'] ?? ''); ?>"
                                               onblur="savePosition(<?php echo $person['id']; ?>, this)"
                                               onkeydown="handlePositionKeydown(event, <?php echo $person['id']; ?>, this)">
                                    </div>
                                </td>
                                <td>
                                    <?php 
                                    // 显示工作证类型选择下拉框
                                    if (!empty($project_badge_types)): ?>
                                        <select class="form-select form-select-sm badge-select" data-personnel-id="<?php echo $person['id']; ?>">
                                            <option value="">请选择工作证类型</option>
                                            <?php foreach ($project_badge_types as $badge_type): ?>
                                                <option value="<?php echo htmlspecialchars($badge_type); ?>"
                                                        <?php echo (isset($person['badge_types']) && $person['badge_types'] === $badge_type) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($badge_type); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($person['id_card'] ?? '-'); ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="personnel_edit.php?id=<?php echo $person['id']; ?>" 
                                           class="btn btn-outline-primary btn-sm">
                                            <i class="bi bi-pencil"></i> 编辑
                                        </a>
                                        <a href="personnel_view.php?id=<?php echo $person['id']; ?>" 
                                           class="btn btn-outline-info btn-sm">
                                            <i class="bi bi-eye"></i> 查看
                                        </a>
                                        <?php if ($is_user_admin): // 仅管理员显示删除按钮 ?>
                                        <!-- 独立删除表单 -->
                                        <form method="POST" action="personnel.php" style="display: inline;" 
                                              onsubmit="return confirmDelete(<?php echo $person['id']; ?>, '<?php echo addslashes($person['name']); ?>');">
                                            <input type="hidden" name="personnel_id" value="<?php echo $person['id']; ?>">
                                            <button type="submit" name="delete_personnel" class="btn btn-outline-danger btn-sm" title="删除此人员（无需选择）">
                                                <i class="bi bi-trash"></i> 删除
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="mt-3">
                <small class="text-muted">
                    共 <?php echo count($personnel); ?> 条记录
                </small>
            </div>
        <?php endif; ?>
    </div>
</div>



<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const personnelCheckboxes = document.querySelectorAll('.personnel-checkbox');
    const clearSelectionBtn = document.getElementById('clearSelectionBtn');
    const batchDepartmentForm = document.getElementById('batchDepartmentForm');
    const batchDepartmentSelect = document.getElementById('batchDepartment');

    // 全选/取消全选
    selectAllCheckbox.addEventListener('change', function() {
        personnelCheckboxes.forEach(checkbox => {
            checkbox.checked = selectAllCheckbox.checked;
        });
        updateButtonState();
    });

    // 单个复选框变化时更新按钮状态
    personnelCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateSelectAllState();
            updateButtonState();
        });
    });

    // 取消选择按钮
    clearSelectionBtn.addEventListener('click', function() {
        personnelCheckboxes.forEach(checkbox => {
            checkbox.checked = false;
        });
        selectAllCheckbox.checked = false;
        updateButtonState();
    });

    // 更新全选复选框状态
    function updateSelectAllState() {
        const checkedCount = document.querySelectorAll('.personnel-checkbox:checked').length;
        const totalCount = personnelCheckboxes.length;
        
        if (checkedCount === totalCount && totalCount > 0) {
            selectAllCheckbox.checked = true;
        } else {
            selectAllCheckbox.checked = false;
        }
    }

    // 获取按钮元素
    const batchUpdateDepartmentBtn = document.getElementById('batchUpdateDepartmentBtn');
    const batchUpdateGenderBtn = document.getElementById('batchUpdateGenderBtn');
    const batchUpdateBadgeTypeBtn = document.getElementById('batchUpdateBadgeTypeBtn');
    
    // 更新按钮状态
    function updateButtonState() {
        const checkedCount = document.querySelectorAll('.personnel-checkbox:checked').length;
        
        if (checkedCount > 0) {
            batchUpdateDepartmentBtn.disabled = false;
            batchUpdateGenderBtn.disabled = false;
            batchUpdateBadgeTypeBtn.disabled = false;
            clearSelectionBtn.style.display = 'inline-block';
            batchUpdateDepartmentBtn.innerHTML = `<i class="bi bi-building"></i> 修改部门 (${checkedCount})`;
            batchUpdateGenderBtn.innerHTML = `<i class="bi bi-gender-ambiguous"></i> 修改性别 (${checkedCount})`;
            batchUpdateBadgeTypeBtn.innerHTML = `<i class="bi bi-card-checklist"></i> 修改工作证 (${checkedCount})`;
        } else {
            batchUpdateDepartmentBtn.disabled = true;
            batchUpdateGenderBtn.disabled = true;
            batchUpdateBadgeTypeBtn.disabled = true;
            clearSelectionBtn.style.display = 'none';
            batchUpdateDepartmentBtn.innerHTML = '<i class="bi bi-building"></i> 修改部门';
            batchUpdateGenderBtn.innerHTML = '<i class="bi bi-gender-ambiguous"></i> 修改性别';
            batchUpdateBadgeTypeBtn.innerHTML = '<i class="bi bi-card-checklist"></i> 修改工作证';
        }
    }

    // 批量修改部门表单验证
    batchDepartmentForm.addEventListener('submit', function(e) {
        const checkedCheckboxes = document.querySelectorAll('.personnel-checkbox:checked');
        const checkedCount = checkedCheckboxes.length;
        
        if (checkedCount === 0) {
            e.preventDefault();
            alert('批量修改部门：请先勾选要修改的人员左侧复选框');
            return;
        }

        if (!batchDepartmentSelect.value) {
            e.preventDefault();
            alert('请选择要修改到的部门');
            return;
        }

        const departmentName = batchDepartmentSelect.options[batchDepartmentSelect.selectedIndex].text;
        const personnelNames = [];
        
        // 清空之前的隐藏输入
        const departmentInputs = document.getElementById('departmentPersonnelInputs');
        departmentInputs.innerHTML = '';
        
        // 添加新的隐藏输入
        checkedCheckboxes.forEach(checkbox => {
            const row = checkbox.closest('tr');
            const name = row.cells[2].textContent;
            personnelNames.push(name);
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_personnel[]';
            input.value = checkbox.value;
            departmentInputs.appendChild(input);
        });

        const confirmMessage = `⚠️ 批量修改部门确认

您即将修改 ${checkedCount} 名人员的部门：

${personnelNames.map(name => `• ${name}`).join('\n')}

修改到部门：${departmentName}

⚠️ 警告：此操作将覆盖现有部门分配！

是否确认执行？`;
        
        if (confirm(confirmMessage)) {
            this.submit();
        } else {
            e.preventDefault();
        }
    });

    // 批量修改性别表单验证
    const batchGenderForm = document.getElementById('batchGenderForm');
    const batchGenderSelect = document.getElementById('batchGender');
    
    batchGenderForm.addEventListener('submit', function(e) {
        const checkedCheckboxes = document.querySelectorAll('.personnel-checkbox:checked');
        const checkedCount = checkedCheckboxes.length;
        
        if (checkedCount === 0) {
            e.preventDefault();
            alert('批量修改性别：请先勾选要修改的人员左侧复选框');
            return;
        }

        if (!batchGenderSelect.value) {
            e.preventDefault();
            alert('请选择要修改到的性别');
            return;
        }

        const genderName = batchGenderSelect.options[batchGenderSelect.selectedIndex].text;
        const personnelNames = [];
        
        // 清空之前的隐藏输入
        const genderInputs = document.getElementById('genderPersonnelInputs');
        genderInputs.innerHTML = '';
        
        // 添加新的隐藏输入
        checkedCheckboxes.forEach(checkbox => {
            const row = checkbox.closest('tr');
            const name = row.cells[2].textContent;
            personnelNames.push(name);
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_personnel[]';
            input.value = checkbox.value;
            genderInputs.appendChild(input);
        });

        const confirmMessage = `⚠️ 批量修改性别确认

您即将修改 ${checkedCount} 名人员的性别：

${personnelNames.map(name => `• ${name}`).join('\n')}

修改到性别：${genderName}

⚠️ 警告：此操作将覆盖现有性别信息！

是否确认执行？`;
        
        if (confirm(confirmMessage)) {
            this.submit();
        } else {
            e.preventDefault();
        }
    });
    
    // 批量修改工作证类型表单验证
    const batchBadgeTypeForm = document.getElementById('batchBadgeTypeForm');
    const batchBadgeTypeSelect = document.getElementById('batchBadgeType');
    
    if (batchBadgeTypeForm) {
        batchBadgeTypeForm.addEventListener('submit', function(e) {
            const checkedCheckboxes = document.querySelectorAll('.personnel-checkbox:checked');
            const checkedCount = checkedCheckboxes.length;
            
            if (checkedCount === 0) {
                e.preventDefault();
                alert('批量修改工作证类型：请先勾选要修改的人员左侧复选框');
                return;
            }

            const badgeTypeName = batchBadgeTypeSelect.value ? batchBadgeTypeSelect.options[batchBadgeTypeSelect.selectedIndex].text : '清空工作证类型';
            const personnelNames = [];
            
            // 清空之前的隐藏输入
            const badgeTypeInputs = document.getElementById('badgeTypePersonnelInputs');
            badgeTypeInputs.innerHTML = '';
            
            // 添加新的隐藏输入
            checkedCheckboxes.forEach(checkbox => {
                const row = checkbox.closest('tr');
                const name = row.cells[2].textContent;
                personnelNames.push(name);
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_personnel[]';
                input.value = checkbox.value;
                badgeTypeInputs.appendChild(input);
            });

            const confirmMessage = `⚠️ 批量修改工作证类型确认

您即将修改 ${checkedCount} 名人员的工作证类型：

${personnelNames.map(name => `• ${name}`).join('\n')}

修改到工作证类型：${badgeTypeName}

⚠️ 警告：此操作将覆盖现有工作证类型信息！

是否确认执行？`;
            
            if (confirm(confirmMessage)) {
                this.submit();
            } else {
                e.preventDefault();
            }
        });
    }
});

// 删除确认函数
function confirmDelete(personnelId, personnelName) {
    // 不论任何情况，都只删除人员在当前项目的分配
    // 只有当人员在其他项目中也没有分配时才删除该人员的基本信息
    
    let message = `⚠️ 删除确认

您即将删除人员：
• 姓名："${personnelName}"
• 此操作将删除该人员在当前项目的分配
• 如果该人员在其他项目中也没有分配，将同时删除人员基本信息

⚠️ 警告：此操作不可恢复！

是否确认删除？`;
    
    return confirm(message);
}

// 职位编辑相关函数
function editPosition(personnelId, element) {
    const cell = element.closest('.position-cell');
    const displayDiv = cell.querySelector('.position-display');
    const editDiv = cell.querySelector('.position-edit');
    const input = cell.querySelector('.position-input');
    
    // 隐藏显示模式，显示编辑模式
    displayDiv.style.display = 'none';
    editDiv.style.display = 'block';
    
    // 聚焦到输入框并选中全部文本
    input.focus();
    input.select();
}

function savePosition(personnelId, input) {
    const cell = input.closest('.position-cell');
    const displayDiv = cell.querySelector('.position-display');
    const editDiv = cell.querySelector('.position-edit');
    const positionText = cell.querySelector('.position-text');
    const newPosition = input.value.trim();
    
    // 显示加载状态
    input.disabled = true;
    positionText.innerHTML = '<i class="bi bi-hourglass-split"></i> 保存中...';
    positionText.className = 'position-text text-primary';
    
    // 发送AJAX请求
    fetch('ajax/update_position.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            personnel_id: personnelId,
            position: newPosition
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // 更新成功
            if (newPosition === '') {
                positionText.innerHTML = '未设置';
                positionText.className = 'position-text text-muted';
            } else {
                positionText.innerHTML = newPosition;
                positionText.className = 'position-text';
            }
            
            // 显示成功提示
            showTemporaryMessage('职位更新成功！', 'success');
        } else {
            // 更新失败，恢复原值
            input.value = positionText.textContent === '未设置' ? '' : positionText.textContent;
            showTemporaryMessage('更新失败：' + (data.message || '未知错误'), 'danger');
            positionText.className = 'position-text';
        }
    })
    .catch(error => {
        console.error('请求错误:', error);
        // 恢复原值
        input.value = positionText.textContent === '未设置' ? '' : positionText.textContent;
        showTemporaryMessage('网络错误，请稍后重试', 'danger');
        positionText.className = 'position-text';
    })
    .finally(() => {
        // 恢复显示模式
        input.disabled = false;
        editDiv.style.display = 'none';
        displayDiv.style.display = 'block';
    });
}

function handlePositionKeydown(event, personnelId, input) {
    if (event.key === 'Enter') {
        event.preventDefault();
        input.blur(); // 触发 onblur 事件
    } else if (event.key === 'Escape') {
        event.preventDefault();
        // 取消编辑，恢复原值
        const cell = input.closest('.position-cell');
        const displayDiv = cell.querySelector('.position-display');
        const editDiv = cell.querySelector('.position-edit');
        const positionText = cell.querySelector('.position-text');
        
        // 恢复原值
        input.value = positionText.textContent === '未设置' ? '' : positionText.textContent;
        
        // 恢复显示模式
        editDiv.style.display = 'none';
        displayDiv.style.display = 'block';
    }
}

// 显示临时消息
function showTemporaryMessage(message, type = 'success') {
    // 创建消息元素
    const messageDiv = document.createElement('div');
    messageDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
    messageDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    messageDiv.innerHTML = `
        <i class="bi bi-${type === 'success' ? 'check-circle' : 'exclamation-triangle'}"></i> ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    // 添加到页面
    document.body.appendChild(messageDiv);
    
    // 3秒后自动移除
    setTimeout(() => {
        if (messageDiv.parentNode) {
            messageDiv.remove();
        }
    }, 3000);
}
</script>

<?php include 'includes/footer.php'; ?>

<style>
/* 职位编辑样式 */
.position-cell {
    min-width: 120px;
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 4px;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.position-display:hover {
    background-color: #f8f9fa;
}

.position-display .edit-icon {
    opacity: 0;
    transition: opacity 0.2s ease;
    font-size: 0.9em;
    color: #6c757d;
}

.position-display:hover .edit-icon {
    opacity: 0.7;
}

.position-edit {
    position: relative;
}

.position-input {
    min-width: 100px;
    font-size: 0.9em;
}

.position-input:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
}

/* 响应式设计 */
@media (max-width: 768px) {
    .position-cell {
        min-width: 100px;
    }
    
    .position-display {
        padding: 2px 4px;
    }
    
    .position-input {
        font-size: 0.8em;
        min-width: 80px;
    }
}

/* 加载状态样式 */
.position-text.text-primary {
    font-style: italic;
}

/* 临时消息样式增强 */
.alert.position-fixed {
    animation: slideInRight 0.3s ease-out;
}

@keyframes slideInRight {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}
</style>

<style>
/* 表格虚线边框样式 */
#personnelTable {
    border: 1px dashed #dee2e6 !important;
}

#personnelTable th,
#personnelTable td {
    border: 1px dashed #dee2e6 !important;
}

/* 批量操作卡片样式 */
.batch-operation-card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
}

/* 响应式调整 */
@media (max-width: 768px) {
    .batch-operation-card .row {
        gap: 1rem;
    }
    
    .batch-operation-card .col-md-4 {
        flex: 0 0 100%;
        max-width: 100%;
    }
}
</style>

<script>
// 职位编辑功能
function editPosition(personnelId, element) {
    const displayDiv = element.closest('.position-display');
    const editDiv = displayDiv.nextElementSibling;
    const displayText = displayDiv.querySelector('.position-text');
    const input = editDiv.querySelector('.position-input');
    
    // 切换显示状态
    displayDiv.style.display = 'none';
    editDiv.style.display = 'block';
    
    // 聚焦到输入框并全选内容
    input.focus();
    input.select();
}

function savePosition(personnelId, input) {
    const newValue = input.value.trim();
    const editDiv = input.closest('.position-edit');
    const displayDiv = editDiv.previousElementSibling;
    const displayText = displayDiv.querySelector('.position-text');
    
    // 如果值没有改变，直接取消编辑
    if (newValue === (displayText.textContent || '').trim()) {
        editDiv.style.display = 'none';
        displayDiv.style.display = 'flex';
        return;
    }
    
    // 显示保存中状态
    displayText.innerHTML = '<span class="text-primary">保存中...</span>';
    editDiv.style.display = 'none';
    displayDiv.style.display = 'flex';
    
    // 发送AJAX请求更新职位
    fetch('ajax/update_position.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `personnel_id=${personnelId}&position=${encodeURIComponent(newValue)}<?php echo isset($_SESSION['project_id']) ? '&project_id=' . $_SESSION['project_id'] : ''; ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // 更新显示文本
            if (newValue) {
                displayText.textContent = newValue;
            } else {
                displayText.textContent = '未设置';
                displayText.classList.add('text-muted');
            }
        } else {
            // 显示错误消息
            alert('更新职位失败: ' + data.message);
            // 恢复原来的值
            displayText.textContent = displayText.dataset.originalValue || displayText.textContent;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('更新职位时发生错误');
        // 恢复原来的值
        displayText.textContent = displayText.dataset.originalValue || displayText.textContent;
    });
}

function handlePositionKeydown(event, personnelId, input) {
    if (event.key === 'Enter') {
        savePosition(personnelId, input);
    } else if (event.key === 'Escape') {
        // 取消编辑，恢复原值
        const editDiv = input.closest('.position-edit');
        const displayDiv = editDiv.previousElementSibling;
        editDiv.style.display = 'none';
        displayDiv.style.display = 'flex';
    }
}

// 工作证类型选择处理
document.addEventListener('DOMContentLoaded', function() {
    // 为工作证类型下拉框添加事件监听器
    document.querySelectorAll('.badge-select').forEach(function(select) {
        select.addEventListener('change', function() {
            const personnelId = this.dataset.personnelId;
            const badgeType = this.value;
            
            // 发送AJAX请求保存工作证类型
            fetch('ajax/update_badge_type.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `personnel_id=${personnelId}&badge_type=${encodeURIComponent(badgeType)}<?php echo isset($_SESSION['project_id']) ? '&project_id=' . $_SESSION['project_id'] : ''; ?>`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 可以添加成功提示
                    console.log('工作证类型更新成功');
                } else {
                    // 显示错误消息
                    alert('更新工作证类型失败: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('更新工作证类型时发生错误');
            });
        });
    });
    
    // 为部门下拉框添加事件监听器
    document.querySelectorAll('.department-select').forEach(function(select) {
        // 保存原始值
        select.dataset.originalValue = select.value;
        
        select.addEventListener('change', function() {
            const personnelId = this.dataset.personnelId;
            const departmentId = this.value;
            
            // 发送AJAX请求保存部门
            fetch('ajax/update_department.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `personnel_id=${personnelId}&department_id=${encodeURIComponent(departmentId)}<?php echo isset($_SESSION['project_id']) ? '&project_id=' . $_SESSION['project_id'] : ''; ?>`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 更新成功，保存新的原始值
                    this.dataset.originalValue = departmentId;
                    // 可以添加成功提示
                    console.log('部门更新成功: ' + data.department_name);
                } else {
                    // 显示错误消息
                    alert('更新部门失败: ' + data.message);
                    // 恢复原来的选择
                    this.value = this.dataset.originalValue || '';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('更新部门时发生错误');
                // 恢复原来的选择
                this.value = this.dataset.originalValue || '';
            });
        });
    });
    
    // 批量操作功能
    const selectAllCheckbox = document.getElementById('selectAll');
    const personnelCheckboxes = document.querySelectorAll('.personnel-checkbox');
    const batchUpdateDepartmentBtn = document.getElementById('batchUpdateDepartmentBtn');
    const batchUpdateGenderBtn = document.getElementById('batchUpdateGenderBtn');
    const batchUpdateBadgeTypeBtn = document.getElementById('batchUpdateBadgeTypeBtn');
    const clearSelectionBtn = document.getElementById('clearSelectionBtn');
    const batchDepartmentSelect = document.getElementById('batchDepartment');
    const batchGenderSelect = document.getElementById('batchGender');
    const batchBadgeTypeSelect = document.getElementById('batchBadgeType');
    
    // 全选/取消全选功能
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            personnelCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateBatchButtons();
        });
    }
    
    // 单个复选框状态改变时更新全选框状态
    personnelCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const allChecked = Array.from(personnelCheckboxes).every(cb => cb.checked);
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = allChecked;
            }
            updateBatchButtons();
        });
    });
    
    // 更新批量操作按钮状态
    function updateBatchButtons() {
        const anyChecked = Array.from(personnelCheckboxes).some(cb => cb.checked);
        if (batchUpdateDepartmentBtn) {
            batchUpdateDepartmentBtn.disabled = !anyChecked;
        }
        if (batchUpdateGenderBtn) {
            batchUpdateGenderBtn.disabled = !anyChecked;
        }
        if (batchUpdateBadgeTypeBtn) {
            batchUpdateBadgeTypeBtn.disabled = !anyChecked;
        }
        if (clearSelectionBtn) {
            clearSelectionBtn.style.display = anyChecked ? 'inline-block' : 'none';
        }
    }
    
    // 批量修改部门表单提交处理
    const batchDepartmentForm = document.getElementById('batchDepartmentForm');
    if (batchDepartmentForm) {
        batchDepartmentForm.addEventListener('submit', function(e) {
            const selectedPersonnel = Array.from(personnelCheckboxes).filter(cb => cb.checked).map(cb => cb.value);
            if (selectedPersonnel.length === 0) {
                e.preventDefault();
                alert('请至少选择一个人员');
                return;
            }
            
            // 动态添加选中的人员ID到表单中
            const personnelInputs = document.getElementById('departmentPersonnelInputs');
            personnelInputs.innerHTML = '';
            selectedPersonnel.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_personnel[]';
                input.value = id;
                personnelInputs.appendChild(input);
            });
        });
    }
    
    // 批量修改性别表单提交处理
    const batchGenderForm = document.getElementById('batchGenderForm');
    if (batchGenderForm) {
        batchGenderForm.addEventListener('submit', function(e) {
            const selectedPersonnel = Array.from(personnelCheckboxes).filter(cb => cb.checked).map(cb => cb.value);
            if (selectedPersonnel.length === 0) {
                e.preventDefault();
                alert('请至少选择一个人员');
                return;
            }
            
            // 动态添加选中的人员ID到表单中
            const personnelInputs = document.getElementById('genderPersonnelInputs');
            personnelInputs.innerHTML = '';
            selectedPersonnel.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_personnel[]';
                input.value = id;
                personnelInputs.appendChild(input);
            });
        });
    }
    
    // 批量修改工作证类型表单提交处理
    const batchBadgeTypeForm = document.getElementById('batchBadgeTypeForm');
    if (batchBadgeTypeForm) {
        batchBadgeTypeForm.addEventListener('submit', function(e) {
            const selectedPersonnel = Array.from(personnelCheckboxes).filter(cb => cb.checked).map(cb => cb.value);
            if (selectedPersonnel.length === 0) {
                e.preventDefault();
                alert('请至少选择一个人员');
                return;
            }
            
            // 动态添加选中的人员ID到表单中
            const personnelInputs = document.getElementById('badgeTypePersonnelInputs');
            personnelInputs.innerHTML = '';
            selectedPersonnel.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_personnel[]';
                input.value = id;
                personnelInputs.appendChild(input);
            });
        });
    }
    
    // 取消选择按钮
    if (clearSelectionBtn) {
        clearSelectionBtn.addEventListener('click', function() {
            personnelCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = false;
            }
            updateBatchButtons();
            
            // 清空批量选择框
            if (batchDepartmentSelect) {
                batchDepartmentSelect.value = '';
            }
            if (batchGenderSelect) {
                batchGenderSelect.value = '';
            }
            if (batchBadgeTypeSelect) {
                batchBadgeTypeSelect.value = '';
            }
        });
    }
    
    // 初始化按钮状态
    updateBatchButtons();
});
</script>

<?php
// 包含页脚文件
include 'includes/footer.php';
?>