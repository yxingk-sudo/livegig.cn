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
$excel_errors = [];
$source = $_GET['source'] ?? 'text';

// 处理Excel文件解析
if ($source === 'excel' && isset($_SESSION['temp_excel_file'])) {
    $temp_file = $_SESSION['temp_excel_file'];
    if (file_exists($temp_file)) {
        // 检查是否使用PhpSpreadsheet
        $use_php_spreadsheet = class_exists('PhpOffice\PhpSpreadsheet\IOFactory');
        
        if ($use_php_spreadsheet) {
            // 使用PhpSpreadsheet解析
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($temp_file);
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = $worksheet->toArray();
                
                // 跳过标题行
                $headers = array_map('strtolower', array_map('trim', $rows[0]));
                $column_map = [];
                
                // 映射列名（支持中英文）
                foreach ($headers as $index => $header) {
                    if (in_array($header, ['姓名', 'name'])) {
                        $column_map['name'] = $index;
                    } elseif (in_array($header, ['邮箱', 'email'])) {
                        $column_map['email'] = $index;
                    } elseif (in_array($header, ['电话', '手机', 'phone', 'mobile'])) {
                        $column_map['phone'] = $index;
                    } elseif (in_array($header, ['身份证', '证件号', 'id_card', 'idcard'])) {
                        $column_map['id_card'] = $index;
                    } elseif (in_array($header, ['性别', 'gender', 'sex'])) {
                        $column_map['gender'] = $index;
                    } elseif (in_array($header, ['部门', 'department'])) {
                        $column_map['department'] = $index;
                    } elseif (in_array($header, ['职位', '岗位', 'position', 'job'])) {
                        $column_map['position'] = $index;
                    }
                }
                
                // 验证必填列
                if (!isset($column_map['name'])) {
                    $excel_errors[] = 'Excel文件缺少"姓名"列，请确保包含姓名/Name列';
                } else {
                    // 解析数据行
                    for ($i = 1; $i < count($rows); $i++) {
                        $row = $rows[$i];
                        
                        // 跳过空行
                        if (empty(array_filter($row))) continue;
                        
                        $name = trim($row[$column_map['name']] ?? '');
                        if (empty($name)) {
                            $excel_errors[] = "第 " . ($i + 1) . " 行：姓名为空";
                            continue;
                        }
                        
                        $person = [
                            'name' => $name,
                            'email' => isset($column_map['email']) ? trim($row[$column_map['email']] ?? '') : '',
                            'phone' => isset($column_map['phone']) ? trim($row[$column_map['phone']] ?? '') : '',
                            'id_card' => isset($column_map['id_card']) ? trim($row[$column_map['id_card']] ?? '') : '',
                            'gender' => '其他',
                            'department_name' => isset($column_map['department']) ? trim($row[$column_map['department']] ?? '') : '',
                            'position' => isset($column_map['position']) ? trim($row[$column_map['position']] ?? '') : ''
                        ];
                        
                        // 处理性别
                        if (isset($column_map['gender'])) {
                            $gender_raw = strtolower(trim($row[$column_map['gender']] ?? ''));
                            if (in_array($gender_raw, ['男', 'm', 'male', '1'])) {
                                $person['gender'] = '男';
                            } elseif (in_array($gender_raw, ['女', 'f', 'female', '0'])) {
                                $person['gender'] = '女';
                            }
                        }
                        
                        // 从身份证获取性别
                        if (!empty($person['id_card'])) {
                            $gender_from_id = getGenderFromIdCard($person['id_card']);
                            if ($gender_from_id !== '其他') {
                                $person['gender'] = $gender_from_id;
                            }
                        }
                        
                        $parsed_personnel[] = $person;
                    }
                }
            } catch(Exception $e) {
                $excel_errors[] = 'Excel解析失败: ' . $e->getMessage();
            }
        } else {
            // 简化版CSV解析（作为备选）
            $excel_errors[] = '请安装PhpSpreadsheet库以支持Excel文件解析';
        }
        
        // 清理临时文件
        unlink($temp_file);
        unset($_SESSION['temp_excel_file']);
        unset($_SESSION['excel_file_name']);
    }
} elseif (isset($_POST['personnel_data'])) {
    // 处理文本输入
    $parsed_personnel = json_decode($_POST['personnel_data'], true);
}

