<?php
// 启动会话
session_start();
// 引入数据库连接类
require_once '../config/database.php';
// 引入权限中间件进行权限验证
require_once '../includes/PermissionMiddleware.php';

$database = new Database();
$db = $database->getConnection();
$middleware = new PermissionMiddleware($db);
$middleware->checkUserPagePermission('frontend:personnel:view');


// 检查用户是否已登录
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 检查是否是管理员会话（来自用户系统）
$is_user_admin = strtolower(trim($_SESSION['role'] ?? '')) === 'admin';

// 设置页面特定变量
$page_title = '人员详情';
$active_page = 'personnel';
$show_page_title = '人员详情';
$page_icon = 'person-lines-fill';

// 启动输出缓冲
ob_start();

// 包含统一头部文件
include('includes/header.php');

// 获取数据库连接
$database = new Database();
$pdo = $database->getConnection();

// 获取人员ID
$personnel_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($personnel_id <= 0) {
    header("Location: personnel.php");
    exit;
}

// 获取人员详细信息
try {
    // 获取基本信息
    $query = "SELECT id, name, email, phone, id_card, gender, created_at 
              FROM personnel WHERE id = :id";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':id', $personnel_id);
    $stmt->execute();
    $person = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$person) {
        header("Location: personnel.php");
        exit;
    }
    
    // 获取项目部门分配信息
    $project_id = $_SESSION['project_id'] ?? 0;
    $assignments = [];
    
    if ($project_id > 0) {
        $query = "SELECT 
                    pdp.id as assignment_id,
                    pdp.position,
                    d.name as department_name,
                    p.name as project_name,
                    pdp.created_at as assigned_at
                  FROM project_department_personnel pdp
                  JOIN departments d ON pdp.department_id = d.id
                  JOIN projects p ON pdp.project_id = p.id
                  WHERE pdp.personnel_id = :personnel_id AND pdp.project_id = :project_id
                  ORDER BY pdp.created_at DESC";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':personnel_id', $personnel_id);
        $stmt->bindParam(':project_id', $project_id);
        $stmt->execute();
        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // 获取所有项目的分配信息
        $query = "SELECT 
                    pdp.id as assignment_id,
                    pdp.position,
                    d.name as department_name,
                    p.name as project_name,
                    pdp.created_at as assigned_at
                  FROM project_department_personnel pdp
                  JOIN departments d ON pdp.department_id = d.id
                  JOIN projects p ON pdp.project_id = p.id
                  WHERE pdp.personnel_id = :personnel_id
                  ORDER BY p.name, d.name";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':personnel_id', $personnel_id);
        $stmt->execute();
        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 获取用餐记录
    $meal_records = [];
    if ($project_id > 0) {
        try {
            $query = "SELECT 
                        mr.id,
                        mr.meal_date,
                        mr.meal_type,
                        mr.meal_count as quantity,
                        mr.created_at,
                        p.name as project_name
                      FROM meal_reports mr
                      JOIN projects p ON mr.project_id = p.id
                      WHERE mr.personnel_id = :personnel_id AND mr.project_id = :project_id
                      ORDER BY mr.meal_date DESC, mr.meal_type
                      LIMIT 10";
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':personnel_id', $personnel_id);
            $stmt->bindParam(':project_id', $project_id);
            $stmt->execute();
            $meal_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $meal_records = []; // 表不存在时返回空数组
        }
    }
    
    // 获取住宿记录
    $hotel_records = [];
    if ($project_id > 0) {
        try {
            $query = "SELECT 
                        hr.id,
                        hr.check_in_date,
                        hr.check_out_date,
                        hr.room_number,
                        hr.status,
                        hr.hotel_name as hotel_name,
                        hr.created_at
                      FROM hotel_reports hr
                      WHERE hr.personnel_id = :personnel_id AND hr.project_id = :project_id
                      ORDER BY hr.check_in_date DESC
                      LIMIT 5";
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':personnel_id', $personnel_id);
            $stmt->bindParam(':project_id', $project_id);
            $stmt->execute();
            $hotel_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $hotel_records = []; // 表不存在时返回空数组
        }
    }
    
    // 获取交通记录
    $transport_records = [];
    if ($project_id > 0) {
        try {
            $query = "SELECT 
                        tr.id,
                        tr.travel_date as transport_date,
                        tr.travel_type as transport_type,
                        tr.departure_location as origin,
                        tr.destination_location as destination,
                        tr.fleet_number as vehicle_number,
                        tr.status,
                        tr.created_at
                      FROM transportation_reports tr
                      WHERE tr.personnel_id = :personnel_id AND tr.project_id = :project_id
                      ORDER BY tr.travel_date DESC
                      LIMIT 5";
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':personnel_id', $personnel_id);
            $stmt->bindParam(':project_id', $project_id);
            $stmt->execute();
            $transport_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $transport_records = []; // 表不存在时返回空数组
        }
    }
    
} catch (PDOException $e) {
    $error = "获取人员信息失败: " . $e->getMessage();
    $person = null;
}

