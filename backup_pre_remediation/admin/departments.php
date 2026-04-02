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
            $description = trim($_POST['description']);
            
            if (empty($name)) {
                $error = '部门名称不能为空！';
            } else {
                $query = "INSERT INTO departments (name, description) 
                         VALUES (:name, :description)";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':description', $description);
                
                if ($stmt->execute()) {
                    $message = '部门添加成功！';
                } else {
                    $error = '添加部门失败，请重试！';
                }
            }
        } elseif ($action === 'edit' && isset($_POST['id'])) {
            // 编辑部门
            $id = intval($_POST['id']);
            $name = trim($_POST['name']);
            $description = trim($_POST['description']);
            
            if (empty($name)) {
                $error = '部门名称不能为空！';
            } else {
                $query = "UPDATE departments SET name = :name, description = :description 
                         WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':id', $id);
                
                if ($stmt->execute()) {
                    $message = '部门更新成功！';
                } else {
                    $error = '更新部门失败，请重试！';
                }
            }
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            // 删除部门
            $id = intval($_POST['id']);
            
            // 检查是否有人员关联
            $check_query = "SELECT COUNT(*) FROM project_department_personnel WHERE department_id = :department_id";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':department_id', $id);
            $check_stmt->execute();
            $personnel_count = $check_stmt->fetchColumn();
            
            if ($personnel_count > 0) {
                $error = '该部门下有人员，无法删除！';
            } else {
                $query = "DELETE FROM departments WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $id);
                
                if ($stmt->execute()) {
                    $message = '部门删除成功！';
                } else {
                    $error = '删除部门失败，请重试！';
                }
            }
        }
    }
}

// 获取部门列表
$query = "SELECT d.* 
         FROM departments d 
         ORDER BY d.id DESC";
