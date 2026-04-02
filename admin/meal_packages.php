<?php
session_start();

// 引入基础控制器进行权限验证
require_once '../includes/BaseAdminController.php';
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

$message = '';
$error = '';
$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? 0;

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add' || $action === 'edit') {
        $project_id = $_POST['project_id'];
        $name = $_POST['name'];
        $meal_type = $_POST['meal_type'];
        $description = $_POST['description'];
        $price = $_POST['price'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        try {
            $db->beginTransaction();
            
            if ($action === 'add') {
                // 新增套餐
                $query = "INSERT INTO meal_packages (project_id, name, meal_type, description, price, is_active) 
                         VALUES (:project_id, :name, :meal_type, :description, :price, :is_active)";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':project_id', $project_id);
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':meal_type', $meal_type);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':price', $price);
                $stmt->bindParam(':is_active', $is_active);
                $stmt->execute();
                $package_id = $db->lastInsertId();
                $message = '套餐添加成功！';
            } else {
                // 编辑套餐
                $query = "UPDATE meal_packages SET project_id = :project_id, name = :name, meal_type = :meal_type, 
                         description = :description, price = :price, is_active = :is_active 
                         WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':project_id', $project_id);
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':meal_type', $meal_type);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':price', $price);
                $stmt->bindParam(':is_active', $is_active);
                $stmt->bindParam(':id', $id);
                $stmt->execute();
                $package_id = $id;
                $message = '套餐更新成功！';
            }
            
            // 处理套餐项目
            if (isset($_POST['items']) && is_array($_POST['items'])) {
                // 删除现有的套餐项目
                $delete_query = "DELETE FROM meal_package_items WHERE package_id = :package_id";
                $delete_stmt = $db->prepare($delete_query);
                $delete_stmt->bindParam(':package_id', $package_id);
                $delete_stmt->execute();
                
                // 添加新的套餐项目
                $item_query = "INSERT INTO meal_package_items (package_id, item_name, item_description, quantity, unit, sort_order) 
                              VALUES (:package_id, :item_name, :item_description, :quantity, :unit, :sort_order)";
                $item_stmt = $db->prepare($item_query);
                
                foreach ($_POST['items'] as $index => $item) {
                    if (!empty($item['item_name'])) {
                        $item_stmt->bindParam(':package_id', $package_id);
                        $item_stmt->bindParam(':item_name', $item['item_name']);
                        $item_stmt->bindParam(':item_description', $item['item_description']);
                        $item_stmt->bindParam(':quantity', $item['quantity']);
                        $item_stmt->bindParam(':unit', $item['unit']);
                        $item_stmt->bindValue(':sort_order', $index + 1);
                        $item_stmt->execute();
                    }
                }
            }
            
            $db->commit();
            
            // 重定向到套餐所属的项目页面
            header("Location: meal_packages.php?project_id=" . $project_id . "&success=1");
            exit;
            
        } catch (Exception $e) {
            $db->rollback();
            $error = '操作失败：' . $e->getMessage();
        }
    } elseif ($action === 'delete') {
        try {
            $db->beginTransaction();
            
            // 先获取套餐的项目ID，用于删除后重定向
            $get_project_query = "SELECT project_id FROM meal_packages WHERE id = :id";
            $get_project_stmt = $db->prepare($get_project_query);
            $get_project_stmt->bindParam(':id', $id);
            $get_project_stmt->execute();
            $package_project = $get_project_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$package_project) {
                throw new Exception('套餐不存在');
            }
            
            $package_project_id = $package_project['project_id'];
            
            // 删除套餐项目
            $delete_items_query = "DELETE FROM meal_package_items WHERE package_id = :id";
            $delete_items_stmt = $db->prepare($delete_items_query);
            $delete_items_stmt->bindParam(':id', $id);
            $delete_items_stmt->execute();
            
            // 删除套餐
            $delete_query = "DELETE FROM meal_packages WHERE id = :id";
            $delete_stmt = $db->prepare($delete_query);
            $delete_stmt->bindParam(':id', $id);
            $delete_stmt->execute();
            
            $db->commit();
            
            // 删除成功后重定向到对应项目的套餐管理页面
            header("Location: meal_packages.php?project_id=" . $package_project_id . "&deleted=1");
            exit;
            
        } catch (Exception $e) {
            $db->rollback();
            $error = '删除失败：' . $e->getMessage();
        }
    } elseif ($action === 'copy') {
        // 复制套餐功能
        $source_id = $_POST['source_id'] ?? $id;
        $target_project_id = $_POST['target_project_id'];
        $target_meal_type = $_POST['target_meal_type'];
        $new_name = $_POST['new_name'];
        
        try {
            $db->beginTransaction();
            
            // 获取源套餐信息
            $source_query = "SELECT * FROM meal_packages WHERE id = :id";
            $source_stmt = $db->prepare($source_query);
            $source_stmt->bindParam(':id', $source_id);
            $source_stmt->execute();
            $source_package = $source_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$source_package) {
                throw new Exception('源套餐不存在');
            }
            
            // 创建新套餐
            $copy_query = "INSERT INTO meal_packages (project_id, name, meal_type, description, price, is_active) 
                          VALUES (:project_id, :name, :meal_type, :description, :price, :is_active)";
            $copy_stmt = $db->prepare($copy_query);
            $copy_stmt->bindParam(':project_id', $target_project_id);
            $copy_stmt->bindParam(':name', $new_name);
            $copy_stmt->bindParam(':meal_type', $target_meal_type);
            $copy_stmt->bindParam(':description', $source_package['description']);
            $copy_stmt->bindParam(':price', $source_package['price']);
            $copy_stmt->bindParam(':is_active', $source_package['is_active']);
            $copy_stmt->execute();
            $new_package_id = $db->lastInsertId();
            
            // 复制套餐项目
            $items_query = "SELECT * FROM meal_package_items WHERE package_id = :package_id ORDER BY sort_order";
            $items_stmt = $db->prepare($items_query);
            $items_stmt->bindParam(':package_id', $source_id);
            $items_stmt->execute();
            $source_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if ($source_items) {
                $copy_item_query = "INSERT INTO meal_package_items (package_id, item_name, item_description, quantity, unit, sort_order) 
                                   VALUES (:package_id, :item_name, :item_description, :quantity, :unit, :sort_order)";
                $copy_item_stmt = $db->prepare($copy_item_query);
                
                foreach ($source_items as $item) {
                    $copy_item_stmt->bindParam(':package_id', $new_package_id);
                    $copy_item_stmt->bindParam(':item_name', $item['item_name']);
                    $copy_item_stmt->bindParam(':item_description', $item['item_description']);
                    $copy_item_stmt->bindParam(':quantity', $item['quantity']);
                    $copy_item_stmt->bindParam(':unit', $item['unit']);
                    $copy_item_stmt->bindParam(':sort_order', $item['sort_order']);
                    $copy_item_stmt->execute();
                }
            }
            
            $db->commit();
            $message = '套餐复制成功！';
            
            // 重定向到目标项目页面
            header("Location: meal_packages.php?project_id=" . $target_project_id . "&success=1");
            exit;
            
        } catch (Exception $e) {
            $db->rollback();
            $error = '复制失败：' . $e->getMessage();
        }
    }
}

