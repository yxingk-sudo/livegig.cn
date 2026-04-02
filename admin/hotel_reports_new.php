<?php
// 酒店报告管理系统 - 简洁高效版本

// 1. 初始化设置
if (session_status() === PHP_SESSION_NONE) {
    session_start();

// 引入基础控制器进行权限验证
require_once '../includes/BaseAdminController.php';
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db.php';

// 2. 权限检查
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// 设置页面标题和活动页面
$page_title = '酒店报告管理';
$active_page = 'hotel_reports';

// 3. 核心数据处理函数
function getHotelReports($filters = [], $page = 1, $pageSize = 20) {
    $pdo = get_db_connection();
    
    $where = [];
    $params = [];
    
    // 构建筛选条件
    if (!empty($filters['project_id'])) {
        $where[] = "hr.project_id = :project_id";
        $params[':project_id'] = $filters['project_id'];
    }
    
    if (!empty($filters['status'])) {
        $where[] = "hr.status = :status";
        $params[':status'] = $filters['status'];
    }
    
    if (!empty($filters['check_in_date'])) {
        $where[] = "hr.check_in_date >= :check_in_date";
        $params[':check_in_date'] = $filters['check_in_date'];
    }
    
    if (!empty($filters['check_out_date'])) {
        $where[] = "hr.check_out_date <= :check_out_date";
        $params[':check_out_date'] = $filters['check_out_date'];
    }
    
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // 计算总记录数 - 注意：这里需要重新构建查询来获取总数
    $countSql = "
        SELECT COUNT(*) as total_count
        FROM (
            SELECT 
                1 
            FROM hotel_reports hr
            LEFT JOIN projects p ON hr.project_id = p.id
            LEFT JOIN project_department_personnel pdp ON hr.personnel_id = pdp.personnel_id AND hr.project_id = pdp.project_id
            LEFT JOIN departments d ON pdp.department_id = d.id
            $whereClause
            GROUP BY 
                p.id,
                CASE 
                    WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1)
                    ELSE hr.hotel_name
                END,
                hr.room_type, 
                hr.check_in_date, 
                hr.check_out_date,
                CASE 
                    WHEN hr.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' AND hr.shared_room_info != '无' THEN hr.shared_room_info
                    ELSE CONCAT('individual_', hr.id)  -- 使用唯一标识，避免不同记录被合并
                END
        ) as subquery
    ";
    
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetchColumn();
    
    // 计算分页参数
    $page = max(1, intval($page));
    $pageSize = max(1, intval($pageSize));
    $offset = ($page - 1) * $pageSize;
    $totalPages = ceil($totalCount / $pageSize);
    
    // 主查询 - 按实际房间数统计，支持共享的房型（双床房、套房、大床房、总统套房、副总统套房）共享时只算1间
    // 使用与hotel_statistics.php一致的业务逻辑
    // 修改查询以按照部门顺序排序
    $sql = "
        SELECT 
            GROUP_CONCAT(hr.id) as record_ids,
            p.name as project_name,
            CASE 
                WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1)
                ELSE hr.hotel_name
            END as normalized_hotel_name,
            CONCAT(
                CASE 
                    WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1)
                    ELSE hr.hotel_name
                END,
                CASE 
                    WHEN h.hotel_name_en IS NOT NULL AND h.hotel_name_en != '' 
                    THEN CONCAT('<br><small class=\"text-muted\">', h.hotel_name_en, '</small>')
                    ELSE ''
                END
            ) as hotel_name,
            CASE 
                WHEN hr.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' AND hr.shared_room_info != '无' THEN 
                    GROUP_CONCAT(DISTINCT per.name ORDER BY per.name SEPARATOR ', ')  -- 共享房间显示所有入住人
                ELSE 
                    GROUP_CONCAT(DISTINCT per.name ORDER BY per.name SEPARATOR ', ')  -- 非共享房间也显示所有入住人
            END as personnel_name,
            hr.room_type,
            hr.check_in_date,
            hr.check_out_date,
            CASE 
                WHEN hr.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' AND hr.shared_room_info != '无' THEN 1
                ELSE MIN(hr.room_count)
            END as room_count,
            CASE 
                WHEN hr.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' AND hr.shared_room_info != '无' THEN 
                    COUNT(DISTINCT hr.personnel_id)  -- 共享房间统计入住人数
                ELSE 
                    COUNT(DISTINCT hr.personnel_id)  -- 非共享房间也统计入住人数
            END as total_guests,
            DATEDIFF(hr.check_out_date, hr.check_in_date) as total_nights,
            (
                DATEDIFF(hr.check_out_date, hr.check_in_date) * 
                CASE 
                    WHEN hr.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' AND hr.shared_room_info != '无' THEN 1
                    ELSE MIN(hr.room_count)
                END
            ) as total_room_nights,
            MAX(hr.status) as status,
            MAX(hr.shared_room_info) as shared_room_info,
            GROUP_CONCAT(DISTINCT hr.special_requirements SEPARATOR '; ') as special_requirements
        FROM hotel_reports hr
        LEFT JOIN projects p ON hr.project_id = p.id
        LEFT JOIN hotels h ON (
            CASE 
                WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1)
                ELSE hr.hotel_name
            END
        ) = h.hotel_name_cn
        LEFT JOIN personnel per ON hr.personnel_id = per.id
        LEFT JOIN project_department_personnel pdp ON hr.personnel_id = pdp.personnel_id AND hr.project_id = pdp.project_id
        LEFT JOIN departments d ON pdp.department_id = d.id
        $whereClause
        GROUP BY 
            p.id,
            CASE 
                WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1)
                ELSE hr.hotel_name
            END,
            hr.room_type, 
            hr.check_in_date, 
            hr.check_out_date,
            CASE 
                WHEN hr.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' AND hr.shared_room_info != '无' THEN hr.shared_room_info
                ELSE CONCAT('individual_', hr.id)  -- 使用唯一标识，避免不同记录被合并
            END
        ORDER BY COALESCE(d.sort_order, 0) ASC, d.id ASC, per.name ASC, hr.check_in_date DESC, p.name, normalized_hotel_name
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $pdo->prepare($sql);
    // 添加分页参数
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    // 绑定其他参数
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->execute();
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 返回结果和分页信息
    return [
        'data' => $reports,
        'total_count' => $totalCount,
        'total_pages' => $totalPages,
        'current_page' => $page,
        'page_size' => $pageSize
    ];
}

