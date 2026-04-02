<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

session_start();
// 引入权限中间件进行权限验证
require_once '../includes/PermissionMiddleware.php';
$database = new Database();
$db = $database->getConnection();
$middleware = new PermissionMiddleware($db);
$middleware->checkUserPagePermission('frontend:meal:allowance');

requireLogin();
checkProjectPermission($_SESSION['project_id']);

// 获取数据库连接
$database = new Database();
$db = $database->getConnection();

$projectId = $_SESSION['project_id'];

// 获取项目信息，包括开始日期和结束日期以及默认餐费补助金额
$project_query = "SELECT start_date, end_date, name, default_meal_allowance FROM projects WHERE id = :project_id";
$project_stmt = $db->prepare($project_query);
$project_stmt->bindParam(':project_id', $projectId);
$project_stmt->execute();
$project_info = $project_stmt->fetch(PDO::FETCH_ASSOC);

// 如果查询到项目信息，则使用项目的开始和结束日期作为默认值，否则使用当前逻辑
$default_date_from = $project_info['start_date'] ?? date('Y-m-01');
$default_date_to = $project_info['end_date'] ?? date('Y-m-d');
// 获取项目默认餐费补助金额，如果未设置则使用100元
$default_meal_allowance = isset($project_info['default_meal_allowance']) && $project_info['default_meal_allowance'] > 0 ? 
                          floatval($project_info['default_meal_allowance']) : 100.00;

// 获取筛选参数，使用项目日期作为默认值
// 但在页面首次加载时，不应用日期范围进行过滤，即显示所有数据
$filters = [
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'department_id' => $_GET['department_id'] ?? ''
];

// 设置筛选表单的默认值为项目日期
$form_date_from = $_GET['date_from'] ?? $default_date_from;
$form_date_to = $_GET['date_to'] ?? $default_date_to;

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

// 获取人员入住信息及餐费补助计算（包括没有酒店入住记录的人员）
function getPersonnelAllowance($projectId, $db, $filters, $default_meal_allowance) {
    // 查询项目中的所有人员，包括有酒店入住记录和没有酒店入住记录的人员
    // 只有当日期筛选条件都存在且不为空时才应用日期过滤
    if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
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
    $stmt->bindParam(':project_id', $projectId);
    $stmt->bindParam(':project_id_param', $projectId);
    
    // 只有当日期筛选条件都存在且不为空时才绑定日期参数
    if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
        $stmt->bindParam(':date_from', $filters['date_from']);
        $stmt->bindParam(':date_to', $filters['date_to']);
    }
    
    $stmt->execute();
    
    $rawData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 获取手动输入的天数信息
    $manualDaysQuery = "SELECT personnel_id, days FROM manual_meal_allowance_days WHERE project_id = :project_id";
    $manualDaysStmt = $db->prepare($manualDaysQuery);
    $manualDaysStmt->bindParam(':project_id', $projectId);
    $manualDaysStmt->execute();
    $manualDaysData = $manualDaysStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 将手动天数数据转换为关联数组以便快速查找
    $manualDaysMap = [];
    foreach ($manualDaysData as $record) {
        $manualDaysMap[$record['personnel_id']] = $record['days'];
    }
    
    // 处理数据，合并同一人员的多条记录，并处理没有酒店记录的人员
    $results = [];
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
        if (!is_null($record['check_in_date'])) {
            $personnelMap[$personnelId]['reports'][] = [
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
                $results[] = [
                    'personnel_id' => $personnel['personnel_id'],
                    'personnel_name' => $personnel['personnel_name'],
                    'meal_allowance' => $personnel['meal_allowance'],
                    'department_name' => $personnel['department_name'],
                    'department_sort_order' => $personnel['department_sort_order'],
                    'check_in_date' => $report['check_in_date'],
                    'check_out_date' => $report['check_out_date'],
                    'hotel_name' => $report['hotel_name'],
                    'room_type' => $report['room_type'],
                    'actual_days' => $report['days_count'],
                    'effective_room_count' => $report['effective_room_count']
                ];
            }
        } else {
            // 如果人员没有酒店记录，检查是否有手动输入的天数
            $manualDays = isset($manualDaysMap[$personnelId]) ? $manualDaysMap[$personnelId] : 0;
            
            // 如果人员没有酒店记录，也创建一行（显示基本信息，但天数和房间数为0或手动输入的天数）
            $results[] = [
                'personnel_id' => $personnel['personnel_id'],
                'personnel_name' => $personnel['personnel_name'],
                'meal_allowance' => $personnel['meal_allowance'],
                'department_name' => $personnel['department_name'],
                'department_sort_order' => $personnel['department_sort_order'],
                'check_in_date' => null,
                'check_out_date' => null,
                'hotel_name' => null,
                'room_type' => null,
                'actual_days' => $manualDays,  // 使用手动输入的天数
                'effective_room_count' => 1
            ];
        }
    }
    
    // 计算每日餐费补助
    foreach ($results as &$record) {
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
        
        // 计算补助金额
        $record['allowance_amount'] = $record['actual_days'] * $allowance_rate * $record['effective_room_count'];
        // 保存餐费补助标准用于显示
        $record['allowance_rate'] = $allowance_rate;
    }
    
    // 去重处理，解决重复显示问题
    $uniqueResults = [];
    $seenRecords = [];
    
    foreach ($results as $record) {
        // 创建唯一标识符
        $key = $record['personnel_id'] . '_' . ($record['check_in_date'] ?? 'no_date') . '_' . ($record['check_out_date'] ?? 'no_date');
        
        if (!isset($seenRecords[$key])) {
            $seenRecords[$key] = true;
            $uniqueResults[] = $record;
        }
    }
    
    return $uniqueResults;
}

