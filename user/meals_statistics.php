<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

session_start();
requireLogin();
checkProjectPermission($_SESSION['project_id']);

// 获取数据库连接
$database = new Database();
$db = $database->getConnection();

$projectId = $_SESSION['project_id'];
$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? 0;

// 获取项目信息，包括开始日期和结束日期
$project_query = "SELECT start_date, end_date FROM projects WHERE id = :project_id";
$project_stmt = $db->prepare($project_query);
$project_stmt->bindParam(':project_id', $projectId);
$project_stmt->execute();
$project_info = $project_stmt->fetch(PDO::FETCH_ASSOC);

// 如果查询到项目信息，则使用项目的开始和结束日期作为默认值，否则使用当前逻辑
$default_date_from = $project_info['start_date'] ?? date('Y-m-01');
$default_date_to = $project_info['end_date'] ?? date('Y-m-d');

// 处理删除操作
if ($action == 'delete' && $id) {
    try {
        $delete_query = "DELETE FROM meal_reports WHERE id = :id AND project_id = :project_id";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->bindParam(':id', $id);
        $delete_stmt->bindParam(':project_id', $projectId);
        
        if ($delete_stmt->execute()) {
            $_SESSION['message'] = "报餐记录删除成功";
        } else {
            $_SESSION['error'] = "删除失败，请重试";
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "删除失败：" . $e->getMessage();
    }
    
    // 重定向回统计页面，保持筛选条件
    $redirect_url = "meals_statistics.php";
    $params = [];
    
    if (!empty($_GET['date_from'])) $params[] = "date_from=" . urlencode($_GET['date_from']);
    if (!empty($_GET['date_to'])) $params[] = "date_to=" . urlencode($_GET['date_to']);
    if (!empty($_GET['meal_type'])) $params[] = "meal_type=" . urlencode($_GET['meal_type']);
    if (!empty($_GET['department_id'])) $params[] = "department_id=" . urlencode($_GET['department_id']);
    
    if (!empty($params)) {
        $redirect_url .= "?" . implode("&", $params);
    }
    
    header("Location: " . $redirect_url);
    exit;
}

// 处理批量删除操作
if ($action == 'batch_delete' && isset($_POST['selected_ids'])) {
    try {
        // 添加调试信息
        error_log("批量删除开始: action={$action}, project_id={$projectId}");
        error_log("POST数据: " . print_r($_POST, true));
        
        $selected_ids = $_POST['selected_ids'];
        if (!is_array($selected_ids)) {
            $selected_ids = explode(',', $selected_ids);
        }
        
        // 确保所有ID都是整数
        $selected_ids = array_map('intval', $selected_ids);
        
        // 过滤掉空值和0值
        $selected_ids = array_filter($selected_ids, function($id) {
            return $id > 0;
        });
        
        error_log("处理后的selected_ids: " . print_r($selected_ids, true));
        
        if (!empty($selected_ids)) {
            // 首先验证这些记录确实属于当前项目
            $placeholders = str_repeat('?,', count($selected_ids) - 1) . '?';
            $validate_query = "SELECT id FROM meal_reports WHERE id IN ($placeholders) AND project_id = ?";
            $validate_stmt = $db->prepare($validate_query);
            $validate_params = array_merge($selected_ids, [$projectId]);
            $validate_stmt->execute($validate_params);
            $valid_ids = $validate_stmt->fetchAll(PDO::FETCH_COLUMN, 0);
            
            error_log("验证后的有效ID: " . print_r($valid_ids, true));
            
            if (!empty($valid_ids)) {
                // 构建IN查询语句
                $placeholders = str_repeat('?,', count($valid_ids) - 1) . '?';
                $delete_query = "DELETE FROM meal_reports WHERE id IN ($placeholders) AND project_id = ?";
                
                error_log("删除SQL: {$delete_query}");
                error_log("参数: " . print_r(array_merge($valid_ids, [$projectId]), true));
                
                $delete_stmt = $db->prepare($delete_query);
                $params = array_merge($valid_ids, [$projectId]);
                $result = $delete_stmt->execute($params);
                
                if ($result) {
                    $deleted_count = $delete_stmt->rowCount();
                    $_SESSION['message'] = "成功删除 " . $deleted_count . " 条报餐记录";
                    
                    // 记录删除日志
                    error_log("批量删除成功: 项目ID={$projectId}, 删除记录数={$deleted_count}, 删除的ID=" . implode(',', $valid_ids));
                } else {
                    $_SESSION['error'] = "批量删除执行失败";
                    error_log("批量删除执行失败: 项目ID={$projectId}");
                    error_log("错误信息: " . print_r($delete_stmt->errorInfo(), true));
                }
            } else {
                $_SESSION['error'] = "选择的记录不属于当前项目";
                error_log("选择的记录不属于当前项目: project_id={$projectId}");
            }
        } else {
            $_SESSION['error'] = "未选择任何有效记录";
            error_log("未选择任何有效记录");
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "批量删除失败：" . $e->getMessage();
        error_log("批量删除错误: " . $e->getMessage()); // 记录错误日志
        error_log("错误跟踪: " . $e->getTraceAsString());
    }
    
    // 重定向回统计页面，保持筛选条件
    $redirect_url = "meals_statistics.php";
    $params = [];
    
    if (!empty($_GET['date_from'])) $params[] = "date_from=" . urlencode($_GET['date_from']);
    if (!empty($_GET['date_to'])) $params[] = "date_to=" . urlencode($_GET['date_to']);
    if (!empty($_GET['meal_type'])) $params[] = "meal_type=" . urlencode($_GET['meal_type']);
    if (!empty($_GET['department_id'])) $params[] = "department_id=" . urlencode($_GET['department_id']);
    
    if (!empty($params)) {
        $redirect_url .= "?" . implode("&", $params);
    }
    
    error_log("重定向前的URL: {$redirect_url}");
    header("Location: " . $redirect_url);
    exit;
}

// 处理编辑操作
if ($action == 'edit' && $id) {
    // 获取要编辑的记录详情
    $edit_query = "SELECT mr.*, p.name as personnel_name, d.name as department_name 
                   FROM meal_reports mr
                   JOIN personnel p ON mr.personnel_id = p.id
                   LEFT JOIN project_department_personnel pdp ON (mr.project_id = pdp.project_id AND p.id = pdp.personnel_id)
                   LEFT JOIN departments d ON pdp.department_id = d.id
                   WHERE mr.id = :id AND mr.project_id = :project_id";
    $edit_stmt = $db->prepare($edit_query);
    $edit_stmt->bindParam(':id', $id);
    $edit_stmt->bindParam(':project_id', $projectId);
    $edit_stmt->execute();
    $edit_record = $edit_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$edit_record) {
        $_SESSION['error'] = "未找到要编辑的记录";
        header("Location: meals_statistics.php");
        exit;
    }
    
    // 处理表单提交
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            $meal_date = $_POST['meal_date'];
            $meal_type = $_POST['meal_type'];
            $meal_count = intval($_POST['meal_count']);
            $package_id = !empty($_POST['package_id']) ? intval($_POST['package_id']) : null;
            
            $update_query = "UPDATE meal_reports SET meal_date = :meal_date, meal_type = :meal_type, 
                             meal_count = :meal_count, package_id = :package_id 
                             WHERE id = :id AND project_id = :project_id";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(':meal_date', $meal_date);
            $update_stmt->bindParam(':meal_type', $meal_type);
            $update_stmt->bindParam(':meal_count', $meal_count);
            $update_stmt->bindParam(':package_id', $package_id);
            $update_stmt->bindParam(':id', $id);
            $update_stmt->bindParam(':project_id', $projectId);
            
            if ($update_stmt->execute()) {
                $_SESSION['message'] = "报餐记录更新成功";
            } else {
                $_SESSION['error'] = "更新失败，请重试";
            }
        } catch (Exception $e) {
            $_SESSION['error'] = "更新失败：" . $e->getMessage();
        }
        
        // 重定向回统计页面，保持筛选条件
        $redirect_url = "meals_statistics.php";
        $params = [];
        
        if (!empty($_GET['date_from'])) $params[] = "date_from=" . urlencode($_GET['date_from']);
        if (!empty($_GET['date_to'])) $params[] = "date_to=" . urlencode($_GET['date_to']);
        if (!empty($_GET['meal_type'])) $params[] = "meal_type=" . urlencode($_GET['meal_type']);
        if (!empty($_GET['department_id'])) $params[] = "department_id=" . urlencode($_GET['department_id']);
        
        if (!empty($params)) {
            $redirect_url .= "?" . implode("&", $params);
        }
        
        header("Location: " . $redirect_url);
        exit;
    }
}

