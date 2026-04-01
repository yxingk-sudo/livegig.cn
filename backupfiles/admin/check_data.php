<?php
require_once '../config/database.php';

// 获取数据库连接
$database = new Database();
$db = $database->getConnection();

// 检查项目和酒店数据
echo "<h2>项目和酒店数据检查</h2>";

// 查找项目ID
$project_query = "SELECT id, name FROM projects WHERE name = 'AGA Onederful Live 江海迦中国巡回演唱会深圳站'";
$project_stmt = $db->prepare($project_query);
$project_stmt->execute();
$project = $project_stmt->fetch(PDO::FETCH_ASSOC);

if ($project) {
    echo "<p>项目信息：<br>";
    echo "ID: " . $project['id'] . "<br>";
    echo "名称: " . $project['name'] . "</p>";
    
    // 查找该项目的酒店预订数据
    $reports_query = "SELECT 
        hotel_name,
        COUNT(*) as bookings,
        SUM(room_count) as rooms,
        COUNT(DISTINCT personnel_id) as checkins
    FROM hotel_reports 
    WHERE project_id = :project_id AND hotel_name IS NOT NULL
    GROUP BY hotel_name";
    
    $reports_stmt = $db->prepare($reports_query);
    $reports_stmt->bindValue(':project_id', $project['id']);
    $reports_stmt->execute();
    $reports = $reports_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>酒店预订数据：</h3>";
    echo "<table border='1'>";
    echo "<tr><th>酒店名称</th><th>预订数</th><th>房间数</th><th>入住人次</th></tr>";
    
    foreach ($reports as $report) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($report['hotel_name']) . "</td>";
        echo "<td>" . $report['bookings'] . "</td>";
        echo "<td>" . $report['rooms'] . "</td>";
        echo "<td>" . $report['checkins'] . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    
    // 查看所有记录详情
    echo "<h3>详细记录：</h3>";
    $details_query = "SELECT 
        id,
        hotel_name,
        room_type,
        room_count,
        check_in_date,
        check_out_date,
        shared_room_info
    FROM hotel_reports 
    WHERE project_id = :project_id AND hotel_name IS NOT NULL
    ORDER BY hotel_name, check_in_date";
    
    $details_stmt = $db->prepare($details_query);
    $details_stmt->bindValue(':project_id', $project['id']);
    $details_stmt->execute();
    $details = $details_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>酒店名称</th><th>房型</th><th>房间数</th><th>入住日期</th><th>退房日期</th><th>共享信息</th></tr>";
    
    foreach ($details as $detail) {
        echo "<tr>";
        echo "<td>" . $detail['id'] . "</td>";
        echo "<td>" . htmlspecialchars($detail['hotel_name']) . "</td>";
        echo "<td>" . htmlspecialchars($detail['room_type']) . "</td>";
        echo "<td>" . $detail['room_count'] . "</td>";
        echo "<td>" . $detail['check_in_date'] . "</td>";
        echo "<td>" . $detail['check_out_date'] . "</td>";
        echo "<td>" . htmlspecialchars($detail['shared_room_info']) . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
} else {
    echo "<p>未找到项目 'AGA Onederful Live 江海迦中国巡回演唱会深圳站'</p>";
    
    // 列出所有项目
    echo "<h3>所有项目：</h3>";
    $all_projects_query = "SELECT id, name FROM projects ORDER BY name";
    $all_projects_stmt = $db->prepare($all_projects_query);
    $all_projects_stmt->execute();
    $all_projects = $all_projects_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<ul>";
    foreach ($all_projects as $proj) {
        echo "<li>ID: " . $proj['id'] . " - " . htmlspecialchars($proj['name']) . "</li>";
    }
    echo "</ul>";
}
?>