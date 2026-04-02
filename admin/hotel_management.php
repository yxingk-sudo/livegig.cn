<?php
session_start();

// 引入基础控制器进行权限验证
require_once '../includes/BaseAdminController.php';
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

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();

$message = '';
$error = '';

// 房型选项
$roomTypes = [
    '大床房' => '大床房',
    '双床房' => '双床房', 
    '套房' => '套房',
    '副总统套房' => '副总统套房',
    '总统套房' => '总统套房'
];

// 处理删除操作
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $delete_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    
    if ($delete_id > 0) {
        try {
            $query = "DELETE FROM hotels WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $delete_id);
            
            if ($stmt->execute()) {
                $message = "酒店删除成功！";
            } else {
                $error = "删除失败，请重试！";
            }
        } catch (PDOException $e) {
            $error = "删除失败: " . $e->getMessage();
        }
    }
}

// 获取酒店列表
$action = $_GET['action'] ?? 'list';
$edit_id = $_GET['id'] ?? 0;

// 获取单个酒店信息（编辑时）
$edit_hotel = null;
if ($action === 'edit' && $edit_id > 0) {
    $query = "SELECT * FROM hotels WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $edit_id);
    $stmt->execute();
    $edit_hotel = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($edit_hotel) {
        $edit_hotel['room_types'] = json_decode($edit_hotel['room_types'], true);
    }
}

// 获取搜索和筛选参数
$search = $_GET['search'] ?? '';
$province_filter = $_GET['province'] ?? '';
$city_filter = $_GET['city'] ?? '';
$room_type_filter = $_GET['room_type'] ?? '';

// 构建查询条件
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(hotel_name_cn LIKE :search OR hotel_name_en LIKE :search2 OR address LIKE :search3)";
    $params[':search'] = "%{$search}%";
    $params[':search2'] = "%{$search}%";
    $params[':search3'] = "%{$search}%";
}

if (!empty($province_filter)) {
    $where_conditions[] = "province = :province";
    $params[':province'] = $province_filter;
}

if (!empty($city_filter)) {
    $where_conditions[] = "city = :city";
    $params[':city'] = $city_filter;
}

if (!empty($room_type_filter)) {
    $where_conditions[] = "JSON_CONTAINS(room_types, :room_type)";
    $params[':room_type'] = json_encode($room_type_filter);
}

// 构建完整查询
$where_sql = $where_conditions ? "WHERE " . implode(" AND ", $where_conditions) : "";
$query = "SELECT h.* FROM hotels h {$where_sql} ORDER BY h.created_at DESC";
$stmt = $db->prepare($query);

// 绑定参数
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->execute();
$hotels = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取搜索和筛选参数
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$selectedProvince = isset($_GET['province']) ? trim($_GET['province']) : '';
$selectedCity = isset($_GET['city']) ? trim($_GET['city']) : '';
$selectedRoomType = isset($_GET['room_type']) ? trim($_GET['room_type']) : '';

// 获取筛选选项
$provinces = [];
$cities = [];

$stmt = $db->query("SELECT DISTINCT province FROM hotels ORDER BY province");
$provinces = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $db->query("SELECT DISTINCT city FROM hotels ORDER BY city");
$cities = $stmt->fetchAll(PDO::FETCH_COLUMN);

// 处理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}
?>

<?php include 'includes/header.php'; ?>

<!-- 为酒店管理页面添加自定义样式 -->
<style>
/* Espire 风格的卡片样式 */
.hotel-card {
    border: none;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    border-radius: 0.5rem;
    transition: all 0.3s ease;
    height: 100%;
    background: #fff;
}

.hotel-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
}

/* 醒目的卡片标题样式 */
.hotel-card .card-header {
    background: linear-gradient(135deg, #11a1fd 0%, #0d6efd 100%);
    color: white;
    border-radius: 0.5rem 0.5rem 0 0 !important;
    padding: 1rem 1.25rem;
    border: none;
    position: relative;
    overflow: hidden;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.15);
}

