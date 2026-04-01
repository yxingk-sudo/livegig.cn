<?php

session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();
checkProjectPermission($_SESSION['project_id']);

$projectId = $_SESSION['project_id'];

// 获取项目人员和车辆
$personnel = getProjectPersonnel($projectId, $db);

// 获取项目部门信息
$departments = [];
try {
    $dept_query = "
        SELECT d.id, d.name 
        FROM departments d
        JOIN project_department_personnel pdp ON d.id = pdp.department_id
        WHERE pdp.project_id = :project_id
        GROUP BY d.id, d.name
        ORDER BY d.name ASC
    ";
    $dept_stmt = $db->prepare($dept_query);
    $dept_stmt->execute([':project_id' => $projectId]);
    $departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("获取部门信息失败: " . $e->getMessage());
}

// 获取地点列表
$locations = [];
$transport_locations = []; // 机场和高铁站
$venue_locations = []; // 项目场地和酒店

if ($db && $projectId) {
    try {
        // 获取项目关联的场地位置
        $query = "SELECT DISTINCT location FROM projects WHERE id = :project_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':project_id', $projectId);
        $stmt->execute();
        $project_venue_locations = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // 获取项目关联的酒店名称 - 支持多酒店功能
        // 检查project_hotels表是否存在
        $check_table_query = "SHOW TABLES LIKE 'project_hotels'";
        $table_exists = $db->query($check_table_query)->rowCount() > 0;
        
        if ($table_exists) {
            // 使用新的多酒店关联模式
            $query = "SELECT DISTINCT h.hotel_name_cn 
                     FROM hotels h 
                     JOIN project_hotels ph ON h.id = ph.hotel_id 
                     WHERE ph.project_id = :project_id 
                     ORDER BY h.hotel_name_cn";
        } else {
            // 使用旧的项目单酒店模式（向后兼容）
            $query = "SELECT h.hotel_name_cn FROM projects p 
                     JOIN hotels h ON p.hotel_id = h.id 
                     WHERE p.id = :project_id";
        }
        $stmt = $db->prepare($query);
        $stmt->bindParam(':project_id', $projectId);
        $stmt->execute();
        $project_hotels = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // 获取项目信息中的机场和高铁站
        $query = "SELECT arrival_airport, arrival_railway_station, departure_airport, departure_railway_station 
                 FROM projects WHERE id = :project_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':project_id', $projectId);
        $stmt->execute();
        $project_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 构建交通地点（机场和高铁站）
        $transport_locations = [];
        if (!empty($project_info['arrival_airport']) && $project_info['arrival_airport'] !== '未设置') {
            $transport_locations[] = $project_info['arrival_airport'] . '（机场）';
        }
        if (!empty($project_info['arrival_railway_station']) && $project_info['arrival_railway_station'] !== '未设置') {
            $transport_locations[] = $project_info['arrival_railway_station'] . '（高铁站）';
        }
        if (!empty($project_info['departure_airport']) && $project_info['departure_airport'] !== '未设置') {
            $transport_locations[] = $project_info['departure_airport'] . '（机场）';
        }
        if (!empty($project_info['departure_railway_station']) && $project_info['departure_railway_station'] !== '未设置') {
            $transport_locations[] = $project_info['departure_railway_station'] . '（高铁站）';
        }
        
        // 构建场地地点（项目场地和酒店）
        $venue_locations = [];
        foreach ($project_venue_locations as $location) {
            if (!empty($location)) {
                $venue_locations[] = $location;
            }
        }
        
        foreach ($project_hotels as $hotel_name) {
            if (!empty($hotel_name)) {
                $venue_locations[] = $hotel_name;
            }
        }
        
        // 合并所有地点用于默认显示
        $locations = array_unique(array_merge($transport_locations, $venue_locations));
        sort($locations);
        
    } catch (PDOException $e) {
        // 错误处理
    }
}

// 处理快速创建请求
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['quick_create'])) {
    $travel_date = $_POST['travel_date'] ?? '';
    $departure_time = $_POST['departure_time'] ?? '';
    $travel_type = $_POST['travel_type'] ?? '点对点';
    $special_requirements = $_POST['special_requirements'] ?? '';
    
    // 根据交通类型获取地点值
    if ($travel_type === '混合交通安排（自定义）') {
        $departure_location = $_POST['departure_location_custom'] ?? '';
        $destination_location = $_POST['destination_location_custom'] ?? '';
    } else {
        $departure_location = $_POST['departure_location'] ?? '';
        $destination_location = $_POST['destination_location'] ?? '';
    }
    
    $personnel_ids = $_POST['personnel_ids'] ?? [];
    
    // 处理车型需求数据
    $vehicle_requirements = [];
    if (isset($_POST['vehicle_requirements']) && is_array($_POST['vehicle_requirements'])) {
        foreach ($_POST['vehicle_requirements'] as $type => $data) {
            if (isset($data['type']) && !empty($data['quantity'])) {
                $vehicle_requirements[$type] = [
                    'type' => $data['type'],
                    'quantity' => max(1, (int)$data['quantity'])
                ];
            }
        }
    }
    $vehicle_requirements_json = !empty($vehicle_requirements) ? json_encode($vehicle_requirements) : null;
    
    if (empty($travel_date) || empty($departure_location) || empty($destination_location) || empty($personnel_ids)) {
        $_SESSION['message'] = '请填写所有必填项并选择乘客！';
        $_SESSION['message_type'] = 'warning';
    } else {
        try {
            $db->beginTransaction();
            
            // 创建行程记录
            $insert_query = "
                INSERT INTO transportation_reports 
                (project_id, travel_date, travel_type, departure_time, departure_location, destination_location, personnel_id, passenger_count, vehicle_requirements, special_requirements, reported_by, created_at)
                VALUES (:project_id, :travel_date, :travel_type, :departure_time, :departure_location, :destination_location, :personnel_id, :passenger_count, :vehicle_requirements, :special_requirements, :reported_by, NOW())
            ";
            
            $stmt = $db->prepare($insert_query);
            
            // 使用第一个选择的人员作为主负责人
            $main_personnel_id = $personnel_ids[0];
            $passenger_count = count($personnel_ids);
            
            $stmt->execute([
                ':project_id' => $projectId,
                ':travel_date' => $travel_date,
                ':travel_type' => $travel_type,
                ':departure_time' => $departure_time,
                ':departure_location' => $departure_location,
                ':destination_location' => $destination_location,
                ':personnel_id' => $main_personnel_id,
                ':passenger_count' => $passenger_count,
                ':vehicle_requirements' => $vehicle_requirements_json,
                ':special_requirements' => $special_requirements,
                ':reported_by' => $_SESSION['user_id'] ?? 1 // 默认使用当前登录用户ID
            ]);
            
            $transport_id = $db->lastInsertId();
            
            // 创建乘客关联记录
            if (count($personnel_ids) > 1) {
                $passenger_query = "INSERT INTO transportation_passengers (transportation_report_id, personnel_id) VALUES (?, ?)";
                $passenger_stmt = $db->prepare($passenger_query);
                
                foreach ($personnel_ids as $person_id) {
                    $passenger_stmt->execute([$transport_id, $person_id]);
                }
            }
            
            $db->commit();
            
            $_SESSION['message'] = '快速行程创建成功！';
            $_SESSION['message_type'] = 'success';
            
            header('Location: transport_list.php');
            exit;
            
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['message'] = '创建失败：' . $e->getMessage();
            $_SESSION['message_type'] = 'danger';
        }
    }
}

