<?php
session_start();
require_once '../config/database.php';
require_once 'page_functions.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();

$message = '';
$error = '';

// 获取项目筛选参数
$filters = [
    'project_id' => isset($_GET['project_id']) ? intval($_GET['project_id']) : 0
];

// 获取所有项目用于选择
$projects_query = "SELECT id, name, code FROM projects ORDER BY name ASC";
$projects_stmt = $db->prepare($projects_query);
$projects_stmt->execute();
$all_projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);

// 只有选择了项目才查询报餐数据
$meal_reports_by_date = [];
if ($filters['project_id']) {
    // 处理操作
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'update_status') {
            $id = intval($_POST['id']);
            $status = $_POST['status'];
            
            if (in_array($status, ['pending', 'confirmed', 'cancelled'])) {
                $query = "UPDATE meal_reports SET status = :status WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':id', $id);
                
                if ($stmt->execute()) {
                    $message = '报餐状态更新成功！';
                } else {
                    $error = '更新失败，请重试！';
                }
            }
        } elseif ($action === 'delete') {
            // 单个删除操作
            $id = intval($_POST['id']);
            
            try {
                $query = "DELETE FROM meal_reports WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $id);
                
                if ($stmt->execute()) {
                    $message = '报餐记录删除成功！';
                } else {
                    $error = '删除失败，请重试！';
                }
            } catch (Exception $e) {
                $error = '删除失败：' . $e->getMessage();
            }
        } elseif ($action === 'batch_delete') {
            // 批量删除操作
            $ids = $_POST['ids'] ?? [];
            
            if (!empty($ids) && is_array($ids)) {
                try {
                    $db->beginTransaction();
                    $deleted_count = 0;
                    
                    foreach ($ids as $id) {
                        $id = intval($id);
                        if ($id > 0) {
                            $query = "DELETE FROM meal_reports WHERE id = :id";
                            $stmt = $db->prepare($query);
                            $stmt->bindParam(':id', $id);
                            
                            if ($stmt->execute()) {
                                $deleted_count++;
                            }
                        }
                    }
                    
                    $db->commit();
                    $message = "成功删除 {$deleted_count} 条报餐记录！";
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = '批量删除失败：' . $e->getMessage();
                }
            } else {
                $error = '请选择要删除的记录！';
            }
        } elseif ($action === 'confirm') {
            // 单个确认操作
            $id = intval($_POST['id']);
            
            if ($id > 0) {
                try {
                    $query = "UPDATE meal_reports SET status = 'confirmed' WHERE id = :id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':id', $id);
                    
                    if ($stmt->execute()) {
                        $message = '报餐记录确认成功！';
                    } else {
                        $error = '确认失败，请重试！';
                    }
                } catch (Exception $e) {
                    $error = '确认失败：' . $e->getMessage();
                }
            } else {
                $error = '无效的记录ID！';
            }
        } elseif ($action === 'batch_confirm') {
            // 批量确认操作
            $ids = $_POST['ids'] ?? [];
            
            // 如果$ids是字符串，则将其转换为数组
            if (is_string($ids)) {
                $ids = explode(',', $ids);
            }
            
            if (!empty($ids) && is_array($ids)) {
                try {
                    $db->beginTransaction();
                    $confirmed_count = 0;
                    
                    foreach ($ids as $id) {
                        $id = intval($id);
                        if ($id > 0) {
                            $query = "UPDATE meal_reports SET status = 'confirmed' WHERE id = :id";
                            $stmt = $db->prepare($query);
                            $stmt->bindParam(':id', $id);
                            
                            if ($stmt->execute()) {
                                $confirmed_count++;
                            }
                        }
                    }
                    
                    $db->commit();
                    $message = "成功确认 {$confirmed_count} 条报餐记录！";
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = '批量确认失败：' . $e->getMessage();
                }
            } else {
                $error = '请选择要确认的记录！';
            }
        } elseif ($action === 'cancel') {
            // 单个取消操作
            $id = intval($_POST['id']);
            
            if ($id > 0) {
                try {
                    $query = "UPDATE meal_reports SET status = 'cancelled' WHERE id = :id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':id', $id);
                    
                    if ($stmt->execute()) {
                        $message = '报餐记录取消成功！';
                    } else {
                        $error = '取消失败，请重试！';
                    }
                } catch (Exception $e) {
                    $error = '取消失败：' . $e->getMessage();
                }
            } else {
                $error = '无效的记录ID！';
            }
        } elseif ($action === 'batch_cancel') {
            // 批量取消操作
            $ids = $_POST['ids'] ?? [];
            
            // 如果$ids是字符串，则将其转换为数组
            if (is_string($ids)) {
                $ids = explode(',', $ids);
            }
            
            if (!empty($ids) && is_array($ids)) {
                try {
                    $db->beginTransaction();
                    $cancelled_count = 0;
                    
                    foreach ($ids as $id) {
                        $id = intval($id);
                        if ($id > 0) {
                            $query = "UPDATE meal_reports SET status = 'cancelled' WHERE id = :id";
                            $stmt = $db->prepare($query);
                            $stmt->bindParam(':id', $id);
                            
                            if ($stmt->execute()) {
                                $cancelled_count++;
                            }
                        }
                    }
                    
                    $db->commit();
                    $message = "成功取消 {$cancelled_count} 条报餐记录！";
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = '批量取消失败：' . $e->getMessage();
                }
            } else {
                $error = '请选择要取消的记录！';
            }
        }
    }

    // 获取筛选参数
    $status_filter = $_GET['status'] ?? '';
    $meal_type_filter = $_GET['meal_type'] ?? '';
    $date_filter = $_GET['date'] ?? '';

    // 构建查询条件
    $where_conditions = [];
    $params = [];

    // 添加项目筛选条件
    $where_conditions[] = "mr.project_id = :project_id";
    $params[':project_id'] = $filters['project_id'];

    if ($status_filter) {
        $where_conditions[] = "mr.status = :status";
        $params[':status'] = $status_filter;
    }

    if ($meal_type_filter) {
        $where_conditions[] = "mr.meal_type = :meal_type";
        $params[':meal_type'] = $meal_type_filter;
    }

    if ($date_filter) {
        $where_conditions[] = "mr.meal_date = :date";
        $params[':date'] = $date_filter;
    }

    $where_clause = $where_conditions ? "WHERE " . implode(' AND ', $where_conditions) : "";

    // 获取报餐列表
    $query = "SELECT mr.*, p.name as project_name, p.code as project_code, 
                     pr.name as personnel_name, pu.username as reporter_name
              FROM meal_reports mr
              JOIN projects p ON mr.project_id = p.id
              JOIN personnel pr ON mr.personnel_id = pr.id
              JOIN project_users pu ON mr.reported_by = pu.id
              $where_clause
              ORDER BY mr.meal_date DESC, mr.created_at DESC";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $meal_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 按日期分组
    foreach ($meal_reports as $report) {
        $date = $report['meal_date'];
        if (!isset($meal_reports_by_date[$date])) {
            $meal_reports_by_date[$date] = [];
        }
        $meal_reports_by_date[$date][] = $report;
    }
}

