<?php
session_start();
require_once '../config/database.php';
require_once 'page_functions.php';

// 检查管理员是否已登录
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();

// 获取所有公司
$companies_stmt = $db->prepare("SELECT id, name FROM companies ORDER BY name");
$companies_stmt->execute();
$companies = $companies_stmt->fetchAll(PDO::FETCH_ASSOC);

$message = '';
$error = '';
$page_title = '批量添加人员 - 第二步';

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_departments') {
    try {
        // 获取人员数据
        $personnel_data = json_decode($_POST['personnel_data'], true);
        if (!$personnel_data) {
            throw new Exception('人员数据解析失败');
        }
        
        $db->beginTransaction();
        $added_count = 0;
        $updated_count = 0;
        
        foreach ($personnel_data as $index => $person_data) {
            // 获取分配信息
            $assignment = $_POST['assignments'][$index] ?? [];
            $company_id = intval($assignment['company_id'] ?? 0);
            $project_id = intval($assignment['project_id'] ?? 0);
            $department_id = intval($assignment['department_id'] ?? 0);
            $position = trim($assignment['position'] ?? '');
            
            // 验证必填字段
            if (empty($company_id) || empty($project_id) || empty($department_id)) {
                throw new Exception("第" . ($index + 1) . "行人员分配信息不完整");
            }
            
            // 检查人员是否已存在（根据姓名和身份证号）
            $existing_person = null;
            if (!empty($person_data['id_card'])) {
                // 优先按身份证号查找
                $check_stmt = $db->prepare("SELECT id FROM personnel WHERE id_card = ?");
                $check_stmt->execute([$person_data['id_card']]);
                $existing_person = $check_stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            if (!$existing_person && !empty($person_data['name'])) {
                // 按姓名查找
                $check_stmt = $db->prepare("SELECT id FROM personnel WHERE name = ? AND (id_card = '' OR id_card IS NULL)");
                $check_stmt->execute([$person_data['name']]);
                $existing_person = $check_stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            if ($existing_person) {
                // 人员已存在，更新信息并添加到部门
                $person_id = $existing_person['id'];
                
                // 更新人员信息
                $update_stmt = $db->prepare("UPDATE personnel SET 
                    name = ?, 
                    id_card = ?, 
                    phone = ?, 
                    gender = ?, 
                    email = ? 
                    WHERE id = ?");
                $update_stmt->execute([
                    $person_data['name'],
                    $person_data['id_card'],
                    $person_data['phone'],
                    $person_data['gender'],
                    $person_data['email'],
                    $person_id
                ]);
                
                // 检查是否已在指定项目部门中
                $check_pdp_stmt = $db->prepare("SELECT id FROM project_department_personnel 
                    WHERE personnel_id = ? AND project_id = ? AND department_id = ?");
                $check_pdp_stmt->execute([$person_id, $project_id, $department_id]);
                
                if (!$check_pdp_stmt->fetch()) {
                    // 添加到项目部门
                    $insert_pdp_stmt = $db->prepare("INSERT INTO project_department_personnel 
                        (project_id, department_id, personnel_id, position) VALUES (?, ?, ?, ?)");
                    $insert_pdp_stmt->execute([$project_id, $department_id, $person_id, $position]);
                }
                
                $updated_count++;
            } else {
                // 新人员，创建记录
                $insert_stmt = $db->prepare("INSERT INTO personnel 
                    (name, id_card, phone, gender, email) VALUES (?, ?, ?, ?, ?)");
                $insert_stmt->execute([
                    $person_data['name'],
                    $person_data['id_card'],
                    $person_data['phone'],
                    $person_data['gender'],
                    $person_data['email']
                ]);
                
                $person_id = $db->lastInsertId();
                
                // 添加到项目部门
                $insert_pdp_stmt = $db->prepare("INSERT INTO project_department_personnel 
                    (project_id, department_id, personnel_id, position) VALUES (?, ?, ?, ?)");
                $insert_pdp_stmt->execute([$project_id, $department_id, $person_id, $position]);
                
                $added_count++;
            }
        }
        
        $db->commit();
        
        if ($added_count > 0 && $updated_count > 0) {
            $message = "成功添加 {$added_count} 人，更新 {$updated_count} 人信息";
        } elseif ($added_count > 0) {
            $message = "成功添加 {$added_count} 人";
        } else {
            $message = "成功更新 {$updated_count} 人信息";
        }
        
        // 设置跳转目标
        if (isset($_POST['assignments'][0]['project_id']) && !empty($_POST['assignments'][0]['project_id'])) {
            $redirect_target = "personnel_enhanced.php?project_id=" . intval($_POST['assignments'][0]['project_id']);
        } else {
            $redirect_target = "personnel_enhanced.php";
        }
        
        // 自动跳转
        echo '<script>
            setTimeout(function() {
                window.location.href = "' . $redirect_target . '";
            }, 3000);
        </script>';
        
    } catch (Exception $e) {
        $db->rollBack();
        $error = '操作失败：' . $e->getMessage();
    }
} else if (isset($_POST['action']) && $_POST['action'] === 'return_to_edit') {
    // 从隐藏字段获取原始数据
    $personnel_data_json = $_POST['personnel_data'] ?? '';
    $original_input_text = $_POST['original_input_text'] ?? '';
        
    if (empty($personnel_data_json)) {
        $error = '未提供人员数据';
    } else {
        $parsed_personnel = json_decode($personnel_data_json, true);
        if (!$parsed_personnel) {
            $error = '人员数据解析失败';
        }
        // 重定向回第一步页面
        header("Location: batch_add_personnel.php?step=1");
        exit;
    }
} else {
    // 获取从前一页传递的人员数据
    $personnel_data_json = $_POST['personnel_data'] ?? $_GET['personnel_data'] ?? '';
    if (empty($personnel_data_json)) {
        $error = '未提供人员数据';
    } else {
        $parsed_personnel = json_decode($personnel_data_json, true);
        if (!$parsed_personnel) {
            $error = '人员数据解析失败';
        }
    }
}

include 'includes/header.php';
?>

<style>
    .form-select-sm, .form-control-sm {
        font-size: 0.875rem;
    }
</style>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1><i class="bi bi-people"></i> 批量添加人员 - 第二步</h1>
                <div>
                    <a href="batch_add_personnel.php" class="btn btn-secondary me-2">
                        <i class="bi bi-arrow-left"></i> 返回第一步
                    </a>
                    <a href="personnel_enhanced.php" class="btn btn-secondary">
                        <i class="bi bi-list"></i> 查看人员列表
                    </a>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle"></i> <?php echo $message; ?>
                    <?php if (strpos($message, '成功添加') !== false && strpos($message, '0 人') === false): ?>
                        <hr>
                        <p class="mb-0">
                            <i class="bi bi-info-circle"></i> 
                            正在自动跳转到人员列表... 
                            <span id="countdown">3</span> 秒
                            <br><small>如果没有自动跳转，请 
                                <?php if (isset($redirect_target)): ?>
                                    <a href="<?php echo htmlspecialchars($redirect_target); ?>">点击这里</a>
                                <?php else: ?>
                                    <a href="personnel_enhanced.php">点击这里</a>
                                <?php endif; ?>
                            </small>
                        </p>
                    <?php endif; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">为人员分配部门和职位</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="assign_departments">
                        <input type="hidden" name="personnel_data" value='<?php echo htmlspecialchars(json_encode($parsed_personnel)); ?>'>
                        <input type="hidden" name="original_input_text" value='<?php echo isset($_POST['original_input_text']) ? htmlspecialchars($_POST['original_input_text']) : ''; ?>'>
                        
                        <!-- 批量操作区域 -->
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="btn-group" role="group">
                                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="selectAll()">
                                        <i class="bi bi-check-all"></i> 全选
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearAll()">
                                        <i class="bi bi-x"></i> 清除选择
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="input-group input-group-sm">
                                    <select class="form-select" id="batchCompany" onchange="loadBatchProjects()">
                                        <option value="">批量选择公司</option>
                                        <?php foreach ($companies as $company): ?>
                                            <option value="<?php echo $company['id']; ?>">
                                                <?php echo htmlspecialchars($company['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select class="form-select" id="batchProject" onchange="loadBatchDepartments()" disabled>
                                        <option value="">批量选择项目</option>
                                    </select>
                                    <select class="form-select" id="batchDepartment" onchange="applyBatchSelection()" disabled>
                                        <option value="">批量选择部门</option>
                                    </select>
                                    <input type="text" class="form-control" id="bulk_position" placeholder="批量职位" 
                                           onkeyup="if(event.keyCode === 13) bulkAssignPosition()">
                                    <button type="button" class="btn btn-outline-primary" onclick="bulkAssignPosition()">
                                        <i class="bi bi-arrow-right"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th width="30">
                                            <input type="checkbox" id="select_all" onchange="toggleAll(this)">
                                        </th>
                                        <th width="30">#</th>
                                        <th>姓名</th>
                                        <th>身份证</th>
                                        <th>性别</th>
                                        <th>电话</th>
                                        <th>公司</th>
                                        <th>项目</th>
                                        <th>部门</th>
                                        <th>职位</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($parsed_personnel as $index => $person): ?>
                                        <tr data-row="<?php echo $index; ?>">
                                        <td>
                                            <input type="checkbox" class="row-checkbox" data-row="<?php echo $index; ?>" onchange="updateRowSelection(this)">
                                        </td>
                                        <td><?php echo $index + 1; ?></td>
                                        <td><?php echo htmlspecialchars($person['name']); ?></td>
                                        <td><?php echo htmlspecialchars($person['id_card']); ?></td>
                                        <td><?php echo htmlspecialchars($person['gender']); ?></td>
                                        <td><?php echo htmlspecialchars($person['phone']); ?></td>
                                        <td>
                                            <select class="form-select form-select-sm company-select" 
                                                    name="assignments[<?php echo $index; ?>][company_id]" 
                                                    data-row="<?php echo $index; ?>" 
                                                    onchange="loadProjects(this)" required>
                                                <option value="">选择公司</option>
                                                <?php foreach ($companies as $company): ?>
                                                    <option value="<?php echo $company['id']; ?>">
                                                        <?php echo htmlspecialchars($company['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <select class="form-select form-select-sm project-select" 
                                                    name="assignments[<?php echo $index; ?>][project_id]" 
                                                    data-row="<?php echo $index; ?>" 
                                                    onchange="loadDepartments(this)" required>
                                                <option value="">选择项目</option>
                                            </select>
                                        </td>
                                        <td>
                                            <select class="form-select form-select-sm department-select" 
                                                    name="assignments[<?php echo $index; ?>][department_id]" 
                                                    data-row="<?php echo $index; ?>" required>
                                                <option value="">选择部门</option>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="text" class="form-control form-control-sm position-input" 
                                                   name="assignments[<?php echo $index; ?>][position]" 
                                                   data-row="<?php echo $index; ?>"
                                                   placeholder="职位（可选）">
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            <strong>提示：</strong>为以上人员选择公司、项目、部门和填写职位，已存在的人员会直接添加到指定部门
                        </div>
                        
                        <button type="submit" class="btn btn-primary" onclick="return confirm('⚠️ 确认添加人员\n\n您即将添加 <?php echo count($parsed_personnel); ?> 个人员到系统中\n• 请确保所有信息已正确填写\n• 已存在的人员将被更新部门信息\n• 新人员将被创建并分配到指定部门\n\n是否确认提交？');">
                            <i class="bi bi-check-circle"></i> 完成添加
                        </button>
                        <!-- 使用表单提交方式返回修改，保留原始数据 -->
                        <button type="submit" name="action" value="return_to_edit" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> 返回修改
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // 批量选择功能
    function toggleAll(checkbox) {
        const checkboxes = document.querySelectorAll('.row-checkbox');
        checkboxes.forEach(cb => {
            cb.checked = checkbox.checked;
            updateRowSelection(cb);
        });
    }
    
    function updateRowSelection(checkbox) {
        // 更新全选状态
        const allCheckboxes = document.querySelectorAll('.row-checkbox');
        const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
        const selectAllCheckbox = document.getElementById('select_all');
        
        if (selectAllCheckbox) {
            if (checkedCount === 0) {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = false;
            } else if (checkedCount === allCheckboxes.length) {
                selectAllCheckbox.checked = true;
                selectAllCheckbox.indeterminate = false;
            } else {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = true;
            }
        }
    }
    
    function selectAll() {
        const checkboxes = document.querySelectorAll('.row-checkbox');
        checkboxes.forEach(cb => {
            cb.checked = true;
            updateRowSelection(cb);
        });
    }
    
    function clearAll() {
        const checkboxes = document.querySelectorAll('.row-checkbox');
        checkboxes.forEach(cb => {
            cb.checked = false;
            updateRowSelection(cb);
        });
    }
    
    // 加载项目列表
    function loadProjects(selectElement) {
        const companyId = selectElement.value;
        const row = selectElement.dataset.row;
        const projectSelect = document.querySelector(`.project-select[data-row="${row}"]`);
        const departmentSelect = document.querySelector(`.department-select[data-row="${row}"]`);
        
        // 清空后续选择
        projectSelect.innerHTML = '<option value="">选择项目</option>';
        departmentSelect.innerHTML = '<option value="">选择部门</option>';
        projectSelect.disabled = true;
        departmentSelect.disabled = true;
        
        if (!companyId) return;
        
        // 发送AJAX请求获取项目列表
        fetch('api/api_get_projects.php?company_id=' + companyId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    projectSelect.innerHTML = '<option value="">选择项目</option>';
                    data.projects.forEach(project => {
                        const option = document.createElement('option');
                        option.value = project.id;
                        option.textContent = project.name;
                        projectSelect.appendChild(option);
                    });
                    projectSelect.disabled = false;
                } else {
                    alert('获取项目列表失败：' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('获取项目列表失败');
            });
    }
    
    // 加载部门列表
    function loadDepartments(selectElement) {
        const projectId = selectElement.value;
        const row = selectElement.dataset.row;
        const departmentSelect = document.querySelector(`.department-select[data-row="${row}"]`);
        
        // 清空部门选择
        departmentSelect.innerHTML = '<option value="">选择部门</option>';
        departmentSelect.disabled = true;
        
        if (!projectId) return;
        
        // 发送AJAX请求获取部门列表
        fetch('api/api_get_departments.php?project_id=' + projectId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    departmentSelect.innerHTML = '<option value="">选择部门</option>';
                    data.departments.forEach(department => {
                        const option = document.createElement('option');
                        option.value = department.id;
                        option.textContent = department.name;
                        departmentSelect.appendChild(option);
                    });
                    departmentSelect.disabled = false;
                } else {
                    alert('获取部门列表失败：' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('获取部门列表失败');
            });
    }
    
    // 批量操作功能
    function loadBatchProjects() {
        const companyId = document.getElementById('batchCompany').value;
        const projectSelect = document.getElementById('batchProject');
        const departmentSelect = document.getElementById('batchDepartment');
        
        // 清空后续选择
        projectSelect.innerHTML = '<option value="">批量选择项目</option>';
        departmentSelect.innerHTML = '<option value="">批量选择部门</option>';
        projectSelect.disabled = true;
        departmentSelect.disabled = true;
        
        if (!companyId) return;
        
        // 发送AJAX请求获取项目列表
        fetch('api/api_get_projects.php?company_id=' + companyId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    projectSelect.innerHTML = '<option value="">批量选择项目</option>';
                    data.projects.forEach(project => {
                        const option = document.createElement('option');
                        option.value = project.id;
                        option.textContent = project.name;
                        projectSelect.appendChild(option);
                    });
                    projectSelect.disabled = false;
                } else {
                    alert('获取项目列表失败：' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('获取项目列表失败');
            });
    }
    
    function loadBatchDepartments() {
        const projectId = document.getElementById('batchProject').value;
        const departmentSelect = document.getElementById('batchDepartment');
        
        // 清空部门选择
        departmentSelect.innerHTML = '<option value="">批量选择部门</option>';
        departmentSelect.disabled = true;
        
        if (!projectId) return;
        
        // 发送AJAX请求获取部门列表
        fetch('api/api_get_departments.php?project_id=' + projectId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    departmentSelect.innerHTML = '<option value="">批量选择部门</option>';
                    data.departments.forEach(department => {
                        const option = document.createElement('option');
                        option.value = department.id;
                        option.textContent = department.name;
                        departmentSelect.appendChild(option);
                    });
                    departmentSelect.disabled = false;
                } else {
                    alert('获取部门列表失败：' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('获取部门列表失败');
            });
    }
    
    function applyBatchSelection() {
        const companyId = document.getElementById('batchCompany').value;
        const projectId = document.getElementById('batchProject').value;
        const departmentId = document.getElementById('batchDepartment').value;
        const selectedRows = document.querySelectorAll('.row-checkbox:checked');
        
        if (!companyId || !projectId || !departmentId) {
            alert('请先选择公司、项目和部门');
            return;
        }
        
        selectedRows.forEach(checkbox => {
            const row = checkbox.dataset.row;
            const companySelect = document.querySelector(`.company-select[data-row="${row}"]`);
            const projectSelect = document.querySelector(`.project-select[data-row="${row}"]`);
            const departmentSelect = document.querySelector(`.department-select[data-row="${row}"]`);
            
            // 设置选择值
            companySelect.value = companyId;
            loadProjects(companySelect); // 触发项目加载
            
            // 延迟设置项目和部门值，等待AJAX完成
            setTimeout(() => {
                projectSelect.value = projectId;
                loadDepartments(projectSelect); // 触发部门加载
                
                setTimeout(() => {
                    departmentSelect.value = departmentId;
                }, 100);
            }, 100);
        });
    }
    
    function bulkAssignPosition() {
        const position = document.getElementById('bulk_position').value;
        const selectedRows = document.querySelectorAll('.row-checkbox:checked');
        
        if (!position) {
            alert('请先输入职位');
            return;
        }
        
        selectedRows.forEach(checkbox => {
            const row = checkbox.dataset.row;
            const positionInput = document.querySelector(`.position-input[data-row="${row}"]`);
            positionInput.value = position;
        });
        
        // 清空输入框
        document.getElementById('bulk_position').value = '';
    }
    
    // 页面加载完成后初始化
    document.addEventListener('DOMContentLoaded', function() {
        // 初始化计时器
        const countdownElement = document.getElementById('countdown');
        if (countdownElement) {
            let countdown = 3;
            const timer = setInterval(() => {
                countdown--;
                countdownElement.textContent = countdown;
                if (countdown <= 0) {
                    clearInterval(timer);
                }
            }, 1000);
        }
    });
</script>

<?php include 'includes/footer.php'; ?>