$active_page = 'quick_transport';
include 'includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <h2><i class="bi bi-lightning-fill text-warning"></i> 快速行程安排</h2>
            <p class="text-muted">三步完成行程创建：选择时间 → 选择地点 → 选择人员</p>
        </div>
    </div>

    <?php if (isset($_SESSION['message'])): ?>
    <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" role="alert">
        <?php echo $_SESSION['message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['message']); unset($_SESSION['message_type']); endif; ?>

    <form method="POST" action="quick_transport.php" id="quickTransportForm">
        <div class="row">
            <!-- 左侧：时间选择 -->
            <div class="col-md-3">
                <div class="card h-100">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0"><i class="bi bi-clock"></i> 时间设置</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">出行日期 *</label>
                            <input type="date" class="form-control" name="travel_date" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label" id="time_label">出发时间 *</label>
                            <input type="time" class="form-control" id="departure_time_input" name="departure_time" required>
                        </div>

                        <!-- 车型需求功能开始 -->
                        <div class="mb-3">
                            <label class="form-label">车型需求 <small class="text-muted">可多选，可设置数量</small></label>
                            <div class="border rounded p-2">
                                <div class="d-flex flex-wrap gap-3">
                                    <?php
                                    // 车型需求功能 - 根据transport_enhanced.php的车型类型
                                    $vehicle_types = [
                                        'car' => '轿车',
                                        'van' => '商务车',
                                        'minibus' => '中巴车',
                                        'bus' => '大巴车',
                                        'truck' => '货车',
                                        'other' => '其他'
                                    ];
                                    foreach ($vehicle_types as $key => $label): 
                                    ?>
                                    <div class="d-flex align-items-center">
                                        <div class="form-check mb-0 d-flex align-items-center">
                                            <input class="form-check-input vehicle-type-checkbox" type="checkbox" 
                                                   name="vehicle_requirements[<?php echo $key; ?>][type]" 
                                                   value="<?php echo $key; ?>" 
                                                   id="vehicle_req_<?php echo $key; ?>"
                                                   onchange="toggleVehicleQuantity(this, '<?php echo $key; ?>')">
                                            <label class="form-check-label small me-1 ms-1" for="vehicle_req_<?php echo $key; ?>">
                                                <?php echo $label; ?>
                                            </label>
                                            <input type="number" 
                                                   name="vehicle_requirements[<?php echo $key; ?>][quantity]" 
                                                   id="vehicle_quantity_<?php echo $key; ?>"
                                                   class="form-control form-control-sm" 
                                                   style="width: 45px; display: none; padding: 0.2rem 0.4rem; font-size: 0.75rem;" 
                                                   min="1" max="10" 
                                                   value="1"
                                                   placeholder="数量">
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <!-- 车型需求功能结束 -->
                    </div>
                </div>
            </div>

            <!-- 中间：地点设置 -->
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title mb-0"><i class="bi bi-geo-alt"></i> 地点设置</h5>
                    </div>
                    <div class="card-body">
                        <!-- 交通类型选择 -->
                        <div class="mb-4">
                            <label class="form-label">交通类型 *</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="travel_type" id="pickup" value="接机/站" checked onchange="updateLocationOptions()">
                                <label class="form-check-label" for="pickup">接机/站</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="travel_type" id="dropoff" value="送机/站" onchange="updateLocationOptions()">
                                <label class="form-check-label" for="dropoff">送机/站</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="travel_type" id="custom" value="混合交通安排（自定义）" onchange="updateLocationOptions()">
                                <label class="form-check-label" for="custom">混合交通安排（自定义）</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="travel_type" id="point2point" value="点对点" onchange="updateLocationOptions()">
                                <label class="form-check-label" for="point2point">点对点</label>
                            </div>
                        </div>

                        <!-- 出发地点 -->
                        <div class="mb-4">
                            <label class="form-label">出发地点 *</label>
                            <!-- 接机/站、送机/站、点对点模式：下拉选择 -->
                            <select class="form-select location-select" name="departure_location" id="departure_select" required>
                                <option value="">请选择出发地点</option>
                                <?php foreach ($locations as $location): ?>
                                <option value="<?php echo htmlspecialchars($location); ?>">
                                    <?php echo htmlspecialchars($location); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <!-- 混合交通安排自定义模式：文本输入 -->
                            <input type="text" class="form-control location-input" name="departure_location_custom" id="departure_input" 
                                   placeholder="请输入出发地点" style="display: none;" required>
                        </div>
                        
                        <!-- 到达地点 -->
                        <div class="mb-3">
                            <label class="form-label">到达地点 *</label>
                            <!-- 接机/站、送机/站、点对点模式：下拉选择 -->
                            <select class="form-select location-select" name="destination_location" id="destination_select" required>
                                <option value="">请选择到达地点</option>
                                <?php foreach ($locations as $location): ?>
                                <option value="<?php echo htmlspecialchars($location); ?>">
                                    <?php echo htmlspecialchars($location); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <!-- 混合交通安排自定义模式：文本输入 -->
                            <input type="text" class="form-control location-input" name="destination_location_custom" id="destination_input" 
                                   placeholder="请输入到达地点" style="display: none;" required>
                        </div>
                        
                        <div class="text-center mb-3">
                            <button type="button" class="btn btn-outline-secondary" onclick="swapLocations()" id="swapLocationsBtn" style="display: none;">
                                <i class="bi bi-arrow-left-right"></i> 交换地点
                            </button>
                        </div>
                        
                        <!-- 特殊要求输入框 -->
                        <div class="mb-3">
                            <label class="form-label special-requirements-label">特殊要求</label>
                            <input type="text" class="form-control" id="specialRequirements" name="special_requirements" 
                                   placeholder="可填写航班信息及备注信息">
                        </div>
                    </div>
                </div>
            </div>

            <!-- 右侧：人员选择 -->
            <div class="col-md-5">
                <div class="card h-100">
                    <div class="card-header bg-success text-white">
                        <h5 class="card-title mb-0"><i class="bi bi-people"></i> 选择乘客</h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <select class="form-select" id="departmentFilter">
                                    <option value="">全部部门 - 点击可按部门筛选人员</option>
                                    <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept['name']); ?>">
                                        <?php echo htmlspecialchars($dept['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <input type="text" class="form-control" id="personnelSearch" 
                                       placeholder="搜索人员姓名...">
                            </div>
                        </div>
                        
                        <div class="border rounded p-3" style="max-height: 360px; overflow-y: auto;">
                            <?php if (empty($personnel)): ?>
                            <div class="text-center text-muted py-5">
                                <i class="bi bi-people fs-1"></i>
                                <p>暂无人员数据</p>
                                <a href="personnel.php" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-plus"></i> 前往人员管理
                                </a>
                            </div>
                            <?php else: ?>
                            <div class="row">
                                <?php foreach ($personnel as $person): 
                                    $dept_names = isset($person['departments']) ? $person['departments'] : '';
                                ?>
                                <div class="col-md-6 mb-2 personnel-item" 
                                     data-name="<?php echo htmlspecialchars(strtolower($person['name'])); ?>"
                                     data-department="<?php echo htmlspecialchars($dept_names); ?>">
                                    <div class="form-check personnel-card">
                                        <input class="form-check-input" type="checkbox" 
                                               name="personnel_ids[]" value="<?php echo $person['id']; ?>" 
                                               id="person_<?php echo $person['id']; ?>">
                                        <label class="form-check-label" for="person_<?php echo $person['id']; ?>">
                                            <div class="d-flex align-items-center mb-1">
                                                <strong class="ms-1"><?php echo htmlspecialchars($person['name']); ?></strong>
                                            </div>
                                            <!-- 新增：显示职位信息 -->
                                            <?php if (!empty($person['positions'])): ?>
                                            <div class="mb-1">
                                                <small class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-2 py-1"><?php echo htmlspecialchars($person['positions']); ?></small>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($dept_names): ?>
                                            <div>
                                                <small class="text-muted"><?php echo htmlspecialchars($dept_names); ?></small>
                                            </div>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- 已选乘客摘要（动态） -->
                        <div id="selectedSummary" class="mt-2" style="display:none;">
                            <div class="small text-muted mb-1">已选乘客：</div>
                            <div id="selectedSummaryList" class="d-flex flex-wrap gap-1"></div>
                        </div>

                        <div class="mt-3 d-flex align-items-center gap-2">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="selectAllPersonnel">
                                <label class="form-check-label" for="selectAllPersonnel">
                                    <small>全选/全不选</small>
                                </label>
                            </div>
                            <small class="text-muted">已选择：<span id="selectedCount">0</span>人</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 底部：创建行程按钮 -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="text-center">
                    <button type="submit" name="quick_create" class="btn btn-success btn-lg px-5">
                        <i class="bi bi-plus-circle"></i> 立刻创建行程
                    </button>
                </div>
            </div>
        </div>
    </form>

    <!-- 交通类型切换脚本 -->
    <script>
    // 处理交通类型切换
    function handleTravelTypeChange() {
        const travelType = document.querySelector('input[name="travel_type"]:checked').value;
        const departureSelect = document.getElementById('departure_select');
        const destinationSelect = document.getElementById('destination_select');
        const departureInput = document.getElementById('departure_input');
        const destinationInput = document.getElementById('destination_input');
        const swapBtn = document.getElementById('swapLocationsBtn');
        const specialRequirements = document.getElementById('specialRequirements');

        // 控制交换地点按钮的显示
        if (travelType === '混合交通安排（自定义）' || travelType === '点对点') {
            swapBtn.style.display = 'inline-block';
            // 更改特殊要求输入框提示内容
            specialRequirements.placeholder = '可填写备注';
        } else {
            swapBtn.style.display = 'none';
            // 更改特殊要求输入框提示内容
            specialRequirements.placeholder = '可填写航班信息及备注信息';
        }

        if (travelType === '混合交通安排（自定义）') {
            // 自定义模式：显示文本输入框，隐藏下拉选择
            departureSelect.style.display = 'none';
            destinationSelect.style.display = 'none';
            departureInput.style.display = 'block';
            destinationInput.style.display = 'block';
            
            // 更新required属性
            departureSelect.removeAttribute('required');
            destinationSelect.removeAttribute('required');
            departureInput.setAttribute('required', 'required');
            destinationInput.setAttribute('required', 'required');
            
            // 清空下拉选择值
            departureSelect.value = '';
            destinationSelect.value = '';
        } else {
            // 其他模式：显示下拉选择，隐藏文本输入框
            departureSelect.style.display = 'block';
            destinationSelect.style.display = 'block';
            departureInput.style.display = 'none';
            destinationInput.style.display = 'none';
            
            // 更新required属性
            departureSelect.setAttribute('required', 'required');
            destinationSelect.setAttribute('required', 'required');
            departureInput.removeAttribute('required');
            destinationInput.removeAttribute('required');
            
            // 清空文本输入框值
            departureInput.value = '';
            destinationInput.value = '';
        }
    }

    // 为交通类型单选按钮添加事件监听
    document.querySelectorAll('input[name="travel_type"]').forEach(radio => {
        radio.addEventListener('change', handleTravelTypeChange);
    });

    // 初始化页面时调用一次
    handleTravelTypeChange();
    
    // 更新地点选项显示
    function updateLocationOptions() {
        const travelType = document.querySelector('input[name="travel_type"]:checked').value;
        const departureSelect = document.getElementById('departure_select');
        const destinationSelect = document.getElementById('destination_select');
        const timeLabel = document.getElementById('time_label');
        const timeInput = document.getElementById('departure_time_input');
        
        // 根据交通类型更新时间标签和样式
        if (travelType === '接机/站') {
            timeLabel.textContent = '到达时间 *';
            timeLabel.style.color = '#fd7e14'; // 浅橘色
            timeInput.classList.add('arrival-time-input');
        } else {
            timeLabel.textContent = '出发时间 *';
            timeLabel.style.color = ''; // 恢复默认颜色
            timeInput.classList.remove('arrival-time-input');
        }
        
        // 保存当前选中的值
        const currentDeparture = departureSelect.value;
        const currentDestination = destinationSelect.value;
        
        // 遍历选项，根据交通类型显示或隐藏"机场/高铁站"
        [departureSelect, destinationSelect].forEach(select => {
            Array.from(select.options).forEach(option => {
                if (option.value === '机场/高铁站') {
                    // 点对点模式不显示机场/高铁站，其他模式显示
                    option.style.display = (travelType === '点对点' || travelType === '混合交通安排（自定义）') ? 'none' : '';
                }
            });
        });
        
        // 如果当前选中的值被隐藏了，重置为第一个有效选项
        if (departureSelect.options[departureSelect.selectedIndex]?.style.display === 'none') {
            departureSelect.selectedIndex = 0;
        }
        if (destinationSelect.options[destinationSelect.selectedIndex]?.style.display === 'none') {
            destinationSelect.selectedIndex = 0;
        }
    }
    
    // 为交通类型单选按钮添加更新地点选项的事件监听
    document.querySelectorAll('input[name="travel_type"]').forEach(radio => {
        radio.addEventListener('change', updateLocationOptions);
    });
    
    // 初始化时调用更新地点选项
    updateLocationOptions();

    // 更新交换地点功能
    function swapLocations() {
        const travelType = document.querySelector('input[name="travel_type"]:checked').value;
        
        if (travelType === '混合交通安排（自定义）') {
            // 自定义模式：交换文本输入框的值
            const departureInput = document.getElementById('departure_input');
            const destinationInput = document.getElementById('destination_input');
            const temp = departureInput.value;
            departureInput.value = destinationInput.value;
            destinationInput.value = temp;
        } else {
            // 其他模式：交换下拉选择的值
            const departureSelect = document.getElementById('departure_select');
            const destinationSelect = document.getElementById('destination_select');
            const temp = departureSelect.value;
            departureSelect.value = destinationSelect.value;
            destinationSelect.value = temp;
        }
    }

    // 表单提交前处理地点值
    document.getElementById('quickTransportForm').addEventListener('submit', function(e) {
        const travelType = document.querySelector('input[name="travel_type"]:checked').value;
        
        if (travelType === '市内交通（自定义）') {
            // 将文本输入框的值复制到隐藏的实际提交字段
            const departureInput = document.getElementById('departure_input');
            const destinationInput = document.getElementById('destination_input');
            
            // 创建隐藏字段或使用现有字段
            let departureField = document.querySelector('input[name="departure_location"]');
            let destinationField = document.querySelector('input[name="destination_location"]');
            
            if (!departureField) {
                departureField = document.createElement('input');
                departureField.type = 'hidden';
                departureField.name = 'departure_location';
                this.appendChild(departureField);
            }
            
            if (!destinationField) {
                destinationField = document.createElement('input');
                destinationField.type = 'hidden';
                destinationField.name = 'destination_location';
                this.appendChild(destinationField);
            }
            
            departureField.value = departureInput.value;
            destinationField.value = destinationInput.value;
        }
    });
    
    // 车型需求数量切换功能
    function toggleVehicleQuantity(checkbox, vehicleType) {
        const quantityInput = document.getElementById('vehicle_quantity_' + vehicleType);
        if (checkbox.checked) {
            quantityInput.style.display = 'inline-block';
            quantityInput.setAttribute('required', 'required');
        } else {
            quantityInput.style.display = 'none';
            quantityInput.removeAttribute('required');
            quantityInput.value = 1; // 重置为默认值
        }
    }
    
    // 初始化车型需求复选框状态
    document.addEventListener('DOMContentLoaded', function() {
        const checkboxes = document.querySelectorAll('.vehicle-type-checkbox');
        checkboxes.forEach(checkbox => {
            const vehicleType = checkbox.value;
            const quantityInput = document.getElementById('vehicle_quantity_' + vehicleType);
            if (checkbox.checked) {
                quantityInput.style.display = 'inline-block';
            } else {
                quantityInput.style.display = 'none';
            }
        });
    });
    
    </script>

    <style>
    /* 交通类型单选按钮样式优化 */
    .form-check {
        margin-bottom: 0.5rem;
    }

    .form-check-input:checked + .form-check-label {
        color: #0d6efd;
        font-weight: 500;
    }

    /* 地点输入框样式 */
    .location-input:focus {
        border-color: #86b7fe;
        box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
    }

    /* 到达时间输入框样式 - 浅橘色主题 */
    .arrival-time-input:focus {
        border-color: #fd7e14;
        box-shadow: 0 0 0 0.25rem rgba(253, 126, 20, 0.25);
    }

    .arrival-time-input {
        border-color: #fd7e14;
    }

    /* 交换按钮样式 */
    .btn-outline-secondary:hover {
        background-color: #6c757d;
        border-color: #6c757d;
        color: white;
    }

    /* 人员卡片样式 */
    .personnel-card {
        padding: 10px;
        border-radius: 5px;
        transition: all 0.3s ease;
        border: 1px solid #e9ecef;
    }

    /* 选中人员卡片样式 */
    .personnel-card:hover {
        background-color: #f8f9fa;
        border-color: #dee2e6;
    }

    .personnel-card input[type="checkbox"]:checked + label {
        background-color: #e3f2fd;
        border: 1px dashed #0d6efd;
        border-radius: 5px;
        padding: 10px;
        display: block;
        margin: -10px;
    }

    /* 特殊要求标签样式 - 浅橘色下划线 */
    .special-requirements-label {
        color: #f80;
        text-decoration: underline;
        text-decoration-color: #f80;
        text-underline-offset: 3px;
    }

    /* 特殊要求输入框样式 - 浅橘色虚线框 */
    #specialRequirements {
        border: 1px dashed #f80;
    }

    #specialRequirements:focus {
        border: 1px dashed #f80;
        box-shadow: 0 0 0 0.2rem rgba(255, 136, 0, 0.25);
    }

    .card {
        box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        border: 1px solid rgba(0, 0, 0, 0.125);
    }

    .card-header {
        font-weight: 600;
    }

    .personnel-item {
        transition: all 0.3s ease;
    }

    .personnel-item.hidden {
        display: none;
    }

    #personnelSearch:focus {
        box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
    }

    /* 简化版行程列表样式 */
    .row.mt-6 {
        margin-top: 3rem !important;
    }

    /* 乘车人列表样式 */
    .passenger-tags {
        line-height: 1.5;
    }

    .department-group {
        margin-bottom: 0.25rem;
    }

    .dept-tag {
        display: inline-block;
        font-weight: bold;
        color: #495057;
    }

    .count-badge {
        font-size: 0.75rem;
        background-color: #e9ecef;
        border-radius: 12px;
        padding: 0.125rem 0.5rem;
        margin-left: 0.25rem;
    }

    .names-list {
        display: block;
        font-size: 0.875rem;
        color: #6c757d;
        margin-left: 0.5rem;
    }

    /* 表格样式优化 */
    .table thead th {
        font-weight: 600;
        text-align: center;
        vertical-align: middle;
        border: 1px solid #dee2e6;
    }

    .table tbody td {
        vertical-align: middle;
        border: 1px solid #dee2e6;
    }

    .table-hover tbody tr:hover {
        background-color: #f8f9fa;
    }

    /* 时间显示样式 */
    .time-display {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .time-label {
        color: #6c757d;
        min-width: 40px;
    }

    .time-value {
        font-weight: 600;
        color: #495057;
    }

    /* 车型需求样式 */
    .vehicle-requirements {
        display: flex;
        align-items: center;
        padding: 4px 0;
    }

    /* 行程状态样式 */
    .badge {
        font-size: 0.75rem;
        padding: 0.35em 0.65em;
    }
    </style>
    <style>
    /* 已选乘客摘要徽章样式（更醒目） */
    #selectedSummaryList .badge {
        background-color: #fff3cd; /* 浅黄背景 */
        color: #a15c00;            /* 深橙文字 */
        border: 1px solid #ffd78a; /* 浅橙边框 */
        font-weight: 500;
    }
    </style>

    <!-- 简化版行程列表 - 开始 -->
    <?php
    // 获取最近5条行程记录
    $recent_transports = [];
    try {
        // 只选择主行程（没有父行程ID的行程）
        $recent_query = "SELECT 
            tr.id as main_id,
            tr.travel_date,
            tr.travel_type,
            tr.departure_time,
            tr.departure_location,
            tr.destination_location,
            tr.status,
            tr.passenger_count as total_passengers,
            tr.special_requirements,
            tr.vehicle_requirements,
            GROUP_CONCAT(DISTINCT CONCAT(p.name, '|', COALESCE(d.name, '未分配部门')) ORDER BY p.name) as personnel_info
        FROM transportation_reports tr 
        LEFT JOIN transportation_passengers tp ON tr.id = tp.transportation_report_id
        LEFT JOIN personnel p ON tp.personnel_id = p.id OR tr.personnel_id = p.id
        LEFT JOIN project_department_personnel pdp ON p.id = pdp.personnel_id AND pdp.project_id = tr.project_id
        LEFT JOIN departments d ON pdp.department_id = d.id
        WHERE tr.project_id = :project_id AND tr.parent_transport_id IS NULL
        GROUP BY tr.id, tr.travel_date, tr.travel_type, tr.departure_time, tr.departure_location, tr.destination_location, tr.status, tr.passenger_count, tr.special_requirements, tr.vehicle_requirements
        ORDER BY tr.travel_date DESC, tr.departure_time DESC
        LIMIT 5";
        
        $recent_stmt = $db->prepare($recent_query);
        $recent_stmt->execute([':project_id' => $projectId]);
        $recent_transports = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // 错误处理，仅记录日志，不影响页面功能
        error_log("获取最近行程失败: " . $e->getMessage());
    }
    
    // 状态映射配置
    $status_class = [
        'pending' => 'warning',
        'assigned' => 'info',
        'in_progress' => 'primary',
        'completed' => 'success',
        'cancelled' => 'danger',
        'confirmed' => 'success',
        'approved' => 'success',
        'processing' => 'primary'
    ];
    
    $status_text = [
        'pending' => '待处理',
        'assigned' => '已分配',
        'in_progress' => '进行中',
        'completed' => '已完成',
        'cancelled' => '已取消',
        'confirmed' => '已确认',
        'approved' => '已批准',
        'processing' => '处理中'
    ];
    
    // 车型映射配置
    $vehicle_type_map = [
        'car' => '轿车',
        'van' => '商务车',
        'minibus' => '中巴车',
        'bus' => '大巴车',
        'truck' => '货车',
        'other' => '其他'
    ];
    
    // 解析车型需求函数
    function parse_vehicle_requirements($vehicle_requirements_json) {
        global $vehicle_type_map;
        
        if (empty($vehicle_requirements_json)) {
            return [];
        }
        
        try {
            $requirements = json_decode($vehicle_requirements_json, true);
            if (!is_array($requirements)) {
                return [];
            }
            
            $result = [];
            foreach ($requirements as $vehicle_type => $details) {
                if (isset($details['type']) && $details['type'] === $vehicle_type && 
                    isset($details['quantity']) && $details['quantity'] > 0 && 
                    isset($vehicle_type_map[$vehicle_type])) {
                    
                    $result[] = [
                        'type' => $vehicle_type_map[$vehicle_type],
                        'quantity' => $details['quantity']
                    ];
                }
            }
            
            return $result;
        } catch (Exception $e) {
            // 解析错误时返回空数组
            return [];
        }
    }
    ?>
    
    <div class="row mt-6">
        <div class="col-12">
            <h3><i class="bi bi-clock-history text-primary"></i> 最近行程</h3>
            <p class="text-muted">显示最近5条行程记录，点击查看完整列表查看更多</p>
        </div>
    </div>
    
    <?php if (empty($recent_transports)): ?>
        <div class="text-center py-5">
            <i class="bi bi-inbox" style="font-size: 2em; color: #ccc;"></i>
            <h4 class="text-muted mt-3">暂无行程记录</h4>
            <p class="text-muted">还没有创建任何行程</p>
        </div>
    <?php else: ?>
        <!-- 表格格式显示行程列表 -->
        <div class="table-responsive">
            <table class="table table-hover table-striped table-bordered">
                <thead class="table-light">
                    <tr>
                        <th width="50" align="center">序号</th>
                        <th width="40" align="center">交通类型</th> <!-- 列宽调整为40px -->
                        <th width="10" align="center">时间</th> <!-- 列宽调整为40px -->
                        <th width="200" align="center">乘车人</th>
                        <th width="46" align="center">乘客数</th>
                        <th width="75" align="center">路线</th>
                        <th width="120" align="center">车型需求</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $index = 1;
                    foreach ($recent_transports as $transport):
                        // 处理乘车人列表（按部门聚合显示）
                        $department_groups = [];
                        if ($transport['personnel_info']) {
                            $personnel_data = explode(',', $transport['personnel_info']);
                            foreach ($personnel_data as $data) {
                                if (!empty($data)) {
                                    $parts = explode('|', $data);
                                    $name = htmlspecialchars($parts[0] ?? '');
                                    $department = htmlspecialchars($parts[1] ?? '未分配部门');
                                    if (!empty($name)) {
                                        if (!isset($department_groups[$department])) {
                                            $department_groups[$department] = [];
                                        }
                                        $department_groups[$department][] = $name;
                                    }
                                }
                            }
                        }
                        // 按部门名称排序
                        ksort($department_groups);
                    ?>
                        <tr>
                            <td class="text-center">
                                <span class="badge bg-light text-dark border"><?php echo $index++; ?></span>
                            </td>
                            <td align="center">
                                <span class="badge bg-primary"><?php echo htmlspecialchars($transport['travel_type']); ?></span>
                            </td>
                            <td>
                                <div><strong>日期:</strong> <?php echo date('Y/m/d', strtotime($transport['travel_date'])); ?></div>
                                <div class="time-display">
    <span class="time-label">时间:</span>
    <span class="time-value fw-bold text-dark" style="background-color: #fff3cd; padding: 2px 6px; border-radius: 3px; display: inline-block;"><?php echo substr($transport['departure_time'], 0, 5); ?></span>
    <?php if ($transport['travel_type'] === '接机/站'): ?>
        <span class="badge bg-danger text-white ms-2" style="font-size: 0.6rem; padding: 0.2em 0.45em; line-height: 1.1;">航班/车次<br>到站时间</span>
    <?php endif; ?>
