<?php
// 编辑酒店报告分组记录页面

session_start();

// 引入基础控制器进行权限验证
require_once '../includes/BaseAdminController.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db.php';

// 权限检查
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// 获取记录ID（支持多个ID，逗号分隔）
$record_ids = isset($_GET['ids']) ? $_GET['ids'] : '';
$ids_array = explode(',', $record_ids);

// 验证ID
if (empty($record_ids) || empty($ids_array)) {
    $_SESSION['error'] = '请选择要编辑的记录';
    header('Location: hotel_reports_new.php');
    exit;
}

$pdo = get_db_connection();

// 获取记录详情
$placeholders = implode(',', array_fill(0, count($ids_array), '?'));
$sql = "
    SELECT hr.*, p.name as project_name, per.name as personnel_name 
    FROM hotel_reports hr
    LEFT JOIN projects p ON hr.project_id = p.id
    LEFT JOIN personnel per ON hr.personnel_id = per.id
    WHERE hr.id IN ($placeholders)
    ORDER BY hr.check_in_date DESC, p.name, hr.hotel_name
";

$stmt = $pdo->prepare($sql);
$stmt->execute($ids_array);
$all_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 将记录按共享房间分组，合并具有相同关键信息的记录
$grouped_reports = [];
$ungrouped_reports = [];

foreach ($all_reports as $report) {
    // 检查是否为可以共享的房型且有共享房间信息
    // 根据规范，双床房、双人房、套房、大床房、总统套房和副总统套房都可以共享
    $shareable_room_types = ['双床房', '双人房', '套房', '大床房', '总统套房', '副总统套房'];
    
    if (in_array($report['room_type'], $shareable_room_types) && !empty($report['shared_room_info'])) {
        // 创建分组键，包含所有需要相同的字段
        $key = $report['project_id'] . '|' . 
               $report['hotel_name'] . '|' . 
               $report['check_in_date'] . '|' . 
               $report['check_out_date'] . '|' . 
               $report['room_type'] . '|' . 
               $report['room_count'] . '|' . 
               $report['shared_room_info'] . '|' . 
               $report['special_requirements'] . '|' . 
               ($report['room_number'] ?? '');
        
        if (!isset($grouped_reports[$key])) {
            $grouped_reports[$key] = [
                'is_grouped' => true,
                'group_key' => $key,
                'project_id' => $report['project_id'],
                'project_name' => $report['project_name'],
                'hotel_name' => $report['hotel_name'],
                'check_in_date' => $report['check_in_date'],
                'check_out_date' => $report['check_out_date'],
                'room_type' => $report['room_type'],
                'room_count' => $report['room_count'],
                'shared_room_info' => $report['shared_room_info'],
                'special_requirements' => $report['special_requirements'],
                'room_number' => $report['room_number'] ?? '',
                'status' => $report['status'],
                'records' => []
            ];
        }
        
        $grouped_reports[$key]['records'][] = $report;
        
    } else {
        // 非共享房间或不支持共享的房型单独处理
        $report['is_grouped'] = false;
        $ungrouped_reports[] = $report;
    }
}

// 合并分组和未分组的记录
$reports = [];
foreach ($grouped_reports as $group) {
    $reports[] = $group;
}
$reports = array_merge($reports, $ungrouped_reports);

if (empty($reports)) {
    $_SESSION['error'] = '未找到要编辑的记录';
    header('Location: hotel_reports_new.php');
    exit;
}

