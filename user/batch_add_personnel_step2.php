<?php
// 启动会话
session_start();

// 检查用户是否已登录
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 设置页面特定变量
$page_title = '批量添加人员 - 第二步';
$active_page = 'personnel';
$show_page_title = '批量添加人员';
$page_icon = 'people';

// 包含统一头部文件
include 'includes/header.php';

// 数据库连接
$database = new Database();
$pdo = $database->getConnection();

try {
    if (!$pdo) {
        throw new Exception("无法连接到数据库");
    }
} catch(Exception $e) {
    echo '<div class="alert alert-danger">数据库连接失败: ' . $e->getMessage() . '</div>';
    include 'includes/footer.php';
    exit;
}

// 获取当前用户的项目信息
$project_id = $_SESSION['project_id'] ?? 0;
$project_name = '';
$departments = [];

if ($project_id > 0) {
    try {
        // 获取项目名称
        $stmt = $pdo->prepare("SELECT name FROM projects WHERE id = ?");
        $stmt->execute([$project_id]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);
        $project_name = $project['name'] ?? '';
        
        // 获取项目下的部门
        $stmt = $pdo->prepare("SELECT id, name FROM departments WHERE project_id = ? ORDER BY name");
        $stmt->execute([$project_id]);
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        $error_message = "获取项目信息失败: " . $e->getMessage();
    }
}

// 获取已解析的人员信息
$parsed_personnel = [];
if (isset($_POST['personnel_data'])) {
    $parsed_personnel = json_decode($_POST['personnel_data'], true);
}

if (empty($parsed_personnel)) {
    header("Location: batch_add_personnel.php");
    exit;
}