function getStatistics($filters = []) {
    $pdo = get_db_connection();
    
    $where = [];
    $params = [];
    
    // 构建筛选条件（同上）
    if (!empty($filters['project_id'])) {
        $where[] = "project_id = :project_id";
        $params[':project_id'] = $filters['project_id'];
    }
    
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // 基础统计 - 按实际房间数统计，支持共享的房型（双床房、套房、大床房、总统套房、副总统套房）共享时只算1间
    // 使用与hotel_statistics.php一致的业务逻辑
    $statsSql = "
        SELECT 
            COUNT(*) as total_bookings,
            SUM(actual_rooms) as total_booked_rooms,
            SUM(actual_room_nights) as total_room_nights,
            (SELECT SUM(hotel_checkins) FROM (
                SELECT 
                    CASE 
                        WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
                        ELSE hotel_name
                    END as normalized_hotel_name,
                    COUNT(DISTINCT personnel_id) as hotel_checkins
                FROM hotel_reports 
                WHERE project_id = :project_id
                GROUP BY 
                    CASE 
                        WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
                        ELSE hotel_name
                    END
            ) as hotel_stats) as total_checkins
        FROM (
            SELECT 
                CASE 
                    WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
                    ELSE hotel_name
                END as normalized_hotel_name,
                room_type,
                check_in_date,
                check_out_date,
                room_count,
                shared_room_info,
                CASE 
                    WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' AND shared_room_info != '无' THEN 1
                    ELSE room_count
                END as actual_rooms,
                CASE 
                    WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' AND shared_room_info != '无' THEN 
                        DATEDIFF(check_out_date, check_in_date) * 1
                    ELSE 
                        DATEDIFF(check_out_date, check_in_date) * room_count
                END as actual_room_nights
            FROM hotel_reports
            $whereClause
            GROUP BY 
                CASE 
                    WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
                    ELSE hotel_name
                END,
                room_type,
                check_in_date,
                check_out_date,
                room_count,
                CASE 
                    WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' AND shared_room_info != '无' THEN shared_room_info
                    ELSE CONCAT(id, '-', room_type, '-', room_count)
                END
        ) as grouped_reports
    ";
    
    $stmt = $pdo->prepare($statsSql);
    $stmt->execute($params);
    $basicStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 酒店统计 - 按实际房间数统计，支持共享的房型（双床房、套房、大床房、总统套房、副总统套房）共享时只算1间
    // 使用与hotel_statistics.php一致的业务逻辑
    $hotelStatsSql = "
        SELECT 
            CONCAT(
                normalized_hotel_name,
                CASE 
                    WHEN hotel_name_en IS NOT NULL AND hotel_name_en != '' 
                    THEN CONCAT('<br><small class=\"text-muted\">', hotel_name_en, '</small>')
                    ELSE ''
                END
            ) as hotel_name,
            COUNT(*) as total_bookings,
            SUM(actual_rooms) as total_booked_rooms,
            SUM(actual_room_nights) as total_room_nights,
            (SELECT COUNT(DISTINCT personnel_id) FROM hotel_reports hr2 WHERE hr2.project_id = :project_id AND (CASE WHEN hr2.hotel_name LIKE '% - %' THEN SUBSTRING(hr2.hotel_name, 1, LOCATE(' - ', hr2.hotel_name) - 1) ELSE hr2.hotel_name END) = normalized_hotel_name) as total_checkins
        FROM (
            SELECT 
                CASE 
                    WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1)
                    ELSE hr.hotel_name
                END as normalized_hotel_name,
                h.hotel_name_en,
                hr.room_type,
                hr.check_in_date,
                hr.check_out_date,
                hr.room_count,
                hr.shared_room_info,
                hr.project_id,
                CASE 
                    WHEN hr.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' AND hr.shared_room_info != '无' THEN 1
                    ELSE hr.room_count
                END as actual_rooms,
                CASE 
                    WHEN hr.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' AND hr.shared_room_info != '无' THEN 
                        DATEDIFF(hr.check_out_date, hr.check_in_date) * 1
                    ELSE 
                        DATEDIFF(hr.check_out_date, hr.check_in_date) * hr.room_count
                END as actual_room_nights
            FROM hotel_reports hr
            LEFT JOIN hotels h ON (
                CASE 
                    WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1)
                    ELSE hr.hotel_name
                END
            ) = h.hotel_name_cn
            $whereClause
            GROUP BY 
                CASE 
                    WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1)
                    ELSE hr.hotel_name
                END,
                h.hotel_name_en,
                hr.room_type,
                hr.check_in_date,
                hr.check_out_date,
                hr.room_count,
                hr.project_id,
                CASE 
                    WHEN hr.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' AND hr.shared_room_info != '无' THEN hr.shared_room_info
                    ELSE CONCAT(hr.id, '-', hr.room_type, '-', hr.room_count)
                END
        ) as hotel_groups
        GROUP BY normalized_hotel_name, hotel_name_en, project_id
        ORDER BY total_bookings DESC
    ";
    
    $stmt = $pdo->prepare($hotelStatsSql);
    $stmt->execute($params);
    $hotelStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 房型统计 - 按实际房间数统计，支持共享的房型（双床房、套房、大床房、总统套房、副总统套房）共享时只算1间
    // 使用与hotel_statistics.php一致的业务逻辑
    $roomTypeSql = "
        SELECT 
            CONCAT(
                normalized_hotel_name,
                CASE 
                    WHEN hotel_name_en IS NOT NULL AND hotel_name_en != '' 
                    THEN CONCAT('<br><small class=\"text-muted\">', hotel_name_en, '</small>')
                    ELSE ''
                END
            ) as hotel_name,
            room_type,
            COUNT(*) as bookings_count,
            SUM(actual_rooms) as total_booked_rooms,
            SUM(actual_room_nights) as total_room_nights,
            SUM(personnel_count) as total_checkins,
            MIN(check_in_date) as earliest_checkin,
            MAX(check_out_date) as latest_checkout
        FROM (
            SELECT 
                CASE 
                    WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1)
                    ELSE hr.hotel_name
                END as normalized_hotel_name,
                h.hotel_name_en,
                hr.room_type,
                hr.check_in_date,
                hr.check_out_date,
                hr.room_count,
                hr.shared_room_info,
                COUNT(DISTINCT hr.personnel_id) as personnel_count,
                CASE 
                    WHEN hr.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' AND hr.shared_room_info != '无' THEN 1
                    ELSE hr.room_count
                END as actual_rooms,
                CASE 
                    WHEN hr.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' AND hr.shared_room_info != '无' THEN 
                        DATEDIFF(hr.check_out_date, hr.check_in_date) * 1
                    ELSE 
                        DATEDIFF(hr.check_out_date, hr.check_in_date) * hr.room_count
                END as actual_room_nights
            FROM hotel_reports hr
            LEFT JOIN hotels h ON (
                CASE 
                    WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1)
                    ELSE hr.hotel_name
                END
            ) = h.hotel_name_cn
            $whereClause
            GROUP BY 
                CASE 
                    WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1)
                    ELSE hr.hotel_name
                END,
                h.hotel_name_en,
                hr.room_type,
                hr.check_in_date,
                hr.check_out_date,
                hr.room_count,
                CASE 
                    WHEN hr.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' AND hr.shared_room_info != '无' THEN hr.shared_room_info
                    ELSE CONCAT(hr.id, '-', hr.room_type, '-', hr.room_count)
                END
        ) as room_groups
        GROUP BY normalized_hotel_name, hotel_name_en, room_type
        ORDER BY normalized_hotel_name, room_type
    ";
    
    $stmt = $pdo->prepare($roomTypeSql);
    $stmt->execute($params);
    $roomTypeStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'basic' => $basicStats,
        'hotels' => $hotelStats,
        'room_types' => $roomTypeStats
    ];
}

