<?php
require_once '../config/database.php';
require_once '../includes/functions.php';

session_start();
requireLogin();
checkProjectPermission($_SESSION['project_id']);

$projectId = $_SESSION['project_id'];
$action = $_GET['action'] ?? 'list';
$id = $_GET['id'] ?? 0;

// 获取项目人员
$personnel = getProjectPersonnel($projectId, $db);

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($action == 'add') {
        // 新增报餐
        $personnel_id = $_POST['personnel_id'];
        $meal_date = $_POST['meal_date'];
        $meal_type = $_POST['meal_type'];
        $meal_count = $_POST['meal_count'];
        $special_requirements = $_POST['special_requirements'];
        
        $query = "INSERT INTO meal_reports (project_id, personnel_id, meal_date, meal_type, meal_count, special_requirements, reported_by) 
                  VALUES (:project_id, :personnel_id, :meal_date, :meal_type, :meal_count, :special_requirements, :reported_by)";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':project_id', $projectId);
        $stmt->bindParam(':personnel_id', $personnel_id);
        $stmt->bindParam(':meal_date', $meal_date);
        $stmt->bindParam(':meal_type', $meal_type);
        $stmt->bindParam(':meal_count', $meal_count);
        $stmt->bindParam(':special_requirements', $special_requirements);
        $stmt->bindParam(':reported_by', $_SESSION['user_id']);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "报餐记录添加成功";
            header("Location: meals.php");
            exit;
        }
    } elseif ($action == 'edit' && $id) {
        // 编辑报餐
        $personnel_id = $_POST['personnel_id'];
        $meal_date = $_POST['meal_date'];
        $meal_type = $_POST['meal_type'];
        $meal_count = $_POST['meal_count'];
        $special_requirements = $_POST['special_requirements'];
        
        $query = "UPDATE meal_reports SET personnel_id = :personnel_id, meal_date = :meal_date, 
                  meal_type = :meal_type, meal_count = :meal_count, special_requirements = :special_requirements 
                  WHERE id = :id AND project_id = :project_id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':personnel_id', $personnel_id);
        $stmt->bindParam(':meal_date', $meal_date);
        $stmt->bindParam(':meal_type', $meal_type);
        $stmt->bindParam(':meal_count', $meal_count);
        $stmt->bindParam(':special_requirements', $special_requirements);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':project_id', $projectId);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "报餐记录更新成功";
            header("Location: meals.php");
            exit;
        }
    } elseif ($action == 'delete' && $id) {
        // 删除报餐
        $query = "DELETE FROM meal_reports WHERE id = :id AND project_id = :project_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->bindParam(':project_id', $projectId);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = "报餐记录删除成功";
        }
        header("Location: meals.php");
        exit;
    }
}

// 获取单个记录（编辑时）
$meal = null;
if ($action == 'edit' && $id) {
    $query = "SELECT * FROM meal_reports WHERE id = :id AND project_id = :project_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $id);
    $stmt->bindParam(':project_id', $projectId);
    $stmt->execute();
    $meal = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 获取报餐记录
$date_filter = $_GET['date'] ?? '';
$meals = getMealReports($projectId, $db, $date_filter);

// 显示消息
$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);
?>
<?php
// 设置页面特定变量
$page_title = '报餐管理 - ' . ($_SESSION['project_name'] ?? '项目');
$active_page = 'meals';
$show_page_title = '报餐管理';
$page_icon = 'cup-hot';
$page_action_text = '新增报餐';
$page_action_url = 'meals.php?action=add';

