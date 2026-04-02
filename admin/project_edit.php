<?php
session_start();

// 引入基础控制器进行权限验证
require_once '../includes/BaseAdminController.php';

// 检查是否登录
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

// 修正包含路径 - 根据实际文件结构调整
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

// 检查是否有项目ID参数
if (!isset($_GET['id'])) {
    $_SESSION['error'] = '未指定项目ID';
    header('Location: projects.php');
    exit;
}

$project_id = intval($_GET['id']);

// 获取项目信息
$database = new Database();
$db = $database->getConnection();

$stmt = $db->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->execute([$project_id]);
$project = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$project) {
    $_SESSION['error'] = '项目不存在';
    header('Location: projects.php');
    exit;
}

// 解析工作证类型为数组
$badge_types = [];
if (!empty($project['badge_types'])) {
    $badge_types = explode(',', $project['badge_types']);
    $badge_types = array_map('trim', $badge_types);
}

// 获取所有公司
$companies = $db->query("SELECT id, name FROM companies ORDER BY name ASC");
$companies = $companies->fetchAll(PDO::FETCH_ASSOC);

// 获取所有酒店
$hotels = $db->query("SELECT id, hotel_name_cn, hotel_name_en, province, city FROM hotels ORDER BY hotel_name_cn ASC");
$hotels = $hotels->fetchAll(PDO::FETCH_ASSOC);

// 获取项目关联的酒店ID
$stmt = $db->prepare("SELECT hotel_id FROM project_hotels WHERE project_id = ?");
$stmt->execute([$project_id]);
$selected_hotels = $stmt->fetchAll(PDO::FETCH_COLUMN);

// 获取项目的交通地点信息
$transportation_locations = $db->prepare("SELECT id, name, type FROM transportation_locations WHERE project_id = ? ORDER BY type, name");
$transportation_locations->execute([$project_id]);
$project_transport_locations = $transportation_locations->fetchAll(PDO::FETCH_ASSOC);

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'edit') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $company_id = intval($_POST['company_id'] ?? 0);
        $location = trim($_POST['location'] ?? '');
        $start_date = $_POST['start_date'] ?? null;
        $end_date = $_POST['end_date'] ?? null;
        $status = $_POST['status'] ?? 'active';
        $hotel_ids = $_POST['hotel_ids'] ?? [];
        $default_meal_allowance = isset($_POST['default_meal_allowance']) ? floatval($_POST['default_meal_allowance']) : 100.00;
        
        // 交通地点信息
        $arrival_airport = trim($_POST['arrival_airport'] ?? '');
        $arrival_railway_station = trim($_POST['arrival_railway_station'] ?? '');
        $departure_airport = trim($_POST['departure_airport'] ?? '');
        $departure_railway_station = trim($_POST['departure_railway_station'] ?? '');
        
        // 新增交通地点信息
        $new_transport_locations = $_POST['new_transport_locations'] ?? [];
        $new_transport_types = $_POST['new_transport_types'] ?? [];
        
        // 工作证类型信息
        $badge_types_input = $_POST['badge_types'] ?? [];
        $badge_types_clean = array_filter(array_map('trim', $badge_types_input));
        $badge_types_str = !empty($badge_types_clean) ? implode(',', $badge_types_clean) : null;
        
        // 验证必填字段
        if (empty($name)) {
            $error = '项目名称不能为空';
        } elseif (empty($company_id)) {
            $error = '请选择所属公司';
        } elseif (empty($location)) {
            $error = '项目场地不能为空';
        } else {
            try {
                $db->beginTransaction();
                
                // 更新项目信息
                $stmt = $db->prepare("UPDATE projects SET 
                    name = ?, description = ?, company_id = ?, location = ?, 
                    start_date = ?, end_date = ?, status = ?, 
                    arrival_airport = ?, arrival_railway_station = ?, departure_airport = ?, departure_railway_station = ?,
                    badge_types = ?, default_meal_allowance = ?
                    WHERE id = ?");
                $stmt->execute([$name, $description, $company_id, $location, 
                    $start_date, $end_date, $status, $arrival_airport, $arrival_railway_station, $departure_airport, $departure_railway_station, 
                    $badge_types_str, $default_meal_allowance, $project_id]);
                
                // 删除现有的酒店关联
                $stmt = $db->prepare("DELETE FROM project_hotels WHERE project_id = ?");
                $stmt->execute([$project_id]);
                
                // 添加新的酒店关联
                if (!empty($hotel_ids)) {
                    $stmt = $db->prepare("INSERT INTO project_hotels (project_id, hotel_id) VALUES (?, ?)");
                    foreach ($hotel_ids as $hotel_id) {
                        $stmt->execute([$project_id, intval($hotel_id)]);
                    }
                }
                
                // 处理新增的交通地点
                if (!empty($new_transport_locations)) {
                    $stmt = $db->prepare("INSERT INTO transportation_locations (project_id, name, type) VALUES (?, ?, ?)");
                    for ($i = 0; $i < count($new_transport_locations); $i++) {
                        $location_name = trim($new_transport_locations[$i]);
                        $location_type = $new_transport_types[$i] ?? 'airport';
                        
                        // 只有当名称不为空时才插入
                        if (!empty($location_name)) {
                            $stmt->execute([$project_id, $location_name, $location_type]);
                        }
                    }
                }
                
                $db->commit();
                
                $_SESSION['success'] = '项目更新成功';
                header('Location: projects.php');
                exit;
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = '更新失败：' . $e->getMessage();
            }
        }
    }
}