// 性别显示文本
function getGenderText($gender) {
    switch (strtolower($gender)) {
        case 'male':
        case '男':
            return '男';
        case 'female':
        case '女':
            return '女';
        default:
            return $gender ?: '未知';
    }
}

// 格式化日期（仅日期）- 保留这个函数，因为它与includes/functions.php中的函数行为略有不同
function formatDateOnly($date) {
    return $date ? date('Y-m-d', strtotime($date)) : '-';
}

// 获取状态徽章类
function getStatusBadgeClass($status) {
    switch (strtolower($status)) {
        case 'active':
        case 'confirmed':
            return 'bg-success';
        case 'pending':
            return 'bg-warning';
        case 'cancelled':
            return 'bg-danger';
        default:
            return 'bg-secondary';
    }
}

// 获取餐食类型文本
function getMealTypeText($type) {
    switch (strtolower($type)) {
        case 'breakfast':
            return '早餐';
        case 'lunch':
            return '午餐';
        case 'dinner':
            return '晚餐';
        default:
            return $type;
    }
}

// 获取交通类型文本
function getTransportTypeText($type) {
    switch (strtolower($type)) {
        case 'bus':
            return '大巴';
        case 'train':
            return '火车';
        case 'flight':
            return '飞机';
        case 'car':
            return '汽车';
        default:
            return $type;
    }
}
?>

