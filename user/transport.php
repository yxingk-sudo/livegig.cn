<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

session_start();
// 引入权限中间件进行权限验证
require_once '../includes/PermissionMiddleware.php';
$database = new Database();
$db = $database->getConnection();
$middleware = new PermissionMiddleware($db);
$middleware->checkUserPagePermission('frontend:transport:list');

requireLogin();
checkProjectPermission($_SESSION['project_id']);

$projectId = $_SESSION['project_id'];
$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? 0;

// 获取项目人员
$personnel = getProjectPersonnel($projectId, $db);

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($action == 'add') {
        // 新增交通预订
        $personnel_id = $_POST['personnel_id'];
        $travel_date = $_POST['travel_date'];
        $travel_type = $_POST['travel_type'];
        $departure_location = $_POST['departure_location'];
        $destination_location = $_POST['destination_location'];
        $departure_time = $_POST['departure_time'];
        $passenger_count = $_POST['passenger_count'];
        $contact_phone = $_POST['contact_phone'];
        $special_requirements = $_POST['special_requirements'];
        
        $query = "INSERT INTO transportation_reports (project_id, personnel_id, travel_date, travel_type, departure_location, destination_location, departure_time, passenger_count, contact_phone, special_requirements, reported_by) 
                  VALUES (:project_id, :personnel_id, :travel_date, :travel_type, :departure_location, :destination_location, :departure_time, :passenger_count, :contact_phone, :special_requirements, :reported_by)";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':project_id', $projectId);
        $stmt->bindParam(':personnel_id', $personnel_id);
        $stmt->bindParam(':travel_date', $travel_date);
        $stmt->bindParam(':travel_type', $travel_type);
        $stmt->bindParam(':departure_location', $departure_location);
        $stmt->bindParam(':destination_location', $destination_location);
        $stmt->bindParam(':departure_time', $departure_time);
        $stmt->bindParam(':passenger_count', $passenger_count);
        $stmt->bindParam(':contact_phone', $contact_phone);
        $stmt->bindParam(':special_requirements', $special_requirements);
        $stmt->bindParam(':reported_by', $_SESSION['user_id']);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "交通预订添加成功";
            header("Location: transport.php");
            exit;
        }
    } elseif ($action == 'edit' && $id) {
        // 编辑交通预订
        $personnel_id = $_POST['personnel_id'];
        $travel_date = $_POST['travel_date'];
        $travel_type = $_POST['travel_type'];
        $departure_location = $_POST['departure_location'];
        $destination_location = $_POST['destination_location'];
        $departure_time = $_POST['departure_time'];
        $passenger_count = $_POST['passenger_count'];
        $contact_phone = $_POST['contact_phone'];
        $special_requirements = $_POST['special_requirements'];
        
        $query = "UPDATE transportation_reports SET 
                  personnel_id = :personnel_id, 
                  travel_date = :travel_date, 
                  travel_type = :travel_type, 
                  departure_location = :departure_location, 
                  destination_location = :destination_location, 
                  departure_time = :departure_time, 
                  passenger_count = :passenger_count, 
                  contact_phone = :contact_phone, 
                  special_requirements = :special_requirements 
                  WHERE id = :id AND project_id = :project_id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':personnel_id', $personnel_id);
        $stmt->bindParam(':travel_date', $travel_date);
        $stmt->bindParam(':travel_type', $travel_type);
        $stmt->bindParam(':departure_location', $departure_location);
        $stmt->bindParam(':destination_location', $destination_location);
        $stmt->bindParam(':departure_time', $departure_time);
        $stmt->bindParam(':passenger_count', $passenger_count);
        $stmt->bindParam(':contact_phone', $contact_phone);
        $stmt->bindParam(':special_requirements', $special_requirements);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':project_id', $projectId);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "交通预订更新成功";
            header("Location: transport.php");
            exit;
        }
    } elseif ($action == 'delete' && $id) {
        // 删除交通预订
        $query = "DELETE FROM transportation_reports WHERE id = :id AND project_id = :project_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':project_id', $projectId);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "交通预订删除成功";
        }
        header("Location: transport.php");
        exit;
    }
}

// 获取单个记录（编辑时）
$transport = null;  // 应该是 $transport = null;
if ($action == 'edit' && $id) {
    $query = "SELECT * FROM transportation_reports WHERE id = :id AND project_id = :project_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $id);
    $stmt->bindParam(':project_id', $projectId);
    $stmt->execute();
    $transport = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 获取当前项目的交通预订记录
$query = "SELECT tr.*, p.name as personnel_name 
          FROM transportation_reports tr 
          LEFT JOIN personnel p ON tr.personnel_id = p.id 
          WHERE tr.project_id = :project_id 
          ORDER BY tr.travel_date DESC, tr.created_at DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':project_id', $projectId);
$stmt->execute();
$transports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 显示消息
$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);
?>
<?php
// 设置页面特定变量
$page_title = '交通预订管理 - ' . ($_SESSION['project_name'] ?? '');
$active_page = 'transport';
$show_page_title = '交通预订管理';
$page_icon = 'truck';
$page_action_text = '新增预订';
$page_action_url = 'transport.php?action=add';

