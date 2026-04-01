<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
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

// 开启错误报告用于调试
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();

$message = '';
$error = '';

// 处理GET请求中的删除操作
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['ids'])) {
    try {
        $ids_str = $_GET['ids'];
        // 调试信息
        error_log("删除操作 - IDs字符串: " . $ids_str);
        
        $ids = explode(',', $ids_str);
        $ids = array_map('intval', $ids);
        
        // 调试信息
        error_log("删除操作 - 解析后的IDs数组: " . print_r($ids, true));
        
        if (count($ids) > 0) {
            // 开始事务
            $db->beginTransaction();
            
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $query = "DELETE FROM hotel_reports WHERE id IN ($placeholders)";
            
            // 调试信息
            error_log("删除操作 - SQL查询: " . $query);
            
            $stmt = $db->prepare($query);
            
            if ($stmt->execute($ids)) {
                // 提交事务
                $db->commit();
                $message = '记录删除成功！';
                
                // 调试信息
                error_log("删除操作 - 成功删除记录，IDs: " . implode(',', $ids));
            } else {
                // 回滚事务
                $db->rollback();
                $error = '删除失败，请重试！';
                
                // 调试信息
                error_log("删除操作 - 删除失败，错误信息: " . print_r($stmt->errorInfo(), true));
            }
        } else {
            $error = '无效的记录ID！';
        }
    } catch (Exception $e) {
        // 回滚事务
        if ($db->inTransaction()) {
            $db->rollback();
        }
        $error = '删除过程中发生错误：' . $e->getMessage();
        
        // 调试信息
        error_log("删除操作 - 异常: " . $e->getMessage());
    }
    
    // 重定向以避免重复删除
    header("Location: hotel_reports.php");
    exit;
}

