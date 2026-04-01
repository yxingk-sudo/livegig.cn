<?php
// 通用函数文件

function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

function generateProjectCode($companyName, $projectName) {
    $companyPrefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $companyName), 0, 3));
    $projectPrefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $projectName), 0, 3));
    $timestamp = date('YmdHis');
    return $companyPrefix . $projectPrefix . $timestamp;
}

function generateRandomPassword($length = 8) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[rand(0, strlen($chars) - 1)];
    }
    return $password;
}

function checkProjectAccess($projectCode, $username, $password, $db) {
    $query = "SELECT pu.*, p.name as project_name, p.id as project_id 
              FROM project_users pu 
              JOIN projects p ON pu.project_id = p.id 
              WHERE p.code = :code AND pu.username = :username AND pu.is_active = 1";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':code', $projectCode);
    $stmt->bindParam(':username', $username);
    $stmt->execute();
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($password, $user['password'])) {
        return $user;
    }
    
    return false;
}

function getProjectDepartments($projectId, $db) {
    $query = "SELECT * FROM departments WHERE project_id = :project_id ORDER BY name";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':project_id', $projectId);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getDepartmentPersonnel($projectId, $departmentId, $db) {
    $query = "SELECT p.*, pdp.position 
              FROM personnel p 
              JOIN project_department_personnel pdp ON p.id = pdp.personnel_id 
              WHERE pdp.project_id = :project_id AND pdp.department_id = :department_id 
              AND pdp.status = 'active' ORDER BY p.name";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':project_id', $projectId);
    $stmt->bindParam(':department_id', $departmentId);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getProjectPersonnel($projectId, $db) {
    $query = "SELECT DISTINCT p.*, 
              GROUP_CONCAT(DISTINCT d.id ORDER BY d.id SEPARATOR ',') as department_ids,
              GROUP_CONCAT(DISTINCT d.name SEPARATOR ', ') as departments, 
              GROUP_CONCAT(DISTINCT pdp.position SEPARATOR ', ') as positions 
              FROM personnel p 
              JOIN project_department_personnel pdp ON p.id = pdp.personnel_id 
              JOIN departments d ON pdp.department_id = d.id 
              WHERE pdp.project_id = :project_id AND pdp.status = 'active' 
              GROUP BY p.id ORDER BY p.name";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':project_id', $projectId);
    $stmt->execute();
    $personnel = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 处理部门ID，取第一个部门ID作为主要部门
    foreach ($personnel as &$person) {
        $dept_ids = explode(',', $person['department_ids']);
        $person['department_id'] = $dept_ids[0] ?? '';
    }
    
    return $personnel;
}

function getProjectDetails($projectId, $db) {
    $query = "SELECT * FROM projects WHERE id = :project_id";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':project_id', $projectId);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getMealReports($projectId, $db, $date = null) {
    $query = "SELECT mr.*, p.name as personnel_name, pu.display_name as reported_by_name 
              FROM meal_reports mr 
              JOIN personnel p ON mr.personnel_id = p.id 
              JOIN project_users pu ON mr.reported_by = pu.id 
              WHERE mr.project_id = :project_id";
    
    if ($date) {
        $query .= " AND mr.meal_date = :date";
    }
    
    $query .= " ORDER BY mr.meal_date DESC, mr.created_at DESC";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':project_id', $projectId);
    if ($date) {
        $stmt->bindParam(':date', $date);
    }
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getHotelReports($projectId, $db) {
    // 先获取所有记录，然后在PHP中处理合并逻辑
    $query = "SELECT hr.*, p.name as personnel_name, pu.display_name as reported_by_name 
              FROM hotel_reports hr 
              JOIN personnel p ON hr.personnel_id = p.id 
              JOIN project_users pu ON hr.reported_by = pu.id 
              WHERE hr.project_id = :project_id 
              ORDER BY hr.check_in_date DESC, hr.created_at DESC";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':project_id', $projectId);
    $stmt->execute();
    $all_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 处理合并逻辑：仅合并2个人的双床房
    $processed_records = [];
    $grouped_records = [];
    
    // 先按共享房间信息分组
    foreach ($all_records as $record) {
        if ($record['room_type'] === '双床房' && !empty($record['shared_room_info'])) {
            $key = $record['hotel_name'] . '|' . $record['check_in_date'] . '|' . 
                   $record['check_out_date'] . '|' . $record['room_type'] . '|' . 
                   $record['shared_room_info'];
            $grouped_records[$key][] = $record;
        } else {
            // 非双床房或无双床房共享信息的记录单独处理
            $processed_records[] = [
                'id' => $record['id'],
                'hotel_name' => $record['hotel_name'],
                'check_in_date' => $record['check_in_date'],
                'check_out_date' => $record['check_out_date'],
                'room_type' => $record['room_type'],
                'room_count' => $record['room_count'],
                'shared_room_info' => $record['shared_room_info'],
                'special_requirements' => $record['special_requirements'],
                'status' => $record['status'],
                'created_at' => $record['created_at'],
                'reported_by' => $record['reported_by'],
                'personnel_names' => $record['personnel_name'],
                'personnel_count' => 1,
                'reported_by_name' => $record['reported_by_name']
            ];
        }
    }
    
    // 处理双床房分组，仅合并2个人的
    foreach ($grouped_records as $group) {
        if (count($group) === 2) {
            // 合并2个人的双床房
            $names = array_column($group, 'personnel_name');
            sort($names);
            $processed_records[] = [
                'id' => min(array_column($group, 'id')), // 使用最小ID作为代表
                'hotel_name' => $group[0]['hotel_name'],
                'check_in_date' => $group[0]['check_in_date'],
                'check_out_date' => $group[0]['check_out_date'],
                'room_type' => $group[0]['room_type'],
                'room_count' => $group[0]['room_count'],
                'shared_room_info' => $group[0]['shared_room_info'],
                'special_requirements' => $group[0]['special_requirements'],
                'status' => $group[0]['status'],
                'created_at' => $group[0]['created_at'],
                'reported_by' => $group[0]['reported_by'],
                'personnel_names' => implode(', ', $names),
                'personnel_count' => 2,
                'reported_by_name' => $group[0]['reported_by_name']
            ];
        } else {
            // 不是2个人的，保持单独显示
            foreach ($group as $record) {
                $processed_records[] = [
                    'id' => $record['id'],
                    'hotel_name' => $record['hotel_name'],
                    'check_in_date' => $record['check_in_date'],
                    'check_out_date' => $record['check_out_date'],
                    'room_type' => $record['room_type'],
                    'room_count' => $record['room_count'],
                    'shared_room_info' => $record['shared_room_info'],
                    'special_requirements' => $record['special_requirements'],
                    'status' => $record['status'],
                    'created_at' => $record['created_at'],
                    'reported_by' => $record['reported_by'],
                    'personnel_names' => $record['personnel_name'],
                    'personnel_count' => 1,
                    'reported_by_name' => $record['reported_by_name']
                ];
            }
        }
    }
    
    // 按日期排序
    usort($processed_records, function($a, $b) {
        $date_cmp = strcmp($b['check_in_date'], $a['check_in_date']);
        if ($date_cmp === 0) {
            return strcmp($b['created_at'], $a['created_at']);
        }
        return $date_cmp;
    });
    
    return $processed_records;
}

function getTransportationReports($projectId, $db) {
    $query = "SELECT tr.*, p.name as personnel_name, pu.display_name as reported_by_name 
              FROM transportation_reports tr 
              JOIN personnel p ON tr.personnel_id = p.id 
              JOIN project_users pu ON tr.reported_by = pu.id 
              WHERE tr.project_id = :project_id 
              ORDER BY tr.travel_date DESC, tr.created_at DESC";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':project_id', $projectId);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getProjectVehicles($projectId, $db) {
    $query = "SELECT f.id, f.fleet_number, f.license_plate, f.vehicle_model, f.vehicle_type, 
                     f.driver_name, f.driver_phone, f.seats as capacity, f.status,
                     CASE 
                         WHEN f.vehicle_type = 'car' THEN '轿车'
                         WHEN f.vehicle_type = 'van' THEN '商务车'
                         WHEN f.vehicle_type = 'bus' THEN '大巴'
                         WHEN f.vehicle_type = 'truck' THEN '货车'
                         ELSE f.vehicle_type 
                     END as type_name
              FROM fleet f
              WHERE f.project_id = :project_id
              ORDER BY f.fleet_number ASC";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':project_id', $projectId);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function formatDate($date) {
    return date('Y-m-d', strtotime($date));
}

function formatDateTime($datetime) {
    return date('Y-m-d H:i', strtotime($datetime));
}

function showAlert($message, $type = 'success') {
    return "<div class='alert alert-{$type} alert-dismissible fade show' role='alert'>
            {$message}
            <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
        </div>";
}

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }
}

function checkProjectPermission($projectId) {
    if (!isset($_SESSION['project_id']) || $_SESSION['project_id'] != $projectId) {
        header("Location: login.php");
        exit;
    }
}
?>