// 包含统一头部文件
include 'includes/header.php';
?>
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($action == 'add' || $action == 'edit'): ?>
            <!-- 表单页面 -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><?php echo $action == 'add' ? '新增交通预订' : '编辑交通预订'; ?></h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="personnel_id" class="form-label">选择人员 *</label>
                                    <select class="form-select" id="personnel_id" name="personnel_id" required>
                                        <option value="">请选择人员</option>
                                        <?php foreach ($personnel as $person): ?>
                                            <option value="<?php echo $person['id']; ?>" 
                                                <?php echo ($transport && $transport['personnel_id'] == $person['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($person['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="travel_type" class="form-label">交通类型 *</label>
                                    <select class="form-select" id="travel_type" name="travel_type" required>
                                        <option value="接站" <?php echo ($transport && $transport['travel_type'] == '接站') ? 'selected' : ''; ?>>接站</option>
                                        <option value="送站" <?php echo ($transport && $transport['travel_type'] == '送站') ? 'selected' : ''; ?>>送站</option>
                                        <option value="混合交通安排" <?php echo ($transport && $transport['travel_type'] == '混合交通安排') ? 'selected' : ''; ?>>混合交通安排</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="travel_date" class="form-label">出行日期 *</label>
                                    <input type="date" class="form-control" id="travel_date" name="travel_date" 
                                           value="<?php echo $transport ? $transport['travel_date'] : date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="departure_time" class="form-label">出发时间</label>
                                    <input type="time" class="form-control" id="departure_time" name="departure_time" 
                                           value="<?php echo $transport ? $transport['departure_time'] : ''; ?>">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="departure_location" class="form-label">出发地点 *</label>
                                    <input type="text" class="form-control" id="departure_location" name="departure_location" 
                                           value="<?php echo $transport ? htmlspecialchars($transport['departure_location']) : ''; ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="destination_location" class="form-label">目的地 *</label>
                                    <input type="text" class="form-control" id="destination_location" name="destination_location" 
                                           value="<?php echo $transport ? htmlspecialchars($transport['destination_location']) : ''; ?>" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="passenger_count" class="form-label">乘客数量 *</label>
                                    <input type="number" class="form-control" id="passenger_count" name="passenger_count" 
                                           min="1" value="<?php echo $transport ? $transport['passenger_count'] : '1'; ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="contact_phone" class="form-label">联系电话</label>
                                    <input type="tel" class="form-control" id="contact_phone" name="contact_phone" 
                                           value="<?php echo $transport ? htmlspecialchars($transport['contact_phone']) : ''; ?>">
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="special_requirements" class="form-label">特殊要求</label>
                            <textarea class="form-control" id="special_requirements" name="special_requirements" 
                                      rows="3" placeholder="如：需要儿童座椅、行李较多等"><?php echo $transport ? htmlspecialchars($transport['special_requirements']) : ''; ?></textarea>
                        </div>
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="transport.php" class="btn btn-secondary">取消</a>
                            <button type="submit" class="btn btn-primary">
                                <?php echo $action == 'add' ? '新增' : '更新'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <!-- 列表页面 -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">交通预订记录</h5>
                    <a href="transport.php?action=add" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus"></i> 新增预订
                    </a>
                </div>
                <div class="card-body">
                    <?php if (empty($transports)): ?>
                        <p class="text-muted">暂无交通预订记录</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>人员</th>
                                        <th>交通类型</th>
                                        <th>出行日期</th>
                                        <th>出发地点</th>
                                        <th>目的地</th>
                                        <th>出发时间</th>
                                        <th>乘客数</th>
                                        <th>联系电话</th>
                                        <th>状态</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transports as $transport): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($transport['personnel_name']); ?></td>
                                            <td>
                                                <span class="badge bg-primary"><?php echo $transport['travel_type']; ?></span>
                                            </td>
                                            <td><?php echo $transport['travel_date']; ?></td>
                                            <td><?php echo htmlspecialchars($transport['departure_location']); ?></td>
                                            <td><?php echo htmlspecialchars($transport['destination_location']); ?></td>
                                            <td><?php echo $transport['departure_time'] ? $transport['departure_time'] : '-'; ?></td>
                                            <td><?php echo $transport['passenger_count']; ?></td>
                                            <td><?php echo htmlspecialchars($transport['contact_phone']); ?></td>
                                            <td>
                                                <?php 
                                                $status_class = [
                                                    'pending' => 'warning',
                                                    'confirmed' => 'success',
                                                    'cancelled' => 'danger'
                                                ];
                                                $status_text = [
                                                    'pending' => '待确认',
                                                    'confirmed' => '已确认',
                                                    'cancelled' => '已取消'
                                                ];
                                                ?>
                                                <span class="badge bg-<?php echo $status_class[$transport['status']]; ?>">
                                                    <?php echo $status_text[$transport['status']]; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="transport.php?action=edit&id=<?php echo $transport['id']; ?>" 
                                                   class="btn btn-sm btn-warning">编辑</a>
                                                <a href="transport.php?action=delete&id=<?php echo $transport['id']; ?>" 
                                                   class="btn btn-sm btn-danger" 
                                                   onclick="return confirm('确定要删除这条记录吗？')">删除</a>
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

<?php include 'includes/footer.php'; ?>
</body>
</html>
