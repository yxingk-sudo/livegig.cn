<?php
session_start();
require_once '../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
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

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        // 添加项目
        $company_id = intval($_POST['company_id']);
        $name = trim($_POST['name']);
        
        // 获取公司名称用于生成项目代码
        $company_query = "SELECT name FROM companies WHERE id = :id";
        $company_stmt = $db->prepare($company_query);
        $company_stmt->bindParam(':id', $company_id);
        $company_stmt->execute();
        $company = $company_stmt->fetch(PDO::FETCH_ASSOC);
        $company_name = $company['name'] ?? 'UNKNOWN';
        
        $code = generateProjectCode($company_name, $name);
        $description = trim($_POST['description']);
        $location = trim($_POST['location']);
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $status = $_POST['status'];
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
        
        // 处理多选酒店
        $hotel_ids = isset($_POST['hotel_ids']) ? $_POST['hotel_ids'] : [];
        
        if (empty($name) || empty($company_id)) {
            $error = '项目名称和公司不能为空！';
        } else {
            // 检查项目名称是否已存在
            $check_name = "SELECT COUNT(*) FROM projects WHERE name = :name AND company_id = :company_id";
            $stmt_name = $db->prepare($check_name);
            $stmt_name->bindParam(':name', $name);
            $stmt_name->bindParam(':company_id', $company_id);
            $stmt_name->execute();
            $name_count = $stmt_name->fetchColumn();
            
            if ($name_count > 0) {
                $error = '该公司下已存在同名项目！';
            } else {
                // 检查项目代码是否已存在
                $check_code = "SELECT COUNT(*) FROM projects WHERE code = :code";
                $stmt_code = $db->prepare($check_code);
                $stmt_code->bindParam(':code', $code);
                $stmt_code->execute();
                $code_count = $stmt_code->fetchColumn();
                
                if ($code_count > 0) {
                    $error = '项目代码已存在！请使用其他代码。';
                } else {
                    // 添加项目（包含交通地点字段、工作证类型字段和默认餐费补助字段）
                    $query = "INSERT INTO projects (company_id, name, code, description, location, start_date, end_date, status, 
                             arrival_airport, arrival_railway_station, departure_airport, departure_railway_station, badge_types, default_meal_allowance) 
                             VALUES (:company_id, :name, :code, :description, :location, :start_date, :end_date, :status, 
                             :arrival_airport, :arrival_railway_station, :departure_airport, :departure_railway_station, :badge_types, :default_meal_allowance)";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':company_id', $company_id);
                    $stmt->bindParam(':name', $name);
                    $stmt->bindParam(':code', $code);
                    $stmt->bindParam(':description', $description);
                    $stmt->bindParam(':location', $location);
                    $stmt->bindParam(':start_date', $start_date);
                    $stmt->bindParam(':end_date', $end_date);
                    $stmt->bindParam(':status', $status);
                    $stmt->bindParam(':arrival_airport', $arrival_airport);
                    $stmt->bindParam(':arrival_railway_station', $arrival_railway_station);
                    $stmt->bindParam(':departure_airport', $departure_airport);
                    $stmt->bindParam(':departure_railway_station', $departure_railway_station);
                    $stmt->bindParam(':badge_types', $badge_types_str);
                    $stmt->bindParam(':default_meal_allowance', $default_meal_allowance);
                    
                    if ($stmt->execute()) {
                        $project_id = $db->lastInsertId();
                        
                        // 添加项目酒店关联
                        try {
                            if (!empty($hotel_ids)) {
                                $hotel_query = "INSERT INTO project_hotels (project_id, hotel_id) VALUES (:project_id, :hotel_id)";
                                $hotel_stmt = $db->prepare($hotel_query);
                                foreach ($hotel_ids as $hotel_id) {
                                    $hotel_id = intval($hotel_id);
                                    $hotel_stmt->bindParam(':project_id', $project_id);
                                    $hotel_stmt->bindParam(':hotel_id', $hotel_id);
                                    $hotel_stmt->execute();
                                }
                            }
                        } catch (PDOException $e) {
                            // 如果project_hotels表不存在，忽略酒店关联
                            error_log("project_hotels表不存在，跳过酒店关联: " . $e->getMessage());
                        }
                        
                        // 处理新增的交通地点
                        if (!empty($new_transport_locations)) {
                            try {
                                $transport_stmt = $db->prepare("INSERT INTO transportation_locations (project_id, name, type) VALUES (:project_id, :name, :type)");
                                for ($i = 0; $i < count($new_transport_locations); $i++) {
                                    $location_name = trim($new_transport_locations[$i]);
                                    $location_type = $new_transport_types[$i] ?? 'airport';
                                    
                                    // 只有当名称不为空时才插入
                                    if (!empty($location_name)) {
                                        $transport_stmt->bindParam(':project_id', $project_id);
                                        $transport_stmt->bindParam(':name', $location_name);
                                        $transport_stmt->bindParam(':type', $location_type);
                                        $transport_stmt->execute();
                                    }
                                }
                            } catch (Exception $e) {
                                error_log("添加交通地点失败: " . $e->getMessage());
                            }
                        }
                        
                        // 自动添加标准部门
                        $standard_departments = [
                            '乐队' => '负责音乐演奏和现场伴奏',
                            '导演组' => '负责整体节目策划和导演工作',
                            '管理组' => '负责项目管理和协调工作',
                            '行动组' => '负责现场执行和后勤保障',
                            '和音' => '负责和声演唱和音乐配合',
                            '舞蹈' => '负责舞蹈编排和表演',
                            '化妆组' => '负责演员化妆和造型',
                            '服装组' => '负责服装管理和造型搭配'
                        ];
                        
                        try {
                            foreach ($standard_departments as $dept_name => $description) {
                                $dept_query = "INSERT INTO departments (name, project_id, description) VALUES (:name, :project_id, :description)";
                                $dept_stmt = $db->prepare($dept_query);
                                $dept_stmt->bindParam(':name', $dept_name);
                                $dept_stmt->bindParam(':project_id', $project_id);
                                $dept_stmt->bindParam(':description', $description);
                                $dept_stmt->execute();
                            }
                        } catch (Exception $e) {
                            // 忽略部门创建错误
                        }
                        
                        $_SESSION['success_message'] = '项目添加成功！项目代码：' . $code . ' 已自动添加8个标准部门！';
                        header("Location: projects.php");
                        exit;
                    } else {
                        $error = '添加项目失败，请重试！';
                    }
                }
            }
        }
    }
}

