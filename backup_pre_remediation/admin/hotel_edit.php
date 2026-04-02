<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once 'page_functions.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();

$message = '';
$error = '';

// 获取要编辑的记录
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update') {
        $id = intval($_POST['id']);
        $personnel_id = intval($_POST['personnel_id']);
        $hotel_name = $_POST['hotel_name'];
        $room_type = $_POST['room_type'];
        $check_in_date = $_POST['check_in_date'];
        $check_out_date = $_POST['check_out_date'];
        $room_count = intval($_POST['room_count']);
        $shared_room_info = $_POST['shared_room_info'] ?? '';
        $special_requirements = $_POST['special_requirements'] ?? '';
        $status = $_POST['status'];
        
        // 验证数据
        if (empty($hotel_name) || empty($room_type) || empty($check_in_date) || empty($check_out_date)) {
            $error = '请填写所有必填字段！';
        } elseif (strtotime($check_out_date) <= strtotime($check_in_date)) {
            $error = '退房日期必须晚于入住日期！';
        } else {
            // 更新记录
            $query = "UPDATE hotel_reports SET 
                      personnel_id = :personnel_id,
                      hotel_name = :hotel_name,
                      room_type = :room_type,
                      check_in_date = :check_in_date,
                      check_out_date = :check_out_date,
                      room_count = :room_count,
                      shared_room_info = :shared_room_info,
                      special_requirements = :special_requirements,
                      status = :status
                      WHERE id = :id";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':personnel_id', $personnel_id);
            $stmt->bindParam(':hotel_name', $hotel_name);
            $stmt->bindParam(':room_type', $room_type);
            $stmt->bindParam(':check_in_date', $check_in_date);
            $stmt->bindParam(':check_out_date', $check_out_date);
            $stmt->bindParam(':room_count', $room_count);
            $stmt->bindParam(':shared_room_info', $shared_room_info);
            $stmt->bindParam(':special_requirements', $special_requirements);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                $message = '酒店预订信息更新成功！';
                // 重定向回列表页
                header("refresh:2;url=hotel_reports_new.php");
            } else {
                $error = '更新失败，请重试！';
            }
        }
    }
}

// 获取要编辑的记录信息
$query = "SELECT hr.*, p.name as project_name, pr.name as personnel_name 
          FROM hotel_reports hr
          JOIN projects p ON hr.project_id = p.id
          JOIN personnel pr ON hr.personnel_id = pr.id
          WHERE hr.id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    $_SESSION['error'] = '记录不存在！';
    header("Location: hotel_reports_new.php");
    exit;
}

