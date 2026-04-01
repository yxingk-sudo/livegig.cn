<?php
session_start();
require_once '../config/database.php';
require_once 'page_functions.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();

$message = '';
$error = '';

// 处理操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_status') {
        $id = intval($_POST['id']);
        $status = $_POST['status'];
        
        if (in_array($status, ['pending', 'confirmed', 'cancelled'])) {
            $query = "UPDATE transportation_reports SET status = :status WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                $message = '报出行车状态更新成功！';
            } else {
                $error = '更新失败，请重试！';
            }
        }
    } elseif ($action === 'batch_confirm') {
        // 批量确认功能
        $ids = $_POST['ids'] ?? [];
        
        if (!empty($ids) && is_array($ids)) {
            try {
                $db->beginTransaction();
                
                $confirmed_count = 0;
                foreach ($ids as $id) {
                    $id = intval($id);
                    if ($id > 0) {
                        $query = "UPDATE transportation_reports SET status = 'confirmed' WHERE id = :id AND status = 'pending'";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':id', $id);
                        if ($stmt->execute()) {
                            $confirmed_count++;
                        }
                    }
                }
                
                $db->commit();
                $message = "成功确认 {$confirmed_count} 条记录！";
            } catch (Exception $e) {
                $db->rollback();
                $error = '批量确认失败：' . $e->getMessage();
            }
        } else {
            $error = '请选择要确认的记录！';
        }
    } elseif ($action === 'cancel_assignment') {
        $report_id = intval($_POST['report_id']);
        
        try {
            // 开始事务
            $db->beginTransaction();
            
            // 删除车辆分配记录 - 不需要project_id，因为transportation_report_id已经唯一
            $delete_query = "DELETE FROM transportation_fleet_assignments 
                           WHERE transportation_report_id = :report_id";
            $delete_stmt = $db->prepare($delete_query);
            $delete_stmt->bindParam(':report_id', $report_id);
            $delete_stmt->execute();
            
            
            // 提交事务
            $db->commit();
            
            $message = '车辆分配已成功取消！';
        } catch (Exception $e) {
            // 回滚事务
            $db->rollback();
            $error = '取消车辆分配失败：' . $e->getMessage();
        }
    }
}

// 获取筛选参数
$filters = [
    'project_id' => $_GET['project_id'] ?? '',
    'status' => $_GET['status'] ?? '',
    'travel_date' => $_GET['travel_date'] ?? '',
    'vehicle_type' => $_GET['vehicle_type'] ?? ''
];

// 获取项目列表用于筛选
$projects_query = "SELECT id, name, code FROM projects ORDER BY name ASC";
$projects_stmt = $db->prepare($projects_query);
$projects_stmt->execute();
$projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);