// 状态映射
$status_map = [
    'pending' => ['label' => '待确认', 'class' => 'warning'],
    'confirmed' => ['label' => '已确认', 'class' => 'success'],
    'cancelled' => ['label' => '已取消', 'class' => 'danger']
];

// 餐类型映射
$meal_type_map = [
    '早餐' => '早餐',
    '午餐' => '午餐',
    '晚餐' => '晚餐'
];

// 处理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}
?>

// 设置页面标题
$page_title = '报餐管理';

<?php include 'includes/header.php'; ?>

<!-- 引入优化样式 -->
<link href="assets/css/meal-reports-optimized.css" rel="stylesheet">

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <!-- 项目选择区域 -->
            <div class="project-selector">
                <h4>
                    <i class="bi bi-building"></i>
                    项目选择
                </h4>
                <form method="GET" id="projectForm">
                    <!-- 保持其他筛选条件 -->
                    <?php if (isset($_GET['status'])): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($_GET['status']); ?>"><?php endif; ?>
                    <?php if (isset($_GET['date'])): ?><input type="hidden" name="date" value="<?php echo htmlspecialchars($_GET['date']); ?>"><?php endif; ?>
                    
                    <div class="row align-items-end">
                        <div class="col-md-8">
                            <label for="project_id" class="form-label fw-semibold">请选择要查看的项目</label>
                            <select class="form-select" id="project_id" name="project_id" onchange="this.form.submit()">
                                <option value="">请选择项目...</option>
                                <?php foreach ($all_projects as $project): ?>
                                    <option value="<?php echo $project['id']; ?>" 
                                            <?php echo $filters['project_id'] == $project['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($project['name']); ?>
                                        <?php if ($project['code']): ?>
                                            (<?php echo htmlspecialchars($project['code']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <?php if ($filters['project_id']): ?>
                                <div class="project-status selected">
                                    <i class="bi bi-check-circle-fill"></i>
                                    已选择项目
                                </div>
                            <?php else: ?>
                                <div class="project-status not-selected">
                                    <i class="bi bi-exclamation-circle-fill"></i>
                                    请先选择项目
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill"></i> <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($filters['project_id']): ?>
                <!-- 报餐管理区域 -->
                <div class="reports-container">
                    <div class="reports-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-table me-2"></i>报餐记录列表
                                <span class="badge bg-light text-dark ms-2"><?php echo count($meal_reports); ?></span>
                            </h5>
                        </div>
                    </div>
                    <div class="reports-body">
                        <!-- 筛选表单 -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="bi bi-funnel"></i> 筛选条件</h5>
                            </div>
                            <div class="card-body">
                                <form method="GET" class="row g-3">
                                    <input type="hidden" name="project_id" value="<?php echo htmlspecialchars($filters['project_id']); ?>">
                                    <div class="col-md-2">
                                        <label for="status" class="form-label">状态</label>
                                        <select class="form-select" id="status" name="status">
                                            <option value="">所有状态</option>
                                            <option value="pending" <?php echo (isset($_GET['status']) && $_GET['status'] == 'pending') ? 'selected' : ''; ?>>待确认</option>
                                            <option value="confirmed" <?php echo (isset($_GET['status']) && $_GET['status'] == 'confirmed') ? 'selected' : ''; ?>>已确认</option>
                                            <option value="cancelled" <?php echo (isset($_GET['status']) && $_GET['status'] == 'cancelled') ? 'selected' : ''; ?>>已取消</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label for="meal_type" class="form-label">餐类型</label>
                                        <select class="form-select" id="meal_type" name="meal_type">
                                            <option value="">所有餐类型</option>
                                            <option value="早餐" <?php echo (isset($_GET['meal_type']) && $_GET['meal_type'] == '早餐') ? 'selected' : ''; ?>>早餐</option>
                                            <option value="午餐" <?php echo (isset($_GET['meal_type']) && $_GET['meal_type'] == '午餐') ? 'selected' : ''; ?>>午餐</option>
                                            <option value="晚餐" <?php echo (isset($_GET['meal_type']) && $_GET['meal_type'] == '晚餐') ? 'selected' : ''; ?>>晚餐</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label for="date" class="form-label">日期</label>
                                        <input type="date" class="form-control" id="date" name="date" 
                                               value="<?php echo htmlspecialchars(isset($_GET['date']) ? $_GET['date'] : ''); ?>">
                                    </div>
                                    <div class="col-md-3 d-flex align-items-end">
                                        <button type="submit" class="btn btn-primary me-2">
                                            <i class="bi bi-search"></i> 筛选
                                        </button>
                                        <a href="?project_id=<?php echo $filters['project_id']; ?>" class="btn btn-secondary">
                                            <i class="bi bi-arrow-clockwise"></i> 重置
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- 报餐列表 -->
                        <?php if (empty($meal_reports_by_date)): ?>
                            <div class="empty-state">
                                <i class="bi bi-cup-hot display-1"></i>
                                <h5 class="mt-3">该项目暂无报餐记录</h5>
                                <p class="text-muted mb-4">当前筛选条件下没有找到报餐记录</p>
                            </div>
                        <?php else: ?>
                            <?php 
                            $total_count = 0;
                            foreach ($meal_reports_by_date as $date => $reports): 
                                $total_count += count($reports);
                            ?>
                                <div class="card mb-4">
                                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                                        <h5 class="mb-0">
                                            <i class="bi bi-calendar-event"></i> 
                                            <?php echo date('Y年m月d日', strtotime($date)); ?>
                                            <span class="badge bg-light text-dark ms-2"><?php echo count($reports); ?> 条记录</span>
                                        </h5>
                                    </div>
                                    <div class="card-body p-0">
                                        <div class="table-responsive">
                                            <table class="table table-hover mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th width="30">
                                                            <input type="checkbox" class="select-by-date" data-date="<?php echo $date; ?>" onchange="toggleDate(this, '<?php echo $date; ?>')">
                                                        </th>
                                                        <th>餐类型</th>
                                                        <th>人员</th>
                                                        <th>特殊要求</th>
                                                        <th>状态</th>
                                                        <th>报告人</th>
                                                        <th>报告时间</th>
                                                        <th>操作</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($reports as $report): ?>
                                                        <tr>
                                                            <td>
                                                                <input type="checkbox" class="record-checkbox" name="ids[]" value="<?php echo $report['id']; ?>" data-date="<?php echo $date; ?>">
                                                            </td>
                                                            <td><?php echo $report['meal_type']; ?></td>
                                                            <td><?php echo htmlspecialchars($report['personnel_name']); ?></td>
                                                            <td>
                                                                <?php if ($report['special_requirements']): ?>
                                                                    <small class="text-muted" title="<?php echo htmlspecialchars($report['special_requirements']); ?>">
                                                                        <?php echo mb_strlen($report['special_requirements']) > 20 ? mb_substr($report['special_requirements'], 0, 20) . '...' : $report['special_requirements']; ?>
                                                                    </small>
                                                                <?php else: ?>
                                                                    <span class="text-muted">-</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php
                                                                $status_badge = [
                                                                    'pending' => ['label' => '待确认', 'class' => 'warning'],
                                                                    'confirmed' => ['label' => '已确认', 'class' => 'success'],
                                                                    'cancelled' => ['label' => '已取消', 'class' => 'secondary']
                                                                ];
                                                                $status_info = $status_badge[$report['status']] ?? ['label' => '未知', 'class' => 'secondary'];
                                                                ?>
                                                                <span class="badge bg-<?php echo $status_info['class']; ?>">
                                                                    <?php echo $status_info['label']; ?>
                                                                </span>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($report['reporter_name'] ?? '未知'); ?></td>
                                                            <td><?php echo date('Y-m-d H:i', strtotime($report['created_at'])); ?></td>
                                                            <td>
                                                                <div class="btn-group btn-group-sm" role="group">
                                                                    <button type="button" class="btn btn-outline-primary" onclick="viewMealDetails(<?php echo $report['id']; ?>)">
                                                                        <i class="bi bi-eye"></i>
                                                                    </button>
                                                                    <?php if ($report['status'] == 'pending'): ?>
                                                                        <button type="button" class="btn btn-outline-success" onclick="confirmMeal(<?php echo $report['id']; ?>)">
                                                                            <i class="bi bi-check-circle"></i>
                                                                        </button>
                                                                    <?php endif; ?>
                                                                    <?php if ($report['status'] != 'cancelled'): ?>
                                                                        <button type="button" class="btn btn-outline-danger" onclick="cancelMeal(<?php echo $report['id']; ?>)">
                                                                            <i class="bi bi-x-circle"></i>
                                                                        </button>
                                                                    <?php endif; ?>
                                                                    <button type="button" class="btn btn-outline-dark" onclick="deleteMeal(<?php echo $report['id']; ?>)">
                                                                        <i class="bi bi-trash"></i>
                                                                    </button>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        
                                        <!-- 每日批量操作 -->
                                        <div class="card-footer bg-light">
                                            <button type="button" class="btn btn-success btn-sm" onclick="batchConfirmByDate('<?php echo $date; ?>')" disabled id="batchConfirmBtn_<?php echo $date; ?>">
                                                <i class="bi bi-check-circle"></i> 批量确认
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="batchCancelByDate('<?php echo $date; ?>')" disabled id="batchCancelBtn_<?php echo $date; ?>">
                                                <i class="bi bi-x-circle"></i> 批量取消
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <!-- 总计和全局批量操作 -->
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong>总计:</strong> <?php echo $total_count; ?> 条记录
                                        </div>
                                        <div>
                                            <button type="button" class="btn btn-success btn-sm" onclick="batchConfirm()" disabled id="batchConfirmBtn">
                                                <i class="bi bi-check-circle"></i> 全部批量确认
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm" onclick="batchCancel()" disabled id="batchCancelBtn">
                                                <i class="bi bi-x-circle"></i> 全部批量取消
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// 按日期全选/取消全选
function toggleDate(source, date) {
    const checkboxes = document.querySelectorAll(`.record-checkbox[data-date="${date}"]`);
    checkboxes.forEach(checkbox => {
        checkbox.checked = source.checked;
    });
    updateDateButtons(date);
    updateBatchButtons();
}

// 更新每日批量操作按钮状态
function updateDateButtons(date) {
    const checkedBoxes = document.querySelectorAll(`.record-checkbox[data-date="${date}"]:checked`);
    const batchConfirmBtn = document.getElementById(`batchConfirmBtn_${date}`);
    const batchCancelBtn = document.getElementById(`batchCancelBtn_${date}`);
    
    if (checkedBoxes.length > 0) {
        batchConfirmBtn.disabled = false;
        batchCancelBtn.disabled = false;
    } else {
        batchConfirmBtn.disabled = true;
        batchCancelBtn.disabled = true;
    }
}

// 全选/取消全选
function toggleAll(source) {
    const checkboxes = document.querySelectorAll('.record-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = source.checked;
    });
    
    // 更新所有日期的按钮状态
    const dates = new Set();
    checkboxes.forEach(checkbox => {
        dates.add(checkbox.dataset.date);
    });
    dates.forEach(date => {
        updateDateButtons(date);
    });
    
    updateBatchButtons();
}

// 更新批量操作按钮状态
function updateBatchButtons() {
    const checkedBoxes = document.querySelectorAll('.record-checkbox:checked');
    const batchConfirmBtn = document.getElementById('batchConfirmBtn');
    const batchCancelBtn = document.getElementById('batchCancelBtn');
    
    if (checkedBoxes.length > 0) {
        batchConfirmBtn.disabled = false;
        batchCancelBtn.disabled = false;
    } else {
        batchConfirmBtn.disabled = true;
        batchCancelBtn.disabled = true;
    }
}

// 监听复选框变化
document.addEventListener('change', function(e) {
    if (e.target.classList.contains('record-checkbox')) {
        updateDateButtons(e.target.dataset.date);
        updateBatchButtons();
    }
});

// 按日期批量确认
function batchConfirmByDate(date) {
    const checkedBoxes = document.querySelectorAll(`.record-checkbox[data-date="${date}"]:checked`);
    if (checkedBoxes.length === 0) {
        alert('请先选择要操作的记录');
        return;
    }
    
    if (confirm(`确定要确认选中的 ${checkedBoxes.length} 条报餐记录吗？`)) {
        const ids = Array.from(checkedBoxes).map(cb => cb.value);
        // 使用AJAX提交请求
        const formData = new FormData();
        formData.append('action', 'batch_confirm');
        formData.append('ids', ids.join(','));
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            // 操作完成后重新加载页面以显示更新后的状态
            window.location.reload();
        })
        .catch(error => {
            alert('操作失败：' + error.message);
        });
    }
}