// 获取项目人员列表
$personnel_query = "SELECT id, name FROM personnel ORDER BY name ASC";
$personnel_stmt = $db->prepare($personnel_query);
$personnel_stmt->execute();
$personnel_list = $personnel_stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>编辑酒店预订 - 管理系统</title>
    <link href="assets/css/app.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="css/admin.css" rel="stylesheet">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">编辑酒店预订</h1>
                    <a href="hotel_reports_new.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> 返回列表
                    </a>
                </div>

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

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">预订信息</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="editHotelForm">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?php echo $report['id']; ?>">

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">项目</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($report['project_name']); ?>" readonly>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="personnel_id" class="form-label">人员姓名 <span class="text-danger">*</span></label>
                                        <select class="form-select" id="personnel_id" name="personnel_id" required>
                                            <option value="">选择人员</option>
                                            <?php foreach ($personnel_list as $person): ?>
                                                <option value="<?php echo $person['id']; ?>" 
                                                        <?php echo $report['personnel_id'] == $person['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($person['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="hotel_name" class="form-label">酒店名称 <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="hotel_name" name="hotel_name" 
                                               value="<?php echo htmlspecialchars($report['hotel_name']); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="room_type" class="form-label">房型 <span class="text-danger">*</span></label>
                                        <select class="form-select" id="room_type" name="room_type" required>
                                            <option value="">选择房型</option>
                                            <option value="标准间" <?php echo $report['room_type'] == '标准间' ? 'selected' : ''; ?>>标准间</option>
                                            <option value="大床房" <?php echo $report['room_type'] == '大床房' ? 'selected' : ''; ?>>大床房</option>
                                            <option value="双人房" <?php echo $report['room_type'] == '双人房' ? 'selected' : ''; ?>>双人房</option>
                                            <option value="套房" <?php echo $report['room_type'] == '套房' ? 'selected' : ''; ?>>套房</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="check_in_date" class="form-label">入住日期 <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="check_in_date" name="check_in_date" 
                                               value="<?php echo $report['check_in_date']; ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="check_out_date" class="form-label">退房日期 <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" id="check_out_date" name="check_out_date" 
                                               value="<?php echo $report['check_out_date']; ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="room_count" class="form-label">房间数 <span class="text-danger">*</span></label>
                                        <input type="number" class="form-control" id="room_count" name="room_count" 
                                               min="1" value="<?php echo $report['room_count']; ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label for="status" class="form-label">状态 <span class="text-danger">*</span></label>
                                        <select class="form-select" id="status" name="status" required>
                                            <option value="pending" <?php echo $report['status'] == 'pending' ? 'selected' : ''; ?>>待确认</option>
                                            <option value="confirmed" <?php echo $report['status'] == 'confirmed' ? 'selected' : ''; ?>>已确认</option>
                                            <option value="cancelled" <?php echo $report['status'] == 'cancelled' ? 'selected' : ''; ?>>已取消</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3" id="shared_room_container" style="display: none;">
                                <label for="shared_room_info" class="form-label">
                                    <i class="bi bi-people-fill"></i> 共享房间信息
                                    <span class="text-danger">*</span>
                                </label>
                                <textarea class="form-control" id="shared_room_info" name="shared_room_info" 
                                          rows="3" placeholder="请详细说明房间分配情况，例如：与张三共享房间"><?php echo htmlspecialchars($report['shared_room_info'] ?? ''); ?></textarea>
                                <div class="form-text">当选择双人房时，请填写共享房间的详细信息</div>
                            </div>

                            <div class="mb-3">
                                <label for="special_requirements" class="form-label">特殊要求</label>
                                <textarea class="form-control" id="special_requirements" name="special_requirements" 
                                          rows="2" placeholder="如有特殊要求请在此填写"><?php echo htmlspecialchars($report['special_requirements'] ?? ''); ?></textarea>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <a href="hotel_reports_new.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left"></i> 取消
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i> 保存修改
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="assets/js/app.min.js"></script>
    <script>
    // 房型选择变更时显示/隐藏共享房间信息输入框
    document.getElementById('room_type').addEventListener('change', function() {
        const roomType = this.value;
        const sharedRoomContainer = document.getElementById('shared_room_container');
        
        if (roomType === '双人房') {
            sharedRoomContainer.style.display = 'block';
            document.getElementById('shared_room_info').setAttribute('required', 'required');
        } else {
            sharedRoomContainer.style.display = 'none';
            document.getElementById('shared_room_info').removeAttribute('required');
            document.getElementById('shared_room_info').value = '';
        }
    });

    // 表单验证
    document.getElementById('editHotelForm').addEventListener('submit', function(e) {
        const checkInDate = new Date(document.getElementById('check_in_date').value);
        const checkOutDate = new Date(document.getElementById('check_out_date').value);
        const roomType = document.getElementById('room_type').value;
        
        if (checkOutDate <= checkInDate) {
            e.preventDefault();
            alert('退房日期必须晚于入住日期');
            return false;
        }
        
        // 双人房验证共享信息
        if (roomType === '双人房') {
            const sharedRoomInfo = document.getElementById('shared_room_info').value.trim();
            if (sharedRoomInfo === '') {
                e.preventDefault();
                alert('请填写共享房间信息，说明房间分配情况');
                return false;
            }
            
            if (sharedRoomInfo.length < 5) {
                e.preventDefault();
                alert('共享房间信息过于简单，请详细说明房间分配情况');
                return false;
            }
        }
    });

    // 页面加载时初始化房型显示
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('room_type').dispatchEvent(new Event('change'));
    });
    </script>

    <?php include 'includes/footer.php'; ?>
</body>
</html>