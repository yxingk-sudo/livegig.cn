<?php
// 批量报餐独立页面
// 功能：批量为项目人员报餐
require_once '../config/database.php';
require_once '../includes/functions.php';

session_start();
requireLogin();
checkProjectPermission($_SESSION['project_id']);

// 获取数据库连接
$database = new Database();
$db = $database->getConnection();

$projectId = $_SESSION['project_id'];

// 获取项目详情
$project = getProjectDetails($projectId, $db);
$projectStartDate = $project['start_date'];
$projectEndDate = $project['end_date'];

// 获取项目人员
$personnel = getProjectPersonnel($projectId, $db);

// 获取项目部门及人数统计
$dept_query = "SELECT DISTINCT d.id, d.name, COUNT(pdp.personnel_id) as person_count
               FROM departments d 
               JOIN project_department_personnel pdp ON d.id = pdp.department_id 
               WHERE pdp.project_id = :project_id 
               AND pdp.status = 'active'
               GROUP BY d.id, d.name
               ORDER BY d.name";
$dept_stmt = $db->prepare($dept_query);
$dept_stmt->bindParam(':project_id', $projectId);
$dept_stmt->execute();
$departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);

// 处理批量报餐提交
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $db->beginTransaction();
        
        $meal_date = $_POST['meal_date'];
        $meal_type = $_POST['meal_type'];
        $meal_time = $_POST['meal_time'] ?? null;
        $delivery_time = $_POST['delivery_time'] ?? null;
        $package_id = (isset($_POST['package_id']) && $_POST['package_id'] !== '') ? $_POST['package_id'] : null;
        $selection_type = $_POST['selection_type'];
        $selected_personnel = $_POST['selected_personnel'] ?? [];
        $special_requirements = $_POST['special_requirements'] ?? '';
        
        // 验证必填字段
        if (empty($meal_date) || empty($meal_type) || empty($selection_type)) {
            throw new Exception('请填写所有必填字段');
        }
        
        // 验证用餐日期是否在项目日期范围内
        if ($meal_date < $projectStartDate || $meal_date > $projectEndDate) {
            throw new Exception('用餐日期必须在项目日期范围内 (' . $projectStartDate . ' 至 ' . $projectEndDate . ')');
        }
        
        // 验证时间格式
        if (!empty($meal_time) && !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $meal_time)) {
            throw new Exception('用餐时间格式不正确');
        }
        if (!empty($delivery_time) && !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $delivery_time)) {
            throw new Exception('送餐时间格式不正确');
        }
        
        // 根据选择类型获取人员ID列表
        $selected_personnel = [];
        
        if ($selection_type === 'department' && !empty($_POST['selected_departments'])) {
            // 按部门多选
            $department_ids = array_map('intval', $_POST['selected_departments']);
            $placeholders = str_repeat('?,', count($department_ids) - 1) . '?';
            
            $personnel_query = "SELECT DISTINCT p.id 
                              FROM personnel p 
                              JOIN project_department_personnel pdp ON p.id = pdp.personnel_id 
                              WHERE pdp.project_id = ? 
                              AND pdp.department_id IN ($placeholders) 
                              AND pdp.status = 'active'";
            $personnel_stmt = $db->prepare($personnel_query);
            
            // 绑定参数 - 项目ID + 部门IDs
            $params = array_merge([$projectId], $department_ids);
            $personnel_stmt->execute($params);
            $selected_personnel = $personnel_stmt->fetchAll(PDO::FETCH_COLUMN);
        } elseif ($selection_type === 'individual' && !empty($_POST['selected_personnel'])) {
            // 按个人选择
            $selected_personnel = array_map('intval', $_POST['selected_personnel']);
        }

        if (empty($selected_personnel)) {
            throw new Exception('请选择至少一名人员');
        }
        
        // 插入报餐记录
        $insert_query = "INSERT INTO meal_reports 
                        (project_id, personnel_id, meal_date, meal_type, meal_time, delivery_time, 
                         package_id, meal_count, special_requirements, reported_by, created_at) 
                        VALUES 
                        (:project_id, :personnel_id, :meal_date, :meal_type, :meal_time, :delivery_time, 
                         :package_id, :meal_count, :special_requirements, :reported_by, NOW())";
        
        $insert_stmt = $db->prepare($insert_query);
        $success_count = 0;
        
        foreach ($selected_personnel as $personnel_id) {
            // 检查是否已存在同日期同餐类型的记录
            $check_query = "SELECT COUNT(*) FROM meal_reports 
                           WHERE project_id = :project_id 
                           AND personnel_id = :personnel_id 
                           AND meal_date = :meal_date 
                           AND meal_type = :meal_type";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':project_id', $projectId);
            $check_stmt->bindParam(':personnel_id', $personnel_id);
            $check_stmt->bindParam(':meal_date', $meal_date);
            $check_stmt->bindParam(':meal_type', $meal_type);
            $check_stmt->execute();
            
            if ($check_stmt->fetchColumn() > 0) {
                continue; // 跳过已存在的记录
            }
            
            // 插入记录
            $insert_stmt->bindParam(':project_id', $projectId);
            $insert_stmt->bindParam(':personnel_id', $personnel_id);
            $insert_stmt->bindParam(':meal_date', $meal_date);
            $insert_stmt->bindParam(':meal_type', $meal_type);
            $insert_stmt->bindParam(':meal_time', $meal_time);
            $insert_stmt->bindParam(':delivery_time', $delivery_time);
            $insert_stmt->bindParam(':package_id', $package_id);
            $insert_stmt->bindValue(':meal_count', 1);
            $insert_stmt->bindParam(':special_requirements', $special_requirements);
            $insert_stmt->bindParam(':reported_by', $_SESSION['user_id']);
            
            if ($insert_stmt->execute()) {
                $success_count++;
            }
        }
        
        $db->commit();
        $_SESSION['message'] = "批量报餐成功！共为 {$success_count} 人报餐";
        header("Location: meals_new.php");
        exit;
        
    } catch (Exception $e) {
        $db->rollback();
        $_SESSION['error'] = "报餐失败：" . $e->getMessage();
    }
}