</div>
                            </td>
                            <td>
                                <div class="passenger-tags">
                                    <?php if (!empty($department_groups)): ?>
                                        <?php foreach ($department_groups as $department => $names): ?>
                                            <div class="department-group">
                                                <span class="dept-tag">
                                                    <?= $department ?>
                                                    <span class="count-badge">(<?= count($names) ?>人)</span>
                                                </span>
                                                <span class="names-list"><?= implode('、', $names) ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="text-muted">无乘车人</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td align="center">
                                <span class="badge bg-info text-white"><?php echo htmlspecialchars($transport['total_passengers']); ?>人</span>
                            </td>
                            <td>
                                <div><strong>出发:</strong> <?php echo htmlspecialchars($transport['departure_location']); ?></div>
                                <div><strong>到达:</strong> <?php echo htmlspecialchars($transport['destination_location']); ?></div>
                            </td>
                            <td align="center">
                                <div class="vehicle-requirements">
                                    <?php 
                                    // 解析车型需求
                                    $vehicle_requirements = parse_vehicle_requirements($transport['vehicle_requirements']);
                                    if (!empty($vehicle_requirements)): 
                                    ?>
                                        <i class="fas fa-car-side text-primary me-1"></i>
                                        <div>
                                            <?php foreach ($vehicle_requirements as $req): ?>
                                                <span class="badge bg-primary text-white mb-1 mr-1 d-inline-block">
                                                    <?php echo htmlspecialchars($req['type']); ?>
                                                    <?php if ($req['quantity'] >= 1): ?>
                                                        <span class="badge bg-white text-primary text-xs ml-1">x<?php echo $req['quantity']; ?></span>
                                                    <?php endif; ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">无车型需求</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    
    <!-- 简化版行程列表 - 结束 -->
    
    <!-- 查看/编辑完整行程列表按钮区域 - 开始 -->
    <div class="row mt-5">
        <div class="col-12 text-center">
            <a href="transport_list.php" class="btn btn-outline-primary btn-lg">
                <i class="bi bi-list"></i> 查看/编辑完整行程列表
            </a>
        </div>
    </div>
    <!-- 查看/编辑完整行程列表按钮区域 - 结束 -->
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 部门筛选功能
    const departmentFilter = document.getElementById('departmentFilter');
    const personnelSearch = document.getElementById('personnelSearch');
    const personnelItems = document.querySelectorAll('.personnel-item');
    const selectedCount = document.getElementById('selectedCount');
    const selectAllCheckbox = document.getElementById('selectAllPersonnel');
    
    // 添加选中状态处理
    function updatePersonnelCardStyle() {
        const checkboxes = document.querySelectorAll('.personnel-card input[type="checkbox"]');
        checkboxes.forEach(checkbox => {
            const card = checkbox.closest('.personnel-card');
            if (checkbox.checked) {
                card.style.backgroundColor = '#e3f2fd';
                card.style.border = '1px dashed #0d6efd';
            } else {
                card.style.backgroundColor = '';
                card.style.border = '1px solid #e9ecef';
            }
        });
    }
    
    // 为所有复选框添加事件监听器
    const checkboxes = document.querySelectorAll('.personnel-card input[type="checkbox"]');
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const card = this.closest('.personnel-card');
            if (this.checked) {
                card.style.backgroundColor = '#e3f2fd';
                card.style.border = '1px dashed #0d6efd';
            } else {
                card.style.backgroundColor = '';
                card.style.border = '1px solid #e9ecef';
            }
            updateSelectedCount();
            updateSelectAllState();
            updateSelectedSummary();
        });
    });
    
    function filterPersonnel() {
        const selectedDept = departmentFilter.value.toLowerCase();
        const searchTerm = personnelSearch.value.toLowerCase();
        
        personnelItems.forEach(item => {
            const name = item.dataset.name;
            const department = item.dataset.department.toLowerCase();
            
            const matchesDept = !selectedDept || department.includes(selectedDept);
            const matchesSearch = !searchTerm || name.includes(searchTerm);
            
            if (matchesDept && matchesSearch) {
                item.classList.remove('hidden');
            } else {
                item.classList.add('hidden');
            }
        });
        
        // 筛选后重置全选状态
        updateSelectAllState();
    }
    
    function updateSelectedCount() {
        const checkedBoxes = document.querySelectorAll('input[name="personnel_ids[]"]:checked');
        selectedCount.textContent = checkedBoxes.length;
    }

    // 更新已选乘客摘要（姓名（部门））
    function updateSelectedSummary() {
        const container = document.getElementById('selectedSummary');
        const list = document.getElementById('selectedSummaryList');
        if (!container || !list) return;

        const checkedBoxes = document.querySelectorAll('input[name="personnel_ids[]"]:checked');
        list.innerHTML = '';

        if (checkedBoxes.length === 0) {
            container.style.display = 'none';
            return;
        }

        checkedBoxes.forEach(cb => {
            const item = cb.closest('.personnel-item');
            if (!item) return;
            const nameEl = item.querySelector('.form-check-label strong');
            const name = (nameEl ? nameEl.textContent : '').trim();
            const dept = (item.dataset.department || '').trim() || '未分配部门';

            const tag = document.createElement('span');
            tag.className = 'badge rounded-pill me-1 mb-1';
            tag.textContent = `${name}（${dept}）`;
            list.appendChild(tag);
        });

        container.style.display = 'block';
    }
    
    // 更新全选复选框状态
    function updateSelectAllState() {
        const visibleCheckboxes = document.querySelectorAll('.personnel-item:not(.hidden) input[name="personnel_ids[]"]');
        const checkedVisibleCheckboxes = document.querySelectorAll('.personnel-item:not(.hidden) input[name="personnel_ids[]"]:checked');
        
        if (visibleCheckboxes.length === 0) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
            selectAllCheckbox.disabled = true;
        } else {
            selectAllCheckbox.disabled = false;
            if (checkedVisibleCheckboxes.length === visibleCheckboxes.length) {
                selectAllCheckbox.checked = true;
                selectAllCheckbox.indeterminate = false;
            } else if (checkedVisibleCheckboxes.length === 0) {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = false;
            } else {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = true;
            }
        }
    }
    
    // 全选/全不选功能
    selectAllCheckbox.addEventListener('change', function() {
        if (this.disabled) return;
        
        const visibleCheckboxes = document.querySelectorAll('.personnel-item:not(.hidden) input[name="personnel_ids[]"]');
        visibleCheckboxes.forEach(checkbox => {
            checkbox.checked = this.checked;
            // 更新卡片样式
            const card = checkbox.closest('.personnel-card');
            if (this.checked) {
                card.style.backgroundColor = '#e3f2fd';
                card.style.border = '1px dashed #0d6efd';
            } else {
                card.style.backgroundColor = '';
                card.style.border = '1px solid #e9ecef';
            }
        });
        updateSelectedCount();
    updateSelectedSummary();
    });
    
    // 当单个复选框状态改变时，更新全选复选框状态
    document.addEventListener('change', function(e) {
        if (e.target.name === 'personnel_ids[]') {
            updateSelectAllState();
            updateSelectedCount();
            updateSelectedSummary();
        }
    });
    
    // 事件监听
    departmentFilter.addEventListener('change', filterPersonnel);
    personnelSearch.addEventListener('input', filterPersonnel);
    
    // 初始更新计数和全选状态
    updateSelectedCount();
    updateSelectAllState();
    updatePersonnelCardStyle();
    updateSelectedSummary();
    
    // 初始化交通类型对应的下拉框选项
    updateLocationOptions();
    
    // 表单验证
    document.getElementById('quickTransportForm').addEventListener('submit', function(e) {
        const checkedBoxes = document.querySelectorAll('input[name="personnel_ids[]"]:checked');
        if (checkedBoxes.length === 0) {
            e.preventDefault();
            alert('请至少选择一名乘客！');
            return false;
        }
    });
});

