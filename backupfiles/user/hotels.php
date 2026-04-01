<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
// 酒店预订列表页面
session_start();
requireLogin();

// 检查用户是否有项目权限
if (!isset($_SESSION['project_id'])) {
    // 如果用户没有默认项目，跳转到项目选择页面
    header("Location: projects.php");
    exit;
}

checkProjectPermission($_SESSION['project_id']);

$projectId = $_SESSION['project_id'];
$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

// 初始化数据库连接
$database = new Database();
$db = $database->getConnection();

// 获取项目信息
$project = getProjectDetails($projectId, $db);
$projectName = $project['name'] ?? '';

// 获取项目人员
$personnel = getProjectPersonnel($projectId, $db);

// 开始：查询没有住宿安排的人员信息
$no_hotel_personnel = [];
try {
    // 获取当前项目ID
    $current_project_id = $_SESSION['project_id'] ?? 0;
    
    if ($current_project_id > 0) {
        // 查询没有住宿安排的人员
        $no_hotel_query = "
            SELECT DISTINCT p.id, p.name, p.id_card, d.name as department
            FROM personnel p 
            INNER JOIN project_department_personnel pdp ON p.id = pdp.personnel_id
            LEFT JOIN departments d ON pdp.department_id = d.id
            WHERE p.id NOT IN (
                SELECT DISTINCT hr.personnel_id 
                FROM hotel_reports hr
                WHERE hr.project_id = :project_id AND hr.personnel_id IS NOT NULL
            )
            AND pdp.project_id = :project_id
            AND pdp.status = 'active'
            ORDER BY p.name ASC
        ";
        
        $stmt = $db->prepare($no_hotel_query);
        $stmt->execute(['project_id' => $current_project_id]);
        $no_hotel_personnel = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // 如果查询出错，静默处理，不显示错误
    error_log("查询无住宿人员失败: " . $e->getMessage());
}
// 结束：查询没有住宿安排的人员信息

// 获取项目分配的酒店列表
$available_hotels = [];
try {
    $query = "SELECT h.id, h.hotel_name_cn, h.hotel_name_en, h.room_types 
              FROM hotels h 
              JOIN project_hotels ph ON h.id = ph.hotel_id 
              WHERE ph.project_id = :project_id 
              ORDER BY h.hotel_name_cn";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':project_id', $projectId);
    $stmt->execute();
    $available_hotels = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("获取酒店信息失败: " . $e->getMessage());
    $message = "获取酒店信息失败，请稍后再试";
}

// 处理编辑操作
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $editId = $_POST['id'] ?? '';
    $hotelName = trim($_POST['hotel_name'] ?? '');
    $checkInDate = $_POST['check_in_date'] ?? '';
    $checkOutDate = $_POST['check_out_date'] ?? '';
    $roomType = $_POST['room_type'] ?? '';
    $roomCount = intval($_POST['room_count'] ?? 1);
    $specialRequirements = trim($_POST['special_requirements'] ?? '');
    $sharedRoomInfo = trim($_POST['shared_room_info'] ?? '');
    
    // 验证必填字段
    $missing_fields = [];
    if (empty($editId)) $missing_fields[] = 'ID';
    if (empty($hotelName)) $missing_fields[] = '酒店名称';
    if (empty($checkInDate)) $missing_fields[] = '入住日期';
    if (empty($checkOutDate)) $missing_fields[] = '退房日期';
    if (empty($roomType)) $missing_fields[] = '房型';
    
    if (!empty($missing_fields)) {
        $_SESSION['message'] = "请填写所有必填字段：" . implode('、', $missing_fields);
        // 调试信息
        error_log("编辑表单验证失败 - 缺少字段：" . implode('、', $missing_fields));
        error_log("提交的数据：" . print_r([
            'id' => $editId,
            'hotel_name' => $hotelName,
            'check_in_date' => $checkInDate,
            'check_out_date' => $checkOutDate,
            'room_type' => $roomType
        ], true));
    } else {
        try {
            // 验证日期有效性
            $checkIn = new DateTime($checkInDate);
            $checkOut = new DateTime($checkOutDate);
            
            if ($checkOut <= $checkIn) {
                $_SESSION['message'] = "退房日期必须晚于入住日期";
            } else {
                $db->beginTransaction();
                
                // 检查记录是否存在且属于当前项目
                $check_query = "SELECT id, shared_room_info, room_type, hotel_name, check_in_date, check_out_date FROM hotel_reports WHERE id = :id AND project_id = :project_id";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->execute([':id' => $editId, ':project_id' => $projectId]);
                $original_record = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$original_record) {
                    $_SESSION['message'] = "记录不存在或无权限编辑";
                } else {
                    $affected_rows = 0;
                    
                    // 判断是否为共享房间（支持共享的房型且有共享信息）
                    $shareable_room_types = ['双床房', '双人房', '套房', '大床房', '总统套房', '副总统套房'];
                    if (in_array($roomType, $shareable_room_types) && !empty($sharedRoomInfo)) {
                        // 对于共享房间，同时更新所有相关记录
                        // 使用更精确的条件：项目ID、酒店名称、入住日期、退房日期、房型和原始共享房间信息
                        $update_shared_query = "UPDATE hotel_reports SET 
                                                   hotel_name = :hotel_name,
                                                   check_in_date = :check_in_date,
                                                   check_out_date = :check_out_date,
                                                   room_type = :room_type,
                                                   room_count = :room_count,
                                                   special_requirements = :special_requirements,
                                                   shared_room_info = :shared_room_info,
                                                   updated_at = NOW()
                                                WHERE project_id = :project_id 
                                                  AND hotel_name = :original_hotel_name
                                                  AND check_in_date = :original_check_in_date
                                                  AND check_out_date = :original_check_out_date
                                                  AND room_type = :original_room_type
                                                  AND shared_room_info = :original_shared_info";
                        
                        $update_shared_stmt = $db->prepare($update_shared_query);
                        $result = $update_shared_stmt->execute([
                            ':hotel_name' => $hotelName,
                            ':check_in_date' => $checkInDate,
                            ':check_out_date' => $checkOutDate,
                            ':room_type' => $roomType,
                            ':room_count' => $roomCount,
                            ':special_requirements' => $specialRequirements,
                            ':shared_room_info' => $sharedRoomInfo,
                            ':project_id' => $projectId,
                            ':original_shared_info' => $original_record['shared_room_info'],
                            ':original_room_type' => $original_record['room_type'],
                            ':original_hotel_name' => $original_record['hotel_name'],
                            ':original_check_in_date' => $original_record['check_in_date'],
                            ':original_check_out_date' => $original_record['check_out_date']
                        ]);
                        
                        $affected_rows = $update_shared_stmt->rowCount();
                        
                        // 如果没有更新任何记录，可能是因为shared_room_info发生了变化
                        if ($affected_rows == 0) {
                            // 尝试使用新的shared_room_info进行更新
                            // 添加更精确的条件来确保只更新当前共享房间的记录
                            $update_shared_query2 = "UPDATE hotel_reports SET 
                                                        hotel_name = :hotel_name,
                                                        check_in_date = :check_in_date,
                                                        check_out_date = :check_out_date,
                                                        room_type = :room_type,
                                                        room_count = :room_count,
                                                        special_requirements = :special_requirements,
                                                        shared_room_info = :shared_room_info,
                                                        updated_at = NOW()
                                                     WHERE project_id = :project_id 
                                                       AND hotel_name = :original_hotel_name
                                                       AND check_in_date = :original_check_in_date
                                                       AND check_out_date = :original_check_out_date
                                                       AND room_type = :original_room_type
                                                       AND shared_room_info = :original_shared_info
                                                       AND id IN (
                                                           SELECT id FROM (
                                                               SELECT id FROM hotel_reports 
                                                               WHERE project_id = :project_id_2
                                                               AND hotel_name = :original_hotel_name_2
                                                               AND check_in_date = :original_check_in_date_2
                                                               AND check_out_date = :original_check_out_date_2
                                                               AND room_type = :original_room_type_2
                                                               AND shared_room_info = :original_shared_info_2
                                                           ) AS subquery
                                                       )";
                            
                            $update_shared_stmt2 = $db->prepare($update_shared_query2);
                            $result2 = $update_shared_stmt2->execute([
                                ':hotel_name' => $hotelName,
                                ':check_in_date' => $checkInDate,
                                ':check_out_date' => $checkOutDate,
                                ':room_type' => $roomType,
                                ':room_count' => $roomCount,
                                ':special_requirements' => $specialRequirements,
                                ':shared_room_info' => $sharedRoomInfo,
                                ':project_id' => $projectId,
                                ':original_hotel_name' => $original_record['hotel_name'],
                                ':original_check_in_date' => $original_record['check_in_date'],
                                ':original_check_out_date' => $original_record['check_out_date'],
                                ':original_room_type' => $original_record['room_type'],
                                ':original_shared_info' => $original_record['shared_room_info'],
                                ':project_id_2' => $projectId,
                                ':original_hotel_name_2' => $original_record['hotel_name'],
                                ':original_check_in_date_2' => $original_record['check_in_date'],
                                ':original_check_out_date_2' => $original_record['check_out_date'],
                                ':original_room_type_2' => $original_record['room_type'],
                                ':original_shared_info_2' => $original_record['shared_room_info']
                            ]);
                            
                            $affected_rows = $update_shared_stmt2->rowCount();
                        }
                    } else {
                        // 对于非共享房间，只更新单个记录
                        $update_query = "UPDATE hotel_reports SET 
                                            hotel_name = :hotel_name,
                                            check_in_date = :check_in_date,
                                            check_out_date = :check_out_date,
                                            room_type = :room_type,
                                            room_count = :room_count,
                                            special_requirements = :special_requirements,
                                            shared_room_info = :shared_room_info,
                                            updated_at = NOW()
                                         WHERE id = :id AND project_id = :project_id";
                        
                        $update_stmt = $db->prepare($update_query);
                        $result = $update_stmt->execute([
                            ':hotel_name' => $hotelName,
                            ':check_in_date' => $checkInDate,
                            ':check_out_date' => $checkOutDate,
                            ':room_type' => $roomType,
                            ':room_count' => $roomCount,
                            ':special_requirements' => $specialRequirements,
                            ':shared_room_info' => $sharedRoomInfo,
                            ':id' => $editId,
                            ':project_id' => $projectId
                        ]);
                        
                        $affected_rows = $update_stmt->rowCount();
                    }
                    
                    if ($affected_rows > 0) {
                        $db->commit();
                        if ($affected_rows > 1) {
                            $_SESSION['message'] = "共享房间相关的 {$affected_rows} 条记录已成功更新";
                        } else {
                            $_SESSION['message'] = "酒店预订已成功更新";
                        }
                    } else {
                        $db->rollback();
                        $_SESSION['message'] = "更新失败，可能记录未发生变化";
                    }
                }
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollback();
            }
            error_log("编辑酒店预订失败: " . $e->getMessage());
            $_SESSION['message'] = "更新失败: " . $e->getMessage();
        }
    }
    
    header("Location: hotels.php");
    exit;
}