// 获取每种餐类型的可用套餐数量
function getMealTypePackageCounts($projectId, $db) {
    $query = "SELECT meal_type, COUNT(*) as package_count
              FROM meal_packages 
              WHERE project_id = :project_id 
              AND is_active = 1
              GROUP BY meal_type";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':project_id', $projectId);
    $stmt->execute();
    
    $counts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $counts[$row['meal_type']] = (int)$row['package_count'];
    }
    
    return $counts;
}

// 获取餐类型套餐统计
$mealTypePackageCounts = getMealTypePackageCounts($projectId, $db);

// 显示消息
$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message'], $_SESSION['error']);

// 设置页面特定变量
$page_title = '批量报餐 - ' . ($_SESSION['project_name'] ?? '项目');
$active_page = 'batch_meal_order';
$show_page_title = '批量报餐';
$page_icon = 'calendar-plus';

// 包含统一头部文件
include 'includes/header.php';
?>

<style>
/* 批量报餐页面样式 */
.meal-card {
    border: 2px solid #e9ecef;
    border-radius: 10px;
    transition: all 0.3s ease;
    cursor: pointer;
    background-color: #f8f9ff;
}
.meal-card:hover {
    border-color: #007bff;
    background-color: #e3f2fd;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,123,255,0.15);
}
.meal-card.selected {
    border-color: #007bff;
    background-color: #e3f2fd;
    box-shadow: 0 4px 8px rgba(0,123,255,0.15);
}
.meal-card.disabled {
    opacity: 0.6;
    cursor: not-allowed;
    pointer-events: none;
}
.package-option {
    transition: all 0.3s ease;
    cursor: pointer;
}
.package-option:hover {
    background-color: #f8f9fa !important;
    border-color: #007bff !important;
}
.form-check-input:checked + .form-check-label .package-option {
    background-color: #e3f2fd !important;
    border-color: #007bff !important;
}
.selected-personnel-badge {
    background-color: #0d6efd;
    color: white;
    margin: 2px;
    font-size: 0.8rem;
}
.selected-personnel-container {
    max-height: 200px;
    overflow-y: auto;
}

/* 人员选择方式按钮样式优化 */
#selectDepartment:checked + .btn-outline-success,
.btn-outline-success:hover {
    background-color: #198754;
    border-color: #198754;
    color: white;
}

#selectIndividual:checked + .btn-outline-primary,
.btn-outline-primary:hover {
    background-color: #0d6efd;
    border-color: #0d6efd;
    color: white;
}

/* 提示信息样式 */
.personnel-required-alert {
    display: none;
    background-color: #fff3cd;
    border-color: #ffeaa7;
    color: #856404;
    padding: 12px;
    border-radius: 6px;
    margin-bottom: 15px;
    border: 1px solid #ffeeba;
}
</style>

