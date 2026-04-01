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
            // 添加公司
            $name = trim($_POST['name']);
            $contact_person = trim($_POST['contact_person']);
            $phone = trim($_POST['phone']);
            $email = trim($_POST['email']);
            $address = trim($_POST['address']);
            
            if (empty($name)) {
                $error = '公司名称不能为空！';
            } else {
                $query = "INSERT INTO companies (name, contact_person, phone, email, address) 
                         VALUES (:name, :contact_person, :phone, :email, :address)";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':contact_person', $contact_person);
                $stmt->bindParam(':phone', $phone);
                $stmt->bindParam(':email', $email);
                $stmt->bindParam(':address', $address);
                
                if ($stmt->execute()) {
                    $message = '公司添加成功！';
                } else {
                    $error = '添加公司失败，请重试！';
                }
            }
        } elseif ($action === 'edit' && isset($_POST['id'])) {
            // 编辑公司
            $id = intval($_POST['id']);
            $name = trim($_POST['name']);
            $contact_person = trim($_POST['contact_person']);
            $phone = trim($_POST['phone']);
            $email = trim($_POST['email']);
            $address = trim($_POST['address']);
            
            if (empty($name)) {
                $error = '公司名称不能为空！';
            } else {
                $query = "UPDATE companies SET name = :name, contact_person = :contact_person, 
                         phone = :phone, email = :email, address = :address WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':contact_person', $contact_person);
                $stmt->bindParam(':phone', $phone);
                $stmt->bindParam(':email', $email);
                $stmt->bindParam(':address', $address);
                $stmt->bindParam(':id', $id);
                
                if ($stmt->execute()) {
                    $message = '公司更新成功！';
                } else {
                    $error = '更新公司失败，请重试！';
                }
            }
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            // 删除公司
            $id = intval($_POST['id']);
            
            // 检查是否有项目关联
            $check_query = "SELECT COUNT(*) FROM projects WHERE company_id = :company_id";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':company_id', $id);
            $check_stmt->execute();
            $project_count = $check_stmt->fetchColumn();
            
            if ($project_count > 0) {
                $error = '该公司下有项目，无法删除！';
            } else {
                $query = "DELETE FROM companies WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $id);
                
                if ($stmt->execute()) {
                    $message = '公司删除成功！';
                } else {
                    $error = '删除公司失败，请重试！';
                }
            }
        }
    }
}

// 获取公司列表及项目统计数据
$query = "SELECT c.*, 
                 COUNT(p.id) as project_count,
                 GROUP_CONCAT(p.name ORDER BY p.created_at DESC SEPARATOR ', ') as recent_projects
          FROM companies c 
          LEFT JOIN projects p ON c.id = p.company_id 
          GROUP BY c.id 
          ORDER BY c.created_at DESC";
$stmt = $db->prepare($query);
$stmt->execute();
$companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 处理编辑请求
$edit_company = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $edit_id = intval($_GET['id']);
    $query = "SELECT * FROM companies WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $edit_id);
    $stmt->execute();
    $edit_company = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 处理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}
?>

<?php include 'includes/header.php'; ?>

<style>
/* 公司管理表格列宽优化 */
.companies-table th:nth-child(1),
.companies-table td:nth-child(1) { width: 15%; min-width: 120px; } /* 公司名称 */

.companies-table th:nth-child(2),
.companies-table td:nth-child(2) { width: 10%; min-width: 80px; } /* 联系人 */

.companies-table th:nth-child(3),
.companies-table td:nth-child(3) { width: 12%; min-width: 100px; } /* 电话 */

.companies-table th:nth-child(4),
.companies-table td:nth-child(4) { width: 15%; min-width: 120px; } /* 邮箱 */

.companies-table th:nth-child(5),
.companies-table td:nth-child(5) { width: 20%; min-width: 150px; } /* 地址 */

.companies-table th:nth-child(6),
.companies-table td:nth-child(6) { width: 8%; min-width: 60px; } /* 项目数 */

.companies-table th:nth-child(7),
.companies-table td:nth-child(7) { width: 20%; min-width: 120px; } /* 操作 */

/* 响应式调整 */
@media (max-width: 768px) {
    .companies-table th:nth-child(1),
    .companies-table td:nth-child(1) { width: 25%; min-width: 100px; }
    
    .companies-table th:nth-child(2),
    .companies-table td:nth-child(2) { width: 15%; min-width: 60px; }
    
    .companies-table th:nth-child(3),
    .companies-table td:nth-child(3) { width: 20%; min-width: 80px; }
    
    .companies-table th:nth-child(4),
    .companies-table td:nth-child(4) { width: 20%; min-width: 100px; }
    
    .companies-table th:nth-child(5),
    .companies-table td:nth-child(5) { width: 0; min-width: 0; display: none; } /* 隐藏地址列 */
    
    .companies-table th:nth-child(6),
    .companies-table td:nth-child(6) { width: 10%; min-width: 50px; }
    
    .companies-table th:nth-child(7),
    .companies-table td:nth-child(7) { width: 30%; min-width: 100px; }
}

