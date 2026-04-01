<?php
// 添加共享房间信息字段到hotel_reports表
try {
    $db->exec("ALTER TABLE hotel_reports ADD COLUMN IF NOT EXISTS shared_room_info TEXT NULL DEFAULT NULL");
    echo "共享房间信息字段添加成功！\n";
} catch (PDOException $e) {
    echo "添加字段失败: " . $e->getMessage() . "\n";
}
?>