// 处理删除操作
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $hotelReportId = $_GET['id'];
    try {
        $query = "DELETE FROM hotel_reports WHERE id = :id AND project_id = :project_id";
        $stmt = $db->prepare($query);
        $stmt->execute([':id' => $hotelReportId, ':project_id' => $projectId]);
        
        if ($stmt->rowCount() > 0) {
            $_SESSION['message'] = "酒店预订已成功删除";
        } else {
            $_SESSION['message'] = "无法删除该酒店预订，可能已被删除";
        }
    } catch (PDOException $e) {
        error_log("删除酒店预订失败: " . $e->getMessage());
        $_SESSION['message'] = "删除失败: " . $e->getMessage();
    }
    header("Location: hotels.php");
    exit;
}

// 处理批量删除操作
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['batch_delete'])) {
    // 调试信息：记录接收到的批量删除参数
    error_log("批量删除请求 - POST数据: " . print_r($_POST, true));
    
    $selectedIds = $_POST['selected_ids'] ?? [];
    if (empty($selectedIds)) {
        $_SESSION['message'] = "请至少选择一个酒店预订进行删除";
    } else {
        try {
            $db->beginTransaction();
            
            // 收集所有需要删除的ID（包括共享房间关联的记录）
            $allIdsToDelete = [];
            
            // 首先收集所有选中的ID
            foreach ($selectedIds as $id) {
                // 检查记录是否属于当前项目
                $check_query = "SELECT id, shared_room_info, room_type FROM hotel_reports WHERE id = :id AND project_id = :project_id";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->execute([':id' => $id, ':project_id' => $projectId]);
                
                $record = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                // 调试信息：记录ID验证结果
                error_log("验证ID $id - 行数: " . $check_stmt->rowCount() . ", 项目ID: $projectId");
                
                if ($record) {
                    // 记录有效，添加到删除列表
                    $allIdsToDelete[] = $record['id'];
                    
                    // 如果是共享房间，找出所有关联的记录
                    if (!empty($record['shared_room_info'])) {
                        // 判断是否为可共享的房型
                        $shareable_room_types = ['双床房', '双人房', '套房', '大床房', '总统套房', '副总统套房'];
                        if (in_array($record['room_type'], $shareable_room_types)) {
                            // 查找所有具有相同共享房间信息的记录
                            // 使用更精确的条件：项目ID、酒店名称、入住日期、退房日期、房型和共享房间信息
                            // 首先获取原始记录的详细信息
                            $detail_query = "SELECT hotel_name, check_in_date, check_out_date FROM hotel_reports WHERE id = :id AND project_id = :project_id";
                            $detail_stmt = $db->prepare($detail_query);
                            $detail_stmt->execute([':id' => $id, ':project_id' => $projectId]);
                            $detail_record = $detail_stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($detail_record) {
                                $shared_query = "SELECT id FROM hotel_reports WHERE project_id = :project_id 
                                                AND hotel_name = :hotel_name
                                                AND check_in_date = :check_in_date
                                                AND check_out_date = :check_out_date
                                                AND room_type = :room_type
                                                AND shared_room_info = :shared_room_info";
                                $shared_stmt = $db->prepare($shared_query);
                                $shared_stmt->execute([
                                    ':project_id' => $projectId,
                                    ':hotel_name' => $detail_record['hotel_name'],
                                    ':check_in_date' => $detail_record['check_in_date'],
                                    ':check_out_date' => $detail_record['check_out_date'],
                                    ':room_type' => $record['room_type'],
                                    ':shared_room_info' => $record['shared_room_info']
                                ]);
                                
                                $shared_records = $shared_stmt->fetchAll(PDO::FETCH_ASSOC);
                            } else {
                                $shared_records = [];
                            }
                            foreach ($shared_records as $shared_record) {
                                // 避免重复添加
                                if (!in_array($shared_record['id'], $allIdsToDelete)) {
                                    $allIdsToDelete[] = $shared_record['id'];
                                }
                            }
                        }
                    }
                    
                    error_log("ID $id 被确认为有效并准备删除");
                } else {
                    error_log("ID $id 无效，不属于当前项目或不存在");
                }
            }
            
            // 执行删除操作
            $deleted_count = 0;
            if (!empty($allIdsToDelete)) {
                // 使用IN语句一次性删除所有记录
                $placeholders = str_repeat('?,', count($allIdsToDelete) - 1) . '?';
                $delete_query = "DELETE FROM hotel_reports WHERE id IN ($placeholders) AND project_id = ?";
                
                $delete_stmt = $db->prepare($delete_query);
                $params = array_merge($allIdsToDelete, [$projectId]);
                $delete_stmt->execute($params);
                
                $deleted_count = $delete_stmt->rowCount();
            }
            
            $db->commit();
            $_SESSION['message'] = "成功删除了 {$deleted_count} 条酒店预订记录";
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("批量删除酒店预订失败: " . $e->getMessage());
            $_SESSION['message'] = "批量删除失败: " . $e->getMessage();
        }
    }
    header("Location: hotels.php");
    exit;
}

// 获取筛选参数
$filter_hotel = $_GET['hotel'] ?? '';
$filter_room_type = $_GET['room_type'] ?? '';
$filter_department = $_GET['department'] ?? '';
$filter_check_in_date = $_GET['check_in_date'] ?? '';
$filter_check_out_date = $_GET['check_out_date'] ?? '';