// 5. AJAX请求处理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'batch_status_update':
                $ids = $_POST['ids'] ?? [];
                $status = $_POST['status'] ?? '';
                
                if (empty($ids) || empty($status)) {
                    throw new Exception('参数不完整');
                }
                
                // 确保ids是数组，处理各种可能的格式
                if (is_string($ids)) {
                    $ids = [$ids];
                } elseif (!is_array($ids)) {
                    throw new Exception('ID格式无效');
                }
                
                // 扁平化数组（处理嵌套数组的情况）
                $flatIds = [];
                array_walk_recursive($ids, function($id) use (&$flatIds) {
                    if (is_string($id) && strpos($id, ',') !== false) {
                        // 处理逗号分隔的字符串
                        $parts = explode(',', $id);
                        foreach ($parts as $part) {
                            $trimmed = trim($part);
                            if (is_numeric($trimmed) && $trimmed > 0) {
                                $flatIds[] = (int)$trimmed;
                            }
                        }
                    } elseif (is_numeric($id) && $id > 0) {
                        $flatIds[] = (int)$id;
                    }
                });
                
                if (empty($flatIds)) {
                    throw new Exception('没有有效的ID');
                }
                
                // 去重
                $flatIds = array_unique($flatIds);
                
                $pdo = get_db_connection();
                if (!$pdo) {
                    throw new Exception('数据库连接失败');
                }
                
                $placeholders = implode(',', array_fill(0, count($flatIds), '?'));
                $sql = "UPDATE hotel_reports SET status = ? WHERE id IN ($placeholders)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_merge([$status], $flatIds));
                
                echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
                break;
                
            case 'batch_delete':
                $ids = $_POST['ids'] ?? [];
                
                if (empty($ids)) {
                    throw new Exception('请选择要删除的记录');
                }
                
                // 确保ids是数组，处理各种可能的格式
                if (is_string($ids)) {
                    $ids = [$ids];
                } elseif (!is_array($ids)) {
                    throw new Exception('ID格式无效');
                }
                
                // 扁平化数组（处理嵌套数组的情况）
                $flatIds = [];
                array_walk_recursive($ids, function($id) use (&$flatIds) {
                    if (is_string($id) && strpos($id, ',') !== false) {
                        // 处理逗号分隔的字符串
                        $parts = explode(',', $id);
                        foreach ($parts as $part) {
                            $trimmed = trim($part);
                            if (is_numeric($trimmed) && $trimmed > 0) {
                                $flatIds[] = (int)$trimmed;
                            }
                        }
                    } elseif (is_numeric($id) && $id > 0) {
                        $flatIds[] = (int)$id;
                    }
                });
                
                if (empty($flatIds)) {
                    throw new Exception('没有有效的ID');
                }
                
                // 去重
                $flatIds = array_unique($flatIds);
                
                $pdo = get_db_connection();
                if (!$pdo) {
                    throw new Exception('数据库连接失败');
                }
                
                $placeholders = implode(',', array_fill(0, count($flatIds), '?'));
                $sql = "DELETE FROM hotel_reports WHERE id IN ($placeholders)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($flatIds);
                
                echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
                break;
                
            default:
                throw new Exception('未知操作');
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
    exit;
}

// 6. 主逻辑执行
$pdo = get_db_connection();

$filters = [
    'project_id' => $_GET['project_id'] ?? '',
    'status' => $_GET['status'] ?? '',
    'check_in_date' => $_GET['check_in_date'] ?? '',
    'check_out_date' => $_GET['check_out_date'] ?? ''
];

// 处理分页参数
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = max(1, intval($_GET['pageSize'] ?? 20));

// 只有选择了项目才获取数据
$reports = [];
$statistics = ['basic' => ['total_bookings' => 0, 'total_booked_rooms' => 0, 'total_room_nights' => 0, 'total_checkins' => 0], 'hotels' => [], 'room_types' => []];
$pagination = ['total_count' => 0, 'total_pages' => 0, 'current_page' => $page, 'page_size' => $pageSize];

if ($filters['project_id']) {
    $reportsData = getHotelReports($filters, $page, $pageSize);
    $reports = $reportsData['data'];
    $pagination = [
        'total_count' => $reportsData['total_count'],
        'total_pages' => $reportsData['total_pages'],
        'current_page' => $reportsData['current_page'],
        'page_size' => $reportsData['page_size']
    ];
    $statistics = getStatistics($filters);
}