// 按日期批量取消
function batchCancelByDate(date) {
    const checkedBoxes = document.querySelectorAll(`.record-checkbox[data-date="${date}"]:checked`);
    if (checkedBoxes.length === 0) {
        alert('请先选择要操作的记录');
        return;
    }
    
    if (confirm(`确定要取消选中的 ${checkedBoxes.length} 条报餐记录吗？`)) {
        if (confirm(`再次确认：您确定要取消选中的 ${checkedBoxes.length} 条报餐记录吗？此操作不可撤销！`)) {
            const ids = Array.from(checkedBoxes).map(cb => cb.value);
            // 使用AJAX提交请求
            const formData = new FormData();
            formData.append('action', 'batch_cancel');
            formData.append('ids', ids.join(','));
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                // 操作完成后重新加载页面以显示更新后的状态
                window.location.reload();
            })
            .catch(error => {
                alert('操作失败：' + error.message);
            });
        }
    }
}

// 确认单个报餐
function confirmMeal(id) {
    console.log('confirmMeal called with id:', id);
    if (confirm('确定要确认此报餐记录吗？')) {
        // 使用AJAX提交请求
        const formData = new FormData();
        formData.append('action', 'confirm');
        formData.append('id', id);
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            // 操作完成后重新加载页面以显示更新后的状态
            window.location.reload();
        })
        .catch(error => {
            alert('操作失败：' + error.message);
        });
    }
}