// 处理AJAX请求 - 新的筛选功能支持
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    // 返回JSON格式数据
    header('Content-Type: application/json');
    
    // 处理AJAX GET请求（获取数据）
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // 获取筛选参数
        $project_id = $_GET['project_id'] ?? null;
        $status_filter = $_GET['status'] ?? '';
        $checkin_filter = $_GET['check_in_date'] ?? '';
        $checkout_filter = $_GET['check_out_date'] ?? '';
        
        // 构建查询条件
        $where_conditions = [];
        $params = [];
        
        if ($project_id) {
            $where_conditions[] = "hr.project_id = :project_id";
            $params[':project_id'] = $project_id;
        }
        
        if ($status_filter) {
            $where_conditions[] = "hr.status = :status";
            $params[':status'] = $status_filter;
        }
        
        if ($checkin_filter) {
            $where_conditions[] = "hr.check_in_date >= :check_in_date";
            $params[':check_in_date'] = $checkin_filter;
        }
        
        if ($checkout_filter) {
            $where_conditions[] = "hr.check_out_date <= :check_out_date";
            $params[':check_out_date'] = $checkout_filter;
        }
        
        $where_clause = $where_conditions ? "WHERE " . implode(' AND ', $where_conditions) : "";
        
        // 获取报酒店列表
        $query = "SELECT 
            MIN(hr.id) as id,
            hr.hotel_name,
            hr.room_type,
            hr.check_in_date,
            hr.check_out_date,
            hr.room_count,
            MIN(hr.status) as status,
            MIN(hr.special_requirements) as special_requirements,
            MIN(hr.created_at) as created_at,
            MIN(hr.updated_at) as updated_at,
            MIN(hr.reported_by) as reported_by,
            MIN(hr.project_id) as project_id,
            p.name as project_name,
            p.code as project_code,
            GROUP_CONCAT(DISTINCT pr.name ORDER BY pr.name SEPARATOR '、') as personnel_name,
            GROUP_CONCAT(DISTINCT pr.id ORDER BY pr.name SEPARATOR ',') as personnel_ids,
            COUNT(DISTINCT hr.personnel_id) as personnel_count,
            pu.username as reporter_name,
            GROUP_CONCAT(DISTINCT hr.id ORDER BY hr.id) as record_ids,
            MIN(hr.shared_room_info) as shared_room_info
        FROM hotel_reports hr
        JOIN projects p ON hr.project_id = p.id
        JOIN personnel pr ON hr.personnel_id = pr.id
        JOIN project_users pu ON hr.reported_by = pu.id
        $where_clause
        GROUP BY hr.hotel_name, hr.room_type, hr.check_in_date, hr.check_out_date, hr.project_id, hr.room_count, pu.username,
            CASE 
                WHEN hr.room_type IN ('双床房', '双人房', '套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' 
                THEN hr.shared_room_info
                ELSE CONCAT('独立_', hr.id)
            END
        ORDER BY MIN(hr.created_at) DESC";
        
        $stmt = $db->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 构建统计查询的WHERE条件
        $stats_where_conditions = $where_conditions;
        $stats_where_conditions[] = "hr.hotel_name IS NOT NULL";
        $stats_where_conditions[] = "hr.status != 'cancelled'";
        $stats_where_clause = $stats_where_conditions ? "WHERE " . implode(' AND ', $stats_where_conditions) : "";
        
        // 获取项目总统计
        $project_total_query = "
            SELECT 
                COUNT(DISTINCT hr.personnel_id) as total_checkins,
                COALESCE(SUM(
                    CASE 
                        WHEN hr.room_type IN ('双床房','双人房','套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' THEN 1
                        ELSE hr.room_count
                    END
                ), 0) as total_booked_rooms,
                COUNT(*) as total_bookings,
                COALESCE(SUM(DATEDIFF(hr.check_out_date, hr.check_in_date) * 
                    CASE 
                        WHEN hr.room_type IN ('双床房','双人房','套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' THEN 1
                        ELSE hr.room_count
                    END
                ), 0) as total_room_nights
            FROM hotel_reports hr
            $stats_where_clause
        ";
        
        $project_total_stmt = $db->prepare($project_total_query);
        foreach ($params as $key => $value) {
            $project_total_stmt->bindValue($key, $value);
        }
        $project_total_stmt->execute();
        $project_total = $project_total_stmt->fetch(PDO::FETCH_ASSOC);
        
        // 返回数据
        echo json_encode([
            'success' => true,
            'reports' => $reports,
            'stats' => $project_total
        ]);
        exit;
    }
    
    // 处理AJAX POST请求（执行操作）
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        $response = ['success' => false, 'message' => '未知操作'];
        
        // 单个状态更新
        if ($action === 'update_status' && isset($_POST['id'], $_POST['status'])) {
            $id = intval($_POST['id']);
            $status = $_POST['status'];
            
            if (in_array($status, ['pending', 'confirmed', 'cancelled'])) {
                $query = "UPDATE hotel_reports SET status = :status WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':id', $id);
                
                if ($stmt->execute()) {
                    $response['success'] = true;
                    $response['message'] = '状态更新成功！';
                } else {
                    $response['message'] = '更新失败，请重试！';
                }
            } else {
                $response['message'] = '无效的状态值！';
            }
        }
        
        // 批量确认
        if ($action === 'batch_confirm' && isset($_POST['ids'])) {
            $ids_str = $_POST['ids'];
            $ids = explode(',', $ids_str);
            $ids = array_map('intval', $ids);
            
            if (count($ids) > 0) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $query = "UPDATE hotel_reports SET status = 'confirmed' WHERE id IN ($placeholders)";
                $stmt = $db->prepare($query);
                if ($stmt->execute($ids)) {
                    $response['success'] = true;
                    $response['message'] = '批量确认成功！';
                } else {
                    $response['message'] = '批量确认失败，请重试！';
                }
            } else {
                $response['message'] = '请选择至少一条记录！';
            }
        }
        
        // 删除操作
        if ($action === 'delete' && isset($_POST['id'])) {
            $id = intval($_POST['id']);
            
            $query = "DELETE FROM hotel_reports WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = '记录删除成功！';
            } else {
                $response['message'] = '删除失败，请重试！';
            }
        }
        
        // 批量删除
        if ($action === 'batch_delete' && isset($_POST['ids'])) {
            $ids_str = $_POST['ids'];
            $ids = explode(',', $ids_str);
            $ids = array_map('intval', $ids);
            
            if (count($ids) > 0) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $query = "DELETE FROM hotel_reports WHERE id IN ($placeholders)";
                $stmt = $db->prepare($query);
                if ($stmt->execute($ids)) {
                    $response['success'] = true;
                    $response['message'] = '批量删除成功！';
                } else {
                    $response['message'] = '批量删除失败，请重试！';
                }
            } else {
                $response['message'] = '请选择至少一条记录！';
            }
        }
        
        // 输出响应
        echo json_encode($response);
        exit;
    }
}

