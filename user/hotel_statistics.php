<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
// 酒店信息统计页面
// 包含入住人数、时间、房型、房间数等统计信息

// 启动session
session_start();
// 引入权限中间件进行权限验证
require_once '../includes/PermissionMiddleware.php';
$database = new Database();
$db = $database->getConnection();
$middleware = new PermissionMiddleware($db);
$middleware->checkUserPagePermission('frontend:hotel:statistics');

requireLogin();
checkProjectPermission($_SESSION['project_id']);

$projectId = $_SESSION['project_id'];
$projectName = $_SESSION['project_name'] ?? '项目';

// 获取显示控制参数
$show_daily_stats = isset($_GET['show_daily']) ? (bool)$_GET['show_daily'] : false;
$show_room_type_stats = isset($_GET['show_room_type']) ? (bool)$_GET['show_room_type'] : false;

// 获取数据库连接
$database = new Database();
$db = $database->getConnection();

// 获取当前项目信息，包括开始和结束时间
$project_query = "SELECT start_date, end_date FROM projects WHERE id = :project_id";
$project_stmt = $db->prepare($project_query);
$project_stmt->bindParam(':project_id', $projectId);
$project_stmt->execute();
$project_info = $project_stmt->fetch(PDO::FETCH_ASSOC);

// 如果没有获取到项目信息，设置默认值
$project_start_date = $project_info['start_date'] ?? date('Y-m-d');
$project_end_date = $project_info['end_date'] ?? date('Y-m-d', strtotime('+30 days'));

// 获取查询参数 - 取消日期筛选，显示所有数据
$hotel_id = $_GET['hotel_id'] ?? '';

// 获取当前项目的酒店列表 - 支持项目分配多个酒店功能
// 检查project_hotels表是否存在，使用不同的查询策略
$check_table_query = "SHOW TABLES LIKE 'project_hotels'";
$check_stmt = $db->prepare($check_table_query);
$check_stmt->execute();
$table_exists = ($check_stmt->rowCount() > 0);

if ($table_exists) {
    // 使用新的多酒店关联模式获取项目分配的酒店
    $hotels_query = "SELECT DISTINCT h.hotel_name_cn as hotel_name 
                     FROM hotels h 
                     JOIN project_hotels ph ON h.id = ph.hotel_id 
                     WHERE ph.project_id = :project_id 
                     ORDER BY h.hotel_name_cn";
} else {
    // 使用旧的项目单酒店模式或从hotel_reports获取实际使用过的酒店
    // 标准化酒店名称：提取中文名部分（' - '之前的部分）
    $hotels_query = "SELECT DISTINCT 
                        CASE 
                            WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
                            ELSE hotel_name
                        END as hotel_name
                     FROM hotel_reports 
                     WHERE project_id = :project_id AND hotel_name IS NOT NULL
                     ORDER BY hotel_name";
}

$hotels_stmt = $db->prepare($hotels_query);
$hotels_stmt->bindParam(':project_id', $projectId);
$hotels_stmt->execute();
$hotels = $hotels_stmt->fetchAll(PDO::FETCH_ASSOC);

// 如果新模式下没有数据，回退到旧模式
if (empty($hotels) && $table_exists) {
    // 标准化酒店名称：提取中文名部分（' - '之前的部分）
    $hotels_query = "SELECT DISTINCT 
                        CASE 
                            WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
                            ELSE hotel_name
                        END as hotel_name
                     FROM hotel_reports 
                     WHERE project_id = :project_id AND hotel_name IS NOT NULL
                     ORDER BY hotel_name";
    $hotels_stmt = $db->prepare($hotels_query);
    $hotels_stmt->bindParam(':project_id', $projectId);
    $hotels_stmt->execute();
    $hotels = $hotels_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 获取酒店统计信息
$stats = [];

// 1. 各酒店基本统计（合并双床房计算，1间双床房视为1间房）
// 标准化酒店名称：提取中文名部分进行统计
// 使用与项目总统计和其他统计表一致的逻辑
$basic_stats_query = "SELECT 
    CASE 
        WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
        ELSE hotel_name
    END as hotel_name,
    (SELECT COUNT(DISTINCT personnel_id) FROM hotel_reports WHERE project_id = :project_id 
        AND (CASE WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1) ELSE hotel_name END) = 
            (CASE WHEN grouped_rooms.hotel_name LIKE '% - %' THEN SUBSTRING(grouped_rooms.hotel_name, 1, LOCATE(' - ', grouped_rooms.hotel_name) - 1) ELSE grouped_rooms.hotel_name END)
    ) as total_checkins,
    COALESCE(SUM(
        CASE 
            WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' THEN 1
            ELSE room_count
        END
    ), 0) as total_booked_rooms,
    COUNT(*) as total_bookings,
    COALESCE(SUM(DATEDIFF(check_out_date, check_in_date) * 
        CASE 
            WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' THEN 1
            ELSE room_count
        END
    ), 0) as total_room_nights
FROM (
    SELECT 
        MIN(id) as id,
        CASE 
            WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
            ELSE hotel_name
        END as hotel_name,
        room_type,
        room_count,
        shared_room_info,
        check_in_date,
        check_out_date
    FROM hotel_reports 
    WHERE project_id = :project_id 
        AND (CASE 
            WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
            ELSE hotel_name
        END = :hotel_name OR :hotel_name = '')
        AND hotel_name IS NOT NULL
    GROUP BY CASE 
        WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
            ELSE hotel_name
        END, room_type, room_count, shared_room_info, check_in_date, check_out_date,
        CASE 
            WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' THEN shared_room_info
            ELSE CONCAT(id, '-', room_type, '-', room_count)
        END
) as grouped_rooms
GROUP BY CASE 
    WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
    ELSE hotel_name
END, 
    CASE 
        WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
        ELSE hotel_name
    END,
    hotel_name
ORDER BY CASE 
    WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
    ELSE hotel_name
END";

$basic_stmt = $db->prepare($basic_stats_query);
$basic_stmt->bindParam(':project_id', $projectId);
$basic_stmt->bindParam(':hotel_name', $hotel_id);
$basic_stmt->execute();
$basic_stats = $basic_stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. 每日入住统计（合并双床房计算，1间双床房视为1间房）
// 标准化酒店名称：提取中文名部分进行统计
// 与房型统计表和入住人员详情表保持一致的计算逻辑
$daily_stats_query = "SELECT 
    date,
    CASE 
        WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
        ELSE hotel_name
    END as hotel_name,
    COUNT(DISTINCT personnel_count) as daily_checkins,
    SUM(actual_rooms) as daily_booked_rooms,
    SUM(actual_room_nights) as daily_room_nights,
    GROUP_CONCAT(DISTINCT room_type ORDER BY room_type) as room_types_used
