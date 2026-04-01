<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// 检查用户是否已登录
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 检查用户是否有项目权限
if (!isset($_SESSION['project_id'])) {
    header("Location: project.php");
    exit;
}

// 设置页面变量
$page_title = '添加物资 - 项目管理系统';
$active_page = 'add_material';
$show_page_title = '添加物资';
$page_icon = 'plus-circle';

// 包含头部
include 'includes/header.php';

// 初始化数据库
$database = new Database();
$db = $database->getConnection();

$project_id = $_SESSION['project_id'];
$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';
$success = false;

// 定义上传配置
$upload_dir = '../uploads/materials/';
$allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
$allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
$max_file_size = 5 * 1024 * 1024; // 5MB
$image_path = '';

// 确保上传目录存在
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// 获取项目信息
try {
    $query = "SELECT name FROM projects WHERE id = :project_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':project_id', $project_id);
    $stmt->execute();
    $project = $stmt->fetch(PDO::FETCH_ASSOC);
    $project_name = $project['name'] ?? '未知项目';
} catch (PDOException $e) {
    $message = "获取项目信息失败: " . $e->getMessage();
    $message_type = 'danger';
}

// 定义预设的房间信息和归属选项
$room_options = ['101房间', '102房间', '会议室A', '会议室B', '接待厅', '大堂'];
$belongs_to_options = ['后台物资', '酒店物资', '会议室用品', '接待用品', '办公用品', '其他'];