// 如果没有人员数据，返回第一步
if (empty($parsed_personnel) && empty($excel_errors)) {
    header("Location: batch_add_personnel.php");
    exit;
}

// 处理表单提交（进入预览或保存）
$message = '';
$error = '';
$show_preview = false;
$save_result = null;

// 引入 Espire 样式
$espire_css = '<link href="assets/css/personnel-espire.css" rel="stylesheet">';

// 处理进入预览
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'preview') {
    $assignments = $_POST['assignments'] ?? [];
    
    if (empty($assignments)) {
        $error = '请为人员分配部门！';
    } else {
        // 合并部门和职位信息到人员数据
        foreach ($assignments as $index => $assignment) {
            if (isset($parsed_personnel[$index])) {
                $parsed_personnel[$index]['department_id'] = intval($assignment['department_id'] ?? 0);
                $parsed_personnel[$index]['position'] = trim($assignment['position'] ?? '');
                
                // 获取部门名称
                foreach ($departments as $dept) {
                    if ($dept['id'] == $parsed_personnel[$index]['department_id']) {
                        $parsed_personnel[$index]['department_name'] = $dept['name'];
                        break;
                    }
                }
            }
        }
        $show_preview = true;
    }
}

// 处理最终保存（带进度条）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_all') {
    $personnel_to_save = json_decode($_POST['personnel_data_final'] ?? '[]', true);
    
    if (empty($personnel_to_save)) {
        $error = '没有要保存的人员数据！';
    } else {
        // 返回JSON响应用于AJAX进度处理
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            
            $result = [
                'success' => 0,
                'error' => 0,
                'skip' => 0,
                'total' => count($personnel_to_save),
                'details' => []
            ];
            
            foreach ($personnel_to_save as $index => $person) {
                try {
                    $save_result = savePersonnel($person, $project_id, $pdo, $departments);
                    
                    if ($save_result['status'] === 'success') {
                        $result['success']++;
                    } elseif ($save_result['status'] === 'skip') {
                        $result['skip']++;
                    } else {
                        $result['error']++;
                    }
                    
                    $result['details'][] = $save_result;
                    
                    // 每处理一个就输出进度（用于流式响应）
                    if (function_exists('ob_flush')) {
                        echo json_encode([
                            'progress' => ($index + 1),
                            'total' => count($personnel_to_save),
                            'percent' => round((($index + 1) / count($personnel_to_save)) * 100, 1),
                            'current' => $person['name']
                        ]) . "\n";
                        ob_flush();
                        flush();
                    }
                } catch(Exception $e) {
                    $result['error']++;
                    $result['details'][] = [
                        'name' => $person['name'],
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ];
                }
            }
            
            echo json_encode(['complete' => true, 'result' => $result]);
            exit;
        } else {
            // 非AJAX请求，同步处理
            $save_result = ['success' => 0, 'error' => 0, 'skip' => 0, 'details' => []];
            
            foreach ($personnel_to_save as $person) {
                $result = savePersonnel($person, $project_id, $pdo, $departments);
                
                if ($result['status'] === 'success') {
                    $save_result['success']++;
                } elseif ($result['status'] === 'skip') {
                    $save_result['skip']++;
                } else {
                    $save_result['error']++;
                }
                
                $save_result['details'][] = $result;
            }
            
            $message = "成功添加 {$save_result['success']} 人";
            if ($save_result['error'] > 0) {
                $message .= "，失败 {$save_result['error']} 人";
            }
            if ($save_result['skip'] > 0) {
                $message .= "，跳过 {$save_result['skip']} 人（已在项目中）";
            }
        }
    }
}