// 获取酒店预订列表，合并共享双床房和套房记录，按照部门顺序升序排列
$hotel_reports = [];
$grouped_hotel_reports = []; // 按酒店分组的预订列表
try {
    // 构建查询条件
    $where_conditions = ["hr.project_id = :project_id"];
    $params = [':project_id' => $projectId];
    
    if ($filter_hotel) {
        $where_conditions[] = "hr.hotel_name = :hotel_name"; // 精确匹配而不是LIKE
        $params[':hotel_name'] = $filter_hotel;
    }
    
    if ($filter_room_type) {
        $where_conditions[] = "hr.room_type = :room_type";
        $params[':room_type'] = $filter_room_type;
    }
    
    if ($filter_department) {
        $where_conditions[] = "d.name = :department_name";
        $params[':department_name'] = $filter_department;
    }
    
    if ($filter_check_in_date) {
        $where_conditions[] = "hr.check_in_date >= :check_in_date";
        $params[':check_in_date'] = $filter_check_in_date;
    }
    
    if ($filter_check_out_date) {
        $where_conditions[] = "hr.check_out_date <= :check_out_date";
        $params[':check_out_date'] = $filter_check_out_date;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // 查询所有记录并按共享房间分组，按照部门顺序升序排列
    // 修改查询以连接部门表并按部门排序字段排序
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
                COALESCE(d.sort_order, 0) as department_sort_order,
                d.id as department_id,
                CASE 
                    WHEN hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' THEN 1 
                    ELSE 0 
                END as is_shared_room
              FROM hotel_reports hr 
              LEFT JOIN personnel p ON hr.personnel_id = p.id 
              LEFT JOIN project_department_personnel pdp ON p.id = pdp.personnel_id AND pdp.project_id = hr.project_id 
              LEFT JOIN departments d ON pdp.department_id = d.id 
              WHERE {$where_clause}
              ORDER BY hr.hotel_name, COALESCE(d.sort_order, 0) ASC, d.id ASC, p.name, hr.check_in_date ASC, hr.check_out_date ASC, hr.room_type, hr.shared_room_info, hr.id ASC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $raw_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 按房间合并记录（双床房和套房每2人一间，其他房型每人一间）
    $room_groups = [];
    // 记录每个组合当前可用的房间索引（用于双床房）
    $available_room_indices = [];
    
    foreach ($raw_results as $row) {
        // 为每间双床房、套房、大床房、总统套房和副总统套房创建房间分组
        if ((in_array($row['room_type'], ['双床房', '套房', '大床房', '总统套房', '副总统套房'])) && !empty($row['shared_room_info'])) {
            // 双床房和套房按每间房2人计算，但只有在shared_room_info不为空时才进行分组
            $base_key = $row['hotel_name'] . '_' . $row['check_in_date'] . '_' . $row['check_out_date'] . '_' . $row['room_type'] . '_' . $row['shared_room_info'];
            
            // 检查是否已经存在具有相同base_key的房间组
            $existing_room_key = null;
            foreach ($room_groups as $key => $group) {
                if (isset($group['base_key']) && $group['base_key'] === $base_key) {
                    $existing_room_key = $key;
                    break;
                }
            }
            
            if ($existing_room_key !== null) {
                // 如果已存在相同房间组，则添加人员到该组
                $room_groups[$existing_room_key]['personnel_names'][] = $row['personnel_name'];
                $room_groups[$existing_room_key]['department_names'][] = $row['department_name'];
                $room_groups[$existing_room_key]['person_count']++;
                $room_groups[$existing_room_key]['ids'][] = $row['id'];
            } else {
                // 创建新房间组
                $room_key = $base_key . '_room_' . uniqid();
                $room_groups[$room_key] = [
                    'base_key' => $base_key, // 添加base_key用于后续查找
                    'hotel_name' => $row['hotel_name'],
                    'check_in_date' => $row['check_in_date'],
                    'check_out_date' => $row['check_out_date'],
                    'room_type' => $row['room_type'],
                    'room_count' => 1, // 每间房计为1间
                    'personnel_names' => [$row['personnel_name']],
                    'department_names' => [$row['department_name']],
                    'person_count' => 1, // 当前1人
                    'is_shared_room' => 1, // 所有指定房型且有共享信息的都是共享房间
                    'ids' => [$row['id']],
                    'shared_room_info' => $row['shared_room_info'] ?: ($row['room_type'] === '套房' ? '套房共享' : ($row['room_type'] === '双床房' ? '双床房共享' : $row['room_type'].'共享')),
                    'special_requirements' => $row['special_requirements'] // 添加特殊要求字段
                ];
            }
        } else {
            // 其他房型每人一间，或者指定房型但shared_room_info为空的情况也每人一间
            $single_key = 'single_' . $row['id'];
            $room_groups[$single_key] = [
                'hotel_name' => $row['hotel_name'],
                'check_in_date' => $row['check_in_date'],
                'check_out_date' => $row['check_out_date'],
                'room_type' => $row['room_type'],
                'room_count' => $row['room_count'],
                'personnel_names' => $row['personnel_name'] ? [$row['personnel_name']] : [],
                'department_names' => $row['department_name'] ? [$row['department_name']] : [],
                'person_count' => 1,
                'is_shared_room' => 0,
                'ids' => [$row['id']],
                'shared_room_info' => $row['shared_room_info'],
                'special_requirements' => $row['special_requirements']
            ];
        }
    }
    
    // 重新整理合并后的记录，确保按部门排序
    $hotel_reports = array_values($room_groups);
    
    // 按酒店名称分组
    foreach ($hotel_reports as $report) {
        $hotel_name = $report['hotel_name'];
        if (!isset($grouped_hotel_reports[$hotel_name])) {
            $grouped_hotel_reports[$hotel_name] = [];
        }
        $grouped_hotel_reports[$hotel_name][] = $report;
    }
} catch (PDOException $e) {
    error_log("获取酒店预订列表失败: " . $e->getMessage());
    $message = "获取酒店预订列表失败: " . $e->getMessage();
}

// 计算统计信息
$total_bookings = count($hotel_reports);
$total_room_nights = 0;

// 房型统计
$room_type_stats = [];

foreach ($hotel_reports as $report) {
    // 计算房晚数
    $check_in = new DateTime($report['check_in_date']);
    $check_out = new DateTime($report['check_out_date']);
    $nights = $check_in->diff($check_out)->days;
    
    // 共享房间按实际房间数计算（1间）
    $room_count = $report['is_shared_room'] ? 1 : $report['room_count'];
    $room_nights = $room_count * $nights;
    $total_room_nights += $room_nights;
    
    // 统计房型
    if (!isset($room_type_stats[$report['room_type']])) {
        $room_type_stats[$report['room_type']] = 0;
    }
    $room_type_stats[$report['room_type']] += $room_count;
}

// 获取所有部门列表用于筛选
$all_departments = [];
try {
    $dept_query = "SELECT DISTINCT d.name 
                   FROM departments d 
                   JOIN project_department_personnel pdp ON d.id = pdp.department_id 
                   WHERE pdp.project_id = :project_id 
                   ORDER BY d.name";
    $dept_stmt = $db->prepare($dept_query);
    $dept_stmt->execute([':project_id' => $projectId]);
    $all_departments = $dept_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("获取部门列表失败: " . $e->getMessage());
}

// 获取所有房型列表用于筛选
$all_room_types = [];
try {
    $room_type_query = "SELECT DISTINCT hr.room_type 
                        FROM hotel_reports hr 
                        WHERE hr.project_id = :project_id 
                        ORDER BY hr.room_type";
    $room_type_stmt = $db->prepare($room_type_query);
    $room_type_stmt->execute([':project_id' => $projectId]);
    $all_room_types = $room_type_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("获取房型列表失败: " . $e->getMessage());
}

// 获取所有酒店列表用于筛选
$all_hotels = [];
try {
    $hotel_query = "SELECT DISTINCT hr.hotel_name 
                   FROM hotel_reports hr 
                   WHERE hr.project_id = :project_id 
                   ORDER BY hr.hotel_name";
    $hotel_stmt = $db->prepare($hotel_query);
    $hotel_stmt->execute([':project_id' => $projectId]);
    $all_hotels = $hotel_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("获取酒店列表失败: " . $e->getMessage());
}

// 页面标题和导航
$page_title = '酒店预订管理 - ' . ($projectName ?? '项目');
$active_page = 'hotels';
include 'includes/header.php';
?>
<style>
    /* 整体紧凑化样式 */
    .container {
        max-width: 100%;
        padding: 0 15px;
    }
    
    .card {
        margin-bottom: 1rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        border: 1px solid #e3e6f0;
    }
    
    .card-header {
        padding: 0.75rem 1rem;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-bottom: none;
        color: white;
    }
    
    .card-body {
        padding: 1rem;
    }
    
    .stat-card {
        background: #fff;
        border: 1px solid #e3e6f0;
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 1rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        transition: all 0.2s ease;
    }
    
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    }
    
    .stat-card i {
        font-size: 20px;
        margin-right: 8px;
        color: #007bff;
    }
    
    .stat-number {
        font-size: 1.5rem;
        font-weight: 700;
        color: #2c3e50;
    }
    
    /* 表格优化 */
    .table {
        margin-bottom: 0;
        font-size: 0.9rem;
    }
    
    .table th {
        background-color: #f8f9fa;
        font-weight: 600;
        color: #495057;
        border-bottom: 2px solid #dee2e6;
        padding: 0.6rem 0.5rem;
        white-space: nowrap;
        font-size: 0.85rem;
    }
    
    .table td {
        padding: 0.6rem 0.5rem;
        vertical-align: middle;
        border-bottom: 1px solid #dee2e6;
    }
    
    .table-hover tbody tr:hover {
        background-color: #f5f5f5;
        transform: translateY(-1px);
        transition: all 0.2s ease;
    }
    
    /* 共享房间样式优化 */
    .shared-room-indicator {
        background-color: rgba(0, 123, 255, 0.05);
        border-left: 4px solid #007bff;
    }
    
    /* 房型徽章优化 */
    .room-type-badge {
        display: inline-block;
        padding: 0.25rem 0.5rem;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
        color: white;
        text-align: center;
        min-width: 60px;
    }
    
    .badge-dafang { background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); }
    .badge-shuangchuang { background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%); }
    .badge-taofang { background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%); color: #212529; }
    .badge-fuzongtong { background: linear-gradient(135deg, #6f42c1 0%, #59359a 100%); }
    .badge-zongtong { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); }
    .badge-other { background: linear-gradient(135deg, #6c757d 0%, #545b62 100%); }
    
    /* 酒店名称优化 */
    .hotel-name-container {
        max-width: 200px;
        overflow: hidden;
    }
    
    .hotel-name-cn {
        font-weight: 600;
        font-size: 0.9rem;
        color: #2c3e50;
        margin-bottom: 2px;
        line-height: 1.2;
    }
    
    .hotel-name-en {
        font-size: 0.75rem;
        color: #6c757d;
        line-height: 1.1;
        font-style: italic;
    }
    
    /* 人员信息优化 */
    .personnel-info {
        max-width: 140px;
        overflow: hidden;
    }
    
    .personnel-names {
        font-weight: 500;
        font-size: 0.85rem;
        color: #2c3e50;
        margin-bottom: 2px;
        word-break: break-all;
        line-height: 1.3;
    }
    
    .department-names {
        font-size: 0.75rem;
        color: #007bff;
        font-weight: 500;
        line-height: 1.1;
    }
    
    /* 日期显示优化 */
    .date-display {
        font-size: 0.85rem;
        font-weight: 500;
        color: #495057;
        white-space: nowrap;
    }
    
    .date-range {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }
    
    .date-in {
        color: #28a745;
        font-weight: 600;
    }
    
    .date-out {
        color: #dc3545;
        font-weight: 600;
    }
    
    /* 房数显示 */
    .room-count {
        font-size: 1rem;
        font-weight: 700;
        color: #007bff;
        text-align: center;
    }
    
    /* 特殊要求显示 */
    .special-requirements {
        font-size: 0.8rem;
        color: #495057;
        white-space: normal;
        word-wrap: break-word;
        max-width: 150px;
        line-height: 1.4;
    }
    
    /* 房间信息优化 - 修改这里确保完整显示 */
    .room-info {
        font-size: 0.8rem;
        color: #6c757d;
        white-space: normal;
        word-wrap: break-word;
        max-width: 200px;
        line-height: 1.4;
    }
    
    /* 共享房间徽章 */
    .shared-badge {
        font-size: 0.7rem;
        padding: 0.2rem 0.4rem;
        border-radius: 4px;
        font-weight: 600;
    }
    
    .bg-warning { background-color: #ffc107 !important; }
    .bg-secondary { background-color: #6c757d !important; }
    .text-dark { color: #495057 !important; }
    
    /* 操作按钮优化 */
    .action-buttons {
        white-space: nowrap;
        min-width: 120px;
    }
    
    .btn-group-sm > .btn {
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
        border-radius: 4px;
        margin-right: 2px;
    }
    
    .btn-group-sm > .btn:last-child {
        margin-right: 0;
    }
    
    .btn-sm {
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
        border-radius: 4px;
        transition: all 0.2s ease;
    }
    
    .btn-sm:hover {
        transform: translateY(-1px);
        box-shadow: 0 2px 4px rgba(0,0,0,0.15);
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
        border-color: #007bff;
    }
    
    .btn-danger {
        background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        border-color: #dc3545;
    }
    
    /* 复选框优化 */
    .form-check-input {
        margin-top: 0.1rem;
    }
    
    /* 响应式优化 */
    @media (max-width: 1200px) {
        .table {
            font-size: 0.8rem;
        }
        
        .hotel-name-container {
            max-width: 150px;
        }
        
        .personnel-info {
            max-width: 120px;
        }
        
        .special-requirements {
            max-width: 100px;
        }
        
        .room-info {
            max-width: 150px;
        }
    }
    
    @media (max-width: 992px) {
        .table {
            font-size: 0.75rem;
        }
        
        .table th,
        .table td {
            padding: 0.4rem 0.3rem;
        }
        
        .hotel-name-container {
            max-width: 120px;
        }
        
        .personnel-info {
            max-width: 100px;
        }
        
        .room-info {
            max-width: 120px;
        }
    }
    
    /* 统计卡片响应式 */
    @media (max-width: 768px) {
        .stat-card {
            margin-bottom: 0.5rem;
        }
        
        .stat-number {
            font-size: 1.25rem;
        }
    }
    
    /* 房型统计优化 */
    .room-type-stat {
        background: #f8f9fa;
        border: 1px solid #e9ecef;
        border-radius: 6px;
        padding: 0.5rem;
        text-align: center;
        transition: all 0.2s ease;
    }
    
    .room-type-stat:hover {
        background: #e9ecef;
        transform: translateY(-1px);
    }
    
    .room-type-stat .badge {
        font-size: 0.8rem;
        padding: 0.3rem 0.6rem;
    }
</style>

    <div class="container mt-4">
        <!-- 消息提示 -->
        <?php if ($message): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- 开始：无住宿人员提示区域 -->
        <?php if (!empty($no_hotel_personnel)): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert" style="max-width: 800px; margin: 20px auto;">
            <h5 class="alert-heading">
                <i class="fa fa-info-circle me-2"></i>
                人员住宿统计提醒
            </h5>
            <p class="mb-2">
                <strong>以下 <?php echo count($no_hotel_personnel); ?> 位人员在当前项目中没有任何住宿安排：</strong>
            </p>
            <div class="row">
                <?php 
                $chunks = array_chunk($no_hotel_personnel, 3);
                foreach ($chunks as $chunk): 
                ?>
                    <div class="col-md-4">
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($chunk as $person): ?>
                                <li class="small">
                                    <i class="fa fa-user text-muted me-1"></i>
                                    <?php echo htmlspecialchars($person['name']); ?>
                                    <?php if (!empty($person['department'])): ?>
                                        <span class="text-muted">(<?php echo htmlspecialchars($person['department']); ?>)</span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>
            <hr>
            <p class="mb-0 small text-muted">
                提示：这些人员可能还未安排住宿，建议检查是否需要为他们创建住宿记录。
            </p>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="关闭"></button>
        </div>
        <?php endif; ?>
        <!-- 结束：无住宿人员提示区域 -->

        <!-- 统计卡片 -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <i class="fa fa-calendar-check-o"></i>
                        <div>
                            <div class="stat-number"><?php echo $total_bookings; ?></div>
                            <div class="text-sm text-muted">预订总数</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <i class="fa fa-bed"></i>
                        <div>
                            <div class="stat-number"><?php echo $total_room_nights; ?></div>
                            <div class="text-sm text-muted">房晚总数</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <i class="fa fa-building"></i>
                        <div>
                            <div class="stat-number"><?php echo count($available_hotels); ?></div>
                            <div class="text-sm text-muted">可用酒店</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <i class="fa fa-users"></i>
                        <div>
                            <div class="stat-number"><?php echo count($personnel); ?></div>
                            <div class="text-sm text-muted">项目人员</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 房型统计卡片 -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">房型统计</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php if (!empty($room_type_stats)): ?>
                        <?php foreach ($room_type_stats as $type => $count): ?>
                            <div class="col-md-2 col-sm-4 col-6 mb-2">
                                <div class="room-type-stat">
                                    <span class="badge <?php 
                                        switch ($type) {
                                            case '大床房': echo 'badge-dafang'; break;
                                            case '双床房': echo 'badge-shuangchuang'; break;
                                            case '套房': echo 'badge-taofang'; break;
                                            case '副总统套房': echo 'badge-fuzongtong'; break;
                                            case '总统套房': echo 'badge-zongtong'; break;
                                            default: echo 'badge-other'; break;
                                        }
                                    ?>
                                    ">
                                        <?php echo htmlspecialchars($type); ?>: <?php echo $count; ?>间
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-12 text-center text-muted">暂无统计数据</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 酒店预订列表 -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">酒店预订列表</h5>
                <div class="d-flex gap-2">
                    <!-- 批量删除按钮 -->
                    <button type="button" id="batchDeleteBtn" class="btn btn-sm btn-danger" disabled>
                        <i class="fa fa-trash-o"></i> 批量删除
                    </button>
                    <!-- 添加预订按钮 -->
                    <a href="hotel_add.php" class="btn btn-sm btn-primary">
                        <i class="fa fa-plus"></i> 添加预订
                    </a>
                </div>
            </div>
            <div class="card-body">
                <!-- 筛选表单 -->
                <form method="GET" class="row g-3 mb-4">
                    <div class="col-md-2">
                        <label for="hotel" class="form-label">酒店名称</label>
                        <select class="form-select" id="hotel" name="hotel">
                            <option value="">全部酒店</option>
                            <?php foreach ($all_hotels as $hotel): ?>
                                <option value="<?php echo htmlspecialchars($hotel); ?>" <?php echo $filter_hotel === $hotel ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($hotel); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="room_type" class="form-label">房型</label>
                        <select class="form-select" id="room_type" name="room_type">
                            <option value="">全部房型</option>
                            <?php foreach ($all_room_types as $room_type): ?>
                                <option value="<?php echo htmlspecialchars($room_type); ?>" <?php echo $filter_room_type === $room_type ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($room_type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="department" class="form-label">部门</label>
                        <select class="form-select" id="department" name="department">
                            <option value="">全部部门</option>
                            <?php foreach ($all_departments as $department): ?>
                                <option value="<?php echo htmlspecialchars($department); ?>" <?php echo $filter_department === $department ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($department); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="check_in_date" class="form-label">入住日期</label>
                        <input type="date" class="form-control" id="check_in_date" name="check_in_date" value="<?php echo htmlspecialchars($filter_check_in_date); ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="check_out_date" class="form-label">退房日期</label>
                        <input type="date" class="form-control" id="check_out_date" name="check_out_date" value="<?php echo htmlspecialchars($filter_check_out_date); ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="btn-group" role="group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-search"></i> 筛选
                            </button>
                            <a href="hotels.php" class="btn btn-outline-secondary">
                                <i class="fa fa-refresh"></i> 重置
                            </a>
                        </div>
                    </div>
                </form>
                
                <!-- 批量操作表单 -->
                <form id="batchDeleteForm" method="POST">
                    <input type="hidden" name="batch_delete" value="1">
                    <?php if (empty($grouped_hotel_reports)): ?>
                        <div class="text-center text-muted py-5">
                            <i class="fa fa-bed fa-3x mb-3"></i>
                            <h5>暂无酒店预订记录</h5>
                            <p class="mb-0">当前筛选条件下没有找到任何酒店预订信息</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($grouped_hotel_reports as $hotel_name => $reports): ?>
                        <div class="card mb-4">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">
                                    <i class="fa fa-building"></i> <?php echo htmlspecialchars($hotel_name); ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover table-striped">
                                        <thead>
                                            <tr>
                                                <th class="w-5 text-center">
                                                    <input type="checkbox" class="form-check-input select-all-hotel" id="selectAll" data-hotel="<?php echo md5($hotel_name); ?>">
                                                </th>
                                                <th>入住人员</th>
                                                <th>部门</th>
                                                <th>入住日期</th>
                                                <th>退房日期</th>
                                                <th>房型</th>
                                                <th>房晚</th>
                                                <th>特殊要求</th>
                                                <th>房间信息</th>
                                                <th>共享房间</th>
                                                <th>操作</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($reports as $report): ?>
                                                <?php 
                                                // 对于合并后的记录，使用第一个ID作为代表ID
                                                $display_id = '';
                                                if (is_array($report['ids']) && count($report['ids']) > 0) {
                                                    $display_id = $report['ids'][0];
                                                } elseif (isset($report['id']) && !empty($report['id'])) {
                                                    $display_id = $report['id'];
                                                }
                                                ?>
                                                <tr class="<?php echo $report['is_shared_room'] ? 'shared-room-indicator' : ''; ?>">
                                                    <td class="text-center">
                                                        <input type="checkbox" name="selected_ids[]" value="<?php echo $display_id; ?>" 
                                                               class="form-check-input report-checkbox" data-hotel="<?php echo md5($hotel_name); ?>">
                                                    </td>
                                                    <td>
                                                        <div class="personnel-info">
                                                            <div class="personnel-names" title="<?php echo htmlspecialchars(is_array($report['personnel_names']) ? implode('、', $report['personnel_names']) : ($report['personnel_names'] ?? '-')); ?>" data-personnel-names="<?php echo htmlspecialchars(is_array($report['personnel_names']) ? implode('、', $report['personnel_names']) : ($report['personnel_names'] ?? '')); ?>">
                                                                <?php echo htmlspecialchars(is_array($report['personnel_names']) ? implode('、', $report['personnel_names']) : ($report['personnel_names'] ?? '-')); ?>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="personnel-info">
                                                            <div class="department-names" title="<?php echo htmlspecialchars(is_array($report['department_names']) ? implode('、', $report['department_names']) : ($report['department_names'] ?? '-')); ?>">
                                                                <?php echo htmlspecialchars(is_array($report['department_names']) ? implode('、', $report['department_names']) : ($report['department_names'] ?? '-')); ?>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="date-range">
                                                            <span class="date-display date-in" title="入住：<?php echo date('Y-m-d', strtotime($report['check_in_date'])); ?>">
                                                                入<?php echo date('m-d', strtotime($report['check_in_date'])); ?>
                                                            </span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="date-range">
                                                            <span class="date-display date-out" title="退房：<?php echo date('Y-m-d', strtotime($report['check_out_date'])); ?>">
                                                                退<?php echo date('m-d', strtotime($report['check_out_date'])); ?>
                                                            </span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span class="room-type-badge <?php 
                                                            switch ($report['room_type']) {
                                                                case '大床房': echo 'badge-dafang'; break;
                                                                case '双床房': echo 'badge-shuangchuang'; break;
                                                                case '套房': echo 'badge-taofang'; break;
                                                                case '副总统套房': echo 'badge-fuzongtong'; break;
                                                                case '总统套房': echo 'badge-zongtong'; break;
                                                                default: echo 'badge-other'; break;
                                                            }
                                                        ?>">
                                                            <?php echo htmlspecialchars($report['room_type']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="room-count">
                                                            <?php 
                                                            // 计算房晚数：房间数 × 入住天数
                                                            $check_in = new DateTime($report['check_in_date']);
                                                            $check_out = new DateTime($report['check_out_date']);
                                                            $nights = $check_in->diff($check_out)->days;
                                                                    
                                                            $room_count = $report['is_shared_room'] ? 1 : $report['room_count'];
                                                            $room_nights = $room_count * $nights;
                                                            echo $room_nights;
                                                            ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="special-requirements" title="<?php echo htmlspecialchars($report['special_requirements'] ?? '-'); ?>">
                                                            <?php echo htmlspecialchars($report['special_requirements'] ?? '-'); ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="room-info">
                                                            <?php if (!empty($report['shared_room_info'])): ?>
                                                                <?php echo htmlspecialchars($report['shared_room_info']); ?>
                                                            <?php else: ?>
                                                                <span class="text-muted">-</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if ($report['is_shared_room']): ?>
                                                            <span class="badge bg-warning text-dark shared-badge">共享</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary shared-badge">独立</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="action-buttons">
                                                        <div class="btn-group btn-group-sm" role="group">
                                                            <button type="button" class="btn btn-sm btn-primary" 
                                                                    data-bs-toggle="modal" 
                                                                    data-bs-target="#editHotelModal" 
                                                                    data-id="<?php echo $display_id; ?>"
                                                                    data-hotel-name="<?php echo htmlspecialchars($report['hotel_name'] ?? ''); ?>"
                                                                    data-personnel-names="<?php echo htmlspecialchars(is_array($report['personnel_names']) ? implode('、', $report['personnel_names']) : ($report['personnel_names'] ?? '')); ?>"
                                                                    data-check-in="<?php echo $report['check_in_date']; ?>"
                                                                    data-check-out="<?php echo $report['check_out_date']; ?>"
                                                                    data-room-type="<?php echo htmlspecialchars($report['room_type']); ?>"
                                                                    data-room-count="<?php echo $report['room_count']; ?>"
                                                                    data-special-requirements="<?php echo htmlspecialchars($report['special_requirements'] ?? ''); ?>"
                                                                    data-shared-room-info="<?php echo htmlspecialchars($report['shared_room_info'] ?? ''); ?>"
                                                                    title="编辑酒店预订">
                                                                <i class="fa fa-edit"></i> 编辑
                                                            </button>
                                                            <a href="?action=delete&id=<?php echo $display_id; ?>&confirm=true" 
                                                               class="btn btn-sm btn-danger" title="删除酒店预订" onclick="return confirm('确定要删除这条酒店预订记录吗？');">
                                                                <i class="fa fa-trash-o"></i> 删除
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    // 显示提示消息的函数
    function showToast(message, type = 'info') {
        // 创建toast元素
        const toastHtml = `
            <div class="toast align-items-center text-white bg-${type} border-0" role="alert" aria-live="assertive" aria-atomic="true" style="position: fixed; top: 20px; right: 20px; z-index: 9999;">
                <div class="d-flex">
                    <div class="toast-body">
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        `;
        
        // 添加到body并显示
        $('body').append(toastHtml);
        const toastElement = $('.toast').last();
        const toast = new bootstrap.Toast(toastElement[0]);
        toast.show();
        
        // 自动移除
        setTimeout(() => {
            toastElement.remove();
        }, 3000);
    }

    // 复选框变化处理（全选和单个选择）
    document.addEventListener('change', function(e) {
        // 全选/取消全选功能
        if (e.target.id === 'selectAll') {
            const isChecked = e.target.checked;
            const checkboxes = document.querySelectorAll('.report-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = isChecked;
            });
            updateBatchDeleteButton();
        }
        // 单个复选框变化时更新批量删除按钮状态
        else if (e.target.classList.contains('report-checkbox')) {
            updateBatchDeleteButton();
        }
    });

    // 更新批量删除按钮状态
    function updateBatchDeleteButton() {
        const checkedBoxes = document.querySelectorAll('.report-checkbox:checked');
        const batchDeleteBtn = document.getElementById('batchDeleteBtn');
        
        if (checkedBoxes.length > 0) {
            batchDeleteBtn.disabled = false;
        } else {
            batchDeleteBtn.disabled = true;
        }
    }

    // 批量删除按钮点击事件
    document.getElementById('batchDeleteBtn').addEventListener('click', function() {
        const checkedBoxes = document.querySelectorAll('.report-checkbox:checked');
        
        if (checkedBoxes.length === 0) {
            showToast('请至少选择一个酒店预订记录', 'warning');
            return;
        }
        
        // 收集所有选中的ID
        const selectedIds = Array.from(checkedBoxes).map(checkbox => checkbox.value);
        
        // 处理共享房间：如果选中的是共享房间记录，需要找出所有关联的记录
        const allIdsToDelete = new Set(selectedIds);
        
        // 检查每个选中的记录是否属于共享房间
        checkedBoxes.forEach(checkbox => {
            const row = checkbox.closest('tr');
            // 检查是否是共享房间记录
            if (row.classList.contains('shared-room-indicator')) {
                // 获取该共享房间的信息
                const sharedRoomInfo = row.querySelector('.room-info').textContent.trim();
                
                // 查找所有具有相同共享房间信息的记录
                const allRows = document.querySelectorAll('tbody tr.shared-room-indicator');
                allRows.forEach(sharedRow => {
                    if (sharedRow.querySelector('.room-info').textContent.trim() === sharedRoomInfo) {
                        const relatedId = sharedRow.querySelector('.report-checkbox').value;
                        allIdsToDelete.add(relatedId);
                    }
                });
            }
        });
        
        const idsArray = Array.from(allIdsToDelete);
        
        // 确保表单中包含所有选中的ID
        // 先移除可能存在的旧的选中ID
        const existingInputs = document.querySelectorAll('#batchDeleteForm input[name="selected_ids[]"]');
        existingInputs.forEach(input => {
            if (!input.closest('tbody')) { // 只移除非表格中的input元素
                input.remove();
            }
        });
        
        // 创建新的隐藏字段用于传递ID列表
        idsArray.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_ids[]';
            input.value = id;
            document.getElementById('batchDeleteForm').appendChild(input);
        });
        
        if (confirm(`确定要删除选中的 ${idsArray.length} 条酒店预订记录吗？`)) {
            document.getElementById('batchDeleteForm').submit();
        }
    });


    // 日期格式化函数
    function formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('zh-CN', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit'
        });
    }

    // 页面加载完成后执行
    document.addEventListener('DOMContentLoaded', function() {
        // 初始化批量删除按钮状态
        updateBatchDeleteButton();
        
        // 检查是否有消息需要显示（除了通过alert显示的）
        const urlParams = new URLSearchParams(window.location.search);
        const message = urlParams.get('message');
        if (message) {
            showToast(decodeURIComponent(message));
            // 从URL中移除消息参数，避免刷新页面时重复显示
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    });

    // 编辑模态框功能
    document.addEventListener('DOMContentLoaded', function() {
        // 监听模态框关闭事件，重置增加同住人区域
        const editModalElement = document.getElementById('editHotelModal');
        if (editModalElement) {
            editModalElement.addEventListener('hidden.bs.modal', function () {
                // 重置增加同住人区域
                const addRoommateSection = document.getElementById('addRoommateSection');
                const addRoommateBtnElement = document.getElementById('addRoommateBtn');
                const newRoommateSelect = document.getElementById('new_roommate_select');
                
                if (addRoommateSection) {
                    addRoommateSection.style.display = 'none';
                }
                if (addRoommateBtnElement) {
                    addRoommateBtnElement.style.display = 'inline-block';
                }
                if (newRoommateSelect) {
                    newRoommateSelect.value = '';
                }
            });
        }
        // 存储当前同住人信息
        let currentRoommates = [];
        
        const editModal = document.getElementById('editHotelModal');
        if (editModal) {
            editModal.addEventListener('show.bs.modal', function(event) {
                const button = event.relatedTarget;
                const id = button.getAttribute('data-id');
                const hotelName = button.getAttribute('data-hotel-name');
                const personnelNames = button.getAttribute('data-personnel-names');
                const checkIn = button.getAttribute('data-check-in');
                const checkOut = button.getAttribute('data-check-out');
                const roomType = button.getAttribute('data-room-type');
                const roomCount = button.getAttribute('data-room-count');
                const specialRequirements = button.getAttribute('data-special-requirements');
                const sharedRoomInfo = button.getAttribute('data-shared-room-info');
                
                // 重置同住人信息
                currentRoommates = [];
                if (personnelNames) {
                    // 使用正则表达式分割人员名称，处理多种分隔符
                    currentRoommates = personnelNames.split(/[、,() ]+/).filter(name => name.trim() !== '');
                }
                
                // 填充表单字段
                document.getElementById('edit_id').value = id || '';
                document.getElementById('edit_check_in_date').value = checkIn || '';
                document.getElementById('edit_check_out_date').value = checkOut || '';
                document.getElementById('edit_room_type').value = roomType || '';
                document.getElementById('edit_room_count').value = roomCount || '1';
                document.getElementById('edit_special_requirements').value = specialRequirements || '';
                document.getElementById('edit_shared_room_info').value = sharedRoomInfo || '';
                
                // 显示入住人员信息
                const personnelDiv = document.getElementById('edit_personnel_names');
                if (personnelDiv && personnelNames) {
                    personnelDiv.innerHTML = `<i class="fa fa-users text-primary me-2"></i>${personnelNames}`;
                } else if (personnelDiv) {
                    personnelDiv.innerHTML = '<span class="text-muted">无入住人员信息</span>';
                }
                
                // 初始化增加同住人按钮的显示状态
                const addRoommateBtn = document.getElementById('addRoommateBtn');
                if (addRoommateBtn) {
                    // 所有房型都可以显示增加同住人按钮
                    addRoommateBtn.style.display = 'inline-block';
                }
                
                // 处理酒店名称选择（默认当前酒店）
                const hotelSelect = document.getElementById('edit_hotel_name');
                if (hotelSelect && hotelName) {
                    // 首先尝试精确匹配
                    let found = false;
                    for (let option of hotelSelect.options) {
                        if (option.value === hotelName) {
                            option.selected = true;
                            found = true;
                            break;
                        }
                    }
                    
                    // 如果精确匹配失败，尝试中文名称包含匹配
                    if (!found) {
                        for (let option of hotelSelect.options) {
                            // 检查option.value是否包含传入的酒店名称的中文部分
                            if (option.value && hotelName && 
                                (option.value.includes(hotelName) || 
                                 hotelName.includes(option.value))) {
                                option.selected = true;
                                found = true;
                                break;
                            }
                        }
                    }
                    
                    // 如果仍未找到，尝试提取中文名称进行匹配
                    if (!found) {
                        // 从复合酒店名称中提取中文部分（格式如："中文名称 English Name"）
                        const chineseNameMatch = hotelName.match(/^([\u4e00-\u9fa5\s]+)/); 
                        if (chineseNameMatch) {
                            const chineseName = chineseNameMatch[1].trim();
                            for (let option of hotelSelect.options) {
                                if (option.value === chineseName || 
                                    option.value.includes(chineseName) || 
                                    chineseName.includes(option.value)) {
                                    option.selected = true;
                                    found = true;
                                    break;
                                }
                            }
                        }
                    }
                    
                    // 如果所有匹配都失败，记录日志并设为第一个可用选项作为默认
                    if (!found) {
                        console.log('酒店名称匹配失败:', hotelName, '可用选项:', Array.from(hotelSelect.options).map(o => o.value));
                        // 设置为第一个非空选项作为默认值
                        for (let option of hotelSelect.options) {
                            if (option.value) {
                                option.selected = true;
                                break;
                            }
                        }
                    }
                    
                    // 更新房型选项
                    updateEditRoomTypes();
                    
                    // 设置房型选择
                    if (roomType) {
                        document.getElementById('edit_room_type').value = roomType;
                    }
                }
                
                // 根据房型控制共享房间信息字段的显示
                toggleSharedRoomInfo(roomType);
                
                // 在显示共享房间信息后，再设置其值
                // 修复：所有房型都应该设置共享房间信息，不仅仅是双床房
                if (sharedRoomInfo) {
                    document.getElementById('edit_shared_room_info').value = sharedRoomInfo;
                }
                
                // 初始化增加同住人按钮的显示状态
                const addRoommateBtnElement = document.getElementById('addRoommateBtn');
                if (addRoommateBtnElement) {
                    // 所有房型都可以显示增加同住人按钮
                    addRoommateBtnElement.style.display = 'inline-block';
                }
                
                // 为房型下拉框添加事件监听器（避免重复添加）
                const roomTypeSelect = document.getElementById('edit_room_type');
                if (roomTypeSelect) {
                    // 移除之前的事件监听器
                    roomTypeSelect.removeEventListener('change', roomTypeChangeHandler);
                    // 添加新的事件监听器
                    roomTypeSelect.addEventListener('change', roomTypeChangeHandler);
                }
                
                // 调试信息：显示所有填充的数据
                console.log('模态框数据填充情况:', {
                    id: id,
                    hotelName: hotelName,
                    personnelNames: personnelNames,
                    checkIn: checkIn,
                    checkOut: checkOut,
                    roomType: roomType,
                    roomCount: roomCount,
                    specialRequirements: specialRequirements,
                    sharedRoomInfo: sharedRoomInfo
                });
            });
        }
        
        // 房型变化处理函数
        function roomTypeChangeHandler() {
            toggleSharedRoomInfo(this.value);
            
            // 同时控制增加同住人按钮的显示 - 所有房型都可以增加同住人
            const addRoommateBtnElement = document.getElementById('addRoommateBtn');
            if (addRoommateBtnElement) {
                // 所有房型都可以显示增加同住人按钮
                addRoommateBtnElement.style.display = 'inline-block';
            }
        }
        
        // 控制共享房间信息字段显示/隐藏的函数
        function toggleSharedRoomInfo(roomType) {
            const sharedRoomRow = document.getElementById('shared_room_row');
            const addRoommateSection = document.getElementById('addRoommateSection');
            const addRoommateBtn = document.getElementById('addRoommateBtn');
            
            if (sharedRoomRow) {
                // 所有房型都显示共享房间信息字段
                sharedRoomRow.style.display = 'block';
                // 显示增加同住人按钮（对所有房型）
                if (addRoommateBtn) {
                    addRoommateBtn.style.display = 'inline-block';
                }
            }
        }
        
        // 表单验证和提交处理
        const editForm = document.getElementById('editHotelForm');
        if (editForm) {
            // 日期验证
            const checkInInput = document.getElementById('edit_check_in_date');
            const checkOutInput = document.getElementById('edit_check_out_date');
            
            function validateDates() {
                const checkInDate = new Date(checkInInput.value);
                const checkOutDate = new Date(checkOutInput.value);
                
                if (checkInInput.value && checkOutInput.value) {
                    if (checkOutDate <= checkInDate) {
                        checkOutInput.setCustomValidity('退房日期必须晚于入住日期');
                        return false;
                    } else {
                        checkOutInput.setCustomValidity('');
                        return true;
                    }
                }
                return true;
            }
            
            checkInInput.addEventListener('change', validateDates);
            checkOutInput.addEventListener('change', validateDates);
            
            // 表单提交处理
            editForm.addEventListener('submit', function(e) {
                // 验证日期
                if (!validateDates()) {
                    e.preventDefault();
                    showToast('请检查日期设置，退房日期必须晚于入住日期', 'error');
                    return;
                }
                
                // 验证必填字段
                const formData = new FormData(editForm);
                const requiredFields = {
                    'id': '记录ID',
                    'hotel_name': '酒店名称',
                    'check_in_date': '入住日期',
                    'check_out_date': '退房日期',
                    'room_type': '房型'
                };
                
                const missingFields = [];
                for (const [fieldName, fieldLabel] of Object.entries(requiredFields)) {
                    const value = formData.get(fieldName);
                    if (!value || value.trim() === '') {
                        missingFields.push(fieldLabel);
                    }
                }
                
                if (missingFields.length > 0) {
                    e.preventDefault();
                    showToast('请填写所有必填字段：' + missingFields.join('、'), 'error');
                    console.log('缺少字段:', missingFields);
                    console.log('表单数据:', Object.fromEntries(formData));
                    return;
                }
                
                // 显示提交确认
                if (!confirm('确定要保存对该酒店预订的修改吗？')) {
                    e.preventDefault();
                    return;
                }
                
                // 显示加载状态
                const saveBtn = document.getElementById('saveEditBtn');
                if (saveBtn) {
                    saveBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> 保存中...';
                    saveBtn.disabled = true;
                }
            });
        }
        
        // 编辑模态框中酒店选择变化时更新房型选项
        function updateEditRoomTypes() {
            const selectedHotel = document.getElementById('edit_hotel_name').selectedOptions[0];
            const roomTypeSelect = document.getElementById('edit_room_type');
            
            // 清空房型选项
            roomTypeSelect.innerHTML = '<option value="">请选择房型</option>';
            
            if (!selectedHotel || !selectedHotel.value) {
                return;
            }
            
            // 从data属性获取房型数据
            const roomTypesJson = selectedHotel.dataset.roomTypes;
            
            if (roomTypesJson) {
                try {
                    let roomTypes = [];
                    // 尝试解析JSON
                    if (roomTypesJson.startsWith('[') && roomTypesJson.endsWith(']')) {
                        roomTypes = JSON.parse(roomTypesJson);
                    } else {
                        // 处理非标准格式（逗号分隔的字符串）
                        roomTypes = roomTypesJson.split(',').map(item => item.trim()).filter(item => item !== '');
                    }
                    
                    // 只显示酒店实际拥有的房型
                    roomTypes.forEach(type => {
                        if (type.trim() !== '') {
                            const option = document.createElement('option');
                            option.value = type;
                            option.textContent = type;
                            roomTypeSelect.appendChild(option);
                        }
                    });
                    
                } catch (e) {
                    console.error('解析房型数据失败:', e);
                    const option = document.createElement('option');
                    option.value = '';
                    option.textContent = '房型数据格式错误';
                    roomTypeSelect.appendChild(option);
                }
            } else {
                // 如果没有房型数据，显示提示
                const option = document.createElement('option');
                option.value = '';
                option.textContent = '该酒店暂无房型信息';
                roomTypeSelect.appendChild(option);
            }
        }
    });
    </script>

<script>
// 增加同住人功能
document.addEventListener('DOMContentLoaded', function() {
    // 显示增加同住人区域
    document.getElementById('addRoommateBtn').addEventListener('click', function() {
        document.getElementById('addRoommateSection').style.display = 'block';
        this.style.display = 'none';
    });
    
    // 取消增加同住人
    document.getElementById('cancelAddRoommateBtn').addEventListener('click', function() {
        document.getElementById('addRoommateSection').style.display = 'none';
        document.getElementById('addRoommateBtn').style.display = 'inline-block';
        document.getElementById('new_roommate_select').value = '';
    });
    
    // 确认增加同住人
    document.getElementById('confirmAddRoommateBtn').addEventListener('click', function() {
        const newRoommateId = document.getElementById('new_roommate_select').value;
        const newRoommateText = document.getElementById('new_roommate_select').selectedOptions[0]?.text;
        
        if (!newRoommateId) {
            showToast('请选择同住人员', 'warning');
            return;
        }
        
        // 获取当前编辑的记录ID
        const recordId = document.getElementById('edit_id').value;
        const sharedRoomInfo = document.getElementById('edit_shared_room_info').value;
        
        if (!recordId) {
            showToast('无法获取当前记录信息', 'error');
            return;
        }
        
        // 发送AJAX请求添加同住人
        fetch('add_roommate.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `record_id=${encodeURIComponent(recordId)}&roommate_id=${encodeURIComponent(newRoommateId)}&shared_room_info=${encodeURIComponent(sharedRoomInfo)}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('同住人添加成功', 'success');
                // 更新共享房间信息而不重新加载页面
                if (data.new_shared_room_info) {
                    document.getElementById('edit_shared_room_info').value = data.new_shared_room_info;
                }
                // 更新入住人员信息显示
                const personnelDiv = document.getElementById('edit_personnel_names');
                if (personnelDiv) {
                    // 使用正则表达式分割人员名称，处理多种分隔符
                    let currentText = personnelDiv.textContent || personnelDiv.innerText;
                    // 移除图标文本
                    currentText = currentText.replace(/^[^0-9a-zA-Z\u4e00-\u9fa5]+/, '');
                    
                    // 使用正则表达式分割人员名称，处理多种分隔符
                    const currentNames = currentText.split(/[、,() ]+/).filter(name => name.trim() !== '');
                    if (data.new_person_name) {
                        // 检查是否已经包含该人员，避免重复添加
                        const personExists = currentNames.some(name => name === data.new_person_name);
                        if (!personExists) {
                            currentNames.push(data.new_person_name);
                        }
                        // 去重处理，确保没有重复的人员姓名
                        const uniqueNames = [...new Set(currentNames)];
                        // 重新构建显示文本
                        personnelDiv.innerHTML = `<i class="fa fa-users text-primary me-2"></i>${uniqueNames.join('、')}`;
                    }
                }
                // 隐藏添加同住人区域
                document.getElementById('addRoommateSection').style.display = 'none';
                document.getElementById('addRoommateBtn').style.display = 'inline-block';
                document.getElementById('new_roommate_select').value = '';
                
                // 重要：更新下拉框中的选项，移除已添加的人员
                const selectElement = document.getElementById('new_roommate_select');
                const selectedOption = selectElement.querySelector(`option[value="${newRoommateId}"]`);
                if (selectedOption) {
                    selectedOption.remove();
                }
            } else {
                showToast('添加失败: ' + data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showToast('添加同住人时发生错误', 'error');
        });
    });
});
</script>

<!-- 编辑酒店预订模态框 -->
<div class="modal fade" id="editHotelModal" tabindex="-1" aria-labelledby="editHotelModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editHotelModalLabel">
                    <i class="fa fa-edit"></i> 编辑酒店预订
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
            </div>
            <form method="POST" action="hotels.php" id="editHotelForm">
                <div class="modal-body">
                    <input type="hidden" id="edit_id" name="id" value="">
                    <input type="hidden" name="action" value="edit">
                    
                    <!-- 入住人员信息显示 -->
                    <div class="row">
                        <div class="col-md-12">
                            <div class="mb-3">
                                <label class="form-label">入住人员</label>
                                <div class="form-control-plaintext border rounded p-2 bg-light" id="edit_personnel_names" style="min-height: 38px;">
                                    <!-- 入住人员名单将在这里显示 -->
                                </div>
                                <!-- 增加同住人按钮和选择区域 -->
                                <div class="mt-2">
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="addRoommateBtn">
                                        <i class="fa fa-user-plus"></i> 增加同住人
                                    </button>
                                    <div id="addRoommateSection" class="mt-2" style="display: none;">
                                        <div class="input-group">
                                            <select class="form-select" id="new_roommate_select">
                                                <option value="">请选择同住人员</option>
                                                <?php foreach ($personnel as $person): ?>
                                                    <option value="<?php echo $person['id']; ?>">
                                                        <?php echo htmlspecialchars($person['name']); ?>
                                                        <?php if (!empty($person['departments'])): ?>
                                                            (<?php echo htmlspecialchars($person['departments']); ?>)
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="btn btn-success" id="confirmAddRoommateBtn">
                                                <i class="fa fa-check"></i> 添加
                                            </button>
                                            <button type="button" class="btn btn-secondary" id="cancelAddRoommateBtn">
                                                <i class="fa fa-times"></i> 取消
                                            </button>
                                        </div>
                                        <small class="form-text text-muted">注意：添加同住人将创建新的酒店预订记录并更新共享房间信息</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="mb-3">
                                <label for="edit_hotel_name" class="form-label">酒店名称 <span class="text-danger">*</span></label>
                                <select class="form-select" id="edit_hotel_name" name="hotel_name" required onchange="updateEditRoomTypes()">
                                    <option value="">请选择酒店</option>
                                    <?php foreach ($available_hotels as $hotel): ?>
                                        <option value="<?php echo htmlspecialchars($hotel['hotel_name_cn']); ?>" 
                                                data-room-types='<?php echo htmlspecialchars($hotel['room_types']); ?>'>
                                            <?php echo htmlspecialchars($hotel['hotel_name_cn']); ?>
                                            <?php if (!empty($hotel['hotel_name_en'])): ?>
                                                (<?php echo htmlspecialchars($hotel['hotel_name_en']); ?>)
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">
                                    请选择酒店
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_check_in_date" class="form-label">入住日期 <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="edit_check_in_date" name="check_in_date" required>
                                <div class="invalid-feedback">
                                    请选择入住日期
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_check_out_date" class="form-label">退房日期 <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="edit_check_out_date" name="check_out_date" required>
                                <div class="invalid-feedback">
                                    请选择退房日期
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_room_type" class="form-label">房型 <span class="text-danger">*</span></label>
                                <select class="form-select" id="edit_room_type" name="room_type" required>
                                    <option value="">请先选择酒店</option>
                                </select>
                                <div class="invalid-feedback">
                                    请选择房型
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_room_count" class="form-label">房间数量</label>
                                <input type="number" class="form-control" id="edit_room_count" name="room_count" min="1" max="100" value="1" readonly>
                                <small class="form-text text-muted">房间数量不可修改</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="mb-3">
                                <label for="edit_special_requirements" class="form-label">特殊要求</label>
                                <textarea class="form-control" id="edit_special_requirements" name="special_requirements" rows="3" placeholder="如：需要无烟房、高层房间、相邻房间等"></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 共享房间信息 -->
                    <div class="row" id="shared_room_row" style="display: none;">
                        <div class="col-md-12">
                            <div class="mb-3">
                                <label for="edit_shared_room_info" class="form-label">共享房间信息</label>
                                <input type="text" class="form-control" id="edit_shared_room_info" name="shared_room_info" placeholder="如：双床房共享、与某某同房等">
                                <small class="form-text text-muted">共享房间的相关信息，每个房间最多2人</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fa fa-times"></i> 取消
                    </button>
                    <button type="submit" class="btn btn-primary" id="saveEditBtn">
                        <i class="fa fa-save"></i> 保存修改
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