// 根据交通类型更新地点选项
function updateLocationOptions() {
    const travelType = document.querySelector('input[name="travel_type"]:checked').value;
    const departureSelect = document.getElementById('departure_select');
    const destinationSelect = document.getElementById('destination_select');
    
    // 保存当前选中的值
    const currentDeparture = departureSelect.value;
    const currentDestination = destinationSelect.value;
    
    if (travelType === '接机/站') {
        // 接机/站：出发地点为机场和高铁站，到达地点为项目场地和酒店
        departureSelect.innerHTML = '';
        destinationSelect.innerHTML = '';
        
        // 添加默认选项
        const departureDefault = document.createElement('option');
        departureDefault.value = '';
        departureDefault.textContent = '请选择出发地点';
        departureSelect.appendChild(departureDefault);
        
        const destinationDefault = document.createElement('option');
        destinationDefault.value = '';
        destinationDefault.textContent = '请选择到达地点';
        destinationSelect.appendChild(destinationDefault);
        
        // 添加交通地点到出发地点
        <?php foreach ($transport_locations as $location): ?>
        const depOption<?php echo md5($location); ?> = document.createElement('option');
        depOption<?php echo md5($location); ?>.value = '<?php echo htmlspecialchars($location); ?>';
        depOption<?php echo md5($location); ?>.textContent = '<?php echo htmlspecialchars($location); ?>';
        departureSelect.appendChild(depOption<?php echo md5($location); ?>);
        <?php endforeach; ?>
        
        // 添加场地地点到到达地点
        <?php foreach ($venue_locations as $location): ?>
        const destOption<?php echo md5($location); ?> = document.createElement('option');
        destOption<?php echo md5($location); ?>.value = '<?php echo htmlspecialchars($location); ?>';
        destOption<?php echo md5($location); ?>.textContent = '<?php echo htmlspecialchars($location); ?>';
        destinationSelect.appendChild(destOption<?php echo md5($location); ?>);
        <?php endforeach; ?>
        
    } else if (travelType === '送机/站') {
        // 送机/站：出发地点为项目场地和酒店，到达地点为机场和高铁站
        departureSelect.innerHTML = '';
        destinationSelect.innerHTML = '';
        
        // 添加默认选项
        const departureDefault = document.createElement('option');
        departureDefault.value = '';
        departureDefault.textContent = '请选择出发地点';
        departureSelect.appendChild(departureDefault);
        
        const destinationDefault = document.createElement('option');
        destinationDefault.value = '';
        destinationDefault.textContent = '请选择到达地点';
        destinationSelect.appendChild(destinationDefault);
        
        // 添加场地地点到出发地点
        <?php foreach ($venue_locations as $location): ?>
        const depOption<?php echo md5($location); ?> = document.createElement('option');
        depOption<?php echo md5($location); ?>.value = '<?php echo htmlspecialchars($location); ?>';
        depOption<?php echo md5($location); ?>.textContent = '<?php echo htmlspecialchars($location); ?>';
        departureSelect.appendChild(depOption<?php echo md5($location); ?>);
        <?php endforeach; ?>
        
        // 添加交通地点到到达地点
        <?php foreach ($transport_locations as $location): ?>
        const destOption<?php echo md5($location); ?> = document.createElement('option');
        destOption<?php echo md5($location); ?>.value = '<?php echo htmlspecialchars($location); ?>';
        destOption<?php echo md5($location); ?>.textContent = '<?php echo htmlspecialchars($location); ?>';
        destinationSelect.appendChild(destOption<?php echo md5($location); ?>);
        <?php endforeach; ?>
        
    } else if (travelType === '混合交通安排（自定义）') {
        // 混合交通安排：显示所有地点
        departureSelect.innerHTML = '';
        destinationSelect.innerHTML = '';
        
        // 添加默认选项
        const departureDefault = document.createElement('option');
        departureDefault.value = '';
        departureDefault.textContent = '请选择出发地点';
        departureSelect.appendChild(departureDefault);
        
        const destinationDefault = document.createElement('option');
        destinationDefault.value = '';
        destinationDefault.textContent = '请选择到达地点';
        destinationSelect.appendChild(destinationDefault);
        
        // 添加所有地点
        <?php foreach ($locations as $location): ?>
        const depOption<?php echo md5($location); ?> = document.createElement('option');
        depOption<?php echo md5($location); ?>.value = '<?php echo htmlspecialchars($location); ?>';
        depOption<?php echo md5($location); ?>.textContent = '<?php echo htmlspecialchars($location); ?>';
        departureSelect.appendChild(depOption<?php echo md5($location); ?>);
        
        const destOption<?php echo md5($location); ?> = document.createElement('option');
        destOption<?php echo md5($location); ?>.value = '<?php echo htmlspecialchars($location); ?>';
        destOption<?php echo md5($location); ?>.textContent = '<?php echo htmlspecialchars($location); ?>';
        destinationSelect.appendChild(destOption<?php echo md5($location); ?>);
        <?php endforeach; ?>
        
    } else if (travelType === '点对点') {
        // 点对点：仅显示项目场地和酒店，不显示高铁站和机场
        departureSelect.innerHTML = '';
        destinationSelect.innerHTML = '';
        
        // 添加默认选项
        const departureDefault = document.createElement('option');
        departureDefault.value = '';
        departureDefault.textContent = '请选择出发地点';
        departureSelect.appendChild(departureDefault);
        
        const destinationDefault = document.createElement('option');
        destinationDefault.value = '';
        destinationDefault.textContent = '请选择到达地点';
        destinationSelect.appendChild(destinationDefault);
        
        // 仅添加场地地点（项目场地和酒店）
        <?php foreach ($venue_locations as $location): ?>
        const depOption<?php echo md5($location); ?> = document.createElement('option');
        depOption<?php echo md5($location); ?>.value = '<?php echo htmlspecialchars($location); ?>';
        depOption<?php echo md5($location); ?>.textContent = '<?php echo htmlspecialchars($location); ?>';
        departureSelect.appendChild(depOption<?php echo md5($location); ?>);
        
        const destOption<?php echo md5($location); ?> = document.createElement('option');
        destOption<?php echo md5($location); ?>.value = '<?php echo htmlspecialchars($location); ?>';
        destOption<?php echo md5($location); ?>.textContent = '<?php echo htmlspecialchars($location); ?>';
        destinationSelect.appendChild(destOption<?php echo md5($location); ?>);
        <?php endforeach; ?>
    }
    
    // 尝试恢复之前选中的值，如果不可用则选择第一个选项
    const newDepartureOptions = Array.from(departureSelect.options);
    const newDestinationOptions = Array.from(destinationSelect.options);
    
    const departureStillValid = newDepartureOptions.some(opt => opt.value === currentDeparture);
    const destinationStillValid = newDestinationOptions.some(opt => opt.value === currentDestination);
    
    departureSelect.value = departureStillValid ? currentDeparture : (newDepartureOptions[0]?.value || '');
    destinationSelect.value = destinationStillValid ? currentDestination : (newDestinationOptions[0]?.value || '');
}

// 交换出发和到达地点
function swapLocations() {
    const travelType = document.querySelector('input[name="travel_type"]:checked').value;
    
    if (travelType === '混合交通安排（自定义）') {
        // 自定义模式：交换文本输入框的值
        const departureInput = document.getElementById('departure_input');
        const destinationInput = document.getElementById('destination_input');
        
        const temp = departureInput.value;
        departureInput.value = destinationInput.value;
        destinationInput.value = temp;
    } else {
        // 其他模式：交换下拉选择的值
        const departureSelect = document.getElementById('departure_select');
        const destinationSelect = document.getElementById('destination_select');
        const temp = departureSelect.value;
        departureSelect.value = destinationSelect.value;
        destinationSelect.value = temp;
    }
}
</script>

<?php include 'includes/footer.php'; ?>