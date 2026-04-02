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

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();

// 处理表单提交
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'add') {
            // 添加部门
            $name = trim($_POST['name']);
            $project_id = intval($_POST['project_id']);
            $description = trim($_POST['description']);
            
            if (empty($name)) {
                $error = '部门名称不能为空！';
            } elseif ($project_id <= 0) {
                $error = '请选择所属项目！';
            } else {
                try {
                    // 检查同名部门是否已存在
                    $check_query = "SELECT COUNT(*) FROM departments WHERE name = :name AND project_id = :project_id";
                    $check_stmt = $db->prepare($check_query);
                    $check_stmt->bindParam(':name', $name);
                    $check_stmt->bindParam(':project_id', $project_id);
                    $check_stmt->execute();
                    
                    if ($check_stmt->fetchColumn() > 0) {
                        $error = '该项目下已存在同名部门！';
                    } else {
                        $query = "INSERT INTO departments (name, project_id, description) VALUES (:name, :project_id, :description)";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':name', $name);
                        $stmt->bindParam(':project_id', $project_id);
                        $stmt->bindParam(':description', $description);
                        
                        if ($stmt->execute()) {
                            $_SESSION['success_message'] = '部门添加成功！';
                            $redirect_url = "departments_enhanced.php";
                            if ($project_id > 0) {
                                $redirect_url .= "?project_id=" . $project_id;
                            }
                            header("Location: " . $redirect_url);
                            exit;
                        } else {
                            $error = '添加部门失败，请重试！';
                        }
                    }
                } catch (Exception $e) {
                    $error = '添加部门失败：' . $e->getMessage();
                }
            }
        } elseif ($action === 'edit' && isset($_POST['id'])) {
            // 编辑部门
            $id = intval($_POST['id']);
            $name = trim($_POST['name']);
            $project_id = intval($_POST['project_id']);
            $description = trim($_POST['description']);
            
            if (empty($name)) {
                $error = '部门名称不能为空！';
            } elseif ($project_id <= 0) {
                $error = '请选择所属项目！';
            } else {
                try {
                    // 检查同名部门是否已存在（排除当前部门）
                    $check_query = "SELECT COUNT(*) FROM departments WHERE name = :name AND project_id = :project_id AND id != :id";
                    $check_stmt = $db->prepare($check_query);
                    $check_stmt->bindParam(':name', $name);
                    $check_stmt->bindParam(':project_id', $project_id);
                    $check_stmt->bindParam(':id', $id);
                    $check_stmt->execute();
                    
                    if ($check_stmt->fetchColumn() > 0) {
                        $error = '该项目下已存在同名部门！';
                    } else {
                        $query = "UPDATE departments SET name = :name, project_id = :project_id, description = :description WHERE id = :id";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':name', $name);
                        $stmt->bindParam(':project_id', $project_id);
                        $stmt->bindParam(':description', $description);
                        $stmt->bindParam(':id', $id);
                        
                        if ($stmt->execute()) {
                            $_SESSION['success_message'] = '部门更新成功！';
                            $redirect_url = "departments_enhanced.php";
                            if ($project_id > 0) {
                                $redirect_url .= "?project_id=" . $project_id;
                            }
                            header("Location: " . $redirect_url);
                            exit;
                        } else {
                            $error = '更新部门失败，请重试！';
                        }
                    }
                } catch (Exception $e) {
                    $error = '更新部门失败：' . $e->getMessage();
                }
            }
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            // 删除部门
            $id = intval($_POST['id']);
            
            try {
                // 检查是否有人员关联
                $check_query = "SELECT COUNT(*) FROM project_department_personnel WHERE department_id = :department_id";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->bindParam(':department_id', $id);
                $check_stmt->execute();
                
                if ($check_stmt->fetchColumn() > 0) {
                    $error = '该部门下还有人员，无法删除！';
                } else {
                    $query = "DELETE FROM departments WHERE id = :id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':id', $id);
                    
                    if ($stmt->execute()) {
                        $_SESSION['success_message'] = '部门删除成功！';
                        $redirect_url = "departments_enhanced.php";
                        $current_project = $_GET['project_id'] ?? $_POST['project_id'] ?? '';
                        if ($current_project) {
                            $redirect_url .= "?project_id=" . $current_project;
                        }
                        header("Location: " . $redirect_url);
                        exit;
                    } else {
                        $error = '删除部门失败，请重试！';
                    }
                }
            } catch (Exception $e) {
                $error = '删除部门失败：' . $e->getMessage();
            }
        } elseif ($action === 'update_sort_order') {
            // 更新部门排序
            $project_id = intval($_POST['project_id']);
            $sort_data = json_decode($_POST['sort_data'] ?? '[]', true);
            
            try {
                $db->beginTransaction();
                
                foreach ($sort_data as $index => $dept_id) {
                    $query = "UPDATE departments SET sort_order = :sort_order WHERE id = :id AND project_id = :project_id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':sort_order', $index, PDO::PARAM_INT);
                    $stmt->bindParam(':id', $dept_id, PDO::PARAM_INT);
                    $stmt->bindParam(':project_id', $project_id, PDO::PARAM_INT);
                    $stmt->execute();
                }
                
                $db->commit();
                echo json_encode(['success' => true, 'message' => '排序更新成功！']);
                exit;
            } catch (Exception $e) {
                $db->rollBack();
                echo json_encode(['success' => false, 'message' => '排序更新失败：' . $e->getMessage()]);
                exit;
            }
        } elseif ($action === 'update_manual_sort') {
            // 手动更新部门排序编号
            $project_id = intval($_POST['project_id']);
            $sort_orders = $_POST['sort_orders'] ?? [];
            
            try {
                $db->beginTransaction();
                
                foreach ($sort_orders as $dept_id => $sort_order) {
                    $query = "UPDATE departments SET sort_order = :sort_order WHERE id = :id AND project_id = :project_id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':sort_order', $sort_order, PDO::PARAM_INT);
                    $stmt->bindParam(':id', $dept_id, PDO::PARAM_INT);
                    $stmt->bindParam(':project_id', $project_id, PDO::PARAM_INT);
                    $stmt->execute();
                }
                
                $db->commit();
                $_SESSION['success_message'] = '手动排序更新成功！';
                $redirect_url = "departments_enhanced.php?project_id=" . $project_id;
                header("Location: " . $redirect_url);
                exit;
            } catch (Exception $e) {
                $db->rollBack();
                $error = '手动排序更新失败：' . $e->getMessage();
            }
        }
    }
}

