<?php
// 启动会话
session_start();

// 检查用户是否已登录
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 检查是否是管理员会话（来自用户系统）
$is_user_admin = strtolower(trim($_SESSION['role'] ?? '')) === 'admin';

// 设置页面特定变量
$page_title = '编辑人员信息';
$active_page = 'personnel';
$show_page_title = '编辑人员信息';
$page_icon = 'person-plus-fill';

// 启动输出缓冲
ob_start();

// 包含统一头部文件
include('includes/header.php');

// 获取数据库连接
$database = new Database();
$pdo = $database->getConnection();

// 获取人员ID
$personnel_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($personnel_id <= 0) {
    header("Location: personnel.php");
    exit;
}

// 处理表单提交
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'edit') {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $id_card = trim($_POST['id_card']);
        $gender = trim($_POST['gender']);
        $meal_allowance = isset($_POST['meal_allowance']) ? floatval($_POST['meal_allowance']) : 100.00;
        $badge_type = trim($_POST['badge_type'] ?? ''); // 获取工作证类型
        
        if (empty($name)) {
            $error = '姓名不能为空！';
        } elseif (empty($gender)) {
            $error = '请选择性别！';
        } else {
            try {
                $pdo->beginTransaction();
                
                // 更新人员基本信息，包括餐费补助金额
                $query = "UPDATE personnel SET name = :name, email = :email, phone = :phone, 
                         id_card = :id_card, gender = :gender, meal_allowance = :meal_allowance WHERE id = :id";
                $stmt = $pdo->prepare($query);
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':email', $email);
                $stmt->bindParam(':phone', $phone);
                $stmt->bindParam(':id_card', $id_card);
                $stmt->bindParam(':gender', $gender);
                $stmt->bindParam(':meal_allowance', $meal_allowance);
                $stmt->bindParam(':id', $personnel_id);
                $stmt->execute();
                
                // 如果选择了项目部门，更新关联（包括工作证类型）
                if (isset($_POST['department_id']) && $_POST['department_id'] > 0) {
                    $project_id = $_SESSION['project_id'] ?? 0;
                    $department_id = intval($_POST['department_id']);
                    $position = trim($_POST['position'] ?? '');
                    
                    if ($project_id > 0) {
                        // 先删除现有的关联
                        $delete_query = "DELETE FROM project_department_personnel 
                                       WHERE personnel_id = :personnel_id AND project_id = :project_id";
                        $delete_stmt = $pdo->prepare($delete_query);
                        $delete_stmt->bindParam(':personnel_id', $personnel_id);
                        $delete_stmt->bindParam(':project_id', $project_id);
                        $delete_stmt->execute();
                        
                        // 添加新的部门关联（包括工作证类型）
                        $query = "INSERT INTO project_department_personnel (project_id, department_id, personnel_id, position, badge_type) 
                                 VALUES (:project_id, :department_id, :personnel_id, :position, :badge_type)";
                        $stmt = $pdo->prepare($query);
                        $stmt->bindParam(':project_id', $project_id);
                        $stmt->bindParam(':department_id', $department_id);
                        $stmt->bindParam(':personnel_id', $personnel_id);
                        $stmt->bindParam(':position', $position);
                        $stmt->bindParam(':badge_type', $badge_type); // 绑定工作证类型
                        $stmt->execute();
                    }
                }
                
                $pdo->commit();
                $message = '人员信息更新成功！';
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = '更新人员信息失败：' . $e->getMessage();
            }
        }
    }
}

// 获取人员信息
try {
    $query = "SELECT id, name, email, phone, id_card, gender, created_at, meal_allowance 
              FROM personnel WHERE id = :id";
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':id', $personnel_id);
    $stmt->execute();
    $person = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$person) {
        header("Location: personnel.php");
        exit;
    }
    
    // 如果没有设置餐费补助金额，使用默认值100
    if (!isset($person['meal_allowance']) || $person['meal_allowance'] === null) {
        $person['meal_allowance'] = 100.00;
    }
    
    // 获取当前项目部门信息和工作证类型
    $project_id = $_SESSION['project_id'] ?? 0;
    $current_assignment = null;
    $current_badge_type = null;
    if ($project_id > 0) {
        $query = "SELECT pdp.department_id, pdp.position, pdp.badge_type, d.name as department_name
                  FROM project_department_personnel pdp
                  JOIN departments d ON pdp.department_id = d.id
                  WHERE pdp.personnel_id = :personnel_id AND pdp.project_id = :project_id";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':personnel_id', $personnel_id);
        $stmt->bindParam(':project_id', $project_id);
        $stmt->execute();
        $current_assignment = $stmt->fetch(PDO::FETCH_ASSOC);
        $current_badge_type = $current_assignment['badge_type'] ?? null;
    }
    
    // 获取项目下的部门列表
    $departments = [];
    if ($project_id > 0) {
        $query = "SELECT id, name FROM departments WHERE project_id = :project_id ORDER BY name";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':project_id', $project_id);
        $stmt->execute();
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 获取项目的工作证类型选项
    $project_badge_types = [];
    if ($project_id > 0) {
        $badge_stmt = $pdo->prepare("SELECT badge_types FROM projects WHERE id = ?");
        $badge_stmt->execute([$project_id]);
        $project_result = $badge_stmt->fetch(PDO::FETCH_ASSOC);
        if ($project_result && !empty($project_result['badge_types'])) {
            $project_badge_types = explode(',', $project_result['badge_types']);
            $project_badge_types = array_map('trim', $project_badge_types);
        }
    }
    
} catch (PDOException $e) {
    $error = "获取人员信息失败: " . $e->getMessage();
    $person = null;
}
?>