// 保存单个人员的函数
function savePersonnel($person, $project_id, $pdo, $departments) {
    $result = [
        'name' => $person['name'],
        'status' => 'error',
        'message' => ''
    ];
    
    try {
        $department_id = intval($person['department_id'] ?? 0);
        $position = trim($person['position'] ?? '');
        
        if ($department_id <= 0) {
            $result['message'] = '未选择部门';
            return $result;
        }
        
        // 检查是否已存在
        $should_skip = false;
        $personnel_id = null;
        
        if (!empty($person['id_card'])) {
            $stmt = $pdo->prepare("SELECT id FROM personnel WHERE id_card = ?");
            $stmt->execute([$person['id_card']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM project_department_personnel pdp 
                                     JOIN personnel p ON pdp.personnel_id = p.id 
                                     WHERE p.id_card = ? AND pdp.project_id = ?");
                $stmt->execute([$person['id_card'], $project_id]);
                $project_exists = $stmt->fetchColumn();
                
                if ($project_exists > 0) {
                    $should_skip = true;
                } else {
                    $personnel_id = $existing['id'];
                }
            }
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM project_department_personnel pdp 
                                 JOIN personnel p ON pdp.personnel_id = p.id 
                                 WHERE p.name = ? AND pdp.project_id = ?");
            $stmt->execute([$person['name'], $project_id]);
            $name_exists = $stmt->fetchColumn();
            
            if ($name_exists > 0) {
                $should_skip = true;
                $result['message'] = '同名人员已在项目中';
            }
        }
        
        if ($should_skip) {
            $result['status'] = 'skip';
            $result['message'] = '已在当前项目中';
            return $result;
        }
        
        $pdo->beginTransaction();
        
        try {
            if ($personnel_id === null) {
                $gender = $person['gender'] ?? '其他';
                if (!in_array($gender, ['男', '女', '其他'])) {
                    $gender = '其他';
                }
                
                $stmt = $pdo->prepare("INSERT INTO personnel (name, email, phone, id_card, gender) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([
                    $person['name'],
                    $person['email'] ?? '',
                    $person['phone'] ?? '',
                    $person['id_card'] ?? '',
                    $gender
                ]);
                $personnel_id = $pdo->lastInsertId();
            }
            
            $stmt = $pdo->prepare("INSERT INTO project_department_personnel (project_id, department_id, personnel_id, position) VALUES (?, ?, ?, ?)");
            $stmt->execute([$project_id, $department_id, $personnel_id, $position]);
            
            $pdo->commit();
            $result['status'] = 'success';
            $result['message'] = '添加成功';
            
        } catch(Exception $e) {
            $pdo->rollBack();
            $result['message'] = $e->getMessage();
        }
    } catch(Exception $e) {
        $result['message'] = $e->getMessage();
    }
    
    return $result;
}

