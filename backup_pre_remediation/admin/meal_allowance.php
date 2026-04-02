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

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();

$message = '';
$error = '';

// 获取项目筛选参数
$filters = [
    'project_id' => isset($_GET['project_id']) ? intval($_GET['project_id']) : 0
];

// 获取所有项目用于选择
$projects_query = "SELECT id, name, code FROM projects ORDER BY name ASC";
$projects_stmt = $db->prepare($projects_query);
$projects_stmt->execute();
$all_projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);

// 只有选择了项目才查询餐费补助数据
$allowanceData = [];
$total_days = 0;
$total_amount = 0;
$default_meal_allowance = 100.00;
$departments = [];

if ($filters['project_id']) {
    // 获取项目信息，包括默认餐费补助金额
    $project_query = "SELECT start_date, end_date, name, default_meal_allowance FROM projects WHERE id = :project_id";
    $project_stmt = $db->prepare($project_query);
    $project_stmt->bindParam(':project_id', $filters['project_id']);
    $project_stmt->execute();
    $project_info = $project_stmt->fetch(PDO::FETCH_ASSOC);
    
    // 获取项目默认餐费补助金额，如果未设置则使用100元
    $default_meal_allowance = isset($project_info['default_meal_allowance']) && $project_info['default_meal_allowance'] > 0 ? 
                              floatval($project_info['default_meal_allowance']) : 100.00;
    
    // 获取筛选参数，使用项目日期作为默认值
    // 但在页面首次加载时，不应用日期范围进行过滤，即显示所有数据
    $date_filters = [
        'date_from' => $_GET['date_from'] ?? '',
        'date_to' => $_GET['date_to'] ?? '',
        'department_id' => $_GET['department_id'] ?? ''
    ];
    
    // 设置筛选表单的默认值为项目日期
    $form_date_from = $_GET['date_from'] ?? $project_info['start_date'] ?? date('Y-m-01');
    $form_date_to = $_GET['date_to'] ?? $project_info['end_date'] ?? date('Y-m-d');
    
    // 获取部门列表
    $dept_query = "SELECT DISTINCT d.id, d.name 
                   FROM departments d 
                   JOIN project_department_personnel pdp ON d.id = pdp.department_id 
                   WHERE pdp.project_id = :project_id 
                   ORDER BY d.name";
    $dept_stmt = $db->prepare($dept_query);
    $dept_stmt->bindParam(':project_id', $filters['project_id']);
    $dept_stmt->execute();
    $departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 构建查询条件
    function buildWhereClause($projectId, $filters) {
        $where_conditions = ['hr.project_id = :project_id'];
        $params = [':project_id' => $projectId];
        
        // 只有当日期筛选条件都存在且不为空时才应用日期过滤
        if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
            $where_conditions[] = 'hr.check_in_date <= :date_to AND hr.check_out_date >= :date_from';
            $params[':date_from'] = $filters['date_from'];
            $params[':date_to'] = $filters['date_to'];
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
    
    // 获取项目中的所有人员信息及餐费补助计算（包括没有酒店入住记录的人员）
    $clause = buildWhereClause($filters['project_id'], $date_filters);
    
    // 查询项目中的所有人员，包括有酒店入住记录和没有酒店入住记录的人员
    // 只有当日期筛选条件都存在且不为空时才应用日期过滤
    if (!empty($date_filters['date_from']) && !empty($date_filters['date_to'])) {
        $date_condition = "hr.check_in_date <= :date_to AND hr.check_out_date >= :date_from";
        $date_calculation = "DATEDIFF(
                            LEAST(hr.check_out_date, :date_to),
                            GREATEST(hr.check_in_date, :date_from)
                        ) + 1";
    } else {
        $date_condition = "1=1";  // 不应用日期过滤
        $date_calculation = "CASE 
                            WHEN hr.check_in_date IS NOT NULL AND hr.check_out_date IS NOT NULL THEN
                                DATEDIFF(hr.check_out_date, hr.check_in_date) + 1
                            ELSE 0
                         END";
    }
    
    $query = "SELECT DISTINCT
                p.id as personnel_id,
                p.name as personnel_name,
                p.meal_allowance,  -- 获取人员的餐费补助金额
                d.name as department_name,
                d.sort_order as department_sort_order,  -- 获取部门排序字段
                hr.id as report_id,  -- 添加报告ID
                hr.check_in_date,
                hr.check_out_date,
                hr.hotel_name,
                hr.room_type,
                CASE 
                    WHEN hr.check_in_date IS NOT NULL AND hr.check_out_date IS NOT NULL THEN
                        $date_calculation
                    ELSE 0
                END AS days_count,
                CASE 
                    WHEN hr.room_type IN ('双床房', '双人房', '套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' 
                    THEN 1 
                    ELSE COALESCE(hr.room_count, 1)
                END as effective_room_count
              FROM personnel p
              LEFT JOIN project_department_personnel pdp ON (p.id = pdp.personnel_id AND pdp.project_id = :project_id)
              LEFT JOIN departments d ON pdp.department_id = d.id
              LEFT JOIN hotel_reports hr ON (p.id = hr.personnel_id AND hr.project_id = :project_id AND 
                    $date_condition)
              WHERE pdp.project_id = :project_id_param
              ORDER BY d.sort_order ASC, p.name, hr.check_in_date";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':project_id', $filters['project_id']);
    $stmt->bindParam(':project_id_param', $filters['project_id']);
    
    // 只有当日期筛选条件都存在且不为空时才绑定日期参数
    if (!empty($date_filters['date_from']) && !empty($date_filters['date_to'])) {
        $stmt->bindParam(':date_from', $date_filters['date_from']);
        $stmt->bindParam(':date_to', $date_filters['date_to']);
    }
    
    $stmt->execute();
    
    $rawData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 获取手动输入的天数信息
    $manualDaysQuery = "SELECT personnel_id, days FROM manual_meal_allowance_days WHERE project_id = :project_id";
    $manualDaysStmt = $db->prepare($manualDaysQuery);
    $manualDaysStmt->bindParam(':project_id', $filters['project_id']);
    $manualDaysStmt->execute();
    $manualDaysData = $manualDaysStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 将手动天数数据转换为关联数组以便快速查找
    $manualDaysMap = [];
    foreach ($manualDaysData as $record) {
        $manualDaysMap[$record['personnel_id']] = $record['days'];
    }
    
    // 处理数据，合并同一人员的多条记录，并处理没有酒店记录的人员
    $allowanceData = [];
    $personnelMap = [];
    
    foreach ($rawData as $record) {
        $personnelId = $record['personnel_id'];
        
        // 如果人员还没有记录，则初始化
        if (!isset($personnelMap[$personnelId])) {
            $personnelMap[$personnelId] = [
                'personnel_id' => $record['personnel_id'],
                'personnel_name' => $record['personnel_name'],
                'meal_allowance' => $record['meal_allowance'],
                'department_name' => $record['department_name'],
                'department_sort_order' => $record['department_sort_order'],
                'total_days' => 0,
                'total_rooms' => 0,
                'reports' => []
            ];
        }
        
        // 如果有酒店记录，则添加到报告中
        if (!is_null($record['report_id'])) {
            $personnelMap[$personnelId]['reports'][] = [
                'report_id' => $record['report_id'],
                'check_in_date' => $record['check_in_date'],
                'check_out_date' => $record['check_out_date'],
                'hotel_name' => $record['hotel_name'],
                'room_type' => $record['room_type'],
                'days_count' => $record['days_count'],
                'effective_room_count' => $record['effective_room_count']
            ];
            
            // 累计天数和房间数
            $personnelMap[$personnelId]['total_days'] += $record['days_count'];
            $personnelMap[$personnelId]['total_rooms'] += $record['effective_room_count'];
        }
    }
    
    // 将处理后的数据转换为显示格式
    foreach ($personnelMap as $personnel) {
        $personnelId = $personnel['personnel_id'];
        
        // 如果人员有酒店记录，则为每条记录创建一行
        if (!empty($personnel['reports'])) {
            foreach ($personnel['reports'] as $report) {
                // 优先使用手动输入的天数，如果没有则使用酒店记录的天数
                $mealDays = isset($manualDaysMap[$personnelId]) ? $manualDaysMap[$personnelId] : $report['days_count'];
                
                $allowanceData[] = [
                    'personnel_id' => $personnel['personnel_id'],
                    'personnel_name' => $personnel['personnel_name'],
                    'meal_allowance' => $personnel['meal_allowance'],
                    'department_name' => $personnel['department_name'],
                    'department_sort_order' => $personnel['department_sort_order'],
                    'report_id' => $report['report_id'],
                    'check_in_date' => $report['check_in_date'],
                    'check_out_date' => $report['check_out_date'],
                    'hotel_name' => $report['hotel_name'],
                    'room_type' => $report['room_type'],
                    'hotel_days_count' => $report['days_count'],  // 实际的酒店房晚数
                    'meal_days' => $mealDays,  // 用于餐补计算的天数（可手动修改）
                    'effective_room_count' => $report['effective_room_count']
                ];
            }
        } else {
            // 如果人员没有酒店记录，检查是否有手动输入的天数
            $manualDays = isset($manualDaysMap[$personnelId]) ? $manualDaysMap[$personnelId] : 0;
            
            // 如果人员没有酒店记录，也创建一行（显示基本信息，但天数和房间数为0或手动输入的天数）
            $allowanceData[] = [
                'personnel_id' => $personnel['personnel_id'],
                'personnel_name' => $personnel['personnel_name'],
                'meal_allowance' => $personnel['meal_allowance'],
                'department_name' => $personnel['department_name'],
                'department_sort_order' => $personnel['department_sort_order'],
                'report_id' => null,
                'check_in_date' => null,
                'check_out_date' => null,
                'hotel_name' => null,
                'room_type' => null,
                'hotel_days_count' => 0,  // 无酒店记录，房晚数为0
                'meal_days' => $manualDays,  // 使用手动输入的天数
                'effective_room_count' => 1
            ];
        }
    }
    
    // 计算每日餐费补助
    foreach ($allowanceData as &$record) {
        // 修复：正确使用个人设置的餐费补助金额
        // 优先级：个人设置 > 项目默认值 > 系统默认值(100元)
        $allowance_rate = 100.00; // 系统默认值
        
        // 如果项目有默认餐费补助金额，则使用项目默认值
        if ($default_meal_allowance > 0) {
            $allowance_rate = $default_meal_allowance;
        }
        
        // 如果人员有个人餐费补助金额，则使用个人设置值（优先级最高）
        // 修复：允许0值，只有当值为null时才不使用个人设置
        if (isset($record['meal_allowance']) && !is_null($record['meal_allowance'])) {
            $allowance_rate = floatval($record['meal_allowance']);
        }
        
        // 计算补助金额（使用meal_days而不是hotel_days_count）
        $record['allowance_amount'] = $record['meal_days'] * $allowance_rate * $record['effective_room_count'];
        // 保存餐费补助标准用于显示
        $record['allowance_rate'] = $allowance_rate;
    }
    
    // 计算总计（使用meal_days）
    foreach ($allowanceData as $record) {
        $total_days += $record['meal_days'] * $record['effective_room_count'];
        $total_amount += $record['allowance_amount'];
    }
    
    // 去重处理，解决重复显示问题
    $uniqueAllowanceData = [];
    $seenRecords = [];
    
    foreach ($allowanceData as $record) {
        // 创建唯一标识符
        $key = $record['personnel_id'] . '_' . ($record['report_id'] ?? 'no_report') . '_' . 
               ($record['check_in_date'] ?? 'no_date') . '_' . ($record['check_out_date'] ?? 'no_date');
        
        if (!isset($seenRecords[$key])) {
            $seenRecords[$key] = true;
            $uniqueAllowanceData[] = $record;
        }
    }
    
    $allowanceData = $uniqueAllowanceData;
    
    // 重新计算总计，因为去重后数据可能发生变化
    $total_days = 0;
    $total_amount = 0;
    foreach ($allowanceData as $record) {
        $total_days += $record['meal_days'] * $record['effective_room_count'];
        $total_amount += $record['allowance_amount'];
    }
}

// 处理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// 设置页面标题
$page_title = '餐费补助明细';

include 'includes/header.php';
?>

<!-- 引入优化样式 -->
<link href="assets/css/meal-allowance-admin.css" rel="stylesheet">

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <!-- 项目选择区域 -->
            <div class="project-selector">
                <h4>
                    <i class="bi bi-building"></i>
                    项目选择
                </h4>
                <form method="GET" id="projectForm">
                    <div class="row align-items-end">
                        <div class="col-md-8">
                            <label for="project_id" class="form-label fw-semibold">请选择要查看的项目</label>
                            <select class="form-select" id="project_id" name="project_id" onchange="this.form.submit()">
                                <option value="">请选择项目...</option>
                                <?php foreach ($all_projects as $project): ?>
                                    <option value="<?php echo $project['id']; ?>" 
                                            <?php echo $filters['project_id'] == $project['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($project['name']); ?>
                                        <?php if ($project['code']): ?>
                                            (<?php echo htmlspecialchars($project['code']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <?php if ($filters['project_id']): ?>
                                <div class="project-status selected">
                                    <i class="bi bi-check-circle-fill"></i>
                                    已选择项目
                                </div>
                            <?php else: ?>
                                <div class="project-status not-selected">
                                    <i class="bi bi-exclamation-circle-fill"></i>
                                    请先选择项目
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($filters['project_id']): ?>
                <!-- 筛选条件 -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-funnel me-2"></i>筛选条件
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <input type="hidden" name="project_id" value="<?php echo htmlspecialchars($filters['project_id']); ?>">
                            <div class="col-md-3">
                                <label class="form-label">开始日期</label>
                                <input type="date" class="form-control" name="date_from" 
                                       value="<?php echo $form_date_from; ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">结束日期</label>
                                <input type="date" class="form-control" name="date_to" 
                                       value="<?php echo $form_date_to; ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">部门</label>
                                <select class="form-select" name="department_id">
                                    <option value="">全部</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>"
                                                <?php echo $date_filters['department_id'] == $dept['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dept['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-2">
                                    <i class="bi bi-search me-1"></i>查询
                                </button>
                                <a href="meal_allowance.php?project_id=<?php echo $filters['project_id']; ?>" class="btn btn-outline-secondary">重置</a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- 统计摘要 -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card border-0 bg-primary text-white h-100">
                            <div class="card-body text-center">
                                <h6 class="text-white-50 mb-2">总天数</h6>
                                <h3 class="mb-0"><?php echo $total_days; ?></h3>
                                <div class="small text-white-50">天</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-0 bg-success text-white h-100">
                            <div class="card-body text-center">
                                <h6 class="text-white-50 mb-2">补助标准</h6>
                                <h3 class="mb-0">¥<?php echo number_format($default_meal_allowance, 2); ?></h3>
                                <div class="small text-white-50">每人每天</div>
                                <?php if (!empty($allowanceData)): ?>
                                    <div class="small text-white-50 mt-2">
                                        <?php
                                        // 计算实际使用的补助标准范围
                                        $rates = array_column($allowanceData, 'allowance_rate');
                                        $min_rate = min($rates);
                                        $max_rate = max($rates);
                                        if ($min_rate != $max_rate) {
                                            echo "实际范围: ¥" . number_format($min_rate, 2) . " - ¥" . number_format($max_rate, 2);
                                        } else {
                                            echo "实际标准: ¥" . number_format($min_rate, 2);
                                        }
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-0 bg-danger text-white h-100">
                            <div class="card-body text-center">
                                <h6 class="text-white-50 mb-2">总金额</h6>
                                <h3 class="mb-0">¥<?php echo number_format($total_amount, 2); ?></h3>
                                <div class="small text-white-50">元</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 餐费补助明细 -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="bi bi-list me-2"></i>餐费补助明细
                        </h5>
                        <span class="badge bg-primary"><?php echo count($allowanceData); ?> 条记录</span>
                    </div>
                    <div class="card-body">
                        <!-- 调试信息（开发阶段显示） -->
                        <?php if (false): // 设置为true启用调试 ?>
                        <div class="alert alert-warning mb-3">
                            <strong>调试信息:</strong><br>
                            project_id: <?php echo $filters['project_id']; ?><br>
                            allowanceData count: <?php echo count($allowanceData); ?><br>
                            显示条件: <?php echo ($filters['project_id'] && !empty($allowanceData)) ? '满足' : '不满足'; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- 批量操作区域 -->
                        <?php if ($filters['project_id'] && !empty($allowanceData)): ?>
                        <div class="card border-info mb-4" id="batchOperationArea">
                            <div class="card-body bg-info bg-opacity-10">
                                <h6 class="card-title text-info"><i class="bi bi-lightning-fill me-2"></i>批量设置天数</h6>
                            <form id="batchUpdateForm" class="row g-3 align-items-end">
                                <input type="hidden" name="project_id" value="<?php echo $filters['project_id']; ?>">
                                <div class="col-md-2">
                                    <label class="form-label small">设置天数</label>
                                    <input type="number" class="form-control" id="batch_days" name="batch_days" 
                                           min="0" step="1" placeholder="输入天数" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">筛选条件</label>
                                    <select class="form-select" id="batch_filter" name="batch_filter">
                                        <option value="all">全部人员</option>
                                        <option value="has_hotel">有酒店记录</option>
                                        <option value="no_hotel">无酒店记录</option>
                                        <?php if (!empty($departments)): ?>
                                            <optgroup label="按部门">
                                                <?php foreach ($departments as $dept): ?>
                                                    <option value="dept_<?php echo $dept['id']; ?>">
                                                        <?php echo htmlspecialchars($dept['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <button type="submit" class="btn btn-primary" id="batchSubmitBtn">
                                        <i class="bi bi-check-circle me-1"></i>批量设置天数
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('batchUpdateForm').reset();">
                                        <i class="bi bi-x-circle me-1"></i>清空
                                    </button>
                                </div>
                                <div class="col-md-3">
                                    <div class="small text-muted">
                                        <i class="bi bi-info-circle me-1"></i>仅更新符合条件的记录
                                    </div>
                                </div>
                            </form>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if (empty($allowanceData)): ?>
                            <p class="text-muted text-center py-4">暂无数据</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>人员姓名</th>
                                            <th>部门</th>
                                            <th>入住日期</th>
                                            <th>退房日期</th>
                                            <th>酒店</th>
                                            <th>房晚数</th>
                                            <th>天数</th>
                                            <th>补助标准</th>
                                            <th>补助金额</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($allowanceData as $record): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($record['personnel_name']); ?></td>
                                                <td>
                                                    <span class="badge bg-secondary">
                                                        <?php echo htmlspecialchars($record['department_name'] ?? '未分配'); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo $record['check_in_date'] ?? '-'; ?></td>
                                                <td><?php echo $record['check_out_date'] ?? '-'; ?></td>
                                                <td><?php echo htmlspecialchars($record['hotel_name'] ?? '-'); ?></td>
                                                <td><?php echo $record['hotel_days_count'] * $record['effective_room_count']; ?></td>
                                                <td>
                                                    <div class="input-group input-group-sm" style="width: 100px;">
                                                        <input type="number" 
                                                               class="form-control form-control-sm days-input" 
                                                               value="<?php echo $record['meal_days']; ?>" 
                                                               data-personnel-id="<?php echo $record['personnel_id']; ?>"
                                                               data-project-id="<?php echo $filters['project_id']; ?>"
                                                               <?php if (!is_null($record['report_id'])): ?>
                                                               data-report-id="<?php echo $record['report_id']; ?>"
                                                               data-original-value="<?php echo $record['meal_days']; ?>"
                                                               data-check-in-date="<?php echo $record['check_in_date']; ?>"
                                                               data-check-out-date="<?php echo $record['check_out_date']; ?>"
                                                               <?php else: ?>
                                                               data-original-value="<?php echo $record['meal_days']; ?>"
                                                               data-no-report="true"
                                                               <?php endif; ?>
                                                               min="0" 
                                                               step="1">
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="input-group input-group-sm" style="width: 120px;">
                                                        <span class="input-group-text">¥</span>
                                                        <input type="number" 
                                                               class="form-control form-control-sm meal-allowance-input" 
                                                               value="<?php echo number_format($record['allowance_rate'], 2, '.', ''); ?>" 
                                                               data-personnel-id="<?php echo $record['personnel_id']; ?>"
                                                               data-original-value="<?php echo number_format($record['allowance_rate'], 2, '.', ''); ?>"
                                                               min="0" 
                                                               step="0.01">
                                                    </div>
                                                </td>
                                                <td class="text-danger fw-bold">¥<?php echo number_format($record['allowance_amount'], 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-dark">
                                        <tr>
                                            <td colspan="7" class="text-end"><strong>总计：</strong></td>
                                            <td><strong><?php echo $total_days; ?></strong></td>
                                            <td class="text-danger fw-bold">
                                                <strong>¥<?php echo number_format($total_amount, 2); ?></strong>
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('=== 页面加载完成 (DOMContentLoaded) ===');
    console.log('批量操作区域是否存在:', document.getElementById('batchOperationArea') !== null);
    
    // 监控批量操作区域的状态
    const batchArea = document.getElementById('batchOperationArea');
    if (batchArea) {
        console.log('批量操作区域初始状态:', {
            display: window.getComputedStyle(batchArea).display,
            visibility: window.getComputedStyle(batchArea).visibility,
            exists: true
        });
        
        // 监控DOM变化
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'childList' && mutation.removedNodes.length > 0) {
                    mutation.removedNodes.forEach(function(node) {
                        if (node.id === 'batchOperationArea') {
                            console.error('!!! 批量操作区域被删除 !!!');
                            console.trace('删除调用堆栈:');
                        }
                    });
                }
                if (mutation.type === 'attributes' && mutation.target.id === 'batchOperationArea') {
                    console.warn('批量操作区域属性被修改:', {
                        attribute: mutation.attributeName,
                        newValue: mutation.target.getAttribute(mutation.attributeName)
                    });
                }
            });
        });
        
        observer.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['style', 'class']
        });
        
        // 每秒1秒检查一次
        let checkCount = 0;
        const checkInterval = setInterval(function() {
            checkCount++;
            const area = document.getElementById('batchOperationArea');
            if (!area) {
                console.error(`!!! [第${checkCount}秒] 批量操作区域不存在了 !!!`);
                clearInterval(checkInterval);
            } else {
                const style = window.getComputedStyle(area);
                if (style.display === 'none' || style.visibility === 'hidden') {
                    console.warn(`[第${checkCount}秒] 批量操作区域被隐藏:`, {
                        display: style.display,
                        visibility: style.visibility
                    });
                } else {
                    console.log(`[第${checkCount}秒] 批量操作区域正常`);
                }
            }
            
            if (checkCount >= 10) {
                console.log('停止监控（10秒后）');
                clearInterval(checkInterval);
            }
        }, 1000);
    }
    
    // 为所有餐费补助输入框添加事件监听器
    const allowanceInputs = document.querySelectorAll('.meal-allowance-input');
    
    allowanceInputs.forEach(input => {
        // 保存原始值
        input.dataset.originalValue = input.value;
        
        // 失去焦点时保存更改
        input.addEventListener('blur', function() {
            console.log('餐费补助输入框 blur 事件触发');
            const personnelId = this.dataset.personnelId;
            const newValue = parseFloat(this.value);
            const originalValue = parseFloat(this.dataset.originalValue);
            
            console.log('餐费补助值比较:', { personnelId, newValue, originalValue });
            
            // 如果值没有改变，则不执行任何操作
            if (newValue === originalValue) {
                console.log('餐费补助值未改变，不更新');
                return;
            }
            
            console.log('餐费补助值已改变，准备更新...');
            
            // 验证输入值
            if (isNaN(newValue) || newValue < 0) {
                alert('请输入有效的餐费补助金额！');
                this.value = originalValue;
                return;
            }
            
            // 发送 AJAX 请求更新数据库
            updateMealAllowance(personnelId, newValue, this);
        });
        
        // 按回车键时保存更改
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                this.blur();
            }
        });
    });
    
    // 为所有天数输入框添加事件监听器
    const daysInputs = document.querySelectorAll('.days-input');
    
    daysInputs.forEach(input => {
        // 保存原始值
        input.dataset.originalValue = input.value;
        
        // 失去焦点时保存更改
        input.addEventListener('blur', function() {
            console.log('天数输入框 blur 事件触发');
            const reportId = this.dataset.reportId;
            const newValue = parseInt(this.value);
            const originalValue = parseInt(this.dataset.originalValue);
            const checkInDate = this.dataset.checkInDate;
            const checkOutDate = this.dataset.checkOutDate;
            const personnelId = this.dataset.personnelId;
            const projectId = this.dataset.projectId;
            const noReport = this.dataset.noReport;
            
            console.log('天数值比较:', { reportId, newValue, originalValue, noReport });
            
            // 如果值没有改变，则不执行任何操作
            if (newValue === originalValue) {
                console.log('天数值未改变，不更新');
                return;
            }
            
            console.log('天数值已改变，准备更新...');
            console.log('!!! 将在更新成功后0.5秒刷新页面 !!!');
            
            // 验证输入值
            if (isNaN(newValue) || newValue < 0) {
                alert('请输入有效的天数！');
                this.value = originalValue;
                return;
            }
            
            // 如果是没有酒店记录的人员，更新手动天数
            if (noReport) {
                updateManualMealDays(personnelId, projectId, newValue, this);
            } else {
                // 发送 AJAX 请求更新数据库
                updateHotelReportDays(reportId, newValue, checkInDate, checkOutDate, personnelId, projectId, this);
            }
        });
        
        // 按回车键时保存更改
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                this.blur();
            }
        });
    });
    
    // 更新餐费补助金额的函数
    function updateMealAllowance(personnelId, newValue, inputElement) {
        // 显示加载状态
        const originalHtml = inputElement.outerHTML;
        inputElement.classList.add('is-valid');
        
        // 发送 AJAX 请求
        fetch('ajax_update_meal_allowance.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `personnel_id=${personnelId}&meal_allowance=${newValue}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // 更新成功，保存新值为原始值
                inputElement.dataset.originalValue = newValue;
                inputElement.classList.remove('is-valid');
                inputElement.classList.add('is-valid');
                
                // 重新加载页面以更新计算结果
                setTimeout(() => {
                    location.reload();
                }, 500);
            } else {
                // 更新失败，恢复原始值
                alert('更新失败: ' + data.message);
                inputElement.value = inputElement.dataset.originalValue;
                inputElement.classList.remove('is-valid');
            }
        })
        .catch(error => {
            // 请求失败，恢复原始值
            console.error('Error:', error);
            alert('更新失败，请重试');
            inputElement.value = inputElement.dataset.originalValue;
            inputElement.classList.remove('is-valid');
        });
    }
    
    // 更新酒店报告天数的函数
    function updateHotelReportDays(reportId, newValue, checkInDate, checkOutDate, personnelId, projectId, inputElement) {
        // 显示加载状态
        inputElement.classList.add('is-valid');
        
        // 发送 AJAX 请求
        fetch('ajax_update_hotel_report_days.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `report_id=${reportId}&new_days=${newValue}&original_check_in=${checkInDate}&original_check_out=${checkOutDate}&personnel_id=${personnelId}&project_id=${projectId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // 更新成功，保存新值为原始值
                inputElement.dataset.originalValue = newValue;
                inputElement.classList.remove('is-valid');
                inputElement.classList.add('is-valid');
                
                // 重新加载页面以更新计算结果
                setTimeout(() => {
                    location.reload();
                }, 500);
            } else {
                // 更新失败，恢复原始值
                alert('更新失败: ' + data.message);
                inputElement.value = inputElement.dataset.originalValue;
                inputElement.classList.remove('is-valid');
            }
        })
        .catch(error => {
            // 请求失败，恢复原始值
            console.error('Error:', error);
            alert('更新失败，请重试');
            inputElement.value = inputElement.dataset.originalValue;
            inputElement.classList.remove('is-valid');
        });
    }
    
    // 为没有酒店记录的人员更新手动天数
    function updateManualMealDays(personnelId, projectId, days, inputElement) {
        // 显示加载状态
        inputElement.classList.add('is-valid');
        
        // 发送 AJAX 请求更新手动天数
        fetch('ajax_update_manual_meal_days.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `personnel_id=${personnelId}&project_id=${projectId}&days=${days}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // 更新成功，保存新值为原始值
                inputElement.dataset.originalValue = days;
                inputElement.classList.remove('is-valid');
                inputElement.classList.add('is-valid');
                
                // 重新加载页面以更新计算结果
                setTimeout(() => {
                    location.reload();
                }, 500);
            } else {
                // 更新失败，恢复原始值
                alert('更新失败: ' + data.message);
                inputElement.value = inputElement.dataset.originalValue;
                inputElement.classList.remove('is-valid');
            }
        })
        .catch(error => {
            // 请求失败，恢复原始值
            console.error('Error:', error);
            alert('更新失败，请重试');
            inputElement.value = inputElement.dataset.originalValue;
            inputElement.classList.remove('is-valid');
        });
    }
    
    // 批量设置天数功能
    const batchUpdateForm = document.getElementById('batchUpdateForm');
    if (batchUpdateForm) {
        console.log('批量操作表单已初始化');
        
        batchUpdateForm.addEventListener('submit', function(e) {
            e.preventDefault();
            console.log('批量设置表单提交');
            
            const batchDays = parseInt(document.getElementById('batch_days').value);
            const batchFilter = document.getElementById('batch_filter').value;
            const projectId = this.querySelector('input[name="project_id"]').value;
            
            console.log('批量设置参数:', { batchDays, batchFilter, projectId });
            
            // 验证输入
            if (isNaN(batchDays) || batchDays < 0) {
                alert('请输入有效的天数（非负数）');
                return;
            }
            
            // 根据筛选条件获取符合条件的输入框
            const allDaysInputs = document.querySelectorAll('.days-input');
            const targetInputs = [];
            
            allDaysInputs.forEach(input => {
                let shouldInclude = false;
                
                if (batchFilter === 'all') {
                    shouldInclude = true;
                } else if (batchFilter === 'has_hotel') {
                    shouldInclude = input.dataset.reportId !== undefined;
                } else if (batchFilter === 'no_hotel') {
                    shouldInclude = input.dataset.noReport === 'true';
                } else if (batchFilter.startsWith('dept_')) {
                    // 按部门筛选
                    const deptId = batchFilter.replace('dept_', '');
                    const row = input.closest('tr');
                    const deptBadge = row.querySelector('.badge');
                    if (deptBadge) {
                        const deptName = deptBadge.textContent.trim();
                        // 需要对比部门名称
                        <?php if (!empty($departments)): ?>
                        const deptNames = {
                            <?php foreach ($departments as $dept): ?>
                            '<?php echo $dept['id']; ?>': '<?php echo addslashes($dept['name']); ?>',
                            <?php endforeach; ?>
                        };
                        shouldInclude = deptNames[deptId] === deptName;
                        <?php endif; ?>
                    }
                }
                
                if (shouldInclude) {
                    targetInputs.push(input);
                }
            });
            
            if (targetInputs.length === 0) {
                alert('没有符合条件的记录');
                console.log('未找到符合条件的记录');
                return;
            }
            
            console.log(`找到 ${targetInputs.length} 条符合条件的记录`);
            
            // 确认对话框
            if (!confirm(`确定要将 ${targetInputs.length} 条记录的天数设置为 ${batchDays} 天吗？`)) {
                console.log('用户取消批量设置');
                return;
            }
            
            // 禁用表单按钮
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>批量更新中...';
            
            // 批量更新
            let successCount = 0;
            let failCount = 0;
            let processedCount = 0;
            
            const updatePromises = targetInputs.map(input => {
                return new Promise((resolve) => {
                    const reportId = input.dataset.reportId;
                    const noReport = input.dataset.noReport === 'true';
                    
                    if (noReport) {
                        // 无酒店记录，更新manual_meal_allowance_days表
                        const personnelId = input.dataset.personnelId;
                        const projectId = input.dataset.projectId;
                        
                        fetch('ajax_update_manual_meal_days.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: `personnel_id=${personnelId}&project_id=${projectId}&days=${batchDays}`
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                input.value = batchDays;
                                input.dataset.originalValue = batchDays;
                                successCount++;
                            } else {
                                failCount++;
                            }
                            processedCount++;
                            resolve();
                        })
                        .catch(() => {
                            failCount++;
                            processedCount++;
                            resolve();
                        });
                    } else {
                        // 有酒店记录，更新hotel_reports表
                        const checkInDate = input.dataset.checkInDate;
                        const checkOutDate = input.dataset.checkOutDate;
                        const personnelId = input.closest('tr').querySelector('.days-input').dataset.personnelId || '';
                        
                        fetch('ajax_update_hotel_report_days.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: `report_id=${reportId}&new_days=${batchDays}&original_check_in=${checkInDate}&original_check_out=${checkOutDate}&personnel_id=${personnelId}&project_id=${projectId}`
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                input.value = batchDays;
                                input.dataset.originalValue = batchDays;
                                successCount++;
                            } else {
                                failCount++;
                            }
                            processedCount++;
                            resolve();
                        })
                        .catch(() => {
                            failCount++;
                            processedCount++;
                            resolve();
                        });
                    }
                });
            });
            
            // 等待所有更新完成
            Promise.all(updatePromises).then(() => {
                console.log(`批量更新完成: 成功 ${successCount} 条, 失败 ${failCount} 条`);
                
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
                
                if (failCount === 0) {
                    alert(`批量设置成功！共更新 ${successCount} 条记录`);
                } else {
                    alert(`批量设置完成！成功 ${successCount} 条，失败 ${failCount} 条`);
                }
                
                // 保留项目ID参数刷新页面
                const currentUrl = new URL(window.location.href);
                const projectId = currentUrl.searchParams.get('project_id');
                
                console.log('准备刷新页面, project_id:', projectId);
                
                setTimeout(() => {
                    if (projectId) {
                        window.location.href = `meal_allowance.php?project_id=${projectId}`;
                    } else {
                        location.reload();
                    }
                }, 1000);
            });
        });
    } else {
        console.log('批量操作表单未找到 (batchUpdateForm)');
    }
});
</script>

</body>
</html>