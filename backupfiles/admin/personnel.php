<?php
session_start();
require_once '../config/database.php';
require_once 'page_functions.php';

// 检查登录状态
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
            // 添加人员
            $name = trim($_POST['name']);
            $email = trim($_POST['email']);
            $phone = trim($_POST['phone']);
            $id_card = trim($_POST['id_card']);
            $gender = $_POST['gender'];
            
            if (empty($name)) {
                $error = '姓名不能为空！';
            } else {
                try {
                    $db->beginTransaction();
                    
                    // 添加人员基本信息
                    $query = "INSERT INTO personnel (name, email, phone, id_card, gender) 
                             VALUES (:name, :email, :phone, :id_card, :gender)";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':name', $name);
                    $stmt->bindParam(':email', $email);
                    $stmt->bindParam(':phone', $phone);
                    $stmt->bindParam(':id_card', $id_card);
                    $stmt->bindParam(':gender', $gender);
                    $stmt->execute();
                    
                    $personnel_id = $db->lastInsertId();
                    
                    // 如果选择了项目和部门，添加关联
                    if (!empty($_POST['project_id']) && !empty($_POST['department_ids'])) {
                        $project_id = $_POST['project_id'];
                        $position = $_POST['position'] ?? '';
                        $department_ids = explode(',', $_POST['department_ids']);
                        
                        foreach ($department_ids as $department_id) {
                            $department_id = intval($department_id);
                            if ($department_id > 0) {
                                $query = "INSERT INTO project_department_personnel (project_id, department_id, personnel_id, position) 
                                         VALUES (:project_id, :department_id, :personnel_id, :position)";
                                $stmt = $db->prepare($query);
                                $stmt->bindParam(':project_id', $project_id);
                                $stmt->bindParam(':department_id', $department_id);
                                $stmt->bindParam(':personnel_id', $personnel_id);
                                $stmt->bindParam(':position', $position);
                                $stmt->execute();
                            }
                        }
                    }
                    
                    $db->commit();
                    $message = '人员添加成功！';
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = '添加人员失败：' . $e->getMessage();
                }
            }
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            // 删除人员
            $id = intval($_POST['id']);
            
            // 检查是否有项目关联
            $check_query = "SELECT COUNT(*) FROM project_department_personnel WHERE personnel_id = :personnel_id";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':personnel_id', $id);
            $check_stmt->execute();
            $project_count = $check_stmt->fetchColumn();
            
            if ($project_count > 0) {
                $error = '该人员已关联项目，无法删除！';
            } else {
                $query = "DELETE FROM personnel WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $id);
                
                if ($stmt->execute()) {
                    $message = '人员删除成功！';
                } else {
                    $error = '删除人员失败，请重试！';
                }
            }
        }
    }
}

// 获取数据
$companies = [];
$projects = [];
$departments = [];

try {
    $query = "SELECT id, name FROM companies ORDER BY name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $query = "SELECT id, name, company_id FROM projects ORDER BY name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $query = "SELECT id, name, project_id FROM departments ORDER BY name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = '数据加载失败：' . $e->getMessage();
}

// 获取人员列表
$query = "SELECT 
            p.id,
            p.name,
            p.gender,
            p.phone,
            p.email,
            p.id_card,
            p.created_at,
            COUNT(DISTINCT pdp.project_id) as project_count,
            GROUP_CONCAT(DISTINCT CONCAT(p2.name, '|', d.name, '|', pdp.position) SEPARATOR ';') as project_details,
            CASE WHEN ppu.personnel_id IS NOT NULL THEN 1 ELSE 0 END as is_project_user
         FROM personnel p 
         LEFT JOIN project_department_personnel pdp ON p.id = pdp.personnel_id 
         LEFT JOIN departments d ON pdp.department_id = d.id
         LEFT JOIN projects p2 ON pdp.project_id = p2.id
         LEFT JOIN personnel_project_users ppu ON p.id = ppu.personnel_id
         GROUP BY p.id, p.name, p.gender, p.phone, p.email, p.id_card, p.created_at, is_project_user
         ORDER BY p.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute();
$personnel = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">人员列表</h5>
            <div>
                <a href="add_personnel.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> 新增人员
                </a>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($personnel)): ?>
                <div class="text-center py-4">
                    <i class="bi bi-inbox display-1 text-muted"></i>
                    <h5 class="text-muted">暂无人员</h5>
                    <p class="text-muted">请先添加人员</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>姓名</th>
                                <th>性别</th>
                                <th>身份证</th>
                                <th>项目/部门/职位</th>
                                <th>项目数量</th>
                                <th>是否项目用户</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($personnel as $person): ?>
                                <tr>
                                    <td><?php echo $person['id']; ?></td>
                                    <td><?php echo htmlspecialchars($person['name']); ?></td>
                                    <td><?php echo htmlspecialchars($person['gender'] ?? '未知'); ?></td>
                                    <td><small class="text-muted"><?php echo htmlspecialchars($person['id_card'] ?: '-'); ?></small></td>
                                    <td>
                                        <?php 
                                        if (!empty($person['project_details'])) {
                                            $details = explode(';', $person['project_details']);
                                            $displayed = 0;
                                            foreach ($details as $detail) {
                                                if ($displayed >= 2) {
                                                    echo '<small class="text-muted">...等' . (count($details) - 2) . '个项目</small>';
                                                    break;
                                                }
                                                list($project, $dept, $position) = explode('|', $detail);
                                                echo '<div class="project-details mb-1">';
                                                echo '<strong>' . htmlspecialchars($project) . '</strong> - ';
                                                echo '<span class="badge bg-info">' . htmlspecialchars($dept) . '</span> ';
                                                echo '<span class="badge bg-secondary">' . htmlspecialchars($position) . '</span>';
                                                echo '</div>';
                                                $displayed++;
                                            }
                                        } else {
                                            echo '<span class="text-muted">-</span>';
                                        }
                                        ?>
                                    </td>
                                    <td><span class="badge bg-primary"><?php echo $person['project_count']; ?></span></td>
                                    <td>
                                        <?php if ($person['is_project_user']): ?>
                                            <span class="badge bg-success">是</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">否</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <a href="edit_personnel.php?id=<?php echo $person['id']; ?>" class="btn btn-sm btn-warning" title="编辑">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-danger" onclick="deletePerson(<?php echo $person['id']; ?>)" title="删除">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    // 删除人员
    function deletePerson(id) {
        if (confirm('确定要删除该人员吗？此操作不可恢复！')) {
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