<!-- 页面标题 -->
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1>
                    <i class="bi bi-<?php echo $page_icon; ?>"></i> 
                    <?php echo htmlspecialchars($person['name']); ?> - 详细信息
                </h1>
                <div>
                    <a href="personnel_edit.php?id=<?php echo $person['id']; ?>" class="btn btn-primary me-2">
                        <i class="bi bi-pencil"></i> 编辑信息
                    </a>
                    <a href="personnel.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> 返回列表
                    </a>
                </div>
            </div>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- 基本信息卡片 -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-person-circle"></i> 基本信息
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-sm-4"><strong>姓名：</strong></div>
                                    <div class="col-sm-8"><?php echo htmlspecialchars($person['name']); ?></div>
                                </div>
                                <div class="row">
                                    <div class="col-sm-4"><strong>性别：</strong></div>
                                    <div class="col-sm-8">
                                        <span class="badge bg-info">
                                            <?php echo getGenderText($person['gender']); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-sm-4"><strong>身份证：</strong></div>
                                    <div class="col-sm-8"><?php echo htmlspecialchars($person['id_card']); ?></div>
                                </div>
                                <div class="row">
                                    <div class="col-sm-4"><strong>电话：</strong></div>
                                    <div class="col-sm-8"><?php echo htmlspecialchars($person['phone'] ?? '-'); ?></div>
                                </div>
                                <div class="row">
                                    <div class="col-sm-4"><strong>邮箱：</strong></div>
                                    <div class="col-sm-8"><?php echo htmlspecialchars($person['email'] ?? '-'); ?></div>
                                </div>
                                <div class="row">
                                    <div class="col-sm-4"><strong>创建时间：</strong></div>
                                    <div class="col-sm-8"><?php echo formatDate($person['created_at']); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-building"></i> 项目部门分配
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($assignments)): ?>
                                    <div class="text-muted">
                                        <i class="bi bi-info-circle"></i> 暂无项目部门分配
                                    </div>
                                <?php else: ?>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($assignments as $assignment): ?>
                                            <div class="list-group-item">
                                                <div class="d-flex justify-content-between">
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($assignment['project_name']); ?></strong>
                                                        <br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($assignment['department_name']); ?></small>
                                                        <?php if (!empty($assignment['position'])): ?>
                                                            <br>
                                                            <small class="text-info"><?php echo htmlspecialchars($assignment['position']); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="text-end">
                                                        <small class="text-muted"><?php echo formatDateOnly($assignment['assigned_at']); ?></small>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 用餐记录 -->
                <?php if ($project_id > 0): ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-cup-hot"></i> 最近用餐记录
                                <small class="text-muted">(最近10条)</small>
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($meal_records)): ?>
                                <div class="text-muted">
                                    <i class="bi bi-info-circle"></i> 暂无用餐记录
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>日期</th>
                                                <th>餐食类型</th>
                                                <th>数量</th>
                                                <th>记录时间</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($meal_records as $record): ?>
                                                <tr>
                                                    <td><?php echo formatDateOnly($record['meal_date']); ?></td>
                                                    <td>
                                                        <span class="badge bg-primary">
                                                            <?php echo getMealTypeText($record['meal_type']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo $record['quantity']; ?></td>
                                                    <td><small><?php echo formatDate($record['created_at']); ?></small></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- 住宿记录 -->
                <?php if ($project_id > 0): ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-building"></i> 住宿记录
                                <small class="text-muted">(最近5条)</small>
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($hotel_records)): ?>
                                <div class="text-muted">
                                    <i class="bi bi-info-circle"></i> 暂无住宿记录
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>酒店</th>
                                                <th>房间号</th>
                                                <th>入住日期</th>
                                                <th>退房日期</th>
                                                <th>状态</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($hotel_records as $record): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($record['hotel_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($record['room_number']); ?></td>
                                                    <td><?php echo formatDateOnly($record['check_in_date']); ?></td>
                                                    <td><?php echo formatDateOnly($record['check_out_date']); ?></td>
                                                    <td>
                                                        <span class="badge <?php echo getStatusBadgeClass($record['status']); ?>">
                                                            <?php echo ucfirst($record['status']); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- 交通记录 -->
                <?php if ($project_id > 0): ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-car-front"></i> 交通记录
                                <small class="text-muted">(最近5条)</small>
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($transport_records)): ?>
                                <div class="text-muted">
                                    <i class="bi bi-info-circle"></i> 暂无交通记录
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>日期</th>
                                                <th>类型</th>
                                                <th>起点</th>
                                                <th>终点</th>
                                                <th>车牌号</th>
                                                <th>状态</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($transport_records as $record): ?>
                                                <tr>
                                                    <td><?php echo formatDateOnly($record['transport_date']); ?></td>
                                                    <td>
                                                        <span class="badge bg-info">
                                                            <?php echo getTransportTypeText($record['transport_type']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($record['origin']); ?></td>
                                                    <td><?php echo htmlspecialchars($record['destination']); ?></td>
                                                    <td><?php echo htmlspecialchars($record['vehicle_number']); ?></td>
                                                    <td>
                                                        <span class="badge <?php echo getStatusBadgeClass($record['status']); ?>">
                                                            <?php echo ucfirst($record['status']); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

<?php include 'includes/footer.php'; ?>