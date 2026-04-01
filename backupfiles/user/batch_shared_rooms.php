<?php
require_once '../config/database.php';
session_start();

// 检查登录状态
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// 检查项目权限
if (!isset($_SESSION['project_id'])) {
    header("Location: ../select_project.php");
    exit;
}

$projectId = $_SESSION['project_id'];
$database = new Database();
$db = $database->getConnection();

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'batch_set_shared') {
        $selected_records = $_POST['selected_records'] ?? [];
        $shared_room_info = $_POST['shared_room_info'] ?? '';
        
        if (!empty($selected_records) && !empty($shared_room_info)) {
            try {
                $placeholders = implode(',', array_fill(0, count($selected_records), '?'));
                $query = "UPDATE hotel_reports SET shared_room_info = ? WHERE id IN ($placeholders) AND project_id = ?";
                
                $stmt = $db->prepare($query);
                $params = array_merge([$shared_room_info], $selected_records, [$projectId]);
                
                if ($stmt->execute($params)) {
                    $_SESSION['message'] = "成功为 {$stmt->rowCount()} 条记录设置共享房间信息";
                } else {
                    $_SESSION['message'] = "设置共享房间信息失败";
                }
            } catch (Exception $e) {
                $_SESSION['message'] = "错误: " . $e->getMessage();
            }
        } else {
            $_SESSION['message'] = "请选择记录和共享房间编号";
        }
        
        header("Location: batch_shared_rooms.php");
        exit;
    }
}

// 获取筛选参数
$filter_hotel = $_GET['hotel'] ?? '';
$filter_date = $_GET['date'] ?? '';
$filter_room_type = $_GET['room_type'] ?? '';

// 构建查询条件
$where_conditions = ["project_id = :project_id"];
$params = [':project_id' => $projectId];

if ($filter_hotel) {
    $where_conditions[] = "hotel_name LIKE :hotel_name";
    $params[':hotel_name'] = '%' . $filter_hotel . '%';
}

if ($filter_date) {
    $where_conditions[] = "check_in_date <= :filter_date AND check_out_date >= :filter_date";
    $params[':filter_date'] = $filter_date;
}

if ($filter_room_type) {
    $where_conditions[] = "room_type = :room_type";
    $params[':room_type'] = $filter_room_type;
}

$where_sql = implode(' AND ', $where_conditions);

// 获取酒店预订记录
$query = "SELECT hr.*, p.name as personnel_name, p.gender 
          FROM hotel_reports hr 
          JOIN personnel p ON hr.personnel_id = p.id 
          WHERE $where_sql 
          ORDER BY hr.hotel_name, hr.check_in_date, hr.room_type";

$stmt = $db->prepare($query);
$stmt->execute($params);
$hotels = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取所有酒店和房型用于筛选
$hotels_list = $db->query("SELECT DISTINCT hotel_name FROM hotel_reports WHERE project_id = $projectId ORDER BY hotel_name")
                  ->fetchAll(PDO::FETCH_COLUMN);
$room_types = $db->query("SELECT DISTINCT room_type FROM hotel_reports WHERE project_id = $projectId ORDER BY room_type")
                 ->fetchAll(PDO::FETCH_COLUMN);

// 设置页面变量
$page_title = '批量设置共享房间 - ' . $_SESSION['project_name'];
$active_page = 'batch_shared_rooms';
$show_page_title = '批量设置共享房间';
$page_icon = 'people-fill';

include 'includes/header.php';
?>

<div class="container mt-4">
    <?php if (isset($_SESSION['message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo $_SESSION['message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['message']); ?>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">筛选条件</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">酒店名称</label>
                    <select name="hotel" class="form-select">
                        <option value="">全部酒店</option>
                        <?php foreach ($hotels_list as $hotel): ?>
                            <option value="<?php echo htmlspecialchars($hotel); ?>" 
                                <?php echo $filter_hotel === $hotel ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($hotel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">房型</label>
                    <select name="room_type" class="form-select">
                        <option value="">全部房型</option>
                        <?php foreach ($room_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>" 
                                <?php echo $filter_room_type === $type ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">日期</label>
                    <input type="date" name="date" class="form-control" 
                           value="<?php echo htmlspecialchars($filter_date); ?>">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">筛选</button>
                    <a href="batch_shared_rooms.php" class="btn btn-secondary">重置</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header">
            <h5 class="mb-0">酒店预订记录 (<?php echo count($hotels); ?>条)</h5>
        </div>
        <div class="card-body">
            <?php if (empty($hotels)): ?>
                <p class="text-muted">暂无符合条件的记录</p>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="action" value="batch_set_shared">
                    
                    <div class="mb-3">
                        <label class="form-label">选择共享房间编号</label>
                        <select name="shared_room_info" class="form-select" required>
                            <option value="">请选择共享房间</option>
                            <option value="共享房间A">共享房间A</option>
                            <option value="共享房间B">共享房间B</option>
                            <option value="共享房间C">共享房间C</option>
                            <option value="共享房间D">共享房间D</option>
                            <option value="共享房间E">共享房间E</option>
                        </select>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th width="50">
                                        <input type="checkbox" id="select_all" class="form-check-input">
                                    </th>
                                    <th>酒店名称</th>
                                    <th>人员</th>
                                    <th>入住日期</th>
                                    <th>退房日期</th>
                                    <th>房型</th>
                                    <th>共享房间</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($hotels as $hotel): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="selected_records[]" 
                                                   value="<?php echo $hotel['id']; ?>" 
                                                   class="form-check-input record-checkbox">
                                        </td>
                                        <td>
                                            <?php 
                                            $hotelName = $hotel['hotel_name']; 
                                            if (strpos($hotelName, ' - ') !== false) {
                                                list($cn, $en) = explode(' - ', $hotelName, 2);
                                                echo htmlspecialchars($cn) . '<br><small class="text-muted">' . htmlspecialchars($en) . '</small>';
                                            } else {
                                                echo htmlspecialchars($hotelName);
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($hotel['personnel_name']); ?>
                                            <small class="text-muted">(<?php echo $hotel['gender']; ?>)</small>
                                        </td>
                                        <td><?php echo $hotel['check_in_date']; ?></td>
                                        <td><?php echo $hotel['check_out_date']; ?></td>
                                        <td><?php echo htmlspecialchars($hotel['room_type']); ?></td>
                                        <td>
                                            <?php if ($hotel['shared_room_info']): ?>
                                                <span class="badge bg-info"><?php echo htmlspecialchars($hotel['shared_room_info']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <button type="submit" class="btn btn-primary" onclick="return confirm('确定要为选中的记录设置共享房间吗？')">
                            批量设置共享房间
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.getElementById('select_all').addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.record-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = this.checked;
    });
});

// 仅显示双床房记录时启用全选
const roomTypeFilter = document.querySelector('select[name="room_type"]');
if (roomTypeFilter.value === '双床房') {
    document.getElementById('select_all').disabled = false;
} else {
    document.getElementById('select_all').disabled = true;
}
</script>

<?php include 'includes/footer.php'; ?>