FROM (
    SELECT 
        DATE(check_in_date) as date,
        CASE 
            WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
            ELSE hotel_name
        END as hotel_name,
        room_type,
        COUNT(DISTINCT personnel_id) as personnel_count,
        CASE 
            WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' THEN 
                1  -- 共享房间，无论多少人，都只算一间房
            ELSE 
                MIN(room_count)  -- 非共享房间按实际房间数计算
        END as actual_rooms,
        CASE 
            WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' THEN 
                DATEDIFF(check_out_date, check_in_date) * 1  -- 共享房间的房晚数
            ELSE 
                DATEDIFF(check_out_date, check_in_date) * MIN(room_count)  -- 非共享房间的房晚数
        END as actual_room_nights
    FROM hotel_reports 
    WHERE project_id = :project_id 
        AND (CASE 
            WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
            ELSE hotel_name
        END = :hotel_name OR :hotel_name = '')
        AND hotel_name IS NOT NULL
    GROUP BY 
        DATE(check_in_date),
        CASE 
            WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
            ELSE hotel_name
        END,
        room_type,
        check_in_date,
        check_out_date,
        room_count,
        CASE 
            WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' THEN shared_room_info
            ELSE CONCAT(id, '-', room_type, '-', room_count)
        END,
        shared_room_info
) as daily_groups
GROUP BY date, CASE 
    WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
    ELSE hotel_name
END, 
    CASE 
        WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
        ELSE hotel_name
    END,
    hotel_name,
    room_type
ORDER BY date ASC";

$daily_stmt = $db->prepare($daily_stats_query);
$daily_stmt->bindParam(':project_id', $projectId);
$daily_stmt->bindParam(':hotel_name', $hotel_id);
$daily_stmt->execute();
$daily_stats = $daily_stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. 房型统计（合并双床房计算，1间双床房视为1间房）
// 标准化酒店名称：提取中文名部分进行统计
// 与入住人员详情表保持一致的计算逻辑
$room_type_stats_query = "SELECT 
    CASE 
        WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
        ELSE hotel_name
    END as hotel_name,
    room_type,
    COUNT(*) as bookings_count,
    SUM(actual_rooms) as total_rooms,
    SUM(actual_room_nights) as total_room_nights,
    MIN(earliest_checkin) as earliest_checkin,
    MAX(latest_checkout) as latest_checkout
FROM (
    SELECT 
        CASE 
            WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
            ELSE hotel_name
        END as hotel_name,
        room_type,
        check_in_date as earliest_checkin,
        check_out_date as latest_checkout,
        CASE 
            WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' THEN 
                1  -- 共享房间，无论多少人，都只算一间房
            ELSE 
                MIN(room_count)  -- 非共享房间按实际房间数计算（使用MIN避免重复计算）
        END as actual_rooms,
        CASE 
            WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' THEN 
                DATEDIFF(check_out_date, check_in_date) * 1  -- 共享房间的房晚数
            ELSE 
                DATEDIFF(check_out_date, check_in_date) * MIN(room_count)  -- 非共享房间的房晚数
        END as actual_room_nights
    FROM hotel_reports 
    WHERE project_id = :project_id 
        AND (CASE 
            WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
            ELSE hotel_name
        END = :hotel_name OR :hotel_name = '')
        AND hotel_name IS NOT NULL
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
            WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' THEN shared_room_info
            ELSE CONCAT(id, '-', room_type, '-', room_count)
        END,
        shared_room_info
) as room_groups
GROUP BY CASE 
    WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
    ELSE hotel_name
END, room_type, 
    CASE 
        WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
        ELSE hotel_name
    END,
    hotel_name,
    earliest_checkin,
    latest_checkout
ORDER BY CASE 
    WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
    ELSE hotel_name
END, bookings_count DESC";

$room_type_stmt = $db->prepare($room_type_stats_query);
$room_type_stmt->bindParam(':project_id', $projectId);
$room_type_stmt->bindParam(':hotel_name', $hotel_id);
$room_type_stmt->execute();
$room_type_stats = $room_type_stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. 按日期统计房型数据（新添加的查询）
// 标准化酒店名称：提取中文名部分进行统计
// 重新设计：按日期显示每日各房型多少间，总数多少间
// 修复共享房间计算逻辑：共享房间无论多少人只算一间房
// 修复问题：当没有筛选特定酒店时，应该合并所有酒店的数据而不是按酒店分组
// 修复：当没有筛选特定酒店时，正确合并所有酒店的数据
$daily_room_type_stats_query = "SELECT 
    dates.date,
    hotel_data.room_type,
    SUM(
        CASE 
            WHEN hotel_data.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND hotel_data.shared_room_info IS NOT NULL AND hotel_data.shared_room_info != '' THEN 1
            ELSE hotel_data.room_count
        END
    ) as room_count,
    SUM(
        CASE 
            WHEN hotel_data.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND hotel_data.shared_room_info IS NOT NULL AND hotel_data.shared_room_info != '' THEN 1
            ELSE hotel_data.room_count
        END
    ) as room_nights