// 取消单个报餐
function cancelMeal(id) {
    console.log('cancelMeal called with id:', id);
    if (confirm('确定要取消此报餐记录吗？')) {
        if (confirm('再次确认：您确定要取消此报餐记录吗？此操作不可撤销！')) {
            // 使用AJAX提交请求
            const formData = new FormData();
            formData.append('action', 'cancel');
            formData.append('id', id);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                // 操作完成后重新加载页面以显示更新后的状态
                window.location.reload();
            })
            .catch(error => {
                alert('操作失败：' + error.message);
            });
        }
    }
}

// 批量确认
function batchConfirm() {
    console.log('batchConfirm called');
    const checkedBoxes = document.querySelectorAll('.record-checkbox:checked');
    if (checkedBoxes.length === 0) {
        alert('请先选择要操作的记录');
        return;
    }
    
    if (confirm(`确定要确认选中的 ${checkedBoxes.length} 条报餐记录吗？`)) {
        const ids = Array.from(checkedBoxes).map(cb => cb.value);
        // 使用AJAX提交请求
        const formData = new FormData();
        formData.append('action', 'batch_confirm');
        formData.append('ids', ids.join(','));
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(data => {
            // 操作完成后重新加载页面以显示更新后的状态
            window.location.reload();
        })
        .catch(error => {
            alert('操作失败：' + error.message);
        });
    }
}