// 处理表单提交
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_departments') {
    $assignments = $_POST['assignments'] ?? [];
    
    if (empty($assignments)) {
        $error = '请为人员分配部门！';
    } else {
        try {
            $success_count = 0;
            $error_count = 0;
            $skip_count = 0;
            $detailed_errors = []; // 详细错误信息数组
            $skip_details = []; // 跳过人员详细信息
            
            foreach ($assignments as $index => $assignment) {
                if (!isset($parsed_personnel[$index])) continue;
                
                $person = $parsed_personnel[$index];
                $department_id = intval($assignment['department_id'] ?? 0);
                $position = trim($assignment['position'] ?? '');
                
                if ($department_id <= 0) continue;
                
                // 获取部门名称用于错误提示
                $department_name = '';
                foreach ($departments as $dept) {
                    if ($dept['id'] == $department_id) {
                        $department_name = $dept['name'];
                        break;
                    }
                }
                
                // 检查是否需要跳过
                $should_skip = false;
                $personnel_id = null;
                
                try {
                    // 识别证件类型
                    $id_card = $person['id_card'];
                    $id_type = '身份证';
                    
                    if (preg_match('/^[A-Z]\d{8}$/', $id_card) || preg_match('/^[A-Z]{1,3}\d{6,12}$/', $id_card) || preg_match('/^P[A-Z0-9]{7,9}$/', $id_card)) {
                        $id_type = '护照';
                    } elseif (preg_match('/^[HM]\d{8,10}$/', $id_card)) {
                        $id_type = '港澳通行证';
                    } elseif (preg_match('/^T\d{8,10}$/', $id_card)) {
                        $id_type = '台湾通行证';
                    }
                    
                    // 检查是否已存在相同证件号的人员（只在有证件号时检查）
                    if (!empty($person['id_card'])) {
                        $stmt = $pdo->prepare("SELECT id FROM personnel WHERE id_card = ?");
                        $stmt->execute([$person['id_card']]);
                        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($existing) {
                            // 检查该人员是否已在此项目中
                            $stmt = $pdo->prepare("SELECT COUNT(*) FROM project_department_personnel pdp 
                                                 JOIN personnel p ON pdp.personnel_id = p.id 
                                                 WHERE p.id_card = ? AND pdp.project_id = ?");
                            $stmt->execute([$person['id_card'], $project_id]);
                            $project_exists = $stmt->fetchColumn();
                            
                            if ($project_exists > 0) {
                                // 标记跳过，不启动事务
                                $should_skip = true;
                            } else {
                                $personnel_id = $existing['id'];
                            }
                        }
                    } else {
                        // 没有证件号，检查姓名是否重复（宽松检查）
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM project_department_personnel pdp 
                                             JOIN personnel p ON pdp.personnel_id = p.id 
                                             WHERE p.name = ? AND pdp.project_id = ?");
                        $stmt->execute([$person['name'], $project_id]);
                        $name_exists = $stmt->fetchColumn();
                        
                        if ($name_exists > 0) {
                            // 同名人员已存在，跳过
                            $should_skip = true;
                            $skip_details[] = [
                                'name' => $person['name'],
                                'id_card' => $person['id_card'] ?: '未提供',
                                'department' => $department_name,
                                'reason' => '同名人员已在项目中'
                            ];
                        }
                    }
                    
                    if (!$should_skip) {
                        $pdo->beginTransaction();
                        
                        try {
                            if ($personnel_id === null) {
                                // 确保gender字段值符合数据库枚举要求
                                $gender = $person['gender'] ?? '';
                                if (!in_array($gender, ['男', '女', '其他'])) {
                                    $gender = '其他'; // 默认值
                                }
                                
                                // 插入新人员（允许不完整信息）
                                $stmt = $pdo->prepare("INSERT INTO personnel (name, email, phone, id_card, gender) VALUES (?, ?, ?, ?, ?)");
                                $stmt->execute([
                                    $person['name'],
                                    $person['email'] ?? '',
                                    $person['phone'] ?? '',
                                    $person['id_card'] ?? '', // 允许为空
                                    $gender
                                ]);
                                $personnel_id = $pdo->lastInsertId();
                            }
                            
                            // 添加到项目部门
                            $stmt = $pdo->prepare("INSERT INTO project_department_personnel (project_id, department_id, personnel_id, position) VALUES (?, ?, ?, ?)");
                            $stmt->execute([$project_id, $department_id, $personnel_id, $position]);
                            
                            $pdo->commit();
                            $success_count++;
                            
                        } catch(Exception $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            $error_count++;
                            $detailed_errors[] = [
                                'name' => $person['name'],
                                'id_card' => $person['id_card'],
                                'error' => $e->getMessage(),
                                'department' => $department_name
                            ];
                        }
                    } else {
                        // 跳过已在项目中的重复人员
                        $skip_count++;
                        $skip_details[] = [
                            'name' => $person['name'],
                            'id_card' => $person['id_card'],
                            'department' => $department_name,
                            'reason' => '已在当前项目中'
                        ];
                    }
                } catch(Exception $e) {
                    $error_count++;
                }
            }
            
            $message = "成功添加 {$success_count} 人";
            if ($error_count > 0) {
                $message .= "，失败 {$error_count} 人";
            }
            if ($skip_count > 0) {
                $message .= "，跳过 {$skip_count} 人（已在项目中）";
            }
            
            // 如果有详细错误，构建可展开的错误详情
            if (!empty($detailed_errors)) {
                $message .= '<br><button class="btn btn-link btn-sm p-0" type="button" data-bs-toggle="collapse" data-bs-target="#errorDetails">';
                $message .= '查看详细错误 <i class="bi bi-chevron-down"></i></button>';
                $message .= '<div class="collapse mt-2" id="errorDetails"><ul class="list-group">';
                foreach ($detailed_errors as $err) {
                    $message .= '<li class="list-group-item list-group-item-danger">';
                    $message .= '<strong>'.htmlspecialchars($err['name']).'</strong> ('.htmlspecialchars($err['id_card']).') ';
                    $message .= '部门：'.htmlspecialchars($err['department']).' - '.htmlspecialchars($err['error']);
                    $message .= '</li>';
                }
                $message .= '</ul></div>';
            }
            
            // 如果有跳过人员，构建可展开的跳过详情
            if (!empty($skip_details)) {
                $message .= '<br><button class="btn btn-link btn-sm p-0" type="button" data-bs-toggle="collapse" data-bs-target="#skipDetails">';
                $message .= '查看跳过详情 <i class="bi bi-chevron-down"></i></button>';
                $message .= '<div class="collapse mt-2" id="skipDetails"><ul class="list-group">';
                foreach ($skip_details as $skip) {
                    $message .= '<li class="list-group-item list-group-item-warning">';
                    $message .= '<strong>'.htmlspecialchars($skip['name']).'</strong> ('.htmlspecialchars($skip['id_card']).') ';
                    $message .= '部门：'.htmlspecialchars($skip['department']).' - '.htmlspecialchars($skip['reason']);
                    $message .= '</li>';
                }
                $message .= '</ul></div>';
            }
        } catch(Exception $e) {
            $error = "处理过程中发生错误: " . $e->getMessage();
        }
    }
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
    
                    <h1 class="mt-4 mb-4">
                    <i class="bi bi-people"></i> 批量添加人员 - 第二步
                </h1>

                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle"></i> <?php echo $message; ?>
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
                        <h5 class="card-title mb-0">项目: <?php echo htmlspecialchars($project_name); ?></h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="assign_departments">
                            <input type="hidden" name="personnel_data" value='<?php echo htmlspecialchars(json_encode($parsed_personnel)); ?>'>
                            
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
                                        <select class="form-select" id="bulk_department" onchange="bulkAssignDepartment()">
                                            <option value="">批量分配部门...</option>
                                            <?php foreach ($departments as $dept): ?>
                                                <option value="<?php echo $dept['id']; ?>">
                                                    <?php echo htmlspecialchars($dept['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
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
                                                <select class="form-select form-select-sm department-select" 
                                                        name="assignments[<?php echo $index; ?>][department_id]" 
                                                        data-row="<?php echo $index; ?>" required>
                                                    <option value="">选择部门</option>
                                                    <?php foreach ($departments as $dept): ?>
                                                        <option value="<?php echo $dept['id']; ?>">
                                                            <?php echo htmlspecialchars($dept['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
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
                                <strong>提示：</strong>为以上人员选择部门和填写职位，已存在的人员会直接添加到当前项目部门
                            </div>
                            
                            <button type="submit" class="btn btn-primary" onclick="return confirm('⚠️ 确认添加人员\n\n您即将添加 <?php echo count($parsed_personnel); ?> 个人员到系统中\n• 请确保所有信息已正确填写\n• 已存在的人员将被更新部门信息\n• 新人员将被创建并分配到指定部门\n\n是否确认提交？');">
                                <i class="bi bi-check-circle"></i> 完成添加
                            </button>
                            <a href="batch_add_personnel.php?return=1&return_data=<?php echo urlencode($_POST['original_personnel_data'] ?? ''); ?>" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> 返回修改
                            </a>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php
// 包含页脚文件
include 'includes/footer.php';;
?>

<script>
    // 批量选择功能
    function toggleAll(checkbox) {
        const checkboxes = document.querySelectorAll('.row-checkbox');
        checkboxes.forEach(cb => {
            cb.checked = checkbox.checked;
            updateRowStyle(cb);
        });
    }

    function selectAll() {
        const checkboxes = document.querySelectorAll('.row-checkbox');
        checkboxes.forEach(cb => {
            cb.checked = true;
            updateRowStyle(cb);
        });
        document.getElementById('select_all').checked = true;
    }

    function clearAll() {
        const checkboxes = document.querySelectorAll('.row-checkbox');
        checkboxes.forEach(cb => {
            cb.checked = false;
            updateRowStyle(cb);
        });
        document.getElementById('select_all').checked = false;
    }

    function updateRowSelection(checkbox) {
        updateRowStyle(checkbox);
        updateSelectAllState();
    }

    function updateRowStyle(checkbox) {
        const row = checkbox.closest('tr');
        if (checkbox.checked) {
            row.classList.add('table-primary');
        } else {
            row.classList.remove('table-primary');
        }
    }

    function updateSelectAllState() {
        const allCheckboxes = document.querySelectorAll('.row-checkbox');
        const checkedCheckboxes = document.querySelectorAll('.row-checkbox:checked');
        const selectAllCheckbox = document.getElementById('select_all');
        
        if (checkedCheckboxes.length === 0) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        } else if (checkedCheckboxes.length === allCheckboxes.length) {
            selectAllCheckbox.checked = true;
            selectAllCheckbox.indeterminate = false;
        } else {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = true;
        }
    }

    function bulkAssignDepartment() {
        const departmentId = document.getElementById('bulk_department').value;
        if (!departmentId) return;

        const checkedRows = document.querySelectorAll('.row-checkbox:checked');
        checkedRows.forEach(checkbox => {
            const rowIndex = checkbox.dataset.row;
            const departmentSelect = document.querySelector(`select[data-row="${rowIndex}"].department-select`);
            if (departmentSelect) {
                departmentSelect.value = departmentId;
            }
        });
    }

    function bulkAssignPosition() {
        const position = document.getElementById('bulk_position').value.trim();
        if (!position) return;

        const checkedRows = document.querySelectorAll('.row-checkbox:checked');
        checkedRows.forEach(checkbox => {
            const rowIndex = checkbox.dataset.row;
            const positionInput = document.querySelector(`input[data-row="${rowIndex}"].position-input`);
            if (positionInput) {
                positionInput.value = position;
            }
        });
        
        document.getElementById('bulk_position').value = '';
    }

    // 初始化
    document.addEventListener('DOMContentLoaded', function() {
        // 为所有复选框添加初始状态
        const checkboxes = document.querySelectorAll('.row-checkbox');
        checkboxes.forEach(cb => {
            updateRowStyle(cb);
        });
        updateSelectAllState();
    });
</script>