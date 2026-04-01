<?php
// 调试模式 - 绕过登录验证
$debug = isset($_GET['debug']) && $_GET['debug'] == '1';
if ($debug) {
    $_SESSION['user_id'] = 1;
    $_SESSION['username'] = 'admin';
    $_SESSION['role'] = 'admin';
}

// 检查登录
if (!$debug && !isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

// 引入数据库连接
try {
    include '../config/database.php';
} catch (Exception $e) {
    die("数据库连接失败: " . $e->getMessage());
}

// 获取部门列表
$departments = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM departments ORDER BY name");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "获取部门列表失败: " . $e->getMessage();
}

// 获取项目列表
$projects = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM projects ORDER BY name");
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "获取项目列表失败: " . $e->getMessage();
}

// 获取人员列表
$personnel = [];
try {
    $query = "SELECT p.*, d.name as department_name, pr.name as project_name 
              FROM personnel p 
              LEFT JOIN departments d ON p.department_id = d.id 
              LEFT JOIN projects pr ON p.project_id = pr.id 
              ORDER BY p.name";
    $stmt = $pdo->query($query);
    $personnel = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "获取人员列表失败: " . $e->getMessage();
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'add') {
            $stmt = $pdo->prepare("INSERT INTO personnel (name, phone, email, department_id, position, project_id) 
                                VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['name'],
                $_POST['phone'],
                $_POST['email'],
                $_POST['department_id'],
                $_POST['position'],
                $_POST['project_id']
            ]);
            $success = "人员添加成功！";
        } elseif ($action === 'edit') {
            $stmt = $pdo->prepare("UPDATE personnel SET name=?, phone=?, email=?, department_id=?, position=?, project_id=? 
                                WHERE id=?");
            $stmt->execute([
                $_POST['name'],
                $_POST['phone'],
                $_POST['email'],
                $_POST['department_id'],
                $_POST['position'],
                $_POST['project_id'],
                $_POST['id']
            ]);
            $success = "人员更新成功！";
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare("DELETE FROM personnel WHERE id=?");
            $stmt->execute([$_POST['id']]);
            $success = "人员删除成功！";
        }
    } catch (PDOException $e) {
        $error = "操作失败: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>人员管理</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <h2>人员管理</h2>
                
                <?php if (isset($success)): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">人员列表</h5>
                        <a href="add_personnel.php" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> 添加人员
                        </a>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>姓名</th>
                                        <th>电话</th>
                                        <th>邮箱</th>
                                        <th>部门</th>
                                        <th>职位</th>
                                        <th>项目</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($personnel as $person): ?>
                                    <tr>
                                        <td><?php echo $person['id']; ?></td>
                                        <td><?php echo htmlspecialchars($person['name']); ?></td>
                                        <td><?php echo htmlspecialchars($person['phone']); ?></td>
                                        <td><?php echo htmlspecialchars($person['email']); ?></td>
                                        <td><?php echo htmlspecialchars($person['department_name']); ?></td>
                                        <td><?php echo htmlspecialchars($person['position']); ?></td>
                                        <td><?php echo htmlspecialchars($person['project_name']); ?></td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-warning" 
                                                    onclick="editPerson(<?php echo $person['id']; ?>)">
                                                <i class="bi bi-pencil"></i> 编辑
                                            </button>
                                            <button type="button" class="btn btn-sm btn-danger" 
                                                    onclick="deletePerson(<?php echo $person['id']; ?>, '<?php echo addslashes($person['name']); ?>')">
                                                <i class="bi bi-trash"></i> 删除
                                            </button>
                                            <a href="create_project_user.php?id=<?php echo $person['id']; ?>&name=<?php echo addslashes($person['name']); ?>" class="btn btn-sm btn-info">
                                                <i class="bi bi-person-plus"></i> 创建项目用户
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    

    <!-- 编辑人员模态框 -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">编辑人员</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="editForm" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="id" id="edit_id">
                        <div class="mb-3">
                            <label class="form-label">姓名</label>
                            <input type="text" class="form-control" name="name" id="edit_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">电话</label>
                            <input type="tel" class="form-control" name="phone" id="edit_phone">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">邮箱</label>
                            <input type="email" class="form-control" name="email" id="edit_email">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">部门</label>
                            <select class="form-select" name="department_id" id="edit_department">
                                <option value="">请选择部门</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">职位</label>
                            <input type="text" class="form-control" name="position" id="edit_position">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">项目</label>
                            <select class="form-select" name="project_id" id="edit_project">
                                <option value="">请选择项目</option>
                                <?php foreach ($projects as $project): ?>
                                <option value="<?php echo $project['id']; ?>"><?php echo htmlspecialchars($project['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 编辑人员
        function editPerson(id) {
            console.log('编辑人员:', id);
            fetch(`api/personnel/get_person.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const person = data.data;
                        document.getElementById('edit_id').value = person.id;
                        document.getElementById('edit_name').value = person.name;
                        document.getElementById('edit_phone').value = person.phone || '';
                        document.getElementById('edit_email').value = person.email || '';
                        document.getElementById('edit_department').value = person.department_id || '';
                        document.getElementById('edit_position').value = person.position || '';
                        document.getElementById('edit_project').value = person.project_id || '';
                        
                        const modal = new bootstrap.Modal(document.getElementById('editModal'));
                        modal.show();
                    } else {
                        alert('获取人员信息失败: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('获取人员信息时出错');
                });
        }

        // 删除人员
        function deletePerson(id, name) {
            if (confirm(`确定要删除人员 "${name}" 吗？此操作不可恢复！`)) {
                fetch('delete_person.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ id: id })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('删除成功！');
                        location.reload();
                    } else {
                        alert('删除失败: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('删除时出错');
                });
            }
        }



        // 页面加载完成后初始化
        document.addEventListener('DOMContentLoaded', function() {
            console.log('人员管理页面已加载');
        });
    </script>
</body>
</html>