// 获取统计数据
$allowanceData = getPersonnelAllowance($projectId, $db, $filters, $default_meal_allowance);

// 计算总计
$total_days = 0;
$total_amount = 0;
foreach ($allowanceData as $record) {
    $total_days += $record['actual_days'] * $record['effective_room_count'];
    $total_amount += $record['allowance_amount'];
}

// 设置页面变量
$page_title = '餐费补助明细 - ' . ($_SESSION['project_name'] ?? '项目');
$active_page = 'meal_allowance';
$show_page_title = '餐费补助明细';
$page_icon = 'cash';

include 'includes/header.php';
?>

<div class="container-fluid mt-4">
    <!-- 筛选条件 -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="bi bi-funnel me-2"></i>筛选条件
            </h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
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
                    <a href="meal_allowance.php" class="btn btn-outline-secondary me-2">重置</a>
                    <!-- 添加导出按钮 -->
                    <a href="export_meal_allowance.php?project_id=<?php echo $projectId; ?>&date_from=<?php echo $filters['date_from']; ?>&date_to=<?php echo $filters['date_to']; ?>&department_id=<?php echo $filters['department_id']; ?>" class="btn btn-success" target="_blank">
                        <i class="bi bi-download me-1"></i>导出
                    </a>
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
                                <th>房型</th>
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
                                    <td><?php echo htmlspecialchars($record['room_type'] ?? '-'); ?></td>
                                    <td><?php echo $record['actual_days'] * $record['effective_room_count']; ?></td>
                                    <td><?php echo $record['actual_days']; ?></td>
                                    <td>
                                        <!-- 修改为只读显示 -->
                                        <span class="form-control form-control-sm" style="display: inline-block; width: 120px; text-align: right;">
                                            ¥<?php echo number_format($record['allowance_rate'], 2); ?>
                                        </span>
                                    </td>
                                    <td class="text-danger fw-bold">¥<?php echo number_format($record['allowance_amount'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-dark">
                            <tr>
                                <td colspan="8" class="text-end"><strong>总计：</strong></td>
                                <td><strong>¥<?php echo number_format($default_meal_allowance, 2); ?></strong></td>
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
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 移除餐费补助输入框的事件监听器（因为该列已改为只读）
    
    // 更新餐费补助金额的函数（已移除，因为该功能不再需要）
});
</script>

<?php include 'includes/footer.php'; ?>
</body>
</html>