// 包含统一头部文件
include 'includes/header.php';
?>
    <div class="container mt-4">
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($action == 'add' || $action == 'edit'): ?>
            <!-- 表单页面 -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><?php echo $action == 'add' ? '新增报餐' : '编辑报餐'; ?></h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="personnel_id" class="form-label">选择人员 *</label>
                                    <select class="form-select" id="personnel_id" name="personnel_id" required>
                                        <option value="">请选择人员</option>
                                        <?php foreach ($personnel as $person): ?>
                                            <option value="<?php echo $person['id']; ?>" 
                                                <?php echo ($meal && $meal['personnel_id'] == $person['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($person['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="meal_date" class="form-label">用餐日期 *</label>
                                    <input type="date" class="form-control" id="meal_date" name="meal_date" 
                                           value="<?php echo $meal ? $meal['meal_date'] : date('Y-m-d'); ?>" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="meal_type" class="form-label">用餐类型 *</label>
                                    <select class="form-select" id="meal_type" name="meal_type" required>
                                        <option value="早餐" <?php echo ($meal && $meal['meal_type'] == '早餐') ? 'selected' : ''; ?>>早餐</option>
                                        <option value="午餐" <?php echo ($meal && $meal['meal_type'] == '午餐') ? 'selected' : ''; ?>>午餐</option>
                                        <option value="晚餐" <?php echo ($meal && $meal['meal_type'] == '晚餐') ? 'selected' : ''; ?>>晚餐</option>
                                        <option value="宵夜" <?php echo ($meal && $meal['meal_type'] == '宵夜') ? 'selected' : ''; ?>>宵夜</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="meal_count" class="form-label">用餐人数 *</label>
                                    <input type="number" class="form-control" id="meal_count" name="meal_count" 
                                           min="1" value="<?php echo $meal ? $meal['meal_count'] : '1'; ?>" required>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="special_requirements" class="form-label">特殊要求</label>
                            <textarea class="form-control" id="special_requirements" name="special_requirements" 
                                      rows="3"><?php echo $meal ? htmlspecialchars($meal['special_requirements']) : ''; ?></textarea>
                        </div>
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="meals.php" class="btn btn-secondary">取消</a>
                            <button type="submit" class="btn btn-primary">
                                <?php echo $action == 'add' ? '新增' : '更新'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <!-- 列表页面 -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">报餐记录</h5>
                    <div class="d-flex gap-2">
                        <input type="date" class="form-control form-control-sm" id="dateFilter" 
                               value="<?php echo $date_filter; ?>" onchange="filterByDate()">
                        <a href="meals.php?action=add" class="btn btn-primary btn-sm">
                            <i class="bi bi-plus"></i> 新增报餐
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($meals)): ?>
                        <p class="text-muted">暂无报餐记录</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>人员</th>
                                        <th>用餐日期</th>
                                        <th>用餐类型</th>
                                        <th>人数</th>
                                        <th>特殊要求</th>
                                        <th>状态</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($meals as $meal): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($meal['personnel_name']); ?></td>
                                            <td><?php echo $meal['meal_date']; ?></td>
                                            <td>
                                                <span class="badge bg-info"><?php echo $meal['meal_type']; ?></span>
                                            </td>
                                            <td><?php echo $meal['meal_count']; ?></td>
                                            <td><?php echo htmlspecialchars($meal['special_requirements']); ?></td>
                                            <td>
                                                <?php 
                                                $status_class = [
                                                    'pending' => 'warning',
                                                    'confirmed' => 'success',
                                                    'cancelled' => 'danger'
                                                ];
                                                $status_text = [
                                                    'pending' => '待确认',
                                                    'confirmed' => '已确认',
                                                    'cancelled' => '已取消'
                                                ];
                                                ?>
                                                <span class="badge bg-<?php echo $status_class[$meal['status']]; ?>">
                                                    <?php echo $status_text[$meal['status']]; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="meals.php?action=edit&id=<?php echo $meal['id']; ?>" 
                                                   class="btn btn-sm btn-warning">编辑</a>
                                                <a href="meals.php?action=delete&id=<?php echo $meal['id']; ?>" 
                                                   class="btn btn-sm btn-danger" 
                                                   onclick="return confirm('确定要删除这条记录吗？')">删除</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <script>
        function filterByDate() {
            const date = document.getElementById('dateFilter').value;
            const url = new URL(window.location);
            if (date) {
                url.searchParams.set('date', date);
            } else {
                url.searchParams.delete('date');
            }
            window.location = url;
        }
    </script>
    <?php include 'includes/footer.php'; ?>
</body>
</html>