// 获取项目列表
$projects_query = "SELECT id, name, code FROM projects ORDER BY name ASC";
$projects_stmt = $db->prepare($projects_query);
$projects_stmt->execute();
$projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取套餐数据（编辑时）
$package = null;
$package_items = [];
if ($action === 'edit' && $id) {
    $package_query = "SELECT * FROM meal_packages WHERE id = :id";
    $package_stmt = $db->prepare($package_query);
    $package_stmt->bindParam(':id', $id);
    $package_stmt->execute();
    $package = $package_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($package) {
        $items_query = "SELECT * FROM meal_package_items WHERE package_id = :package_id ORDER BY sort_order";
        $items_stmt = $db->prepare($items_query);
        $items_stmt->bindParam(':package_id', $id);
        $items_stmt->execute();
        $package_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// 获取套餐列表
$filters = [
    'project_id' => $_GET['project_id'] ?? '',
    'meal_type' => $_GET['meal_type'] ?? '',
    'is_active' => $_GET['is_active'] ?? ''
];

$where_conditions = [];
$params = [];
$packages = []; // 默认为空数组

// 只有选择了项目才查询套餐数据
if ($filters['project_id']) {
    $where_conditions[] = "mp.project_id = :project_id";
    $params[':project_id'] = $filters['project_id'];
    
    if ($filters['meal_type']) {
        $where_conditions[] = "mp.meal_type = :meal_type";
        $params[':meal_type'] = $filters['meal_type'];
    }
    
    if ($filters['is_active'] !== '') {
        $where_conditions[] = "mp.is_active = :is_active";
        $params[':is_active'] = $filters['is_active'];
    }
    
    $where_clause = "WHERE " . implode(' AND ', $where_conditions);
    
    $packages_query = "SELECT mp.*, p.name as project_name, p.code as project_code,
                              COUNT(mpi.id) as item_count,
                              GROUP_CONCAT(
                                  CONCAT(mpi.item_name, 
                                         CASE WHEN mpi.item_description IS NOT NULL AND mpi.item_description != '' 
                                              THEN CONCAT('(', mpi.item_description, ')') 
                                              ELSE '' END,
                                         CASE WHEN mpi.quantity > 1 
                                              THEN CONCAT(' x', mpi.quantity, mpi.unit) 
                                              ELSE CONCAT(' ', IFNULL(mpi.unit, '')) END
                                        ) 
                                  ORDER BY mpi.sort_order 
                                  SEPARATOR '|'
                              ) as items_detail
                       FROM meal_packages mp
                       LEFT JOIN projects p ON mp.project_id = p.id
                       LEFT JOIN meal_package_items mpi ON mp.id = mpi.package_id
                       $where_clause
                       GROUP BY mp.id
                       ORDER BY mp.meal_type, mp.name";
    
    $packages_stmt = $db->prepare($packages_query);
    $packages_stmt->execute($params);
    $packages = $packages_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 处理成功消息
if (isset($_GET['success'])) {
    $message = '操作完成！';
} elseif (isset($_GET['deleted'])) {
    $message = '套餐删除成功！';
}

// 处理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// 设置页面标题
$page_title = '套餐管理';
?>
<?php include 'includes/header.php'; ?>

<!-- 引入优化样式 -->
<link href="assets/css/meal-reports-optimized.css" rel="stylesheet">

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
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

            <?php if ($action === 'add' || $action === 'edit'): ?>
                <!-- 套餐表单页面 -->
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-<?php echo $action === 'add' ? 'plus-circle' : 'pencil-square'; ?> me-2"></i>
                            <?php echo $action === 'add' ? '新增套餐' : '编辑套餐'; ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="packageForm">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="project_id" class="form-label">所属项目 *</label>
                                        <select class="form-select" id="project_id" name="project_id" required>
                                            <option value="">请选择项目</option>
                                            <?php foreach ($projects as $project): ?>
                                                <option value="<?php echo $project['id']; ?>" 
                                                        <?php 
                                                        $selected_project_id = $package ? $package['project_id'] : ($_GET['project_id'] ?? '');
                                                        echo ($selected_project_id == $project['id']) ? 'selected' : ''; 
                                                        ?>>
                                                    <?php echo htmlspecialchars($project['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="name" class="form-label">套餐名称 *</label>
                                        <input type="text" class="form-control" id="name" name="name" 
                                               value="<?php echo $package ? htmlspecialchars($package['name']) : ''; ?>" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="meal_type" class="form-label">餐类型 *</label>
                                        <select class="form-select" id="meal_type" name="meal_type" required>
                                            <option value="">请选择餐类型</option>
                                            <?php foreach (['早餐', '午餐', '晚餐', '宵夜'] as $type): ?>
                                                <option value="<?php echo $type; ?>" 
                                                        <?php echo ($package && $package['meal_type'] === $type) ? 'selected' : ''; ?>>
                                                    <?php echo $type; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="price" class="form-label">套餐价格 (元)</label>
                                        <input type="number" class="form-control" id="price" name="price" 
                                               step="0.01" min="0" 
                                               value="<?php echo $package ? $package['price'] : '0.00'; ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <div class="form-check form-switch mt-4">
                                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                                   <?php echo (!$package || $package['is_active']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="is_active">
                                                启用套餐
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">套餐描述</label>
                                <textarea class="form-control" id="description" name="description" rows="2" 
                                          placeholder="简要描述套餐特色和内容"><?php echo $package ? htmlspecialchars($package['description']) : ''; ?></textarea>
                            </div>
                            
                            <!-- 套餐项目管理 -->
                            <div class="mb-4">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <label class="form-label mb-0">
                                        <i class="bi bi-list-ul me-1"></i>套餐项目
                                    </label>
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="addPackageItem()">
                                        <i class="bi bi-plus"></i> 添加项目
                                    </button>
                                </div>
                                <div id="packageItems">
                                    <?php if (!empty($package_items)): ?>
                                        <?php foreach ($package_items as $index => $item): ?>
                                            <div class="item-row">
                                                <div class="row align-items-center">
                                                    <div class="col-md-3">
                                                        <input type="text" class="form-control form-control-sm" 
                                                               name="items[<?php echo $index; ?>][item_name]" 
                                                               placeholder="菜品名称" 
                                                               value="<?php echo htmlspecialchars($item['item_name']); ?>">
                                                    </div>
                                                    <div class="col-md-4">
                                                        <input type="text" class="form-control form-control-sm" 
                                                               name="items[<?php echo $index; ?>][item_description]" 
                                                               placeholder="菜品描述" 
                                                               value="<?php echo htmlspecialchars($item['item_description']); ?>">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <input type="number" class="form-control form-control-sm" 
                                                               name="items[<?php echo $index; ?>][quantity]" 
                                                               placeholder="数量" min="1" 
                                                               value="<?php echo $item['quantity']; ?>">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control form-control-sm" 
                                                               name="items[<?php echo $index; ?>][unit]" 
                                                               placeholder="单位" 
                                                               value="<?php echo htmlspecialchars($item['unit']); ?>">
                                                    </div>
                                                    <div class="col-md-1">
                                                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                                                onclick="removePackageItem(this)">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <?php $return_url = $package ? "meal_packages.php?project_id=" . $package['project_id'] : "meal_packages.php"; ?>
                                <a href="<?php echo $return_url; ?>" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left me-1"></i>返回
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check me-1"></i><?php echo $action === 'add' ? '添加套餐' : '更新套餐'; ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                <!-- 套餐列表页面 -->
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

                <?php if ($filters['project_id']): ?>
                    <!-- 套餐管理区域 -->
                    <div class="packages-container">
                        <div class="packages-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-box-seam me-2"></i>套餐管理
                                <span class="badge bg-light text-dark ms-2"><?php echo count($packages); ?></span>
                            </h5>
                            <!-- 使用更醒目的按钮样式 -->
                            <a href="meal_packages.php?action=add&project_id=<?php echo $filters['project_id']; ?>" class="btn btn-primary btn-lg">
                                <i class="bi bi-plus-circle me-1"></i>新增套餐
                            </a>
                        </div>
                        <div class="packages-body">
                            <!-- 筛选条件 -->
                            <div class="filter-row">
                                <form method="GET" class="row g-3">
                                    <input type="hidden" name="project_id" value="<?php echo $filters['project_id']; ?>">
                                    <div class="col-md-4">
                                        <label for="meal_type" class="form-label fw-semibold">餐类型</label>
                                        <select class="form-select" id="meal_type" name="meal_type">
                                            <option value="">所有类型</option>
                                            <?php foreach (['早餐', '午餐', '晚餐', '宵夜'] as $type): ?>
                                                <option value="<?php echo $type; ?>" 
                                                        <?php echo $filters['meal_type'] === $type ? 'selected' : ''; ?>>
                                                    <?php echo $type; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="is_active" class="form-label fw-semibold">状态</label>
                                        <select class="form-select" id="is_active" name="is_active">
                                            <option value="">所有状态</option>
                                            <option value="1" <?php echo $filters['is_active'] === '1' ? 'selected' : ''; ?>>启用</option>
                                            <option value="0" <?php echo $filters['is_active'] === '0' ? 'selected' : ''; ?>>禁用</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 d-flex align-items-end gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-search me-1"></i>筛选
                                        </button>
                                        <a href="meal_packages.php?project_id=<?php echo $filters['project_id']; ?>" class="btn btn-outline-secondary">重置</a>
                                    </div>
                                </form>
                            </div>

                            <!-- 增加筛选条件和套餐统计区域之间的间距 -->
                            <div class="mb-4"></div>

                            <?php if (empty($packages)): ?>
                                <div class="empty-state">
                                    <i class="bi bi-inbox display-1"></i>
                                    <h5 class="mt-3">该项目暂无套餐</h5>
                                    <p class="text-muted mb-4">点击"新增套餐"按钮为该项目创建第一个套餐</p>
                                    <!-- 使用更醒目的按钮样式 -->
                                    <a href="meal_packages.php?action=add&project_id=<?php echo $filters['project_id']; ?>" class="btn btn-primary btn-lg">
                                        <i class="bi bi-plus-circle me-1"></i>新增套餐
                                    </a>
                                </div>
                            <?php else: ?>
                                <!-- 套餐统计 -->
                                <div class="package-stats">
                                    <div class="row">
                                        <!-- 总套餐数 -->
                                        <div class="col-md-3 mb-2">
                                            <div class="card h-100 border-primary">
                                                <div class="card-body text-center py-2">
                                                    <h6 class="card-title text-muted mb-1 small">总套餐数</h6>
                                                    <h4 class="text-primary mb-0"><?php echo count($packages); ?></h4>
                                                    <div class="mt-1">
                                                        <i class="bi bi-box-seam text-primary" style="font-size: 1.2rem;"></i>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- 启用套餐 -->
                                        <div class="col-md-3 mb-2">
                                            <div class="card h-100 border-success">
                                                <div class="card-body text-center py-2">
                                                    <h6 class="card-title text-muted mb-1 small">启用套餐</h6>
                                                    <h4 class="text-success mb-0"><?php echo count(array_filter($packages, function($p) { return $p['is_active']; })); ?></h4>
                                                    <div class="mt-1">
                                                        <i class="bi bi-check-circle text-success" style="font-size: 1.2rem;"></i>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- 平均价格 -->
                                        <div class="col-md-3 mb-2">
                                            <div class="card h-100 border-warning">
                                                <div class="card-body text-center py-2">
                                                    <h6 class="card-title text-muted mb-1 small">平均价格</h6>
                                                    <h4 class="text-warning mb-0">¥<?php echo number_format(array_sum(array_column($packages, 'price')) / count($packages), 2); ?></h4>
                                                    <div class="mt-1">
                                                        <i class="bi bi-currency-dollar text-warning" style="font-size: 1.2rem;"></i>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- 总菜品项 -->
                                        <div class="col-md-3 mb-2">
                                            <div class="card h-100 border-info">
                                                <div class="card-body text-center py-2">
                                                    <h6 class="card-title text-muted mb-1 small">总菜品项</h6>
                                                    <h4 class="text-info mb-0"><?php echo array_sum(array_column($packages, 'item_count')); ?></h4>
                                                    <div class="mt-1">
                                                        <i class="bi bi-list-ul text-info" style="font-size: 1.2rem;"></i>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- 增加套餐统计和套餐表格之间的间距 -->
                                <div class="mb-3"></div>

                                <!-- 按餐类型分组显示的套餐表格 -->
                                <div class="table-container">
                                    <?php
                                    // 按餐类型分组套餐
                                    $packages_by_type = [];
                                    foreach ($packages as $pkg) {
                                        $meal_type = $pkg['meal_type'];
                                        if (!isset($packages_by_type[$meal_type])) {
                                            $packages_by_type[$meal_type] = [];
                                        }
                                        $packages_by_type[$meal_type][] = $pkg;
                                    }
                                    
                                    // 定义餐类型显示顺序
                                    $meal_type_order = ['早餐', '午餐', '晚餐', '宵夜'];
                                    ?>
                                    
                                    <?php foreach ($meal_type_order as $meal_type): ?>
                                        <?php if (isset($packages_by_type[$meal_type]) && !empty($packages_by_type[$meal_type])): ?>
                                            <!-- 餐类型分隔行 -->
                                            <div class="meal-type-divider bg-light p-3 mb-3 rounded">
                                                <h5 class="mb-0">
                                                    <i class="bi bi-collection me-2"></i>
                                                    <?php echo $meal_type; ?>
                                                    <span class="badge bg-primary ms-2"><?php echo count($packages_by_type[$meal_type]); ?></span>
                                                </h5>
                                            </div>
                                            
                                            <div class="table-responsive mb-4">
                                                <table class="table table-hover align-middle">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th scope="col" style="width: 25%;">套餐信息</th>
                                                            <th scope="col" style="width: 10%;">餐类型</th>
                                                            <th scope="col" style="width: 35%;">套餐内容</th>
                                                            <th scope="col" style="width: 10%;">价格</th>
                                                            <th scope="col" style="width: 8%;">状态</th>
                                                            <th scope="col" style="width: 12%;">操作</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($packages_by_type[$meal_type] as $pkg): ?>
                                                        <tr>
                                                            <td>
                                                                <div class="package-name fw-semibold"><?php echo htmlspecialchars($pkg['name']); ?></div>
                                                            </td>
                                                            <td>
                                                                <span class="badge bg-info"><?php echo $pkg['meal_type']; ?></span>
                                                            </td>
                                                            <td>
                                                                <?php if ($pkg['items_detail']): ?>
                                                                    <?php 
                                                                    $items = explode('|', $pkg['items_detail']);
                                                                    foreach (array_slice($items, 0, 3) as $item): ?>
                                                                        <span class="package-item-tag"><?php echo htmlspecialchars($item); ?></span>
                                                                    <?php endforeach; ?>
                                                                    <?php if (count($items) > 3): ?>
                                                                        <span class="text-muted">+<?php echo count($items) - 3; ?> 更多</span>
                                                                    <?php endif; ?>
                                                                <?php else: ?>
                                                                    <span class="text-muted">-</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <span class="fw-semibold">¥<?php echo number_format($pkg['price'], 2); ?></span>
                                                            </td>
                                                            <td>
                                                                <?php if ($pkg['is_active']): ?>
                                                                    <span class="badge bg-success">启用</span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-secondary">禁用</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <div class="btn-group btn-group-sm" role="group">
                                                                    <a href="meal_packages.php?action=edit&id=<?php echo $pkg['id']; ?>" 
                                                                       class="btn btn-outline-primary" title="编辑">
                                                                        <i class="bi bi-pencil"></i>
                                                                    </a>
                                                                    <button type="button" class="btn btn-outline-info" 
                                                                            onclick="copyPackage(<?php echo $pkg['id']; ?>)" title="复制">
                                                                        <i class="bi bi-files"></i>
                                                                    </button>
                                                                    <button type="button" class="btn btn-outline-danger" 
                                                                            onclick="deletePackage(<?php echo $pkg['id']; ?>, '<?php echo htmlspecialchars($pkg['name']); ?>')" title="删除">
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
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- 空状态 - 未选择项目 -->
                    <div class="empty-state">
                        <i class="bi bi-building display-1"></i>
                        <h5 class="mt-3">请先选择项目</h5>
                        <p class="text-muted mb-4">选择一个项目后才能查看和管理该项目的套餐信息</p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 复制套餐模态框 -->
<div class="modal fade" id="copyPackageModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">复制套餐</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="copy">
                    <input type="hidden" name="source_id" id="sourcePackageId">
                    
                    <div class="mb-3">
                        <label for="target_project_id" class="form-label">目标项目 *</label>
                        <select class="form-select" id="target_project_id" name="target_project_id" required>
                            <option value="">请选择项目</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?php echo $project['id']; ?>">
                                    <?php echo htmlspecialchars($project['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="target_meal_type" class="form-label">餐类型 *</label>
                        <select class="form-select" id="target_meal_type" name="target_meal_type" required>
                            <option value="">请选择餐类型</option>
                            <?php foreach (['早餐', '午餐', '晚餐', '宵夜'] as $type): ?>
                                <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="new_name" class="form-label">新套餐名称 *</label>
                        <input type="text" class="form-control" id="new_name" name="new_name" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">复制套餐</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// 复制套餐
function copyPackage(id) {
    document.getElementById('sourcePackageId').value = id;
    // 重置表单
    document.getElementById('target_project_id').value = '';
    document.getElementById('target_meal_type').value = '';
    document.getElementById('new_name').value = '';
    
    // 显示模态框
    const modal = new bootstrap.Modal(document.getElementById('copyPackageModal'));
    modal.show();
}

let itemIndex = <?php echo count($package_items); ?>;

function addPackageItem() {
    const container = document.getElementById('packageItems');
    const itemHtml = `
        <div class="item-row">
            <div class="row align-items-center">
                <div class="col-md-3">
                    <input type="text" class="form-control form-control-sm" 
                           name="items[${itemIndex}][item_name]" 
                           placeholder="菜品名称">
                </div>
                <div class="col-md-4">
                    <input type="text" class="form-control form-control-sm" 
                           name="items[${itemIndex}][item_description]" 
                           placeholder="菜品描述">
                </div>
                <div class="col-md-2">
                    <input type="number" class="form-control form-control-sm" 
                           name="items[${itemIndex}][quantity]" 
                           placeholder="数量" min="1" value="1">
                </div>
                <div class="col-md-2">
                    <input type="text" class="form-control form-control-sm" 
                           name="items[${itemIndex}][unit]" 
                           placeholder="单位" value="份">
                </div>
                <div class="col-md-1">
                    <button type="button" class="btn btn-sm btn-outline-danger" 
                            onclick="removePackageItem(this)">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', itemHtml);
    itemIndex++;
}

function removePackageItem(button) {
    button.closest('.item-row').remove();
}

function deletePackage(id, name) {
    if (confirm(`确定要删除套餐"${name}"吗？此操作将同时删除该套餐的所有菜品项目。`)) {
        // 创建表单并提交
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = `meal_packages.php?action=delete&id=${id}`;
        document.body.appendChild(form);
        form.submit();
    }
}

// 如果没有套餐项目，默认添加一个
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('packageItems');
    if (container && container.children.length === 0) {
        addPackageItem();
    }
});

</script>

<?php include 'includes/footer.php'; ?>