FROM (
    -- 生成日期序列，为每个入住记录生成其入住期间的每一天
    SELECT 
        hr.id,
        DATE_ADD(DATE(hr.check_in_date), INTERVAL seq.n DAY) as date
    FROM hotel_reports hr
    CROSS JOIN (
        SELECT a.N + b.N * 10 as n
        FROM 
        (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a
        CROSS JOIN
        (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b
    ) seq
    WHERE hr.project_id = :project_id 
        AND (CASE 
            WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1)
            ELSE hr.hotel_name
        END = :hotel_name OR (:hotel_name = '' OR :hotel_name IS NULL))
        AND hr.hotel_name IS NOT NULL
        AND DATE_ADD(DATE(hr.check_in_date), INTERVAL seq.n DAY) < hr.check_out_date
) as dates
JOIN (
    -- 修正共享房间分组逻辑：确保共享房间只计算一次
    SELECT 
        MIN(id) as id,
        CASE 
            WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
            ELSE hotel_name
        END as hotel_name,
        room_type,
        room_count,
        shared_room_info,
        check_in_date,
        check_out_date
    FROM hotel_reports 
    WHERE project_id = :project_id2 
        AND (CASE 
            WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
            ELSE hotel_name
        END = :hotel_name2 OR (:hotel_name2 = '' OR :hotel_name2 IS NULL))
        AND hotel_name IS NOT NULL
    GROUP BY 
        room_type,
        room_count,
        shared_room_info,
        check_in_date,
        check_out_date,
        CASE 
            WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' THEN shared_room_info
            ELSE CONCAT(id, '-', room_type, '-', room_count)
        END
) as hotel_data ON dates.id = hotel_data.id
GROUP BY dates.date, hotel_data.room_type
ORDER BY dates.date ASC, hotel_data.room_type";

$daily_room_type_stmt = $db->prepare($daily_room_type_stats_query);
$daily_room_type_stmt->bindParam(':project_id', $projectId);
$daily_room_type_stmt->bindParam(':project_id2', $projectId);
$daily_room_type_stmt->bindParam(':hotel_name', $hotel_id);
$daily_room_type_stmt->bindParam(':hotel_name2', $hotel_id);
$daily_room_type_stmt->execute();
$daily_room_type_stats = $daily_room_type_stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. 人员入住详情（按共享房间分组显示）
// 标准化酒店名称：提取中文名部分进行统计，但显示时保留原始名称
$personnel_query = "SELECT 
    hr.id,
    GROUP_CONCAT(p.name ORDER BY d.sort_order ASC, d.name, p.name SEPARATOR '、') as personnel_names,
    hr.hotel_name as original_hotel_name,
    CASE 
        WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1)
        ELSE hr.hotel_name
    END as hotel_name,
    hr.check_in_date,
    hr.check_out_date,
    hr.room_type,
    hr.room_count,
    hr.special_requirements,
    hr.shared_room_info,
    DATEDIFF(hr.check_out_date, hr.check_in_date) as stay_days,
    (DATEDIFF(hr.check_out_date, hr.check_in_date) * 
        CASE 
            WHEN hr.room_type IN ('双床房','套房','大床房','总统套房','副总统套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' THEN 1
            ELSE hr.room_count
        END
    ) as room_nights,
    COUNT(*) as person_count,
    CASE 
        WHEN hr.room_type IN ('双床房','套房','大床房','总统套房','副总统套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' THEN 'shared'
        ELSE 'individual'
    END as room_group_type,
    CASE 
        WHEN hr.room_type IN ('双床房','套房','大床房','总统套房','副总统套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' THEN hr.shared_room_info
        ELSE CAST(hr.id AS CHAR)
    END as room_group_id
FROM hotel_reports hr
JOIN personnel p ON hr.personnel_id = p.id
LEFT JOIN project_department_personnel pdp ON p.id = pdp.personnel_id AND hr.project_id = pdp.project_id
LEFT JOIN departments d ON pdp.department_id = d.id
WHERE hr.project_id = :project_id 
    AND (CASE 
        WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1)
        ELSE hr.hotel_name
    END = :hotel_name OR :hotel_name = '')
    AND hr.hotel_name IS NOT NULL
GROUP BY hr.id, hr.hotel_name, hr.check_in_date, hr.check_out_date, hr.room_type, hr.room_count, hr.special_requirements,
    CASE 
        WHEN hr.room_type IN ('双床房','套房','大床房','总统套房','副总统套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' THEN hr.shared_room_info
        ELSE hr.id  -- 对于非共享房间，按个人记录分组
    END, 
    hr.hotel_name,
    CASE 
        WHEN hr.room_type IN ('双床房','套房','大床房','总统套房','副总统套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' THEN 'shared'
        ELSE 'individual'
    END,
    CASE 
        WHEN hr.room_type IN ('双床房','套房','大床房','总统套房','副总统套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' THEN hr.shared_room_info
        ELSE CAST(hr.id AS CHAR)
    END
ORDER BY COALESCE(d.sort_order, 0) ASC, d.name, hr.check_in_date DESC, hr.hotel_name, p.name";

$personnel_stmt = $db->prepare($personnel_query);
$personnel_stmt->bindParam(':project_id', $projectId);
$personnel_stmt->bindParam(':hotel_name', $hotel_id);
$personnel_stmt->execute();
$personnel_stats = $personnel_stmt->fetchAll(PDO::FETCH_ASSOC);

// 设置页面标题
$page_title = "酒店信息统计 - {$projectName}";
$active_page = 'hotel_statistics';
$show_page_title = "酒店信息统计";
$page_icon = 'bar-chart';

include 'includes/header.php';
?>

<!-- 添加页面特定样式 -->
<style>
    /* 表格整体优化 */
    .table {
        font-size: 0.85rem;
        margin-bottom: 0;
        border: 1px solid #dee2e6; /* 添加表格边框 */
    }
    
    .table th {
        padding: 8px 6px;
        font-size: 0.8rem;
        font-weight: 600;
        background-color: #f8f9fa;
        border-bottom: 2px solid #dee2e6;
        white-space: nowrap;
        border: 1px solid #dee2e6; /* 添加表头单元格边框 */
    }
    
    .table td {
        padding: 6px 6px;
        vertical-align: middle;
        border-bottom: 1px solid #dee2e6;
        border: 1px solid #dee2e6; /* 添加数据单元格边框 */
    }
    
    /* 表格行悬停效果 */
    .table-hover tbody tr:hover {
        background-color: #f1f3f4;
        transform: translateY(-1px);
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        transition: all 0.2s ease;
    }
    
    /* 统计卡片样式 */
    .stat-card {
        background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        border-radius: 8px;
        padding: 12px;
        margin-bottom: 12px;
        border-left: 4px solid #007bff;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        transition: all 0.3s ease;
        min-height: 80px;
    }
    
    .stat-card:hover {
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
        transform: translateY(-2px);
    }
    
    .stat-card i {
        font-size: 20px;
        margin-right: 8px;
        color: #007bff;
    }
    
    .stat-number {
        font-size: 24px;
        font-weight: bold;
        color: #2c3e50;
        margin-bottom: 2px;
    }
    
    .stat-label {
        font-size: 0.75rem;
        color: #6c757d;
        margin: 0;
    }
    
    /* 酒店名称优化显示 */
    .hotel-name {
        max-width: 180px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-weight: 500;
        color: #2c3e50;
    }
    
    .hotel-name:hover {
        overflow: visible;
        white-space: normal;
        background-color: #fff3cd;
        padding: 4px;
        border-radius: 4px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        z-index: 10;
        position: relative;
    }
    
    /* 人员名称优化 */
    .personnel-names {
        max-width: 160px;
        overflow: hidden;
        text-overflow: ellipsis;
        font-weight: 500;
        color: #2c3e50;
        line-height: 1.3;
    }
    
    .personnel-names:hover {
        overflow: visible;
        white-space: normal;
        background-color: #e7f3ff;
        padding: 4px;
        border-radius: 4px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        z-index: 10;
        position: relative;
    }
    
    /* 日期显示优化 */
    .date-display {
        font-size: 0.8rem;
        font-weight: 500;
        white-space: nowrap;
    }
    
    .date-in {
        color: #28a745;
    }
    
    .date-out {
        color: #dc3545;
    }
    
    /* 房型标识 */
    .room-type-badge {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 500;
        background-color: #e9ecef;
        color: #495057;
        border: 1px solid #ced4da; /* 添加房型标识边框 */
    }
    
    .room-type-single { background-color: #d1ecf1; color: #0c5460; }
    .room-type-double { background-color: #d4edda; color: #155724; }
    .room-type-suite { background-color: #fff3cd; color: #856404; }
    .room-type-executive { background-color: #f8d7da; color: #721c24; }
    
    /* 数字统计显示 */
    .number-display {
        font-weight: 600;
        font-size: 0.9rem;
        text-align: center;
    }
    
    .text-primary-custom { color: #007bff !important; }
    .text-success-custom { color: #28a745 !important; }
    .text-warning-custom { color: #ffc107 !important; }
    .text-info-custom { color: #17a2b8 !important; }
    
    /* 备注信息优化 */
    .notes-cell {
        max-width: 200px;
        overflow: hidden;
        text-overflow: ellipsis;
        font-size: 0.8rem;
        color: #6c757d;
    }
    
    .notes-cell:hover {
        overflow: visible;
        white-space: normal;
        background-color: #f8f9fa;
        padding: 6px;
        border-radius: 4px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        z-index: 10;
        position: relative;
    }
    
    /* 总统计卡片特殊样式 */
    .project-total-card {
        background: linear-gradient(135deg, #343a40 0%, #495057 100%);
        color: white;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
    }
    
    .project-total-card .stat-number {
        color: white;
        font-size: 28px;
    }
    
    .project-total-card .stat-label {
        color: #ced4da;
        font-size: 0.8rem;
    }
    
    /* 各酒店统计卡片 */
    .hotel-stat-card {
        background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
        color: white;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 15px;
        box-shadow: 0 3px 10px rgba(0, 123, 255, 0.3);
        transition: all 0.3s ease;
    }
    
    .hotel-stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 20px rgba(0, 123, 255, 0.4);
    }
    
    .hotel-stat-card .stat-number {
        color: white;
        font-size: 20px;
    }
    
    .hotel-stat-card .stat-label {
        color: #cce7ff;
        font-size: 0.75rem;
    }
    
    /* 每日房型统计表样式 */
    .daily-room-type-table th {
        text-align: center;
        background-color: #e9ecef;
    }
    
    .daily-room-type-table td:first-child,
    .daily-room-type-table th:first-child {
        background-color: #f8f9fa;
        font-weight: bold;
    }
    
    .text-danger-custom { color: #dc3545 !important; }
    
    /* 共享/独享徽章样式 */
    .sharing-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 0.65rem;
        font-weight: 600;
        margin-top: 5px;
        margin-bottom: 2px;
    }
    
    .sharing-badge.shared {
        background-color: #e7f3ff;
        color: #0d6efd;
        border: 1px solid #0d6efd;
    }
    
    .sharing-badge.private {
        background-color: #f0f0f0;
        color: #6c757d;
        border: 1px solid #6c757d;
    }
    
    /* 响应式优化 */
    @media (max-width: 768px) {
        .table {
            font-size: 0.75rem;
        }
        
        .table th, .table td {
            padding: 4px 3px;
        }
        
        .hotel-name, .personnel-names {
            max-width: 120px;
        }
        
        .stat-card {
            padding: 8px;
            min-height: 70px;
        }
        
        .stat-number {
            font-size: 20px;
        }
    }
    
    /* DataTables 样式覆盖 */
    .dataTables_wrapper .dataTables_length,
    .dataTables_wrapper .dataTables_filter,
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_paginate {
        font-size: 0.8rem;
    }
    
    .dataTables_wrapper .dataTables_length select,
    .dataTables_wrapper .dataTables_filter input {
        font-size: 0.8rem;
        padding: 2px 6px;
    }
    
    /* 确保表格边框可见 */
    .table-bordered {
        border: 1px solid #dee2e6;
    }
    
    .table-bordered th,
    .table-bordered td {
        border: 1px solid #dee2e6;
    }
    
    .table-bordered thead th,
    .table-bordered thead td {
        border-bottom-width: 2px;
    }
</style>

<div class="container mt-4">
    <!-- 页面标题 -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-bar-chart"></i> 酒店信息统计</h2>
        <a href="dashboard.php" class="btn btn-secondary">
            <i class="bi bi-arrow-left"></i> 返回
        </a>
    </div>

    <!-- 筛选条件 -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-funnel"></i> 筛选条件</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3" id="filterForm">
                <!-- 取消日期筛选，只保留酒店筛选 -->
                <div class="col-md-6">
                    <label for="hotel_id" class="form-label">选择酒店</label>
                    <select class="form-select" id="hotel_id" name="hotel_id" onchange="submitFilterForm()">
                        <option value="">全部酒店（显示整个项目所有数据）</option>
                        <?php foreach ($hotels as $hotel): ?>
                            <option value="<?php echo htmlspecialchars($hotel['hotel_name']); ?>" 
                                    <?php echo $hotel_id == $hotel['hotel_name'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($hotel['hotel_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary" onclick="submitFilterForm()">
                        <i class="bi bi-search"></i> 查询
                    </button>
                    <a href="hotel_statistics.php" class="btn btn-outline-secondary ms-2">
                        <i class="bi bi-arrow-clockwise"></i> 重置
                    </a>
                </div>
                
                <!-- 显示控制选项 -->
                <div class="col-12 mt-3">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="showDailyStats" name="show_daily" value="1" <?php echo $show_daily_stats ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="showDailyStats">显示每日入住统计</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="showRoomTypeStats" name="show_room_type" value="1" <?php echo $show_room_type_stats ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="showRoomTypeStats">显示房型统计</label>
                    </div>
                </div>
            </form>
            
            <!-- 调试信息 -->
            <?php if (isset($_GET['debug'])): ?>
            <div class="mt-3">
                <small class="text-muted">
                    调试信息: 当前hotel_id = "<?php echo htmlspecialchars($hotel_id); ?>"<br>
                    酒店列表: <?php echo count($hotels); ?> 个酒店<br>
                    基本统计: <?php echo count($basic_stats); ?> 条记录<br>
                    人员详情: <?php echo count($personnel_stats); ?> 条记录
                </small>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 筛选结果提示和数据验证 -->
    <?php 
    // 验证数据是否存在
    $data_check_query = "SELECT COUNT(*) as total_records 
                        FROM hotel_reports 
                        WHERE project_id = :project_id AND hotel_name IS NOT NULL";
    $data_check_stmt = $db->prepare($data_check_query);
    $data_check_stmt->bindParam(':project_id', $projectId);
    $data_check_stmt->execute();
    $total_records = $data_check_stmt->fetch(PDO::FETCH_ASSOC)['total_records'];
    
    if ($total_records == 0): 
    ?>
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle"></i> 
            <strong>提示：</strong>当前项目暂无酒店预订数据，请先在酒店预订页面添加数据。
            <a href="hotels.php" class="alert-link">前往酒店预订</a>
        </div>
    <?php elseif (!empty($hotel_id)): ?>
        <div class="alert alert-info">
            <i class="bi bi-filter-circle"></i> 
            当前显示 <strong><?php echo htmlspecialchars($hotel_id); ?></strong> 的统计信息
            <a href="hotel_statistics.php" class="alert-link">查看全部酒店</a>
        </div>
    <?php else: ?>
        <div class="alert alert-light">
            <i class="bi bi-info-circle"></i> 当前显示整个项目的酒店统计信息
        </div>
    <?php endif; ?>

    <!-- 项目总统计 -->
    <div class="row mb-4">
        <?php 
        // 计算项目总统计（按共享信息分组计算，避免重复计算共享房间）
        // 标准化酒店名称：提取中文名部分进行统计
        // 修复筛选单独酒店时统计数据不正确的问题，正确应用双床房和套房的合并计算逻辑
        // 修正：确保与各酒店统计概览表的数据一致
        // 修改总入住人次计算方式：应该是各酒店入住人次的总和，而不是去重后的总人数
        $project_total_query = "SELECT 
            (SELECT SUM(hotel_checkins) FROM (
                SELECT 
                    CASE 
                        WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
                        ELSE hotel_name
                    END as normalized_hotel_name,
                    COUNT(DISTINCT personnel_id) as hotel_checkins
                FROM hotel_reports 
                WHERE project_id = :project_id 
                    AND (CASE 
                        WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
                        ELSE hotel_name
                    END = :hotel_name OR :hotel_name = '')
                    AND hotel_name IS NOT NULL
                GROUP BY 
                    CASE 
                        WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
                        ELSE hotel_name
                    END
            ) as hotel_stats) as total_checkins,
            COALESCE(SUM(
                CASE 
                    WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' THEN 1
                    ELSE room_count
                END
            ), 0) as total_booked_rooms,
            COUNT(*) as total_bookings,
            COALESCE(SUM(DATEDIFF(check_out_date, check_in_date) * 
                CASE 
                    WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' THEN 1
                    ELSE room_count
                END
            ), 0) as total_room_nights
        FROM (
            SELECT 
                MIN(id) as id,
                CASE 
                    WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
                    ELSE hotel_name
                END as hotel_name,
                room_type,
                room_count,
                shared_room_info,
                check_in_date,
                check_out_date
            FROM hotel_reports 
            WHERE project_id = :project_id 
                AND (CASE 
                    WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
                    ELSE hotel_name
                END = :hotel_name OR :hotel_name = '')
                AND hotel_name IS NOT NULL
            GROUP BY 
                CASE 
                    WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
                    ELSE hotel_name
                END,
                room_type,
                room_count,
                shared_room_info,
                check_in_date,
                check_out_date,
                CASE 
                    WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' THEN shared_room_info
                    ELSE CONCAT(id, '-', room_type, '-', room_count)
                END
        ) as grouped_rooms";
        
        $project_total_stmt = $db->prepare($project_total_query);
        $project_total_stmt->bindParam(':project_id', $projectId);
        $project_total_stmt->bindParam(':hotel_name', $hotel_id);
        $project_total_stmt->execute();
        $project_total = $project_total_stmt->fetch(PDO::FETCH_ASSOC);
        
        // 检查当前筛选条件下的数据
        if ($project_total['total_bookings'] == 0 && !empty($hotel_id)) {
            // 检查该酒店是否有任何数据（标准化酒店名称）
            $check_hotel_query = "SELECT COUNT(*) FROM hotel_reports WHERE project_id = :project_id AND 
                (CASE 
                    WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
                    ELSE hotel_name
                END = :hotel_name)";
            $check_stmt = $db->prepare($check_hotel_query);
            $check_stmt->bindParam(':project_id', $projectId);
            $check_stmt->bindParam(':hotel_name', $hotel_id);
            $check_stmt->execute();
            $hotel_exists = $check_stmt->fetchColumn();
            
            if ($hotel_exists == 0) {
                echo '<div class="col-12">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>提示：</strong>酒店 "' . htmlspecialchars($hotel_id) . '" 暂无预订记录。
                        <a href="hotel_statistics.php" class="alert-link">查看全部酒店数据</a>
                    </div>
                </div>';
            }
        }
        ?>
        
        <div class="col-12 mb-3">
            <div class="project-total-card">
                <div class="d-flex align-items-center mb-3">
                    <h5 class="mb-0"><i class="bi bi-graph-up"></i> 项目总统计</h5>
                </div>
                <div class="row text-center">
                    <div class="col-md-3">
                        <div class="stat-number"><?php echo number_format($project_total['total_bookings']); ?></div>
                        <div class="stat-label">总预订次数</div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-number"><?php echo number_format($project_total['total_booked_rooms']); ?></div>
                        <div class="stat-label">总房间数</div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-number"><?php echo number_format($project_total['total_checkins']); ?></div>
                        <div class="stat-label">总入住人次</div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-number"><?php echo number_format($project_total['total_room_nights']); ?></div>
                        <div class="stat-label">总房晚数</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 各酒店统计概览 -->
    <div class="row mb-4">
        <?php foreach ($basic_stats as $hotel): ?>
            <div class="col-md-6 mb-3">
                <div class="hotel-stat-card">
                    <h6 class="mb-3 text-white"><?php echo htmlspecialchars($hotel['hotel_name']); ?></h6>
                    <div class="row text-center">
                        <div class="col-3">
                            <div class="stat-number"><?php echo number_format($hotel['total_bookings']); ?></div>
                            <div class="stat-label">预订次数</div>
                        </div>
                        <div class="col-3">
                            <div class="stat-number"><?php echo number_format($hotel['total_booked_rooms']); ?></div>
                            <div class="stat-label">房间数</div>
                        </div>
                        <div class="col-3">
                            <div class="stat-number"><?php echo number_format($hotel['total_checkins']); ?></div>
                            <div class="stat-label">入住人次</div>
                        </div>
                        <div class="col-3">
                            <div class="stat-number"><?php echo number_format($hotel['total_room_nights']); ?></div>
                            <div class="stat-label">总房晚</div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- 入住人员详情 -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-people"></i> 入住人员详情</h5>
        </div>
        <div class="card-body">
            <?php if (!empty($personnel_stats)): ?>
                <?php 
                // 按酒店分组人员数据
                $grouped_by_hotel = [];
                foreach ($personnel_stats as $person) {
                    $hotel_name = $person['hotel_name'];
                    if (!isset($grouped_by_hotel[$hotel_name])) {
                        $grouped_by_hotel[$hotel_name] = [];
                    }
                    $grouped_by_hotel[$hotel_name][] = $person;
                }
                
                // 按酒店显示数据
                foreach ($grouped_by_hotel as $hotel_name => $hotel_personnel): 
                ?>
                <div class="mb-4">
                    <h5 class="mb-3 hotel-name"><?php echo htmlspecialchars($hotel_name); ?></h5>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>入住人员</th>
                                    <th>入住日期</th>
                                    <th>退房日期</th>
                                    <th>房型</th>
                                    <th class="text-center">房间数</th>
                                    <th class="text-center">入住天数</th>
                                    <th class="text-center">房晚数</th>
                                    <th>备注信息</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $row_number = 1;
                                // 按照room_group_id分组处理数据
                                $grouped_personnel = [];
                                foreach ($hotel_personnel as $person) {
                                    $group_id = $person['room_group_id'];
                                    if (!isset($grouped_personnel[$group_id])) {
                                        $grouped_personnel[$group_id] = [];
                                    }
                                    $grouped_personnel[$group_id][] = $person;
                                }
                                
                                // 显示分组数据
                                foreach ($grouped_personnel as $group) {
                                    $group_count = count($group);
                                    $first_person = $group[0];
                                    
                                    // 判断是否为共享房间
                                    $is_shared = ($first_person['room_group_type'] === 'shared');
                                    
                                    if ($is_shared) {
                                        // 共享房间：第一行显示所有信息，其他行只显示入住人员
                                        for ($i = 0; $i < $group_count; $i++) {
                                            $person = $group[$i];
                                            echo '<tr>';
                                            
                                            if ($i === 0) {
                                                // 第一行显示序号和合并的单元格
                                                echo '<td class="text-center" rowspan="' . $group_count . '">' . $row_number . '</td>';
                                                echo '<td class="personnel-names" title="' . htmlspecialchars($person['personnel_names']) . '">' . htmlspecialchars($person['personnel_names']) . '</td>';
                                                echo '<td class="date-display date-in" rowspan="' . $group_count . '">' . $first_person['check_in_date'] . '</td>';
                                                echo '<td class="date-display date-out" rowspan="' . $group_count . '">' . $first_person['check_out_date'] . '</td>';
                                                echo '<td rowspan="' . $group_count . '">';
                                                $type = $first_person['room_type'];
                                                $badgeClass = '';
                                                switch ($type) {
                                                    case '大床房': $badgeClass = 'room-type-single'; break;
                                                    case '双床房': $badgeClass = 'room-type-double'; break;
                                                    case '套房': $badgeClass = 'room-type-suite'; break;
                                                    case '行政房': $badgeClass = 'room-type-executive'; break;
                                                    default: $badgeClass = 'room-type-badge';
                                                }
                                                echo '<span class="room-type-badge ' . $badgeClass . '">' . htmlspecialchars($type) . '</span>';
                                                // 添加共享/独享徽章
                                                $is_shared_room = (!empty($first_person['shared_room_info']) && in_array($first_person['room_type'], ['双床房', '套房', '大床房', '总统套房', '副总统套房']));
                                                echo '<br><span class="sharing-badge ' . ($is_shared_room ? 'shared' : 'private') . '">' . ($is_shared_room ? '共享' : '独立') . '</span>';
                                                echo '</td>';
                                                echo '<td class="text-center number-display text-success-custom" rowspan="' . $group_count . '">' . $first_person['room_count'] . '</td>';
                                                echo '<td class="text-center number-display text-info-custom" rowspan="' . $group_count . '">' . $first_person['stay_days'] . '</td>';
                                                echo '<td class="text-center number-display text-warning-custom" rowspan="' . $group_count . '">' . $first_person['room_nights'] . '</td>';
                                                echo '<td class="notes-cell" rowspan="' . $group_count . '">';
                                                $display = [];
                                                if (!empty($first_person['special_requirements'])) {
                                                    $display[] = htmlspecialchars($first_person['special_requirements']);
                                                }
                                                // 过滤掉可能包含"手动输入天数"的信息
                                                if (!empty($first_person['shared_room_info']) && strpos($first_person['shared_room_info'], '手动输入天数') === false) {
                                                    $display[] = '<small class="text-muted">' . htmlspecialchars($first_person['shared_room_info']) . '</small>';
                                                }
                                                echo implode('<br>', $display);
                                                echo '</td>';
                                            } else {
                                                // 其他行只显示入住人员
                                                echo '<td class="personnel-names" title="' . htmlspecialchars($person['personnel_names']) . '">' . htmlspecialchars($person['personnel_names']) . '</td>';
                                            }
                                            
                                            echo '</tr>';
                                        }
                                        $row_number++;
                                    } else {
                                        // 非共享房间：每行独立显示
                                        foreach ($group as $person) {
                                            echo '<tr>';
                                            echo '<td class="text-center">' . $row_number++ . '</td>';
                                            echo '<td class="personnel-names" title="' . htmlspecialchars($person['personnel_names']) . '">' . htmlspecialchars($person['personnel_names']) . '</td>';
                                            echo '<td class="date-display date-in">' . $person['check_in_date'] . '</td>';
                                            echo '<td class="date-display date-out">' . $person['check_out_date'] . '</td>';
                                            echo '<td>';
                                            $type = $person['room_type'];
                                            $badgeClass = '';
                                            switch ($type) {
                                                case '大床房': $badgeClass = 'room-type-single'; break;
                                                case '双床房': $badgeClass = 'room-type-double'; break;
                                                case '套房': $badgeClass = 'room-type-suite'; break;
                                                case '行政房': $badgeClass = 'room-type-executive'; break;
                                                default: $badgeClass = 'room-type-badge';
                                            }
                                            echo '<span class="room-type-badge ' . $badgeClass . '">' . htmlspecialchars($type) . '</span>';
                                            // 添加共享/独享徽章
                                            $is_shared_room = (!empty($person['shared_room_info']) && in_array($person['room_type'], ['双床房', '套房', '大床房', '总统套房', '副总统套房']));
                                            echo '<br><span class="sharing-badge ' . ($is_shared_room ? 'shared' : 'private') . '">' . ($is_shared_room ? '共享' : '独立') . '</span>';
                                            echo '</td>';
                                            echo '<td class="text-center number-display text-success-custom">' . $person['room_count'] . '</td>';
                                            echo '<td class="text-center number-display text-info-custom">' . $person['stay_days'] . '</td>';
                                            echo '<td class="text-center number-display text-warning-custom">' . $person['room_nights'] . '</td>';
                                            echo '<td class="notes-cell">';
                                            $display = [];
                                            if (!empty($person['special_requirements'])) {
                                                $display[] = htmlspecialchars($person['special_requirements']);
                                            }
                                            // 过滤掉可能包含"手动输入天数"的信息
                                            if (!empty($person['shared_room_info']) && strpos($person['shared_room_info'], '手动输入天数') === false) {
                                                $display[] = '<small class="text-muted">' . htmlspecialchars($person['shared_room_info']) . '</small>';
                                            }
                                            // 过滤掉特殊要求中可能包含"手动输入天数"的信息
                                            if (!empty($person['special_requirements']) && strpos($person['special_requirements'], '手动输入天数') === false) {
                                                $display[] = '<small class="text-muted">' . htmlspecialchars($person['special_requirements']) . '</small>';
                                            }
                                            echo implode('<br>', $display);
                                            echo '</td>';
                                            echo '</tr>';
                                        }
                                    }
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-4">
                    <i class="bi bi-info-circle text-muted" style="font-size: 3rem;"></i>
                    <p class="text-muted mt-3">暂无入住记录</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 每日入住统计 -->
    <?php if ($show_daily_stats && !empty($daily_stats)): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-calendar-date"></i> 每日入住统计</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>日期</th>
                            <th>酒店</th>
                            <th class="text-center">入住人数</th>
                            <th class="text-center">预订房间数</th>
                            <th class="text-center">房晚数</th>
                            <th>房型</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($daily_stats as $day): ?>
                            <tr>
                                <td class="date-display date-in"><?php echo $day['date']; ?></td>
                                <td class="hotel-name" title="<?php echo htmlspecialchars($day['hotel_name']); ?>"><?php echo htmlspecialchars($day['hotel_name']); ?></td>
                                <td class="text-center number-display text-info-custom"><?php echo $day['daily_checkins']; ?></td>
                                <td class="text-center number-display text-success-custom"><?php echo $day['daily_booked_rooms']; ?></td>
                                <td class="text-center number-display text-warning-custom"><?php echo $day['daily_room_nights']; ?></td>
                                <td><?php 
                                    $roomTypes = explode(',', $day['room_types_used']);
                                    foreach ($roomTypes as $type) {
                                        $type = trim($type);
                                        $badgeClass = '';
                                        switch ($type) {
                                            case '大床房': $badgeClass = 'room-type-single'; break;
                                            case '双床房': $badgeClass = 'room-type-double'; break;
                                            case '套房': $badgeClass = 'room-type-suite'; break;
                                            case '行政房': $badgeClass = 'room-type-executive'; break;
                                            default: $badgeClass = 'room-type-badge';
                                        }
                                        echo '<span class="room-type-badge ' . $badgeClass . ' me-1">' . htmlspecialchars($type) . '</span>';
                                    }
                                ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 房型统计 -->
    <?php if ($show_room_type_stats && !empty($room_type_stats)): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-house-door"></i> 房型统计</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>酒店</th>
                            <th>房型</th>
                            <th class="text-center">预订次数</th>
                            <th class="text-center">总房间数</th>
                            <th class="text-center">房晚数</th>
                            <th>入住时间范围</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($room_type_stats as $room_type): ?>
                            <tr>
                                <td class="hotel-name" title="<?php echo htmlspecialchars($room_type['hotel_name']); ?>"><?php echo htmlspecialchars($room_type['hotel_name']); ?></td>
                                <td><?php 
                                    $type = $room_type['room_type'];
                                    $badgeClass = '';
                                    switch ($type) {
                                        case '大床房': $badgeClass = 'room-type-single'; break;
                                        case '双床房': $badgeClass = 'room-type-double'; break;
                                        case '套房': $badgeClass = 'room-type-suite'; break;
                                        case '行政房': $badgeClass = 'room-type-executive'; break;
                                        default: $badgeClass = 'room-type-badge';
                                    }
                                    echo '<span class="room-type-badge ' . $badgeClass . '">' . htmlspecialchars($type) . '</span>';
                                ?></td>
                                <td class="text-center number-display text-primary-custom"><?php echo $room_type['bookings_count']; ?></td>
                                <td class="text-center number-display text-success-custom"><?php echo $room_type['total_rooms']; ?></td>
                                <td class="text-center number-display text-warning-custom"><?php echo $room_type['total_room_nights']; ?></td>
                                <td class="date-display">
                                    <span class="date-in"><?php echo $room_type['earliest_checkin']; ?></span>
                                    <span class="text-muted"> 至 </span>
                                    <span class="date-out"><?php echo $room_type['latest_checkout']; ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- 按日期房型统计（新添加的部分） -->
    <?php if (!empty($daily_room_type_stats)): ?>
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-calendar-check"></i> 每日房型统计</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <!-- 创建一个透视表，按日期作为行，房型作为列 -->
                <?php
                // 处理数据，构建透视表
                $room_types = [];
                $data_matrix = [];
                
                // 收集所有房型
                foreach ($daily_room_type_stats as $stat) {
                    $room_type = $stat['room_type'];
                    if (!in_array($room_type, $room_types)) {
                        $room_types[] = $room_type;
                    }
                    
                    // 构建数据矩阵
                    $date = $stat['date'];
                    if (!isset($data_matrix[$date])) {
                        $data_matrix[$date] = [];
                    }
                    
                    if (!isset($data_matrix[$date][$room_type])) {
                        $data_matrix[$date][$room_type] = [
                            'room_count' => 0,
                            'room_nights' => 0
                        ];
                    }
                    
                    $data_matrix[$date][$room_type]['room_count'] += $stat['room_count'];
                    $data_matrix[$date][$room_type]['room_nights'] += $stat['room_nights'];
                }
                
                // 生成从项目开始日期到结束日期的所有日期
                $dates = [];
                $current = strtotime($project_start_date);
                $end = strtotime($project_end_date);
                
                while ($current <= $end) {
                    $dates[] = date('Y-m-d', $current);
                    $current = strtotime('+1 day', $current);
                }
                
                sort($room_types);
                ?>
                
                <table class="table table-striped table-bordered daily-room-type-table">
                    <thead class="table-light">
                        <tr>
                            <th>日期</th>
                            <?php foreach ($room_types as $room_type): ?>
                                <th class="text-center"><?php echo htmlspecialchars($room_type); ?></th>
                            <?php endforeach; ?>
                            <th class="text-center">总计</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $daily_totals = []; // 每日总计
                        $room_type_totals = [
                            'room_count' => [],
                            'room_nights' => []
                        ]; // 每种房型总计
                        
                        // 初始化房型总计数组
                        foreach ($room_types as $room_type) {
                            $room_type_totals['room_count'][$room_type] = 0;
                            $room_type_totals['room_nights'][$room_type] = 0;
                        }
                        
                        foreach ($dates as $date): 
                            $daily_total = [
                                'room_count' => 0,
                                'room_nights' => 0
                            ];
                        ?>
                            <tr>
                                <td><?php echo $date; ?></td>
                                <?php foreach ($room_types as $room_type): ?>
                                    <td class="text-center number-display text-success-custom">
                                        <?php 
                                        $count = isset($data_matrix[$date][$room_type]) ? $data_matrix[$date][$room_type]['room_count'] : 0;
                                        $nights = isset($data_matrix[$date][$room_type]) ? $data_matrix[$date][$room_type]['room_nights'] : 0;
                                        echo $count;
                                        if ($nights > 0 && $nights != $count) {
                                            echo '<br><small class="text-muted">(' . $nights . '晚)</small>';
                                        }
                                        ?>
                                    </td>
                                <?php endforeach; ?>
                                <td class="text-center number-display text-warning-custom">
                                    <?php 
                                    foreach ($room_types as $room_type) {
                                        if (isset($data_matrix[$date][$room_type])) {
                                            $daily_total['room_count'] += $data_matrix[$date][$room_type]['room_count'];
                                            $daily_total['room_nights'] += $data_matrix[$date][$room_type]['room_nights'];
                                            $room_type_totals['room_count'][$room_type] += $data_matrix[$date][$room_type]['room_count'];
                                            $room_type_totals['room_nights'][$room_type] += $data_matrix[$date][$room_type]['room_nights'];
                                        }
                                    }
                                    $daily_totals[$date] = $daily_total;
                                    echo $daily_total['room_count'];
                                    if ($daily_total['room_nights'] > 0 && $daily_total['room_nights'] != $daily_total['room_count']) {
                                        echo '<br><small class="text-muted">(' . $daily_total['room_nights'] . '晚)</small>';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="table-light">
                            <td><strong>总计</strong></td>
                            <?php foreach ($room_types as $room_type): ?>
                                <td class="text-center number-display text-success-custom">
                                    <?php 
                                    $total_count = $room_type_totals['room_count'][$room_type];
                                    $total_nights = $room_type_totals['room_nights'][$room_type];
                                    echo $total_count;
                                    if ($total_nights > 0 && $total_nights != $total_count) {
                                        echo '<br><small class="text-muted">(' . $total_nights . '晚)</small>';
                                    }
                                    ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="text-center number-display text-warning-custom">
                                <?php 
                                $grand_total_count = array_sum($room_type_totals['room_count']);
                                $grand_total_nights = array_sum($room_type_totals['room_nights']);
                                echo $grand_total_count;
                                if ($grand_total_nights > 0 && $grand_total_nights != $grand_total_count) {
                                    echo '<br><small class="text-muted">(' . $grand_total_nights . '晚)</small>';
                                }
                                ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
// 表单提交函数
function submitFilterForm() {
    document.getElementById('filterForm').submit();
}

// 显示筛选状态
function showFilterStatus() {
    const urlParams = new URLSearchParams(window.location.search);
    const hotelId = urlParams.get('hotel_id');
    const showDaily = urlParams.get('show_daily');
    const showRoomType = urlParams.get('show_room_type');
    
    if (hotelId || showDaily || showRoomType) {
        let statusText = '当前筛选条件: ';
        const conditions = [];
        
        if (hotelId) {
            conditions.push(`酒店: ${hotelId}`);
        }
        
        if (showDaily) {
            conditions.push('显示每日统计');
        }
        
        if (showRoomType) {
            conditions.push('显示房型统计');
        }
        
        statusText += conditions.join(', ');
        
        // 创建状态提示元素
        const statusDiv = document.createElement('div');
        statusDiv.className = 'alert alert-info alert-dismissible fade show mt-3';
        statusDiv.innerHTML = `
            <i class="bi bi-info-circle"></i> ${statusText}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        // 插入到页面中
        const container = document.querySelector('.container');
        if (container) {
            container.insertBefore(statusDiv, container.firstChild);
        }
    }
}

// 页面加载完成后执行
document.addEventListener('DOMContentLoaded', function() {
    // 初始化DataTables（如果需要）
    if (document.getElementById('personnelTable')) {
        $('#personnelTable').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/zh.json',
                emptyTable: "暂无入住记录",
                info: "显示第 _START_ 至 _END_ 条，共 _TOTAL_ 条记录",
                infoEmpty: "显示第 0 至 0 条，共 0 条记录",
                search: "搜索：",
                lengthMenu: "显示 _MENU_ 条记录",
                paginate: {
                    first: "首页",
                    last: "末页",
                    next: "下一页",
                    previous: "上一页"
                }
            },
            order: [[2, 'desc']], // 按入住日期降序排列
            pageLength: 25,
            // 确保筛选后表格正常显示
            initComplete: function() {
                console.log('DataTables初始化完成');
            }
        });
    }
    
    // 表单提交事件处理
    const filterForm = document.getElementById('filterForm');
    if (filterForm) {
        filterForm.addEventListener('submit', function(e) {
            e.preventDefault();
            submitFilterForm();
        });
    }
    
    // 处理URL参数变化
    window.addEventListener('popstate', function() {
        location.reload();
    });
    
    // 显示筛选状态
    showFilterStatus();
    
    // 添加加载状态提示
    const hotelSelect = document.getElementById('hotel_id');
    if (hotelSelect) {
        hotelSelect.addEventListener('change', function() {
            // 添加加载提示
            const loadingDiv = document.createElement('div');
            loadingDiv.id = 'loading-message';
            loadingDiv.className = 'alert alert-info mt-2';
            loadingDiv.innerHTML = '<i class="bi bi-hourglass-split"></i> 正在加载数据...';
            
            const cardBody = this.closest('.card-body');
            if (cardBody) {
                cardBody.appendChild(loadingDiv);
            }
        });
    }
    
    // 为每日房型统计表添加DataTables支持（如果需要）
    if ($.fn.dataTable.isDataTable('.daily-room-type-table')) {
        $('.daily-room-type-table').DataTable();
    }
});
</script>

<?php include 'includes/footer.php'; ?>