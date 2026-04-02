<?php
// 管理员酒店信息统计页面
// 可以查看所有项目和所有酒店的统计信息

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
    
    // 定义默认的get_current_page_title函数
    if (!function_exists('get_current_page_title')) {
        function get_current_page_title() {
            $page_titles = [
                'hotel_statistics_admin.php' => '酒店统计管理',
                'index.php' => '控制台',
                'projects.php' => '项目管理',
                'personnel_enhanced.php' => '人员管理',
                'departments_enhanced.php' => '部门管理',
                'meal_reports.php' => '用餐管理',
                'meal_packages.php' => '套餐管理',
                'meal_statistics.php' => '用餐统计',
                'meal_allowance.php' => '餐费补助明细',
                'hotel_management.php' => '酒店管理',
                'hotel_reports_new.php' => '酒店预订管理',
                'hotel_statistics_admin.php' => '酒店统计管理',
                'transportation_reports.php' => '交通管理',
                'transportation_statistics.php' => '交通统计',
                'fleet_management.php' => '车队管理',
                'admin_management.php' => '管理员管理',
                'permission_management_enhanced.php' => '增强权限管理',
                'project_access.php' => '项目访问管理',
                'site_config.php' => '网站配置',
                'backup_management.php' => '备份管理'
            ];
            
            $current_page = basename($_SERVER['PHP_SELF']);
            return $page_titles[$current_page] ?? '管理系统';
        }
    }
}

// 启动session并验证管理员权限
session_start();

// 引入基础控制器进行权限验证
require_once '../includes/BaseAdminController.php';
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

// 设置页面标题
$page_title = get_current_page_title();
$active_page = 'hotel_statistics_admin.php';
$show_page_title = $page_title;
$page_icon = 'bar-chart';

// 引入标准头部
include 'includes/header.php';

// 获取数据库连接
$database = new Database();
$db = $database->getConnection();

// 获取查询参数
$project_id = $_GET['project_id'] ?? '';
$hotel_name = $_GET['hotel_name'] ?? '';

// 获取项目开始和结束日期（用于生成日期序列）
// 如果筛选了特定项目，获取该项目的日期范围，否则使用合理的默认范围
if (!empty($project_id)) {
    $project_dates_query = "SELECT MIN(check_in_date) as start_date, MAX(check_out_date) as end_date FROM hotel_reports WHERE project_id = :project_id AND hotel_name IS NOT NULL";
    $project_dates_stmt = $db->prepare($project_dates_query);
    $project_dates_stmt->bindParam(':project_id', $project_id);
    $project_dates_stmt->execute();
    $project_dates = $project_dates_stmt->fetch(PDO::FETCH_ASSOC);
    $project_start_date = $project_dates['start_date'] ?? date('Y-m-d');
    $project_end_date = $project_dates['end_date'] ?? date('Y-m-d', strtotime('+30 days'));
} else {
    // 没有筛选特定项目时，使用所有数据的日期范围
    $project_dates_query = "SELECT MIN(check_in_date) as start_date, MAX(check_out_date) as end_date FROM hotel_reports WHERE hotel_name IS NOT NULL";
    $project_dates_stmt = $db->prepare($project_dates_query);
    $project_dates_stmt->execute();
    $project_dates = $project_dates_stmt->fetch(PDO::FETCH_ASSOC);
    $project_start_date = $project_dates['start_date'] ?? date('Y-m-d');
    $project_end_date = $project_dates['end_date'] ?? date('Y-m-d', strtotime('+30 days'));
}

// 获取所有项目列表
$projects_query = "SELECT id, name FROM projects ORDER BY name";
$projects_stmt = $db->prepare($projects_query);
$projects_stmt->execute();
$projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取所有酒店列表（基于实际使用过的酒店）
// 如果选择了项目，则只显示该项目相关的酒店
if (!empty($project_id)) {
    $hotels_query = "SELECT DISTINCT hotel_name FROM hotel_reports WHERE hotel_name IS NOT NULL AND project_id = :project_id ORDER BY hotel_name";
    $hotels_stmt = $db->prepare($hotels_query);
    $hotels_stmt->bindValue(':project_id', $project_id);
} else {
    $hotels_query = "SELECT DISTINCT hotel_name FROM hotel_reports WHERE hotel_name IS NOT NULL ORDER BY hotel_name";
    $hotels_stmt = $db->prepare($hotels_query);
}
$hotels_stmt->execute();
$all_hotels = $hotels_stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取酒店统计信息
$stats = [];

// 1. 全局总统计（修正逻辑，基于项目酒店信息统计的数据进行汇总）
// 直接使用已计算的项目总统计和酒店基本信息统计数据进行汇总，确保数据一致性
$global_total = [
    'total_bookings' => 0,
    'total_checkins' => 0,
    'total_booked_rooms' => 0,
    'total_room_nights' => 0,
    'total_hotels' => 0,
    'total_projects' => 0
];

// 先执行所有查询，再进行全局统计计算
// 全局统计计算已移到查询执行之后，避免foreach()警告

// 2. 项目总统计（使用正确的动态计算逻辑，按共享房间分组）
$project_total_query = "SELECT 
    p.name as project_name,
    COUNT(*) as total_checkins,  -- 入住人次：按每次入住记录计算
    COUNT(DISTINCT CASE 
        WHEN hr.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') 
             AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' 
        THEN hr.shared_room_info
        ELSE CONCAT('独立_', hr.id)
    END) as total_booked_rooms,  -- 房间数：按共享房间信息分组
    COUNT(DISTINCT CASE 
        WHEN hr.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') 
             AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' 
        THEN hr.shared_room_info
        ELSE CONCAT('独立_', hr.id)
    END) as total_bookings,  -- 预订次数：与房间数逻辑相同
    COALESCE(SUM(
        CASE 
            WHEN hr.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') 
                 AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' 
            THEN DATEDIFF(hr.check_out_date, hr.check_in_date) / 
                 (SELECT COUNT(*) FROM hotel_reports hr3 
                  WHERE hr3.project_id = hr.project_id 
                  AND hr3.shared_room_info = hr.shared_room_info 
                  AND hr3.shared_room_info IS NOT NULL AND hr3.shared_room_info != '')
            ELSE DATEDIFF(hr.check_out_date, hr.check_in_date)
        END
    ), 0) as total_room_nights  -- 总房晚：共享房间按人数平分
FROM hotel_reports hr
JOIN projects p ON hr.project_id = p.id
WHERE hr.hotel_name IS NOT NULL";

// 添加项目和酒店筛选条件
if (!empty($project_id)) {
    $project_total_query .= " AND hr.project_id = :project_id";
}

if (!empty($hotel_name)) {
    $project_total_query .= " AND (CASE WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1) ELSE hr.hotel_name END) = :hotel_name";
}

$project_total_query .= " GROUP BY p.id, p.name";