// 获取已使用过的房间信息和归属（用于增强选项）
try {
    $check_table = $db->query("SHOW TABLES LIKE 'materials'");
    if ($check_table->rowCount() > 0) {
        // 获取已使用过的房间信息
        $room_query = "SELECT DISTINCT room_info FROM materials WHERE project_id = :project_id AND room_info IS NOT NULL AND room_info != '' ORDER BY room_info";
        $room_stmt = $db->prepare($room_query);
        $room_stmt->bindParam(':project_id', $project_id);
        $room_stmt->execute();
        $existing_rooms = $room_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($existing_rooms as $room) {
            if (!in_array($room['room_info'], $room_options)) {
                $room_options[] = $room['room_info'];
            }
        }
        sort($room_options);
        
        // 获取已使用过的归属
        $belongs_query = "SELECT DISTINCT belongs_to FROM materials WHERE project_id = :project_id AND belongs_to IS NOT NULL AND belongs_to != '' ORDER BY belongs_to";
        $belongs_stmt = $db->prepare($belongs_query);
        $belongs_stmt->bindParam(':project_id', $project_id);
        $belongs_stmt->execute();
        $existing_belongs = $belongs_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($existing_belongs as $belongs) {
            if (!in_array($belongs['belongs_to'], $belongs_to_options)) {
                $belongs_to_options[] = $belongs['belongs_to'];
            }
        }
        sort($belongs_to_options);
    }
} catch (PDOException $e) {
    // 如果查询失败，继续使用预设选项
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $material_name = trim($_POST['material_name'] ?? '');
        $room_info = trim($_POST['room_info'] ?? '');
        $quantity = trim($_POST['quantity'] ?? '');
        $belongs_to = trim($_POST['belongs_to'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');
        
        // 验证必填字段
        if (empty($material_name)) {
            throw new Exception('物品名称不能为空');
        }
        
        if (empty($quantity) || !is_numeric($quantity) || (int)$quantity <= 0) {
            throw new Exception('数量必须是正整数');
        }
        
        $quantity = (int)$quantity;
        
        // 验证归属字段长度
        if (strlen($belongs_to) > 100) {
            throw new Exception('归属信息长度不能超过100个字符');
        }
        
        // 处理图片上传
        if (!empty($_FILES['image']['name'])) {
            $file_name = $_FILES['image']['name'];
            $file_tmp = $_FILES['image']['tmp_name'];
            $file_size = $_FILES['image']['size'];
            $file_type = $_FILES['image']['type'];
            
            // 验证文件大小
            if ($file_size > $max_file_size) {
                throw new Exception('图片大小不能超过5MB');
            }
            
            // 验证文件类型
            if (!in_array($file_type, $allowed_types)) {
                throw new Exception('只支持jpg、jpeg、png、gif格式的图片');
            }
            
            // 验证文件扩展名
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            if (!in_array($file_ext, $allowed_extensions)) {
                throw new Exception('只支持jpg、jpeg、png、gif格式的图片');
            }
            
            // 生成唯一文件名
            $new_filename = 'material_' . $project_id . '_' . time() . '_' . uniqid() . '.' . $file_ext;
            $upload_path = $upload_dir . $new_filename;
            
            // 移动上传的文件
            if (!move_uploaded_file($file_tmp, $upload_path)) {
                throw new Exception('图片上传失败，请稍后重试');
            }
            
            $image_path = 'uploads/materials/' . $new_filename;
        }
        
        // 首先检查物资表是否存在
        $check_table = $db->query("SHOW TABLES LIKE 'materials'");
        if ($check_table->rowCount() == 0) {
            // 创建物资表
            $create_table_sql = "
            CREATE TABLE IF NOT EXISTS materials (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_id INT NOT NULL,
                material_name VARCHAR(255) NOT NULL COMMENT '物品名称',
                room_info VARCHAR(255) COMMENT '房间信息',
                belongs_to VARCHAR(100) COMMENT '归属',
                quantity INT NOT NULL COMMENT '数量',
                image_path VARCHAR(255) COMMENT '图片路径',
                remarks TEXT COMMENT '备注',
                created_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES project_users(id) ON DELETE CASCADE,
                INDEX idx_project (project_id),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $db->exec($create_table_sql);
        } else {
            // 检查表中是否存在 belongs_to 字段，如果不存在则添加
            $check_column = $db->query("SHOW COLUMNS FROM materials LIKE 'belongs_to'");
            if ($check_column->rowCount() == 0) {
                // 添加 belongs_to 字段
                $db->exec("ALTER TABLE materials ADD COLUMN belongs_to VARCHAR(100) COMMENT '归属' AFTER room_info");
            }
        }
        
        // 插入物资数据
        $insert_query = "
        INSERT INTO materials (project_id, material_name, room_info, belongs_to, quantity, image_path, remarks, created_by)
        VALUES (:project_id, :material_name, :room_info, :belongs_to, :quantity, :image_path, :remarks, :created_by)
        ";
        
        $insert_stmt = $db->prepare($insert_query);
        $insert_stmt->execute([
            ':project_id' => $project_id,
            ':material_name' => $material_name,
            ':room_info' => $room_info,
            ':belongs_to' => $belongs_to,
            ':quantity' => $quantity,
            ':image_path' => $image_path,
            ':remarks' => $remarks,
            ':created_by' => $user_id
        ]);
        
        $success = true;
        $message = '物资添加成功！';
        $message_type = 'success';
        
        // 清空表单
        $_POST = [];
        
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = 'danger';
        
        // 如果上传了文件但提交失败，删除上传的文件
        if (!empty($image_path) && file_exists($upload_dir . basename($image_path))) {
            unlink($upload_dir . basename($image_path));
        }
    }
}
?>

<div class="container mt-4">
    <div class="row">
        <div class="col-md-8 offset-md-2">
            <!-- 消息提示 -->
            <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <i class="bi bi-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- 成功后的操作选项 -->
            <?php if ($success): ?>
            <div class="alert alert-info">
                <p class="mb-2">您还可以：</p>
                <a href="./add_material.php" class="btn btn-primary btn-sm me-2">
                    <i class="bi bi-plus-circle"></i> 继续添加物资
                </a>
                <a href="./materials_all.php" class="btn btn-secondary btn-sm">
                    <i class="bi bi-list"></i> 查看全部物资
                </a>
            </div>
            <?php endif; ?>
            
            <!-- 物资添加表单 -->
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-box-seam"></i> 添加物资
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" id="materialForm">
                        <!-- 项目信息（只读） -->
                        <div class="mb-3">
                            <label for="project_name" class="form-label">项目</label>
                            <input type="text" class="form-control" id="project_name" value="<?php echo htmlspecialchars($project_name); ?>" readonly>
                        </div>
                        
                        <!-- 物品名称 -->
                        <div class="mb-3">
                            <label for="material_name" class="form-label">
                                <i class="bi bi-asterisk text-danger"></i> 物品名称
                            </label>
                            <input type="text" class="form-control" id="material_name" name="material_name" 
                                   value="<?php echo htmlspecialchars($_POST['material_name'] ?? ''); ?>"
                                   placeholder="请输入物品名称（如：办公桌、椅子、地毯等）" required>
                            <small class="text-muted">请输入具体的物品名称</small>
                        </div>
                        
                        <!-- 房间信息 -->
                        <div class="mb-3">
                            <label for="room_info_select" class="form-label">房间信息</label>
                            <div class="input-group">
                                <select class="form-select" id="room_info_select" onchange="updateRoomInfo()">
                                    <option value="">-- 从下拉选择一个或自由填写 --</option>
                                    <?php foreach ($room_options as $option): ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="input-group-text">或</span>
                                <input type="text" class="form-control" id="room_info" name="room_info"
                                       value="<?php echo htmlspecialchars($_POST['room_info'] ?? ''); ?>"
                                       placeholder="自由填写房间信息" onchange="updateRoomSelect()">
                            </div>
                            <small class="text-muted">可选，下拉选择或自由填写房间信息</small>
                        </div>
                        
                        <!-- 归属 -->
                        <div class="mb-3">
                            <label for="belongs_to_select" class="form-label">归属</label>
                            <div class="input-group">
                                <select class="form-select" id="belongs_to_select" onchange="updateBelongsTo()">
                                    <option value="">-- 从下拉选择一个或自由填写 --</option>
                                    <?php foreach ($belongs_to_options as $option): ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>"><?php echo htmlspecialchars($option); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="input-group-text">或</span>
                                <input type="text" class="form-control" id="belongs_to" name="belongs_to"
                                       value="<?php echo htmlspecialchars($_POST['belongs_to'] ?? ''); ?>"
                                       placeholder="自由填写归属信息" maxlength="100" onchange="updateBelongsSelect()">
                            </div>
                            <small class="text-muted">可选，最多100个字符。支持中英文。下拉选择或自由填写</small>
                        </div>
                        
                        <!-- 数量 -->
                        <div class="mb-3">
                            <label for="quantity" class="form-label">
                                <i class="bi bi-asterisk text-danger"></i> 数量
                            </label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="quantity" name="quantity" 
                                       value="<?php echo htmlspecialchars($_POST['quantity'] ?? ''); ?>"
                                       placeholder="请输入数量" min="1" required>
                                <span class="input-group-text">件</span>
                            </div>
                            <small class="text-muted">只允许输入正整数</small>
                        </div>
                        
                        <!-- 图片上传 -->
                        <div class="mb-3">
                            <label for="image" class="form-label">图片</label>
                            <div class="mb-2">
                                <input type="file" class="form-control" id="image" name="image" 
                                       accept=".jpg,.jpeg,.png,.gif" onchange="previewImage(this)">
                                <small class="text-muted">支持jpg、jpeg、png、gif格式，大小不超过5MB</small>
                            </div>
                            <!-- 图片预览 -->
                            <div id="imagePreview" style="display: none;">
                                <img id="previewImg" src="" alt="图片预览" style="max-width: 100%; max-height: 300px; border-radius: 8px; border: 1px solid #ddd; padding: 5px;">
                                <div class="mt-2">
                                    <small class="text-muted">图片预览</small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 备注 -->
                        <div class="mb-3">
                            <label for="remarks" class="form-label">备注</label>
                            <textarea class="form-control" id="remarks" name="remarks" rows="4"
                                      placeholder="请输入备注信息（可选）"><?php echo htmlspecialchars($_POST['remarks'] ?? ''); ?></textarea>
                            <small class="text-muted">可用于记录物品的额外信息、状态或其他说明</small>
                        </div>
                        
                        <!-- 提交按钮 -->
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="./materials_all.php" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left"></i> 取消
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle"></i> 添加物资
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- 帮助信息 -->
            <div class="alert alert-light mt-4 border">
                <h6 class="mb-3"><i class="bi bi-info-circle"></i> 使用提示</h6>
                <ul class="mb-0">
                    <li>物品名称和数量为必填项</li>
                    <li>房间信息下拉选择或自由填写，你的选择将会自动同步</li>
                    <li>归属信息下拉选择或自由填写，下拉列表中包含已使用的分类</li>
                    <li>建议上传物品的清晰照片以便识别</li>
                    <li>备注可记录物品的特殊属性或状态</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
// 图片预览功能
function previewImage(input) {
    const previewDiv = document.getElementById('imagePreview');
    const previewImg = document.getElementById('previewImg');
    
    if (input.files && input.files[0]) {
        const file = input.files[0];
        
        // 验证文件大小
        if (file.size > 5 * 1024 * 1024) {
            alert('文件大小不能超过5MB');
            input.value = '';
            previewDiv.style.display = 'none';
            return;
        }
        
        // 验证文件类型
        const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
        if (!validTypes.includes(file.type)) {
            alert('只支持jpg、jpeg、png、gif格式的图片');
            input.value = '';
            previewDiv.style.display = 'none';
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            previewDiv.style.display = 'block';
        };
        reader.readAsDataURL(file);
    } else {
        previewDiv.style.display = 'none';
    }
}

// 下拉选择和自由填写的互动函数
function updateRoomInfo() {
    const select = document.getElementById('room_info_select');
    const input = document.getElementById('room_info');
    if (select.value) {
        input.value = select.value;
    }
}

function updateRoomSelect() {
    const input = document.getElementById('room_info');
    const select = document.getElementById('room_info_select');
    select.value = input.value;
}

function updateBelongsTo() {
    const select = document.getElementById('belongs_to_select');
    const input = document.getElementById('belongs_to');
    if (select.value) {
        input.value = select.value;
    }
}

function updateBelongsSelect() {
    const input = document.getElementById('belongs_to');
    const select = document.getElementById('belongs_to_select');
    select.value = input.value;
}
</script>

<?php include 'includes/footer.php'; ?>
</body>
</html>
