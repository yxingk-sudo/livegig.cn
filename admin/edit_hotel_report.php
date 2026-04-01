<?php
session_start();
require_once '../config/database.php';
// 更可靠地包含page_functions.php，如果文件不存在则定义默认函数
$page_functions_path = __DIR__ . '/page_functions.php';
if (file_exists($page_functions_path)) {
    require_once $page_functions_path;
} else {
    // 定义默认的getCurrentPage函数
    if (!function_exists('getCurrentPage')) {
        function getCurrentPage() {
            return basename($_SERVER['PHP_SELF']);
        }
    }
}

// 检查管理员是否已登录
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();

// 处理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// 获取所有项目
$stmt = $db->prepare("SELECT id, name, code FROM projects ORDER BY name");
$stmt->execute();
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取所有人员（按项目分组）
$personnel_list = [];
foreach ($projects as $project) {
    $stmt = $db->prepare("SELECT p.id, p.name, p.gender, 
                          GROUP_CONCAT(d.name ORDER BY d.name SEPARATOR ', ') as departments
                          FROM personnel p
                          LEFT JOIN project_department_personnel pdp ON p.id = pdp.personnel_id
                          LEFT JOIN departments d ON pdp.department_id = d.id
                          WHERE pdp.project_id = ?
                          GROUP BY p.id, p.name, p.gender
                          ORDER BY p.name");
    $stmt->execute([$project['id']]);
    $project_personnel = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $personnel_list[$project['id']] = $project_personnel;
}

$error = '';
$errors = [];
$edit_report = null;
$edit_id = 0;

// 处理编辑请求
if (isset($_GET['id'])) {
    $edit_id = intval($_GET['id']);
    
    // 获取要编辑的记录
    $stmt = $db->prepare("SELECT * FROM hotel_reports WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_report = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$edit_report) {
        $error = '找不到要编辑的记录';
    } else {
        $current_hotel = $edit_report['hotel_name'];
    }
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $id = intval($_POST['id']);
    $project_id = intval($_POST['project_id']);
    $personnel_id = intval($_POST['personnel_id']);
    $hotel_id = intval($_POST['hotel_id']);
    $hotel_name = trim($_POST['hotel_name']);
    $room_type = trim($_POST['room_type']);
    $check_in_date = $_POST['check_in_date'];
    $check_out_date = $_POST['check_out_date'];
    $room_count = intval($_POST['room_count']);
    $special_requirements = trim($_POST['special_requirements']);
    
    // 验证必填字段
    if (empty($project_id)) {
        $errors[] = '请选择项目';
    }
    if (empty($personnel_id)) {
        $errors[] = '请选择人员';
    }
    if (empty($hotel_id) || empty($hotel_name)) {
        $errors[] = '请选择酒店';
    }
    if (empty($room_type)) {
        $errors[] = '请选择房型';
    }
    if (empty($check_in_date)) {
        $errors[] = '请输入入住日期';
    }
    if (empty($check_out_date)) {
        $errors[] = '请输入退房日期';
    }
    if (empty($room_count) || $room_count < 1) {
        $errors[] = '房间数量必须大于0';
    }
    
    // 验证日期逻辑
    if (empty($errors) && $check_in_date >= $check_out_date) {
        $errors[] = '退房日期必须晚于入住日期';
    }
    
    if (empty($errors)) {
        try {
            $stmt = $db->prepare("UPDATE hotel_reports SET 
                project_id = ?, personnel_id = ?, hotel_id = ?, hotel_name = ?, room_type = ?, 
                check_in_date = ?, check_out_date = ?, room_count = ?, special_requirements = ? 
                WHERE id = ?");
            $stmt->execute([
                $project_id, $personnel_id, $hotel_id, $hotel_name, $room_type,
                $check_in_date, $check_out_date, $room_count, $special_requirements,
                $id
            ]);
            
            $_SESSION['success_message'] = '酒店入住记录更新成功';
            header("Location: hotel_reports.php");
            exit;
            
        } catch (Exception $e) {
            $errors[] = '更新失败：' . $e->getMessage();
        }
    }
}

$page_title = "编辑酒店入住记录";

include 'includes/header.php';
?>

<style>
    /* 样式已移至admin.css */
</style>

<div class="container-fluid">
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i> <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($errors) && !empty($errors)): ?>
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!empty($edit_report)): ?>
        <div class="card">
            <div class="card-header">
                <h5>编辑酒店入住记录</h5>
            </div>
            <div class="card-body">
                <form action="edit_hotel_report.php?id=<?php echo $edit_id; ?>" method="POST" class="mt-4">
                    <input type="hidden" name="id" value="<?php echo $edit_report['id']; ?>">
                    <input type="hidden" name="action" value="edit">
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="project_id" class="form-label">项目 <span class="text-danger">*</span></label>
                                <select class="form-control" id="project_id" name="project_id" required>
                                    <option value="">请选择项目</option>
                                    <?php foreach ($projects as $project): ?>
                                        <option value="<?php echo $project['id']; ?>" 
                                            <?php if ($edit_report['project_id'] == $project['id']) echo 'selected'; ?>>
                                            <?php echo $project['name'] . ' (' . $project['code'] . ')'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="personnel_id" class="form-label">人员 <span class="text-danger">*</span></label>
                                <select class="form-control" id="personnel_id" name="personnel_id" required>
                                    <option value="">请选择人员</option>
                                    <?php foreach ($personnel_list as $project_id => $project_personnel): ?>
                                        <?php foreach ($project_personnel as $person): ?>
                                            <option value="<?php echo $person['id']; ?>" 
                                                data-project="<?php echo $project_id; ?>"
                                                <?php if ($edit_report['personnel_id'] == $person['id']) echo 'selected'; ?>>
                                                <?php echo htmlspecialchars($person['name']); ?> 
                                                (<?php echo $person['gender']; ?>)
                                                <?php if (!empty($person['departments'])): ?>
                                                    - <?php echo htmlspecialchars($person['departments']); ?>
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="hotel_name" class="form-label">酒店名称 <span class="text-danger">*</span></label>
                                <select class="form-control" id="hotel_name" name="hotel_id" required onchange="updateRoomTypes()">
                                    <option value="">请选择酒店</option>
                                    <!-- 酒店选项会通过JavaScript动态加载 -->
                                </select>
                                <input type="hidden" id="hotel_name_hidden" name="hotel_name" value="<?php echo htmlspecialchars($current_hotel); ?>">
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="room_type" class="form-label">房型 <span class="text-danger">*</span></label>
                                <select class="form-control" id="room_type" name="room_type" required>
                                    <option value="">请选择房型</option>
                                    <!-- 房型选项会通过JavaScript动态加载 -->
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="check_in_date" class="form-label">入住日期 <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="check_in_date" name="check_in_date" 
                                    value="<?php echo $edit_report['check_in_date']; ?>" required>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="check_out_date" class="form-label">退房日期 <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="check_out_date" name="check_out_date" 
                                    value="<?php echo $edit_report['check_out_date']; ?>" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="room_count" class="form-label">房间数量 <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" id="room_count" name="room_count" 
                                    value="<?php echo $edit_report['room_count']; ?>" min="1" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="special_requirements" class="form-label">特殊要求</label>
                        <textarea class="form-control" id="special_requirements" name="special_requirements" 
                            rows="3"><?php echo $edit_report['special_requirements']; ?></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> 保存修改
                        </button>
                        <a href="hotel_reports.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> 返回列表
                        </a>
                    </div>
                </form>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-warning" role="alert">
            无法找到要编辑的记录，请检查ID是否正确。
        </div>
    <?php endif; ?>
