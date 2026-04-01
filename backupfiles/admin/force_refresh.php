<?php
// 强制刷新统计缓存
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db.php';

// 强制刷新统计数据
$pdo = get_db_connection();

// 获取实际统计数据
$statsSql = "
    SELECT 
        COUNT(*) as total_bookings,
        SUM(room_count) as total_checkins,
        SUM(room_count * DATEDIFF(check_out_date, check_in_date)) as total_room_nights,
        SUM(room_count) as total_booked_rooms
    FROM hotel_reports
";

$stmt = $pdo->prepare($statsSql);
$stmt->execute();
$basicStats = $stmt->fetch(PDO::FETCH_ASSOC);

echo '<h2>强制刷新统计结果</h2>';
echo '<pre>';
echo '总预订次数: ' . $basicStats['total_bookings'] . "\n";
echo '总房间数: ' . $basicStats['total_booked_rooms'] . "\n";
echo '总入住人次: ' . $basicStats['total_checkins'] . "\n";
echo '总房晚数: ' . $basicStats['total_room_nights'] . "\n";
echo '</pre>';

// 显示原始数据
$allData = $pdo->query("SELECT hotel_name, room_type, room_count, check_in_date, check_out_date FROM hotel_reports ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
echo '<h3>最近10条记录</h3>';
echo '<table border="1" cellpadding="5">';
echo '<tr><th>酒店</th><th>房型</th><th>房间数</th><th>入住日期</th><th>退房日期</th></tr>';
foreach ($allData as $row) {
    echo '<tr>';
    echo '<td>' . htmlspecialchars($row['hotel_name']) . '</td>';
    echo '<td>' . htmlspecialchars($row['room_type']) . '</td>';
    echo '<td>' . $row['room_count'] . '</td>';
    echo '<td>' . $row['check_in_date'] . '</td>';
    echo '<td>' . $row['check_out_date'] . '</td>';
    echo '</tr>';
}
echo '</table>';

echo '<p><a href="hotel_reports_new.php">返回管理员页面</a></p>';
?>