// 批量取消
function batchCancel() {
    console.log('batchCancel called');
    const checkedBoxes = document.querySelectorAll('.record-checkbox:checked');
    if (checkedBoxes.length === 0) {
        alert('请先选择要操作的记录');
        return;
    }
    
    if (confirm(`确定要取消选中的 ${checkedBoxes.length} 条报餐记录吗？`)) {
        if (confirm(`再次确认：您确定要取消选中的 ${checkedBoxes.length} 条报餐记录吗？此操作不可撤销！`)) {
            const ids = Array.from(checkedBoxes).map(cb => cb.value);
            // 使用AJAX提交请求
            const formData = new FormData();
            formData.append('action', 'batch_cancel');
            formData.append('ids', ids.join(','));
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                // 操作完成后重新加载页面以显示更新后的状态
                window.location.reload();
            })
            .catch(error => {
                alert('操作失败：' + error.message);
            });
        }
    }
}

// 删除单个报餐记录
function deleteMeal(id) {
    console.log('deleteMeal called with id:', id);
    if (confirm('确定要删除此报餐记录吗？')) {
        if (confirm('再次确认：您确定要删除此报餐记录吗？此操作不可撤销！')) {
            // 使用AJAX提交请求
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                // 操作完成后重新加载页面以显示更新后的状态
                window.location.reload();
            })
            .catch(error => {
                alert('操作失败：' + error.message);
            });
        }
    }
}