</div>

<!-- JavaScript -->
<script>
    // 酒店数据
    var hotelData = <?php echo json_encode(getHotelData($db)); ?>;
    
    // 页面加载完成后初始化
    document.addEventListener('DOMContentLoaded', function() {
        // 初始化酒店选择
        var hotelSelect = document.getElementById('hotel_name');
        var currentHotel = document.getElementById('hotel_name_hidden').value;
        
        // 填充酒店选项
        hotelData.forEach(function(hotel) {
            var option = document.createElement('option');
            option.value = hotel.id;
            option.textContent = hotel.name + ' (' + hotel.city + ')';
            if (hotel.name === currentHotel) {
                option.selected = true;
            }
            hotelSelect.appendChild(option);
        });
        
        // 如果已有选中的酒店，加载对应的房型
        if (currentHotel) {
            var selectedHotel = hotelData.find(hotel => hotel.name === currentHotel);
            if (selectedHotel) {
                updateRoomTypes();
            }
        }
    });
    
    // 更新房型选项
    function updateRoomTypes() {
        var hotelSelect = document.getElementById('hotel_name');
        var roomTypeSelect = document.getElementById('room_type');
        var selectedHotelId = hotelSelect.value;
        var currentRoomType = '<?php echo $edit_report['room_type'] ?? ''; ?>';
        
        // 清空房型选项
        roomTypeSelect.innerHTML = '<option value="">请选择房型</option>';
        
        if (selectedHotelId) {
            // 查找选中的酒店
            var selectedHotel = hotelData.find(hotel => hotel.id == selectedHotelId);
            if (selectedHotel && selectedHotel.room_types) {
                // 填充房型选项
                selectedHotel.room_types.forEach(function(roomType) {
                    var option = document.createElement('option');
                    option.value = roomType;
                    option.textContent = roomType;
                    if (roomType === currentRoomType) {
                        option.selected = true;
                    }
                    roomTypeSelect.appendChild(option);
                });
            }
        }
    }
    
    // 根据项目过滤人员
    document.getElementById('project_id').addEventListener('change', function() {
        var projectId = this.value;
        var personnelOptions = document.querySelectorAll('#personnel_id option');
        
        personnelOptions.forEach(function(option) {
            if (projectId === '' || option.dataset.project === projectId) {
                option.style.display = 'block';
            } else {
                option.style.display = 'none';
            }
        });
        
        // 重置人员选择
        document.getElementById('personnel_id').value = '';
    });
</script>

<?php include 'includes/footer.php'; ?>