// 3. 酒店基本信息统计（修正SQL语法错误）
$basic_stats_query = "SELECT 
    hotel_name,
    p.name as project_name,
    COUNT(DISTINCT grouped_rooms.personnel_id) as total_checkins,
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
        MIN(hr.id) as report_id,
        hr.personnel_id,
        CASE 
            WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
            ELSE hotel_name
        END as hotel_name,
        project_id,
        room_type,
        room_count,
        shared_room_info,
        check_in_date,
        check_out_date
    FROM hotel_reports hr
    WHERE hotel_name IS NOT NULL" . 
    (!empty($project_id) ? " AND project_id = :project_id" : "") . 
    (!empty($hotel_name) ? " AND (CASE WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1) ELSE hotel_name END) = :hotel_name" : "") . 
    "
    GROUP BY CASE 
        WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
        ELSE hotel_name
    END, project_id, room_type, room_count, shared_room_info, check_in_date, check_out_date,
        CASE 
            WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' 
            THEN shared_room_info
            ELSE CONCAT('独立_', hr.id)
        END
) as grouped_rooms
JOIN projects p ON grouped_rooms.project_id = p.id
WHERE 1=1" .
(!empty($project_id) ? " AND grouped_rooms.project_id = :project_id_final" : "") .
(!empty($hotel_name) ? " AND (CASE 
        WHEN grouped_rooms.hotel_name LIKE '% - %' THEN SUBSTRING(grouped_rooms.hotel_name, 1, LOCATE(' - ', grouped_rooms.hotel_name) - 1)
        ELSE grouped_rooms.hotel_name
    END) = :hotel_name_final" : "") . "
GROUP BY hotel_name, p.id, p.name";

// 4. 每日入住统计（修正逻辑，使用分组去重）
$daily_stats_query = "SELECT 
    date,
    hotel_name,
    project_name,
    COUNT(DISTINCT personnel_id) as daily_checkins,
    SUM(actual_rooms) as daily_booked_rooms,
    SUM(actual_room_nights) as daily_room_nights,
    GROUP_CONCAT(room_type ORDER BY room_type) as room_types_used
FROM (
    SELECT 
        DATE(check_in_date) as date,
        CASE 
            WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
            ELSE hotel_name
        END as hotel_name,
        p.name as project_name,
        hr.personnel_id,
        room_type,
        CASE 
            WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' THEN 
                1  -- 共享房间，无论多少人，都只算一间房
            ELSE 
                room_count  -- 非共享房间按实际房间数计算
        END as actual_rooms,
        CASE 
            WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' THEN 
                DATEDIFF(check_out_date, check_in_date) * 1  -- 共享房间的房晚数
            ELSE 
                DATEDIFF(check_out_date, check_in_date) * room_count  -- 非共享房间的房晚数
        END as actual_room_nights,
        MIN(hr.id) as report_id
    FROM hotel_reports hr
    JOIN projects p ON hr.project_id = p.id
    WHERE hr.hotel_name IS NOT NULL" . 
    (!empty($project_id) ? " AND hr.project_id = :project_id" : "") . 
    (!empty($hotel_name) ? " AND (CASE WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1) ELSE hr.hotel_name END) = :hotel_name" : "") . 
    "
    GROUP BY 
        DATE(check_in_date),
        CASE 
            WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
            ELSE hotel_name
        END,
        p.name,
        room_type,
        CASE 
            WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' 
            THEN shared_room_info
            ELSE CONCAT('独立_', hr.id)
        END
) as daily_data
GROUP BY date, hotel_name, project_name";

// 5. 计算全局总统计 - 简化查询逻辑
// 修改全局总统计的房晚数计算逻辑，使其与项目统计概览保持一致
$global_total_query = "SELECT 
    (SELECT SUM(hotel_checkins) FROM (
        SELECT 
            CASE 
                WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
                ELSE hotel_name
            END as normalized_hotel_name,
            COUNT(DISTINCT personnel_id) as hotel_checkins
        FROM hotel_reports 
        WHERE hotel_name IS NOT NULL
            AND (CASE 
                WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
                ELSE hotel_name
            END = :hotel_name_global OR :hotel_name_global = '')
            AND (project_id = :project_id_global OR :project_id_global = '')
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
    COALESCE(SUM(
        DATEDIFF(check_out_date, check_in_date) * 
        CASE 
            WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' THEN 1
            ELSE room_count
        END
    ), 0) as total_room_nights,
    COUNT(DISTINCT CASE 
        WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
        ELSE hotel_name
    END) as total_hotels,
    COUNT(DISTINCT project_id) as total_projects
FROM (
    SELECT 
        MIN(hr.id) as report_id,
        hr.personnel_id,
        hr.project_id,
        hr.room_type,
        hr.room_count,
        hr.shared_room_info,
        hr.check_in_date,
        hr.check_out_date,
        CASE 
            WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1)
            ELSE hr.hotel_name
        END as hotel_name
    FROM hotel_reports hr
    WHERE hr.hotel_name IS NOT NULL" .
    (!empty($project_id) ? " AND hr.project_id = :project_id" : "") .
    (!empty($hotel_name) ? " AND (CASE 
            WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1)
            ELSE hr.hotel_name
        END) = :hotel_name" : "") . "
    GROUP BY 
        CASE 
            WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1)
            ELSE hr.hotel_name
        END,
        hr.project_id,
        hr.room_type,
        hr.room_count,
        hr.shared_room_info,
        hr.check_in_date,
        hr.check_out_date,
        CASE 
            WHEN hr.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' 
            THEN hr.shared_room_info
            ELSE CONCAT('独立_', hr.id)
        END
) as grouped_data";
    
// 调试信息 - 查看查询语句和参数
/*
echo "Global Total Query: " . $global_total_query . "\n";
echo "Project ID: " . ($project_id ?? 'null') . "\n";
echo "Hotel Name: " . ($hotel_name ?? 'null') . "\n";
*/

$global_total_stmt = $db->prepare($global_total_query);

// 绑定参数 - 每个参数绑定相应的次数
if (!empty($project_id)) {
    $global_total_stmt->bindValue(':project_id_global', $project_id);
} else {
    // 如果project_id为空，绑定空字符串以匹配OR条件
    $global_total_stmt->bindValue(':project_id_global', '');
}

if (!empty($hotel_name)) {
    $global_total_stmt->bindValue(':hotel_name_global', $hotel_name);
} else {
    // 如果hotel_name为空，绑定空字符串以匹配OR条件
    $global_total_stmt->bindValue(':hotel_name_global', '');
}

// 绑定动态条件中的参数
if (!empty($project_id)) {
    $global_total_stmt->bindValue(':project_id', $project_id);
}

if (!empty($hotel_name)) {
    $global_total_stmt->bindValue(':hotel_name', $hotel_name);
}

$global_total_stmt->execute();
$global_total = $global_total_stmt->fetch(PDO::FETCH_ASSOC);

// 6. 人员入住详情（修正逻辑，合并共享房间显示）
$personnel_query = "SELECT 
    MIN(hr.id) as report_id,
    p.name as project_name,
    CASE 
        WHEN hr.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' 
        THEN GROUP_CONCAT(per.name ORDER BY per.name SEPARATOR ', ')  -- 合并人员姓名
        ELSE MIN(per.name)
    END as personnel_name,
    CASE 
        WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1)
        ELSE hr.hotel_name
    END as hotel_name,
    hr.check_in_date,
    hr.check_out_date,
    hr.room_type,
    CASE 
        WHEN hr.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' 
        THEN 1  -- 共享房间显示为1间
        ELSE hr.room_count
    END as room_count,
    CASE 
        WHEN hr.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' 
        THEN hr.special_requirements  -- 取特殊要求
        ELSE hr.special_requirements
    END as special_requirements,
    hr.shared_room_info,
    DATEDIFF(hr.check_out_date, hr.check_in_date) as stay_days,
    CASE 
        WHEN hr.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' 
        THEN DATEDIFF(hr.check_out_date, hr.check_in_date)  -- 共享房间房晚数
        ELSE DATEDIFF(hr.check_out_date, hr.check_in_date) * hr.room_count
    END as room_nights,
    COUNT(DISTINCT hr.personnel_id) as person_count  -- 统计实际人员数量