// 查看详情
function viewMealDetails(id) {
    console.log('viewMealDetails called with id:', id);
    // 显示加载状态
    showMealDetailsModal(`
        <div class="text-center">
            <div class="spinner-border" role="status">
                <span class="visually-hidden">加载中...</span>
            </div>
            <p class="mt-2">正在加载报餐详情...</p>
        </div>
    `);
    
    // 获取报餐详情
    fetch(`api/meal/get_meal_report_details.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayMealDetails(data);
            } else {
                showMealDetailsModal(`
                    <div class="text-center py-5">
                        <i class="bi bi-exclamation-triangle text-danger" style="font-size: 3rem;"></i>
                        <h5 class="text-danger mt-3">加载失败</h5>
                        <p class="text-muted">${data.message || '获取数据失败'}</p>
                        <button class="btn btn-primary" onclick="viewMealDetails(${id})">
                            <i class="bi bi-arrow-clockwise"></i> 重试
                        </button>
                    </div>
                `);
            }
        })
        .catch(error => {
            showMealDetailsModal(`
                <div class="text-center py-5">
                    <i class="bi bi-exclamation-triangle text-danger" style="font-size: 3rem;"></i>
                    <h5 class="text-danger mt-3">加载失败</h5>
                    <p class="text-muted">${error.message || '网络错误，请重试'}</p>
                    <button class="btn btn-primary" onclick="viewMealDetails(${id})">
                        <i class="bi bi-arrow-clockwise"></i> 重试
                    </button>
                </div>
            `);
        });
}

// 显示详情模态框
function showMealDetailsModal(content) {
    // 如果模态框不存在则创建
    let modalElement = document.getElementById('mealDetailsModal');
    if (!modalElement) {
        const modalHtml = `
            <div class="modal fade" id="mealDetailsModal" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">报餐详情</h5>
                            <button type="button" class="btn-close" onclick="hideMealDetailsModal()"></button>
                        </div>
                        <div class="modal-body" id="mealDetailsContent">
                            ${content}
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="hideMealDetailsModal()">关闭</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        modalElement = document.getElementById('mealDetailsModal');
    } else {
        document.getElementById('mealDetailsContent').innerHTML = content;
    }
    
    // 显示模态框
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        // 清理可能存在的旧背景遮罩层
        const existingBackdrops = document.querySelectorAll('.modal-backdrop');
        existingBackdrops.forEach(backdrop => backdrop.remove());
        
        const modalInstance = new bootstrap.Modal(modalElement);
        modalInstance.show();
        
        // 监听模态框隐藏事件，确保背景遮罩层被移除
        modalElement.addEventListener('hidden.bs.modal', function () {
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(backdrop => backdrop.remove());
            document.body.classList.remove('modal-open');
            // 确保恢复页面滚动
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        });
    } else {
        // 降级处理
        // 清理可能存在的旧背景遮罩层
        const existingBackdrop = document.getElementById('mealDetailsModalBackdrop');
        if (existingBackdrop) {
            existingBackdrop.remove();
        }
        
        modalElement.classList.add('show');
        modalElement.style.display = 'block';
        document.body.classList.add('modal-open');
        // 禁用页面滚动
        document.body.style.overflow = 'hidden';
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade show';
        backdrop.id = 'mealDetailsModalBackdrop';
        document.body.appendChild(backdrop);
    }
}