@media (max-width: 576px) {
    .companies-table th:nth-child(2),
    .companies-table td:nth-child(2) { width: 0; min-width: 0; display: none; } /* 隐藏联系人列 */
    
    .companies-table th:nth-child(3),
    .companies-table td:nth-child(3) { width: 25%; min-width: 70px; }
    
    .companies-table th:nth-child(4),
    .companies-table td:nth-child(4) { width: 0; min-width: 0; display: none; } /* 隐藏邮箱列 */
    
    .companies-table th:nth-child(6),
    .companies-table td:nth-child(6) { width: 15%; min-width: 40px; }
}
</style>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-building"></i> 公司管理</h5>
                </div>
                <div class="card-body">
                    <?php if ($message): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($message); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?php echo htmlspecialchars($error); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="mb-0">公司列表</h6>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCompanyModal">
                            <i class="bi bi-plus-circle"></i> 添加公司
                        </button>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-hover companies-table">
                            <thead>
                                <tr>
                                    <th>公司名称</th>
                                    <th>联系人</th>
                                    <th>电话</th>
                                    <th>邮箱</th>
                                    <th>地址</th>
                                    <th>项目数</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($companies as $company): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($company['name']); ?></td>
                                        <td><?php echo htmlspecialchars($company['contact_person']); ?></td>
                                        <td><?php echo htmlspecialchars($company['phone']); ?></td>
                                        <td><?php echo htmlspecialchars($company['email']); ?></td>
                                        <td><?php echo htmlspecialchars($company['address']); ?></td>
                                        <td><?php echo $company['project_count']; ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <button type="button" class="btn btn-outline-primary" onclick="editCompany(<?php echo $company['id']; ?>)">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-danger" onclick="deleteCompany(<?php echo $company['id']; ?>, '<?php echo htmlspecialchars($company['name']); ?>')">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
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

<!-- 添加公司模态框 -->
<div class="modal fade" id="addCompanyModal" tabindex="-1" aria-labelledby="addCompanyModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title" id="addCompanyModalLabel">添加公司</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">公司名称 *</label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="contact_person" class="form-label">联系人</label>
                        <input type="text" class="form-control" id="contact_person" name="contact_person">
                    </div>
                    <div class="mb-3">
                        <label for="phone" class="form-label">电话</label>
                        <input type="text" class="form-control" id="phone" name="phone">
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">邮箱</label>
                        <input type="email" class="form-control" id="email" name="email">
                    </div>
                    <div class="mb-3">
                        <label for="address" class="form-label">地址</label>
                        <textarea class="form-control" id="address" name="address" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">添加</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 编辑公司模态框 -->
<div class="modal fade" id="editCompanyModal" tabindex="-1" aria-labelledby="editCompanyModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_company_id">
                <div class="modal-header">
                    <h5 class="modal-title" id="editCompanyModalLabel">编辑公司</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">公司名称 *</label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_contact_person" class="form-label">联系人</label>
                        <input type="text" class="form-control" id="edit_contact_person" name="contact_person">
                    </div>
                    <div class="mb-3">
                        <label for="edit_phone" class="form-label">电话</label>
                        <input type="text" class="form-control" id="edit_phone" name="phone">
                    </div>
                    <div class="mb-3">
                        <label for="edit_email" class="form-label">邮箱</label>
                        <input type="email" class="form-control" id="edit_email" name="email">
                    </div>
                    <div class="mb-3">
                        <label for="edit_address" class="form-label">地址</label>
                        <textarea class="form-control" id="edit_address" name="address" rows="3"></textarea>
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

<script>
function editCompany(id) {
    // 获取公司信息并填充到编辑模态框
    fetch(`api/projects/api_get_company.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('edit_company_id').value = data.company.id;
                document.getElementById('edit_name').value = data.company.name;
                document.getElementById('edit_contact_person').value = data.company.contact_person;
                document.getElementById('edit_phone').value = data.company.phone;
                document.getElementById('edit_email').value = data.company.email;
                document.getElementById('edit_address').value = data.company.address;
                // 显示模态框
                var editModal = new bootstrap.Modal(document.getElementById('editCompanyModal'));
                editModal.show();
            } else {
                alert('获取公司信息失败：' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('获取公司信息时发生错误');
        });
}

function deleteCompany(id, name) {
    if (confirm(`确定要删除公司 "${name}" 吗？此操作不可恢复！`)) {
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
</script>

<?php include 'includes/footer.php'; ?>