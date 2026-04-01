<?php
// 启动会话
session_start();

// 检查用户是否已登录
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 包含数据库连接和函数
require_once '../config/database.php';
require_once '../includes/functions.php';

// 检查用户权限
requireLogin();
if (isset($_SESSION['project_id'])) {
    checkProjectPermission($_SESSION['project_id']);
}

// 获取数据库连接
$database = new Database();
$pdo = $database->getConnection();

// 获取搜索和筛选参数
$search = $_GET['search'] ?? '';
$gender = $_GET['gender'] ?? '';
$department_filter = $_GET['department'] ?? '';

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

// 构建排序字段映射（防止SQL注入）
$allowed_sort_fields = [
    'created_at' => 'p.created_at',
    'name' => 'p.name',
    'department' => 'department_names',  // 按部门名称排序
    'gender' => 'p.gender',
    'id' => 'p.id'
];

$sort_by = 'department'; // 默认按部门排序
$sort_order = 'ASC'; // 默认升序

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

// 设置响应头以导出CSV文件
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=personnel_export_' . date('Y-m-d_H-i-s') . '.csv');

// 打开输出流
$output = fopen('php://output', 'w');

// 添加BOM以支持Excel中的中文
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// 写入CSV标题行
fputcsv($output, ['序号', '姓名', '性别', '部门', '职位', '工作证类型', '身份证', '联系电话', '邮箱', '创建时间']);

// 写入数据行
$serial_number = 1;
foreach ($personnel as $person) {
    fputcsv($output, [
        $serial_number++,
        $person['name'],
        $person['gender'],
        $person['department_names'],
        $person['positions'],
        $person['badge_types'],
        $person['id_card'],
        $person['phone'],
        $person['email'],
        $person['created_at']
    ]);
}

// 关闭输出流
fclose($output);
exit;
?>