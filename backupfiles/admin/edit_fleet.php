<?php
// 编辑车辆页面
session_start();
require_once '../config/database.php';

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

// 获取车辆ID
$fleet_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// 获取车辆信息
$vehicle = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM fleet WHERE id = ?");
    $stmt->execute([$fleet_id]);
    $vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$vehicle) {
        $_SESSION['error'] = '车辆不存在';
        header('Location: fleet_management.php');
        exit();
    }
} catch (PDOException $e) {
    $_SESSION['error'] = '获取车辆信息失败: ' . $e->getMessage();
    header('Location: fleet_management.php');
    exit();
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fleet_number = trim($_POST['fleet_number']);
    $vehicle_type = $_POST['vehicle_type'];
    $vehicle_model = trim($_POST['vehicle_model']);
    $license_plate = trim($_POST['license_plate']);
    $driver_name = trim($_POST['driver_name']);
    $driver_phone = trim($_POST['driver_phone']);
    $status = $_POST['status'];
    $seats = isset($_POST['seats']) ? (int)$_POST['seats'] : 5;

    // 验证输入
    $errors = [];
    
    if (empty($fleet_number)) {
        $errors[] = '车队编号不能为空';
    }
    
    if (empty($license_plate)) {
        $errors[] = '车牌号码不能为空';
    }
    
    if (!empty($driver_phone) && !preg_match('/^1[3-9]\d{9}$/', $driver_phone)) {
        $errors[] = '手机号码格式不正确';
    }

    // 检查车队编号是否已存在（排除当前车辆）
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM fleet WHERE project_id = ? AND fleet_number = ? AND id != ?");
            $stmt->execute([$vehicle['project_id'], $fleet_number, $fleet_id]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = '该车队编号已存在';
            }
        } catch (PDOException $e) {
            $errors[] = '检查车队编号失败: ' . $e->getMessage();
        }
    }

    // 检查车牌号码是否已存在（排除当前车辆）
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM fleet WHERE license_plate = ? AND id != ?");
            $stmt->execute([$license_plate, $fleet_id]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = '该车牌号码已存在';
            }
        } catch (PDOException $e) {
            $errors[] = '检查车牌号码失败: ' . $e->getMessage();
        }
    }

    // 更新数据
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("UPDATE fleet SET fleet_number = ?, vehicle_type = ?, vehicle_model = ?, license_plate = ?, driver_name = ?, driver_phone = ?, status = ?, seats = ? WHERE id = ?");
            $stmt->execute([
                $fleet_number, $vehicle_type, $vehicle_model, $license_plate, 
                $driver_name, $driver_phone, $status, $seats, $fleet_id
            ]);
            
            $_SESSION['success'] = '车辆信息更新成功';
            header('Location: fleet_management.php?project_id=' . $vehicle['project_id']);
            exit();
        } catch (PDOException $e) {
            $errors[] = '更新车辆信息失败: ' . $e->getMessage();
        }
    }
} else {
    // 预填充表单数据
    $_POST = $vehicle;
}

// 车辆类型选项
$vehicle_types = [
    'car' => '轿车',
    'van' => '商务车',
    'minibus' => '中巴车',
    'bus' => '大巴车',
    'truck' => '货车',
    'other' => '其他'
];

// 状态选项
$status_options = [
    'active' => '正常',
    'inactive' => '停用',
    'maintenance' => '维修中'
];

?>