// 获取项目列表
$projects = $pdo->query("SELECT id, name, code FROM projects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// 7. 状态映射
$statusMap = [
    'pending' => '待确认',
    'confirmed' => '已确认', 
    'cancelled' => '已取消'
];

?>
<?php include 'includes/header.php'; ?>

<!-- 页面特定样式 -->
<style>
        body {
            background-color: #f8f9fa;
            font-size: 14px;
            min-height: 100vh;
        }
        
        /* 项目选择区域样式 */
        .project-selector {
            background-color: #ffffff;
            border: 1px solid #e9ecef;
            border-radius: 0.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.05);
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .project-selector h4 {
            color: #495057;
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 1rem;
        }
        .project-selector .form-select {
            border: 1px solid #e9ecef;
            border-radius: 0.5rem;
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
        }
        .project-selector .form-select:focus {
            border-color: #86b7fe;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
        .project-status {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0.75rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }
        .project-status.selected {
            background-color: #d1e7dd;
            color: #0f5132;
            border: 1px solid #badbcc;
        }
        .project-status.not-selected {
            background-color: #f8d7da;
            color: #842029;
            border: 1px solid #f5c2c7;
        }
        
        /* 酒店报告容器样式 */
        .reports-container {
            background-color: #ffffff;
            border: 1px solid #e9ecef;
            border-radius: 0.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }
        .reports-header {
            background-color: #f8f9fa;
            color: #495057;
            padding: 0.75rem 1rem;
            margin: 0;
            font-weight: 600;
            font-size: 1rem;
        }
        .reports-body {
            padding: 1rem;
        }
        
        /* 空状态 */
        .empty-state {
            background-color: #f8f9fa;
            border: 2px dashed #dee2e6;
            border-radius: 0.5rem;
            padding: 2rem;
            text-align: center;
            color: #6c757d;
        }
        .empty-state i {
            opacity: 0.5;
            margin-bottom: 1rem;
        }
        .empty-state h5 {
            color: #495057;
            font-weight: 600;
        }
        
        /* 顶部栏 */
        .top-bar {
            background-color: #ffffff;
            border: 1px solid #e9ecef;
            border-radius: 0.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.05);
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
        /* 表格样式 */
        .table-responsive {
            background-color: #ffffff;
            border: 1px solid #e9ecef;
            border-radius: 0.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }
        .table th {
            background-color: #f8f9fa;
            color: #495057;
            font-weight: 600;
            font-size: 0.875rem;
        }
        .table td {
            font-size: 0.875rem;
            vertical-align: middle;
        }
        .table-hover tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        /* 卡片样式 */
        .card {
            background-color: #ffffff;
            border: 1px solid #e9ecef;
            border-radius: 0.5rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.05);
            margin-bottom: 1rem;
            overflow: hidden;
        }
        .card-header {
            background-color: #f8f9fa;
            color: #495057;
            border: none;
            font-weight: 600;
            font-size: 1rem;
            padding: 0.75rem 1rem;
        }
        .card-body {
            padding: 1rem;
        }
        
        /* 表单控件 */
        .form-control, .form-select {
            border-radius: 0.375rem;
            border: 1px solid #e9ecef;
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
        }
        .form-control:focus, .form-select:focus {
            border-color: #86b7fe;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
        .form-label {
            font-weight: 600;
            color: #495057;
            font-size: 0.875rem;
        }
        
        /* 按钮样式 */
        .btn {
            border-radius: 0.375rem;
            font-weight: 500;
            font-size: 0.875rem;
            padding: 0.375rem 0.75rem;
        }
        .btn-primary {
            background-color: #0d6efd;
            border: 1px solid #0d6efd;
        }
        .btn-primary:hover {
            background-color: #0b5ed7;
            border-color: #0a58ca;
        }
        .btn-success {
            background-color: #198754;
            border: 1px solid #198754;
        }
        .btn-success:hover {
            background-color: #157347;
            border-color: #146c43;
        }
        .btn-danger {
            background-color: #dc3545;
            border: 1px solid #dc3545;
        }
        .btn-danger:hover {
            background-color: #bb2d3b;
            border-color: #b02a37;
        }
        
        /* 徽章样式 */
        .badge {
            font-weight: 500;
            padding: 0.375rem 0.5rem;
            border-radius: 0.5rem;
            font-size: 0.75rem;
        }
        
        /* 警告框 */
        .alert {
            border-radius: 0.5rem;
            font-size: 0.875rem;
        }
        
        /* 乘车人信息样式 - 与transport_list.php保持一致 */
        .passenger-tags {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .department-group {
            display: flex;
            flex-direction: column;
            gap: 2px;
            padding: 2px 0;
        }
        
        .dept-tag {
            display: inline-flex;
            align-items: center;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            white-space: nowrap;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
            margin-bottom: 2px;
            width: fit-content;
        }
        
        .count-badge {
            background: rgba(255, 255, 255, 0.2);
            padding: 1px 6px;
            border-radius: 8px;
            margin-left: 4px;
            font-size: 0.7rem;
            font-weight: 500;
        }
        
        .names-list {
            font-size: 0.85rem;
            color: #495057;
            line-height: 1.4;
            margin-left: 8px;
            word-wrap: break-word;
        }

        /* 出行时间醒目样式 */
        .departure-time-highlight {
            font-weight: 700;
            color: #dc3545 !important;
            background: linear-gradient(135deg, #fff3cd, #ffc107);
            padding: 4px 8px;
            border-radius: 6px;
            border: 2px solid #ffc107;
            box-shadow: 0 2px 4px rgba(255, 193, 7, 0.3);
            text-shadow: 0 1px 1px rgba(255, 255, 255, 0.5);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 2px 4px rgba(255, 193, 7, 0.3); }
            50% { box-shadow: 0 2px 12px rgba(255, 193, 7, 0.6); }
            100% { box-shadow: 0 2px 4px rgba(255, 193, 7, 0.3); }
        }

        /* 乘客数量蓝色边框样式 */
        .passenger-count-border {
            display: inline-block;
            padding: 2px 6px;
            border: 2px solid #007bff;
            border-radius: 4px;
            background-color: #f8f9fa;
            font-weight: 600;
            color: #007bff;
        }

        /* 响应式调整 */
        @media (max-width: 768px) {
            .passenger-tags {
                gap: 2px;
            }
            
            .dept-tag {
                font-size: 0.7rem;
                padding: 1px 6px;
            }
            
            .names-list {
                font-size: 0.8rem;
                margin-left: 4px;
            }
        }
        
        /* 日期分隔行样式 */
        .date-divider .date-header {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%) !important;
            padding: 12px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        
        .date-divider .date-main {
            font-size: 1.2rem;
        }
        
        .date-divider .date-weekday, .date-divider .date-count {
            font-size: 1.1rem;
        }
        
        /* 表格样式优化 - 调整表格整体样式，优化字体颜色及表格列宽 */
        .table {
            font-size: 0.85rem;
            color: #495057;
            border-collapse: collapse;
            table-layout: fixed;
            width: 100%;
            /* 移除最小宽度限制，避免产生滚动条 */
        }
        
        .table th {
            background-color: #f8f9fa;
            font-weight: 600;
            text-align: center;
            padding: 0.6rem 0.4rem;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .table td {
            padding: 0.6rem 0.4rem;
            vertical-align: top;
            text-align: left;
            border-left: 1px solid #e9ecef;
            border-right: 1px solid #e9ecef;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        
        .table th {
            border-left: 1px solid #e9ecef;
            border-right: 1px solid #e9ecef;
        }
        
        .table tr:hover {
            background-color: #f8f9fa;
        }
        
        .table-striped tbody tr:nth-of-type(odd) {
            background-color: rgba(0, 0, 0, 0.02);
        }
        
        /* 表格列宽优化 - 使用百分比分配确保不重叠，不产生滚动条 */
        .table th:first-child,
        .table td:first-child {
            width: 3%;
            min-width: 30px;
            text-align: center;
        }
        
        /* 入住人列 - 缩小宽度 */
        .table th:nth-child(2),
        .table td:nth-child(2) {
            width: 16%;
            text-align: left;
            white-space: normal;
            line-height: 1.2;
            font-size: 0.8rem;
        }
        
        /* 房型/共享信息列 */
        .table th:nth-child(3),
        .table td:nth-child(3) {
            width: 13%;
            text-align: center;
            white-space: normal;
            vertical-align: top;
        }
        
        /* 日期列 - 缩小宽度并优化显示 */
        .table th:nth-child(4),
        .table td:nth-child(4) {
            width: 16%;
            white-space: normal;
            text-align: left;
            font-size: 0.75rem;
            line-height: 1.3;
        }
        
        /* 房间数/房晚数列 */
        .table th:nth-child(5),
        .table td:nth-child(5) {
            width: 10%;
            text-align: center;
            white-space: nowrap;
            font-size: 0.8rem;
        }
        
        /* 特殊要求列 - 缩小宽度 */
        .table th:nth-child(6),
        .table td:nth-child(6) {
            width: 30%;
            text-align: left;
            white-space: normal;
            word-wrap: break-word;
            overflow-wrap: break-word;
            font-size: 0.8rem;
            line-height: 1.2;
        }
        
        /* 操作列 */
        .table th:last-child,
        .table td:last-child {
            width: 12%;
            text-align: center;
            white-space: normal;
        }
        
        /* 操作列按钮间距优化 */
        .table td:last-child .btn {
            margin: 0 1px;
            padding: 0.2rem 0.3rem;
            font-size: 0.7rem;
            display: block;
            width: 100%;
            margin-bottom: 2px;
        }
        
        /* 操作列表单控件优化 */
        .table td:last-child .form-select {
            font-size: 0.7rem;
            padding: 0.15rem 0.25rem;
            margin-bottom: 0.2rem;
            width: 100%;
        }
        
        /* 特殊要求列样式优化 - 确保内容完整显示 */
        .table td:nth-child(6) {
            color: #dc3545;
            font-weight: 600;
            line-height: 1.3;
        }
        
        /* 响应式表格容器 - 移除滚动条 */
        .table-responsive {
            overflow: visible;
            width: 100%;
        }
        
        /* 房型徽章样式优化 */
        .table td:nth-child(3) .badge {
            font-size: 0.7rem;
            padding: 0.2rem 0.4rem;
            margin-bottom: 0.1rem;
            display: inline-block;
        }
        
        /* 日期格式优化 */
        .table td:nth-child(4) {
            font-family: 'Courier New', monospace;
        }
        
        /* 统计表格样式优化 */
        .card .table th {
            padding: 0.4rem 0.25rem;
            font-size: 0.75rem;
            white-space: normal;
            word-wrap: break-word;
            background-color: #e3f2fd !important;
            color: #1976d2 !important;
            font-weight: 600;
        }
        
        .card .table td {
            padding: 0.4rem 0.25rem;
            font-size: 0.75rem;
            vertical-align: middle;
            white-space: normal;
            word-wrap: break-word;
        }
        
        /* 房型统计表格专用样式 */
        .card .table-striped th:nth-child(1),
        .card .table-striped td:nth-child(1) {
            text-align: left;
            line-height: 1.2;
            width: 35%;
        }
        
        .card .table-striped th:nth-child(2),
        .card .table-striped td:nth-child(2) {
            text-align: center;
            width: 15%;
        }
        
        /* 数字列样式优化 - 根据用户偏好添加浅蓝色背景和悉停效果 */
        .card .table-striped th:nth-child(3),
        .card .table-striped td:nth-child(3),
        .card .table-striped th:nth-child(4),
        .card .table-striped td:nth-child(4),
        .card .table-striped th:nth-child(5),
        .card .table-striped td:nth-child(5) {
            text-align: center;
            width: 10%;
            background-color: #e3f2fd;
            color: #1976d2;
            font-weight: 600;
        }
        
        .card .table-striped td:nth-child(3):hover,
        .card .table-striped td:nth-child(4):hover,
        .card .table-striped td:nth-child(5):hover {
            background-color: #bbdefb !important;
        }
        
        .card .table-striped th:nth-child(6),
        .card .table-striped td:nth-child(6) {
            text-align: left;
            font-size: 0.7rem;
            line-height: 1.2;
            width: 20%;
        }
        
        /* 移除房型统计表格的滚动条 */
        .card .table-responsive {
            overflow: visible;
            width: 100%;
        }
        
        /* 确保黑色和浅蓝色背景上的文字为白色 - 优化可读性 */
        /* 统计概览标题 */
        .card-header.bg-dark,
        /* 筛选条件标题 */
        .card-header.bg-primary,
        /* 酒店统计标题 */
        .stat-card .card-header.bg-primary,
        /* 酒店报告列表标题 */
        .card-header.bg-primary.text-white {
            color: white !important;
        }
        
        /* 确保统计概览内容中的文字在深色背景上显示正常 */
        .card-header.bg-dark .h5,
        .card-header.bg-dark .mb-0,
        .card-header.bg-primary .h5,
        .card-header.bg-primary .mb-0,
        .card-header.bg-primary .h6,
        .card-header.bg-primary .badge {
            color: white !important;
        }
        
        /* 筛选条件区域样式优化 */
        .card-header.bg-dark {
            background-color: #343a40 !important;
        }
        
        /* 确保统计概览区域中的文本在黑色背景上正确显示 */
        .stat-card .card-header {
            color: white !important;
        }
</style>
<!-- 面包屑导航 -->


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
                    <!-- 保持其他筛选条件 -->
                    <?php if ($filters['status']): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($filters['status']); ?>"><?php endif; ?>
                    <?php if ($filters['check_in_date']): ?><input type="hidden" name="check_in_date" value="<?php echo htmlspecialchars($filters['check_in_date']); ?>"><?php endif; ?>
                    <?php if ($filters['check_out_date']): ?><input type="hidden" name="check_out_date" value="<?php echo htmlspecialchars($filters['check_out_date']); ?>"><?php endif; ?>
                    
                    <div class="row align-items-end">
                        <div class="col-md-8">
                            <label for="project_id" class="form-label fw-semibold">请选择要查看的项目</label>
                            <select class="form-select" id="project_id" name="project_id" onchange="this.form.submit()">
                                <option value="">请选择项目...</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo $project['id']; ?>" 
                                            <?php echo $filters['project_id'] == $project['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($project['name']); ?>
                                        <?php if (!empty($project['code'])): ?>
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
        
        <div class="container-fluid py-4">
            <?php if ($filters['project_id']): ?>
                <!-- 酒店报告管理区域 -->
                <div class="reports-container">
                    <div class="reports-header">
                        <h5 class="mb-0">
                            <i class="bi bi-table me-2"></i>酒店报告管理
                            <span class="badge bg-light text-dark ms-2">显示:<?php echo count($reports); ?>条 / 共:<?php echo $pagination['total_count']; ?>条</span>
                            
                        </h5>
                    </div>
                    <div class="reports-body">
                        <!-- 筛选区域 -->
                        <div class="card mb-4">
                            <div class="card-header bg-dark text-white">
                                <h5 class="mb-0"><i class="bi bi-funnel"></i> 筛选条件</h5>
                            </div>
                            <div class="card-body">
                                <form method="GET" class="row g-3">
                                    <input type="hidden" name="project_id" value="<?php echo htmlspecialchars($filters['project_id']); ?>">
                                    <div class="col-md-2">
                                        <label class="form-label">状态</label>
                                        <select name="status" class="form-select">
                                            <option value="">所有状态</option>
                                            <?php foreach ($statusMap as $value => $label): ?>
                                                <option value="<?= $value ?>" <?= $filters['status'] == $value ? 'selected' : '' ?>>
                                                    <?= $label ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">入住日期</label>
                                        <input type="date" name="check_in_date" value="<?= $filters['check_in_date'] ?>" class="form-control">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label">退房日期</label>
                                        <input type="date" name="check_out_date" value="<?= $filters['check_out_date'] ?>" class="form-control">
                                    </div>
                                    <div class="col-md-3 d-flex align-items-end">
                                        <button type="submit" class="btn btn-primary me-2">
                                            <i class="bi bi-search"></i> 筛选
                                        </button>
                                        <a href="?project_id=<?php echo $filters['project_id']; ?>" class="btn btn-outline-secondary">
                                            <i class="bi bi-arrow-clockwise"></i> 重置
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- 数据内容 -->
                        <?php if (empty($reports)): ?>
                            <div class="empty-state">
                                <i class="bi bi-inbox display-1"></i>
                                <h5 class="mt-3">该项目暂无酒店报告</h5>
                                <p class="text-muted mb-4">当前筛选条件下没有找到酒店报告记录</p>
                            </div>
                        <?php else: ?>
                            <!-- 统计信息 -->
                            <div class="row mb-4">
                                <!-- 总预订记录数 -->
                                <div class="col-md-3">
                                    <div class="card bg-primary text-white">
                                        <div class="card-body">
                                            <h5 class="card-title"><?= number_format($statistics['basic']['total_bookings'] ?? 0) ?></h5>
                                            <p class="card-text mb-0">总预订记录数</p>
                                        </div>
                                    </div>
                                </div>
                                <!-- 总预订房间数 -->
                                <div class="col-md-3">
                                    <div class="card bg-success text-white">
                                        <div class="card-body">
                                            <h5 class="card-title"><?= number_format($statistics['basic']['total_booked_rooms'] ?? 0) ?></h5>
                                            <p class="card-text mb-0">总预订房间数</p>
                                        </div>
                                    </div>
                                </div>
                                <!-- 总房晚数 -->
                                <div class="col-md-3">
                                    <div class="card bg-info text-white">
                                        <div class="card-body">
                                            <h5 class="card-title"><?= number_format($statistics['basic']['total_room_nights'] ?? 0) ?></h5>
                                            <p class="card-text mb-0">总房晚数</p>
                                        </div>
                                    </div>
                                </div>
                                <!-- 总入住人次 -->
                                <div class="col-md-3">
                                    <div class="card bg-warning text-white">
                                        <div class="card-body">
                                            <h5 class="card-title"><?= number_format($statistics['basic']['total_checkins'] ?? 0) ?></h5>
                                            <p class="card-text mb-0">总入住人次</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- 按酒店分组显示酒店报告列表 -->
                            <?php 
                            // 按酒店名称分组报告
                            $reportsByHotel = [];
                            foreach ($reports as $report) {
                                // 提取酒店名称作为分组键
                                $hotelName = strip_tags($report['hotel_name']); // 去除HTML标签获取纯文本酒店名
                                if (!isset($reportsByHotel[$hotelName])) {
                                    $reportsByHotel[$hotelName] = [];
                                }
                                $reportsByHotel[$hotelName][] = $report;
                            }
                            
                            // 为每个酒店显示一个独立的表格
                            foreach ($reportsByHotel as $hotelName => $hotelReports): 
                            ?>
                            <div class="card mb-4">
                                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="bi bi-building"></i> <?= htmlspecialchars($hotelName) ?> - 酒店报告列表
                                        <span class="badge bg-primary text-white ms-2"><?php echo count($hotelReports); ?> 条记录</span> <!-- 优化徽章样式，使用蓝色背景和白色文字，增加对比度 -->
                                    </h5>
                                    <div>
                                        <button class="btn btn-xs btn-success me-2" onclick="batchAction('confirm')">
                                            <i class="bi bi-check2-square"></i> 批量确认
                                        </button>
                                        <button class="btn btn-xs btn-danger" onclick="batchAction('delete')">
                                            <i class="bi bi-trash"></i> 批量删除
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="width: 4%;" width="4%"><input type="checkbox" class="selectAllHotel" data-hotel="<?= htmlspecialchars($hotelName, ENT_QUOTES, 'UTF-8') ?>" onchange="toggleAllHotel(this, '<?= htmlspecialchars($hotelName, ENT_QUOTES, 'UTF-8') ?>')"></th>
                                                    <th style="width: 18%;" width="18%">入住人</th>
                                                    <th style="width: 15%;" width="15%">房型</th>
                                                    <th style="width: 18%;" width="18%">日期</th>
                                                    <th style="width: 12%;" width="12%">房间数/房晚数</th>
                                                    <th style="width: 20%;" width="20%">特殊要求</th>
                                                    <th style="width: 13%;" width="13%">操作</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($hotelReports as $report): ?>
                                                <tr>
                                                    <td><input type="checkbox" class="row-checkbox hotel-checkbox-<?= str_replace(['=', '/', '+'], '', base64_encode($hotelName)) ?>" value="<?= $report['record_ids'] ?>" onchange="updateSelectAllHotel('<?= htmlspecialchars($hotelName, ENT_QUOTES, 'UTF-8') ?>')"></td>
                                                    <td>
                                                        <?php if ($report['room_type'] == '双床房'): ?>
                                                            <?= htmlspecialchars($report['personnel_name']) ?>
                                                        <?php else: ?>
                                                            <?= htmlspecialchars($report['personnel_name']) ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $roomType = $report['room_type'];
                                                        $badgeClass = 'bg-secondary';
                                                        switch ($roomType) {
                                                            case '双床房':
                                                                $badgeClass = 'bg-info text-dark';
                                                                break;
                                                            case '大床房':
                                                                $badgeClass = 'bg-warning text-dark';
                                                                break;
                                                            case '套房':
                                                                $badgeClass = 'bg-success';
                                                                break;
                                                            case '总统套房':
                                                                $badgeClass = 'bg-danger';
                                                                break;
                                                            case '副总统套房':
                                                                $badgeClass = 'bg-danger';
                                                                break;
                                                            case '单人房':
                                                                $badgeClass = 'bg-primary';
                                                                break;
                                                            default:
                                                                $badgeClass = 'bg-secondary';
                                                        }
                                                        ?>
                                                        <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($roomType) ?></span>
                                                        <?php 
                                                        // 判断是否为共享房间
                                                        $isShared = in_array($roomType, ['双床房', '套房', '大床房', '总统套房', '副总统套房']) && 
                                                                   !empty($report['shared_room_info']) && 
                                                                   $report['shared_room_info'] != '无';
                                                        ?><br>
                                                        <?php if ($isShared): ?>
                                                            <span class="badge text-white ms-2" style="background-color: #28a745; font-size: 0.65rem; padding: 0.2em 0.4em;">共享</span>
                                                        <?php else: ?>
                                                            <span class="badge text-muted ms-2" style="background-color: #f8f9fa; border: 1px solid #dee2e6; font-size: 0.65rem; padding: 0.2em 0.4em;">独立</span>
                                                        <?php endif; ?>

                                                    </td>
                                                    <td>
                                                        入住日期：<?= $report['check_in_date'] ?><br>
                                                        退房日期：<?= $report['check_out_date'] ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-primary"><?= $report['room_count'] ?></span>
                                                        <small class="text-muted d-block"><?= $report['total_room_nights'] ?> 房晚</small>
                                                    </td>
                                                    <td><?= htmlspecialchars($report['special_requirements']) ?></td>
                                                    <td>
                                                        <select class="form-select form-select-sm status-update me-2" 
                                                                data-ids="<?= $report['record_ids'] ?>"
                                                                onchange="updateStatus(this)" 
                                                                style="display: inline-block; width: auto;">
                                                            <?php foreach ($statusMap as $value => $label): ?>
                                                                <option value="<?= $value ?>" <?= $report['status'] == $value ? 'selected' : '' ?>>
                                                                    <?= $label ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <a href="edit_hotel_reports_group.php?ids=<?= $report['record_ids'] ?>" 
                                                           class="btn btn-sm btn-warning" title="编辑">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>
                                                        <a href="hotel_reports.php?action=delete&ids=<?= $report['record_ids'] ?>" 
                                                           class="btn btn-sm btn-danger" 
                                                           onclick="return confirm('确定删除？')" title="删除">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <!-- 分页控件 -->
                            <?php if ($filters['project_id'] && $pagination['total_pages'] > 1): ?>
                            <div class="d-flex justify-content-between align-items-center mt-4">
                                <div class="text-muted">
                                    显示第 <?php echo ($pagination['current_page'] - 1) * $pagination['page_size'] + 1; ?> 至 
                                    <?php echo min($pagination['current_page'] * $pagination['page_size'], $pagination['total_count']); ?> 条，
                                    共 <?php echo $pagination['total_count']; ?> 条记录（分组后）
                                    <span class="text-primary">
                                      注：系统采用特殊的显示逻辑：
                                      <br>1. 共享房间（如双床房）即使有多个入住人，也只显示为1条记录并算作1间房间
                                      <br>2. 对于项目ID 4 "AGA Onederful Live 江海迦中国巡回演唱会深圳站"，系统正确显示了22条记录（分组后），对应实际53条预订记录（原始数据）
                                      <br>3. 房型统计显示的是实际房间数量，而不是入住人数
                                    </span>
                                </div>
                                <nav aria-label="Page navigation">
                                    <ul class="pagination">
                                        <li class="page-item <?php echo ($pagination['current_page'] == 1) ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?project_id=<?php echo $filters['project_id']; ?>&status=<?php echo $filters['status']; ?>&check_in_date=<?php echo $filters['check_in_date']; ?>&check_out_date=<?php echo $filters['check_out_date']; ?>&page=<?php echo $pagination['current_page'] - 1; ?>&pageSize=<?php echo $pagination['page_size']; ?>">
                                                <span aria-hidden="true">&laquo;</span>
                                            </a>
                                        </li>
                                        
                                        <?php 
                                        // 计算显示的页码范围
                                        $startPage = max(1, $pagination['current_page'] - 2);
                                        $endPage = min($pagination['total_pages'], $startPage + 4);
                                        
                                        // 确保显示5个页码
                                        if ($endPage - $startPage < 4) {
                                            $startPage = max(1, $endPage - 4);
                                        }
                                        
                                        for ($i = $startPage; $i <= $endPage; $i++):
                                        ?>
                                        <li class="page-item <?php echo ($i == $pagination['current_page']) ? 'active' : ''; ?>">
                                            <a class="page-link" href="?project_id=<?php echo $filters['project_id']; ?>&status=<?php echo $filters['status']; ?>&check_in_date=<?php echo $filters['check_in_date']; ?>&check_out_date=<?php echo $filters['check_out_date']; ?>&page=<?php echo $i; ?>&pageSize=<?php echo $pagination['page_size']; ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                        <?php endfor; ?>
                                        
                                        <li class="page-item <?php echo ($pagination['current_page'] == $pagination['total_pages']) ? 'disabled' : ''; ?>">
                                            <a class="page-link" href="?project_id=<?php echo $filters['project_id']; ?>&status=<?php echo $filters['status']; ?>&check_in_date=<?php echo $filters['check_in_date']; ?>&check_out_date=<?php echo $filters['check_out_date']; ?>&page=<?php echo $pagination['current_page'] + 1; ?>&pageSize=<?php echo $pagination['page_size']; ?>">
                                                <span aria-hidden="true">&raquo;</span>
                                            </a>
                                        </li>
                                    </ul>
                                </nav>
                            </div>
                            <?php endif; ?>
                            
                            <!-- 房型统计 -->
                            <?php if (!empty($statistics['room_types'])): ?>
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="mb-0"><i class="bi bi-house-door"></i> 房型统计</h5>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-striped">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="width: 28%; min-width: 180px;">酒店</th>
                                                    <th style="width: 18%; min-width: 120px;">房型</th>
                                                    <th class="text-center" style="width: 12%; min-width: 90px;">预订次数</th>
                                                    <th class="text-center" style="width: 12%; min-width: 90px;">总房间数</th>
                                                    <th class="text-center" style="width: 12%; min-width: 90px;">房晚数</th>
                                                    <th style="width: 18%; min-width: 200px;">入住时间范围</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($statistics['room_types'] as $room): ?>
                                                    <tr>
                                                        <td><?= $room['hotel_name'] ?></td>
                                                        <td><?= htmlspecialchars($room['room_type']) ?></td>
                                                        <td class="text-center"><?= $room['bookings_count'] ?? 0 ?></td>
                                                        <td class="text-center"><?= $room['total_checkins'] ?? 0 ?></td>
                                                        <td class="text-center"><?= $room['total_room_nights'] ?? 0 ?></td>
                                                        <td><?= ($room['earliest_checkin'] ?? '') . ' 至 ' . ($room['latest_checkout'] ?? '') ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <!-- 未选择项目的空状态 -->
                <div class="empty-state">
                    <i class="bi bi-folder2-open display-1"></i>
                    <h5 class="mt-3">请先选择项目</h5>
                    <p class="text-muted mb-4">选择要查看的项目后，才能查看和管理该项目的酒店报告信息</p>
                    <small class="text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        这样可以确保数据的准确性和管理的效率
                    </small>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // 全选/取消全选（全局）
    function toggleAll(master) {
        const checkboxes = document.querySelectorAll('.row-checkbox');
        checkboxes.forEach(cb => cb.checked = master.checked);
        
        // 更新所有酒店的全选状态
        document.querySelectorAll('.selectAllHotel').forEach(selectAll => {
            selectAll.checked = master.checked;
            selectAll.indeterminate = false;
        });
    }
    
    // 按酒店全选/取消全选
    function toggleAllHotel(master, hotelName) {
        // 使用与HTML中相同的编码方式
        const encodedHotelName = btoa(unescape(encodeURIComponent(hotelName))).replace(/=/g, '').replace(/\//g, '').replace(/\+/g, '');
        const checkboxes = document.querySelectorAll('.hotel-checkbox-' + encodedHotelName);
        checkboxes.forEach(cb => cb.checked = master.checked);
        updateSelectAllHotel(hotelName);
    }
    
    // 更新全局全选状态
    function updateSelectAll() {
        const checkboxes = document.querySelectorAll('.row-checkbox');
        const allChecked = Array.from(checkboxes).every(cb => cb.checked);
        const someChecked = Array.from(checkboxes).some(cb => cb.checked);
        
        // 注意：这里没有全局的selectAll复选框，因为我们按酒店分组了
        // 如果需要全局全选功能，可以在这里添加
    }
    
    // 更新特定酒店的全选状态
    function updateSelectAllHotel(hotelName) {
        // 使用与HTML中相同的编码方式
        const encodedHotelName = btoa(unescape(encodeURIComponent(hotelName))).replace(/=/g, '').replace(/\//g, '').replace(/\+/g, '');
        const checkboxes = document.querySelectorAll('.hotel-checkbox-' + encodedHotelName);
        const allChecked = Array.from(checkboxes).every(cb => cb.checked);
        const someChecked = Array.from(checkboxes).some(cb => cb.checked);
        
        const selectAllCheckbox = document.querySelector('.selectAllHotel[data-hotel="' + CSS.escape(hotelName) + '"]');
        if (selectAllCheckbox) {
            selectAllCheckbox.checked = allChecked;
            selectAllCheckbox.indeterminate = someChecked && !allChecked;
        }
    }
    
    // 批量操作
    function batchAction(action) {
        const checked = document.querySelectorAll('.row-checkbox:checked');
        if (checked.length === 0) {
            alert('请至少选择一条记录');
            return;
        }
        
        // 将每个checkbox的值按逗号分割并合并所有ID
        const ids = [];
        checked.forEach(cb => {
            const values = cb.value.split(',');
            values.forEach(id => {
                if (id.trim()) ids.push(id.trim());
            });
        });
        
        if (action === 'confirm') {
            if (confirm(`确定要批量确认 ${ids.length} 条记录吗？`)) {
                updateBatchStatus(ids, 'confirmed');
            }
        } else if (action === 'delete') {
            if (confirm(`确定要批量删除 ${ids.length} 条记录吗？此操作不可撤销！`)) {
                deleteBatch(ids);
            }
        }
    }
    
    // 批量状态更新
    async function updateBatchStatus(ids, status) {
        try {
            const formData = new FormData();
            formData.append('action', 'batch_status_update');
            formData.append('status', status);
            
            // 确保ids是扁平化的数组，处理合并记录的情况
            const flatIds = [];
            ids.forEach(id => {
                if (typeof id === 'string' && id.includes(',')) {
                    // 处理逗号分隔的多个ID
                    flatIds.push(...id.split(',').map(i => i.trim()));
                } else {
                    flatIds.push(id.toString().trim());
                }
            });
            
            // 过滤无效ID
            const validIds = flatIds.filter(id => id && !isNaN(id));
            
            if (validIds.length === 0) {
                alert('没有有效的记录ID');
                return;
            }
            
            // 将每个ID单独添加到FormData
            validIds.forEach(id => formData.append('ids[]', id));
            
            const response = await fetch('hotel_reports_new.php', {
                method: 'POST',
                body: formData
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const result = await response.json();
            if (result.success) {
                alert(`成功更新 ${result.updated} 条记录`);
                location.reload();
            } else {
                alert('更新失败：' + (result.message || '未知错误'));
            }
        } catch (error) {
            console.error('Error:', error);
            alert('网络错误，请重试：' + error.message);
        }
    }
    
    // 批量删除
    async function deleteBatch(ids) {
        try {
            const formData = new FormData();
            formData.append('action', 'batch_delete');
            
            // 确保ids是扁平化的数组，处理合并记录的情况
            const flatIds = [];
            ids.forEach(id => {
                if (typeof id === 'string' && id.includes(',')) {
                    // 处理逗号分隔的多个ID
                    flatIds.push(...id.split(',').map(i => i.trim()));
                } else {
                    flatIds.push(id.toString().trim());
                }
            });
            
            // 过滤无效ID
            const validIds = flatIds.filter(id => id && !isNaN(id));
            
            if (validIds.length === 0) {
                alert('没有有效的记录ID');
                return;
            }
            
            // 将每个ID单独添加到FormData
            validIds.forEach(id => formData.append('ids[]', id));
            
            const response = await fetch('hotel_reports_new.php', {
                method: 'POST',
                body: formData
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const result = await response.json();
            if (result.success) {
                alert(`成功删除 ${result.deleted} 条记录`);
                location.reload();
            } else {
                alert('删除失败：' + (result.message || '未知错误'));
            }
        } catch (error) {
            console.error('Error:', error);
            alert('网络错误，请重试：' + error.message);
        }
    }
    
    // 单个状态更新
    function updateStatus(select) {
        const ids = [select.dataset.ids];
        const status = select.value;
        
        if (confirm(`确定要更新这条记录的状态吗？`)) {
            updateBatchStatus(ids, status);
        } else {
            select.value = select.dataset.originalValue;
        }
    }
    
    // 初始化原始值
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.status-update').forEach(select => {
            select.dataset.originalValue = select.value;
        });
    });
    
    // 重置筛选
    function resetFilters() {
        const projectId = '<?php echo $filters['project_id']; ?>';
        window.location.href = '?project_id=' + projectId;
    }
    </script>

</div>
</div>
</div>

    </script>

<?php include 'includes/footer.php'; ?>