FROM hotel_reports hr
JOIN projects p ON hr.project_id = p.id
JOIN personnel per ON hr.personnel_id = per.id
WHERE hr.hotel_name IS NOT NULL";

// 动态添加条件
if (!empty($project_id)) {
    $personnel_query .= " AND hr.project_id = :project_id";
}

if (!empty($hotel_name)) {
    $personnel_query .= " AND (CASE WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1) ELSE hr.hotel_name END) = :hotel_name";
}

// 添加人员详情查询的GROUP BY子句
$personnel_group_by = " GROUP BY 
    CASE 
        WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1)
        ELSE hr.hotel_name
    END,
    p.name,
    hr.project_id,
    hr.room_type,
    hr.check_in_date,
    hr.check_out_date,
    hr.room_count,
    hr.shared_room_info,
    hr.special_requirements,
    CASE 
        WHEN hr.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' 
        THEN hr.shared_room_info
        ELSE CONCAT('独立_', hr.id)  -- 非共享房间按ID分组，确保独立显示
    END";

$personnel_order_by = " ORDER BY hr.check_in_date DESC, CASE 
    WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1)
    ELSE hr.hotel_name
END";

// 添加最终的GROUP BY和ORDER BY
$basic_stats_query .= " ORDER BY hotel_name, p.name";

// 注意：每日入住统计查询已经在查询中包含了ORDER BY，不需要再添加
// 注意：房型统计查询已经在查询中包含了ORDER BY，不需要再添加

$personnel_query .= $personnel_group_by . $personnel_order_by;

// 7. 每日房型统计（新增）
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
    WHERE hr.hotel_name IS NOT NULL";
    
// 动态添加条件
$conditions = array();

if (!empty($project_id)) {
    $conditions[] = "hr.project_id = :project_id";
}

if (!empty($hotel_name)) {
    $conditions[] = "(CASE 
            WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1)
            ELSE hr.hotel_name
        END = :hotel_name)";
}

if (!empty($conditions)) {
    $daily_room_type_stats_query .= " AND " . implode(" AND ", $conditions);
}

$daily_room_type_stats_query .= " AND DATE_ADD(DATE(hr.check_in_date), INTERVAL seq.n DAY) < hr.check_out_date
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
    WHERE hotel_name IS NOT NULL";
    
// 重置条件数组用于第二个查询部分
$conditions2 = array();

if (!empty($project_id)) {
    $conditions2[] = "project_id = :project_id_2";
}

if (!empty($hotel_name)) {
    $conditions2[] = "(CASE 
            WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
            ELSE hotel_name
        END = :hotel_name_2)";
}

if (!empty($conditions2)) {
    $daily_room_type_stats_query .= " AND " . implode(" AND ", $conditions2);
}

$daily_room_type_stats_query .= "
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

// 执行查询 - 添加检查确保查询不为空
if (!empty($project_total_query)) {
    $project_total_stmt = $db->prepare($project_total_query);
}
if (!empty($basic_stats_query)) {
    $basic_stats_stmt = $db->prepare($basic_stats_query);
}
if (!empty($daily_stats_query)) {
    $daily_stats_stmt = $db->prepare($daily_stats_query);
}
if (!empty($personnel_query)) {
    $personnel_stmt = $db->prepare($personnel_query);
}
if (!empty($daily_room_type_stats_query)) {
    $daily_room_type_stmt = $db->prepare($daily_room_type_stats_query);
}

// 调试信息 - 查看每日入住统计查询语句中的参数占位符
/*
$pattern = '/:(\w+)/';
preg_match_all($pattern, $daily_stats_query, $matches);
echo "Daily Stats Query Placeholders: " . print_r($matches[0], true) . "\n";
*/

// 绑定参数 - 只为每个查询绑定实际需要的参数
// 项目总统计参数绑定
if (!empty($project_id)) {
    $project_total_stmt->bindValue(':project_id', $project_id);
}
if (!empty($hotel_name)) {
    $project_total_stmt->bindValue(':hotel_name', $hotel_name);
}

// 酒店基本信息统计需要项目ID和酒店名称参数（如果有）
if (!empty($project_id)) {
    $basic_stats_stmt->bindValue(':project_id', $project_id);
    $basic_stats_stmt->bindValue(':project_id_final', $project_id);
    $basic_stats_stmt->bindValue(':project_id_filter', $project_id);
}
if (!empty($hotel_name)) {
    $basic_stats_stmt->bindValue(':hotel_name', $hotel_name);
    $basic_stats_stmt->bindValue(':hotel_name_final', $hotel_name);
}

// 每日入住统计需要项目ID和酒店名称参数（如果有）
if (!empty($project_id)) {
    $daily_stats_stmt->bindValue(':project_id', $project_id);
}
if (!empty($hotel_name)) {
    $daily_stats_stmt->bindValue(':hotel_name', $hotel_name);
}

// 人员入住详情需要项目ID和酒店名称参数（如果有）
// 确保参数绑定与查询语句构建逻辑一致
if (!empty($project_id)) {
    $personnel_stmt->bindValue(':project_id', $project_id);
}
if (!empty($hotel_name)) {
    $personnel_stmt->bindValue(':hotel_name', $hotel_name);
}

// 调试信息 - 仅在开发环境中使用
/*
echo "Personnel Query: " . $personnel_query . "\n";
echo "Project ID: " . ($project_id ?? 'null') . "\n";
echo "Hotel Name: " . ($hotel_name ?? 'null') . "\n";
*/

// 每日房型统计需要项目ID和酒店名称参数（如果有）
// 每日房型统计参数绑定 - 明确绑定每个参数
if (!empty($project_id)) {
    $daily_room_type_stmt->bindValue(':project_id', $project_id);
    $daily_room_type_stmt->bindValue(':project_id_2', $project_id);
}
if (!empty($hotel_name)) {
    $daily_room_type_stmt->bindValue(':hotel_name', $hotel_name);
    $daily_room_type_stmt->bindValue(':hotel_name_2', $hotel_name);
}

// 调试信息 - 查看每日入住统计查询语句和参数
/*
echo "Daily Stats Query: " . $daily_stats_query . "\n";
echo "Project ID: " . ($project_id ?? 'null') . "\n";
echo "Hotel Name: " . ($hotel_name ?? 'null') . "\n";
*/