// 隐藏详情模态框
function hideMealDetailsModal() {
    const modalElement = document.getElementById('mealDetailsModal');
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        bootstrap.Modal.getInstance(modalElement).hide();
        // 确保背景遮罩层被移除
        const backdrop = document.querySelector('.modal-backdrop');
        if (backdrop) {
            backdrop.remove();
        }
        document.body.classList.remove('modal-open');
        // 确保恢复页面滚动
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
    } else {
        // 降级处理
        if (modalElement) {
            modalElement.classList.remove('show');
            modalElement.style.display = 'none';
        }
        const backdrop = document.getElementById('mealDetailsModalBackdrop');
        if (backdrop) {
            backdrop.remove();
        }
        document.body.classList.remove('modal-open');
        // 确保恢复页面滚动
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
    }
}

// 显示报餐详情
function displayMealDetails(data) {
    const report = data.report;
    const statusMap = {
        'pending': { label: '待确认', class: 'warning' },
        'confirmed': { label: '已确认', class: 'success' },
        'cancelled': { label: '已取消', class: 'secondary' }
    };
    
    const statusInfo = statusMap[report.status] || { label: '未知', class: 'secondary' };
    
    // 构建套餐项目HTML
    let packageItemsHtml = '';
    if (data.package_items && data.package_items.length > 0) {
        packageItemsHtml = `
            <div class="card mt-3">
                <div class="card-header">
                    <h5 class="mb-0">套餐内容</h5>
                </div>
                <div class="card-body">
                    <ul class="list-group list-group-flush">
                        ${data.package_items.map(item => `
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <strong>${item.item_name}</strong>
                                    ${item.item_description ? `<br><small class="text-muted">${item.item_description}</small>` : ''}
                                </div>
                                <span class="badge bg-primary rounded-pill">${item.quantity} ${item.unit}</span>
                            </li>
                        `).join('')}
                    </ul>
                </div>
            </div>
        `;
    }
    
    const content = `
        <div class="row">
            <div class="col-12">
                <h5 class="mb-3">${report.project_name || ''} ${report.project_code ? `(${report.project_code})` : ''}</h5>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="card mb-3">
                    <div class="card-header">
                        <h5 class="mb-0">基本信息</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-borderless mb-0">
                                    <tr>
                                        <td class="fw-semibold">人员:</td>
                                        <td>${report.personnel_name || ''}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-semibold">餐类型:</td>
                                        <td>${report.meal_type || ''}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-semibold">用餐日期:</td>
                                        <td>${report.meal_date || ''}</td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-borderless mb-0">
                                    <tr>
                                        <td class="fw-semibold">套餐:</td>
                                        <td>${report.package_name || '无'}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-semibold">数量:</td>
                                        <td>${report.meal_count || 0}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-semibold">状态:</td>
                                        <td><span class="badge bg-${statusInfo.class}">${statusInfo.label}</span></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card mb-3">
                    <div class="card-header">
                        <h5 class="mb-0">其他信息</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-borderless mb-0">
                                    <tr>
                                        <td class="fw-semibold">报告人:</td>
                                        <td>${report.reporter_name || ''}</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-semibold">报告时间:</td>
                                        <td>${report.created_at ? new Date(report.created_at).toLocaleString('zh-CN') : ''}</td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-borderless mb-0">
                                    <tr>
                                        <td class="fw-semibold">特殊要求:</td>
                                        <td>${report.special_requirements || '无'}</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                ${packageItemsHtml}
            </div>
        </div>
    `;
    
    showMealDetailsModal(content);
}
</script>

<?php include 'includes/footer.php'; ?>