$stmt = $db->prepare($query);
$stmt->execute();
$departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取每个部门的人员信息
$dept_personnel = [];
$dept_counts = [];
foreach ($departments as $dept) {
    // 获取人员数量
    $count_query = "SELECT COUNT(DISTINCT personnel_id) FROM project_department_personnel WHERE department_id = :department_id";
    $count_stmt = $db->prepare($count_query);
    $count_stmt->bindParam(':department_id', $dept['id']);
    $count_stmt->execute();
    $dept_counts[$dept['id']] = $count_stmt->fetchColumn();
    
    // 获取详细人员信息
    $personnel_query = "SELECT 
                        p.id as personnel_id,
                        p.name as personnel_name,
                        pdp.position,
                        pr.name as project_name,
                        pr.code as project_code
                     FROM project_department_personnel pdp
                     INNER JOIN personnel p ON pdp.personnel_id = p.id
                     INNER JOIN projects pr ON pdp.project_id = pr.id
                     WHERE pdp.department_id = :department_id
                     ORDER BY p.name ASC";
    $personnel_stmt = $db->prepare($personnel_query);
    $personnel_stmt->bindParam(':department_id', $dept['id']);
    $personnel_stmt->execute();
    $dept_personnel[$dept['id']] = $personnel_stmt->fetchAll(PDO::FETCH_ASSOC);
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

// 处理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}
?>

<?php include 'includes/header.php'; ?>

<div class="container-fluid">
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

    <!-- 页面标题 -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="bi bi-diagram-3"></i> <?php echo get_current_page_title(); ?></h1>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDepartmentModal">
            <i class="bi bi-plus-circle"></i> 新增部门
        </button>
    </div>

    <!-- 部门列表 -->
    <div class="row">
        <?php if (empty($departments)): ?>
            <div class="col-12">
                <div class="text-center py-4">
                    <i class="bi bi-inbox display-1 text-muted"></i>
                    <h5 class="text-muted">暂无部门</h5>
                    <p class="text-muted">请先添加部门</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($departments as $department): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card h-100">
                        <div class="card-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><?php echo htmlspecialchars($department['name']); ?></h5>
                                <div>
                                    <button type="button" class="btn btn-sm btn-warning" 
                                            onclick="editDepartment(<?php echo htmlspecialchars(json_encode($department)); ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('确定要删除该部门吗？');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $department['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="card-text">
                                <?php echo htmlspecialchars($department['description'] ?: '暂无描述'); ?>
                            </p>

                            <p class="card-text">
                                <small class="text-muted">
                                    <i class="bi bi-people"></i> 人员数量: 
                                    <span class="badge bg-secondary"><?php echo $dept_counts[$department['id']] ?? 0; ?></span>
                                </small>
                            </p>

                            <?php if (!empty($dept_personnel[$department['id']])): ?>
                            <div class="mt-3">
                                <h6><i class="bi bi-person-lines-fill"></i> 部门人员:</h6>
                                <div class="list-group list-group-flush">
                                    <?php 
                                    $personnel_count = 0;
                                    foreach ($dept_personnel[$department['id']] as $person): 
                                        if ($personnel_count >= 3) break;
                                        $personnel_count++;
                                    ?>
                                    <div class="list-group-item p-2 small">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span>
                                                <i class="bi bi-person-circle"></i>
                                                <?php echo htmlspecialchars($person['personnel_name']); ?>
                                                <small class="text-muted">- <?php echo htmlspecialchars($person['position'] ?: '暂无职位'); ?></small>
                                            </span>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($person['project_name']); ?>
                                            </small>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php if (count($dept_personnel[$department['id']]) > 3): ?>
                                    <div class="list-group-item p-2 small text-center">
                                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                                onclick="viewDepartmentPersonnel(<?php echo $department['id']; ?>, '<?php echo htmlspecialchars($department['name']); ?>')">
                                            查看更多 (<?php echo count($dept_personnel[$department['id']]); ?>人)
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="text-center mt-3">
                                <small class="text-muted">
                                    <i class="bi bi-info-circle"></i> 暂无人员信息
                                </small>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer">
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-muted">
                                    创建时间: <?php echo date('Y-m-d', strtotime($department['created_at'])); ?>
                                </small>
                                <?php if (!empty($dept_personnel[$department['id']])): ?>
                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                        onclick="viewDepartmentPersonnel(<?php echo $department['id']; ?>, '<?php echo htmlspecialchars($department['name']); ?>')">
                                    <i class="bi bi-eye"></i> 查看全部
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- 新增部门模态框 -->
<div class="modal fade" id="addDepartmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">新增部门</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label for="name" class="form-label">部门名称 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">部门描述</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
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

<!-- 部门人员详情模态框 -->
<div class="modal fade" id="personnelModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="personnelModalTitle">部门人员列表</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="personnelListContent">
                    <!-- 动态加载人员列表 -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
            </div>
        </div>
    </div>
</div>

<!-- 编辑部门模态框 -->
<div class="modal fade" id="editDepartmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">编辑部门</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">部门名称 <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">部门描述</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">更新</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function editDepartment(department) {
        document.getElementById('edit_id').value = department.id;
        document.getElementById('edit_name').value = department.name;
        document.getElementById('edit_description').value = department.description ?? '';
        
        var modal = new bootstrap.Modal(document.getElementById('editDepartmentModal'));
        modal.show();
    }

    function viewDepartmentPersonnel(departmentId, departmentName) {
        // 创建动态内容
        fetch(`api/get_department_personnel.php?department_id=${departmentId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.personnel) {
                    let personnelList = '';
                    data.personnel.forEach(person => {
                        personnelList += `
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-1"><i class="bi bi-person-circle"></i> ${person.personnel_name}</h6>
                                        <p class="mb-1">
                                            <small class="text-muted">
                                                <i class="bi bi-briefcase"></i> ${person.position || '暂无职位'}
                                            </small>
                                        </p>
                                    </div>
                                    <div class="text-end">
                                        <small class="badge bg-info">${person.project_name}</small>
                                    </div>
                                </div>
                            </div>
                        `;
                    });

                    document.getElementById('personnelListContent').innerHTML = personnelList;
                    document.getElementById('personnelModalTitle').textContent = `${departmentName} - 部门人员列表`;
                    
                    var modal = new bootstrap.Modal(document.getElementById('personnelModal'));
                    modal.show();
                } else {
                    alert('获取人员信息失败');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                // 使用本地数据作为备选
                alert('获取人员信息失败，请刷新页面重试');
            });
    }

    // 如果没有API，使用本地数据
    function viewDepartmentPersonnelLocal(departmentId, departmentName) {
        // 这个方法会在API不可用时使用
        const personnelData = <?php echo json_encode($dept_personnel); ?>;
        const personnel = personnelData[departmentId] || [];
        
        let personnelList = '';
        personnel.forEach(person => {
            personnelList += `
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1"><i class="bi bi-person-circle"></i> ${escapeHtml(person.personnel_name)}</h6>
                            <p class="mb-1">
                                <small class="text-muted">
                                    <i class="bi bi-briefcase"></i> ${escapeHtml(person.position || '暂无职位')}
                                </small>
                            </p>
                        </div>
                        <div class="text-end">
                            <small class="badge bg-info">${escapeHtml(person.project_name)}</small>
                        </div>
                    </div>

                </div>
            `;
        });

        document.getElementById('personnelListContent').innerHTML = personnelList;
        document.getElementById('personnelModalTitle').textContent = `${escapeHtml(departmentName)} - 部门人员列表`;
        
        var modal = new bootstrap.Modal(document.getElementById('personnelModal'));
        modal.show();
    }

    // HTML转义函数
    function escapeHtml(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    // 使用本地数据版本
    function viewDepartmentPersonnel(departmentId, departmentName) {
        viewDepartmentPersonnelLocal(departmentId, departmentName);
    }
</script>

<?php include 'includes/footer.php'; ?>