// 获取项目列表
$projects_query = "SELECT id, name, code FROM projects ORDER BY name ASC";
$projects_stmt = $db->prepare($projects_query);
$projects_stmt->execute();
$projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取筛选参数
$filters = [
    'project_id' => $_GET['project_id'] ?? ''
];

// 获取部门列表（只有选择了项目才查询）
$departments = [];
if ($filters['project_id']) {
    $departments_query = "SELECT 
                            d.id,
                            d.name,
                            d.description,
                            COALESCE(d.sort_order, 0) as sort_order,
                            d.project_id,
                            p.name as project_name,
                            p.code as project_code,
                            COUNT(pdp.personnel_id) as personnel_count
                         FROM departments d
                         LEFT JOIN projects p ON d.project_id = p.id
                         LEFT JOIN project_department_personnel pdp ON d.id = pdp.department_id
                         WHERE d.project_id = :project_id
                         GROUP BY d.id, d.name, d.description, d.sort_order, d.project_id, p.name, p.code
                         ORDER BY d.sort_order ASC, d.name";
                         
    $departments_stmt = $db->prepare($departments_query);
    $departments_stmt->bindParam(':project_id', $filters['project_id']);
    $departments_stmt->execute();
    $departments = $departments_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 处理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// 处理编辑请求
$edit_department = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $edit_id = intval($_GET['id']);
    $query = "SELECT * FROM departments WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $edit_id);
    $stmt->execute();
    $edit_department = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 检查是否是AJAX请求用于排序
if (isset($_GET['action']) && $_GET['action'] === 'get_departments_json' && $filters['project_id']) {
    header('Content-Type: application/json');
    echo json_encode($departments);
    exit;
}
?>

<?php include 'includes/header.php'; ?>

<div class="container-fluid">
    <!-- 项目选择区域 -->
    <div class="project-selector">
        <h4>
            <i class="bi bi-building"></i>
            项目选择
        </h4>
        <form method="GET" id="projectForm">
            <div class="row align-items-end">
                <div class="col-md-8">
                    <label for="project_id" class="form-label fw-semibold">请选择要管理的项目</label>
                    <select class="form-select" id="project_id" name="project_id" onchange="this.form.submit()">
                        <option value="">请选择项目...</option>
                        <?php foreach ($projects as $project): ?>
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

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($_SESSION['success_message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i> <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($filters['project_id']): ?>
        <!-- 部门管理区域 -->
        <div class="departments-container">
            <div class="departments-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-building me-2"></i>部门管理
                    <span class="badge bg-light text-dark ms-2"><?php echo count($departments); ?></span>
                </h5>
                <div>
                    <button type="button" class="btn btn-light me-2" data-bs-toggle="modal" data-bs-target="#manualSortModal">
                        <i class="bi bi-list-ol me-1"></i>编号排序
                    </button>
                    <button type="button" class="btn btn-light" data-bs-toggle="modal" data-bs-target="#addDepartmentModal">
                        <i class="bi bi-plus-circle me-1"></i>新增部门
                    </button>
                </div>
            </div>
            <div class="departments-body">
                <?php if (empty($departments)): ?>
                    <div class="empty-state">
                        <i class="bi bi-building display-1"></i>
                        <h5 class="mt-3">该项目暂无部门</h5>
                        <p class="text-muted mb-4">点击"新增部门"按钮为该项目创建第一个部门</p>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDepartmentModal">
                            <i class="bi bi-plus-circle me-1"></i>新增部门
                        </button>
                    </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> 使用"编号排序"功能手动设置排序编号
                    </div>
                    <div class="project-grid" id="departmentsGrid">
                        <?php foreach ($departments as $index => $dept): ?>
                            <div class="department-card" data-id="<?php echo $dept['id']; ?>" data-sort="<?php echo $dept['sort_order']; ?>">
                                <div class="sort-number"><?php echo $index + 1; ?></div>
                                <div class="department-title">
                                    <?php echo htmlspecialchars($dept['name']); ?>
                                </div>
                                <div class="department-desc" title="<?php echo htmlspecialchars($dept['description']); ?>">
                                    <?php echo htmlspecialchars($dept['description'] ?: '暂无描述'); ?>
                                </div>
                                <div class="department-stats">
                                    <?php 
                                    $count = $dept['personnel_count'];
                                    $badge_class = $count == 0 ? 'bg-secondary' : 
                                                   ($count <= 5 ? 'bg-success' : 
                                                   ($count <= 15 ? 'bg-warning text-dark' : 'bg-danger'));
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?>">
                                        <i class="bi bi-people-fill"></i> <?php echo $count; ?>
                                    </span>
                                </div>
                                <div class="department-actions">
                                    <button type="button" class="btn btn-outline-primary btn-xs" 
                                            onclick="editDepartment(<?php echo $dept['id']; ?>)" title="编辑">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-xs" 
                                            onclick="deleteDepartment(<?php echo $dept['id']; ?>, '<?php echo htmlspecialchars($dept['name']); ?>', <?php echo $dept['personnel_count']; ?>)" title="删除">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <!-- 未选择项目的空状态 -->
        <div class="empty-state">
            <i class="bi bi-folder2-open display-1"></i>
            <h5 class="mt-3">请先选择项目</h5>
            <p class="text-muted mb-4">选择要管理的项目后，才能查看和管理该项目的部门信息</p>
            <small class="text-muted">
                <i class="bi bi-info-circle me-1"></i>
                这样可以确保数据的准确性和管理的效率
            </small>
        </div>
    <?php endif; ?>
</div>

<!-- 添加/编辑部门模态框 -->
<div class="modal fade" id="addDepartmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle"><?php echo $edit_department ? '编辑部门' : '新增部门'; ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="departmentForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="<?php echo $edit_department ? 'edit' : 'add'; ?>">
                    <?php if ($edit_department): ?>
                        <input type="hidden" name="id" value="<?php echo $edit_department['id']; ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">部门名称 *</label>
                        <input type="text" class="form-control" name="name" 
                               value="<?php echo $edit_department ? htmlspecialchars($edit_department['name']) : ''; ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">所属项目 *</label>
                        <select class="form-select" name="project_id" required>
                            <?php if ($filters['project_id']): ?>
                                <?php 
                                $selected_project = null;
                                foreach ($projects as $project) {
                                    if ($project['id'] == $filters['project_id']) {
                                        $selected_project = $project;
                                        break;
                                    }
                                }
                                ?>
                                <option value="<?php echo $filters['project_id']; ?>" selected>
                                    <?php echo htmlspecialchars($selected_project['name']); ?>
                                </option>
                            <?php else: ?>
                                <option value="">选择项目</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo $project['id']; ?>" 
                                            <?php echo $edit_department && $edit_department['project_id'] == $project['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($project['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">部门描述</label>
                        <textarea class="form-control" name="description" rows="3" 
                                  placeholder="请输入部门描述..."><?php echo $edit_department ? htmlspecialchars($edit_department['description']) : ''; ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 手动排序模态框 -->
<div class="modal fade" id="manualSortModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">手动设置部门排序</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="manualSortForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_manual_sort">
                    <input type="hidden" name="project_id" value="<?php echo $filters['project_id']; ?>">
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> 拖动部门可调整排序顺序，数字越小排序越靠前
                    </div>
                    
                    <div class="mb-3" id="sortableDepartments">
                        <?php foreach ($departments as $dept): ?>
                            <div class="sortable-item d-flex align-items-center mb-2 p-2 border rounded" 
                                 data-dept-id="<?php echo $dept['id']; ?>"
                                 style="cursor: move; background-color: #f8f9fa;">
                                <i class="bi bi-grip-vertical me-2 text-muted"></i>
                                <div class="flex-grow-1">
                                    <?php echo htmlspecialchars($dept['name']); ?>
                                </div>
                                <input type="hidden" name="sort_orders[<?php echo $dept['id']; ?>]" 
                                       value="<?php echo $dept['sort_order'] !== null ? $dept['sort_order'] : '0'; ?>" 
                                       class="sort-order-input">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">保存排序</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // 编辑部门
    function editDepartment(id) {
        const currentProject = '<?php echo $filters['project_id']; ?>';
        window.location.href = '?action=edit&id=' + id + '&project_id=' + currentProject;
    }
    
    // 删除部门
    function deleteDepartment(id, name, personnelCount) {
        if (personnelCount > 0) {
            alert('该部门下还有 ' + personnelCount + ' 个人员，无法删除！请先调整相关人员到其它部门。');
            return;
        }
        
        if (confirm('确定要删除部门 "' + name + '" 吗？\n\n此操作不可恢复！')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    // 页面加载完成后初始化编辑模式
    document.addEventListener('DOMContentLoaded', function() {
        <?php if ($edit_department && empty($message)): ?>
            const modal = new bootstrap.Modal(document.getElementById('addDepartmentModal'));
            modal.show();
        <?php endif; ?>
        
        // 手动排序模态框的拖动排序功能
        // 监听模态框显示事件
        const manualSortModal = document.getElementById('manualSortModal');
        if (manualSortModal) {
            manualSortModal.addEventListener('shown.bs.modal', function() {
                initializeSortable();
            });
        }
        
        // 初始化拖动排序
        function initializeSortable() {
            const sortableContainer = document.getElementById('sortableDepartments');
            if (!sortableContainer) return;
            
            // 确保所有项目都可拖动
            sortableContainer.querySelectorAll('.sortable-item').forEach(item => {
                item.setAttribute('draggable', 'true');
                
                // 拖动开始
                item.addEventListener('dragstart', function(e) {
                    setTimeout(() => {
                        this.classList.add('dragging');
                    }, 0);
                });
                
                // 拖动结束
                item.addEventListener('dragend', function(e) {
                    this.classList.remove('dragging');
                    updateSortOrderInputs();
                });
            });
            
            // 容器事件处理
            sortableContainer.addEventListener('dragover', function(e) {
                e.preventDefault();
                const afterElement = getDragAfterElement(sortableContainer, e.clientY);
                const draggable = document.querySelector('.sortable-item.dragging');
                if (draggable) {
                    if (afterElement == null) {
                        sortableContainer.appendChild(draggable);
                    } else {
                        sortableContainer.insertBefore(draggable, afterElement);
                    }
                }
            });
            
            sortableContainer.addEventListener('dragenter', function(e) {
                e.preventDefault();
            });
            
            sortableContainer.addEventListener('drop', function(e) {
                e.preventDefault();
            });
        }
        
        // 获取拖动后的位置
        function getDragAfterElement(container, y) {
            const draggableElements = [...container.querySelectorAll('.sortable-item:not(.dragging)')];
            
            return draggableElements.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset: offset, element: child };
                } else {
                    return closest;
                }
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }
        
        // 更新排序输入框的值
        function updateSortOrderInputs() {
            const sortableItems = document.querySelectorAll('.sortable-item');
            sortableItems.forEach((item, index) => {
                const input = item.querySelector('.sort-order-input');
                if (input) {
                    input.value = index;
                }
            });
        }
    });
</script>

<?php include 'includes/footer.php'; ?>