.hotel-card .card-header .card-title {
    color: white;
    font-weight: 600;
    font-size: 1.15rem;
    margin-bottom: 0;
    text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
}

.hotel-card .card-header .card-title small {
    color: rgba(255, 255, 255, 0.95);
    font-weight: normal;
    font-size: 0.9rem;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}

.hotel-card .card-body {
    padding: 1.25rem;
}

.hotel-card .card-footer {
    background-color: #f8f9fa;
    border-top: 1px solid #e9ecef;
    padding: 1rem 1.25rem;
    border-radius: 0 0 0.5rem 0.5rem !important;
}

/* Espire 风格的搜索表单 */
.filter-card {
    border: none;
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    border-radius: 0.5rem;
    background: #fff;
    margin-bottom: 1.5rem;
}

.filter-card .card-header {
    background-color: #f8f9fa;
    color: #495057;
    border-radius: 0.5rem 0.5rem 0 0 !important;
    padding: 1rem 1.25rem;
    border: none;
    font-weight: 600;
}

.filter-card .card-body {
    padding: 1.5rem;
}

/* Espire 风格的按钮 */
.btn-primary {
    background-color: #11a1fd;
    border-color: #11a1fd;
    border-radius: 0.375rem;
    font-weight: 500;
    padding: 0.5rem 1rem;
}

.btn-primary:hover {
    background-color: #0e89d7;
    border-color: #0e81ca;
    transform: translateY(-1px);
    box-shadow: 0 0.25rem 0.5rem rgba(17, 161, 253, 0.25);
}

.btn-warning {
    background-color: #ff9842;
    border-color: #ff9842;
    border-radius: 0.375rem;
    font-weight: 500;
    padding: 0.375rem 0.75rem;
}

.btn-warning:hover {
    background-color: #d98138;
    border-color: #cc7a35;
    transform: translateY(-1px);
    box-shadow: 0 0.25rem 0.5rem rgba(255, 152, 66, 0.25);
}

.btn-danger {
    background-color: #f46363;
    border-color: #f46363;
    border-radius: 0.375rem;
    font-weight: 500;
    padding: 0.375rem 0.75rem;
}

.btn-danger:hover {
    background-color: #cf5454;
    border-color: #c34f4f;
    transform: translateY(-1px);
    box-shadow: 0 0.25rem 0.5rem rgba(244, 99, 99, 0.25);
}

.btn-secondary {
    background-color: #e4eef5;
    border-color: #e4eef5;
    border-radius: 0.375rem;
    font-weight: 500;
    padding: 0.5rem 1rem;
    color: #495057;
}

.btn-secondary:hover {
    background-color: #d8e2eb;
    border-color: #d0dbe5;
    transform: translateY(-1px);
    box-shadow: 0 0.25rem 0.5rem rgba(228, 238, 245, 0.25);
}

/* Espire 风格的表单控件 */
.form-control, .form-select {
    border-radius: 0.375rem;
    border: 1px solid #ced4da;
    padding: 0.5rem 1rem;
    transition: all 0.15s ease-in-out;
}

.form-control:focus, .form-select:focus {
    border-color: #8fd3fe;
    box-shadow: 0 0 0 0.2rem rgba(17, 161, 253, 0.25);
}

.form-label {
    font-weight: 500;
    color: #495057;
    margin-bottom: 0.5rem;
}

