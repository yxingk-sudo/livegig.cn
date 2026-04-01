<?php
// 用户端和管理员端交通数据同步脚本
session_start();
require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

// 检查并同步数据格式
function syncTransportData($db) {
    echo "=== 开始交通数据同步 ===\n";
    
    try {
        // 1. 检查表结构是否匹配
        $stmt = $db->query("DESCRIBE transportation_reports");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // 2. 检查必要的字段是否存在
        $required_fields = [
            'project_id', 'personnel_id', 'travel_date', 'travel_type',
            'departure_location', 'destination_location', 'passenger_count',
            'contact_phone', 'special_requirements', 'status', 'reported_by'
        ];
        
        $missing_fields = [];
        foreach ($required_fields as $field) {
            if (!in_array($field, $columns)) {
                $missing_fields[] = $field;
            }
        }
        
        if (!empty($missing_fields)) {
            echo "❌ 缺少字段: " . implode(', ', $missing_fields) . "\n";
            return false;
        }
        
        // 3. 检查数据一致性
        $stmt = $db->query("SELECT COUNT(*) as total FROM transportation_reports");
        $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        echo "📊 总记录数: $total\n";
        
        // 4. 检查数据格式问题
        $stmt = $db->query("SELECT tr.*, p.name as personnel_name, pr.name as project_name 
                          FROM transportation_reports tr 
                          LEFT JOIN personnel p ON tr.personnel_id = p.id 
                          LEFT JOIN projects pr ON tr.project_id = pr.id 
                          ORDER BY tr.created_at DESC LIMIT 10");
        $recent_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "\n=== 最近10条记录检查 ===\n";
        foreach ($recent_records as $record) {
            echo "ID: {$record['id']} | 项目: {$record['project_name']} | 人员: {$record['personnel_name']} | 类型: {$record['travel_type']}\n";
        }
        
        // 5. 验证数据完整性
        $stmt = $db->query("SELECT 
                            COUNT(CASE WHEN project_id IS NULL THEN 1 END) as null_project,
                            COUNT(CASE WHEN personnel_id IS NULL THEN 1 END) as null_personnel,
                            COUNT(CASE WHEN travel_date IS NULL THEN 1 END) as null_date,
                            COUNT(CASE WHEN travel_type IS NULL THEN 1 END) as null_type
                            FROM transportation_reports");
        $integrity = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "\n=== 数据完整性检查 ===\n";
        echo "空项目ID: {$integrity['null_project']}\n";
        echo "空人员ID: {$integrity['null_personnel']}\n";
        echo "空出行日期: {$integrity['null_date']}\n";
        echo "空交通类型: {$integrity['null_type']}\n";
        
        return true;
        
    } catch (Exception $e) {
        echo "❌ 错误: " . $e->getMessage() . "\n";
        return false;
    }
}

// 运行同步
if (syncTransportData($db)) {
    echo "\n✅ 数据同步检查完成！\n";
} else {
    echo "\n❌ 数据同步检查失败！\n";
}

// 创建数据同步视图
function createDataSyncView($db) {
    try {
        $view_sql = "
            CREATE OR REPLACE VIEW transport_full_view AS
            SELECT 
                tr.id,
                tr.project_id,
                pr.name as project_name,
                pr.code as project_code,
                tr.personnel_id,
                p.name as personnel_name,
                p.phone as personnel_phone,
                tr.travel_date,
                tr.travel_type,
                tr.departure_location,
                tr.destination_location,
                tr.departure_time,
                tr.passenger_count,
                tr.contact_phone,
                tr.special_requirements,
                tr.status,
                CASE tr.status 
                    WHEN 'pending' THEN '待确认'
                    WHEN 'confirmed' THEN '已确认'
                    WHEN 'cancelled' THEN '已取消'
                    ELSE tr.status
                END as status_label,
                tr.reported_by,
                pu.username as reporter_username,
                tr.created_at,
                tr.updated_at,
                GROUP_CONCAT(f.fleet_number) as assigned_vehicles,
                COUNT(f.id) as vehicle_count
            FROM transportation_reports tr
            LEFT JOIN projects pr ON tr.project_id = pr.id
            LEFT JOIN personnel p ON tr.personnel_id = p.id
            LEFT JOIN project_users pu ON tr.reported_by = pu.id
            LEFT JOIN transportation_fleet_assignments tfa ON tr.id = tfa.transportation_report_id
            LEFT JOIN fleet f ON tfa.fleet_id = f.id
            GROUP BY tr.id
            ORDER BY tr.created_at DESC
        ";
        
        $db->exec($view_sql);
        echo "✅ 数据同步视图创建成功！\n";
        
    } catch (Exception $e) {
        echo "❌ 创建视图失败: " . $e->getMessage() . "\n";
    }
}

createDataSyncView($db);

?>