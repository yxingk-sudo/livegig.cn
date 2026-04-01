<?php
// 模拟hotels.php的完整显示逻辑
require_once '../config/database.php';

// 连接数据库
$db = new Database();
$conn = $db->getConnection();

try {
    // 获取项目4的所有记录（与hotels.php相同的查询）
    $query = "SELECT 
                hr.id,
                hr.hotel_name,
                hr.check_in_date,
                hr.check_out_date,
                hr.room_type,
                hr.room_count,
                hr.special_requirements,
                hr.shared_room_info,
                p.name as personnel_name,
                d.name as department_name,
                CASE 
                    WHEN hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' THEN 1 
                    ELSE 0 
                END as is_shared_room
              FROM hotel_reports hr 
              LEFT JOIN personnel p ON hr.personnel_id = p.id 
              LEFT JOIN project_department_personnel pdp ON p.id = pdp.personnel_id AND pdp.project_id = hr.project_id 
              LEFT JOIN departments d ON pdp.department_id = d.id 
              WHERE hr.project_id = 4 
              ORDER BY hr.check_in_date DESC, hr.check_out_date DESC, hr.room_type, hr.shared_room_info, hr.id ASC";
    
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $raw_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "=== 原始记录（共" . count($raw_results) . "条） ===\n";
    foreach ($raw_results as $row) {
        echo "ID: {$row['id']}, 房型: {$row['room_type']}, 人员: {$row['personnel_name']}, shared_room_info: " . ($row['shared_room_info'] ?: 'NULL') . "\n";
    }
    
    // 应用与hotels.php相同的合并逻辑
    $grouped_reports = [];
    
    foreach ($raw_results as $row) {
        if ($row['room_type'] === '双床房' && $row['is_shared_room'] && $row['shared_room_info']) {
            // 双床房共享记录，按共享信息分组
            $group_key = $row['hotel_name'] . '_' . $row['check_in_date'] . '_' . $row['check_out_date'] . '_' . $row['shared_room_info'];
            
            if (!isset($grouped_reports[$group_key])) {
                $grouped_reports[$group_key] = [
                    'id' => $row['id'],
                    'hotel_name' => $row['hotel_name'],
                    'check_in_date' => $row['check_in_date'],
                    'check_out_date' => $row['check_out_date'],
                    'room_type' => $row['room_type'],
                    'room_count' => $row['room_count'],
                    'special_requirements' => $row['special_requirements'],
                    'shared_room_info' => $row['shared_room_info'],
                    'personnel_names' => [$row['personnel_name']],
                    'department_names' => [$row['department_name']],
                    'person_count' => 2, // 双床房固定2人
                    'is_shared_room' => 1,
                    'ids' => [$row['id']]
                ];
            } else {
                // 添加第二个人员
                $grouped_reports[$group_key]['personnel_names'][] = $row['personnel_name'];
                $grouped_reports[$group_key]['department_names'][] = $row['department_name'];
                $grouped_reports[$group_key]['ids'][] = $row['id'];
            }
        } else {
            // 非共享房间或单人入住，单独显示
            $group_key = 'single_' . $row['id'];
            $grouped_reports[$group_key] = [
                'id' => $row['id'],
                'hotel_name' => $row['hotel_name'],
                'check_in_date' => $row['check_in_date'],
                'check_out_date' => $row['check_out_date'],
                'room_type' => $row['room_type'],
                'room_count' => $row['room_count'],
                'special_requirements' => $row['special_requirements'],
                'shared_room_info' => $row['shared_room_info'],
                'personnel_names' => [$row['personnel_name']],
                'department_names' => [$row['department_name']],
                'person_count' => 1,
                'is_shared_room' => 0,
                'ids' => [$row['id']]
            ];
        }
    }
    
    // 格式化人员名称和部门名称
    foreach ($grouped_reports as &$report) {
        $report['personnel_names'] = implode('、', array_filter($report['personnel_names']));
        $report['department_names'] = implode('、', array_filter($report['department_names']));
    }
    
    $hotel_reports = array_values($grouped_reports);
    
    echo "\n=== 合并后记录（共" . count($hotel_reports) . "条） ===\n";
    foreach ($hotel_reports as $i => $report) {
        $ids_str = implode(',', $report['ids']);
        echo "记录 " . ($i+1) . ": 房型: {$report['room_type']}, 人员: {$report['personnel_names']}, 原始ID: {$ids_str}\n";
        if (!empty($report['shared_room_info'])) {
            echo "      共享信息: {$report['shared_room_info']}\n";
        }
    }
    
    // 检查ID完整性
    $all_ids = [];
    foreach ($hotel_reports as $report) {
        $all_ids = array_merge($all_ids, $report['ids']);
    }
    $all_ids = array_unique($all_ids);
    sort($all_ids);
    
    $original_ids = array_column($raw_results, 'id');
    sort($original_ids);
    
    echo "\n=== ID完整性检查 ===\n";
    echo "所有原始ID: " . implode(',', $original_ids) . "\n";
    echo "合并后包含的ID: " . implode(',', $all_ids) . "\n";
    
    $missing_ids = array_diff($original_ids, $all_ids);
    $extra_ids = array_diff($all_ids, $original_ids);
    
    if (empty($missing_ids) && empty($extra_ids)) {
        echo "✓ ID完整性检查通过\n";
    } else {
        if (!empty($missing_ids)) {
            echo "✗ 缺失的ID: " . implode(',', $missing_ids) . "\n";
        }
        if (!empty($extra_ids)) {
            echo "✗ 多余的ID: " . implode(',', $extra_ids) . "\n";
        }
    }
    
} catch (PDOException $e) {
    echo "数据库错误: " . $e->getMessage();
} catch (Exception $e) {
    echo "错误: " . $e->getMessage();
}
?>