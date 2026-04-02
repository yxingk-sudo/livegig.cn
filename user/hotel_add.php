<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
// 独立的酒店添加页面，可通过 /user/hotels.php?action=add 或 /user/hotel_add.php 访问
session_start();
// 引入权限中间件进行权限验证
require_once '../includes/PermissionMiddleware.php';
$database = new Database();
$db = $database->getConnection();
$middleware = new PermissionMiddleware($db);
$middleware->checkUserPagePermission('frontend:hotel:add');

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

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $check_in_date = $_POST['check_in_date'];
    $check_out_date = $_POST['check_out_date'];
    $hotel_id = $_POST['hotel_id'];
    $room_type = $_POST['room_type'];
    $special_requirements = $_POST['special_requirements'] ?? '';
    $shared_room_info = $_POST['shared_room_info'] ?? '';
    $personnel_ids = $_POST['personnel_ids'] ?? [];
    $allow_sharing = isset($_POST['allow_sharing']) ? true : false; // 是否允许同住
    
    // 辅助函数：根据ID获取人员姓名
    function getPersonnelName($personnel_id, $db) {
        $name_query = "SELECT name FROM personnel WHERE id = :id";
        $name_stmt = $db->prepare($name_query);
        $name_stmt->execute([':id' => $personnel_id]);
        return $name_stmt->fetchColumn() ?: '';
    }
    
    try {
        // 表单验证
        if (empty($check_in_date) || empty($check_out_date) || empty($hotel_id) || empty($room_type) || empty($personnel_ids)) {
            throw new Exception('请填写所有必填字段');
        }
        
        if (strtotime($check_out_date) <= strtotime($check_in_date)) {
            throw new Exception('退房日期必须晚于入住日期');
        }
        
        // 获取酒店名称 - 只使用中文名称，避免统计时出现重复
        $hotel_name = '';
        foreach ($available_hotels as $hotel) {
            if ($hotel['id'] == $hotel_id) {
                // 只使用中文名称，不拼接英文名，确保统计时名称一致
                $hotel_name = $hotel['hotel_name_cn'];
                break;
            }
        }
        
        // 双床房特殊处理：多人入住同一间双床房
        if ($room_type === '双床房') {
            // 验证选择人数是否为偶数
            $person_count = count($personnel_ids);
            if ($person_count % 2 !== 0) {
                throw new Exception('双床房预订需要选择偶数人数');
            }
            
            // 计算需要的房间数量（每2人一间房）
            $actual_room_count = $person_count / 2;
            
            $db->beginTransaction();
            
            // 为每对人员创建一条记录，记录为同一间房
            for ($i = 0; $i < $person_count; $i += 2) {
                $pair_personnel_ids = array_slice($personnel_ids, $i, 2);
                
                // 获取这对人员的姓名用于备注
                $personnel_names = [];
                foreach ($pair_personnel_ids as $pid) {
                    // 使用简单查询只通过ID获取姓名，项目关联已经在前面的getProjectPersonnel中处理
                    $name_query = "SELECT name FROM personnel WHERE id = :id";
                    $name_stmt = $db->prepare($name_query);
                    $name_stmt->execute([':id' => $pid]);
                    $personnel_names[] = $name_stmt->fetchColumn();
                }
                
                // 创建双人房记录，房间数量为1（一间双人房）
                $query = "INSERT INTO hotel_reports (project_id, personnel_id, check_in_date, check_out_date, hotel_name, room_type, room_count, special_requirements, reported_by, shared_room_info) 
                          VALUES (:project_id, :personnel_id, :check_in_date, :check_out_date, :hotel_name, :room_type, :room_count, :special_requirements, :reported_by, :shared_room_info)";
                
                // 为这对人员中的每个人创建记录，但房间数量为1（共享同一间房）
                foreach ($pair_personnel_ids as $personnel_id) {
                    $stmt = $db->prepare($query);
                    $stmt->execute([
                        ':project_id' => $projectId,
                        ':personnel_id' => $personnel_id,
                        ':check_in_date' => $check_in_date,
                        ':check_out_date' => $check_out_date,
                        ':hotel_name' => $hotel_name,
                        ':room_type' => $room_type,
                        ':room_count' => 1, // 每人记录为1间房，但实际是共享
                        ':special_requirements' => $special_requirements,
                        ':reported_by' => $_SESSION['user_id'],
                        ':shared_room_info' => $shared_room_info . ' (' . implode('、', $personnel_names) . ')' // 存储共享房间人员信息
                    ]);
                }
            }
            
            $db->commit();
            $_SESSION['message'] = "成功为 {$person_count} 名人员预订双床房，共 {$actual_room_count} 间双床房";
            
        } else if ($room_type === '套房' && !$allow_sharing) {
            // 套房处理：不允计同住时，每人独立房间
            $actual_room_count = count($personnel_ids); // 每人一间房
            
            $db->beginTransaction();
            $success_count = 0;
            
            foreach ($personnel_ids as $personnel_id) {
                // 检查是否有重复预订
                $check_query = "SELECT COUNT(*) FROM hotel_reports 
                              WHERE project_id = :project_id 
                              AND personnel_id = :personnel_id 
                              AND hotel_name = :hotel_name 
                              AND check_in_date = :check_in_date 
                              AND check_out_date = :check_out_date";
                
                $check_stmt = $db->prepare($check_query);
                $check_stmt->execute([
                    ':project_id' => $projectId,
                    ':personnel_id' => $personnel_id,
                    ':hotel_name' => $hotel_name,
                    ':check_in_date' => $check_in_date,
                    ':check_out_date' => $check_out_date
                ]);
                
                if ($check_stmt->fetchColumn() > 0) {
                    throw new Exception('部分人员已有相同时间段的酒店预订，请重新选择');
                }
                
                // 获取人员姓名
                $name_query = "SELECT name FROM personnel WHERE id = :id";
                $name_stmt = $db->prepare($name_query);
                $name_stmt->execute([':id' => $personnel_id]);
                $personnel_name = $name_stmt->fetchColumn() ?: '';
                
                $query = "INSERT INTO hotel_reports (project_id, personnel_id, check_in_date, check_out_date, hotel_name, room_type, room_count, special_requirements, reported_by, shared_room_info) 
                          VALUES (:project_id, :personnel_id, :check_in_date, :check_out_date, :hotel_name, :room_type, :room_count, :special_requirements, :reported_by, :shared_room_info)";
                
                $stmt = $db->prepare($query);
                $stmt->execute([
                    ':project_id' => $projectId,
                    ':personnel_id' => $personnel_id,
                    ':check_in_date' => $check_in_date,
                    ':check_out_date' => $check_out_date,
                    ':hotel_name' => $hotel_name,
                    ':room_type' => $room_type,
                    ':room_count' => 1, // 每人记录为1间房
                    ':special_requirements' => $special_requirements,
                    ':reported_by' => $_SESSION['user_id'],
                    ':shared_room_info' => $shared_room_info ?: ('套房 (' . $personnel_name . ')') // 如果没有提供共享房间信息，则自动生成
                ]);
                $success_count++;
            }
            
            $db->commit();
            $_SESSION['message'] = "成功为 {$success_count} 名人员添加套房预订，共 {$actual_room_count} 间房";
            
        } else if ($allow_sharing && $room_type === '套房') {
            // 套房处理：按照共享房间逻辑处理，每2人一间房
            $person_count = count($personnel_ids);
            
            // 验证选择人数是否为偶数
            if ($person_count % 2 !== 0) {
                throw new Exception('套房需要选择偶数人数');
            }
            
            // 计算需要的房间数量（每2人一间房）
            $actual_room_count = $person_count / 2;
            
            $db->beginTransaction();
            
            // 为每对人员创建一条记录，记录为同一间房
            for ($i = 0; $i < $person_count; $i += 2) {
                $pair_personnel_ids = array_slice($personnel_ids, $i, 2);
                
                // 获取这对人员的姓名用于备注
                $personnel_names = [];
                foreach ($pair_personnel_ids as $pid) {
                    // 使用简单查询只通过ID获取姓名
                    $name_query = "SELECT name FROM personnel WHERE id = :id";
                    $name_stmt = $db->prepare($name_query);
                    $name_stmt->execute([':id' => $pid]);
                    $personnel_names[] = $name_stmt->fetchColumn();
                }
                
                // 创建共享房记录，房间数量为1（一间房两人共享）
                $query = "INSERT INTO hotel_reports (project_id, personnel_id, check_in_date, check_out_date, hotel_name, room_type, room_count, special_requirements, reported_by, shared_room_info) 
                          VALUES (:project_id, :personnel_id, :check_in_date, :check_out_date, :hotel_name, :room_type, :room_count, :special_requirements, :reported_by, :shared_room_info)";
                
                // 为这对人员中的每个人创建记录，但房间数量为1（共享同一间房）
                foreach ($pair_personnel_ids as $personnel_id) {
                    $stmt = $db->prepare($query);
                    $stmt->execute([
                        ':project_id' => $projectId,
                        ':personnel_id' => $personnel_id,
                        ':check_in_date' => $check_in_date,
                        ':check_out_date' => $check_out_date,
                        ':hotel_name' => $hotel_name,
                        ':room_type' => $room_type,
                        ':room_count' => 1, // 每人记录为1间房，但实际是共享
                        ':special_requirements' => $special_requirements,
                        ':reported_by' => $_SESSION['user_id'],
                        ':shared_room_info' => $shared_room_info ?: ('套房共享 (' . implode('、', $personnel_names) . ')') // 如果没有提供共享房间信息，则自动生成
                    ]);
                }
            }
            
            $db->commit();
            $_SESSION['message'] = "成功为 {$person_count} 名人员预订套房，共 {$actual_room_count} 间房";
            
        } else if ($allow_sharing && in_array($room_type, ['大床房', '总统套房', '副总统套房'])) {
            // 允许同住且房型为大床房、总统套房或副总统套房时的处理
            $person_count = count($personnel_ids);
            
            // 验证选择人数是否为偶数
            if ($person_count % 2 !== 0) {
                throw new Exception($room_type . '选择同住模式需要选择偶数人数');
            }
            
            // 计算需要的房间数量（每2人一间房）
            $actual_room_count = $person_count / 2;
            
            $db->beginTransaction();
            
            // 为每对人员创建一条记录，记录为同一间房
            for ($i = 0; $i < $person_count; $i += 2) {
                $pair_personnel_ids = array_slice($personnel_ids, $i, 2);
                
                // 获取这对人员的姓名用于备注
                $personnel_names = [];
                foreach ($pair_personnel_ids as $pid) {
                    // 使用简单查询只通过ID获取姓名
                    $name_query = "SELECT name FROM personnel WHERE id = :id";
                    $name_stmt = $db->prepare($name_query);
                    $name_stmt->execute([':id' => $pid]);
                    $personnel_names[] = $name_stmt->fetchColumn();
                }
                
                // 创建共享房记录，房间数量为1（一间房两人共享）
                $query = "INSERT INTO hotel_reports (project_id, personnel_id, check_in_date, check_out_date, hotel_name, room_type, room_count, special_requirements, reported_by, shared_room_info) 
                          VALUES (:project_id, :personnel_id, :check_in_date, :check_out_date, :hotel_name, :room_type, :room_count, :special_requirements, :reported_by, :shared_room_info)";
                
                // 为这对人员中的每个人创建记录，但房间数量为1（共享同一间房）
                foreach ($pair_personnel_ids as $personnel_id) {
                    $stmt = $db->prepare($query);
                    $stmt->execute([
                        ':project_id' => $projectId,
                        ':personnel_id' => $personnel_id,
                        ':check_in_date' => $check_in_date,
                        ':check_out_date' => $check_out_date,
                        ':hotel_name' => $hotel_name,
                        ':room_type' => $room_type,
                        ':room_count' => 1, // 每人记录为1间房，但实际是共享
                        ':special_requirements' => $special_requirements,
                        ':reported_by' => $_SESSION['user_id'],
                        ':shared_room_info' => $shared_room_info ?: ($room_type . '共享 (' . implode('、', $personnel_names) . ')') // 如果没有提供共享房间信息，则自动生成
                    ]);
                }
            }
            
            $db->commit();
            $_SESSION['message'] = "成功为 {$person_count} 名人员预订{$room_type}（同住模式），共 {$actual_room_count} 间房";
            
        } else {
            // 普通房型处理：每人独立房间
            $actual_room_count = count($personnel_ids); // 每人一间房
            
            $db->beginTransaction();
            $success_count = 0;
            
            foreach ($personnel_ids as $personnel_id) {
                // 检查是否有重复预订
                $check_query = "SELECT COUNT(*) FROM hotel_reports 
                              WHERE project_id = :project_id 
                              AND personnel_id = :personnel_id 
                              AND hotel_name = :hotel_name 
                              AND check_in_date = :check_in_date 
                              AND check_out_date = :check_out_date";
                
                $check_stmt = $db->prepare($check_query);
                $check_stmt->execute([
                    ':project_id' => $projectId,
                    ':personnel_id' => $personnel_id,
                    ':hotel_name' => $hotel_name,
                    ':check_in_date' => $check_in_date,
                    ':check_out_date' => $check_out_date
                ]);
                
                if ($check_stmt->fetchColumn() > 0) {
                    throw new Exception('部分人员已有相同时间段的酒店预订，请重新选择');
                }
                
                $query = "INSERT INTO hotel_reports (project_id, personnel_id, check_in_date, check_out_date, hotel_name, room_type, room_count, special_requirements, reported_by, shared_room_info) 
                          VALUES (:project_id, :personnel_id, :check_in_date, :check_out_date, :hotel_name, :room_type, :room_count, :special_requirements, :reported_by, :shared_room_info)";
                
                $stmt = $db->prepare($query);
                $stmt->execute([
                    ':project_id' => $projectId,
                    ':personnel_id' => $personnel_id,
                    ':check_in_date' => $check_in_date,
                    ':check_out_date' => $check_out_date,
                    ':hotel_name' => $hotel_name,
                    ':room_type' => $room_type,
                    ':room_count' => 1, // 每人记录为1间房
                    ':special_requirements' => $special_requirements,
                    ':reported_by' => $_SESSION['user_id'],
                    ':shared_room_info' => null // 非双床房不存储共享信息
                ]);
                $success_count++;
            }
            
            $db->commit();
            $_SESSION['message'] = "成功为 {$success_count} 名人员添加酒店预订，共 {$actual_room_count} 间房";
        }
        
        header("Location: hotels.php");
        exit;
        
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        $message = "添加失败: " . $e->getMessage();
    }
}