// 获取筛选参数，使用项目日期作为默认值
$filters = [
    'date_from' => $_GET['date_from'] ?? $default_date_from,
    'date_to' => $_GET['date_to'] ?? $default_date_to,
    'meal_type' => $_GET['meal_type'] ?? '',
    'department_id' => $_GET['department_id'] ?? ''
];

// 获取部门列表
$dept_query = "SELECT DISTINCT d.id, d.name 
               FROM departments d 
               JOIN project_department_personnel pdp ON d.id = pdp.department_id 
               WHERE pdp.project_id = :project_id 
               ORDER BY d.name";
$dept_stmt = $db->prepare($dept_query);
$dept_stmt->bindParam(':project_id', $projectId);
$dept_stmt->execute();
$departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);

// 构建查询条件
function buildWhereClause($projectId, $filters) {
    $where_conditions = ['mr.project_id = :project_id'];
    $params = [':project_id' => $projectId];
    
    if (!empty($filters['date_from'])) {
        $where_conditions[] = 'mr.meal_date >= :date_from';
        $params[':date_from'] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $where_conditions[] = 'mr.meal_date <= :date_to';
        $params[':date_to'] = $filters['date_to'];
    }
    
    if (!empty($filters['meal_type'])) {
        $where_conditions[] = 'mr.meal_type = :meal_type';
        $params[':meal_type'] = $filters['meal_type'];
    }
    
    if (!empty($filters['department_id'])) {
        $where_conditions[] = 'pdp.department_id = :department_id';
        $params[':department_id'] = $filters['department_id'];
    }
    
    return [
        'where' => implode(' AND ', $where_conditions),
        'params' => $params
    ];
}

