<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

session_start();
requireLogin();
checkProjectPermission($_SESSION['project_id']);

// 获取数据库连接
$database = new Database();
$db = $database->getConnection();

// 获取项目ID和其他筛选参数
$projectId = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$department_id = isset($_GET['department_id']) ? $_GET['department_id'] : '';

// 验证项目ID
if ($projectId <= 0) {
    die('无效的项目ID');
}

// 获取项目信息
$project_query = "SELECT name, default_meal_allowance FROM projects WHERE id = :project_id";
$project_stmt = $db->prepare($project_query);
$project_stmt->bindParam(':project_id', $projectId);
$project_stmt->execute();
$project_info = $project_stmt->fetch(PDO::FETCH_ASSOC);

if (!$project_info) {
    die('项目不存在');
}

// 设置默认日期范围
$default_date_from = $project_info['start_date'] ?? date('Y-m-01');
$default_date_to = $project_info['end_date'] ?? date('Y-m-d');

// 获取筛选参数
$filters = [
    'date_from' => $date_from ?: $default_date_from,
    'date_to' => $date_to ?: $default_date_to,
    'department_id' => $department_id
];

// 构建查询条件
function buildWhereClause($projectId, $filters) {
    $where_conditions = ['hr.project_id = :project_id'];
    $params = [':project_id' => $projectId];
    
    if (!empty($filters['date_from'])) {
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

// 获取项目默认餐费补助金额
$default_meal_allowance = isset($project_info['default_meal_allowance']) && $project_info['default_meal_allowance'] > 0 ? 
                          floatval($project_info['default_meal_allowance']) : 100.00;

// 获取人员入住信息及餐费补助计算
$clause = buildWhereClause($projectId, $filters);

// 查询入住记录，计算每日餐费补助
$query = "SELECT 
            p.id as personnel_id,
            p.name as personnel_name,
            p.meal_allowance,  -- 获取人员的餐费补助金额
            d.name as department_name,
            hr.check_in_date,
            hr.check_out_date,
            hr.hotel_name,
            hr.room_type,
            DATEDIFF(
                LEAST(hr.check_out_date, :date_to),
                GREATEST(hr.check_in_date, :date_from)
            ) + 1 AS days_count,
            CASE 
                WHEN hr.room_type IN ('双床房', '双人房', '套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' 
                THEN 1 
                ELSE hr.room_count 
            END as effective_room_count
          FROM personnel p
          JOIN hotel_reports hr ON p.id = hr.personnel_id
          LEFT JOIN project_department_personnel pdp ON (hr.project_id = pdp.project_id AND p.id = pdp.personnel_id)
          LEFT JOIN departments d ON pdp.department_id = d.id
          WHERE {$clause['where']}
          ORDER BY d.name ASC, p.name, hr.check_in_date";

$stmt = $db->prepare($query);
$params = $clause['params'];
$params[':date_from'] = $filters['date_from'];
$params[':date_to'] = $filters['date_to'];
$stmt->execute($params);

$allowanceData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 计算每日餐费补助
foreach ($allowanceData as &$record) {
    // 优先级：个人设置 > 项目默认值 > 系统默认值(100元)
    $allowance_rate = 100.00; // 系统默认值
    
    // 如果项目有默认餐费补助金额，则使用项目默认值
    if ($default_meal_allowance > 0) {
        $allowance_rate = $default_meal_allowance;
    }
    
    // 如果人员有个人餐费补助金额，则使用个人设置值（优先级最高）
    if (isset($record['meal_allowance']) && !is_null($record['meal_allowance'])) {
        $allowance_rate = floatval($record['meal_allowance']);
    }
    
    // 计算实际天数（在筛选日期范围内的天数）
    $check_in = new DateTime($record['check_in_date']);
    $check_out = new DateTime($record['check_out_date']);
    $filter_start = new DateTime($filters['date_from']);
    $filter_end = new DateTime($filters['date_to']);
    
    // 取较晚的入住日期和较早的退房日期
    $start = $check_in > $filter_start ? $check_in : $filter_start;
    $end = $check_out < $filter_end ? $check_out : $filter_end;
    
    // 计算天数
    $interval = $start->diff($end);
    $days = $interval->days + 1;
    
    // 确保天数不为负数
    $record['actual_days'] = max(0, $days);
    $record['allowance_amount'] = $record['actual_days'] * $allowance_rate * $record['effective_room_count'];
    // 保存餐费补助标准用于显示
    $record['allowance_rate'] = $allowance_rate;
}

// 设置响应头，提示浏览器下载CSV文件
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=餐费补助明细_' . date('Y-m-d') . '.csv');

// 打开输出流
$output = fopen('php://output', 'w');

// 添加BOM以支持中文
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// 写入CSV标题行
fputcsv($output, [
    '人员姓名',
    '部门',
    '入住日期',
    '退房日期',
    '酒店',
    '房型',
    '房间数',
    '天数',
    '补助标准(¥)',
    '补助金额(¥)'
]);

// 写入数据行
foreach ($allowanceData as $record) {
    fputcsv($output, [
        $record['personnel_name'],
        $record['department_name'] ?? '未分配',
        $record['check_in_date'],
        $record['check_out_date'],
        $record['hotel_name'],
        $record['room_type'],
        $record['effective_room_count'],
        $record['actual_days'],
        number_format($record['allowance_rate'], 2),
        number_format($record['allowance_amount'], 2)
    ]);
}

// 计算总计
$total_days = 0;
$total_amount = 0;
foreach ($allowanceData as $record) {
    $total_days += $record['actual_days'] * $record['effective_room_count'];
    $total_amount += $record['allowance_amount'];
}

// 添加总计行
fputcsv($output, []);
fputcsv($output, ['总计', '', '', '', '', '', '', $total_days, '', number_format($total_amount, 2)]);

// 关闭输出流
fclose($output);
exit;
?>