<div class="d-flex">
    <div class="sidebar position-fixed top-0 start-0 h-100" style="width: 250px;">
        <?php require_once 'sidebar.php'; ?>
    </div>
    
    <div class="main-content flex-grow-1">
        <!-- 顶部栏 -->
        <div class="d-flex justify-content-between align-items-center mb-4 p-3 bg-white rounded shadow-sm">
            <h1 class="h3 mb-0"><i class="bi bi-pencil-square"></i> 编辑车辆</h1>
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
            <div class="col-md-8 offset-md-2">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-pencil-square"></i> 编辑车辆信息</h5>
                    </div>
                    <div class="card-body">
                    
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="POST" id="editFleetForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="fleet_number">车队编号 *</label>
                                    <input type="text" class="form-control" id="fleet_number" name="fleet_number" 
                                           value="<?php echo htmlspecialchars($_POST['fleet_number'] ?? ''); ?>" required>
                                    <small class="form-text text-muted">如：A001、B002、C003</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="vehicle_type">车辆类型 *</label>
                                    <select class="form-control" id="vehicle_type" name="vehicle_type" required>
                                        <?php foreach ($vehicle_types as $key => $value): ?>
                                            <option value="<?php echo $key; ?>" 
                                                    <?php echo ($_POST['vehicle_type'] ?? '') == $key ? 'selected' : ''; ?>>
                                                <?php echo $value; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="vehicle_model">具体车型</label>
                                    <input type="text" class="form-control" id="vehicle_model" name="vehicle_model" 
                                           value="<?php echo htmlspecialchars($_POST['vehicle_model'] ?? ''); ?>"
                                           placeholder="如：奔驰V级、丰田考斯特">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="license_plate">车牌号码 *</label>
                                    <input type="text" class="form-control" id="license_plate" name="license_plate" 
                                           value="<?php echo htmlspecialchars($_POST['license_plate'] ?? ''); ?>" required>
                                    <small class="form-text text-muted">如：京A12345、京AD12345</small>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="driver_name">驾驶员姓名</label>
                                    <input type="text" class="form-control" id="driver_name" name="driver_name" 
                                           value="<?php echo htmlspecialchars($_POST['driver_name'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="driver_phone">驾驶员电话</label>
                                    <input type="tel" class="form-control" id="driver_phone" name="driver_phone" 
                                           value="<?php echo htmlspecialchars($_POST['driver_phone'] ?? ''); ?>"
                                           placeholder="如：13800138000">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="seats">座位数 *</label>
                                    <input type="number" class="form-control" id="seats" name="seats" 
                                           value="<?php echo htmlspecialchars($_POST['seats'] ?? '5'); ?>" 
                                           min="1" max="100" required>
                                    <small class="form-text text-muted">车辆可承载乘客数量</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="status">状态 *</label>
                                    <select class="form-control" id="status" name="status" required>
                                        <?php foreach ($status_options as $key => $value): ?>
                                            <option value="<?php echo $key; ?>" 
                                                    <?php echo ($_POST['status'] ?? '') == $key ? 'selected' : ''; ?>>
                                                <?php echo $value; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="form-group text-right">
                            <a href="fleet_management.php?project_id=<?php echo $vehicle['project_id']; ?>" 
                               class="btn btn-secondary">取消</a>
                            <button type="submit" class="btn btn-primary">更新</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// 车辆类型对应的默认座位数
const defaultSeats = {
    'car': 4,
    'van': 6,
    'minibus': 18,
    'bus': 44,
    'truck': 2,
    'other': 5
};

// 监听车辆类型变化，自动设置座位数
document.getElementById('vehicle_type').addEventListener('change', function() {
    const selectedType = this.value;
    const seatsInput = document.getElementById('seats');
    
    if (defaultSeats[selectedType]) {
        seatsInput.value = defaultSeats[selectedType];
    }
});

document.getElementById('editFleetForm').addEventListener('submit', function(e) {
    const phone = document.getElementById('driver_phone').value;
    
    // 手机号码验证（仅验证手机号，不验证车牌号）
    const phoneRegex = /^1[3-9]\d{9}$/;
    if (phone && !phoneRegex.test(phone)) {
        e.preventDefault();
        alert('手机号码格式不正确，请输入11位手机号');
        return false;
    }
});
</script>
    <link href="assets/css/app.min.css" rel="stylesheet">
<style>
body {
    background-color: #f8f9fa;
}
.sidebar {
    background-color: #ffffff;
    box-shadow: 0 0 10px rgba(0,0,0,0.1);
    z-index: 1000;
}
.main-content {
    margin-left: 250px;
    padding: 20px;
    min-height: 100vh;
}
@media (max-width: 768px) {
    .sidebar {
        width: 100% !important;
        position: relative !important;
        height: auto !important;
    }
    .main-content {
        margin-left: 0;
    }
}
</style>
<?php include 'includes/footer.php'; ?>