<!-- 页面标题 -->
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1>
                    <i class="bi bi-<?php echo $page_icon; ?>"></i> 
                    编辑人员信息
                </h1>
                <div>
                    <a href="personnel_view.php?id=<?php echo $person['id']; ?>" class="btn btn-info me-2">
                        <i class="bi bi-eye"></i> 查看详情
                    </a>
                    <a href="personnel.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> 返回列表
                    </a>
                </div>
            </div>

                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($message); ?>
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
                        <h5 class="card-title mb-0">
                            <i class="bi bi-person-lines-fill"></i> 基本信息
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="edit">
                            <input type="hidden" name="id" value="<?php echo $person['id']; ?>">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="name" class="form-label">
                                            <i class="bi bi-person"></i> 姓名 <span class="text-danger">*</span>
                                        </label>
                                        <input type="text" class="form-control" id="name" name="name" 
                                               value="<?php echo htmlspecialchars($person['name']); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="gender" class="form-label">
                                            <i class="bi bi-gender-ambiguous"></i> 性别 <span class="text-danger">*</span>
                                        </label>
                                        <select class="form-select" id="gender" name="gender" required>
                                            <option value="" <?php echo empty($person['gender']) ? 'selected' : ''; ?>>请选择</option>
                                            <option value="男" <?php echo $person['gender'] === '男' ? 'selected' : ''; ?>>男</option>
                                            <option value="女" <?php echo $person['gender'] === '女' ? 'selected' : ''; ?>>女</option>
                                        </select>
                                        <div class="invalid-feedback">
                                            请选择性别
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="id_card" class="form-label">
                                            <i class="bi bi-credit-card-2-front"></i> 身份证号码
                                        </label>
                                        <input type="text" class="form-control" id="id_card" name="id_card" 
                                               value="<?php echo htmlspecialchars($person['id_card']); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="phone" class="form-label">
                                            <i class="bi bi-telephone"></i> 联系电话
                                        </label>
                                        <input type="tel" class="form-control" id="phone" name="phone" 
                                               value="<?php echo htmlspecialchars($person['phone']); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="email" class="form-label">
                                    <i class="bi bi-envelope"></i> 电子邮箱
                                </label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($person['email']); ?>">
                            </div>
                            
                            <!-- 添加每日餐费补助金额字段 -->
                            <div class="mb-3">
                                <label for="meal_allowance" class="form-label">
                                    <i class="bi bi-cash"></i> 每日餐费补助金额（元）
                                </label>
                                <input type="number" class="form-control" id="meal_allowance" name="meal_allowance" 
                                       value="<?php echo isset($person['meal_allowance']) ? htmlspecialchars($person['meal_allowance']) : '100.00'; ?>" 
                                       min="0" step="0.01" placeholder="请输入每日餐费补助金额">
                                <div class="form-text">默认为100元，用于计算餐费补助总额</div>
                            </div>
                            
                            <?php if ($project_id > 0 && !empty($departments)): ?>
                                <hr>
                                <h6 class="mb-3">
                                    <i class="bi bi-building"></i> 项目部门信息
                                </h6>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="department_id" class="form-label">
                                                <i class="bi bi-diagram-3"></i> 所属部门
                                            </label>
                                            <select class="form-select" id="department_id" name="department_id">
                                                <option value="">不分配部门</option>
                                                <?php foreach ($departments as $dept): ?>
                                                    <option value="<?php echo $dept['id']; ?>" 
                                                            <?php echo ($current_assignment && $current_assignment['department_id'] == $dept['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($dept['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="position" class="form-label">
                                                <i class="bi bi-briefcase"></i> 职位
                                            </label>
                                            <input type="text" class="form-control" id="position" name="position" 
                                                   value="<?php echo htmlspecialchars($current_assignment['position'] ?? ''); ?>"
                                                   placeholder="请输入职位">
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- 工作证类型 -->
                                <?php if (!empty($project_badge_types)): ?>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="badge_type" class="form-label">
                                                    <i class="bi bi-card-checklist"></i> 工作证类型
                                                </label>
                                                <select class="form-select" id="badge_type" name="badge_type">
                                                    <option value="">请选择工作证类型</option>
                                                    <?php foreach ($project_badge_types as $badge): ?>
                                                        <option value="<?php echo htmlspecialchars($badge); ?>"
                                                                <?php echo ($current_badge_type === $badge) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($badge); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save"></i> 保存修改
                                </button>
                                <a href="personnel.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left"></i> 返回列表
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card mt-4">
                    <div class="card-header">
                        <h6 class="card-title mb-0">
                            <i class="bi bi-info-circle"></i> 人员信息
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>创建时间：</strong><?php echo $person['created_at']; ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>人员ID：</strong><?php echo $person['id']; ?></p>
                            </div>
                        </div>
                        
                        <?php if ($current_assignment): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i>
                                <strong>当前分配：</strong>
                                部门：<?php echo htmlspecialchars($current_assignment['department_name']); ?>
                                <?php if (!empty($current_assignment['position'])): ?>
                                    ，职位：<?php echo htmlspecialchars($current_assignment['position']); ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php 
// 包含统一底部文件
include 'includes/footer.php';;
?>