<div class="container-fluid mt-4">
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle-fill me-2"></i><?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- 人员选择提示信息 -->
    <div id="personnelRequiredAlert" class="personnel-required-alert">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <strong>提示：</strong>请先选择人员，然后才能选择餐类型。
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-calendar-plus me-2"></i>批量报餐
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="batchOrderForm">
                        <!-- 人员选择方式 -->
                        <div class="mb-4">
                            <label class="form-label required">人员选择方式</label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="selection_type" value="department" 
                                       id="selectDepartment" required>
                                <label class="btn btn-outline-success" for="selectDepartment">
                                    <i class="bi bi-people-fill me-2"></i>按部门选择
                                </label>
                                
                                <input type="radio" class="btn-check" name="selection_type" value="individual" 
                                       id="selectIndividual" required>
                                <label class="btn btn-outline-primary" for="selectIndividual">
                                    <i class="bi bi-person-check-fill me-2"></i>按个人选择
                                </label>
                            </div>
                        </div>
                        
                        <!-- 部门选择 - 多选形式 -->
                        <div class="mb-4" id="departmentSelection" style="display: none;">
                            <label class="form-label">选择部门</label>
                            <div class="row">
                                <?php foreach ($departments as $dept): ?>
                                    <div class="col-md-4 mb-2">
                                        <div class="form-check">
                                            <input class="form-check-input department-checkbox" 
                                                   type="checkbox" 
                                                   name="selected_departments[]" 
                                                   value="<?php echo $dept['id']; ?>" 
                                                   id="dept_<?php echo $dept['id']; ?>">
                                            <label class="form-check-label" for="dept_<?php echo $dept['id']; ?>">
                                                <?php echo htmlspecialchars($dept['name']); ?> 
                                                <span class="text-muted">(<?php echo $dept['person_count']; ?>人)</span>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- 个人选择 -->
                        <div class="mb-4" id="individualSelection" style="display: none;">
                            <label class="form-label">选择人员</label>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-header d-flex justify-content-between">
                                            <span>项目人员</span>
                                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                                    onclick="selectAll()">全选</button>
                                        </div>
                                        <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                                            <?php foreach ($personnel as $person): ?>
                                                <div class="form-check">
                                                    <input class="form-check-input personnel-checkbox" 
                                                           type="checkbox" name="selected_personnel[]" 
                                                           value="<?php echo $person['id']; ?>" 
                                                           id="person_<?php echo $person['id']; ?>">
                                                    <label class="form-check-label" for="person_<?php echo $person['id']; ?>">
                                                        <?php echo htmlspecialchars($person['name']); ?>
                                                        <small class="text-muted">
                                                            (<?php echo htmlspecialchars($person['departments'] ?? '未分配部门'); ?>)
                                                        </small>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-header">已选人员</div>
                                        <div class="card-body selected-personnel-container" id="selectedPersonnel">
                                            <p class="no-selection-text">暂无选择</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- 餐类型选择 -->
                        <div class="mb-4">
                            <label class="form-label required">餐类型</label>
                            <div class="row" id="mealTypeCards">
                                <?php 
                                $mealTypes = ['早餐', '午餐', '晚餐', '宵夜'];
                                foreach($mealTypes as $type): 
                                    // 检查该餐类型是否有可用套餐
                                    $packageCount = $mealTypePackageCounts[$type] ?? 0;
                                    if ($packageCount > 0):
                                ?>
                                    <div class="col-md-3 mb-3">
                                        <div class="meal-card p-3 text-center disabled" data-meal-type="<?php echo $type; ?>">
                                            <i class="bi bi-<?php 
                                            $icons = ['早餐'=>'sunrise', '午餐'=>'sun', '晚餐'=>'sunset', '宵夜'=>'moon-stars'];
                                            echo $icons[$type]; 
                                            ?> fs-1 text-primary mb-2"></i>
                                            <h6><?php echo $type; ?></h6>
                                            <input type="radio" name="meal_type" value="<?php echo $type; ?>" 
                                                   class="form-check-input d-none" required>
                                        </div>
                                    </div>
                                <?php 
                                    endif;
                                endforeach; 
                                ?>
                            </div>
                        </div>
                        
                        <!-- 套餐选择 -->
                        <div class="mb-4" id="packageSelection" style="display: none;">
                            <label class="form-label">选择套餐</label>
                            <div id="packageCards" class="row">
                                <!-- 动态加载套餐 -->
                            </div>
                        </div>
                        
                        <!-- 基本信息 -->
                        <div class="row mb-4">
                            <div class="col-md-3">
                                <label class="form-label required">用餐日期</label>
                                <input type="date" class="form-control" name="meal_date" 
                                       value="<?php echo $projectStartDate; ?>" 
                                       min="<?php echo $projectStartDate; ?>" 
                                       max="<?php echo $projectEndDate; ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label required">用餐时间</label>
                                <input type="time" class="form-control" name="meal_time" value="12:00" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label required">送餐时间</label>
                                <input type="time" class="form-control" name="delivery_time" value="11:30" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">特殊要求</label>
                                <input type="text" class="form-control" name="special_requirements" 
                                       placeholder="如：不要辣椒、素食等">
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="meals_new.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left me-2"></i>返回
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-2"></i>提交报餐
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 初始化餐类型卡片为禁用状态
    const mealTypeCards = document.querySelectorAll('[data-meal-type]');
    mealTypeCards.forEach(card => {
        card.classList.add('disabled');
    });
    
    // 餐类型选择
    mealTypeCards.forEach(card => {
        card.addEventListener('click', function() {
            // 检查是否已选择人员
            if (isPersonnelSelected()) {
                // 移除其他选中状态
                mealTypeCards.forEach(c => c.classList.remove('selected'));
                // 添加选中状态
                this.classList.add('selected');
                // 选中radio
                this.querySelector('input[type="radio"]').checked = true;
                // 显示套餐选择
                document.getElementById('packageSelection').style.display = 'block';
                loadPackages(this.dataset.mealType);
            } else {
                // 显示提示信息
                showPersonnelRequiredAlert();
            }
        });
    });
    
    // 人员选择方式切换
    document.getElementById('selectDepartment').addEventListener('change', function() {
        document.getElementById('departmentSelection').style.display = 'block';
        document.getElementById('individualSelection').style.display = 'none';
        // 检查是否需要启用餐类型选择
        checkAndEnableMealTypes();
    });
    
    document.getElementById('selectIndividual').addEventListener('change', function() {
        document.getElementById('departmentSelection').style.display = 'none';
        document.getElementById('individualSelection').style.display = 'block';
        // 检查是否需要启用餐类型选择
        checkAndEnableMealTypes();
    });
    
    // 部门选择更新
    const departmentCheckboxes = document.querySelectorAll('.department-checkbox');
    departmentCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', checkAndEnableMealTypes);
    });
    
    // 个人选择更新
    const personnelCheckboxes = document.querySelectorAll('.personnel-checkbox');
    personnelCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateSelectedPersonnel();
            checkAndEnableMealTypes();
        });
    });
});