// 设置页面标题和激活菜单
$page_title = '添加酒店预订 - ' . $projectName;
$active_page = 'hotel_add';

// 引入头部文件
require_once __DIR__ . '/includes/header.php';

// 添加页面特定样式
?>
<style>
        .personnel-list {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 10px;
        }
        .personnel-item {
            padding: 5px 0;
        }
        .toast {
            min-width: 300px;
        }
        .stat-card {
            background-color: #ffffff;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid #007bff;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        .stat-card i {
            font-size: 24px;
            margin-right: 10px;
            color: #007bff;
        }
        .stat-number {
            font-size: 18px;
            font-weight: bold;
        }
        /* 禁用状态样式 */
        .disabled-section {
            opacity: 0.6;
            pointer-events: none;
        }
        /* 禁用提示样式 */
        .disabled-hint {
            position: relative;
        }
        .disabled-hint::after {
            content: "请先选择房型";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background-color: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 10px 15px;
            border-radius: 4px;
            font-size: 14px;
            z-index: 10;
        }
</style>

    <div class="container mt-4">
        <!-- 返回按钮 -->
        <div class="mb-4">
            <a href="hotels.php" class="btn btn-secondary">
                <i class="fa fa-arrow-left"></i> 返回酒店预订列表
            </a>
        </div>
        
        <!-- 消息提示 -->
        <?php if ($message): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- 统计卡片 -->
        <div class="row mb-4">
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
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <i class="fa fa-sitemap"></i>
                        <div>
                            <div class="stat-number"><?php echo count($departments); ?></div>
                            <div class="text-sm text-muted">部门数量</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="d-flex align-items-center">
                        <i class="fa fa-calendar"></i>
                        <div>
                            <div class="stat-number"><?php echo date('Y-m-d'); ?></div>
                            <div class="text-sm text-muted">当前日期</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 添加酒店预订表单 -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">添加酒店预订</h5>
            </div>
            <div class="card-body">
                <form id="addHotelForm" method="POST">
                    <div class="row">
                        <!-- 酒店信息 -->
                        <div class="col-md-6">
                            <div class="mb-4">
                                <label for="hotel_id" class="form-label">选择酒店 *</label>
                                <select class="form-select" id="hotel_id" name="hotel_id" required onchange="updateRoomTypes()">
                                    <option value="">请选择酒店</option>
                                    <?php foreach ($available_hotels as $hotel): ?>
                                        <option value="<?php echo $hotel['id']; ?>" 
                                                data-room-types='<?php echo htmlspecialchars($hotel['room_types']); ?>'>
                                            <?php echo htmlspecialchars($hotel['hotel_name_cn']); ?>
                                            <?php if (!empty($hotel['hotel_name_en'])): ?>
                                                - <?php echo htmlspecialchars($hotel['hotel_name_en']); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-4">
                                <label for="check_in_date" class="form-label">入住日期 *</label>
                                <input type="date" class="form-control" id="check_in_date" name="check_in_date" 
                                       value="<?php echo $project['start_date'] ?? date('Y-m-d'); ?>" required>
                            </div>

                            <div class="mb-4">
                                <label for="check_out_date" class="form-label">退房日期 *</label>
                                <input type="date" class="form-control" id="check_out_date" name="check_out_date" 
                                       value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
                            </div>

                            <div class="mb-4">
                                <label for="room_type" class="form-label">选择房型 *</label>
                                <select class="form-select" id="room_type" name="room_type" required onchange="toggleSharedRoomOptions()">
                                    <option value="">请先选择酒店</option>
                                </select>
                            </div>

                            <!-- 添加同住人选项 -->
                            <div class="mb-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="allow_sharing" name="allow_sharing" value="1" onchange="toggleSharingOptions()">
                                    <label class="form-check-label" for="allow_sharing">
                                        是否允许同住（大床房、套房、总统套房、副总统套房可两人入住一间）
                                    </label>
                                </div>
                                <div class="form-text text-muted mt-1" id="sharingHint" style="display: none;">
                                    启用同住模式后，所选人员将按两人一间房计算，房间数量会自动调整。
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="room_count" class="form-label">房间数量</label>
                                <input type="number" class="form-control" id="room_count" name="room_count" 
                                       min="1" value="1" readonly>
                            </div>

                            <!-- 双床房共享信息 -->
                            <div id="shared_room_container" class="mb-4" style="display: none;">
                                <label for="shared_room_info" class="form-label">房间信息</label>
                                <input type="text" class="form-control" id="shared_room_info" name="shared_room_info" 
                                       placeholder="例如：房间A（张三、李四共享）">
                                <div class="form-text text-muted">双床房需填写房间信息，注明房间分配情况；套房可选填房间信息</div>
                            </div>

                            <div class="mb-4">
                                <label for="special_requirements" class="form-label">特殊要求</label>
                                <textarea class="form-control" id="special_requirements" name="special_requirements" 
                                          rows="3" placeholder="如有特殊要求，请在此说明"></textarea>
                            </div>
                        </div>

                        <!-- 人员选择 -->
                        <div class="col-md-6" id="personnelSelectionSection">
                            <div class="mb-3">
                                <label class="form-label">部门筛选</label>
                                <select class="form-select" id="departmentFilter" name="departmentFilter" onchange="filterPersonnel()">
                                    <option value="">全部部门</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>">
                                            <?php echo htmlspecialchars($dept['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">搜索人员</label>
                                <input type="text" class="form-control" id="personnelSearch" placeholder="输入姓名搜索" oninput="filterPersonnel()">
                            </div>

                            <div class="mb-3">
                                <div class="d-flex justify-content-between">
                                    <label class="form-label">选择人员 *</label>
                                    <div>
                                        <span id="selectedCount" class="badge bg-secondary">0人</span>
                                        <button type="button" class="btn btn-sm btn-primary ms-2" onclick="selectAllPersonnel()">
                                            <i class="fa fa-check-square-o"></i> 全选
                                        </button>
                                        <button type="button" class="btn btn-sm btn-secondary ms-1" onclick="clearAllPersonnel()">
                                            <i class="fa fa-square-o"></i> 清空
                                        </button>
                                    </div>
                                </div>
                                <div class="personnel-list" id="personnelList">
                                    <?php if (empty($personnel)): ?>
                                        <div class="text-center text-muted">暂无项目人员</div>
                                    <?php else: ?>
                                        <?php foreach ($personnel as $person): ?>
                                            <div class="personnel-item" data-department="<?php echo $person['department_id']; ?>" data-name="<?php echo $person['name']; ?>">
                                                <div class="form-check">
                                                    <input class="form-check-input personnel-checkbox" type="checkbox" 
                                                           name="personnel_ids[]" value="<?php echo $person['id']; ?>" 
                                                           id="person_<?php echo $person['id']; ?>">
                                                    <label class="form-check-label" for="person_<?php echo $person['id']; ?>">
                                                        <?php echo htmlspecialchars($person['name']); ?>
                                                        <span class="text-muted">（<?php echo htmlspecialchars($person['positions']); ?>）</span>
                                                    </label>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 提交按钮 -->
                    <div class="row mt-4">
                        <div class="col text-center">
                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-save"></i> 保存预订
                            </button>
                            <a href="hotels.php" class="btn btn-secondary ms-2">
                                <i class="fa fa-times"></i> 取消
                            </a>
                        </div>
                    </div>
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

    // 酒店选择变化时更新房型选项
    function updateRoomTypes() {
        const selectedHotel = document.getElementById('hotel_id').selectedOptions[0];
        const roomTypeSelect = document.getElementById('room_type');
        
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
        
        // 房型变更时检查双床房规则
        toggleSharedRoomOptions();
        updateRoomCount();
    }
    
    // 启用/禁用人员选择区域
    function togglePersonnelSelection() {
        const roomType = document.getElementById('room_type').value;
        const personnelSection = document.getElementById('personnelSelectionSection');
        
        if (roomType) {
            // 启用人员选择区域
            personnelSection.classList.remove('disabled-section');
            personnelSection.classList.remove('disabled-hint');
        } else {
            // 禁用人员选择区域
            personnelSection.classList.add('disabled-section');
            personnelSection.classList.add('disabled-hint');
            // 清空所有选择
            clearAllPersonnel();
        }
    }

    // 房型选择变更时显示/隐藏共享房间信息输入框
    function toggleSharedRoomOptions() {
        const roomType = document.getElementById('room_type').value;
        const sharedRoomContainer = document.getElementById('shared_room_container');
        const sharedRoomInfo = document.getElementById('shared_room_info');
        const allowSharing = document.getElementById('allow_sharing');
        const sharingHint = document.getElementById('sharingHint');
        
        // 检查是否允许同住且房型为大床房、总统套房、副总统套房或套房
        const isSpecialRoomType = ['大床房', '总统套房', '副总统套房', '套房'].includes(roomType);
        const sharingAllowed = allowSharing.checked && isSpecialRoomType;
        
        // 控制同住选项的显示/隐藏
        if (isSpecialRoomType) {
            // 对于大床房、总统套房、副总统套房、套房，显示同住选项
            allowSharing.parentElement.parentElement.style.display = 'block';
            // 如果已选择同住，显示提示信息
            if (allowSharing.checked) {
                sharingHint.style.display = 'block';
            }
        } else {
            // 对于其他房型，隐藏同住选项
            allowSharing.parentElement.parentElement.style.display = 'none';
            // 隐藏提示信息
            sharingHint.style.display = 'none';
            // 取消同住选项
            allowSharing.checked = false;
        }
        
        if (roomType === '双床房' || roomType === '套房' || sharingAllowed) {
            sharedRoomContainer.style.display = 'block';
            // 清空之前的内容
            sharedRoomInfo.value = '';
            
            // 自动填入选中的人员姓名
            updateSharedRoomInfo();
        } else {
            sharedRoomContainer.style.display = 'none';
            // 非双床房时清空内容
            sharedRoomInfo.value = '';
        }
        
        // 启用/禁用人员选择区域
        togglePersonnelSelection();
        // 更新选中计数和房间数量
        updateSelectedCount();
    }
    
    // 切换同住选项时的处理
    function toggleSharingOptions() {
        const allowSharing = document.getElementById('allow_sharing');
        const roomType = document.getElementById('room_type').value;
        const sharingHint = document.getElementById('sharingHint');
        
        // 检查是否允许同住且房型为大床房、总统套房、副总统套房或套房
        const isSpecialRoomType = ['大床房', '总统套房', '副总统套房', '套房'].includes(roomType);
        
        if (allowSharing.checked && isSpecialRoomType) {
            // 显示同住提示
            sharingHint.style.display = 'block';
        } else {
            // 隐藏同住提示
            sharingHint.style.display = 'none';
        }
        
        // 如果选择了同住选项，更新界面
        toggleSharedRoomOptions();
        updateSelectedCount();
    }
    
    // 更新共享房间信息
    function updateSharedRoomInfo() {
        const roomType = document.getElementById('room_type').value;
        const sharedRoomInfo = document.getElementById('shared_room_info');
        const allowSharing = document.getElementById('allow_sharing');
        
        // 检查是否允许同住且房型为大床房、总统套房、副总统套房或套房
        const isSpecialRoomType = ['大床房', '总统套房', '副总统套房', '套房'].includes(roomType);
        const sharingAllowed = allowSharing.checked && isSpecialRoomType;
        
        if (roomType === '双床房' || roomType === '套房' || sharingAllowed) {
            const selectedPersonnel = [];
            document.querySelectorAll('.personnel-checkbox:checked').forEach(checkbox => {
                const label = document.querySelector(`label[for="${checkbox.id}"]`);
                if (label) {
                    // 提取人员姓名（去除职位信息）
                    const name = label.textContent.split('（')[0].trim();
                    selectedPersonnel.push(name);
                }
            });
            
            // 如果有选中的人员，自动填入房间信息
            if (selectedPersonnel.length > 0) {
                if (roomType === '双床房') {
                    // 双床房：只取前两个人
                    sharedRoomInfo.value = `房间（${selectedPersonnel.slice(0, 2).join('、')}共享）`;
                } else if (roomType === '套房') {
                    // 套房：根据是否允许同住来决定显示方式
                    if (allowSharing.checked) {
                        sharedRoomInfo.value = `套房共享 (${selectedPersonnel.join('、')})`;
                    } else {
                        sharedRoomInfo.value = `套房 (${selectedPersonnel.join('、')})`;
                    }
                } else if (sharingAllowed) {
                    // 允许同住的特殊房型：每2人一间房
                    sharedRoomInfo.value = `${roomType}共享 (${selectedPersonnel.join('、')})`;
                }
            } else {
                // 没有选中人员时，清空共享房间信息
                sharedRoomInfo.value = '';
            }
        } else {
            // 不支持共享的房型，清空共享房间信息
            sharedRoomInfo.value = '';
        }
    }

    // 按部门筛选和搜索功能
    function filterPersonnel() {
        const departmentFilter = document.getElementById('departmentFilter').value;
        const searchTerm = document.getElementById('personnelSearch').value.toLowerCase();
        const personnelItems = document.querySelectorAll('.personnel-item');

        personnelItems.forEach(item => {
            const itemDept = item.dataset.department;
            const itemName = item.dataset.name.toLowerCase();

            const matchesDept = !departmentFilter || itemDept === departmentFilter;
            const matchesSearch = !searchTerm || itemName.includes(searchTerm);

            item.style.display = (matchesDept && matchesSearch) ? 'block' : 'none';
        });

        updateSelectedCount();
    }

    // 全选人员
    function selectAllPersonnel() {
        const visibleItems = document.querySelectorAll('.personnel-item[style="display: block"], .personnel-item:not([style*="display: none"])');
        visibleItems.forEach(item => {
            const checkbox = item.querySelector('.personnel-checkbox');
            if (checkbox) checkbox.checked = true;
        });
        updateSelectedCount();
    }

    // 清空选择
    function clearAllPersonnel() {
        document.querySelectorAll('.personnel-checkbox').forEach(checkbox => {
            checkbox.checked = false;
        });
        updateSelectedCount();
    }

    // 更新选中计数和房间数量
    function updateSelectedCount() {
        const selectedCount = document.querySelectorAll('.personnel-checkbox:checked').length;
        const selectedCountElement = document.getElementById('selectedCount');
        const roomType = document.getElementById('room_type').value;
        const allowSharing = document.getElementById('allow_sharing');
        
        // 检查是否允许同住且房型为大床房、总统套房或副总统套房或套房
        const isSpecialRoomType = ['大床房', '总统套房', '副总统套房', '套房'].includes(roomType);
        const sharingAllowed = allowSharing.checked && isSpecialRoomType;
        
        // 自动计算房间数量
        updateRoomCount();
        
        // 根据房型和同住选项显示不同的提示
        if (roomType === '双床房') {
            if (selectedCount > 0 && selectedCount % 2 === 0) {
                const roomCount = selectedCount / 2;
                selectedCountElement.innerHTML = `<span class="badge bg-success">${selectedCount}人 ✓ (${roomCount}间房)</span>`;
            } else {
                selectedCountElement.innerHTML = `<span class="badge bg-danger">${selectedCount}人 (需偶数人数)</span>`;
            }
        } else if (roomType === '套房') {
            // 套房：根据是否允许同住来决定房间计算方式
            if (allowSharing.checked) {
                // 允许同住：每2人一间房
                if (selectedCount > 0 && selectedCount % 2 === 0) {
                    const roomCount = selectedCount / 2;
                    selectedCountElement.innerHTML = `<span class="badge bg-success">${selectedCount}人 ✓ (${roomCount}间房，同住模式)</span>`;
                } else {
                    selectedCountElement.innerHTML = `<span class="badge bg-danger">${selectedCount}人 (同住模式需偶数人数)</span>`;
                }
            } else {
                // 不允许同住：每人一间房
                const roomCount = selectedCount;
                selectedCountElement.innerHTML = `<span class="badge bg-secondary">${selectedCount}人 (${roomCount}间房)</span>`;
            }
        } else if (sharingAllowed) {
            // 允许同住的特殊房型
            if (selectedCount > 0 && selectedCount % 2 === 0) {
                const roomCount = selectedCount / 2;
                selectedCountElement.innerHTML = `<span class="badge bg-success">${selectedCount}人 ✓ (${roomCount}间房，同住模式)</span>`;
            } else {
                selectedCountElement.innerHTML = `<span class="badge bg-danger">${selectedCount}人 (同住模式需偶数人数)</span>`;
            }
        } else {
            const roomCount = document.getElementById('room_count').value;
            selectedCountElement.innerHTML = `<span class="badge bg-secondary">${selectedCount}人 (${roomCount}间房)</span>`;
        }
    }
    
    // 自动计算房间数量
    function updateRoomCount() {
        const roomType = document.getElementById('room_type').value;
        const selectedCount = document.querySelectorAll('.personnel-checkbox:checked').length;
        const allowSharing = document.getElementById('allow_sharing');
        
        let roomCount = 1; // 默认值
        
        if (selectedCount > 0) {
            // 检查是否允许同住且房型为大床房、总统套房或副总统套房或套房
            const isSpecialRoomType = ['大床房', '总统套房', '副总统套房', '套房'].includes(roomType);
            const sharingAllowed = allowSharing.checked && isSpecialRoomType;
            
            if (roomType === '双床房' || (roomType === '套房' && sharingAllowed) || sharingAllowed) {
                // 双床房、套房（同住模式）或允许同住的特殊房型：每2人一间房
                roomCount = Math.ceil(selectedCount / 2);
            } else {
                // 普通房型：每人一间房
                roomCount = selectedCount;
            }
        }
        
        // 更新房间数量输入框
        document.getElementById('room_count').value = roomCount;
    }

    // 为复选框添加事件监听
    function initCheckboxListeners() {
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('personnel-checkbox')) {
                updateSelectedCount();
                // 当人员选择发生变化时，更新共享房间信息
                const roomType = document.getElementById('room_type').value;
                const allowSharing = document.getElementById('allow_sharing');
                const isSpecialRoomType = ['大床房', '总统套房', '副总统套房', '套房'].includes(roomType);
                const sharingAllowed = allowSharing.checked && isSpecialRoomType;
                
                if (roomType === '双床房' || roomType === '套房' || sharingAllowed) {
                    updateSharedRoomInfo();
                }
            }
        });
    }

    // 日期变更时也更新房间数量
    function initDateListeners() {
        document.getElementById('check_in_date').addEventListener('change', function() {
            const checkInDate = this.value;
            const checkOutDate = document.getElementById('check_out_date').value;
            
            // 如果退房日期早于入住日期，自动调整
            if (checkOutDate <= checkInDate) {
                document.getElementById('check_out_date').value = new Date(new Date(checkInDate).getTime() + 24 * 60 * 60 * 1000).toISOString().split('T')[0];
            }
        });
    }

    // 表单验证
    document.getElementById('addHotelForm').addEventListener('submit', function(e) {
        const checkInDate = new Date(document.getElementById('check_in_date').value);
        const checkOutDate = new Date(document.getElementById('check_out_date').value);
        const roomType = document.getElementById('room_type').value;
        const selectedPersonnel = document.querySelectorAll('.personnel-checkbox:checked');
        const sharedRoomInfo = document.getElementById('shared_room_info').value.trim();
        const allowSharing = document.getElementById('allow_sharing');
        
        // 检查是否允许同住且房型为大床房、总统套房或副总统套房或套房
        const isSpecialRoomType = ['大床房', '总统套房', '副总统套房', '套房'].includes(roomType);
        const sharingAllowed = allowSharing.checked && isSpecialRoomType;
        
        // 日期验证
        if (checkOutDate <= checkInDate) {
            e.preventDefault();
            showToast('退房日期必须晚于入住日期', 'warning');
            return false;
        }
        
        // 人员选择验证
        if (selectedPersonnel.length === 0) {
            e.preventDefault();
            showToast('请至少选择一名人员进行预订', 'warning');
            return false;
        }
        
        // 双床房特殊验证
        if (roomType === '双床房') {
            // 验证是否选择了偶数人数
            if (selectedPersonnel.length % 2 !== 0) {
                e.preventDefault();
                showToast('双床房必须选择偶数人数（每2人共享一间房），当前已选择：' + selectedPersonnel.length + '人', 'warning');
                return false;
            }
            
            // 验证共享房间信息
            if (sharedRoomInfo === '') {
                e.preventDefault();
                showToast('请填写共享房间信息，说明房间分配情况', 'warning');
                return false;
            }
            
            if (sharedRoomInfo.length < 5) {
                e.preventDefault();
                showToast('共享房间信息过于简单，请详细说明房间分配情况', 'warning');
                return false;
            }
        }
        
        // 允许同住的特殊房型验证
        if (sharingAllowed && roomType !== '套房') {
            // 验证是否选择了偶数人数
            if (selectedPersonnel.length % 2 !== 0) {
                e.preventDefault();
                showToast(roomType + '选择同住模式需要选择偶数人数（每2人共享一间房），当前已选择：' + selectedPersonnel.length + '人', 'warning');
                return false;
            }
            
            // 验证共享房间信息
            if (sharedRoomInfo === '') {
                e.preventDefault();
                showToast('请填写共享房间信息，说明房间分配情况', 'warning');
                return false;
            }
            
            if (sharedRoomInfo.length < 5) {
                e.preventDefault();
                showToast('共享房间信息过于简单，请详细说明房间分配情况', 'warning');
                return false;
            }
        }
        
        // 套房验证
        if (roomType === '套房') {
            if (allowSharing.checked) {
                // 验证是否选择了偶数人数
                if (selectedPersonnel.length % 2 !== 0) {
                    e.preventDefault();
                    showToast('套房选择同住模式需要选择偶数人数（每2人共享一间房），当前已选择：' + selectedPersonnel.length + '人', 'warning');
                    return false;
                }
                
                // 验证共享房间信息
                if (sharedRoomInfo === '') {
                    e.preventDefault();
                    showToast('请填写共享房间信息，说明房间分配情况', 'warning');
                    return false;
                }
                
                if (sharedRoomInfo.length < 5) {
                    e.preventDefault();
                    showToast('共享房间信息过于简单，请详细说明房间分配情况', 'warning');
                    return false;
                }
            }
            // 如果不允许同住，套房可以是任意人数
        }
        
        // 普通房型验证
        if (!['双床房', '套房'].includes(roomType) && !sharingAllowed) {
            // 对于普通房型，每人独立房间
            const roomCount = document.getElementById('room_count').value;
            if (parseInt(roomCount) !== selectedPersonnel.length) {
                e.preventDefault();
                showToast('房间数量与选择人员数量不匹配，请检查', 'warning');
                return false;
            }
        }
    });

    // 初始化功能
    document.addEventListener('DOMContentLoaded', function() {
        initCheckboxListeners();
        initDateListeners();
        
        // 初始禁用人员选择区域
        togglePersonnelSelection();
        
        // 隐藏同住选项和提示信息
        const allowSharingContainer = document.getElementById('allow_sharing').parentElement.parentElement;
        allowSharingContainer.style.display = 'none';
        document.getElementById('sharingHint').style.display = 'none';
        
        // 如果有酒店，初始化房型
        if (document.getElementById('hotel_id').options.length > 1) {
            // 模拟选择第一个酒店
            document.getElementById('hotel_id').dispatchEvent(new Event('change'));
        }
    });
    </script>

<?php include 'includes/footer.php'; ?>