// 状态映射
$status_map = [
    'active' => ['label' => '活跃', 'class' => 'success'],
    'inactive' => ['label' => '非活跃', 'class' => 'secondary'],
    'completed' => ['label' => '已完成', 'class' => 'info']
];

include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            
            <div class="card mt-4">

                <div class="card-body">

<!-- 编辑表单 -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">项目信息</h5>
    </div>
    <div class="card-body">
        <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form method="POST" id="editProjectForm">
            <input type="hidden" name="action" value="edit">
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="company_id" class="form-label">所属公司 <span class="text-danger">*</span></label>
                        <select class="form-select" id="company_id" name="company_id" required>
                            <option value="">请选择公司</option>
                            <?php foreach ($companies as $company): ?>
                                <option value="<?php echo $company['id']; ?>" 
                                        <?php echo $project['company_id'] == $company['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($company['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="name" class="form-label">项目名称 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" 
                               value="<?php echo htmlspecialchars($project['name']); ?>" required>
                    </div>
                </div>
            </div>

            <div class="mb-3">
                <label for="description" class="form-label">项目描述</label>
                <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($project['description']); ?></textarea>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="location" class="form-label">项目场地 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="location" name="location" 
                               value="<?php echo htmlspecialchars($project['location']); ?>" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="status" class="form-label">状态</label>
                        <select class="form-select" id="status" name="status">
                            <?php foreach ($status_map as $key => $value): ?>
                                <option value="<?php echo $key; ?>" 
                                        <?php echo $project['status'] == $key ? 'selected' : ''; ?>>
                                    <?php echo $value['label']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="start_date" class="form-label">开始日期</label>
                        <input type="date" class="form-control" id="start_date" name="start_date" 
                               value="<?php echo $project['start_date']; ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="end_date" class="form-label">结束日期</label>
                        <input type="date" class="form-control" id="end_date" name="end_date" 
                               value="<?php echo $project['end_date']; ?>">
                    </div>
                </div>
            </div>

            <!-- 餐费补助设置 -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-cash"></i> 餐费补助设置</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="default_meal_allowance" class="form-label">默认每日餐费补助金额（元）</label>
                                <div class="input-group">
                                    <span class="input-group-text">¥</span>
                                    <input type="number" class="form-control" id="default_meal_allowance" name="default_meal_allowance" 
                                           value="<?php echo isset($project['default_meal_allowance']) ? htmlspecialchars($project['default_meal_allowance']) : '100.00'; ?>" 
                                           min="0" step="0.01" placeholder="请输入默认每日餐费补助金额">
                                </div>
                                <div class="form-text">此金额将作为该项目下所有人员餐费补助计算的基准值，除非个别人员设置了个人特定的补助金额。</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 交通地点信息 -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-geo-alt-fill"></i> 接送机/站交通地点</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="arrival_airport" class="form-label">机场1</label>
                                <input type="text" class="form-control" id="arrival_airport" name="arrival_airport" 
                                       value="<?php echo htmlspecialchars($project['arrival_airport'] ?? ''); ?>" 
                                       placeholder="请输入到达机场名称">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="arrival_railway_station" class="form-label">高铁站1</label>
                                <input type="text" class="form-control" id="arrival_railway_station" name="arrival_railway_station" 
                                       value="<?php echo htmlspecialchars($project['arrival_railway_station'] ?? ''); ?>" 
                                       placeholder="请输入到达高铁站名称">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="departure_airport" class="form-label">机场2</label>
                                <input type="text" class="form-control" id="departure_airport" name="departure_airport" 
                                       value="<?php echo htmlspecialchars($project['departure_airport'] ?? ''); ?>" 
                                       placeholder="请输入出发机场名称">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="departure_railway_station" class="form-label">高铁站2</label>
                                <input type="text" class="form-control" id="departure_railway_station" name="departure_railway_station" 
                                       value="<?php echo htmlspecialchars($project['departure_railway_station'] ?? ''); ?>" 
                                       placeholder="请输入出发高铁站名称">
                            </div>
                        </div>
                    </div>
                    
                    <!-- 现有交通地点列表 -->
                    <?php if (!empty($project_transport_locations)): ?>
                    <div class="mb-3">
                        <label class="form-label">现有交通地点</label>
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th>地点名称</th>
                                        <th>类型</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($project_transport_locations as $location): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($location['name']); ?></td>
                                        <td>
                                            <select class="form-select transport-type-select" data-location-id="<?php echo $location['id']; ?>">
                                                <option value="airport" <?php echo $location['type'] === 'airport' ? 'selected' : ''; ?>>机场</option>
                                                <option value="railway_station" <?php echo $location['type'] === 'railway_station' ? 'selected' : ''; ?>>高铁站</option>
                                            </select>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-outline-danger btn-sm delete-transport-location" data-location-id="<?php echo $location['id']; ?>">
                                                <i class="bi bi-trash"></i> 删除
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- 新增交通地点 -->
                    <div class="mb-3">
                        <label class="form-label">新增交通地点</label>
                        <div id="newTransportLocationsContainer">
                            <div class="row mb-2 new-transport-item">
                                <div class="col-md-6">
                                    <input type="text" class="form-control" name="new_transport_locations[]" placeholder="请输入交通地点名称">
                                </div>
                                <div class="col-md-4">
                                    <select class="form-select" name="new_transport_types[]">
                                        <option value="airport">机场</option>
                                        <option value="railway_station">高铁站</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <button class="btn btn-outline-danger remove-transport-location" type="button">
                                        <i class="bi bi-dash-circle"></i> 删除
                                    </button>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-outline-primary" id="addTransportLocation">
                            <i class="bi bi-plus-circle"></i> 添加交通地点
                        </button>
                    </div>
                </div>
            </div>

            <!-- 工作证类型管理 -->
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0"><i class="bi bi-card-checklist"></i> 可用工作证类型</h6>
                </div>
                <div class="card-body">
                    <div id="badgeTypesContainer">
                        <?php if (!empty($badge_types)): ?>
                            <?php foreach ($badge_types as $index => $badge_type): ?>
                                <div class="input-group mb-2 badge-type-item">
                                    <input type="text" class="form-control" name="badge_types[]" value="<?php echo htmlspecialchars($badge_type); ?>" placeholder="请输入工作证类型">
                                    <button class="btn btn-outline-danger remove-badge-type" type="button">
                                        <i class="bi bi-dash-circle"></i> 删除
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="input-group mb-2 badge-type-item">
                                <input type="text" class="form-control" name="badge_types[]" placeholder="请输入工作证类型">
                                <button class="btn btn-outline-danger remove-badge-type" type="button">
                                    <i class="bi bi-dash-circle"></i> 删除
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn btn-outline-primary" id="addBadgeType">
                        <i class="bi bi-plus-circle"></i> 添加工作证类型
                    </button>
                    <div class="form-text mt-2">可添加多个工作证类型，用于项目人员管理</div>
                </div>
            </div>

            <div class="mb-3">
                <label for="hotel_ids" class="form-label">指定酒店（可多选）</label>
                <select class="form-select" id="hotel_ids" name="hotel_ids[]" multiple size="5">
                    <?php foreach ($hotels as $hotel): ?>
                        <option value="<?php echo $hotel['id']; ?>" 
                                <?php echo in_array($hotel['id'], $selected_hotels) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($hotel['hotel_name_cn']); ?>
                            <?php if ($hotel['hotel_name_en']): ?>
                                (<?php echo htmlspecialchars($hotel['hotel_name_en']); ?>)
                            <?php endif; ?>
                            - <?php echo htmlspecialchars($hotel['province'] . ' ' . $hotel['city']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">按住Ctrl键（Windows）或Command键（Mac）可多选</div>
            </div>

            <div class="d-flex justify-content-end gap-2">
                <a href="projects.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> 返回列表
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> 保存修改
                </button>
            </div>
        </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// 工作证类型管理功能
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('badgeTypesContainer');
    const addButton = document.getElementById('addBadgeType');
    
    // 添加工作证类型
    addButton.addEventListener('click', function() {
        const newItem = document.createElement('div');
        newItem.className = 'input-group mb-2 badge-type-item';
        newItem.innerHTML = `
            <input type="text" class="form-control" name="badge_types[]" placeholder="请输入工作证类型">
            <button class="btn btn-outline-danger remove-badge-type" type="button">
                <i class="bi bi-dash-circle"></i> 删除
            </button>
        `;
        container.appendChild(newItem);
        
        // 为新添加的删除按钮绑定事件
        newItem.querySelector('.remove-badge-type').addEventListener('click', function() {
            // 确保至少保留一个输入框
            if (container.querySelectorAll('.badge-type-item').length > 1) {
                newItem.remove();
            } else {
                // 如果只剩一个输入框，则清空内容
                newItem.querySelector('input').value = '';
            }
        });
    });
    
    // 为现有的删除按钮绑定事件
    document.querySelectorAll('.remove-badge-type').forEach(button => {
        button.addEventListener('click', function() {
            const item = this.closest('.badge-type-item');
            // 确保至少保留一个输入框
            if (container.querySelectorAll('.badge-type-item').length > 1) {
                item.remove();
            } else {
                // 如果只剩一个输入框，则清空内容
                item.querySelector('input').value = '';
            }
        });
    });
    
    // 交通地点管理功能
    const transportContainer = document.getElementById('newTransportLocationsContainer');
    const addTransportButton = document.getElementById('addTransportLocation');
    
    // 添加交通地点
    addTransportButton.addEventListener('click', function() {
        const newItem = document.createElement('div');
        newItem.className = 'row mb-2 new-transport-item';
        newItem.innerHTML = `
            <div class="col-md-6">
                <input type="text" class="form-control" name="new_transport_locations[]" placeholder="请输入交通地点名称">
            </div>
            <div class="col-md-4">
                <select class="form-select" name="new_transport_types[]">
                    <option value="airport">机场</option>
                    <option value="railway_station">高铁站</option>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-danger remove-transport-location" type="button">
                    <i class="bi bi-dash-circle"></i> 删除
                </button>
            </div>
        `;
        transportContainer.appendChild(newItem);
        
        // 为新添加的删除按钮绑定事件
        newItem.querySelector('.remove-transport-location').addEventListener('click', function() {
            newItem.remove();
        });
    });
    
    // 为现有的删除按钮绑定事件
    document.querySelectorAll('.remove-transport-location').forEach(button => {
        button.addEventListener('click', function() {
            const item = this.closest('.new-transport-item');
            item.remove();
        });
    });
    
    // 编辑交通地点类型
    document.querySelectorAll('.transport-type-select').forEach(select => {
        select.addEventListener('change', function() {
            const locationId = this.getAttribute('data-location-id');
            const newType = this.value;
            
            // 发送AJAX请求更新交通地点类型
            fetch('ajax_update_transport_location.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'update_type',
                    location_id: locationId,
                    type: newType
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 显示成功消息
                    const alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-success alert-dismissible fade show position-fixed';
                    alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999;';
                    alertDiv.innerHTML = `
                        类型更新成功
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;
                    document.body.appendChild(alertDiv);
                    
                    // 3秒后自动关闭
                    setTimeout(() => {
                        alertDiv.remove();
                    }, 3000);
                } else {
                    alert('更新失败: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('更新失败，请重试');
            });
        });
    });
    
    // 删除交通地点
    document.querySelectorAll('.delete-transport-location').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const locationId = this.getAttribute('data-location-id');
            const row = this.closest('tr');
            
            if (confirm('确定要删除这个交通地点吗？')) {
                // 发送AJAX请求删除交通地点
                fetch('ajax_update_transport_location.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'delete',
                        location_id: locationId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // 从表格中移除行
                        row.remove();
                        
                        // 显示成功消息
                        const alertDiv = document.createElement('div');
                        alertDiv.className = 'alert alert-success alert-dismissible fade show position-fixed';
                        alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999;';
                        alertDiv.innerHTML = `
                            删除成功
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        `;
                        document.body.appendChild(alertDiv);
                        
                        // 3秒后自动关闭
                        setTimeout(() => {
                            alertDiv.remove();
                        }, 3000);
                    } else {
                        alert('删除失败: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('删除失败，请重试');
                });
            }
        });
    });
});
</script>

<?php include 'includes/footer.php'; ?>
</body>
</html>