// 检查是否已选择人员
function isPersonnelSelected() {
    const selectionType = document.querySelector('input[name="selection_type"]:checked');
    
    if (!selectionType) return false;
    
    const selectionTypeValue = selectionType.value;
    if (selectionTypeValue === 'department') {
        const selectedDepartments = document.querySelectorAll('input[name="selected_departments[]"]:checked');
        return selectedDepartments.length > 0;
    } else if (selectionTypeValue === 'individual') {
        const checkedPersonnel = document.querySelectorAll('.personnel-checkbox:checked');
        return checkedPersonnel.length > 0;
    }
    
    return false;
}

// 检查并启用/禁用餐类型选择
function checkAndEnableMealTypes() {
    const mealTypeCards = document.querySelectorAll('[data-meal-type]');
    const alert = document.getElementById('personnelRequiredAlert');
    
    if (isPersonnelSelected()) {
        // 启用餐类型选择
        mealTypeCards.forEach(card => {
            card.classList.remove('disabled');
        });
        // 隐藏提示信息
        alert.style.display = 'none';
    } else {
        // 禁用餐类型选择
        mealTypeCards.forEach(card => {
            card.classList.add('disabled');
            card.classList.remove('selected');
        });
        // 清除已选中的餐类型
        document.querySelectorAll('input[name="meal_type"]').forEach(radio => {
            radio.checked = false;
        });
        // 隐藏套餐选择
        document.getElementById('packageSelection').style.display = 'none';
    }
}

// 显示人员选择提示信息
function showPersonnelRequiredAlert() {
    const alert = document.getElementById('personnelRequiredAlert');
    alert.style.display = 'block';
    
    // 3秒后自动隐藏
    setTimeout(() => {
        alert.style.display = 'none';
    }, 3000);
}