// 只有选择了项目才查询数据
$transportation_reports = [];
if ($filters['project_id']) {
    // 构建查询条件
    $where_conditions = ['tr.project_id = :project_id'];
    $params = [':project_id' => $filters['project_id']];

    if ($filters['status']) {
        $where_conditions[] = "tr.status = :status";
        $params[':status'] = $filters['status'];
    }

    if ($filters['travel_date']) {
        $where_conditions[] = "tr.travel_date = :date";
        $params[':date'] = $filters['travel_date'];
    }

    if ($filters['vehicle_type']) {
        $where_conditions[] = "tr.travel_type = :vehicle_type";
        $params[':vehicle_type'] = $filters['vehicle_type'];
    }

    $where_clause = "WHERE " . implode(' AND ', $where_conditions);

    // 获取报出行车列表 - 使用与transport_list.php相同的乘车人信息获取方式
    $query = "SELECT 
        tr.id,
        tr.project_id,
        tr.travel_date,
        tr.travel_type,
        tr.departure_time,
        tr.departure_location,
        tr.destination_location,
        tr.status,
        tr.contact_phone,
        tr.special_requirements,
        tr.vehicle_requirements,
        tr.created_at,
        p.code as project_code,
        p.name as project_name,
        -- 使用GROUP_CONCAT获取所有乘车人信息，格式为：姓名|部门
        GROUP_CONCAT(DISTINCT CONCAT(COALESCE(pr_main.name, pr.name), '|', COALESCE(d_main.name, d.name, '未分配部门')) ORDER BY COALESCE(pr_main.name, pr.name)) as personnel_info,
        tr.passenger_count as total_passengers,
        (SELECT COUNT(*) FROM transportation_fleet_assignments WHERE transportation_report_id = tr.id) as vehicle_count,
        -- 获取车辆分配信息：车队编号、车牌号码、驾驶员、驾驶员电话（使用子查询避免重复）
        (SELECT GROUP_CONCAT(f_sub.fleet_number ORDER BY f_sub.fleet_number) 
         FROM transportation_fleet_assignments tfa_sub 
         JOIN fleet f_sub ON tfa_sub.fleet_id = f_sub.id 
         WHERE tfa_sub.transportation_report_id = tr.id) as fleet_numbers,
        (SELECT GROUP_CONCAT(COALESCE(f_sub.license_plate, '-') ORDER BY f_sub.fleet_number) 
         FROM transportation_fleet_assignments tfa_sub 
         JOIN fleet f_sub ON tfa_sub.fleet_id = f_sub.id 
         WHERE tfa_sub.transportation_report_id = tr.id) as license_plates,
        (SELECT GROUP_CONCAT(COALESCE(f_sub.vehicle_model, '-') ORDER BY f_sub.fleet_number) 
         FROM transportation_fleet_assignments tfa_sub 
         JOIN fleet f_sub ON tfa_sub.fleet_id = f_sub.id 
         WHERE tfa_sub.transportation_report_id = tr.id) as vehicle_models,
        (SELECT GROUP_CONCAT(COALESCE(f_sub.driver_name, '未分配') ORDER BY f_sub.fleet_number) 
         FROM transportation_fleet_assignments tfa_sub 
         JOIN fleet f_sub ON tfa_sub.fleet_id = f_sub.id 
         WHERE tfa_sub.transportation_report_id = tr.id) as driver_names,
        (SELECT GROUP_CONCAT(COALESCE(f_sub.driver_phone, '-') ORDER BY f_sub.fleet_number) 
         FROM transportation_fleet_assignments tfa_sub 
         JOIN fleet f_sub ON tfa_sub.fleet_id = f_sub.id 
         WHERE tfa_sub.transportation_report_id = tr.id) as driver_phones,
        -- 获取报告人信息（前台提交人）
        COALESCE(reporter.display_name, reporter.username, '未知') as reporter_name
    FROM transportation_reports tr
    JOIN projects p ON tr.project_id = p.id
    -- 获取关联人员信息
    LEFT JOIN personnel pr ON tr.personnel_id = pr.id
    LEFT JOIN project_department_personnel pdp ON pdp.personnel_id = pr.id AND pdp.project_id = tr.project_id
    LEFT JOIN departments d ON pdp.department_id = d.id
    -- 获取transportation_passengers表中的乘客信息
    LEFT JOIN transportation_passengers tp ON tr.id = tp.transportation_report_id
    LEFT JOIN personnel pr_main ON tp.personnel_id = pr_main.id
    LEFT JOIN project_department_personnel pdp_main ON pdp_main.personnel_id = pr_main.id AND pdp_main.project_id = tr.project_id
    LEFT JOIN departments d_main ON d_main.id = pdp_main.department_id
    -- 车辆分配信息已通过子查询获取，无需JOIN
    -- 获取报告人信息（前台提交人）
    LEFT JOIN project_users reporter ON tr.reported_by = reporter.id
    $where_clause
    GROUP BY tr.id, tr.project_id, tr.travel_date, tr.travel_type, tr.departure_time, tr.departure_location, tr.destination_location, tr.status, tr.contact_phone, tr.special_requirements, tr.vehicle_requirements, tr.created_at, p.code, p.name, pr.name, pr.gender, d.name, reporter.display_name, reporter.username
    ORDER BY tr.travel_date DESC, tr.departure_time DESC, tr.created_at DESC";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $transportation_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 新的查询已经包含了所有乘车人信息，无需额外处理

// 状态映射
$status_map = [
    'pending' => ['label' => '待确认', 'class' => 'warning'],
    'confirmed' => ['label' => '已确认', 'class' => 'success'],
    'cancelled' => ['label' => '已取消', 'class' => 'danger']
];

// 处理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}
?>

<?php include 'includes/header.php'; ?>

<!-- 引入优化样式 -->
<link href="assets/css/meal-reports-optimized.css" rel="stylesheet">

<div class="container-fluid">
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i> <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- 项目选择区域 -->
    <div class="project-selector">
        <h4>
            <i class="bi bi-building"></i>
            项目选择
        </h4>
        <form method="GET" id="projectForm">
            <!-- 保持其他筛选条件 -->
            <?php if ($filters['status']): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($filters['status']); ?>"><?php endif; ?>
            <?php if ($filters['travel_date']): ?><input type="hidden" name="travel_date" value="<?php echo htmlspecialchars($filters['travel_date']); ?>"><?php endif; ?>
            <?php if ($filters['vehicle_type']): ?><input type="hidden" name="vehicle_type" value="<?php echo htmlspecialchars($filters['vehicle_type']); ?>"><?php endif; ?>
            
            <div class="row align-items-end">
                <div class="col-md-8">
                    <label for="project_id" class="form-label fw-semibold">请选择要管理的项目</label>
                    <select class="form-select" id="project_id" name="project_id" onchange="this.form.submit()">
                        <option value="">请选择项目...</option>
                        <?php foreach ($projects as $project): ?>
                            <option value="<?php echo $project['id']; ?>" 
                                    <?php echo $filters['project_id'] == $project['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($project['name']); ?>
                                <?php if (!empty($project['code'])): ?>
                                    (<?php echo htmlspecialchars($project['code']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <?php if ($filters['project_id']): ?>
                        <div class="project-status selected">
                            <i class="bi bi-check-circle-fill"></i>
                            已选择项目
                        </div>
                    <?php else: ?>
                        <div class="project-status not-selected">
                            <i class="bi bi-exclamation-circle-fill"></i>
                            请先选择项目
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <?php if ($filters['project_id']): ?>
        <!-- 筛选表单 -->
        <div class="card mb-3">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-funnel"></i> 筛选条件</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="project_id" value="<?php echo htmlspecialchars($filters['project_id']); ?>">
                    <div class="col-md-3">
                        <label for="status" class="form-label">状态</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">所有状态</option>
                            <option value="pending" <?php echo (isset($_GET['status']) && $_GET['status'] == 'pending') ? 'selected' : ''; ?>>待确认</option>
                            <option value="confirmed" <?php echo (isset($_GET['status']) && $_GET['status'] == 'confirmed') ? 'selected' : ''; ?>>已确认</option>
                            <option value="cancelled" <?php echo (isset($_GET['status']) && $_GET['status'] == 'cancelled') ? 'selected' : ''; ?>>已取消</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="travel_date" class="form-label">出行日期</label>
                        <input type="date" class="form-control" id="travel_date" name="travel_date" 
                               value="<?php echo htmlspecialchars(isset($_GET['travel_date']) ? $_GET['travel_date'] : ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label for="vehicle_type" class="form-label">车辆类型</label>
                        <select class="form-select" id="vehicle_type" name="vehicle_type">
                            <option value="">所有类型</option>
                            <option value="car" <?php echo (isset($_GET['vehicle_type']) && $_GET['vehicle_type'] == 'car') ? 'selected' : ''; ?>>轿车</option>
                            <option value="van" <?php echo (isset($_GET['vehicle_type']) && $_GET['vehicle_type'] == 'van') ? 'selected' : ''; ?>>商务车</option>
                            <option value="minibus" <?php echo (isset($_GET['vehicle_type']) && $_GET['vehicle_type'] == 'minibus') ? 'selected' : ''; ?>>中巴车</option>
                            <option value="bus" <?php echo (isset($_GET['vehicle_type']) && $_GET['vehicle_type'] == 'bus') ? 'selected' : ''; ?>>大巴车</option>
                            <option value="truck" <?php echo (isset($_GET['vehicle_type']) && $_GET['vehicle_type'] == 'truck') ? 'selected' : ''; ?>>货车</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-search"></i> 筛选
                        </button>
                        <a href="?project_id=<?php echo $filters['project_id']; ?>" class="btn btn-secondary">
                            <i class="bi bi-arrow-clockwise"></i> 重置
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- 报出行车列表 -->
        <?php if (empty($transportation_reports)): ?>
            <div class="empty-state text-center py-5">
                <i class="bi bi-car-front display-1 text-muted"></i>
                <h4 class="mt-3">暂无报出行车记录</h4>
                <p class="text-muted">当前筛选条件下没有找到报出行车记录</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th width="30">
                                <input type="checkbox" id="selectAll" onchange="toggleAll(this)">
                            </th>
                            <th>项目</th>
                            <th>出行日期</th>
                            <th>出行时间</th>
                            <th>出发地</th>
                            <th>目的地</th>
                            <th>乘车人</th>
                            <th>人数</th>
                            <th>车辆类型</th>
                            <th>车辆分配</th>
                            <th>状态</th>
                            <th>报告人</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transportation_reports as $report): ?>
                            <tr>
                                <td>
                                    <?php if ($report['status'] === 'pending'): ?>
                                        <input type="checkbox" name="report_ids[]" value="<?php echo $report['id']; ?>" class="report-checkbox" onchange="updateBatchButton()">
                                    <?php else: ?>
                                        <input type="checkbox" disabled>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($report['project_name']); ?></div>
                                    <?php if (!empty($report['project_code'])): ?>
                                        <small class="text-muted"><?php echo htmlspecialchars($report['project_code']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('Y-m-d', strtotime($report['travel_date'])); ?></td>
                                <td><?php echo htmlspecialchars($report['departure_time']); ?></td>
                                <td><?php echo htmlspecialchars($report['departure_location']); ?></td>
                                <td><?php echo htmlspecialchars($report['destination_location']); ?></td>
                                <td>
                                    <?php 
                                    if (!empty($report['personnel_info'])):
                                        $personnel_list = explode(',', $report['personnel_info']);
                                        foreach ($personnel_list as $personnel):
                                            $parts = explode('|', $personnel);
                                            $name = $parts[0];
                                            $department = isset($parts[1]) ? $parts[1] : '未分配部门';
                                    ?>
                                        <span class="badge bg-info-subtle text-info-emphasis me-1 mb-1">
                                            <?php echo htmlspecialchars($name); ?>
                                            <small class="text-muted">(<?php echo htmlspecialchars($department); ?>)</small>
                                        </span>
                                    <?php 
                                        endforeach;
                                    else:
                                    ?>
                                        <span class="text-muted">未指定</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $report['total_passengers']; ?></td>
                                <td>
                                    <?php
                                    $vehicle_type_map = [
                                        'car' => '轿车',
                                        'van' => '商务车',
                                        'minibus' => '中巴车',
                                        'bus' => '大巴车',
                                        'truck' => '货车'
                                    ];
                                    echo $vehicle_type_map[$report['travel_type']] ?? $report['travel_type'];
                                    ?>
                                </td>
                                <td>
                                    <?php if ($report['vehicle_count'] > 0): ?>
                                        <div class="vehicle-info">
                                            <?php 
                                            // 解析车辆分配信息
                                            $fleet_numbers = explode(',', $report['fleet_numbers'] ?? '');
                                            $license_plates = explode(',', $report['license_plates'] ?? '');
                                            $vehicle_models = explode(',', $report['vehicle_models'] ?? '');
                                            $driver_names = explode(',', $report['driver_names'] ?? '');
                                            $driver_phones = explode(',', $report['driver_phones'] ?? '');
                                            
                                            for ($i = 0; $i < count($fleet_numbers); $i++):
                                                $fleet_number = $fleet_numbers[$i] ?? '-';
                                                $license_plate = $license_plates[$i] ?? '-';
                                                $vehicle_model = $vehicle_models[$i] ?? '-';
                                                $driver_name = $driver_names[$i] ?? '未分配';
                                                $driver_phone = $driver_phones[$i] ?? '-';
                                            ?>
                                                <div class="vehicle-item mb-1">
                                                    <span class="badge bg-primary-subtle text-primary-emphasis">
                                                        <?php echo htmlspecialchars($fleet_number); ?>
                                                    </span>
                                                    <small class="d-block text-muted">
                                                        <?php echo htmlspecialchars($license_plate); ?>
                                                        <?php if ($vehicle_model !== '-'): ?>
                                                            (<?php echo htmlspecialchars($vehicle_model); ?>)
                                                        <?php endif; ?>
                                                    </small>
                                                    <?php if ($driver_name !== '未分配'): ?>
                                                        <small class="d-block text-muted">
                                                            司机: <?php echo htmlspecialchars($driver_name); ?>
                                                            <?php if ($driver_phone !== '-'): ?>
                                                                (<?php echo htmlspecialchars($driver_phone); ?>)
                                                            <?php endif; ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endfor; ?>
                                        </div>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('确定要取消车辆分配吗？')">
                                            <input type="hidden" name="action" value="cancel_assignment">
                                            <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger mt-1">
                                                <i class="bi bi-x-circle"></i> 取消分配
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted">未分配</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $status_map[$report['status']]['class']; ?>">
                                        <?php echo $status_map[$report['status']]['label']; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($report['reporter_name']); ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <?php if ($report['status'] === 'pending'): ?>
                                            <button type="button" class="btn btn-outline-success" 
                                                    onclick="updateStatus(<?php echo $report['id']; ?>, 'confirmed')">
                                                <i class="bi bi-check-circle"></i> 确认
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($report['status'] !== 'cancelled'): ?>
                                            <button type="button" class="btn btn-outline-danger" 
                                                    onclick="updateStatus(<?php echo $report['id']; ?>, 'cancelled')">
                                                <i class="bi bi-x-circle"></i> 取消
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-outline-primary" 
                                                onclick="viewDetails(<?php echo $report['id']; ?>)">
                                            <i class="bi bi-eye"></i> 详情
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- 批量操作按钮 -->
            <div class="mt-3">
                <button type="button" class="btn btn-success" id="batchConfirmBtn" disabled 
                        onclick="batchConfirm()">
                    <i class="bi bi-check-all"></i> 批量确认
                </button>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
function toggleAll(source) {
    const checkboxes = document.querySelectorAll('.report-checkbox');
    checkboxes.forEach(checkbox => {
        if (!checkbox.disabled) {
            checkbox.checked = source.checked;
        }
    });
    updateBatchButton();
}

function toggleDate(source, date) {
    const checkboxes = document.querySelectorAll(`.report-checkbox[data-date="${date}"]`);
    checkboxes.forEach(checkbox => {
        if (!checkbox.disabled) {
            checkbox.checked = source.checked;
        }
    });
    updateBatchButton();
}

function updateBatchButton() {
    const checkedBoxes = document.querySelectorAll('.report-checkbox:checked');
    const batchBtn = document.getElementById('batchConfirmBtn');
    batchBtn.disabled = checkedBoxes.length === 0;
}

function updateStatus(id, status) {
    if (confirm('确定要更新状态吗？')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="id" value="${id}">
            <input type="hidden" name="status" value="${status}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function batchConfirm() {
    const checkedBoxes = document.querySelectorAll('.report-checkbox:checked');
    if (checkedBoxes.length === 0) {
        alert('请至少选择一条记录');
        return;
    }
    
    if (confirm(`确定要确认选中的 ${checkedBoxes.length} 条记录吗？`)) {
        const ids = Array.from(checkedBoxes).map(cb => cb.value);
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="batch_confirm">
            <input type="hidden" name="ids" value="${ids.join(',')}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function viewDetails(id) {
    // 这里可以实现查看详情的逻辑，比如打开模态框或跳转到详情页
    alert('查看详情功能待实现，报告ID: ' + id);
}
</script>

<?php include 'includes/footer.php'; ?>