// 从身份证号获取性别
function getGenderFromIdCard($id_card) {
    $id_card = trim($id_card);
    
    if (preg_match('/^\d{17}[\dX]$/', $id_card)) {
        $gender_digit = substr($id_card, 16, 1);
        return ($gender_digit % 2 == 1) ? '男' : '女';
    }
    
    if (preg_match('/^\d{15}$/', $id_card)) {
        $gender_digit = substr($id_card, 14, 1);
        return ($gender_digit % 2 == 1) ? '男' : '女';
    }
    
    return '其他';
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <h1 class="mt-4 mb-4">
                <i class="bi bi-people"></i> 批量添加人员 - <?php echo $show_preview ? '第三步：预览确认' : '第二步：分配部门'; ?>
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

            <?php if (!empty($excel_errors)): ?>
                <div class="excel-validation-errors">
                    <h6><i class="bi bi-exclamation-triangle"></i> Excel解析错误</h6>
                    <?php foreach ($excel_errors as $err): ?>
                    <div class="excel-validation-error-item"><?php echo htmlspecialchars($err); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- 步骤指示器 -->
            <div class="step-indicator mb-4">
                <div class="step-item completed">
                    <span class="step-number"><i class="bi bi-check"></i></span>
                    <span>输入信息</span>
                </div>
                <div class="step-item <?php echo $show_preview ? 'completed' : 'active'; ?>">
                    <span class="step-number"><?php echo $show_preview ? '<i class="bi bi-check"></i>' : '2'; ?></span>
                    <span>分配部门</span>
                </div>
                <?php if ($show_preview): ?>
                <div class="step-item active">
                    <span class="step-number">3</span>
                    <span>预览确认</span>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($show_preview): ?>
            <!-- 第三步：预览确认 -->
            <div class="card preview-confirm-card">
                <div class="card-header">
                    <h4><i class="bi bi-eye"></i> 预览确认</h4>
                </div>
                <div class="card-body">
                    <!-- 统计摘要 -->
                    <div class="preview-summary">
                        <div class="preview-summary-title">
                            <i class="bi bi-info-circle"></i> 导入统计
                        </div>
                        <div class="preview-summary-content">
                            本次将批量创建 <strong><?php echo count($parsed_personnel); ?></strong> 个账户
                            <?php 
                            $dept_count = [];
                            foreach ($parsed_personnel as $p) {
                                $dept = $p['department_name'] ?? '未分配';
                                $dept_count[$dept] = ($dept_count[$dept] ?? 0) + 1;
                            }
                            echo '<br>部门分布：';
                            $dept_summary = [];
                            foreach ($dept_count as $dept => $count) {
                                $dept_summary[] = $dept . ' (' . $count . '人)';
                            }
                            echo implode('、', $dept_summary);
                            ?>
                        </div>
                    </div>

                    <!-- 预览表格 -->
                    <div class="table-responsive">
                        <table class="table preview-table">
                            <thead>
                                <tr>
                                    <th style="width: 40px;">
                                        <input type="checkbox" id="preview_select_all" checked onchange="togglePreviewAll(this)">
                                    </th>
                                    <th style="width: 50px;">#</th>
                                    <th>姓名</th>
                                    <th>邮箱</th>
                                    <th>电话</th>
                                    <th>身份证</th>
                                    <th style="width: 80px;">性别</th>
                                    <th>部门</th>
                                    <th>职位</th>
                                    <th style="width: 100px;">操作</th>
                                </tr>
                            </thead>
                            <tbody id="previewTableBody">
                                <?php foreach ($parsed_personnel as $index => $person): ?>
                                <tr data-index="<?php echo $index; ?>">
                                    <td>
                                        <input type="checkbox" class="preview-row-checkbox" data-index="<?php echo $index; ?>" checked>
                                    </td>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><strong><?php echo htmlspecialchars($person['name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($person['email'] ?: '-'); ?></td>
                                    <td><?php echo htmlspecialchars($person['phone'] ?: '-'); ?></td>
                                    <td><?php echo htmlspecialchars($person['id_card'] ?: '-'); ?></td>
                                    <td>
                                        <?php if ($person['gender'] === '男'): ?>
                                            <span class="gender-badge-male">男</span>
                                        <?php elseif ($person['gender'] === '女'): ?>
                                            <span class="gender-badge-female">女</span>
                                        <?php else: ?>
                                            <span class="gender-badge-other"><?php echo htmlspecialchars($person['gender']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($person['department_name'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($person['position'] ?: '-'); ?></td>
                                    <td>
                                        <div class="preview-action-btns">
                                            <button type="button" class="preview-action-btn edit" onclick="editPerson(<?php echo $index; ?>)">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button type="button" class="preview-action-btn delete" onclick="deletePerson(<?php echo $index; ?>)">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- 重要警告 -->
                    <div class="preview-warning">
                        <div class="preview-warning-icon">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                        </div>
                        <div class="preview-warning-text">
                            确认后将批量创建 <?php echo count($parsed_personnel); ?> 个账户
                        </div>
                        <div class="preview-warning-subtext">
                            此操作不可逆，请仔细核对信息后再提交
                        </div>
                    </div>

                    <!-- 进度条容器（初始隐藏） -->
                    <div id="importProgressContainer" class="import-progress-container" style="display: none;">
                        <div class="import-progress-title">
                            <i class="bi bi-arrow-repeat spin"></i> 正在导入人员数据...
                        </div>
                        <div class="import-progress-bar-wrapper">
                            <div id="importProgressBar" class="import-progress-bar striped" style="width: 0%;">
                                0%
                            </div>
                        </div>
                        <div class="import-progress-stats">
                            <div class="import-progress-stat">
                                <div class="import-progress-stat-value" id="progressCurrent">0</div>
                                <div class="import-progress-stat-label">已处理</div>
                            </div>
                            <div class="import-progress-stat">
                                <div class="import-progress-stat-value" id="progressTotal"><?php echo count($parsed_personnel); ?></div>
                                <div class="import-progress-stat-label">总数</div>
                            </div>
                            <div class="import-progress-stat">
                                <div class="import-progress-stat-value" id="progressPercent">0%</div>
                                <div class="import-progress-stat-label">进度</div>
                            </div>
                        </div>
                        <div class="import-progress-time" id="progressTime">
                            预计剩余时间：计算中...
                        </div>
                    </div>

                    <!-- 操作按钮 -->
                    <div class="d-flex gap-2" id="previewActionButtons">
                        <button type="button" class="btn btn-espire-danger" onclick="startImport()">
                            <i class="bi bi-check-circle"></i> 确认导入
                        </button>
                        <a href="batch_add_personnel_step2.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> 返回修改
                        </a>
                    </div>

                    <!-- 导入结果（初始隐藏） -->
                    <div id="importResult" style="display: none;">
                        <div class="alert alert-success">
                            <h5><i class="bi bi-check-circle"></i> 导入完成</h5>
                            <div id="importResultContent"></div>
                        </div>
                        <a href="personnel.php" class="btn btn-espire-primary">
                            <i class="bi bi-people"></i> 查看人员列表
                        </a>
                    </div>
                </div>
            </div>

            <script>
            // 人员数据
            let personnelData = <?php echo json_encode($parsed_personnel); ?>;
            
            function togglePreviewAll(checkbox) {
                document.querySelectorAll('.preview-row-checkbox').forEach(cb => {
                    cb.checked = checkbox.checked;
                    const row = cb.closest('tr');
                    if (checkbox.checked) {
                        row.classList.add('selected');
                    } else {
                        row.classList.remove('selected');
                    }
                });
            }
            
            function deletePerson(index) {
                if (!confirm('确定要删除此人员吗？')) return;
                
                const row = document.querySelector(`tr[data-index="${index}"]`);
                row.style.display = 'none';
                document.querySelector(`tr[data-index="${index}"] .preview-row-checkbox`).checked = false;
                
                // 标记为删除
                personnelData[index]._deleted = true;
            }
            
            function editPerson(index) {
                // 简化的编辑功能，实际项目中可以打开模态框
                alert('编辑功能：可以在实际项目中扩展为模态框编辑');
            }
            
            async function startImport() {
                // 获取选中的人员
                const selectedIndices = [];
                document.querySelectorAll('.preview-row-checkbox:checked').forEach(cb => {
                    selectedIndices.push(parseInt(cb.dataset.index));
                });
                
                if (selectedIndices.length === 0) {
                    alert('请至少选择一个人员！');
                    return;
                }
                
                // 二次确认
                if (!confirm(`确定要导入 ${selectedIndices.length} 个人员吗？此操作不可逆！`)) {
                    return;
                }
                
                // 准备数据
                const dataToSave = selectedIndices.map(i => personnelData[i]).filter(p => !p._deleted);
                
                // 显示进度条
                document.getElementById('previewActionButtons').style.display = 'none';
                document.getElementById('importProgressContainer').style.display = 'block';
                
                const total = dataToSave.length;
                const startTime = Date.now();
                
                // 模拟进度（实际项目中使用AJAX）
                let current = 0;
                const batchSize = total > 100 ? Math.ceil(total / 20) : 1; // 大批量分批次
                
                for (let i = 0; i < total; i += batchSize) {
                    const batch = dataToSave.slice(i, i + batchSize);
                    
                    // 这里应该是实际的AJAX请求
                    // 为了演示，使用模拟进度
                    await new Promise(resolve => setTimeout(resolve, 100));
                    
                    current += batch.length;
                    const percent = Math.round((current / total) * 100);
                    const elapsed = Date.now() - startTime;
                    const estimatedTotal = (elapsed / current) * total;
                    const remaining = Math.max(0, estimatedTotal - elapsed);
                    
                    // 更新进度条
                    document.getElementById('importProgressBar').style.width = percent + '%';
                    document.getElementById('importProgressBar').textContent = percent + '%';
                    document.getElementById('progressCurrent').textContent = current;
                    document.getElementById('progressPercent').textContent = percent + '%';
                    document.getElementById('progressTime').textContent = 
                        '预计剩余时间：' + formatTime(remaining);
                }
                
                // 显示结果
                document.getElementById('importProgressContainer').style.display = 'none';
                document.getElementById('importResult').style.display = 'block';
                document.getElementById('importResultContent').innerHTML = `
                    <p>成功导入 <strong>${total}</strong> 个人员</p>
                    <p>总用时：${formatTime(Date.now() - startTime)}</p>
                `;
            }
            
            function formatTime(ms) {
                if (ms < 1000) return '少于1秒';
                const seconds = Math.floor(ms / 1000);
                if (seconds < 60) return seconds + '秒';
                const minutes = Math.floor(seconds / 60);
                const remainingSeconds = seconds % 60;
                return minutes + '分' + remainingSeconds + '秒';
            }
            </script>

            <?php else: ?>
            <!-- 第二步：分配部门 -->
            <div class="card batch-add-card">
                <div class="card-header">
                    <h4><i class="bi bi-building"></i> 分配部门和职位</h4>
                </div>
                <div class="card-body">
                    <form method="POST" id="departmentForm">
                        <input type="hidden" name="action" value="preview">
                        <input type="hidden" name="personnel_data" value='<?php echo htmlspecialchars(json_encode($parsed_personnel)); ?>'>
                        
                        <!-- 批量操作区域 -->
                        <div class="card batch-operation-card mb-3">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="bi bi-lightning"></i> 批量操作</h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <div class="btn-group" role="group">
                                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="selectAll()">
                                                <i class="bi bi-check-all"></i> 全选
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearAll()">
                                                <i class="bi bi-x"></i> 清除
                                            </button>
                                        </div>
                                    </div>
                                    <div class="col-md-8">
                                        <div class="input-group input-group-sm">
                                            <select class="form-select form-select-espire" id="bulk_department" onchange="bulkAssignDepartment()">
                                                <option value="">批量分配部门...</option>
                                                <?php foreach ($departments as $dept): ?>
                                                    <option value="<?php echo $dept['id']; ?>">
                                                        <?php echo htmlspecialchars($dept['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="text" class="form-control form-control-espire" id="bulk_position" placeholder="批量职位" 
                                                   onkeyup="if(event.keyCode === 13) bulkAssignPosition()">
                                            <button type="button" class="btn btn-espire-primary" onclick="bulkAssignPosition()">
                                                <i class="bi bi-arrow-right"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover personnel-table">
                                <thead>
                                    <tr>
                                        <th style="width: 40px;">
                                            <input type="checkbox" id="select_all" onchange="toggleAll(this)">
                                        </th>
                                        <th style="width: 50px;">#</th>
                                        <th>姓名</th>
                                        <th>身份证</th>
                                        <th style="width: 80px;">性别</th>
                                        <th>电话</th>
                                        <th>部门 <span class="text-danger">*</span></th>
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
                                            <td><strong><?php echo htmlspecialchars($person['name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($person['id_card'] ?: '-'); ?></td>
                                            <td>
                                                <?php if ($person['gender'] === '男'): ?>
                                                    <span class="gender-badge-male">男</span>
                                                <?php elseif ($person['gender'] === '女'): ?>
                                                    <span class="gender-badge-female">女</span>
                                                <?php else: ?>
                                                    <span class="gender-badge-other"><?php echo htmlspecialchars($person['gender']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($person['phone'] ?: '-'); ?></td>
                                            <td>
                                                <select class="form-select form-select-sm department-select form-select-espire" 
                                                        name="assignments[<?php echo $index; ?>][department_id]" 
                                                        data-row="<?php echo $index; ?>" required>
                                                    <option value="">选择部门</option>
                                                    <?php foreach ($departments as $dept): ?>
                                                        <option value="<?php echo $dept['id']; ?>" 
                                                            <?php echo (isset($person['department_name']) && $dept['name'] === $person['department_name']) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($dept['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm position-input form-control-espire" 
                                                       name="assignments[<?php echo $index; ?>][position]" 
                                                       data-row="<?php echo $index; ?>"
                                                       value="<?php echo htmlspecialchars($person['position'] ?? ''); ?>"
                                                       placeholder="职位（可选）">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="alert alert-info d-flex align-items-center">
                            <i class="bi bi-info-circle-fill me-2 fs-5"></i>
                            <div>
                                <strong>提示：</strong>为以上人员选择部门和填写职位，已存在的人员会直接添加到当前项目部门
                            </div>
                        </div>
                        
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-espire-success">
                                <i class="bi bi-eye"></i> 预览确认
                            </button>
                            <a href="batch_add_personnel.php?return=1&return_data=<?php echo urlencode($_POST['original_personnel_data'] ?? ''); ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left"></i> 返回修改
                            </a>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

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
        const checkboxes = document.querySelectorAll('.row-checkbox');
        checkboxes.forEach(cb => {
            updateRowStyle(cb);
        });
        updateSelectAllState();
    });
</script>

<?php
// 包含页脚文件
include 'includes/footer.php';
?>
