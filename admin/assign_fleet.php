<?php
// 车队分配页面 - 将车辆分配给出行记录
session_start();

// 引入基础控制器进行权限验证
require_once '../includes/BaseAdminController.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// 创建数据库连接
$database = new Database();
$pdo = $database->getConnection();

if ($pdo === null) {
    die("数据库连接失败，请检查配置");
}

// 权限检查
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

// 获取项目ID
$project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

// 获取项目信息
$project = null;
try {
    $stmt = $pdo->prepare("SELECT id, name FROM projects WHERE id = ?");
    $stmt->execute([$project_id]);
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$project) {
        $_SESSION['error'] = '项目不存在';
        header('Location: fleet_management.php');
        exit();
    }
} catch (PDOException $e) {
    $_SESSION['error'] = '获取项目信息失败: ' . $e->getMessage();
    header('Location: fleet_management.php');
    exit();
}

// 获取出行记录ID
$transportation_id = isset($_GET['transportation_id']) ? (int)$_GET['transportation_id'] : 0;

// 获取出行记录信息
$transportation = null;
if ($transportation_id) {
    try {
        $stmt = $pdo->prepare("SELECT tr.*, p.name as project_name 
                             FROM transportation_reports tr 
                             JOIN projects p ON tr.project_id = p.id 
                             WHERE tr.id = ? AND tr.project_id = ?");
        $stmt->execute([$transportation_id, $project_id]);
        $transportation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$transportation) {
            $_SESSION['error'] = '出行记录不存在';
            header('Location: assign_fleet.php?project_id=' . $project_id);
            exit();
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = '获取出行记录失败: ' . $e->getMessage();
        header('Location: assign_fleet.php?project_id=' . $project_id);
        exit();
    }
}

// 处理分配操作
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $transportation_id) {
    $fleet_id = isset($_POST['fleet_id']) ? (int)$_POST['fleet_id'] : 0;
    
    if ($fleet_id) {
        try {
            // 获取出行记录信息
            $stmt = $pdo->prepare("SELECT passenger_count FROM transportation_reports WHERE id = ?");
            $stmt->execute([$transportation_id]);
            $transportation = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$transportation) {
                $_SESSION['error'] = '出行记录不存在';
                header('Location: assign_fleet.php?project_id=' . $project_id . '&transportation_id=' . $transportation_id);
                exit();
            }
            
            $passenger_count = $transportation['passenger_count'];
            
            // 获取车辆座位数
            $stmt = $pdo->prepare("SELECT seats FROM fleet WHERE id = ?");
            $stmt->execute([$fleet_id]);
            $fleet = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$fleet) {
                $_SESSION['error'] = '车辆不存在';
                header('Location: assign_fleet.php?project_id=' . $project_id . '&transportation_id=' . $transportation_id);
                exit();
            }
            
            $vehicle_seats = $fleet['seats'];
            
            // 检查车辆是否已分配给该出行记录
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM transportation_fleet_assignments 
                                 WHERE transportation_report_id = ? AND fleet_id = ?");
            $stmt->execute([$transportation_id, $fleet_id]);
            
            if ($stmt->fetchColumn() > 0) {
                $_SESSION['info'] = '该车辆已分配给此出行记录';
                header('Location: assign_fleet.php?project_id=' . $project_id . '&transportation_id=' . $transportation_id);
                exit();
            }
            
            // 计算已分配车辆的总座位数
            $stmt = $pdo->prepare("SELECT COALESCE(SUM(f.seats), 0) as total_seats 
                                 FROM transportation_fleet_assignments tfa 
                                 JOIN fleet f ON tfa.fleet_id = f.id 
                                 WHERE tfa.transportation_report_id = ?");
            $stmt->execute([$transportation_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $current_total_seats = $result['total_seats'];
            
            // 计算剩余需要分配的乘客数量
            $remaining_passengers = max(0, $passenger_count - $current_total_seats);
            
            // 检查当前车辆是否能满足剩余乘客需求
            if ($vehicle_seats > $remaining_passengers * 2) { // 如果车辆座位数超过剩余乘客数的2倍
                $_SESSION['warning'] = "此车辆座位数({$vehicle_seats})远大于剩余乘客数量({$remaining_passengers})，建议分配更小型车辆";
            } elseif ($vehicle_seats < $remaining_passengers) {
                $_SESSION['info'] = "此车辆座位数({$vehicle_seats})不足以容纳所有剩余乘客({$remaining_passengers})，需要继续分配其他车辆";
            }
            
            // 分配车辆
            $stmt = $pdo->prepare("INSERT INTO transportation_fleet_assignments (transportation_report_id, fleet_id) VALUES (?, ?)");
            $stmt->execute([$transportation_id, $fleet_id]);
            
            $_SESSION['success'] = '车辆分配成功';
            
            header('Location: assign_fleet.php?project_id=' . $project_id . '&transportation_id=' . $transportation_id);
            exit();
        } catch (PDOException $e) {
            $_SESSION['error'] = '分配车辆失败: ' . $e->getMessage();
        }
    }
}

// 处理取消分配操作
if (isset($_GET['remove_fleet']) && $transportation_id) {
    $remove_fleet_id = (int)$_GET['remove_fleet'];
    
    try {
        $stmt = $pdo->prepare("DELETE FROM transportation_fleet_assignments 
                           WHERE transportation_report_id = ? AND fleet_id = ?");
        $stmt->execute([$transportation_id, $remove_fleet_id]);
        
        $_SESSION['success'] = '取消分配成功';
        header('Location: assign_fleet.php?project_id=' . $project_id . '&transportation_id=' . $transportation_id);
        exit();
    } catch (PDOException $e) {
        $_SESSION['error'] = '取消分配失败: ' . $e->getMessage();
    }
}

// 获取筛选条件
$date_filter = isset($_GET['date']) ? $_GET['date'] : '';
$vehicle_type_filter = isset($_GET['vehicle_type']) ? $_GET['vehicle_type'] : '';

// 获取出行记录列表
$transportation_list = [];
try {
    $where_conditions = ["tr.project_id = ?"];
    $params = [$project_id];
    
    if ($date_filter) {
        $where_conditions[] = "tr.travel_date = ?";
        $params[] = $date_filter;
    }
    
    if ($vehicle_type_filter) {
        $where_conditions[] = "tr.travel_type = ?";
        $params[] = $vehicle_type_filter;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $sql = "SELECT tr.*, 
                   GROUP_CONCAT(f.id) as assigned_fleet_ids,
                   GROUP_CONCAT(f.fleet_number) as assigned_fleet_numbers,
                   GROUP_CONCAT(f.license_plate) as assigned_license_plates,
                   GROUP_CONCAT(f.seats) as assigned_seats,
                   GROUP_CONCAT(f.status) as assigned_fleet_statuses,
                   COUNT(f.id) as assigned_vehicle_count,
                   -- 获取乘车人信息，格式为：姓名|部门
                   GROUP_CONCAT(DISTINCT CONCAT(COALESCE(pr_main.name, pr.name), '|', COALESCE(d_main.name, d.name, '未分配部门')) ORDER BY COALESCE(pr_main.name, pr.name)) as personnel_info,
                   (SELECT COUNT(*) FROM transportation_passengers WHERE transportation_report_id = tr.id) + 
                   CASE WHEN tr.personnel_id IS NOT NULL THEN 1 ELSE 0 END as total_passengers
            FROM transportation_reports tr
            LEFT JOIN transportation_fleet_assignments tfa ON tr.id = tfa.transportation_report_id
            LEFT JOIN fleet f ON tfa.fleet_id = f.id
            -- 获取关联人员信息
            LEFT JOIN personnel pr ON tr.personnel_id = pr.id
            LEFT JOIN project_department_personnel pdp ON pdp.personnel_id = pr.id AND pdp.project_id = tr.project_id
            LEFT JOIN departments d ON pdp.department_id = d.id
            -- 获取transportation_passengers表中的乘客信息
            LEFT JOIN transportation_passengers tp ON tr.id = tp.transportation_report_id
            LEFT JOIN personnel pr_main ON tp.personnel_id = pr_main.id
            LEFT JOIN project_department_personnel pdp_main ON pdp_main.personnel_id = pr_main.id AND pdp_main.project_id = tr.project_id
            LEFT JOIN departments d_main ON d_main.id = pdp_main.department_id
            WHERE $where_clause
            GROUP BY tr.id
            ORDER BY tr.travel_date DESC, tr.departure_time DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transportation_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "获取出行记录失败: " . $e->getMessage();
}

// 获取可用车辆列表
$available_fleet = [];
try {
    $stmt = $pdo->prepare("SELECT id, fleet_number, vehicle_type, vehicle_model, license_plate, driver_name, seats 
                         FROM fleet 
                         WHERE project_id = ? AND status = 'active' 
                         ORDER BY fleet_number ASC");
    $stmt->execute([$project_id]);
    $available_fleet = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "获取车辆列表失败: " . $e->getMessage();
}

// 获取所有实际的交通类型（用于动态填充下拉框）
$actual_travel_types = [];
try {
    $stmt = $pdo->prepare("SELECT DISTINCT travel_type FROM transportation_reports WHERE project_id = ? ORDER BY travel_type ASC");
    $stmt->execute([$project_id]);
    $actual_travel_types = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
} catch (PDOException $e) {
    $error = "获取交通类型失败: " . $e->getMessage();
}

// 车辆类型映射
$vehicle_type_map = [
    'car' => '轿车',
    'van' => '商务车',
    'minibus' => '中巴车',
    'bus' => '大巴车',
    'truck' => '货车',
    'other' => '其他'
];

?>

<div class="container-fluid">
    <div class="row">
        <!-- 侧边栏 -->
        <div class="sidebar col-md-3 col-lg-2 d-md-block position-fixed start-0 top-0 h-100" style="width: 250px;">
            <?php require_once 'includes/sidebar.php'; ?>
        </div>
        
        <!-- 主内容区域 -->
        <div class="main-content col-md-9 col-lg-10 ms-auto">
        <!-- 顶部栏 -->
        <div class="d-flex justify-content-between align-items-center mb-4 p-3 bg-white rounded shadow-sm">
            <h1 class="h3 mb-0"><i class="bi bi-truck"></i> 车队分配 - <?php echo htmlspecialchars($project['name']); ?></h1>
            <div>
                <span class="text-muted me-3">
                    <i class="bi bi-person-circle"></i> 管理员: <?php echo htmlspecialchars($_SESSION['admin_username']); ?>
                </span>
                <a href="?logout=1" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-box-arrow-right"></i> 退出登录
                </a>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-truck"></i> 车队分配 - <?php echo htmlspecialchars($project['name']); ?></h5>
                    </div>
                    <div class="card-body">
                    
                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                    <?php endif; ?>
                    
                    <?php if (isset($_SESSION['error'])): ?>
                        <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['info'])): ?>
                        <div class="alert alert-info"><?php echo $_SESSION['info']; unset($_SESSION['info']); ?></div>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['warning'])): ?>
                        <div class="alert alert-warning"><?php echo $_SESSION['warning']; unset($_SESSION['warning']); ?></div>
                    <?php endif; ?>

                    <!-- 筛选表单 -->
                    <form method="GET" class="mb-3">
                        <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
                        <div class="row">
                            <div class="col-md-3">
                                <label>出行日期：</label>
                                <input type="date" name="date" class="form-control" 
                                       value="<?php echo htmlspecialchars($date_filter); ?>"
                                       onchange="this.form.submit()">
                            </div>
                            <div class="col-md-3">
                                <label>车辆类型：</label>
                                <select name="vehicle_type" class="form-control" onchange="this.form.submit()">
                                    <option value="">全部类型</option>
                                    <option value="接站" <?php echo $vehicle_type_filter == '接站' ? 'selected' : ''; ?>>接站</option>
                                    <option value="送站" <?php echo $vehicle_type_filter == '送站' ? 'selected' : ''; ?>>送站</option>
                                    <option value="混合交通安排（自定义）" <?php echo $vehicle_type_filter == '混合交通安排（自定义）' ? 'selected' : ''; ?>>混合交通安排（自定义）</option>
                                </select>
                            </div>
                            <div class="col-md-6 text-right">
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <div>
                                        <a href="assign_fleet.php?project_id=<?php echo $project_id; ?>" 
                                           class="btn btn-secondary">清除筛选</a>
                                        <a href="fleet_management.php?project_id=<?php echo $project_id; ?>" 
                                           class="btn btn-primary">返回车队管理</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>

                    <!-- 出行记录列表 -->
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>出行日期</th>
                                    <th>时间</th>
                                    <th>交通类型</th>
                                    <th>起点</th>
                                    <th>终点</th>
                                    <!-- 新增：乘车人列 -->
                                    <th style="min-width: 350px;">乘车人</th>
                                    <!-- 新增：车辆需求列 -->
                                    <th>车辆需求</th>
                                    <th class="assigned-vehicles-column">已分配车辆</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transportation_list as $transport): ?>
                                    <tr>
                                        <td>
                                            <?php 
                                            // 格式化日期显示，去除年份，只显示月-日
                                            $date = $transport['travel_date'] ?? '';
                                            if ($date && $date !== '-') {
                                                // 处理YYYY-MM-DD格式，只保留MM-DD
                                                if (strlen($date) === 10 && strpos($date, '-') === 4) {
                                                    echo substr($date, 5, 5); // 提取MM-DD部分
                                                } else {
                                                    echo $date;
                                                }
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                            // 格式化时间显示，只显示时和分，去除秒
                                            $time = $transport['departure_time'] ?? '';
                                            if ($time && $time !== '-') {
                                                // 处理HH:MM:SS格式，只保留HH:MM
                                                if (strlen($time) === 8 && strpos($time, ':') === 2) {
                                                    echo substr($time, 0, 5);
                                                } else {
                                                    echo $time;
                                                }
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </td>
                                        <td><?php echo $transport['travel_type']; ?></td>
                                        <td><?php echo htmlspecialchars($transport['departure_location']); ?></td>
                                        <td><?php echo htmlspecialchars($transport['destination_location']); ?></td>
                                        <!-- 新增：乘车人列 - 按transportation_reports.php样式显示 -->
                                        <td>
                                            <?php 
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
                                            
                                            <div class="passenger-tags">
                                                <?php if (!empty($department_groups)): ?>
                                                    <?php foreach ($department_groups as $department => $names): ?>
                                                        <?php $count = count($names); ?>
                                                        <div class="department-group">
                                                            <span class="dept-tag">
                                                                <?= $department ?>
                                                                <span class="count-badge">(<?= $count ?>人)</span>
                                                            </span>
                                                            <span class="names-list"><?= implode('、', $names) ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">无乘车人</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <!-- 新增：车辆需求显示列 -->
                                        <td class="align-middle">
                                            <?php if ($transport['vehicle_requirements']): ?>
                                                <?php 
                                                // 解析车辆需求信息
                                                $decoded = json_decode($transport['vehicle_requirements'], true);
                                                if (is_array($decoded) && !empty($decoded)):
                                                    // 新的JSON格式需求
                                                    foreach ($decoded as $vehicle_type => $vehicle_info):
                                                        if (isset($vehicle_type_map[$vehicle_type]) && isset($vehicle_info['quantity']) && $vehicle_info['quantity'] > 0):
                                                            $display_name = $vehicle_type_map[$vehicle_type];
                                                            $quantity = intval($vehicle_info['quantity']);
                                                ?>
                                                            <span class="badge bg-info me-1 mb-1"><?php echo htmlspecialchars($display_name); ?> x<?php echo $quantity; ?></span>
                                                <?php 
                                                        endif;
                                                    endforeach;
                                                else:
                                                    // 兼容旧格式
                                                    echo '<span class="badge bg-secondary me-1 mb-1">' . htmlspecialchars(trim($transport['vehicle_requirements'])) . '</span>';
                                                endif;
                                            else: 
                                            ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="assigned-vehicles-column align-middle">
                                            <?php if ($transport['assigned_fleet_numbers']): ?>
                                                <?php 
                                                // 解析已分配车辆信息 - 去重显示
                                                $fleet_numbers = explode(',', $transport['assigned_fleet_numbers']); 
                                                $license_plates = explode(',', $transport['assigned_license_plates']); 
                                                $fleet_statuses = explode(',', $transport['assigned_fleet_statuses']); 
                                                
                                                // 创建唯一车辆标识数组，避免重复
                                                $unique_vehicles = [];
                                                foreach ($fleet_numbers as $i => $fleet_number) {
                                                    $key = $fleet_number . '_' . $license_plates[$i]; // 使用车牌号+车辆编号作为唯一键
                                                    if (!isset($unique_vehicles[$key])) {
                                                        $unique_vehicles[$key] = [
                                                            'fleet_number' => $fleet_number,
                                                            'license_plate' => $license_plates[$i],
                                                            'status' => $fleet_statuses[$i] ?? 'available'
                                                        ];
                                                    }
                                                }
                                                ?>
                                                <?php foreach ($unique_vehicles as $vehicle): ?>
                                                    <?php 
                                                    // 检查车辆状态是否为维修或停用
                                                    $is_problematic = in_array($vehicle['status'], ['maintenance', 'inactive', 'disabled']);
                                                    $span_class = $is_problematic ? 'badge bg-danger text-white' : 'badge bg-primary text-white';
                                                    ?>
                                                    <span class="<?php echo $span_class; ?> me-1 mb-1 d-inline-block">
                                                        <?php echo htmlspecialchars($vehicle['fleet_number']); ?>
                                                        (<?php echo htmlspecialchars($vehicle['license_plate']); ?>)
                                                        <?php if ($is_problematic): ?>
                                                            <i class="bi bi-exclamation-triangle-fill ms-1" title="车辆状态: <?php echo htmlspecialchars($vehicle['status']); ?>"></i>
                                                        <?php endif; ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="text-muted">未分配</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($transportation_id == $transport['id']): ?>
                                                <form method="POST" class="form-inline">
                                                    <input type="hidden" name="transportation_id" value="<?php echo $transport['id']; ?>">
                                                    <select name="fleet_id" class="form-control form-control-sm mr-2" required>
                                                        <option value="">选择车辆</option>
                                                        <?php foreach ($available_fleet as $fleet): ?>
                                                            <option value="<?php echo $fleet['id']; ?>">
                                                                <?php echo htmlspecialchars($fleet['fleet_number']); ?> - 
                                                                <?php echo htmlspecialchars($fleet['license_plate']); ?> - 
                                                                <?php echo htmlspecialchars($fleet['driver_name'] ?? '无司机'); ?> - 
                                                                <?php echo htmlspecialchars($fleet['seats']); ?>座
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" class="btn btn-sm btn-success">分配</button>
                                                </form>
                                            <?php else: ?>
                                                <a href="assign_fleet.php?project_id=<?php echo $project_id; ?>&transportation_id=<?php echo $transport['id']; ?>" 
                                                   class="btn btn-sm btn-success">分配车辆</a>
                                            <?php endif; ?>
                                            
                                            <?php if ($transport['assigned_fleet_numbers'] && $transport['assigned_fleet_ids']): ?>
                                                <br>
                                                <?php 
                                                // 解析已分配车辆信息 - 去重显示取消按钮
                                                $fleet_ids = explode(',', $transport['assigned_fleet_ids']); 
                                                $fleet_numbers = explode(',', $transport['assigned_fleet_numbers']); 
                                                $license_plates = explode(',', $transport['assigned_license_plates']); 
                                                $fleet_statuses = explode(',', $transport['assigned_fleet_statuses']); 
                                                
                                                // 创建唯一车辆标识数组，避免重复
                                                $unique_vehicles = [];
                                                foreach ($fleet_ids as $i => $fleet_id) {
                                                    if (isset($fleet_numbers[$i]) && isset($license_plates[$i])) {
                                                        $key = $fleet_numbers[$i] . '_' . $license_plates[$i]; // 使用车牌号+车辆编号作为唯一键
                                                        if (!isset($unique_vehicles[$key])) {
                                                            $unique_vehicles[$key] = [
                                                                'id' => $fleet_id,
                                                                'fleet_number' => $fleet_numbers[$i],
                                                                'license_plate' => $license_plates[$i],
                                                                'status' => $fleet_statuses[$i] ?? 'available'
                                                            ];
                                                        }
                                                    }
                                                }
                                                ?>
                                                <div class="btn-group-vertical">
                                                <?php foreach ($unique_vehicles as $vehicle): ?>
                                                    <?php 
                                                    $is_problematic = in_array($vehicle['status'], ['maintenance', 'inactive', 'disabled']);
                                                    $btn_class = $is_problematic ? 'btn-danger' : 'btn-outline-danger';
                                                    ?>
                                                    <a href="assign_fleet.php?project_id=<?php echo $project_id; ?>&transportation_id=<?php echo $transport['id']; ?>&remove_fleet=<?php echo $vehicle['id']; ?>" 
                                                       class="btn btn-sm <?php echo $btn_class; ?> mb-1"
                                                       onclick="return confirm('确定要取消分配车辆 <?php echo htmlspecialchars($vehicle['fleet_number']); ?> (<?php echo htmlspecialchars($vehicle['license_plate']); ?>) 吗？<?php echo $is_problematic ? '注意：该车辆当前状态为' . htmlspecialchars($vehicle['status']) : ''; ?>')">
                                                        取消 <?php echo htmlspecialchars($vehicle['fleet_number']); ?>
                                                        <?php if ($is_problematic): ?>
                                                            <i class="bi bi-exclamation-triangle-fill ms-1"></i>
                                                        <?php endif; ?>
                                                    </a>
                                                <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
/* 基础布局重置 */
html, body {
    height: 100%;
    margin: 0;
    padding: 0;
}

/* 主页面布局 */
body {
    background-color: #f8f9fa;
    min-height: 100vh;
}

/* 主容器 */
.container-fluid {
    padding: 0;
}

/* 侧边栏固定定位 */
.sidebar {
    background-color: #ffffff;
    box-shadow: 0 0 10px rgba(0,0,0,0.1);
    z-index: 1000;
    position: fixed;
    left: 0;
    top: 0;
    width: 250px;
    height: 100vh;
    overflow-y: auto;
}

/* 主内容区域 */
.main-content {
    margin-left: 250px;
    padding: 20px;
    min-height: 100vh;
    position: relative;
}

/* Footer样式 - 确保显示在页面底部 */
footer {
    width: 100%;
    background-color: #f8f9fa;
    padding: 20px 0;
    text-align: center;
    position: relative;
    z-index: 100;
}

/* 响应式调整 */
@media (max-width: 768px) {
    .sidebar {
        width: 100%;
        position: relative;
        height: auto;
    }
    .main-content {
        margin-left: 0;
        padding: 15px;
        min-height: auto;
    }
}

/* 已分配车辆列样式优化 - 去重后显示 */
.assigned-vehicles-column {
    font-size: 0.95em;
}

.assigned-vehicles-column .badge {
    font-size: 0.85em;
    margin-bottom: 0.15rem;
    display: inline-block;
}

/* 垂直按钮组样式优化 */
.btn-group-vertical {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.btn-group-vertical .btn {
    width: 100%;
    text-align: left;
    padding: 0.375rem 0.75rem;
    font-size: 0.875rem;
}

/* 乘车人信息样式 - 与transportation_reports.php保持一致 */
.passenger-tags {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.department-group {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 2px 0;
}

.dept-tag {
    display: inline-flex;
    align-items: center;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    white-space: nowrap;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
    margin-bottom: 2px;
    width: fit-content;
}

.count-badge {
    background: rgba(255, 255, 255, 0.2);
    padding: 1px 6px;
    border-radius: 8px;
    margin-left: 4px;
    font-size: 0.7rem;
    font-weight: 500;
}

.names-list {
    font-size: 0.85rem;
    color: #495057;
    line-height: 1.4;
    margin-left: 8px;
    word-wrap: break-word;
}

/* 响应式调整 */
@media (max-width: 768px) {
    .passenger-tags {
        gap: 2px;
    }
    
    .dept-tag {
        font-size: 0.7rem;
        padding: 1px 6px;
    }
    
    .names-list {
        font-size: 0.8rem;
        margin-left: 4px;
    }
}
</style>

<?php include 'includes/footer.php'; ?>