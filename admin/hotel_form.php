<?php
session_start();

// 引入基础控制器进行权限验证
require_once '../includes/BaseAdminController.php';
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

require_once '../config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
} catch (PDOException $e) {
    die("数据库连接失败: " . $e->getMessage());
}

$action = isset($_GET['action']) ? $_GET['action'] : 'add';
$edit_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$edit_hotel = null;
$error = '';
$message = '';

// 房型选项
$roomTypes = ['大床房', '双床房', '套房', '副总统套房', '总统套房'];

if ($action === 'edit' && $edit_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM hotels WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_hotel = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$edit_hotel) {
        header("Location: hotel_management.php?error=" . urlencode("酒店不存在"));
        exit;
    }
    
    // 解码房型
    $edit_hotel['room_types'] = json_decode($edit_hotel['room_types'], true);
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hotel_name_cn = trim($_POST['hotel_name_cn']);
    $hotel_name_en = trim($_POST['hotel_name_en']);
    $province = trim($_POST['province']);
    $city = trim($_POST['city']);
    $district = trim($_POST['district']);
    $address = trim($_POST['address']);
    $total_rooms = intval($_POST['total_rooms']);
    $room_types = isset($_POST['room_types']) ? $_POST['room_types'] : [];
    $notes = trim($_POST['notes']);

    // 验证输入
    if (empty($hotel_name_cn) || empty($hotel_name_en) || empty($province) || empty($city) || empty($address)) {
        $error = "请填写所有必填字段";
    } elseif ($total_rooms < 0) {
        $error = "总房数不能为负数";
    } else {
        $room_types_json = json_encode($room_types);
        
        try {
            if ($action === 'add') {
                $stmt = $conn->prepare("INSERT INTO hotels (hotel_name_cn, hotel_name_en, province, city, district, address, total_rooms, room_types, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$hotel_name_cn, $hotel_name_en, $province, $city, $district, $address, $total_rooms, $room_types_json, $notes]);
                $message = "酒店添加成功！";
            } else {
                $stmt = $conn->prepare("UPDATE hotels SET hotel_name_cn = ?, hotel_name_en = ?, province = ?, city = ?, district = ?, address = ?, total_rooms = ?, room_types = ?, notes = ? WHERE id = ?");
                $stmt->execute([$hotel_name_cn, $hotel_name_en, $province, $city, $district, $address, $total_rooms, $room_types_json, $notes, $edit_id]);
                $message = "酒店更新成功！";
            }
            
            // 重定向回列表页
            header("Location: hotel_management.php?message=" . urlencode($message));
            exit;
            
        } catch (PDOException $e) {
            $error = "操作失败: " . $e->getMessage();
        }
    }
}

$page_title = $action === 'add' ? '新增酒店' : '编辑酒店';

// 引入 header.php
include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="page-header">
                <h1 class="h3 mb-0">
                    <i class="bi bi-building"></i> <?php echo $page_title; ?>
                </h1>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-<?php echo $action === 'add' ? 'plus-circle' : 'pencil-square'; ?>"></i>
                        <?php echo $page_title; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="hotelForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="hotel_name_cn" class="form-label required">酒店中文名</label>
                                    <input type="text" class="form-control" id="hotel_name_cn" name="hotel_name_cn" 
                                           value="<?php echo $edit_hotel ? htmlspecialchars($edit_hotel['hotel_name_cn']) : ''; ?>" 
                                           required maxlength="255" placeholder="请输入酒店中文名称">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="hotel_name_en" class="form-label required">酒店英文名</label>
                                    <input type="text" class="form-control" id="hotel_name_en" name="hotel_name_en" 
                                           value="<?php echo $edit_hotel ? htmlspecialchars($edit_hotel['hotel_name_en']) : ''; ?>" 
                                           required maxlength="255" placeholder="请输入酒店英文名称">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="total_rooms" class="form-label">总房数</label>
                                    <input type="number" class="form-control" id="total_rooms" name="total_rooms" 
                                           value="<?php echo $edit_hotel ? htmlspecialchars($edit_hotel['total_rooms']) : ''; ?>" 
                                           min="0" max="9999" placeholder="请输入总房数（可选）">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <!-- 占位，保持布局平衡 -->
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="province" class="form-label required">省份</label>
                                    <input type="text" class="form-control" id="province" name="province" 
                                           value="<?php echo $edit_hotel ? htmlspecialchars($edit_hotel['province']) : ''; ?>" 
                                           required maxlength="100" placeholder="请输入省份">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="city" class="form-label required">城市</label>
                                    <input type="text" class="form-control" id="city" name="city" 
                                           value="<?php echo $edit_hotel ? htmlspecialchars($edit_hotel['city']) : ''; ?>" 
                                           required maxlength="100" placeholder="请输入城市">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="district" class="form-label">区县</label>
                                    <input type="text" class="form-control" id="district" name="district" 
                                           value="<?php echo $edit_hotel ? htmlspecialchars($edit_hotel['district']) : ''; ?>" 
                                           maxlength="100" placeholder="请输入区县（可选）">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="address" class="form-label required">详细地址</label>
                                    <input type="text" class="form-control" id="address" name="address" 
                                           value="<?php echo $edit_hotel ? htmlspecialchars($edit_hotel['address']) : ''; ?>" 
                                           required maxlength="500" placeholder="请输入详细地址">
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">房型</label>
                            <div class="border rounded p-3">
                                <?php foreach ($roomTypes as $type): ?>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="checkbox" name="room_types[]" 
                                               value="<?php echo $type; ?>" 
                                               id="room_type_<?php echo str_replace(' ', '_', $type); ?>"
                                               <?php echo ($edit_hotel && is_array($edit_hotel['room_types']) && in_array($type, $edit_hotel['room_types'])) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="room_type_<?php echo str_replace(' ', '_', $type); ?>">
                                            <?php echo $type; ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text">可选择房型（可选）</div>
                        </div>

                        <div class="mb-3">
                            <label for="notes" class="form-label">备注</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" maxlength="1000" 
                                      placeholder="请输入备注信息（可选）"><?php 
                                echo $edit_hotel ? htmlspecialchars($edit_hotel['notes']) : ''; 
                            ?></textarea>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="hotel_management.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> 返回列表
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle"></i>
                                <?php echo $action === 'add' ? '添加酒店' : '更新酒店'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // 表单验证 - 仅验证必填字段
    document.getElementById('hotelForm').addEventListener('submit', function(e) {
        const hotelNameCn = document.getElementById('hotel_name_cn').value.trim();
        const hotelNameEn = document.getElementById('hotel_name_en').value.trim();
        const province = document.getElementById('province').value.trim();
        const city = document.getElementById('city').value.trim();
        const address = document.getElementById('address').value.trim();
        
        if (!hotelNameCn || !hotelNameEn || !province || !city || !address) {
            e.preventDefault();
            alert('请填写所有必填字段！');
            return false;
        }
    });

    // 处理登出
    <?php if (isset($_GET['logout'])): ?>
    document.addEventListener('DOMContentLoaded', function() {
        window.location.href = 'login.php';
    });
    <?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>