// 获取公司列表用于下拉选择
$companies_query = "SELECT id, name FROM companies ORDER BY name";
$companies_stmt = $db->prepare($companies_query);
$companies_stmt->execute();
$companies = $companies_stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取酒店列表用于下拉选择
$hotels_query = "SELECT id, hotel_name_cn, hotel_name_en, address, province, city FROM hotels ORDER BY hotel_name_cn";
$hotels_stmt = $db->prepare($hotels_query);
$hotels_stmt->execute();
$hotels = $hotels_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = '新建项目';
include 'includes/header.php';
?>

<!-- 引入项目添加页面优化样式 -->
<link href="assets/css/project-add-optimized.css" rel="stylesheet">

<div class="container-fluid">
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i> <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <!-- 成功消息 -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill"></i> <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-folder-plus"></i> 新建项目</h5>
            <a href="projects.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> 返回项目列表
            </a>
        </div>
        <div class="card-body">
            <form method="POST" id="addProjectForm">
                <input type="hidden" name="action" value="add">
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="company_id" class="form-label">所属公司 <span class="text-danger">*</span></label>
                            <select class="form-select" id="company_id" name="company_id" required>
                                <option value="">请选择公司</option>
                                <?php foreach ($companies as $company): ?>
                                    <option value="<?php echo $company['id']; ?>" 
                                        <?php echo (isset($_POST['company_id']) && $_POST['company_id'] == $company['id']) ? 'selected' : ''; ?>>
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
                                   value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="start_date" class="form-label">开始日期</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" 
                                   value="<?php echo htmlspecialchars($_POST['start_date'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="end_date" class="form-label">结束日期</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" 
                                   value="<?php echo htmlspecialchars($_POST['end_date'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="location" class="form-label">项目场地 <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="location" name="location" 
                           value="<?php echo htmlspecialchars($_POST['location'] ?? ''); ?>" required>
                </div>

                <div class="mb-3">
                    <label for="description" class="form-label">项目描述</label>
                    <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                </div>

                <div class="mb-3">
                    <label for="status" class="form-label">状态</label>
                    <select class="form-select" id="status" name="status">
                        <option value="active" <?php echo (!isset($_POST['status']) || $_POST['status'] == 'active') ? 'selected' : ''; ?>>活跃</option>
                        <option value="inactive" <?php echo (isset($_POST['status']) && $_POST['status'] == 'inactive') ? 'selected' : ''; ?>>非活跃</option>
                        <option value="completed" <?php echo (isset($_POST['status']) && $_POST['status'] == 'completed') ? 'selected' : ''; ?>>已完成</option>
                    </select>
                </div>
                
                <!-- 餐费补助设置 -->
                <div class="card border-0 bg-light mb-3">
                    <div class="card-header bg-light">
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
                                               value="<?php echo isset($_POST['default_meal_allowance']) ? htmlspecialchars($_POST['default_meal_allowance']) : '100.00'; ?>" 
                                               min="0" step="0.01" placeholder="请输入默认每日餐费补助金额">
                                    </div>
                                    <div class="form-text">此金额将作为该项目下所有人员餐费补助计算的基准值，除非个别人员设置了个人特定的补助金额。</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 交通地点信息 -->
                <div class="card border-0 bg-light">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-geo-alt-fill"></i> 接送机/站交通地点</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="arrival_airport" class="form-label">机场1（到达机场）</label>
                                    <input type="text" class="form-control" id="arrival_airport" name="arrival_airport" 
                                           placeholder="请输入到达机场名称" 
                                           value="<?php echo htmlspecialchars($_POST['arrival_airport'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="arrival_railway_station" class="form-label">高铁站1（到达高铁站）</label>
                                    <input type="text" class="form-control" id="arrival_railway_station" name="arrival_railway_station" 
                                           placeholder="请输入到达高铁站名称" 
                                           value="<?php echo htmlspecialchars($_POST['arrival_railway_station'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="departure_airport" class="form-label">机场2（出发机场）</label>
                                    <input type="text" class="form-control" id="departure_airport" name="departure_airport" 
                                           placeholder="请输入出发机场名称" 
                                           value="<?php echo htmlspecialchars($_POST['departure_airport'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="departure_railway_station" class="form-label">高铁站2（出发高铁站）</label>
                                    <input type="text" class="form-control" id="departure_railway_station" name="departure_railway_station" 
                                           placeholder="请输入出发高铁站名称" 
                                           value="<?php echo htmlspecialchars($_POST['departure_railway_station'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        
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
                <div class="card border-0 bg-light mt-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-card-checklist"></i> 可用工作证类型</h6>
                    </div>
                    <div class="card-body">
                        <div id="badgeTypesContainer">
                            <div class="input-group mb-2 badge-type-item">
                                <input type="text" class="form-control" name="badge_types[]" placeholder="请输入工作证类型">
                                <button class="btn btn-outline-danger remove-badge-type" type="button">
                                    <i class="bi bi-dash-circle"></i> 删除
                                </button>
                            </div>
                        </div>
                        <button type="button" class="btn btn-outline-primary" id="addBadgeType">
                            <i class="bi bi-plus-circle"></i> 添加工作证类型
                        </button>
                        <div class="form-text mt-2">可添加多个工作证类型，用于项目人员管理</div>
                    </div>
                </div>
                
                <!-- 指定酒店 -->
                <div class="card border-0 bg-light mt-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-building"></i> 指定酒店</h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="hotel_ids" class="form-label">指定酒店（可多选）</label>
                            <select class="form-select" id="hotel_ids" name="hotel_ids[]" multiple size="6">
                                <?php foreach ($hotels as $hotel): ?>
                                    <option value="<?php echo $hotel['id']; ?>"
                                        <?php echo (isset($_POST['hotel_ids']) && in_array($hotel['id'], $_POST['hotel_ids'])) ? 'selected' : ''; ?>>
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
                    </div>
                </div>

                <div class="text-end mt-4">
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="bi bi-plus-circle"></i> 新建项目
                    </button>
                    <a href="projects.php" class="btn btn-secondary">取消</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// 表单提交处理
document.getElementById('addProjectForm').addEventListener('submit', function(e) {
    const submitBtn = document.getElementById('submitBtn');
    
    // 禁用提交按钮防止重复提交
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> 提交中...';
    
    // 表单验证
    const companyId = document.getElementById('company_id').value;
    const projectName = document.getElementById('name').value;
    const location = document.getElementById('location').value;
    
    if (!companyId || !projectName || !location) {
        e.preventDefault();
        showToast('请填写所有必填字段', 'danger');
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="bi bi-plus-circle"></i> 新建项目';
        return false;
    }
});

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
    
    // 为删除按钮绑定事件
    container.addEventListener('click', function(e) {
        if (e.target.closest('.remove-badge-type')) {
            const button = e.target.closest('.remove-badge-type');
            const item = button.closest('.badge-type-item');
            
            // 确保至少保留一个输入框
            if (container.querySelectorAll('.badge-type-item').length > 1) {
                item.remove();
            } else {
                // 如果只剩一个输入框，则清空内容
                item.querySelector('input').value = '';
            }
        }
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
});
</script>

<?php include 'includes/footer.php'; ?>