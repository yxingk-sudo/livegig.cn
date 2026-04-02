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

// 获取所有公司
$companies_stmt = $db->prepare("SELECT id, name FROM companies ORDER BY name");
$companies_stmt->execute();
$companies = $companies_stmt->fetchAll(PDO::FETCH_ASSOC);

$message = '';
$error = '';
$page_title = '批量添加人员 - 第二步';
$parsed_personnel = []; // 初始化人员数据数组
$personnel_data_json = ''; // 初始化JSON字符串

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
        $added_names = [];  // 记录新增人员姓名
        $updated_names = [];  // 记录更新人员姓名
        
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
                $updated_names[] = $person_data['name'];  // 记录更新的人员姓名
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
                $added_names[] = $person_data['name'];  // 记录新增的人员姓名
            }
        }
        
        $db->commit();
        
        // 构建详细的成功消息
        $summary = [];
        if ($added_count > 0) {
            $summary[] = "成功添加 {$added_count} 人";
        }
        if ($updated_count > 0) {
            $summary[] = "成功更新 {$updated_count} 人";
        }
        
        $message = implode("，", $summary);
        
        // 添加人员姓名列表
        if (!empty($added_names)) {
            $message .= "<br><br><strong>新增人员：</strong><br>";
            $message .= "<span class='badge bg-success me-1 mb-1'>" . implode("</span> <span class='badge bg-success me-1 mb-1'>", $added_names) . "</span>";
        }
        if (!empty($updated_names)) {
            $message .= "<br><br><strong>更新人员：</strong><br>";
            $message .= "<span class='badge bg-info me-1 mb-1'>" . implode("</span> <span class='badge bg-info me-1 mb-1'>", $updated_names) . "</span>";
        }
        
        // 获取项目和部门名称用于显示
        $assignment_details = [];
        if (isset($_POST['assignments']) && is_array($_POST['assignments'])) {
            foreach ($_POST['assignments'] as $assignment) {
                if (!empty($assignment['project_id']) && !empty($assignment['department_id'])) {
                    $project_id = intval($assignment['project_id']);
                    $department_id = intval($assignment['department_id']);
                    
                    // 获取项目名称
                    $proj_stmt = $db->prepare("SELECT name FROM projects WHERE id = ?");
                    $proj_stmt->execute([$project_id]);
                    $project = $proj_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // 获取部门名称
                    $dept_stmt = $db->prepare("SELECT name FROM departments WHERE id = ?");
                    $dept_stmt->execute([$department_id]);
                    $department = $dept_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($project && $department) {
                        $key = $project['name'] . ' - ' . $department['name'];
                        if (!isset($assignment_details[$key])) {
                            $assignment_details[$key] = 0;
                        }
                        $assignment_details[$key]++;
                    }
                }
            }
        }
        
        // 添加分配详情
        if (!empty($assignment_details)) {
            $message .= "<br><br><strong>分配详情：</strong><br>";
            foreach ($assignment_details as $detail => $count) {
                $message .= "<i class='bi bi-check-circle-fill text-success'></i> {$detail}: {$count} 人<br>";
            }
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
        
        // 失败时重新解析人员数据，以便继续显示表单
        if (isset($_POST['personnel_data'])) {
            $personnel_data_json = $_POST['personnel_data'];
            $parsed_personnel = json_decode($personnel_data_json, true);
            if (!$parsed_personnel) {
                $parsed_personnel = [];
            }
        }
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
        } else {
            // 使用SESSION保存原始输入文本，以便在第一步页面回显
            $_SESSION['batch_add_original_input'] = $original_input_text;
            $_SESSION['batch_add_personnel_data'] = $personnel_data_json;
            // 重定向回第一步页面
            header("Location: batch_add_personnel.php?step=1&action=edit");
            exit;
        }
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
            $parsed_personnel = []; // 失败时也要初始化为空数组
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
                    <!-- 返回第一步表单 -->
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="personnel_data" value='<?php echo isset($personnel_data_json) ? htmlspecialchars($personnel_data_json) : ''; ?>'>
                        <input type="hidden" name="original_input_text" value='<?php echo isset($_POST['original_input_text']) ? htmlspecialchars($_POST['original_input_text']) : ''; ?>'>
                        <input type="hidden" name="action" value="return_to_edit">
                        <button type="submit" class="btn btn-secondary me-2">
                            <i class="bi bi-arrow-left"></i> 返回第一步
                        </button>
                    </form>
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
                                    <select class="form-select" id="batchDepartment" disabled>
                                        <option value="">批量选择部门</option>
                                    </select>
                                    <button type="button" class="btn btn-outline-success" onclick="applyBatchSelection()" title="应用批量选择">
                                        <i class="bi bi-check-circle"></i> 应用
                                    </button>
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
        fetch('api/get_company_projects.php?company_id=' + companyId)
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
                    alert('获取项目列表失败：' + (data.error || data.message || '未知错误'));
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
        fetch('api/get_project_departments.php?project_id=' + projectId)
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
                    alert('获取部门列表失败：' + (data.error || data.message || '未知错误'));
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
        
        console.log('[loadBatchProjects] 选择的公司ID:', companyId);
        
        // 清空后续选择
        projectSelect.innerHTML = '<option value="">批量选择项目</option>';
        departmentSelect.innerHTML = '<option value="">批量选择部门</option>';
        projectSelect.disabled = true;
        departmentSelect.disabled = true;
        
        if (!companyId) return;
        
        // 发送AJAX请求获取项目列表
        console.log('[loadBatchProjects] 发送API请求:', 'api/get_company_projects.php?company_id=' + companyId);
        fetch('api/get_company_projects.php?company_id=' + companyId)
            .then(response => {
                console.log('[loadBatchProjects] 响应状态:', response.status);
                return response.json();
            })
            .then(data => {
                console.log('[loadBatchProjects] 响应数据:', data);
                if (data.success) {
                    projectSelect.innerHTML = '<option value="">批量选择项目</option>';
                    data.projects.forEach(project => {
                        const option = document.createElement('option');
                        option.value = project.id;
                        option.textContent = project.name;
                        projectSelect.appendChild(option);
                    });
                    projectSelect.disabled = false;
                    console.log('[loadBatchProjects] 成功加载', data.projects.length, '个项目');
                } else {
                    console.error('[loadBatchProjects] API返回错误:', data.error);
                    alert('获取项目列表失败：' + (data.error || data.message || '未知错误'));
                }
            })
            .catch(error => {
                console.error('[loadBatchProjects] 请求失败:', error);
                alert('获取项目列表失败');
            });
    }
    
    function loadBatchDepartments() {
        const projectId = document.getElementById('batchProject').value;
        const departmentSelect = document.getElementById('batchDepartment');
        
        console.log('[loadBatchDepartments] 选择的项目id:', projectId);
        
        // 清空部门选择
        departmentSelect.innerHTML = '<option value="">批量选择部门</option>';
        departmentSelect.disabled = true;
        
        if (!projectId) return;
        
        // 发送AJAX请求获取部门列表
        console.log('[loadBatchDepartments] 发送API请求:', 'api/get_project_departments.php?project_id=' + projectId);
        fetch('api/get_project_departments.php?project_id=' + projectId)
            .then(response => {
                console.log('[loadBatchDepartments] 响应状态:', response.status);
                return response.json();
            })
            .then(data => {
                console.log('[loadBatchDepartments] 响应数据:', data);
                if (data.success) {
                    departmentSelect.innerHTML = '<option value="">批量选择部门</option>';
                    data.departments.forEach(department => {
                        const option = document.createElement('option');
                        option.value = department.id;
                        option.textContent = department.name;
                        departmentSelect.appendChild(option);
                    });
                    departmentSelect.disabled = false;
                    console.log('[loadBatchDepartments] 成功加载', data.departments.length, '个部门');
                } else {
                    console.error('[loadBatchDepartments] API返回错误:', data.error);
                    alert('获取部门列表失败：' + (data.error || data.message || '未知错误'));
                }
            })
            .catch(error => {
                console.error('[loadBatchDepartments] 请求失败:', error);
                alert('获取部门列表失败');
            });
    }
    
    function applyBatchSelection() {
        const companyId = document.getElementById('batchCompany').value;
        const projectId = document.getElementById('batchProject').value;
        const departmentId = document.getElementById('batchDepartment').value;
        const selectedRows = document.querySelectorAll('.row-checkbox:checked');
        
        console.log('[applyBatchSelection] 公司ID:', companyId, '项目id:', projectId, '部门ID:', departmentId);
        console.log('[applyBatchSelection] 选中行数:', selectedRows.length);
        
        if (!companyId || !projectId || !departmentId) {
            alert('请先选择公司、项目和部门');
            return;
        }
        
        if (selectedRows.length === 0) {
            alert('请先选择要分配的人员');
            return;
        }
        
        // 获取批量选择的选项文本（用于显示）
        const batchCompanySelect = document.getElementById('batchCompany');
        const batchProjectSelect = document.getElementById('batchProject');
        const batchDepartmentSelect = document.getElementById('batchDepartment');
        
        const companyText = batchCompanySelect.options[batchCompanySelect.selectedIndex].text;
        const projectText = batchProjectSelect.options[batchProjectSelect.selectedIndex].text;
        const departmentText = batchDepartmentSelect.options[batchDepartmentSelect.selectedIndex].text;
        
        console.log('[applyBatchSelection] 选中的文本 - 公司:', companyText, '项目:', projectText, '部门:', departmentText);
        
        // 为每个选中的行应用选择
        selectedRows.forEach((checkbox, index) => {
            const row = checkbox.dataset.row;
            const companySelect = document.querySelector(`.company-select[data-row="${row}"]`);
            const projectSelect = document.querySelector(`.project-select[data-row="${row}"]`);
            const departmentSelect = document.querySelector(`.department-select[data-row="${row}"]`);
            
            console.log(`[applyBatchSelection] 处理行 ${index + 1}/${selectedRows.length}:`, row);
            
            // 设置公司
            companySelect.value = companyId;
            
            // 清空并填充项目下拉框
            projectSelect.innerHTML = '<option value="">选择项目</option>';
            const projectOption = document.createElement('option');
            projectOption.value = projectId;
            projectOption.textContent = projectText;
            projectOption.selected = true;
            projectSelect.appendChild(projectOption);
            projectSelect.disabled = false;
            
            // 清空并填充部门下拉框
            departmentSelect.innerHTML = '<option value="">选择部门</option>';
            const departmentOption = document.createElement('option');
            departmentOption.value = departmentId;
            departmentOption.textContent = departmentText;
            departmentOption.selected = true;
            departmentSelect.appendChild(departmentOption);
            departmentSelect.disabled = false;
            
            console.log(`[applyBatchSelection] 行 ${row} 分配完成 - 公司: ${companyText}, 项目: ${projectText}, 部门: ${departmentText}`);
        });
        
        console.log('[applyBatchSelection] 批量分配完成，共处理', selectedRows.length, '行');
        alert(`已成功为 ${selectedRows.length} 个人员分配：\n公司: ${companyText}\n项目: ${projectText}\n部门: ${departmentText}`);
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