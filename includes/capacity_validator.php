<?php
/**
 * 车辆容量验证器
 * 用于检查同时间同出发地和目的地同一辆车的座位数限制
 */

class CapacityValidator {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * 检查是否可以添加人员到指定行程
     * @param array $travel_info 行程信息数组
     * @param int $new_passenger_count 新增乘客数量
     * @param int $vehicle_id 车辆ID (可选)
     * @return array ['success' => bool, 'message' => string]
     */
    public function checkCapacity($travel_info, $new_passenger_count = 1, $vehicle_id = null) {
        try {
            // 获取行程基本信息
            $travel_date = $travel_info['travel_date'];
            $travel_type = $travel_info['travel_type'];
            $departure_location = $travel_info['departure_location'];
            $destination_location = $travel_info['destination_location'];
            $departure_time = $travel_info['departure_time'] ?? null;
            $arrival_time = $travel_info['arrival_time'] ?? null;
            $project_id = $travel_info['project_id'] ?? null;
            
            // 如果没有指定车辆，获取已分配的车辆
            if (!$vehicle_id) {
                $vehicle_id = $this->getAssignedVehicle($travel_date, $travel_type, $departure_location, $destination_location, $departure_time, $arrival_time, $project_id);
            }
            
            // 如果没有分配车辆，返回允许添加
            if (!$vehicle_id) {
                return [
                    'success' => true,
                    'message' => '未分配车辆，可以添加人员'
                ];
            }
            
            // 获取车辆座位数
            $max_capacity = $this->getVehicleCapacity($vehicle_id);
            
            // 获取当前车辆上的乘客数量
            $current_count = $this->getCurrentPassengerCount($travel_date, $travel_type, $departure_location, $destination_location, $departure_time, $arrival_time, $project_id, $vehicle_id);
            
            if ($max_capacity <= 0) {
                return [
                    'success' => true,
                    'message' => '车辆信息不完整，允许添加'
                ];
            }
            
            // 计算添加后的总人数
            $total_after_add = $current_count + $new_passenger_count;
            
            // 检查是否超过容量
            if ($total_after_add > $max_capacity) {
                $remaining_seats = max(0, $max_capacity - $current_count);
                return [
                    'success' => false,
                    'message' => "❌ 超员警告：当前已登记{$current_count}人，尝试新增{$new_passenger_count}人，但车辆最大容量为{$max_capacity}座，仅剩{$remaining_seats}个空位。请减少人数或更换更大车辆。",
                    'error_type' => 'capacity_exceeded',
                    'current_count' => $current_count,
                    'max_capacity' => $max_capacity,
                    'remaining_seats' => $remaining_seats
                ];
            }
            
            return [
                'success' => true,
                'message' => "可以添加：当前{$current_count}人，添加后共{$total_after_add}人，车辆容量{$max_capacity}座"
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => '验证失败：' . $e->getMessage()
            ];
        }
    }
    
    /**
     * 获取当前乘客数量（按车辆统计）
     */
    private function getCurrentPassengerCount($travel_date, $travel_type, $departure_location, $destination_location, $departure_time, $arrival_time, $project_id, $vehicle_id = null) {
        if (!$vehicle_id) {
            return 0; // 如果没有车辆，返回0
        }
        
        $query = "
            SELECT COALESCE(SUM(tr.passenger_count), 0) as total_passengers
            FROM transportation_reports tr
            JOIN transportation_fleet_assignments tfa ON tr.id = tfa.transportation_report_id
            WHERE tr.travel_date = :travel_date
            AND tr.travel_type = :travel_type
            AND tr.departure_location = :departure_location
            AND tr.destination_location = :destination_location
            AND tr.project_id = :project_id
            AND tr.status != 'cancelled'
            AND tfa.fleet_id = :vehicle_id
        ";
        
        // 参数数组
        $params = [
            ':travel_date' => $travel_date,
            ':travel_type' => $travel_type,
            ':departure_location' => $departure_location,
            ':destination_location' => $destination_location,
            ':project_id' => $project_id,
            ':vehicle_id' => $vehicle_id
        ];
        
        // 处理出发时间条件
        if (!empty($departure_time) && $departure_time !== '00:00:00') {
            $query .= " AND (tr.departure_time = :departure_time OR tr.departure_time IS NULL OR tr.departure_time = '')";
            $params[':departure_time'] = $departure_time;
        } else {
            $query .= " AND (tr.departure_time IS NULL OR tr.departure_time = '' OR tr.departure_time = '00:00:00')";
        }
        
        // 处理到达时间条件（可选）
        if (!empty($arrival_time) && $arrival_time !== '00:00:00') {
            $query .= " AND (tr.arrival_time = :arrival_time OR tr.arrival_time IS NULL OR tr.arrival_time = '')";
            $params[':arrival_time'] = $arrival_time;
        }
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * 获取已分配的车辆ID（基于行程条件）
     */
    private function getAssignedVehicle($travel_date, $travel_type, $departure_location, $destination_location, $departure_time, $arrival_time, $project_id) {
        $query = "
            SELECT DISTINCT f.id
            FROM transportation_reports tr
            JOIN transportation_fleet_assignments tfa ON tr.id = tfa.transportation_report_id
            JOIN fleet f ON tfa.fleet_id = f.id
            WHERE tr.travel_date = :travel_date
            AND tr.travel_type = :travel_type
            AND tr.departure_location = :departure_location
            AND tr.destination_location = :destination_location
            AND tr.project_id = :project_id
            AND tr.status != 'cancelled'
        ";
        
        $params = [
            ':travel_date' => $travel_date,
            ':travel_type' => $travel_type,
            ':departure_location' => $departure_location,
            ':destination_location' => $destination_location,
            ':project_id' => $project_id
        ];
        
        // 处理出发时间条件
        if (!empty($departure_time) && $departure_time !== '00:00:00') {
            $query .= " AND (tr.departure_time = :departure_time OR tr.departure_time IS NULL OR tr.departure_time = '' OR tr.departure_time = '00:00:00')";
            $params[':departure_time'] = $departure_time;
        } else {
            $query .= " AND (tr.departure_time IS NULL OR tr.departure_time = '' OR tr.departure_time = '00:00:00')";
        }
        
        // 处理到达时间条件（可选）
        if (!empty($arrival_time) && $arrival_time !== '00:00:00') {
            $query .= " AND (tr.arrival_time = :arrival_time OR tr.arrival_time IS NULL OR tr.arrival_time = '' OR tr.arrival_time = '00:00:00')";
            $params[':arrival_time'] = $arrival_time;
        }
        
        $query .= " LIMIT 1";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
    
    /**
     * 获取已分配的车辆ID（基于运输记录ID）
     */
    public function getAssignedVehicleByTransportId($transport_id) {
        $query = "
            SELECT f.id
            FROM transportation_reports tr
            JOIN transportation_fleet_assignments tfa ON tr.id = tfa.transportation_report_id
            JOIN fleet f ON tfa.fleet_id = f.id
            WHERE tr.id = :transport_id
            LIMIT 1
        ";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([':transport_id' => $transport_id]);
        return $stmt->fetchColumn();
    }
    
    /**
     * 获取车辆座位数
     */
    private function getVehicleCapacity($vehicle_id) {
        $query = "SELECT seats FROM fleet WHERE id = :vehicle_id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':vehicle_id' => $vehicle_id]);
        return (int)$stmt->fetchColumn();
    }
}
?>