// 处理传统表单请求（保持向后兼容性）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['ajax'])) {
    $action = $_POST['action'] ?? '';
    
    // 处理单个状态更新
    if ($action === 'update_status') {
        $id = intval($_POST['id']);
        $status = $_POST['status'];
        
        if (in_array($status, ['pending', 'confirmed', 'cancelled'])) {
            $query = "UPDATE hotel_reports SET status = :status WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                $message = '报酒店状态更新成功！';
            } else {
                $error = '更新失败，请重试！';
            }
        }
    }
    
    // 处理批量状态更新
    if ($action === 'batch_status_update') {
        $ids = $_POST['ids'] ?? [];
        $status = $_POST['status'] ?? '';
        
        if (is_array($ids) && count($ids) > 0 && in_array($status, ['pending', 'confirmed', 'cancelled'])) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $query = "UPDATE hotel_reports SET status = ? WHERE id IN ($placeholders)";
            $stmt = $db->prepare($query);
            
            // 绑定参数：状态 + IDs
            $params = array_merge([$status], $ids);
            
            if ($stmt->execute($params)) {
                // 如果是AJAX请求，返回JSON
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => '状态更新成功！']);
                    exit;
                }
                $message = '状态更新成功！';
            } else {
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => '更新失败，请重试！']);
                    exit;
                }
                $error = '更新失败，请重试！';
            }
        }
    }
    // 批量确认
    if ($action === 'batch_confirm') {
        $ids = $_POST['ids'] ?? [];
        if (is_array($ids) && count($ids) > 0) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $query = "UPDATE hotel_reports SET status = 'confirmed' WHERE id IN ($placeholders)";
            $stmt = $db->prepare($query);
            if ($stmt->execute($ids)) {
                $message = '批量确认成功！';
            } else {
                $error = '批量确认失败，请重试！';
            }
        }
    }
    // 删除操作
    if ($action === 'delete') {
        $id = intval($_POST['id']);
        
        $query = "DELETE FROM hotel_reports WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            $message = '报酒店记录删除成功！';
        } else {
            $error = '删除失败，请重试！';
        }
    }
    // 批量删除
    if ($action === 'batch_delete') {
        $ids = $_POST['ids'] ?? [];
        if (is_array($ids) && count($ids) > 0) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $query = "DELETE FROM hotel_reports WHERE id IN ($placeholders)";
            $stmt = $db->prepare($query);
            if ($stmt->execute($ids)) {
                $message = '批量删除成功！';
            } else {
                $error = '批量删除失败，请重试！';
            }
        }
    }
}

// 获取筛选参数
$project_id = $_GET['project_id'] ?? null;
$status_filter = $_GET['status'] ?? '';
$checkin_filter = $_GET['check_in_date'] ?? '';
$checkout_filter = $_GET['check_out_date'] ?? '';

// 构建查询条件
$where_conditions = [];
$params = [];

if ($project_id) {
    $where_conditions[] = "hr.project_id = :project_id";
    $params[':project_id'] = $project_id;
}

if ($status_filter) {
    $where_conditions[] = "hr.status = :status";
    $params[':status'] = $status_filter;
}

if ($checkin_filter) {
    $where_conditions[] = "hr.check_in_date >= :check_in_date";
    $params[':check_in_date'] = $checkin_filter;
}

if ($checkout_filter) {
    $where_conditions[] = "hr.check_out_date <= :check_out_date";
    $params[':check_out_date'] = $checkout_filter;
}

$where_clause = $where_conditions ? "WHERE " . implode(' AND ', $where_conditions) : "";