/* Espire 风格的空状态 */
.empty-state {
    text-align: center;
    padding: 3rem 1rem;
    color: #6c757d;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}

.empty-state h4 {
    font-weight: 600;
    color: #495057;
    margin-bottom: 1rem;
}

/* Espire 风格的徽章 */
.badge-primary {
    background-color: #11a1fd;
    color: white;
    font-weight: 500;
    padding: 0.5em 0.75em;
    border-radius: 0.375rem;
}

.badge-success {
    background-color: #00c569;
    color: white;
    font-weight: 500;
    padding: 0.5em 0.75em;
    border-radius: 0.375rem;
}

/* 响应式优化 */
@media (max-width: 768px) {
    .hotel-card .card-header h5 {
        font-size: 1rem;
    }
    
    .filter-card .card-body {
        padding: 1rem;
    }
    
    .btn {
        padding: 0.375rem 0.75rem;
        font-size: 0.875rem;
    }
}

/* 页面标题样式 */
.page-title {
    font-weight: 600;
    color: #343a40;
    margin-bottom: 1.5rem;
}

.page-title i {
    color: #11a1fd;
}
</style>

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

    <?php if ($action === 'add'): ?>
        <?php header("Location: hotel_form.php"); exit; ?>
    <?php elseif ($action === 'edit' && $edit_id > 0): ?>
        <?php header("Location: hotel_form.php?id=" . $edit_id); exit; ?>
    <?php else: ?>
        <!-- 酒店列表 -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="page-title"><i class="bi bi-building"></i> 酒店信息管理</h2>
            <a href="hotel_form.php" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> 新增酒店
            </a>
        </div>

        <!-- 搜索和筛选表单 -->
        <div class="card filter-card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-funnel"></i> 搜索和筛选</h5>
            </div>
            <div class="card-body">
                <form method="GET" action="hotel_management.php" class="row g-3">
                    <div class="col-md-3">
                        <label for="search" class="form-label">搜索关键词</label>
                        <input type="text" class="form-control" id="search" name="search" 
                               placeholder="酒店名称或地址" value="<?php echo htmlspecialchars($searchTerm); ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="province_filter" class="form-label">省份</label>
                        <select class="form-select" id="province_filter" name="province">
                            <option value="">所有省份</option>
                            <?php foreach ($provinces as $province): ?>
                                <option value="<?php echo htmlspecialchars($province); ?>" 
                                        <?php echo $selectedProvince === $province ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($province); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="city_filter" class="form-label">城市</label>
                        <select class="form-select" id="city_filter" name="city">
                            <option value="">所有城市</option>
                            <?php foreach ($cities as $city): ?>
                                <option value="<?php echo htmlspecialchars($city); ?>" 
                                        <?php echo $selectedCity === $city ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($city); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="room_type_filter" class="form-label">房型</label>
                        <select class="form-select" id="room_type_filter" name="room_type">
                            <option value="">所有房型</option>
                            <?php foreach ($roomTypes as $type): ?>
                                <option value="<?php echo htmlspecialchars($type); ?>" 
                                        <?php echo $selectedRoomType === $type ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">操作</label>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-search"></i> 搜索
                            </button>
                            <a href="hotel_management.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-clockwise"></i> 重置
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php if (empty($hotels)): ?>
            <div class="empty-state">
                <i class="bi bi-building"></i>
                <h4>暂无酒店信息</h4>
                <p class="mb-4">
                    <?php if ($searchTerm || $selectedProvince || $selectedCity || $selectedRoomType): ?>
                        没有找到符合条件的酒店，请尝试调整搜索条件
                    <?php else: ?>
                        点击下方按钮添加第一个酒店
                    <?php endif; ?>
                </p>
                <a href="hotel_management.php?action=add" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> 添加酒店
                </a>
            </div>
        <?php else: ?>
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <span class="text-muted">
                        共找到 <strong><?php echo count($hotels); ?></strong> 家酒店
                        <?php if ($searchTerm || $selectedProvince || $selectedCity || $selectedRoomType): ?>
                            <span class="badge badge-success ms-2">
                                搜索筛选结果
                            </span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            <div class="row">
                <?php foreach ($hotels as $hotel): ?>
                    <?php 
                    $roomTypesData = json_decode($hotel['room_types'], true);
                    $roomTypesText = is_array($roomTypesData) ? implode('、', $roomTypesData) : $hotel['room_types'];
                    ?>
                    <div class="col-md-6 col-lg-4 mb-4">
                        <div class="card hotel-card h-100">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <?php 
                                    if (isset($hotel['hotel_name_cn'])) {
                                        echo htmlspecialchars($hotel['hotel_name_cn']);
                                    } elseif (isset($hotel['hotel_name'])) {
                                        echo htmlspecialchars($hotel['hotel_name']);
                                    } else {
                                        echo '未知酒店';
                                    }
                                    ?>
                                    <?php if (!empty($hotel['hotel_name_en'])): ?>
                                        <br><small class="opacity-75 fw-normal"><?php echo htmlspecialchars($hotel['hotel_name_en']); ?></small>
                                    <?php endif; ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <p class="card-text mb-2">
                                    <strong><i class="bi bi-geo-alt text-primary"></i> 地址：</strong>
                                    <span class="text-muted"><?php 
                                        echo htmlspecialchars($hotel['province'] . $hotel['city'] . $hotel['district'] . $hotel['address']); 
                                    ?></span>
                                </p>
                                <p class="card-text mb-2">
                                    <strong><i class="bi bi-door-open text-primary"></i> 房型：</strong>
                                    <span class="text-muted"><?php echo htmlspecialchars($roomTypesText); ?></span>
                                </p>
                                <p class="card-text mb-2">
                                    <strong><i class="bi bi-hash text-primary"></i> 总房数：</strong>
                                    <span class="text-muted"><?php echo htmlspecialchars($hotel['total_rooms']); ?> 间</span>
                                </p>
                                <?php if ($hotel['notes']): ?>
                                    <p class="card-text mb-2">
                                        <strong><i class="bi bi-sticky text-primary"></i> 备注：</strong>
                                        <span class="text-muted"><?php echo htmlspecialchars($hotel['notes']); ?></span>
                                    </p>
                                <?php endif; ?>
                                <p class="card-text mb-0">
                                    <small class="text-muted">
                                        <i class="bi bi-person"></i> 创建：<?php echo htmlspecialchars($hotel['created_by_name'] ?? '系统'); ?><br>
                                        <i class="bi bi-clock"></i> 时间：<?php echo date('Y-m-d H:i', strtotime($hotel['created_at'])); ?>
                                    </small>
                                </p>
                            </div>
                            <div class="card-footer">
                                <div class="d-flex justify-content-between">
                                    <a href="hotel_form.php?action=edit&id=<?php echo $hotel['id']; ?>"
                                   class="btn btn-warning btn-sm">
                                    <i class="bi bi-pencil"></i> 编辑
                                </a>
                                    <button type="button" class="btn btn-danger btn-sm" 
                                            onclick="confirmDelete(<?php echo $hotel['id']; ?>, '<?php echo htmlspecialchars(addslashes(isset($hotel['hotel_name_cn']) ? $hotel['hotel_name_cn'] : (isset($hotel['hotel_name']) ? $hotel['hotel_name'] : '未知酒店'))); ?>')">
                                        <i class="bi bi-trash"></i> 删除
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
    function confirmDelete(id, hotelName) {
        const confirmationText = prompt(`您正在删除酒店："${hotelName}"

此操作将永久删除该酒店的所有信息，不可恢复！

请在下方输入框中输入以下内容以确认删除：
"我确认删除此酒店"`);
        
        if (confirmationText === "我确认删除此酒店") {
            // 创建隐藏的表单提交删除请求
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete';
            form.appendChild(actionInput);
            
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'id';
            idInput.value = id;
            form.appendChild(idInput);
            
            document.body.appendChild(form);
            form.submit();
        } else if (confirmationText !== null) {
            alert('输入内容不正确，删除操作已取消。');
        }
    }
</script>

<?php include 'includes/footer.php'; ?>