// 加载套餐
function loadPackages(mealType) {
    const packageCards = document.getElementById('packageCards');
    
    // 显示加载中
    packageCards.innerHTML = '<div class="col-12 text-center"><i class="bi bi-hourglass-split"></i> 加载套餐中...</div>';
    
    // AJAX请求获取套餐
    fetch(`ajax/get_packages.php?meal_type=${encodeURIComponent(mealType)}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.packages) {
                let html = '';
                
                // 添加套餐选项
                data.packages.forEach((pkg, index) => {
                    // 第一个套餐默认选中
                    const checked = index === 0 ? 'checked' : '';
                    html += `
                        <div class="col-md-6 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="package_id" value="${pkg.id}" id="package_${pkg.id}" ${checked}>
                                <label class="form-check-label" for="package_${pkg.id}">
                                    <div class="package-option p-2 border rounded">
                                        <h6 class="mb-1">${pkg.name}</h6>
                                        ${pkg.description ? `<p class="small text-muted mb-1">${pkg.description}</p>` : ''}
                                        <small class="text-success">包含：${pkg.items}</small>
                                    </div>
                                </label>
                            </div>
                        </div>
                    `;
                });
                
                if (data.packages.length === 0) {
                    html += '<div class="col-12 text-center text-muted">该餐类型暂无可用套餐</div>';
                }
                
                packageCards.innerHTML = html;
            } else {
                const errorMsg = data.error || '未知错误';
                packageCards.innerHTML = `<div class="col-12 text-center text-danger">加载套餐失败：${errorMsg}</div>`;
            }
        })
        .catch(error => {
            console.error('Error loading packages:', error);
            packageCards.innerHTML = '<div class="col-12 text-center text-danger">网络错误，无法加载套餐</div>';
        });
}

// 全选功能
function selectAll() {
    const checkboxes = document.querySelectorAll('.personnel-checkbox');
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);
    
    checkboxes.forEach(checkbox => {
        checkbox.checked = !allChecked;
    });
    
    updateSelectedPersonnel();
    checkAndEnableMealTypes();
}

// 更新已选人员显示
function updateSelectedPersonnel() {
    const checked = document.querySelectorAll('.personnel-checkbox:checked');
    const selectedDiv = document.getElementById('selectedPersonnel');
    
    if (checked.length === 0) {
        selectedDiv.innerHTML = '<p class="no-selection-text">暂无选择</p>';
    } else {
        const names = Array.from(checked).map(cb => {
            const label = document.querySelector(`label[for="${cb.id}"]`);
            return label.textContent.trim();
        });
        
        selectedDiv.innerHTML = `
            <p class="selected-personnel-title">已选择 <span class="selected-personnel-count">${checked.length}</span> 人：</p>
            <div class="d-flex flex-wrap">
                ${names.map(name => `<span class="badge selected-personnel-badge">${name}</span>`).join('')}
            </div>
        `;
    }
}

// 表单验证
function validateForm() {
    const mealType = document.querySelector('input[name="meal_type"]:checked');
    const selectionType = document.querySelector('input[name="selection_type"]:checked');
    const mealDate = document.querySelector('input[name="meal_date"]').value;
    const mealTime = document.querySelector('input[name="meal_time"]').value;
    const deliveryTime = document.querySelector('input[name="delivery_time"]').value;
    
    if (!mealDate || !mealTime || !deliveryTime) {
        alert('请填写完整的用餐信息');
        return false;
    }
    
    if (!mealType) {
        alert('请选择餐类型');
        return false;
    }
    
    if (!selectionType) {
        alert('请选择人员选择方式');
        return false;
    }
    
    // 验证人员选择
    const selectionTypeValue = selectionType.value;
    if (selectionTypeValue === 'department') {
        const selectedDepartments = document.querySelectorAll('input[name="selected_departments[]"]:checked');
        if (selectedDepartments.length === 0) {
            alert('请至少选择一个部门');
            return false;
        }
    } else if (selectionTypeValue === 'individual') {
        const checkedPersonnel = document.querySelectorAll('.personnel-checkbox:checked');
        if (checkedPersonnel.length === 0) {
            alert('请选择至少一名人员');
            return false;
        }
    }
    
    return true;
}

// 绑定表单提交验证
document.getElementById('batchOrderForm').addEventListener('submit', function(e) {
    if (!validateForm()) {
        e.preventDefault();
    }
});
</script>

<?php include 'includes/footer.php'; ?>