// 获取报酒店列表 - 合并双床房/双人房共享房间人员
$query = "SELECT 
    MIN(hr.id) as id,
    hr.hotel_name,
    hr.room_type,
    hr.check_in_date,
    hr.check_out_date,
    hr.room_count,
    MIN(hr.status) as status,
    MIN(hr.special_requirements) as special_requirements,
    MIN(hr.created_at) as created_at,
    MIN(hr.updated_at) as updated_at,
    MIN(hr.reported_by) as reported_by,
    MIN(hr.project_id) as project_id,
    p.name as project_name,
    p.code as project_code,
    GROUP_CONCAT(DISTINCT pr.name ORDER BY pr.name SEPARATOR '、') as personnel_name,
    GROUP_CONCAT(DISTINCT pr.id ORDER BY pr.name SEPARATOR ',') as personnel_ids,
    COUNT(DISTINCT hr.personnel_id) as personnel_count,
    pu.username as reporter_name,
    GROUP_CONCAT(DISTINCT hr.id ORDER BY hr.id) as record_ids,
    MIN(hr.shared_room_info) as shared_room_info
FROM hotel_reports hr
JOIN projects p ON hr.project_id = p.id
JOIN personnel pr ON hr.personnel_id = pr.id
JOIN project_users pu ON hr.reported_by = pu.id
$where_clause
GROUP BY hr.hotel_name, hr.room_type, hr.check_in_date, hr.check_out_date, hr.project_id, hr.room_count, pu.username,
    CASE 
        WHEN hr.room_type IN ('双床房', '双人房', '套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' 
        THEN hr.shared_room_info
        ELSE CONCAT('独立_', hr.id)
    END
ORDER BY MIN(hr.created_at) DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取统计信息 - 按照hotel_statistics.php的方式合并双床房计算
$stats = [];

// 构建统计查询的WHERE条件（包含固定条件）
$stats_where_conditions = $where_conditions;
$stats_where_conditions[] = "hr.hotel_name IS NOT NULL";
$stats_where_conditions[] = "hr.status != 'cancelled'";
$stats_where_clause = $stats_where_conditions ? "WHERE " . implode(' AND ', $stats_where_conditions) : "";

// 1. 基本统计（合并双床房共享房间计算）
$basic_stats_query = "SELECT 
    hr.hotel_name,
    COUNT(DISTINCT hr.personnel_id) as total_checkins,
    COALESCE(SUM(
        CASE 
            WHEN hr.room_type IN ('双床房','双人房','套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' THEN 1
            ELSE hr.room_count
        END
    ), 0) as total_booked_rooms,
    COUNT(*) as total_bookings,
    COALESCE(SUM(DATEDIFF(hr.check_out_date, hr.check_in_date) * 
        CASE 
            WHEN hr.room_type IN ('双床房','双人房','套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' THEN 1
            ELSE hr.room_count
        END
    ), 0) as total_room_nights
FROM hotel_reports hr
$stats_where_clause
GROUP BY hr.hotel_name
ORDER BY total_bookings DESC";

$basic_stats_stmt = $db->prepare($basic_stats_query);
foreach ($params as $key => $value) {
    $basic_stats_stmt->bindValue($key, $value);
}
$basic_stats_stmt->execute();
$basic_stats = $basic_stats_stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. 房型统计（合并双床房计算）
$room_type_stats_query = "SELECT 
    hr.hotel_name,
    hr.room_type,
    COUNT(*) as bookings_count,
    COALESCE(SUM(
        CASE 
            WHEN hr.room_type IN ('双床房','双人房','套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' THEN 1
            ELSE hr.room_count
        END
    ), 0) as total_rooms,
    COALESCE(SUM(DATEDIFF(hr.check_out_date, hr.check_in_date) * 
        CASE 
            WHEN hr.room_type IN ('双床房','双人房','套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' THEN 1
            ELSE hr.room_count
        END
    ), 0) as total_room_nights,
    MIN(hr.check_in_date) as earliest_checkin,
    MAX(hr.check_out_date) as latest_checkout
FROM hotel_reports hr
$stats_where_clause
GROUP BY hr.hotel_name, hr.room_type
ORDER BY hr.hotel_name, bookings_count DESC";

$room_type_stats_stmt = $db->prepare($room_type_stats_query);
foreach ($params as $key => $value) {
    $room_type_stats_stmt->bindValue($key, $value);
}
$room_type_stats_stmt->execute();
$room_type_stats = $room_type_stats_stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. 项目总统计（当前筛选条件下的总计）
$project_total_query = "
    SELECT 
        COUNT(DISTINCT hr.personnel_id) as total_checkins,
        COALESCE(SUM(
            CASE 
                WHEN hr.room_type IN ('双床房','双人房','套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' THEN 1
                ELSE hr.room_count
            END
        ), 0) as total_booked_rooms,
        COUNT(*) as total_bookings,
        COALESCE(SUM(DATEDIFF(hr.check_out_date, hr.check_in_date) * 
            CASE 
                WHEN hr.room_type IN ('双床房','双人房','套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' THEN 1
                ELSE hr.room_count
            END
        ), 0) as total_room_nights
    FROM hotel_reports hr
    $stats_where_clause
";

$project_total_stmt = $db->prepare($project_total_query);
foreach ($params as $key => $value) {
    $project_total_stmt->bindValue($key, $value);
}
$project_total_stmt->execute();
$project_total = $project_total_stmt->fetch(PDO::FETCH_ASSOC);

// 获取项目列表用于筛选
$projects_query = "SELECT id, name, code FROM projects ORDER BY name ASC";
$projects_stmt = $db->prepare($projects_query);
$projects_stmt->execute();
$projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取人员列表用于编辑表单
$personnel_query = "SELECT id, name FROM personnel ORDER BY name ASC";
$personnel_stmt = $db->prepare($personnel_query);
$personnel_stmt->execute();
$personnel_list = $personnel_stmt->fetchAll(PDO::FETCH_ASSOC);

// 检查project_hotels表是否存在
$check_table_query = "SHOW TABLES LIKE 'project_hotels'";
$check_stmt = $db->prepare($check_table_query);
$check_stmt->execute();
$table_exists = ($check_stmt->rowCount() > 0);

// 获取项目对应的酒店数据
if ($table_exists) {
    // 使用project_hotels关联表
    $project_hotels_query = "SELECT 
        h.id as hotel_id,
        h.hotel_name_cn as hotel_name,
        h.room_types,
        p.id as project_id,
        p.name as project_name
    FROM hotels h
    JOIN project_hotels ph ON h.id = ph.hotel_id
    JOIN projects p ON ph.project_id = p.id
    ORDER BY p.name ASC, h.hotel_name_cn ASC";
} else {
    // 使用projects表的hotel_id字段（向后兼容）
    $project_hotels_query = "SELECT 
        h.id as hotel_id,
        h.hotel_name_cn as hotel_name,
        h.room_types,
        p.id as project_id,
        p.name as project_name
    FROM hotels h
    JOIN projects p ON h.id = p.hotel_id
    ORDER BY p.name ASC, h.hotel_name_cn ASC";
}

// 获取所有酒店数据作为备选
$all_hotels_query = "SELECT 
    h.id as hotel_id,
    h.hotel_name_cn as hotel_name,
    h.room_types,
    'all' as project_id,
    '所有酒店' as project_name
FROM hotels h
WHERE h.hotel_name_cn IS NOT NULL AND h.hotel_name_cn != ''
ORDER BY h.hotel_name_cn ASC";
$all_hotels_stmt = $db->prepare($all_hotels_query);
$all_hotels_stmt->execute();
$all_hotels_data = $all_hotels_stmt->fetchAll(PDO::FETCH_ASSOC);

$project_hotels_stmt = $db->prepare($project_hotels_query);
$project_hotels_stmt->execute();
$project_hotels_data = $project_hotels_stmt->fetchAll(PDO::FETCH_ASSOC);

// 整理项目酒店数据为JSON格式
$project_hotels_json = [];
foreach ($project_hotels_data as $hotel) {
    $project_id = $hotel['project_id'];
    if (!isset($project_hotels_json[$project_id])) {
        $project_hotels_json[$project_id] = [];
    }
    
    $room_types = json_decode($hotel['room_types'], true) ?? [];
    $project_hotels_json[$project_id][] = [
        'hotel_id' => $hotel['hotel_id'],
        'hotel_name' => $hotel['hotel_name'],
        'room_types' => $room_types
    ];
}

// 添加所有酒店到特殊项目ID
$project_hotels_json['all'] = [];
foreach ($all_hotels_data as $hotel) {
    $room_types = json_decode($hotel['room_types'], true) ?? [];
    $project_hotels_json['all'][] = [
        'hotel_id' => $hotel['hotel_id'],
        'hotel_name' => $hotel['hotel_name'],
        'room_types' => $room_types
    ];
}

// 状态映射
$status_map = [
    'pending' => ['label' => '待确认', 'class' => 'warning'],
    'confirmed' => ['label' => '已确认', 'class' => 'success'],
    'cancelled' => ['label' => '已取消', 'class' => 'danger']
];

// 处理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}
?>

<?php include 'includes/header.php'; ?>

<div class="container-fluid">
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

    <!-- 加载状态遮罩 -->
    <div id="loadingOverlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(255, 255, 255, 0.8); z-index: 9999; justify-content: center; align-items: center;">
        <div class="text-center">
            <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status"></div>
            <p class="mt-3">加载中，请稍候...</p>
        </div>
    </div>
    
    <!-- 错误消息容器 -->
    <div id="errorContainer" class="alert alert-danger" style="display: none; margin-bottom: 15px;"></div>
    
    <!-- 筛选表单 -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-funnel"></i> 筛选条件</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-2">
                    <label for="project_id" class="form-label">项目</label>
                    <select class="form-select" id="project_id">
                        <!-- 改进后的选中逻辑，确保在所有情况下都能正确设置 -->
                        <option value="" <?php echo (is_null($project_id) || $project_id === '' || $project_id === false) ? 'selected' : ''; ?>>所有项目</option>
                        <?php foreach ($projects as $project): ?>
                            <option value="<?php echo $project['id']; ?>" 
                                    <?php echo (!is_null($project_id) && $project_id !== '' && $project_id != false && $project_id == $project['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($project['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="status" class="form-label">状态</label>
                    <select class="form-select" id="status">
                        <option value="">所有状态</option>
                        <option value="pending" <?php echo ($status_filter === 'pending') ? 'selected' : ''; ?>>待确认</option>
                        <option value="confirmed" <?php echo ($status_filter === 'confirmed') ? 'selected' : ''; ?>>已确认</option>
                        <option value="cancelled" <?php echo ($status_filter === 'cancelled') ? 'selected' : ''; ?>>已取消</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="check_in_date" class="form-label">入住日期</label>
                    <input type="date" class="form-control" id="check_in_date" 
                           value="<?php echo htmlspecialchars($checkin_filter); ?>">
                </div>
                <div class="col-md-3">
                    <label for="check_out_date" class="form-label">退房日期</label>
                    <input type="date" class="form-control" id="check_out_date" 
                           value="<?php echo htmlspecialchars($checkout_filter); ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button id="filterButton" type="button" class="btn btn-primary me-2">
                        <i class="bi bi-search"></i> 筛选
                    </button>
                    <button id="resetButton" type="button" class="btn btn-secondary">
                        <i class="bi bi-arrow-clockwise"></i> 重置
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 报酒店列表容器 -->
    <div id="reportsContainer">
        <!-- 内容将通过AJAX动态加载 -->
    </div>
</div>

<script>
// 页面加载完成后初始化
document.addEventListener('DOMContentLoaded', function() {
    // 初始化数据加载
    loadReports();
    
    // 筛选按钮事件
    document.getElementById('filterButton').addEventListener('click', function() {
        loadReports();
    });
    
    // 重置按钮事件
    document.getElementById('resetButton').addEventListener('click', function() {
        document.getElementById('project_id').value = '';
        document.getElementById('status').value = '';
        document.getElementById('check_in_date').value = '';
        document.getElementById('check_out_date').value = '';
        loadReports();
    });
});

// 加载报酒店列表
function loadReports() {
    showLoading();
    
    // 获取筛选参数
    const projectId = document.getElementById('project_id').value;
    const status = document.getElementById('status').value;
    const checkInDate = document.getElementById('check_in_date').value;
    const checkOutDate = document.getElementById('check_out_date').value;
    
    // 构建查询参数
    const params = new URLSearchParams();
    params.append('ajax', '1');
    if (projectId) params.append('project_id', projectId);
    if (status) params.append('status', status);
    if (checkInDate) params.append('check_in_date', checkInDate);
    if (checkOutDate) params.append('check_out_date', checkOutDate);
    
    // 发送AJAX请求
    fetch('hotel_reports.php?' + params.toString())
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                renderReports(data.reports, data.stats);
            } else {
                showError('加载数据失败');
            }
        })
        .catch(error => {
            hideLoading();
            showError('网络错误: ' + error.message);
        });
}

// 显示加载状态
function showLoading() {
    document.getElementById('loadingOverlay').style.display = 'flex';
}

// 隐藏加载状态
function hideLoading() {
    document.getElementById('loadingOverlay').style.display = 'none';
}

// 显示错误消息
function showError(message) {
    const errorContainer = document.getElementById('errorContainer');
    errorContainer.textContent = message;
    errorContainer.style.display = 'block';
    
    // 3秒后自动隐藏
    setTimeout(() => {
        errorContainer.style.display = 'none';
    }, 3000);
}

// 渲染报酒店列表
function renderReports(reports, stats) {
    const container = document.getElementById('reportsContainer');
    
    if (reports.length === 0) {
        container.innerHTML = `
            <div class="text-center py-5">
                <i class="bi bi-building display-1 text-muted"></i>
                <h4 class="mt-3">暂无报酒店记录</h4>
                <p class="text-muted">当前筛选条件下没有找到报酒店记录</p>
            </div>
        `;
        return;
    }
    
    // 构建HTML
    let html = `
        <!-- 统计信息 -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h5 class="card-title">${stats.total_checkins || 0}</h5>
                        <p class="card-text">总入住人次</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h5 class="card-title">${stats.total_booked_rooms || 0}</h5>
                        <p class="card-text">预订房间数</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h5 class="card-title">${stats.total_bookings || 0}</h5>
                        <p class="card-text">总预订记录</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body">
                        <h5 class="card-title">${stats.total_room_nights || 0}</h5>
                        <p class="card-text">总房晚数</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 报酒店列表 -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-list"></i> 报酒店记录列表</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>项目</th>
                                <th>酒店名称</th>
                                <th>房型</th>
                                <th>入住日期</th>
                                <th>退房日期</th>
                                <th>房间数</th>
                                <th>入住人员</th>
                                <th>状态</th>
                                <th>报告人</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
    `;
    
    reports.forEach(report => {
        html += `
            <tr>
                <td>
                    <code>${report.project_code}</code><br>
                    <small>${report.project_name}</small>
                </td>
                <td>${report.hotel_name}</td>
                <td>${report.room_type}</td>
                <td>${report.check_in_date}</td>
                <td>${report.check_out_date}</td>
                <td>${report.room_count}</td>
                <td>
                    ${report.personnel_name ? report.personnel_name.replace(/、/g, '<br>') : '-'}
                </td>
                <td>
                    <span class="badge bg-${getStatusClass(report.status)}">
                        ${getStatusLabel(report.status)}
                    </span>
                </td>
                <td>${report.reporter_name}</td>
                <td>
                    <div class="btn-group btn-group-sm" role="group">
                        <select class="form-select form-select-sm" onchange="updateStatus(${report.id}, this.value)">
                            <option value="pending" ${report.status === 'pending' ? 'selected' : ''}>待确认</option>
                            <option value="confirmed" ${report.status === 'confirmed' ? 'selected' : ''}>已确认</option>
                            <option value="cancelled" ${report.status === 'cancelled' ? 'selected' : ''}>已取消</option>
                        </select>
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteReport(${report.id})">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    });
    
    html += `
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    `;
    
    container.innerHTML