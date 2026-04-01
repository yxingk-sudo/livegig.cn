<?php
// 数据库初始化脚本
require_once '../config/database.php';

echo "开始初始化数据库...\n";

// 创建数据库连接
$database = new Database();
$db = $database->getConnection();

// 删除旧表（如果存在）
$dropQueries = [
    "DROP TABLE IF EXISTS transportation_reports",
    "DROP TABLE IF EXISTS hotel_reports",
    "DROP TABLE IF EXISTS meal_reports",
    "DROP TABLE IF EXISTS project_department_personnel",
    "DROP TABLE IF EXISTS project_users",
    "DROP TABLE IF EXISTS departments",
    "DROP TABLE IF EXISTS personnel",
    "DROP TABLE IF EXISTS projects",
    "DROP TABLE IF EXISTS companies",
    "DROP TABLE IF EXISTS site_config"
];

foreach ($dropQueries as $query) {
    try {
        $db->exec($query);
        echo "删除表成功: $query\n";
    } catch(PDOException $e) {
        echo "删除表警告: " . $e->getMessage() . "\n";
    }
}

// 创建所有表
createTables($db);
echo "数据库表创建完成！\n";

// 插入示例数据
$insertQueries = [
    // 插入示例公司
    "INSERT INTO companies (name, contact_person, phone, email, address) VALUES 
    ('示例公司A', '张三', '13800138001', 'zhang@example.com', '北京市朝阳区')",
    
    // 插入示例项目
    "INSERT INTO projects (company_id, name, code, description, start_date, end_date, status) VALUES 
    (1, '示例项目A1', 'PROJECT-A1', '这是一个示例项目', '2024-01-01', '2024-12-31', 'active')",
    
    // 插入示例人员
    "INSERT INTO personnel (name, phone, email, id_card, gender) VALUES 
    ('李四', '13900139001', 'li@example.com', '110101199001011234', '男')",
    
    // 插入示例酒店预订
    "INSERT INTO hotel_reports (project_id, personnel_id, check_in_date, check_out_date, hotel_name, room_type, room_count, status) VALUES 
    (1, 1, '2024-01-15', '2024-01-20', '北京大酒店', '标准间', 1, 'confirmed')"
];

foreach ($insertQueries as $query) {
    try {
        $db->exec($query);
        echo "插入示例数据成功: $query\n";
    } catch(PDOException $e) {
        echo "插入示例数据警告: " . $e->getMessage() . "\n";
    }
}

echo "数据库初始化完成！\n";
echo "现在可以访问 admin/index.php 查看管理后台\n";
?>