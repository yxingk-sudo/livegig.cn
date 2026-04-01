<?php
session_start();

// 调试模式 - 如果URL中包含debug=1，则绕过登录检查
$debug_mode = isset($_GET['debug']) && $_GET['debug'] == '1';

if (!$debug_mode && !isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// 数据库连接
try {
    include '../config/database.php';
} catch (Exception $e) {
    die('数据库连接失败: ' . $e->getMessage());
}

// 处理AJAX请求
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'get_companies':
            $stmt = $pdo->query("SELECT id, name FROM companies ORDER BY name");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;
            
        case 'get_projects':
            $company_id = $_GET['company_id'] ?? 0;
            $stmt = $pdo->prepare("SELECT id, name FROM projects WHERE company_id = ? ORDER BY name");
            $stmt->execute([$company_id]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;
            
        case 'get_departments':
            $project_id = $_GET['project_id'] ?? 0;
            $stmt = $pdo->prepare("SELECT id, name FROM departments WHERE project_id = ? ORDER BY name");
            $stmt->execute([$project_id]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;
            
        case 'get_personnel':
            $stmt = $pdo->query("
                SELECT p.*, c.name as company_name, pr.name as project_name,
                       GROUP_CONCAT(d.name) as department_names
                FROM personnel p
                LEFT JOIN companies c ON p.company_id = c.id
                LEFT JOIN projects pr ON p.project_id = pr.id
                LEFT JOIN departments d ON FIND_IN_SET(d.id, p.department_ids)
                GROUP BY p.id
                ORDER BY p.created_at DESC
            ");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;
            
        case 'delete':
            $id = $_GET['id'] ?? 0;
            $stmt = $pdo->prepare("DELETE FROM personnel WHERE id = ?");
            if ($stmt->execute([$id])) {
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['success' => false, 'message' => '删除失败']);
            }
            break;
    }
    exit();
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $id_card = $_POST['id_card'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $company_id = $_POST['company_id'] ?? 0;
    $project_id = $_POST['project_id'] ?? 0;
    $department_ids = $_POST['department_ids'] ?? '';
    
    if (isset($_POST['id']) && $_POST['id']) {
        // 更新
        $stmt = $pdo->prepare("
            UPDATE personnel SET 
            name = ?, email = ?, phone = ?, id_card = ?, gender = ?, 
            company_id = ?, project_id = ?, department_ids = ?
            WHERE id = ?
        ");
        $stmt->execute([$name, $email, $phone, $id_card, $gender, 
                       $company_id, $project_id, $department_ids, $_POST['id']]);
    } else {
        // 新增
        $stmt = $pdo->prepare("
            INSERT INTO personnel (name, email, phone, id_card, gender, 
                                 company_id, project_id, department_ids, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$name, $email, $phone, $id_card, $gender, 
                       $company_id, $project_id, $department_ids]);
    }
    
    header("Location: personnel_complete.php");
    exit();
}

// 获取下拉数据
$companies = $pdo->query("SELECT id, name FROM companies ORDER BY name");
$projects = $pdo->query("SELECT id, name, company_id FROM projects ORDER BY name");
$departments = $pdo->query("SELECT id, name, project_id FROM departments ORDER BY name");

// 获取人员列表
$personnel = $pdo->query("
    SELECT p.*, c.name as company_name, pr.name as project_name,
           GROUP_CONCAT(d.name) as department_names
    FROM personnel p
    LEFT JOIN companies c ON p.company_id = c.id
    LEFT JOIN projects pr ON p.project_id = pr.id
    LEFT JOIN departments d ON FIND_IN_SET(d.id, p.department_ids)
    GROUP BY p.id
    ORDER BY p.created_at DESC
");
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
    <?php include 'navbar.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <main class="col-md-12 ms-sm-auto px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">人员管理</h1>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPersonModal">
                        <i class="bi bi-plus-circle"></i> 添加人员
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>姓名</th>
                                <th>邮箱</th>
                                <th>电话</th>
                                <th>身份证</th>
                                <th>性别</th>
                                <th>公司</th>
                                <th>项目</th>
                                <th>部门</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody id="personnelTableBody">
                            <?php while ($person = $personnel->fetch(PDO::FETCH_ASSOC)): ?>
                            <tr>
                                <td><?= $person['id'] ?></td>
                                <td><?= htmlspecialchars($person['name']) ?></td>
                                <td><?= htmlspecialchars($person['email']) ?></td>
                                <td><?= htmlspecialchars($person['phone']) ?></td>
                                <td><?= htmlspecialchars($person['id_card']) ?></td>
                                <td><?= $person['gender'] == 'M' ? '男' : '女' ?></td>
                                <td><?= htmlspecialchars($person['company_name']) ?></td>
                                <td><?= htmlspecialchars($person['project_name']) ?></td>
                                <td><?= htmlspecialchars($person['department_names']) ?></td>
                                <td>
                                    <button class="btn btn-sm btn-warning" 
                                            onclick="editPerson(<?= htmlspecialchars(json_encode($person)) ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger" 
                                            onclick="deletePerson(<?= $person['id'] ?>)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </main>
        </div>
    </div>

    <!-- 添加人员模态框 -->
    <div class="modal fade" id="addPersonModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">添加人员</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">姓名</label>
                                    <input type="text" class="form-control" name="name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">邮箱</label>
                                    <input type="email" class="form-control" name="email" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">电话</label>
                                    <input type="tel" class="form-control" name="phone" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">身份证</label>
                                    <input type="text" class="form-control" name="id_card" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">性别</label>
                                    <select class="form-control" name="gender" required>
                                        <option value="">请选择</option>
                                        <option value="M">男</option>
                                        <option value="F">女</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">公司</label>
                                    <select class="form-control" id="add_company" name="company_id" required onchange="loadProjects(this.value, 'add')">
                                        <option value="">请选择公司</option>
                                        <?php $companies->execute(); while($company = $companies->fetch(PDO::FETCH_ASSOC)): ?>
                                        <option value="<?= $company['id'] ?>"><?= htmlspecialchars($company['name']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">项目</label>
                                    <select class="form-control" id="add_project" name="project_id" required onchange="loadDepartments(this.value, 'add')">
                                        <option value="">请先选择公司</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">部门ID</label>
                                    <input type="hidden" id="add_department_ids" name="department_ids">
                                    <div id="add_departments"></div>
                                </div>
                            </div>
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

    <!-- 编辑人员模态框 -->
    <div class="modal fade" id="editPersonModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">编辑人员</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" id="edit_id" name="id">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">姓名</label>
                                    <input type="text" class="form-control" id="edit_name" name="name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">邮箱</label>
                                    <input type="email" class="form-control" id="edit_email" name="email" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">电话</label>
                                    <input type="tel" class="form-control" id="edit_phone" name="phone" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">身份证</label>
                                    <input type="text" class="form-control" id="edit_id_card" name="id_card" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">性别</label>
                                    <select class="form-control" id="edit_gender" name="gender" required>
                                        <option value="">请选择</option>
                                        <option value="M">男</option>
                                        <option value="F">女</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">公司</label>
                                    <select class="form-control" id="edit_company" name="company_id" required onchange="loadProjects(this.value, 'edit')">
                                        <option value="">请选择公司</option>
                                        <?php $companies->execute(); while($company = $companies->fetch(PDO::FETCH_ASSOC)): ?>
                                        <option value="<?= $company['id'] ?>"><?= htmlspecialchars($company['name']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">项目</label>
                                    <select class="form-control" id="edit_project" name="project_id" required onchange="loadDepartments(this.value, 'edit')">
                                        <option value="">请先选择公司</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">部门ID</label>
                                    <input type="hidden" id="edit_department_ids" name="department_ids">
                                    <div id="edit_departments"></div>
                                </div>
                            </div>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let companiesData = [];
        let projectsData = [];
        let departmentsData = [];

        // 加载公司列表
        function loadCompanies() {
            fetch('personnel_complete.php?action=get_companies')
                .then(response => response.json())
                .then(data => {
                    companiesData = data;
                });
        }

        // 加载项目列表
        function loadProjects(companyId, type) {
            if (!companyId) return;
            
            fetch(`personnel_complete.php?action=get_projects&company_id=${companyId}`)
                .then(response => response.json())
                .then(data => {
                    projectsData = data;
                    const projectSelect = document.getElementById(`${type}_project`);
                    projectSelect.innerHTML = '<option value="">请选择项目</option>';
                    data.forEach(project => {
                        projectSelect.innerHTML += `<option value="${project.id}">${project.name}</option>`;
                    });
                });
        }

        // 加载部门列表
        function loadDepartments(projectId, type) {
            if (!projectId) return;
            
            fetch(`personnel_complete.php?action=get_departments&project_id=${projectId}`)
                .then(response => response.json())
                .then(data => {
                    departmentsData = data;
                    const departmentsDiv = document.getElementById(`${type}_departments`);
                    departmentsDiv.innerHTML = '';
                    data.forEach(dept => {
                        departmentsDiv.innerHTML += `
                            <div class="form-check">
                                <input class="form-check-input department-checkbox" 
                                       type="checkbox" 
                                       data-type="${type}" 
                                       value="${dept.id}" 
                                       id="${type}_dept_${dept.id}" 
                                       onchange="updateDepartmentIds('${type}')">
                                <label class="form-check-label" for="${type}_dept_${dept.id}">
                                    ${dept.name}
                                </label>
                            </div>
                        `;
                    });
                });
        }

        // 更新部门ID
        function updateDepartmentIds(type) {
            const checkboxes = document.querySelectorAll(`.department-checkbox[data-type="${type}"]:checked`);
            const ids = Array.from(checkboxes).map(cb => cb.value.trim());
            
            const departmentIdsInput = document.getElementById(`${type}_department_ids`);
            if (departmentIdsInput) {
                departmentIdsInput.value = ids.join(',');
            }
        }

        // 编辑人员
        function editPerson(person) {
            console.log('编辑人员:', person);
            
            document.getElementById('edit_id').value = person.id;
            document.getElementById('edit_name').value = person.name;
            document.getElementById('edit_email').value = person.email;
            document.getElementById('edit_phone').value = person.phone;
            document.getElementById('edit_id_card').value = person.id_card;
            document.getElementById('edit_gender').value = person.gender;
            document.getElementById('edit_company').value = person.company_id;
            
            loadProjects(person.company_id, 'edit');
            
            setTimeout(() => {
                document.getElementById('edit_project').value = person.project_id;
                loadDepartments(person.project_id, 'edit');
                
                setTimeout(() => {
                    if (person.department_ids) {
                        const departmentIds = person.department_ids.split(',').map(id => id.trim());
                        departmentIds.forEach(deptId => {
                            const checkbox = document.getElementById(`edit_dept_${deptId}`);
                            if (checkbox) checkbox.checked = true;
                        });
                        updateDepartmentIds('edit');
                    }
                }, 100);
            }, 100);
            
            const modal = new bootstrap.Modal(document.getElementById('editPersonModal'));
            modal.show();
        }

        // 删除人员
        function deletePerson(personnelId) {
            if (confirm('确定要删除这个人员吗？')) {
                fetch(`personnel_complete.php?action=delete&id=${personnelId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('删除成功');
                            location.reload();
                        } else {
                            alert('删除失败: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('删除错误:', error);
                        alert('删除失败');
                    });
            }
        }

        // 页面加载完成后初始化
        document.addEventListener('DOMContentLoaded', function() {
            loadCompanies();
        });
    </script>
</body>
</html>