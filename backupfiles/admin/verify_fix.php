<?php
require_once '../config/database.php';

// 获取数据库连接
$database = new Database();
$db = $database->getConnection();

echo "<h2>验证修复后的查询</h2>";

// 测试项目ID
$project_name = 'AGA Onederful Live 江海迦中国巡回演唱会深圳站';
$project_query = "SELECT id FROM projects WHERE name = :project_name";
$project_stmt = $db->prepare($project_query);
$project_stmt->bindValue(':project_name', $project_name);
$project_stmt->execute();
$project = $project_stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    echo "<p>未找到项目</p>";
    exit;
}

$project_id = $project['id'];
echo "<p>项目ID: " . $project_id . "</p>";

// 测试修复后的酒店基本信息统计查询
echo "<h3>修复后的酒店基本信息统计查询</h3>";
$basic_stats_query = "SELECT 
    MIN(CASE 
        WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
        ELSE hotel_name
    END) as hotel_name,
    p.name as project_name,
    (SELECT COUNT(DISTINCT personnel_id) FROM hotel_reports hr2 
     WHERE hr2.project_id = p.id 
       AND (CASE WHEN hr2.hotel_name LIKE '% - %' THEN SUBSTRING(hr2.hotel_name, 1, LOCATE(' - ', hr2.hotel_name) - 1) ELSE hr2.hotel_name END) = 
           grouped_rooms.hotel_name
       AND hr2.project_id = :project_id_hotel_test
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
        MIN(hr.id) as report_id,
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
    WHERE hotel_name IS NOT NULL AND project_id = :project_id_hotel_sub_test
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
WHERE 1=1
GROUP BY hotel_name, p.id, p.name";

try {
    $basic_stats_stmt = $db->prepare($basic_stats_query);
    $basic_stats_stmt->bindValue(':project_id_hotel_test', $project_id);
    $basic_stats_stmt->bindValue(':project_id_hotel_sub_test', $project_id);
    $basic_stats_stmt->execute();
    $basic_stats = $basic_stats_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1'>";
    echo "<tr><th>酒店名称</th><th>项目名称</th><th>预订数</th><th>房间数</th><th>入住人次</th><th>房晚数</th></tr>";
    foreach ($basic_stats as $stat) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($stat['hotel_name']) . "</td>";
        echo "<td>" . htmlspecialchars($stat['project_name']) . "</td>";
        echo "<td>" . $stat['total_bookings'] . "</td>";
        echo "<td>" . $stat['total_booked_rooms'] . "</td>";
        echo "<td>" . $stat['total_checkins'] . "</td>";
        echo "<td>" . $stat['total_room_nights'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 检查数据是否正确
    if (!empty($basic_stats)) {
        $stat = $basic_stats[0];
        if ($stat['total_bookings'] == 46 && $stat['total_booked_rooms'] == 46) {
            echo "<p style='color: green; font-weight: bold;'>数据正确！预订数和房间数都为46。</p>";
        } else {
            echo "<p style='color: red; font-weight: bold;'>数据仍不正确！预订数：" . $stat['total_bookings'] . "，房间数：" . $stat['total_booked_rooms'] . "</p>";
        }
    }
} catch (Exception $e) {
    echo "<p>查询错误: " . $e->getMessage() . "</p>";
}

// 测试房型统计查询
echo "<h3>修复后的房型统计查询</h3>";
$room_type_stats_query = "SELECT 
    hotel_name,
    project_name,
    room_type,
    COUNT(*) as bookings_count,
    COALESCE(SUM(
        CASE 
            WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' THEN 1
            ELSE room_count
        END
    ), 0) as total_rooms,
    COALESCE(SUM(DATEDIFF(check_out_date, check_in_date) * 
        CASE 
            WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' THEN 1
            ELSE room_count
        END
    ), 0) as total_room_nights,
    MIN(check_in_date) as earliest_checkin,
    MAX(check_out_date) as latest_checkout
FROM (
    SELECT 
        MIN(hr.id) as report_id,
        CASE 
            WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
            ELSE hotel_name
        END as hotel_name,
        p.name as project_name,
        room_type,
        room_count,
        shared_room_info,
        check_in_date,
        check_out_date
    FROM hotel_reports hr
    JOIN projects p ON hr.project_id = p.id
    WHERE hr.hotel_name IS NOT NULL AND hr.project_id = :project_id_room_test
    GROUP BY CASE 
        WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
        ELSE hotel_name
    END, p.name, room_type, room_count, shared_room_info, check_in_date, check_out_date,
        CASE 
            WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' 
            THEN shared_room_info
            ELSE CONCAT('独立_', hr.id)
        END
) as grouped_rooms
WHERE 1=1
GROUP BY hotel_name, project_name, room_type";

try {
    $room_type_stats_stmt = $db->prepare($room_type_stats_query);
    $room_type_stats_stmt->bindValue(':project_id_room_test', $project_id);
    $room_type_stats_stmt->execute();
    $room_type_stats = $room_type_stats_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1'>";
    echo "<tr><th>酒店名称</th><th>项目名称</th><th>房型</th><th>预订数</th><th>房间数</th><th>房晚数</th></tr>";
    foreach ($room_type_stats as $stat) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($stat['hotel_name']) . "</td>";
        echo "<td>" . htmlspecialchars($stat['project_name']) . "</td>";
        echo "<td>" . htmlspecialchars($stat['room_type']) . "</td>";
        echo "<td>" . $stat['bookings_count'] . "</td>";
        echo "<td>" . $stat['total_rooms'] . "</td>";
        echo "<td>" . $stat['total_room_nights'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // 计算总预订数和房间数
    $total_bookings = 0;
    $total_rooms = 0;
    foreach ($room_type_stats as $stat) {
        $total_bookings += $stat['bookings_count'];
        $total_rooms += $stat['total_rooms'];
    }
    
    echo "<p>房型统计总计：预订数 " . $total_bookings . "，房间数 " . $total_rooms . "</p>";
} catch (Exception $e) {
    echo "<p>房型统计查询错误: " . $e->getMessage() . "</p>";
}
?>