// 获取项目列表
$projects = $pdo->query("SELECT id, name FROM projects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// 获取当前记录涉及的项目ID和酒店名称
$current_project_ids = array_unique(array_column($all_reports, 'project_id'));
$current_hotel_names = array_unique(array_column($all_reports, 'hotel_name'));

// 获取当前项目的人员列表（只显示当前项目的人员）
$personnel = [];
if (!empty($current_project_ids)) {
    $project_placeholders = implode(',', array_fill(0, count($current_project_ids), '?'));
    $personnel_sql = "
        SELECT DISTINCT p.id, p.name, d.name as department_name 
        FROM personnel p 
        LEFT JOIN departments d ON p.department_id = d.id 
        LEFT JOIN project_department_personnel pdp ON p.id = pdp.personnel_id
        WHERE pdp.project_id IN ($project_placeholders)
        ORDER BY d.sort_order ASC, d.name ASC, p.name ASC
    ";
    $stmt = $pdo->prepare($personnel_sql);
    $stmt->execute($current_project_ids);
    $personnel = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 获取当前酒店可用的房型（只显示当前酒店的房型）
$available_room_types = [];
if (!empty($current_hotel_names)) {
    $hotel_placeholders = implode(',', array_fill(0, count($current_hotel_names), '?'));
    $hotel_sql = "
        SELECT hotel_name_cn, room_types 
        FROM hotels 
        WHERE hotel_name_cn IN ($hotel_placeholders)
    ";
    $stmt = $pdo->prepare($hotel_sql);
    $stmt->execute($current_hotel_names);
    $hotel_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 合并所有酒店的房型
    foreach ($hotel_data as $hotel) {
        if (!empty($hotel['room_types'])) {
            $room_types = explode(',', $hotel['room_types']);
            foreach ($room_types as $type) {
                $type = trim($type);
                // 清理房型名称，移除可能的标点符号和特殊字符
                $type = preg_replace('/[^\x{4e00}-\x{9fa5}a-zA-Z0-9\s]/u', '', $type);
                $type = trim($type);
                if (!empty($type) && !in_array($type, $available_room_types)) {
                    $available_room_types[] = $type;
                }
            }
        }
    }
}

// 如果没有找到酒店房型数据，使用默认房型
if (empty($available_room_types)) {
    $available_room_types = ['大床房', '双床房', '套房', '单人房', '总统套房', '副总统套房'];
}

// 获取酒店列表
$hotels = $pdo->query("SELECT id, hotel_name_cn, hotel_name_en, room_types FROM hotels ORDER BY hotel_name_cn")->fetchAll(PDO::FETCH_ASSOC);

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        $updated_count = 0;
        
        // 处理合并的共享房间
        if (isset($_POST['group_ids']) && is_array($_POST['group_ids'])) {
            foreach ($_POST['group_ids'] as $index => $ids_string) {
                $ids = explode(',', $ids_string);
                
                $project_id = $_POST["project_id_$index"] ?? '';
                $hotel_name = $_POST["hotel_name_$index"] ?? '';
                $room_type = $_POST["room_type_$index"] ?? '';
                $check_in_date = $_POST["check_in_date_$index"] ?? '';
                $check_out_date = $_POST["check_out_date_$index"] ?? '';
                $room_count = $_POST["room_count_$index"] ?? 1;
                $status = $_POST["status_$index"] ?? 'pending';
                $shared_room_info = $_POST["shared_room_info_$index"] ?? '';
                $special_requirements = $_POST["special_requirements_$index"] ?? '';
                $room_number = $_POST["room_number_$index"] ?? '';
                
                // 获取原始项目ID用于重定向
                $original_project_id = $_POST["original_project_id_$index"] ?? $project_id;
                
                // 验证数据
                if (empty($project_id) || empty($hotel_name) || 
                    empty($room_type) || empty($check_in_date) || empty($check_out_date)) {
                    continue;
                }
                
                // 更新所有相关记录（保持人员信息不变，但更新其他共享信息）
                foreach ($ids as $id) {
                    $id = intval($id);
                    
                    $update_sql = "
                        UPDATE hotel_reports SET
                            project_id = :project_id,
                            hotel_name = :hotel_name,
                            room_type = :room_type,
                            check_in_date = :check_in_date,
                            check_out_date = :check_out_date,
                            room_count = :room_count,
                            shared_room_info = :shared_room_info,
                            special_requirements = :special_requirements,
                            room_number = :room_number,
                            status = :status,
                            updated_at = NOW()
                        WHERE id = :id
                    ";
                    
                    $stmt = $pdo->prepare($update_sql);
                    $stmt->execute([
                        ':project_id' => $project_id,
                        ':hotel_name' => $hotel_name,
                        ':room_type' => $room_type,
                        ':check_in_date' => $check_in_date,
                        ':check_out_date' => $check_out_date,
                        ':room_count' => $room_count,
                        ':shared_room_info' => $shared_room_info,
                        ':special_requirements' => $special_requirements,
                        ':room_number' => $room_number,
                        ':status' => $status,
                        ':id' => $id
                    ]);
                    
                    if ($stmt->rowCount() > 0) {
                        $updated_count++;
                    }
                }
            }
        }
        
        // 处理单个记录
        if (isset($_POST['single_ids']) && is_array($_POST['single_ids'])) {
            foreach ($_POST['single_ids'] as $id) {
                $id = intval($id);
                
                $project_id = $_POST["project_id_single_$id"] ?? '';
                $personnel_id = $_POST["personnel_id_single_$id"] ?? '';
                $hotel_name = $_POST["hotel_name_single_$id"] ?? '';
                $room_type = $_POST["room_type_single_$id"] ?? '';
                $check_in_date = $_POST["check_in_date_single_$id"] ?? '';
                $check_out_date = $_POST["check_out_date_single_$id"] ?? '';
                $room_count = $_POST["room_count_single_$id"] ?? 1;
                $status = $_POST["status_single_$id"] ?? 'pending';
                $shared_room_info = $_POST["shared_room_info_single_$id"] ?? '';
                $special_requirements = $_POST["special_requirements_single_$id"] ?? '';
                $room_number = $_POST["room_number_single_$id"] ?? '';
                
                // 获取原始项目ID用于重定向
                $original_project_id = $project_id; // 对于单个记录，直接使用当前项目ID
                
                // 验证数据
                if (empty($project_id) || empty($personnel_id) || empty($hotel_name) || 
                    empty($room_type) || empty($check_in_date) || empty($check_out_date)) {
                    continue;
                }
                
                $update_sql = "
                    UPDATE hotel_reports SET
                        project_id = :project_id,
                        personnel_id = :personnel_id,
                        hotel_name = :hotel_name,
                        room_type = :room_type,
                        check_in_date = :check_in_date,
                        check_out_date = :check_out_date,
                        room_count = :room_count,
                        shared_room_info = :shared_room_info,
                        special_requirements = :special_requirements,
                        room_number = :room_number,
                        status = :status,
                        updated_at = NOW()
                    WHERE id = :id
                ";
                
                $stmt = $pdo->prepare($update_sql);
                $stmt->execute([
                    ':project_id' => $project_id,
                    ':personnel_id' => $personnel_id,
                    ':hotel_name' => $hotel_name,
                    ':room_type' => $room_type,
                    ':check_in_date' => $check_in_date,
                    ':check_out_date' => $check_out_date,
                    ':room_count' => $room_count,
                    ':shared_room_info' => $shared_room_info,
                    ':special_requirements' => $special_requirements,
                    ':room_number' => $room_number,
                    ':status' => $status,
                    ':id' => $id
                ]);
                
                if ($stmt->rowCount() > 0) {
                    $updated_count++;
                }
            }
        }
        
        $pdo->commit();
        
        $_SESSION['success'] = "成功更新 {$updated_count} 条记录";
        
        // 确定重定向的项目ID
        $redirect_project_id = '';
        
        // 优先使用POST数据中的项目ID
        if (isset($_POST['group_ids']) && !empty($_POST['group_ids'])) {
            // 使用第一个分组记录的原始项目ID
            $first_index = 0;
            if (isset($_POST["original_project_id_$first_index"])) {
                $redirect_project_id = $_POST["original_project_id_$first_index"];
            } elseif (isset($_POST["project_id_$first_index"])) {
                $redirect_project_id = $_POST["project_id_$first_index"];
            }
        } elseif (isset($_POST['single_ids']) && !empty($_POST['single_ids'])) {
            // 使用第一个单个记录的原始项目ID
            $first_id = $_POST['single_ids'][0];
            if (isset($_POST["original_project_id_single_$first_id"])) {
                $redirect_project_id = $_POST["original_project_id_single_$first_id"];
            } elseif (isset($_POST["project_id_single_$first_id"])) {
                $redirect_project_id = $_POST["project_id_single_$first_id"];
            }
        }
        
        // 如果仍然没有找到项目ID，使用当前记录的项目ID
        if (empty($redirect_project_id) && !empty($reports)) {
            $redirect_project_id = $reports[0]['project_id'];
        }
        
        // 执行重定向 - 根据用户偏好返回对应的项目，保持项目上下文
        if (!empty($redirect_project_id)) {
            header("Location: hotel_reports_new.php?project_id={$redirect_project_id}");
        } else {
            header('Location: hotel_reports_new.php');
        }
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = '更新失败：' . $e->getMessage();
    }
}