// 准备和执行查询
try {
    // 1. 项目总计查询
    if (!empty($project_total_query)) {
        $project_total_stmt = $db->prepare($project_total_query);
        
        // 简化后的参数绑定
        if (!empty($project_id)) {
            $project_total_stmt->bindValue(':project_id', $project_id);
        }
        
        if (!empty($hotel_name)) {
            $project_total_stmt->bindValue(':hotel_name', $hotel_name);
        }
        
        $project_total_stmt->execute();
    }

    // 2. 基本统计查询
    if (!empty($basic_stats_query)) {
        $basic_stats_stmt = $db->prepare($basic_stats_query);
        if (!empty($project_id)) {
            $basic_stats_stmt->bindValue(':project_id', $project_id);
            $basic_stats_stmt->bindValue(':project_id_final', $project_id);
        }
        if (!empty($hotel_name)) {
            $basic_stats_stmt->bindValue(':hotel_name', $hotel_name);
            $basic_stats_stmt->bindValue(':hotel_name_final', $hotel_name);
        }
        $basic_stats_stmt->execute();
    }

    // 3. 每日统计查询
    if (!empty($daily_stats_query)) {
        $daily_stats_stmt = $db->prepare($daily_stats_query);
        if (!empty($project_id)) {
            $daily_stats_stmt->bindValue(':project_id', $project_id);
        }
        if (!empty($hotel_name)) {
            $daily_stats_stmt->bindValue(':hotel_name', $hotel_name);
        }
        $daily_stats_stmt->execute();
    }

    // 4. 人员查询
    if (!empty($personnel_query)) {
        $personnel_stmt = $db->prepare($personnel_query);
        if (!empty($project_id)) {
            $personnel_stmt->bindValue(':project_id', $project_id);
        }
        if (!empty($hotel_name)) {
            $personnel_stmt->bindValue(':hotel_name', $hotel_name);
        }
        $personnel_stmt->execute();
    }

    // 5. 每日房型统计查询
    if (!empty($daily_room_type_stats_query)) {
        $daily_room_type_stmt = $db->prepare($daily_room_type_stats_query);
        if (!empty($project_id)) {
            $daily_room_type_stmt->bindValue(':project_id', $project_id);
            $daily_room_type_stmt->bindValue(':project_id_2', $project_id);
        }
        if (!empty($hotel_name)) {
            $daily_room_type_stmt->bindValue(':hotel_name', $hotel_name);
            $daily_room_type_stmt->bindValue(':hotel_name_2', $hotel_name);
        }
        $daily_room_type_stmt->execute();
    }

    // 注意：全局总统计查询已在前面执行，此处无需重复执行

    $project_total = !empty($project_total_query) ? $project_total_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $basic_stats = !empty($basic_stats_query) ? $basic_stats_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $daily_stats = !empty($daily_stats_query) ? $daily_stats_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $personnel_stats = !empty($personnel_query) ? $personnel_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $daily_room_type_stats = !empty($daily_room_type_stats_query) ? $daily_room_type_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (PDOException $e) {
    error_log("SQL Error: " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
    throw $e;
}

// 设置页面标题 - 使用标准函数确保侧边栏一致性
$page_title = get_current_page_title();
$active_page = 'hotel_statistics_admin.php';
$show_page_title = $page_title;
$page_icon = 'bar-chart';

?> 

<div class="container-fluid">

    
    <!-- 主内容区域 -->
    <div class="row">
        <div class="col-12">

            <!-- 筛选条件 -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-funnel"></i> 筛选条件</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label for="project_id" class="form-label">选择项目</label>
                            <select class="form-select" id="project_id" name="project_id" onchange="handleProjectChange(this)">
                                <option value="">所有项目</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo htmlspecialchars($project['id']); ?>" 
                                            <?php echo $project_id == $project['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($project['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="hotel_name" class="form-label">选择酒店</label>
                            <select class="form-select" id="hotel_name" name="hotel_name" onchange="handleHotelChange(this)">
                                <option value="">所有酒店</option>
                                <?php foreach ($all_hotels as $hotel): ?>
                                    <option value="<?php echo htmlspecialchars($hotel['hotel_name']); ?>" 
                                            <?php echo $hotel_name == $hotel['hotel_name'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($hotel['hotel_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i> 查询
                            </button>
                            <a href="hotel_statistics_admin.php" class="btn btn-outline-secondary ms-2">
                                <i class="bi bi-arrow-clockwise"></i> 重置
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- 全局总统计 -->
            <?php
            // 使用之前已经计算好的全局统计结果
            $global_total = $global_total;
            ?>
            <div class="row mb-4">
                <div class="col-md-2 mb-3">
                    <div class="card text-center stats-card">
                        <div class="card-body">
                            <i class="bi bi-calendar-check text-primary" style="font-size: 2rem;"></i>
                            <h3 class="card-title"><?php echo number_format($global_total['total_bookings']); ?></h3>
                            <p class="card-text">总预订次数</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 mb-3">
                    <div class="card text-center stats-card">
                        <div class="card-body">
                            <i class="bi bi-house-door text-success" style="font-size: 2rem;"></i>
                            <h3 class="card-title"><?php echo number_format($global_total['total_booked_rooms']); ?></h3>
                            <p class="card-text">总房间数</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 mb-3">
                    <div class="card text-center stats-card">
                        <div class="card-body">
                            <i class="bi bi-people text-info" style="font-size: 2rem;"></i>
                            <h3 class="card-title"><?php echo number_format($global_total['total_checkins']); ?></h3>
                            <p class="card-text">总入住人次</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 mb-3">
                    <div class="card text-center stats-card">
                        <div class="card-body">
                            <i class="bi bi-moon text-warning" style="font-size: 2rem;"></i>
                            <h3 class="card-title"><?php echo number_format($global_total['total_room_nights']); ?></h3>
                            <p class="card-text">总房晚数</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 mb-3">
                    <div class="card text-center stats-card">
                        <div class="card-body">
                            <i class="bi bi-building text-purple" style="font-size: 2rem;"></i>
                            <h3 class="card-title"><?php echo number_format($global_total['total_hotels']); ?></h3>
                            <p class="card-text">合作酒店数</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 mb-3">
                    <div class="card text-center stats-card">
                        <div class="card-body">
                            <i class="bi bi-folder text-danger" style="font-size: 2rem;"></i>
                            <h3 class="card-title"><?php echo number_format($global_total['total_projects']); ?></h3>
                            <p class="card-text">项目数量</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 项目统计概览 -->
            <div class="row mb-4">
                <?php foreach ($project_total as $project): ?>
                    <div class="col-md-6 mb-3">
                        <div class="card stats-card">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="bi bi-folder"></i> <?php echo htmlspecialchars($project['project_name']); ?></h6>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-3">
                                        <h5 class="text-primary mb-1"><?php echo number_format($project['total_bookings']); ?></h5>
                                        <small class="text-muted">预订次数</small>
                                    </div>
                                    <div class="col-3">
                                        <h5 class="text-success mb-1"><?php echo number_format($project['total_booked_rooms']); ?></h5>
                                        <small class="text-muted">房间数</small>
                                    </div>
                                    <div class="col-3">
                                        <h5 class="text-info mb-1"><?php echo number_format($project['total_checkins']); ?></h5>
                                        <small class="text-muted">入住人次</small>
                                    </div>
                                    <div class="col-3">
                                        <h5 class="text-warning mb-1"><?php echo number_format($project['total_room_nights']); ?></h5>
                                        <small class="text-muted">总房晚</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- 各酒店统计概览 -->
            <div class="row mb-4">
                <?php foreach ($basic_stats as $hotel): ?>
                    <div class="col-md-4 mb-3">
                        <div class="card stats-card">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0"><i class="bi bi-building"></i> <?php echo htmlspecialchars($hotel['hotel_name']); ?></h6>
                                <small class="text-light"><i class="bi bi-folder"></i> <?php echo htmlspecialchars($hotel['project_name']); ?></small>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-3">
                                        <h5 class="text-primary mb-1"><?php echo number_format($hotel['total_bookings']); ?></h5>
                                        <small class="text-muted">预订</small>
                                    </div>
                                    <div class="col-3">
                                        <h5 class="text-success mb-1"><?php echo number_format($hotel['total_booked_rooms']); ?></h5>
                                        <small class="text-muted">房间</small>
                                    </div>
                                    <div class="col-3">
                                        <h5 class="text-info mb-1"><?php echo number_format($hotel['total_checkins']); ?></h5>
                                        <small class="text-muted">人次</small>
                                    </div>
                                    <div class="col-3">
                                        <h5 class="text-warning mb-1"><?php echo number_format($hotel['total_room_nights']); ?></h5>
                                        <small class="text-muted">房晚</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- 每日入住统计 -->
            <?php if (!empty($daily_stats)): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-calendar-date"></i> 每日入住统计</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover compact-daily-table" id="dailyTable">
                            <thead class="table-dark">
                                <tr>
                                    <th class="date-col">日期</th>
                                    <th class="hotel-col">酒店信息</th>
                                    <th class="stats-col text-center">入住统计</th>
                                    <th class="rooms-col text-center">房间统计</th>
                                    <th class="room-types-col">房型情况</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($daily_stats as $day): ?>
                                    <tr class="daily-row" 
                                        title="项目: <?php echo htmlspecialchars($day['project_name']); ?>">
                                        <!-- 日期列 -->
                                        <td class="date-info">
                                            <div class="date-display">
                                                <i class="bi bi-calendar3 text-primary me-1"></i>
                                                <span class="date-value"><?php echo $day['date']; ?></span>
                                            </div>
                                            <small class="weekday text-muted">
                                                <?php 
                                                    $weekday = ['日', '一', '二', '三', '四', '五', '六'];
                                                    echo '周' . $weekday[date('w', strtotime($day['date']))];
                                                ?>
                                            </small>
                                        </td>
                                        
                                        <!-- 酒店信息列 -->
                                        <td class="hotel-info" title="<?php echo htmlspecialchars($day['hotel_name']); ?>">
                                            <div class="hotel-name">
                                                <i class="bi bi-building text-info me-1"></i>
                                                <?php echo htmlspecialchars($day['hotel_name']); ?>
                                            </div>
                                            <small class="project-name text-muted">
                                                <i class="bi bi-folder2 me-1"></i>
                                                <?php echo htmlspecialchars($day['project_name']); ?>
                                            </small>
                                        </td>
                                        
                                        <!-- 入住统计列 -->
                                        <td class="stats-info text-center">
                                            <div class="stat-item">
                                                <i class="bi bi-people text-success me-1"></i>
                                                <span class="stat-value text-success"><?php echo $day['daily_checkins']; ?></span>
                                                <span class="stat-label">人</span>
                                            </div>
                                        </td>
                                        
                                        <!-- 房间统计列 -->
                                        <td class="rooms-info text-center">
                                            <div class="rooms-grid">
                                                <div class="room-stat">
                                                    <span class="stat-value text-primary"><?php echo $day['daily_booked_rooms']; ?></span>
                                                    <span class="stat-label">间</span>
                                                </div>
                                                <div class="night-stat">
                                                    <span class="stat-value text-warning"><?php echo $day['daily_room_nights']; ?></span>
                                                    <span class="stat-label">晚</span>
                                                </div>
                                            </div>
                                        </td>
                                        
                                        <!-- 房型情况列 -->
                                        <td class="room-types-info">
                                            <?php 
                                                $room_types = explode(',', $day['room_types_used']);
                                                foreach ($room_types as $type): 
                                                    $type = trim($type);
                                                    $badge_class = '';
                                                    switch($type) {
                                                        case '双床房': $badge_class = 'bg-primary'; break;
                                                        case '大床房': $badge_class = 'bg-success'; break;
                                                        case '套房': $badge_class = 'bg-warning'; break;
                                                        default: $badge_class = 'bg-secondary';
                                                    }
                                            ?>
                                                <span class="badge <?php echo $badge_class; ?> me-1 mb-1">
                                                    <?php echo htmlspecialchars($type); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- 每日房型统计 -->
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
                        
                        // 只生成有数据的日期
                        $dates = array_keys($data_matrix);
                        sort($dates);
                        
                        sort($room_types);
                        ?>
                        
                        <table class="table table-striped table-bordered">
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
                                            <td class="text-center">
                                                <?php 
                                                $count = isset($data_matrix[$date][$room_type]) ? $data_matrix[$date][$room_type]['room_count'] : 0;
                                                $nights = isset($data_matrix[$date][$room_type]) ? $data_matrix[$date][$room_type]['room_nights'] : 0;
                                                echo $count;
                                                if ($nights > 0 && $nights != $count) {
                                                    echo '<br><small class="text-muted">(' . $nights . '晚)</small>';
                                                }
                                                $daily_total['room_count'] += $count;
                                                $daily_total['room_nights'] += $nights;
                                                $room_type_totals['room_count'][$room_type] += $count;
                                                $room_type_totals['room_nights'][$room_type] += $nights;
                                                ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td class="text-center">
                                            <?php 
                                            echo $daily_total['room_count'];
                                            if ($daily_total['room_nights'] > 0 && $daily_total['room_nights'] != $daily_total['room_count']) {
                                                echo '<br><small class="text-muted">(' . $daily_total['room_nights'] . '晚)</small>';
                                            }
                                            ?>
                                        </td>
                                        <?php $daily_totals[$date] = $daily_total; ?>
                                    </tr>
                                <?php endforeach; ?>
                                <!-- 总计行 -->
                                <tr class="table-primary">
                                    <td><strong>总计</strong></td>
                                    <?php 
                                    $grand_total = [
                                        'room_count' => 0,
                                        'room_nights' => 0
                                    ];
                                    foreach ($room_types as $room_type): 
                                        $grand_total['room_count'] += $room_type_totals['room_count'][$room_type];
                                        $grand_total['room_nights'] += $room_type_totals['room_nights'][$room_type];
                                    ?>
                                        <td class="text-center">
                                            <strong>
                                                <?php 
                                                echo $room_type_totals['room_count'][$room_type];
                                                if ($room_type_totals['room_nights'][$room_type] > 0 && 
                                                    $room_type_totals['room_nights'][$room_type] != $room_type_totals['room_count'][$room_type]) {
                                                    echo '<br><small>(' . $room_type_totals['room_nights'][$room_type] . '晚)</small>';
                                                }
                                                ?>
                                            </strong>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="text-center">
                                        <strong>
                                            <?php 
                                            echo $grand_total['room_count'];
                                            if ($grand_total['room_nights'] > 0 && $grand_total['room_nights'] != $grand_total['room_count']) {
                                                echo '<br><small>(' . $grand_total['room_nights'] . '晚)</small>';
                                            }
                                            ?>
                                        </strong>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- 房型统计 -->
            <?php if (!empty($room_type_stats)): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-house-door"></i> 房型统计</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover compact-room-type-table" id="roomTypeTable">
                            <thead class="table-dark">
                                <tr>
                                    <th class="hotel-col">酒店信息</th>
                                    <th class="room-type-col">房型信息</th>
                                    <th class="bookings-col text-center">预订统计</th>
                                    <th class="rooms-stats-col text-center">房间统计</th>
                                    <th class="period-col">入住时间范围</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($room_type_stats as $room_type): ?>
                                    <?php 
                                        $room_type_class = '';
                                        switch($room_type['room_type']) {
                                            case '双床房': $room_type_class = 'room-type-twin'; break;
                                            case '大床房': $room_type_class = 'room-type-king'; break;
                                            case '套房': $room_type_class = 'room-type-suite'; break;
                                            default: $room_type_class = 'room-type-other';
                                        }
                                    ?>
                                    <tr class="room-type-row <?php echo $room_type_class; ?>" 
                                        title="项目: <?php echo htmlspecialchars($room_type['project_name']); ?>">
                                        <!-- 酒店信息列 -->
                                        <td class="hotel-info" title="<?php echo htmlspecialchars($room_type['hotel_name']); ?>">
                                            <div class="hotel-name">
                                                <i class="bi bi-building text-info me-1"></i>
                                                <?php echo htmlspecialchars($room_type['hotel_name']); ?>
                                            </div>
                                            <small class="project-name text-muted">
                                                <i class="bi bi-folder2 me-1"></i>
                                                <?php echo htmlspecialchars($room_type['project_name']); ?>
                                            </small>
                                        </td>
                                        
                                        <!-- 房型信息列 -->
                                        <td class="room-type-info">
                                            <div class="room-type-display">
                                                <?php 
                                                    $badge_class = '';
                                                    $icon_class = '';
                                                    switch($room_type['room_type']) {
                                                        case '双床房': 
                                                            $badge_class = 'bg-primary'; 
                                                            $icon_class = 'bi-menu-button-wide';
                                                            break;
                                                        case '大床房': 
                                                            $badge_class = 'bg-success'; 
                                                            $icon_class = 'bi-square';
                                                            break;
                                                        case '套房': 
                                                            $badge_class = 'bg-warning text-dark'; 
                                                            $icon_class = 'bi-house';
                                                            break;
                                                        default: 
                                                            $badge_class = 'bg-secondary';
                                                            $icon_class = 'bi-door-open';
                                                    }
                                                ?>
                                                <span class="room-type-badge badge <?php echo $badge_class; ?>">
                                                    <i class="bi <?php echo $icon_class; ?> me-1"></i>
                                                    <?php echo htmlspecialchars($room_type['room_type']); ?>
                                                </span>
                                            </div>
                                        </td>
                                        
                                        <!-- 预订统计列 -->
                                        <td class="bookings-info text-center">
                                            <div class="booking-stat">
                                                <i class="bi bi-calendar-check text-primary me-1"></i>
                                                <span class="stat-value text-primary"><?php echo $room_type['bookings_count']; ?></span>
                                                <span class="stat-label">次</span>
                                            </div>
                                        </td>
                                        
                                        <!-- 房间统计列 -->
                                        <td class="rooms-stats-info text-center">
                                            <div class="rooms-stats-grid">
                                                <div class="room-count-stat">
                                                    <span class="stat-value text-success"><?php echo $room_type['total_rooms']; ?></span>
                                                    <span class="stat-label">间</span>
                                                </div>
                                                <div class="room-nights-stat">
                                                    <span class="stat-value text-warning"><?php echo $room_type['total_room_nights']; ?></span>
                                                    <span class="stat-label">晚</span>
                                                </div>
                                            </div>
                                        </td>
                                        
                                        <!-- 入住时间范围列 -->
                                        <td class="period-info">
                                            <div class="date-range">
                                                <div class="start-date">
                                                    <i class="bi bi-calendar-date text-success me-1"></i>
                                                    <span class="date-label">开始:</span>
                                                    <span class="date-value"><?php echo $room_type['earliest_checkin']; ?></span>
                                                </div>
                                                <div class="end-date">
                                                    <i class="bi bi-calendar-date-fill text-danger me-1"></i>
                                                    <span class="date-label">结束:</span>
                                                    <span class="date-value"><?php echo $room_type['latest_checkout']; ?></span>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- 入住人员详情 -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-people"></i> 入住人员详情</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($personnel_stats)): ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover compact-personnel-table" id="personnelTable">
                                <thead class="table-dark">
                                    <tr>
                                        <th class="sequence-col text-center" style="width: 60px;">序号</th>
                                        <th class="personnel-col">人员</th>
                                        <th class="hotel-col">酒店</th>
                                        <th class="dates-col">入住日期</th>
                                        <th class="room-col">房型信息</th>
                                        <th class="stats-col text-center">统计</th>
                                        <th class="requirements-col">特殊要求</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($personnel_stats as $index => $person): ?>
                                        <?php 
                                            $is_shared_room = !empty($person['shared_room_info']);
                                            $room_type_class = $is_shared_room ? 'shared-room' : 'individual-room';
                                            $personnel_names = explode(', ', $person['personnel_name']);
                                            $hotel_display = htmlspecialchars($person['hotel_name']);
                                        ?>
                                        <tr class="personnel-row <?php echo $room_type_class; ?>" 
                                            title="项目: <?php echo htmlspecialchars($person['project_name']); ?>">
                                            <!-- 序号列 -->
                                            <td class="sequence-info text-center">
                                                <span class="sequence-number badge bg-secondary"><?php echo $index + 1; ?></span>
                                            </td>
                                            
                                            <!-- 人员信息列 -->
                                            <td class="personnel-info">
                                                <?php if (strpos($person['personnel_name'], ',') !== false): ?>
                                                    <div class="personnel-group">
                                                        <i class="bi bi-people-fill text-primary me-1"></i>
                                                        <span class="personnel-name"><?php echo htmlspecialchars($person['personnel_name']); ?></span>
                                                        <small class="text-muted ms-2"><?php 
                                                            $personnel_names = explode(', ', $person['personnel_name']);
                                                            echo count($personnel_names); ?>人共享
                                                        </small>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="personnel-single">
                                                        <i class="bi bi-person-fill text-success me-1"></i>
                                                        <span class="personnel-name"><?php echo htmlspecialchars($person['personnel_name']); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            
                                            <!-- 酒店信息列 -->
                                            <td class="hotel-info" title="<?php echo $hotel_display; ?>">
                                                <div class="hotel-name">
                                                    <i class="bi bi-building text-info me-1"></i>
                                                    <?php echo $hotel_display; ?>
                                                </div>
                                                <small class="project-name text-muted">
                                                    <i class="bi bi-folder2 me-1"></i>
                                                    <?php echo htmlspecialchars($person['project_name']); ?>
                                                </small>
                                            </td>
                                            
                                            <!-- 入住日期列 -->
                                            <td class="dates-info">
                                                <div class="check-dates">
                                                    <div class="check-in">
                                                        <i class="bi bi-calendar-check text-success me-1"></i>
                                                        <span class="date-label">入住:</span>
                                                        <span class="date-value"><?php echo $person['check_in_date']; ?></span>
                                                    </div>
                                                    <div class="check-out">
                                                        <i class="bi bi-calendar-x text-danger me-1"></i>
                                                        <span class="date-label">退房:</span>
                                                        <span class="date-value"><?php echo $person['check_out_date']; ?></span>
                                                    </div>
                                                </div>
                                                <small class="stay-duration text-muted">
                                                    共 <?php echo $person['stay_days']; ?> 天
                                                </small>
                                            </td>
                                            
                                            <!-- 房型信息列 -->
                                            <td class="room-info">
                                                <div class="room-type">
                                                    <span class="room-type-badge badge <?php echo !empty($person['shared_room_info']) ? 'bg-primary' : 'bg-success'; ?>">
                                                        <?php echo htmlspecialchars($person['room_type']); ?>
                                                    </span>
                                                </div>
                                                <?php if (!empty($person['shared_room_info'])): ?>
                                                    <small class="shared-info text-primary">
                                                        <i class="bi bi-share me-1"></i>共享房间
                                                    </small>
                                                <?php else: ?>
                                                    <small class="individual-info text-success">
                                                        <i class="bi bi-house me-1"></i>独立房间
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            
                                            <!-- 统计信息列 -->
                                            <td class="stats-info text-center">
                                                <div class="stats-grid">
                                                    <div class="stat-item">
                                                        <div class="stat-value text-primary"><?php echo $person['room_count']; ?></div>
                                                        <div class="stat-label">间</div>
                                                    </div>
                                                    <div class="stat-item">
                                                        <div class="stat-value text-warning"><?php echo $person['room_nights']; ?></div>
                                                        <div class="stat-label">晚</div>
                                                    </div>
                                                    <div class="stat-item">
                                                        <div class="stat-value text-success"><?php echo $person['person_count']; ?></div>
                                                        <div class="stat-label">人</div>
                                                    </div>
                                                </div>
                                            </td>
                                            
                                            <!-- 特殊要求列 -->
                                            <td class="requirements-info">
                                                <?php if (!empty($person['special_requirements'])): ?>
                                                    <div class="requirements-content" 
                                                         data-bs-toggle="tooltip" 
                                                         title="<?php echo htmlspecialchars($person['special_requirements']); ?>">
                                                        <i class="bi bi-exclamation-triangle text-warning me-1"></i>
                                                        <?php 
                                                            $req = htmlspecialchars($person['special_requirements']);
                                                            echo mb_strlen($req) > 20 ? mb_substr($req, 0, 20) . '...' : $req;
                                                        ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">无</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="bi bi-info-circle text-muted" style="font-size: 3rem;"></i>
                            <p class="text-muted mt-3">暂无入住记录</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 添加优化样式 -->
<style>
/* 紧凑型人员表格样式 */
.compact-personnel-table {
    font-size: 0.85rem;
    margin-bottom: 0;
}

.compact-personnel-table th {
    padding: 8px 6px;
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    font-size: 0.8rem;
    white-space: nowrap;
}

.compact-personnel-table td {
    padding: 6px;
    vertical-align: middle;
    border-bottom: 1px solid #e9ecef;
}

/* 每日入住统计表样式 */
.compact-daily-table {
    font-size: 0.85rem;
    margin-bottom: 0;
}

.compact-daily-table th {
    padding: 8px 6px;
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    font-size: 0.8rem;
    white-space: nowrap;
}

.compact-daily-table td {
    padding: 6px;
    vertical-align: middle;
    border-bottom: 1px solid #e9ecef;
}

/* 每日统计表列宽度 - 优化后的5列布局 */
.date-col { width: 18%; }
.hotel-col { width: 25%; }
.stats-col { width: 15%; }
.rooms-col { width: 20%; }
.room-types-col { width: 22%; }

/* 房型统计表样式 */
.compact-room-type-table {
    font-size: 0.85rem;
    margin-bottom: 0;
}

.compact-room-type-table th {
    padding: 8px 6px;
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    font-size: 0.8rem;
    white-space: nowrap;
}

.compact-room-type-table td {
    padding: 6px;
    vertical-align: middle;
    border-bottom: 1px solid #e9ecef;
}

/* 房型统计表列宽度 - 优化后的5列布局 */
.hotel-col { width: 25%; }
.room-type-col { width: 18%; }
.bookings-col { width: 15%; }
.rooms-stats-col { width: 20%; }
.period-col { width: 22%; }

/* 日期信息样式 */
.date-info {
    line-height: 1.3;
}

.date-display {
    font-weight: 500;
    font-size: 0.9rem;
    margin-bottom: 2px;
}

.weekday {
    font-size: 0.75rem;
    display: block;
}

/* 统计项样式 */
.stat-item {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 2px;
}

.rooms-grid, .rooms-stats-grid {
    display: flex;
    justify-content: center;
    gap: 8px;
    flex-wrap: wrap;
}

.room-stat, .night-stat, .room-count-stat, .room-nights-stat {
    display: flex;
    align-items: center;
    gap: 2px;
}

.booking-stat {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 2px;
}

/* 房型徽章样式 */
.room-type-badge {
    font-size: 0.8rem;
    padding: 4px 8px;
    font-weight: 500;
}

.room-type-display {
    text-align: center;
}

/* 日期范围样式 */
.date-range {
    line-height: 1.2;
}

.start-date, .end-date {
    display: flex;
    align-items: center;
    margin-bottom: 1px;
    font-size: 0.8rem;
}

.date-label {
    min-width: 35px;
    font-size: 0.75rem;
}

.date-value {
    font-weight: 500;
}

/* 房型类型行颜色区分 */
.room-type-twin {
    border-left: 3px solid #0d6efd;
}

.room-type-king {
    border-left: 3px solid #198754;
}

.room-type-suite {
    border-left: 3px solid #ffc107;
}

.room-type-other {
    border-left: 3px solid #6c757d;
}

/* 行悬停效果 */
.daily-row, .room-type-row {
    transition: all 0.2s ease;
}

.daily-row:hover, .room-type-row:hover {
    background-color: #f8f9fa !important;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.start-date, .end-date {
    display: flex;
    align-items: center;
    margin-bottom: 1px;
    font-size: 0.8rem;
}

.date-label {
    min-width: 35px;
    font-size: 0.75rem;
}

.date-value {
    font-weight: 500;
}

/* 房型类型行颜色区分 */
.room-type-twin {
    border-left: 3px solid #0d6efd;
}

.room-type-king {
    border-left: 3px solid #198754;
}

.room-type-suite {
    border-left: 3px solid #ffc107;
}

.room-type-other {
    border-left: 3px solid #6c757d;
}

/* 日期行悬停效果 */
.daily-row, .room-type-row {
    transition: all 0.2s ease;
}

.daily-row:hover, .room-type-row:hover {
    background-color: #f8f9fa !important;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

/* 列宽度控制 */
.sequence-col { width: 60px; min-width: 60px; }
.personnel-col { width: 16%; }
.hotel-col { width: 18%; }
.dates-col { width: 18%; }
.room-col { width: 13%; }
.stats-col { width: 12%; }
.requirements-col { width: 15%; }

/* 序号列样式 */
.sequence-info {
    vertical-align: middle;
    padding: 8px 4px !important;
}

.sequence-number {
    font-size: 0.85rem;
    font-weight: 600;
    min-width: 28px;
    height: 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
}

/* 人员信息样式 */
.personnel-info {
    line-height: 1.3;
}

.personnel-group {
    display: flex;
    align-items: center;
    flex-wrap: nowrap;
}

.personnel-group .personnel-name {
    font-weight: 500;
    font-size: 0.9rem;
    margin-bottom: 1px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.personnel-single .personnel-name {
    font-weight: 500;
    font-size: 0.9rem;
    white-space: nowrap;
}

/* 酒店信息样式 */
.hotel-info {
    line-height: 1.2;
}

.hotel-name {
    font-weight: 500;
    font-size: 0.85rem;
    margin-bottom: 2px;
}

.project-name {
    font-size: 0.75rem;
    display: block;
}

/* 日期信息样式 */
.dates-info {
    line-height: 1.2;
}

.check-dates .check-in,
.check-dates .check-out {
    display: flex;
    align-items: center;
    margin-bottom: 1px;
    font-size: 0.8rem;
}

.date-label {
    min-width: 30px;
    font-size: 0.75rem;
}

.date-value {
    font-weight: 500;
}

.stay-duration {
    font-size: 0.7rem;
    margin-top: 2px;
    display: block;
}

/* 房型信息样式 */
.room-info {
    text-align: center;
    line-height: 1.2;
}

.room-type-badge {
    font-size: 0.75rem;
    padding: 3px 6px;
    margin-bottom: 2px;
}

.shared-info, .individual-info {
    font-size: 0.7rem;
    display: block;
}

/* 统计信息样式 */
.stats-grid {
    display: flex;
    justify-content: center;
    gap: 6px;
}

.stat-item {
    text-align: center;
    min-width: 24px;
    flex: 1;
}

.stat-value {
    font-weight: 600;
    font-size: 0.85rem;
    line-height: 1;
}

.stat-label {
    font-size: 0.65rem;
    color: #6c757d;
    line-height: 1;
}

/* 特殊要求样式 */
.requirements-info {
    font-size: 0.8rem;
    line-height: 1.2;
}

.requirements-content {
    cursor: help;
}

/* 行悬停效果 */
.personnel-row {
    transition: all 0.2s ease;
}

.personnel-row:hover {
    background-color: #f8f9fa !important;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

/* 共享房间和独立房间的颜色区分 */
.shared-room {
    border-left: 3px solid #0d6efd;
}

.individual-room {
    border-left: 3px solid #198754;
}

/* 响应式优化 */
@media (max-width: 1200px) {
    .compact-personnel-table {
        font-size: 0.8rem;
    }
    
    .compact-personnel-table th,
    .compact-personnel-table td {
        padding: 4px;
    }
    
    .hotel-name, .personnel-name {
        font-size: 0.8rem;
    }
    
    .project-name, .date-label {
        font-size: 0.7rem;
    }
}

@media (max-width: 768px) {
    .compact-personnel-table {
        font-size: 0.75rem;
    }
    
    .stats-grid {
        flex-direction: column;
        gap: 2px;
    }
    
    .check-dates .check-in,
    .check-dates .check-out {
        font-size: 0.75rem;
    }
}

/* DataTables样式优化 */
.dataTables_wrapper .dataTables_length,
.dataTables_wrapper .dataTables_filter {
    margin-bottom: 10px;
}

.dataTables_wrapper .dataTables_info,
.dataTables_wrapper .dataTables_paginate {
    margin-top: 10px;
}

.dataTables_wrapper .dataTables_filter input {
    border-radius: 4px;
    border: 1px solid #ced4da;
    padding: 4px 8px;
}

/* 表格头部样式 */
.table-dark th {
    background-color: #495057 !important;
    border-color: #495057 !important;
    color: white !important;
}
</style>

<!-- 添加DataTables支持 -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="assets/js/datatables-zh.js"></script>

<script>
// 确保jQuery已加载
if (typeof jQuery !== 'undefined') {
    jQuery(document).ready(function($) {
        // 初始化DataTables
        if ($('#personnelTable').length) {
            $('#personnelTable').DataTable({
                language: window.DataTableLanguage || {
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
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "全部"]],
                responsive: true,
                columnDefs: [
                    { targets: [4], orderable: false }, // 统计列不排序
                    { targets: [5], orderable: false }  // 特殊要求列不排序
                ],
                dom: '<"row"<"col-md-6"l><"col-md-6"f>>rtip',
                initComplete: function() {
                    console.log('人员详情表初始化完成');
                }
            });
        }

        // 初始化每日统计表
        if ($('#dailyTable').length) {
            $('#dailyTable').DataTable({
                language: window.DataTableLanguage || {
                    emptyTable: "暂无每日记录",
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
                order: [[0, 'desc']], // 按日期降序排列
                pageLength: 15,
                lengthMenu: [[10, 15, 25, 50, -1], [10, 15, 25, 50, "全部"]],
                responsive: true,
                columnDefs: [
                    { targets: [2, 3], orderable: false }, // 统计列不排序
                    { targets: [4], orderable: false }  // 房型列不排序
                ],
                dom: '<"row"<"col-md-6"l><"col-md-6"f>>rtip'
            });
        }

        // 初始化房型统计表
        if ($('#roomTypeTable').length) {
            $('#roomTypeTable').DataTable({
                language: window.DataTableLanguage || {
                    emptyTable: "暂无房型记录",
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
                order: [[0, 'asc']], // 按酒店名称升序排列
                pageLength: 20,
                lengthMenu: [[10, 20, 30, 50, -1], [10, 20, 30, 50, "全部"]],
                responsive: true,
                columnDefs: [
                    { targets: [2, 3], orderable: false }, // 统计列不排序
                    { targets: [4], orderable: false }  // 时间范围列不排序
                ],
                dom: '<"row"<"col-md-6"l><"col-md-6"f>>rtip'
            });
        }

        // 初始化Bootstrap工具提示
        if (typeof bootstrap !== 'undefined') {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl, {
                    trigger: 'hover',
                    delay: { show: 300, hide: 100 }
                });
            });
        }

        // 表格样式优化
        $('.table').addClass('table-sm');
        
        // 添加数据加载指示器
        $(document).ajaxStart(function() {
            $('body').addClass('loading');
        }).ajaxStop(function() {
            $('body').removeClass('loading');
        });

        // 统计卡片悬停效果增强
        $('.stats-card').hover(
            function() {
                $(this).css({
                    'transform': 'translateY(-2px)',
                    'box-shadow': '0 4px 12px rgba(0,0,0,0.15)'
                });
            },
            function() {
                $(this).css({
                    'transform': 'translateY(0)',
                    'box-shadow': ''
                });
            }
        );

        // 特殊要求列的完整显示功能
        $('.requirements-content').on('click', function() {
            var fullText = $(this).attr('title');
            if (fullText && fullText.length > 20) {
                alert('特殊要求：\n' + fullText);
            }
        });

        // 数据统计显示
        var totalRows = $('#personnelTable tbody tr').length;
        if (totalRows > 0) {
            console.log('共显示 ' + totalRows + ' 条人员记录');
        }
    });
} else {
    console.error('jQuery未正确加载');
}</script>

    </div>
    </div>
    
    <?php include 'includes/footer.php'; ?>