// 获取每日统计
function getDailyStatistics($projectId, $db, $filters) {
    $clause = buildWhereClause($projectId, $filters);
    
    $query = "SELECT 
                mr.id,
                mr.meal_date,
                mr.meal_type,
                COUNT(*) as person_count,
                SUM(mr.meal_count) as total_meals,
                mp.name as package_name
              FROM meal_reports mr
              LEFT JOIN meal_packages mp ON mr.package_id = mp.id
              LEFT JOIN personnel p ON mr.personnel_id = p.id
              LEFT JOIN project_department_personnel pdp ON (mr.project_id = pdp.project_id AND p.id = pdp.personnel_id)
              WHERE {$clause['where']}
              GROUP BY mr.id, mr.meal_date, mr.meal_type, mp.name
              ORDER BY mr.meal_date DESC, mr.meal_type";
    
    $stmt = $db->prepare($query);
    $stmt->execute($clause['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 获取部门统计
function getDepartmentStatistics($projectId, $db, $filters) {
    $clause = buildWhereClause($projectId, $filters);
    
    $query = "SELECT 
                mr.id,
                d.name as department_name,
                mr.meal_type,
                COUNT(*) as person_count,
                SUM(mr.meal_count) as total_meals
              FROM meal_reports mr
              JOIN personnel p ON mr.personnel_id = p.id
              JOIN project_department_personnel pdp ON (mr.project_id = pdp.project_id AND p.id = pdp.personnel_id)
              JOIN departments d ON pdp.department_id = d.id
              WHERE {$clause['where']}
              GROUP BY mr.id, d.name, mr.meal_type
              ORDER BY d.name, mr.meal_type";
    
    $stmt = $db->prepare($query);
    $stmt->execute($clause['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 获取套餐统计
function getPackageStatistics($projectId, $db, $filters) {
    $clause = buildWhereClause($projectId, $filters);
    
    $query = "SELECT 
                mr.id,
                mp.name as package_name,
                mr.meal_type,
                COUNT(*) as order_count,
                SUM(mr.meal_count) as total_meals,
                mp.price,
                SUM(mr.meal_count * mp.price) as total_amount
              FROM meal_reports mr
              JOIN meal_packages mp ON mr.package_id = mp.id
              LEFT JOIN personnel p ON mr.personnel_id = p.id
              LEFT JOIN project_department_personnel pdp ON (mr.project_id = pdp.project_id AND p.id = pdp.personnel_id)
              WHERE {$clause['where']} AND mr.package_id IS NOT NULL
              GROUP BY mr.id, mp.name, mr.meal_type, mp.price
              ORDER BY total_amount DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($clause['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 获取详细记录（用于删除确认）
function getDetailedRecords($projectId, $db, $filters) {
    $clause = buildWhereClause($projectId, $filters);
    
    $query = "SELECT 
                mr.id,
                mr.meal_date,
                mr.meal_type,
                mr.meal_count,
                p.name as personnel_name,
                d.name as department_name,
                mp.name as package_name
              FROM meal_reports mr
              JOIN personnel p ON mr.personnel_id = p.id
              LEFT JOIN project_department_personnel pdp ON (mr.project_id = pdp.project_id AND p.id = pdp.personnel_id)
              LEFT JOIN departments d ON pdp.department_id = d.id
              LEFT JOIN meal_packages mp ON mr.package_id = mp.id
              WHERE {$clause['where']}
              ORDER BY mr.meal_date DESC, mr.meal_type";
    
    $stmt = $db->prepare($query);
    $stmt->execute($clause['params']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 获取统计数据
$dailyStats = getDailyStatistics($projectId, $db, $filters);
$departmentStats = getDepartmentStatistics($projectId, $db, $filters);
$packageStats = getPackageStatistics($projectId, $db, $filters);
$detailedRecords = getDetailedRecords($projectId, $db, $filters);

// 显示消息
$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);

// 设置页面变量
$page_title = '报餐统计 - ' . ($_SESSION['project_name'] ?? '项目');
$active_page = 'meals_statistics';
$show_page_title = '报餐统计分析';
$page_icon = 'graph-up';

include 'includes/header.php';
?>

<style>
/* 隐藏指定的统计区域 */
.d-none {
    display: none !important;
}
</style>

<div class="container-fluid mt-4">
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- 筛选条件 -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="bi bi-funnel me-2"></i>筛选条件
            </h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">开始日期</label>
                    <input type="date" class="form-control" name="date_from" 
                           value="<?php echo $filters['date_from']; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">结束日期</label>
                    <input type="date" class="form-control" name="date_to" 
                           value="<?php echo $filters['date_to']; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">餐类型</label>
                    <select class="form-select" name="meal_type">
                        <option value="">全部</option>
                        <?php foreach(['早餐', '午餐', '晚餐', '宵夜'] as $type): ?>
                            <option value="<?php echo $type; ?>" 
                                    <?php echo $filters['meal_type'] === $type ? 'selected' : ''; ?>>
                                <?php echo $type; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">部门</label>
                    <select class="form-select" name="department_id">
                        <option value="">全部</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>"
                                    <?php echo $filters['department_id'] == $dept['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="bi bi-search me-1"></i>查询
                    </button>
                    <a href="meals_statistics.php" class="btn btn-outline-secondary">重置</a>
                    <a href="meals_new.php" class="btn btn-success ms-2">
                        <i class="bi bi-arrow-left me-1"></i>返回报餐
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- 详细记录 -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-list me-2"></i>详细记录
                    </h5>
                    <span class="badge bg-primary"><?php echo count($detailedRecords); ?> 条记录</span>
                </div>
                <div class="card-body">
                    <?php if ($action == 'edit' && isset($edit_record)): ?>
                        <!-- 编辑表单 -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">
                                    <i class="bi bi-pencil me-2"></i>编辑报餐记录
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="edit">
                                    <input type="hidden" name="id" value="<?php echo $edit_record['id']; ?>">
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label">人员</label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($edit_record['personnel_name']); ?>" disabled>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">部门</label>
                                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($edit_record['department_name'] ?? '未分配'); ?>" disabled>
                                        </div>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label">日期 *</label>
                                            <input type="date" class="form-control" name="meal_date" value="<?php echo $edit_record['meal_date']; ?>" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">餐类型 *</label>
                                            <select class="form-select" name="meal_type" required>
                                                <?php foreach(['早餐', '午餐', '晚餐', '宵夜'] as $type): ?>
                                                    <option value="<?php echo $type; ?>" <?php echo $edit_record['meal_type'] === $type ? 'selected' : ''; ?>>
                                                        <?php echo $type; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label">人数 *</label>
                                            <input type="number" class="form-control" name="meal_count" value="<?php echo $edit_record['meal_count']; ?>" min="1" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">套餐</label>
                                            <select class="form-select" name="package_id">
                                                <option value="">无套餐</option>
                                                <?php 
                                                // 获取项目套餐列表
                                                $package_query = "SELECT id, name FROM meal_packages WHERE project_id = :project_id ORDER BY name";
                                                $package_stmt = $db->prepare($package_query);
                                                $package_stmt->bindParam(':project_id', $projectId);
                                                $package_stmt->execute();
                                                $packages = $package_stmt->fetchAll(PDO::FETCH_ASSOC);
                                                
                                                foreach ($packages as $package): ?>
                                                    <option value="<?php echo $package['id']; ?>" <?php echo $edit_record['package_id'] == $package['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($package['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between">
                                        <a href="meals_statistics.php?<?php echo http_build_query(array_filter([
                                            'date_from' => $filters['date_from'],
                                            'date_to' => $filters['date_to'],
                                            'meal_type' => $filters['meal_type'],
                                            'department_id' => $filters['department_id']
                                        ])); ?>" class="btn btn-secondary">
                                            <i class="bi bi-arrow-left me-1"></i>返回
                                        </a>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-save me-1"></i>保存
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php elseif (empty($detailedRecords)): ?>
                        <p class="text-muted text-center py-4">暂无数据</p>
                    <?php else: ?>
                        <form method="POST" id="batchForm" action="meals_statistics.php?action=batch_delete">
                            <input type="hidden" name="action" value="batch_delete">
                            <?php if (!empty($filters['date_from'])): ?>
                                <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>">
                            <?php endif; ?>
                            <?php if (!empty($filters['date_to'])): ?>
                                <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($filters['date_to']); ?>">
                            <?php endif; ?>
                            <?php if (!empty($filters['meal_type'])): ?>
                                <input type="hidden" name="meal_type" value="<?php echo htmlspecialchars($filters['meal_type']); ?>">
                            <?php endif; ?>
                            <?php if (!empty($filters['department_id'])): ?>
                                <input type="hidden" name="department_id" value="<?php echo htmlspecialchars($filters['department_id']); ?>">
                            <?php endif; ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-dark">
                                        <tr>
                                            <th width="5%">
                                                <input type="checkbox" id="selectAll" class="form-check-input">
                                            </th>
                                            <th>日期</th>
                                            <th>人员</th>
                                            <th>部门</th>
                                            <th>餐类型</th>
                                            <th>套餐</th>
                                            <th>人数</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($detailedRecords as $record): ?>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" name="selected_ids[]" value="<?php echo $record['id']; ?>" class="form-check-input">
                                                </td>
                                                <td><?php echo $record['meal_date']; ?></td>
                                                <td><?php echo htmlspecialchars($record['personnel_name']); ?></td>
                                                <td>
                                                    <span class="badge bg-secondary">
                                                        <?php echo htmlspecialchars($record['department_name'] ?? '未分配'); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $type_colors = ['早餐'=>'warning', '午餐'=>'primary', '晚餐'=>'success', '宵夜'=>'info'];
                                                    $color = $type_colors[$record['meal_type']] ?? 'secondary';
                                                    ?>
                                                    <span class="badge bg-<?php echo $color; ?>">
                                                        <?php echo $record['meal_type']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($record['package_name']): ?>
                                                        <span class="text-primary">
                                                            <?php echo htmlspecialchars($record['package_name']); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo $record['meal_count']; ?></td>
                                                <td>
                                                    <a href="meals_statistics.php?action=edit&id=<?php echo $record['id']; ?>&<?php 
                                                       echo http_build_query(array_filter([
                                                           'date_from' => $filters['date_from'],
                                                           'date_to' => $filters['date_to'],
                                                           'meal_type' => $filters['meal_type'],
                                                           'department_id' => $filters['department_id']
                                                       ])); ?>" 
                                                       class="btn btn-sm btn-primary">
                                                        <i class="bi bi-pencil"></i> 编辑
                                                    </a>
                                                    <a href="meals_statistics.php?action=delete&id=<?php echo $record['id']; ?>&<?php 
                                                       echo http_build_query(array_filter([
                                                           'date_from' => $filters['date_from'],
                                                           'date_to' => $filters['date_to'],
                                                           'meal_type' => $filters['meal_type'],
                                                           'department_id' => $filters['department_id']
                                                       ])); ?>" 
                                                       class="btn btn-sm btn-danger" 
                                                       onclick="return confirm('确定要删除这条报餐记录吗？\n人员：<?php echo htmlspecialchars($record['personnel_name']); ?>\n日期：<?php echo $record['meal_date']; ?>\n餐类型：<?php echo $record['meal_type']; ?>')">
                                                        <i class="bi bi-trash"></i> 删除
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-2">
                                <button type="button" class="btn btn-danger" onclick="batchDelete()">
                                    <i class="bi bi-trash"></i> 批量删除
                                </button>
                            </div>
                        </form>
                        <script>
                            // 全选/取消全选功能
                            document.getElementById('selectAll').addEventListener('change', function() {
                                const checkboxes = document.querySelectorAll('input[name="selected_ids[]"]');
                                checkboxes.forEach(checkbox => {
                                    checkbox.checked = this.checked;
                                });
                            });
                            
                            // 批量删除功能
                            function batchDelete() {
                                const selectedIds = document.querySelectorAll('input[name="selected_ids[]"]:checked');
                                if (selectedIds.length === 0) {
                                    alert('请至少选择一条记录');
                                    return;
                                }
                                
                                if (confirm(`确定要删除选中的 ${selectedIds.length} 条报餐记录吗？`)) {
                                    // 直接通过表单提交，不修改action
                                    const form = document.getElementById('batchForm');
                                    // 确保表单使用POST方法
                                    form.method = 'POST';
                                    form.submit();
                                }
                            }
                            
                            // 页面加载完成后检查表单
                            document.addEventListener('DOMContentLoaded', function() {
                                const form = document.getElementById('batchForm');
                                if (form) {
                                    console.log('批量删除表单已加载');
                                    console.log('表单method:', form.method);
                                    console.log('表单action:', form.action);
                                }
                            });
                        </script>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- 每日统计 -->
        <div class="col-md-6 mb-4 d-none">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0 d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-calendar3 me-2"></i>每日用餐统计</span>
                        <?php if (!empty($dailyStats)): ?>
                            <span class="badge bg-light text-primary rounded-pill">
                                <?php echo count(array_unique(array_column($dailyStats, 'meal_date'))); ?> 天
                            </span>
                        <?php endif; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($dailyStats)): ?>
                        <p class="text-muted text-center py-4">暂无数据</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped">
                                <thead class="table-light">
                                    <tr>
                                        <th width="25%" class="text-center">日期</th>
                                        <th width="25%" class="text-center">餐类型</th>
                                        <th width="25%" class="text-center">人数</th>
                                        <th width="25%" class="text-center">总餐数</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $current_date = '';
                                    $date_totals = [];
                                    $meal_type_totals = ['早餐' => 0, '午餐' => 0, '晚餐' => 0, '宵夜' => 0];
                                    $total_persons = 0;
                                    $total_meals = 0;
                                    
                                    // 计算总计
                                    foreach ($dailyStats as $stat) {
                                        if (!isset($date_totals[$stat['meal_date']])) {
                                            $date_totals[$stat['meal_date']] = ['persons' => 0, 'meals' => 0];
                                        }
                                        $date_totals[$stat['meal_date']]['persons'] += $stat['person_count'];
                                        $date_totals[$stat['meal_date']]['meals'] += $stat['total_meals'];
                                        $meal_type_totals[$stat['meal_type']] += $stat['total_meals'];
                                        $total_persons += $stat['person_count'];
                                        $total_meals += $stat['total_meals'];
                                    }
                                    
                                    // 按日期分组数据
                                    $grouped_stats = [];
                                    foreach ($dailyStats as $stat) {
                                        $grouped_stats[$stat['meal_date']][] = $stat;
                                    }
                                    
                                    // 遍历分组后的数据
                                    foreach ($grouped_stats as $date => $stats): 
                                    ?>
                                        <tr>
                                            <td class="text-center align-middle">
                                                <strong><?php echo date('m-d', strtotime($date)); ?></strong>
                                                <div class="small text-muted"><?php echo date('Y', strtotime($date)); ?></div>
                                            </td>
                                            <td class="text-center align-middle">
                                                <div class="d-flex flex-wrap justify-content-center align-items-center">
                                                <?php 
                                                // 合并显示餐类型和对应人数，相同的只显示一次
                                                $merged_stats = [];
                                                foreach ($stats as $stat) {
                                                    $key = $stat['meal_type'] . '_' . $stat['person_count'];
                                                    if (!isset($merged_stats[$key])) {
                                                        $merged_stats[$key] = [
                                                            'meal_type' => $stat['meal_type'],
                                                            'person_count' => $stat['person_count'],
                                                            'count' => 1
                                                        ];
                                                    } else {
                                                        $merged_stats[$key]['count']++;
                                                    }
                                                }
                                                
                                                foreach ($merged_stats as $merged_stat): 
                                                    $type_colors = ['早餐'=>'warning', '午餐'=>'primary', '晚餐'=>'success', '宵夜'=>'info'];
                                                    $color = $type_colors[$merged_stat['meal_type']] ?? 'secondary';
                                                ?>
                                                    <div class="d-inline-flex align-items-center me-2 mb-1">
                                                        <span class="badge bg-<?php echo $color; ?> px-1 py-0" style="font-size: 0.7rem;">
                                                            <?php echo $merged_stat['meal_type']; ?>
                                                        </span>
                                                        <span class="badge bg-primary rounded-pill px-1 py-0 ms-1" style="font-size: 0.7rem;">
                                                            <?php echo $merged_stat['person_count']; ?>
                                                            <?php if ($merged_stat['count'] > 1): ?>
                                                                ×<?php echo $merged_stat['count']; ?>
                                                            <?php endif; ?>
                                                        </span>
                                                    </div>
                                                <?php endforeach; ?>
                                                </div>
                                            </td>
                                            <td class="text-center align-middle fw-bold">
                                                <?php echo $date_totals[$date]['meals']; ?> 份
                                            </td>
                                        </tr>
                                        <tr class="table-light">
                                            <td class="text-end" colspan="1"><strong><?php echo date('m-d', strtotime($date)); ?> 小计：</strong></td>
                                            <td class="text-center"><?php echo $date_totals[$date]['persons']; ?> 人</td>
                                            <td class="text-center fw-bold"><?php echo $date_totals[$date]['meals']; ?> 份</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-dark">
                                    <tr>
                                        <td colspan="2" class="text-end"><strong>总计：</strong></td>
                                        <td class="text-center"><?php echo $total_persons; ?> 人</td>
                                        <td class="text-center fw-bold"><?php echo $total_meals; ?> 份</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        
                        <!-- 餐类型分布统计 -->
                        <div class="mt-3">
                            <h6 class="border-bottom pb-2"><i class="bi bi-pie-chart me-2"></i>餐类型分布</h6>
                            <div class="row text-center">
                                <?php foreach ($meal_type_totals as $type => $count): if ($count > 0): ?>
                                    <div class="col-3">
                                        <?php 
                                        $type_colors = ['早餐'=>'warning', '午餐'=>'primary', '晚餐'=>'success', '宵夜'=>'info'];
                                        $color = $type_colors[$type] ?? 'secondary';
                                        ?>
                                        <div class="py-2 px-1 rounded bg-light">
                                            <span class="badge bg-<?php echo $color; ?> d-block mb-1"><?php echo $type; ?></span>
                                            <span class="d-block fw-bold"><?php echo $count; ?> 份</span>
                                            <span class="small text-muted"><?php echo round(($count / $total_meals) * 100, 1); ?>%</span>
                                        </div>
                                    </div>
                                <?php endif; endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 部门统计 -->
        <div class="col-md-6 mb-4 d-none">
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0 d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-people me-2"></i>部门用餐统计</span>
                        <?php if (!empty($departmentStats)): ?>
                            <span class="badge bg-light text-success rounded-pill">
                                <?php echo count(array_unique(array_column($departmentStats, 'department_name'))); ?> 个部门
                            </span>
                        <?php endif; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($departmentStats)): ?>
                        <p class="text-muted text-center py-4">暂无数据</p>
                    <?php else: ?>
                        <?php
                        // 计算部门总计和餐类型总计
                        $dept_totals = [];
                        $meal_type_totals = ['早餐' => 0, '午餐' => 0, '晚餐' => 0, '宵夜' => 0];
                        $total_persons = 0;
                        $total_meals = 0;
                        
                        foreach ($departmentStats as $stat) {
                            if (!isset($dept_totals[$stat['department_name']])) {
                                $dept_totals[$stat['department_name']] = ['persons' => 0, 'meals' => 0];
                            }
                            $dept_totals[$stat['department_name']]['persons'] += $stat['person_count'];
                            $dept_totals[$stat['department_name']]['meals'] += $stat['total_meals'];
                            $meal_type_totals[$stat['meal_type']] += $stat['total_meals'];
                            $total_persons += $stat['person_count'];
                            $total_meals += $stat['total_meals'];
                        }
                        
                        // 按总餐数排序部门
                        arsort($dept_totals);
                        ?>
                        
                        <!-- 部门排名卡片 -->
                        <div class="mb-3">
                            <h6 class="border-bottom pb-2 mb-3"><i class="bi bi-bar-chart me-2"></i>部门用餐排名</h6>
                            <div class="row">
                                <?php 
                                $rank = 1;
                                foreach (array_slice($dept_totals, 0, 4) as $dept_name => $totals): 
                                ?>
                                    <div class="col-md-6 col-lg-3 mb-2">
                                        <div class="card h-100 border-0 bg-light">
                                            <div class="card-body p-2 text-center">
                                                <div class="position-absolute top-0 start-0 p-1">
                                                    <span class="badge <?php echo $rank <= 3 ? 'bg-danger' : 'bg-secondary'; ?> rounded-circle">
                                                        <?php echo $rank++; ?>
                                                    </span>
                                                </div>
                                                <h6 class="card-title text-truncate mb-1" title="<?php echo htmlspecialchars($dept_name); ?>">
                                                    <?php echo htmlspecialchars($dept_name); ?>
                                                </h6>
                                                <div class="d-flex justify-content-around">
                                                    <div>
                                                        <small class="text-muted">人数</small>
                                                        <div class="fw-bold"><?php echo $totals['persons']; ?></div>
                                                    </div>
                                                    <div>
                                                        <small class="text-muted">总餐数</small>
                                                        <div class="fw-bold text-success"><?php echo $totals['meals']; ?></div>
                                                    </div>
                                                </div>
                                                <div class="progress mt-2" style="height: 5px;">
                                                    <div class="progress-bar bg-success" style="width: <?php echo round(($totals['meals'] / max(array_column($dept_totals, 'meals'))) * 100); ?>%"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover table-striped">
                                <thead class="table-light">
                                    <tr>
                                        <th width="40%" class="text-center">部门</th>
                                        <th width="20%" class="text-center">餐类型</th>
                                        <th width="20%" class="text-center">人数</th>
                                        <th width="20%" class="text-center">总餐数</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    // 按部门分组数据
                                    $grouped_dept_stats = [];
                                    foreach ($departmentStats as $stat) {
                                        $grouped_dept_stats[$stat['department_name']][] = $stat;
                                    }
                                    
                                    // 遍历分组后的数据
                                    foreach ($grouped_dept_stats as $dept_name => $stats): 
                                    ?>
                                        <tr>
                                            <td class="align-middle">
                                                <span class="fw-bold"><?php echo htmlspecialchars($dept_name); ?></span>
                                            </td>
                                            <td class="text-center align-middle" colspan="2">
                                                <div class="d-flex flex-wrap justify-content-center align-items-center">
                                                <?php 
                                                // 合并显示餐类型和对应人数，相同的只显示一次
                                                $merged_stats = [];
                                                foreach ($stats as $stat) {
                                                    $key = $stat['meal_type'] . '_' . $stat['person_count'];
                                                    if (!isset($merged_stats[$key])) {
                                                        $merged_stats[$key] = [
                                                            'meal_type' => $stat['meal_type'],
                                                            'person_count' => $stat['person_count'],
                                                            'count' => 1
                                                        ];
                                                    } else {
                                                        $merged_stats[$key]['count']++;
                                                    }
                                                }
                                                
                                                foreach ($merged_stats as $merged_stat): 
                                                    $type_colors = ['早餐'=>'warning', '午餐'=>'primary', '晚餐'=>'success', '宵夜'=>'info'];
                                                    $color = $type_colors[$merged_stat['meal_type']] ?? 'secondary';
                                                ?>
                                                    <div class="d-inline-flex align-items-center me-2 mb-1">
                                                        <span class="badge bg-<?php echo $color; ?> px-1 py-0" style="font-size: 0.7rem;">
                                                            <?php echo $merged_stat['meal_type']; ?>
                                                        </span>
                                                        <span class="badge bg-primary rounded-pill px-1 py-0 ms-1" style="font-size: 0.7rem;">
                                                            <?php echo $merged_stat['person_count']; ?>
                                                            <?php if ($merged_stat['count'] > 1): ?>
                                                                ×<?php echo $merged_stat['count']; ?>
                                                            <?php endif; ?>
                                                        </span>
                                                    </div>
                                                <?php endforeach; ?>
                                                </div>
                                            </td>
                                            <td class="text-center align-middle fw-bold">
                                                <?php echo $dept_totals[$dept_name]['meals']; ?> 份
                                            </td>
                                        </tr>
                                        <tr class="table-light">
                                            <td class="text-end"><strong><?php echo htmlspecialchars($dept_name); ?> 小计：</strong></td>
                                            <td class="text-center" colspan="2"><?php echo $dept_totals[$dept_name]['persons']; ?> 人</td>
                                            <td class="text-center fw-bold"><?php echo $dept_totals[$dept_name]['meals']; ?> 份</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-dark">
                                    <tr>
                                        <td colspan="2" class="text-end"><strong>总计：</strong></td>
                                        <td class="text-center"><?php echo $total_persons; ?> 人</td>
                                        <td class="text-center fw-bold"><?php echo $total_meals; ?> 份</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($packageStats)): ?>
    <!-- 套餐统计 -->
    <div class="row d-none">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0 d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-box me-2"></i>套餐订购统计</span>
                        <div>
                            <span class="badge bg-light text-info rounded-pill me-2">
                                <?php echo count(array_unique(array_column($packageStats, 'package_name'))); ?> 种套餐
                            </span>
                            <span class="badge bg-light text-danger rounded-pill">
                                ¥<?php echo number_format(array_sum(array_column($packageStats, 'total_amount')), 2); ?>
                            </span>
                        </div>
                    </h5>
                </div>
                <div class="card-body">
                    <?php
                    // 计算套餐总计和餐类型总计
                    $package_totals = [];
                    $meal_type_totals = ['早餐' => 0, '午餐' => 0, '晚餐' => 0, '宵夜' => 0];
                    $total_orders = 0;
                    $total_meals = 0;
                    $total_amount = 0;
                    
                    foreach ($packageStats as $stat) {
                        if (!isset($package_totals[$stat['package_name']])) {
                            $package_totals[$stat['package_name']] = [
                                'orders' => 0, 
                                'meals' => 0, 
                                'amount' => 0,
                                'price' => $stat['price']
                            ];
                        }
                        $package_totals[$stat['package_name']]['orders'] += $stat['order_count'];
                        $package_totals[$stat['package_name']]['meals'] += $stat['total_meals'];
                        $package_totals[$stat['package_name']]['amount'] += $stat['total_amount'];
                        $meal_type_totals[$stat['meal_type']] += $stat['total_amount'];
                        $total_orders += $stat['order_count'];
                        $total_meals += $stat['total_meals'];
                        $total_amount += $stat['total_amount'];
                    }
                    
                    // 按总金额排序套餐
                    uasort($package_totals, function($a, $b) {
                        return $b['amount'] <=> $a['amount'];
                    });
                    ?>
                    
                    <!-- 财务摘要卡片 -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="card border-0 bg-light h-100">
                                <div class="card-body text-center">
                                    <h6 class="text-muted mb-2">总订单数</h6>
                                    <h3 class="mb-0"><?php echo $total_orders; ?></h3>
                                    <div class="small text-muted">次</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-0 bg-light h-100">
                                <div class="card-body text-center">
                                    <h6 class="text-muted mb-2">总餐数</h6>
                                    <h3 class="mb-0"><?php echo $total_meals; ?></h3>
                                    <div class="small text-muted">份</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-0 bg-light h-100">
                                <div class="card-body text-center">
                                    <h6 class="text-muted mb-2">平均单价</h6>
                                    <h3 class="mb-0 text-success">¥<?php echo number_format($total_amount / max(1, $total_meals), 2); ?></h3>
                                    <div class="small text-muted">每份</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-0 bg-danger text-white h-100">
                                <div class="card-body text-center">
                                    <h6 class="text-white-50 mb-2">总金额</h6>
                                    <h3 class="mb-0">¥<?php echo number_format($total_amount, 2); ?></h3>
                                    <div class="small text-white-50">元</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 热门套餐排行 -->
                    <div class="mb-4 d-none">
                        <h6 class="border-bottom pb-2 mb-3"><i class="bi bi-star me-2"></i>热门套餐排行</h6>
                        <div class="row">
                            <?php 
                            $rank = 1;
                            foreach (array_slice($package_totals, 0, 3, true) as $package_name => $totals): 
                            ?>
                                <div class="col-md-4 mb-3">
                                    <div class="card h-100 border-0 shadow-sm">
                                        <div class="card-header bg-light py-2 px-3">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <h6 class="mb-0 text-truncate" title="<?php echo htmlspecialchars($package_name); ?>">
                                                    <span class="badge bg-<?php echo $rank <= 3 ? 'danger' : 'secondary'; ?> me-2">
                                                        <?php echo $rank++; ?>
                                                    </span>
                                                    <?php echo htmlspecialchars($package_name); ?>
                                                </h6>
                                                <span class="badge bg-info rounded-pill">
                                                    <?php echo $totals['orders']; ?> 次
                                                </span>
                                            </div>
                                        </div>
                                        <div class="card-body py-2 px-3">
                                            <div class="row text-center">
                                                <div class="col-4">
                                                    <small class="text-muted d-block">单价</small>
                                                    <span class="text-success fw-bold">¥<?php echo number_format($totals['price'], 2); ?></span>
                                                </div>
                                                <div class="col-4">
                                                    <small class="text-muted d-block">总餐数</small>
                                                    <span class="fw-bold"><?php echo $totals['meals']; ?></span>
                                                </div>
                                                <div class="col-4">
                                                    <small class="text-muted d-block">总金额</small>
                                                    <span class="text-danger fw-bold">¥<?php echo number_format($totals['amount'], 2); ?></span>
                                                </div>
                                            </div>
                                            <div class="progress mt-2" style="height: 5px;">
                                                <div class="progress-bar bg-info" style="width: <?php echo round(($totals['amount'] / max(array_column($package_totals, 'amount'))) * 100); ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- 餐类型分布 -->
                    <div class="mb-4 d-none">
                        <h6 class="border-bottom pb-2 mb-3"><i class="bi bi-pie-chart me-2"></i>餐类型消费分布</h6>
                        <div class="row text-center">
                            <?php foreach ($meal_type_totals as $type => $amount): if ($amount > 0): ?>
                                <div class="col-md-3">
                                    <?php 
                                    $type_colors = ['早餐'=>'warning', '午餐'=>'primary', '晚餐'=>'success', '宵夜'=>'info'];
                                    $color = $type_colors[$type] ?? 'secondary';
                                    ?>
                                    <div class="py-3 px-2 rounded bg-light">
                                        <span class="badge bg-<?php echo $color; ?> d-block mb-2 py-2"><?php echo $type; ?></span>
                                        <span class="d-block text-danger fw-bold">¥<?php echo number_format($amount, 2); ?></span>
                                        <span class="small text-muted"><?php echo round(($amount / $total_amount) * 100, 1); ?>%</span>
                                        <div class="progress mt-2" style="height: 5px;">
                                            <div class="progress-bar bg-<?php echo $color; ?>" style="width: <?php echo round(($amount / $total_amount) * 100); ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- 详细数据表格 -->
                    <h6 class="border-bottom pb-2 mb-3 d-none"><i class="bi bi-table me-2"></i>套餐详细数据</h6>
                    <div class="table-responsive d-none">
                        <table class="table table-hover table-striped">
                            <thead class="table-dark">
                                <tr>
                                    <th width="25%">套餐名称</th>
                                    <th width="15%" class="text-center">餐类型</th>
                                    <th width="15%" class="text-center">订购次数</th>
                                    <th width="15%" class="text-center">总餐数</th>
                                    <th width="15%" class="text-center">单价</th>
                                    <th width="15%" class="text-center">总金额</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                // 按套餐名称分组数据
                                $grouped_package_stats = [];
                                foreach ($packageStats as $stat) {
                                    $grouped_package_stats[$stat['package_name']][] = $stat;
                                }
                                
                                // 遍历分组后的数据
                                foreach ($grouped_package_stats as $package_name => $stats): 
                                    // 获取该套餐的第一个价格作为显示价格
                                    $display_price = $stats[0]['price'];
                                ?>
                                    <tr>
                                        <td class="align-middle">
                                            <span class="fw-bold"><?php echo htmlspecialchars($package_name); ?></span>
                                        </td>
                                        <td class="text-center align-middle" colspan="3">
                                            <div class="d-flex flex-wrap justify-content-center align-items-center">
                                            <?php 
                                            // 合并显示相同的餐类型、订购次数和总餐数
                                            $merged_stats = [];
                                            foreach ($stats as $stat) {
                                                $key = $stat['meal_type'] . '_' . $stat['order_count'] . '_' . $stat['total_meals'];
                                                if (!isset($merged_stats[$key])) {
                                                    $merged_stats[$key] = [
                                                        'meal_type' => $stat['meal_type'],
                                                        'order_count' => $stat['order_count'],
                                                        'total_meals' => $stat['total_meals'],
                                                        'count' => 1
                                                    ];
                                                } else {
                                                    $merged_stats[$key]['count']++;
                                                }
                                            }
                                            
                                            foreach ($merged_stats as $stat): 
                                                $type_colors = ['早餐'=>'warning', '午餐'=>'primary', '晚餐'=>'success', '宵夜'=>'info'];
                                                $color = $type_colors[$stat['meal_type']] ?? 'secondary';
                                                $count_display = $stat['count'] > 1 ? ' ×' . $stat['count'] : '';
                                            ?>
                                                <div class="d-inline-flex align-items-center me-2 mb-1">
                                                    <span class="badge bg-<?php echo $color; ?> px-1 py-0" style="font-size: 0.7rem;">
                                                        <?php echo $stat['meal_type']; ?>
                                                    </span>
                                                    <span class="badge bg-info rounded-pill px-1 py-0 ms-1" style="font-size: 0.7rem;">
                                                        <?php echo $stat['order_count']; ?><?php echo $count_display; ?>
                                                    </span>
                                                    <span class="badge bg-primary rounded-pill px-1 py-0 ms-1" style="font-size: 0.7rem;">
                                                        <?php echo $stat['total_meals']; ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <td class="text-center align-middle text-success fw-bold">
                                            ¥<?php echo number_format($display_price, 2); ?>
                                        </td>
                                        <td class="text-center align-middle text-danger fw-bold">
                                            ¥<?php echo number_format($package_totals[$package_name]['amount'], 2); ?>
                                        </td>
                                    </tr>
                                    <tr class="table-light">
                                        <td class="text-end"><strong><?php echo htmlspecialchars($package_name); ?> 小计：</strong></td>
                                        <td class="text-center" colspan="3">
                                            <span class="me-3"><?php echo $package_totals[$package_name]['orders']; ?> 次</span>
                                            <span><?php echo $package_totals[$package_name]['meals']; ?> 份</span>
                                        </td>
                                        <td class="text-center">-</td>
                                        <td class="text-center text-danger fw-bold">¥<?php echo number_format($package_totals[$package_name]['amount'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-dark">
                                <tr class="fw-bold">
                                    <td colspan="2" class="text-end">总计：</td>
                                    <td class="text-center"><?php echo $total_orders; ?> 次</td>
                                    <td class="text-center"><?php echo $total_meals; ?> 份</td>
                                    <td class="text-center text-success">¥<?php echo number_format($total_amount / max(1, $total_meals), 2); ?></td>
                                    <td class="text-center text-danger">
                                        ¥<?php echo number_format($total_amount, 2); ?>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
</body>
</html>