// 状态映射
$statusMap = [
    'pending' => '待确认',
    'confirmed' => '已确认',
    'cancelled' => '已取消'
];

// 使用从数据库获取的可用房型
$roomTypes = $available_room_types;
// 设置页面标题
$page_title = '编辑酒店报告';

// 引入标准头部
// 权限验证
$database = new Database();
$db = $database->getConnection();
$middleware = new PermissionMiddleware($db);
$middleware->checkAdminPagePermission('backend:system:config');

require_once 'includes/header.php';
?>

<!-- 页面内容开始 -->
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h2><i class="bi bi-pencil-square"></i> 编辑酒店报告</h2>
                        <a href="hotel_reports_new.php<?php 
                            // 确定重定向的项目ID
                            $redirect_project_id = '';
                            if (!empty($reports)) {
                                // 使用第一个记录的项目ID
                                $redirect_project_id = $reports[0]['project_id'];
                            }
                            echo !empty($redirect_project_id) ? '?project_id=' . $redirect_project_id : '';
                        ?>" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> 返回列表
                        </a>
                    </div>
                    
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" id="editForm">
                        <!-- 显示当前限制信息 -->
                        <div class="alert alert-info mb-4">
                            <i class="bi bi-info-circle"></i>
                            <strong>编辑范围限制：</strong>
                            <ul class="mb-0 mt-2">
                                <li><strong>入住人员：</strong>只显示当前项目（<?php echo implode('、', array_unique(array_column($all_reports, 'project_name'))); ?>）的人员，共 <?php echo count($personnel); ?> 人</li>
                                <li><strong>房型选择：</strong>只显示当前酒店（<?php echo implode('、', array_unique($current_hotel_names)); ?>）可用的房型：<?php echo implode('、', $roomTypes); ?></li>
                            </ul>
                        </div>
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">
                                    <i class="bi bi-hotel"></i>
                                    共 <?php echo count($reports); ?> 个房间待编辑
                                    <small class="text-light">(已合并共享房间记录)</small>
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php foreach ($reports as $index => $report): ?>
                                    <?php if ($report['is_grouped']): ?>
                                        <!-- 合并的共享房间 -->
                                        <div class="record-card border-warning">
                                            <div class="record-header bg-warning bg-opacity-25">
                                                <i class="bi bi-people-fill text-warning"></i>
                                                合并房间 #<?php echo $index + 1; ?> 
                                                <span class="badge bg-warning text-dark">共享房间</span>
                                                <small class="text-muted">包含 <?php echo count($report['records']); ?> 条记录</small>
                                            </div>
                                            <div class="record-body">
                                                <!-- 隐藏字段：记录所有相关的记录ID -->
                                                <input type="hidden" name="group_ids[]" value="<?php echo implode(',', array_column($report['records'], 'id')); ?>">
                                                
                                                <div class="row">
                                                    <div class="col-md-3">
                                                        <label class="form-label">项目</label>
                                                        <input type="text" class="form-control" 
                                                               value="<?php echo htmlspecialchars($report['project_name']); ?>" 
                                                               readonly>
                                                        <input type="hidden" name="project_id_<?php echo $index; ?>" value="<?php echo $report['project_id']; ?>">
                                                        <!-- 添加隐藏字段保存原始项目ID，用于重定向 -->
                                                        <input type="hidden" name="original_project_id_<?php echo $index; ?>" value="<?php echo $report['project_id']; ?>">
                                                    </div>
                                                    
                                                    <div class="col-md-3">
                                                        <label class="form-label">酒店名称</label>
                                                        <input type="text" class="form-control" 
                                                               value="<?php echo htmlspecialchars($report['hotel_name']); ?>" 
                                                               readonly>
                                                        <input type="hidden" name="hotel_name_<?php echo $index; ?>" value="<?php echo htmlspecialchars($report['hotel_name']); ?>">
                                                    </div>
                                                    
                                                    <div class="col-md-6">
                                                        <label class="form-label">入住人员</label>
                                                        <div class="alert alert-info">
                                                            <i class="bi bi-info-circle"></i>
                                                            以下人员共享此房间：
                                                            <ul class="mb-0 mt-2">
                                                                <?php foreach ($report['records'] as $rec): ?>
                                                                    <li><?php echo htmlspecialchars($rec['personnel_name']); ?> (记录#<?php echo $rec['id']; ?>)</li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <div class="row mt-3">
                                                    <div class="col-md-3">
                                                        <label class="form-label">房型</label>
                                                        <select class="form-select" name="room_type_<?php echo $index; ?>" required>
                                                            <?php foreach ($roomTypes as $type): ?>
                                                                <option value="<?php echo $type; ?>"
                                                                    <?php echo $type == $report['room_type'] ? 'selected' : ''; ?>>
                                                                    <?php echo $type; ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    
                                                    <div class="col-md-3">
                                                        <label class="form-label">入住日期</label>
                                                        <input type="date" class="form-control" 
                                                               name="check_in_date_<?php echo $index; ?>"
                                                               value="<?php echo $report['check_in_date']; ?>" 
                                                               required>
                                                    </div>
                                                    
                                                    <div class="col-md-3">
                                                        <label class="form-label">退房日期</label>
                                                        <input type="date" class="form-control" 
                                                               name="check_out_date_<?php echo $index; ?>"
                                                               value="<?php echo $report['check_out_date']; ?>" 
                                                               required>
                                                    </div>
                                                    
                                                    <div class="col-md-3">
                                                        <label class="form-label">房间数</label>
                                                        <input type="number" class="form-control" 
                                                               value="<?php echo $report['room_count']; ?>" 
                                                               readonly>
                                                        <input type="hidden" name="room_count_<?php echo $index; ?>" value="<?php echo $report['room_count']; ?>">
                                                    </div>
                                                </div>
                                                
                                                <div class="row mt-3">
                                                    <div class="col-md-4">
                                                        <label class="form-label">共享房间信息</label>
                                                        <input type="text" class="form-control" 
                                                               name="shared_room_info_<?php echo $index; ?>"
                                                               value="<?php echo htmlspecialchars($report['shared_room_info']); ?>"
                                                               placeholder="如：与XXX共享">
                                                    </div>
                                                    
                                                    <div class="col-md-4">
                                                        <label class="form-label">特殊要求</label>
                                                        <input type="text" class="form-control" 
                                                               name="special_requirements_<?php echo $index; ?>"
                                                               value="<?php echo htmlspecialchars($report['special_requirements']); ?>"
                                                               placeholder="如无烟房、高楼层等">
                                                    </div>
                                                    
                                                    <div class="col-md-4">
                                                        <label class="form-label">房号</label>
                                                        <input type="text" class="form-control" 
                                                               name="room_number_<?php echo $index; ?>"
                                                               value="<?php echo htmlspecialchars($report['room_number']); ?>"
                                                               placeholder="请输入房号">
                                                    </div>
                                                </div>
                                                
                                                <div class="row mt-3">
                                                    <div class="col-md-3">
                                                        <label class="form-label">状态</label>
                                                        <select class="form-select" name="status_<?php echo $index; ?>">
                                                            <?php foreach ($statusMap as $value => $label): ?>
                                                                <option value="<?php echo $value; ?>"
                                                                    <?php echo $value == $report['status'] ? 'selected' : ''; ?>>
                                                                    <?php echo $label; ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <!-- 单个记录 -->
                                        <div class="record-card">
                                            <div class="record-header">
                                                <i class="bi bi-hash"></i>
                                                记录 #<?php echo $report['id']; ?>
                                                - <?php echo htmlspecialchars($report['project_name']); ?>
                                                - <?php echo htmlspecialchars($report['personnel_name']); ?>
                                            </div>
                                            <div class="record-body">
                                                <input type="hidden" name="single_ids[]" value="<?php echo $report['id']; ?>">
                                                
                                                <div class="row">
                                                    <div class="col-md-3">
                                                        <label class="form-label">项目</label>
                                                        <input type="text" class="form-control" 
                                                               value="<?php echo htmlspecialchars($report['project_name']); ?>" 
                                                               readonly>
                                                        <input type="hidden" name="project_id_single_<?php echo $report['id']; ?>" value="<?php echo $report['project_id']; ?>">
                                                        <!-- 添加隐藏字段保存原始项目ID，用于重定向 -->
                                                        <input type="hidden" name="original_project_id_single_<?php echo $report['id']; ?>" value="<?php echo $report['project_id']; ?>">
                                                    </div>
                                                    
                                                    <div class="col-md-3">
                                                        <label class="form-label">酒店名称</label>
                                                        <input type="text" class="form-control" 
                                                               value="<?php echo htmlspecialchars($report['hotel_name']); ?>" 
                                                               readonly>
                                                        <input type="hidden" name="hotel_name_single_<?php echo $report['id']; ?>" value="<?php echo htmlspecialchars($report['hotel_name']); ?>">
                                                    </div>
                                                    
                                                    <div class="col-md-3">
                                                        <label class="form-label">入住人</label>
                                                        <select class="form-select" name="personnel_id_single_<?php echo $report['id']; ?>" required>
                                                            <?php foreach ($personnel as $person): ?>
                                                                <option value="<?php echo $person['id']; ?>"
                                                                    <?php echo $person['id'] == $report['personnel_id'] ? 'selected' : ''; ?>>
                                                                    <?php echo htmlspecialchars($person['name']); ?>
                                                                    <?php if (!empty($person['department_name'])): ?>
                                                                        - <?php echo htmlspecialchars($person['department_name']); ?>
                                                                    <?php endif; ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    
                                                    <div class="col-md-3">
                                                        <label class="form-label">房型</label>
                                                        <select class="form-select" name="room_type_single_<?php echo $report['id']; ?>" required>
                                                            <?php foreach ($roomTypes as $type): ?>
                                                                <option value="<?php echo $type; ?>"
                                                                    <?php echo $type == $report['room_type'] ? 'selected' : ''; ?>>
                                                                    <?php echo $type; ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                
                                                <div class="row mt-3">
                                                    <div class="col-md-3">
                                                        <label class="form-label">入住日期</label>
                                                        <input type="date" class="form-control" 
                                                               name="check_in_date_single_<?php echo $report['id']; ?>"
                                                               value="<?php echo $report['check_in_date']; ?>" 
                                                               required>
                                                    </div>
                                                    
                                                    <div class="col-md-3">
                                                        <label class="form-label">退房日期</label>
                                                        <input type="date" class="form-control" 
                                                               name="check_out_date_single_<?php echo $report['id']; ?>"
                                                               value="<?php echo $report['check_out_date']; ?>" 
                                                               required>
                                                    </div>
                                                    
                                                    <div class="col-md-2">
                                                        <label class="form-label">房间数</label>
                                                        <input type="number" class="form-control" 
                                                               value="<?php echo $report['room_count']; ?>" 
                                                               readonly>
                                                        <input type="hidden" name="room_count_single_<?php echo $report['id']; ?>" value="<?php echo $report['room_count']; ?>">
                                                    </div>
                                                    
                                                    <div class="col-md-2">
                                                        <label class="form-label">状态</label>
                                                        <select class="form-select" name="status_single_<?php echo $report['id']; ?>">
                                                            <?php foreach ($statusMap as $value => $label): ?>
                                                                <option value="<?php echo $value; ?>"
                                                                    <?php echo $value == $report['status'] ? 'selected' : ''; ?>>
                                                                    <?php echo $label; ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    
                                                    <div class="col-md-4">
                                                        <label class="form-label">共享房间信息</label>
                                                        <input type="text" class="form-control" 
                                                               name="shared_room_info_single_<?php echo $report['id']; ?>"
                                                               value="<?php echo htmlspecialchars($report['shared_room_info']); ?>"
                                                               placeholder="如：与XXX共享">
                                                    </div>
                                                </div>
                                                
                                                <div class="row mt-3">
                                                    <div class="col-md-6">
                                                        <label class="form-label">特殊要求</label>
                                                        <input type="text" class="form-control" 
                                                               name="special_requirements_single_<?php echo $report['id']; ?>"
                                                               value="<?php echo htmlspecialchars($report['special_requirements']); ?>"
                                                               placeholder="如无烟房、高楼层等">
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">房号</label>
                                                        <input type="text" class="form-control" 
                                                               name="room_number_single_<?php echo $report['id']; ?>"
                                                               value="<?php echo htmlspecialchars($report['room_number'] ?? ''); ?>"
                                                               placeholder="请输入房号">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="btn-group-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> 保存所有修改
                            </button>
                            <a href="hotel_reports_new.php<?php 
                                // 确定重定向的项目ID
                                $redirect_project_id = '';
                                if (!empty($reports)) {
                                    // 使用第一个记录的项目ID
                                    $redirect_project_id = $reports[0]['project_id'];
                                }
                                echo !empty($redirect_project_id) ? '?project_id=' . $redirect_project_id : '';
                            ?>" class="btn btn-secondary">
                                <i class="bi bi-x-circle"></i> 取消
                            </a>
                        </div>
                    </form></div>
                </div>
            </div>
        </div>
    </div>
</div><!-- 容器结束 -->

    <script>
        // 表单验证
        document.getElementById('editForm').addEventListener('submit', function(e) {
            const forms = this.querySelectorAll('input[required], select[required]');
            let isValid = true;
            
            forms.forEach(function(input) {
                if (!input.value.trim()) {
                    input.classList.add('is-invalid');
                    isValid = false;
                } else {
                    input.classList.remove('is-invalid');
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                alert('请填写所有必填字段');
            }
        });
        
        // 实时验证
        document.querySelectorAll('input[required], select[required]').forEach(function(input) {
            input.addEventListener('blur', function() {
                if (!this.value.trim()) {
                    this.classList.add('is-invalid');
                } else {
                    this.classList.remove('is-invalid');
                }
            });
        });
    </script>
    
    <style>
        /* 编辑表单样式优化 */
        .record-card {
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            background: #ffffff;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        
        .record-card.border-warning {
            border-color: #ffc107;
        }
        
        .record-header {
            background-color: #f8f9fa;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #dee2e6;
            border-radius: 0.5rem 0.5rem 0 0;
            font-weight: 600;
            color: #495057;
        }
        
        .record-header.bg-warning.bg-opacity-25 {
            background-color: rgba(255, 193, 7, 0.25) !important;
        }
        
        .record-body {
            padding: 1rem;
        }
        
        .btn-group-actions {
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid #dee2e6;
            text-align: center;
        }
        
        .btn-group-actions .btn {
            margin: 0 0.5rem;
        }
        
        /* 限制信息提示样式 */
        .alert-info {
            border-left: 4px solid #0dcaf0;
            background-color: #e7f3ff;
        }
        
        .alert-info .bi-info-circle {
            color: #0dcaf0;
            font-size: 1.2rem;
        }
        
        .alert-info strong {
            color: #055160;
        }
        
        .alert-info ul li {
            margin-bottom: 0.5rem;
        }
        
        .alert-info ul li strong {
            color: #0a58ca;
        }
    </style>

<!-- 引入标准页脚 -->
<?php require_once 'includes/footer.php'; ?>