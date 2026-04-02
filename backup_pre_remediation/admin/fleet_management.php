<?php
// 车队管理主页面
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';

// 权限检查
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

// 创建数据库连接
$database = new Database();
$pdo = $database->getConnection();

if ($pdo === null) {
    die("数据库连接失败，请检查配置");
}

// 获取项目列表
$projects = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM projects ORDER BY name");
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "获取项目列表失败: " . $e->getMessage();
}

// 获取当前项目ID
$current_project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : ($projects ? $projects[0]['id'] : 0);

// 获取筛选条件
$vehicle_type_filter = isset($_GET['vehicle_type']) ? $_GET['vehicle_type'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// 构建查询条件
$where_conditions = ["f.project_id = ?"];
$params = [$current_project_id];

if ($vehicle_type_filter) {
    $where_conditions[] = "f.vehicle_type = ?";
    $params[] = $vehicle_type_filter;
}

if ($status_filter) {
    $where_conditions[] = "f.status = ?";
    $params[] = $status_filter;
}

$where_clause = implode(' AND ', $where_conditions);

// 获取车队列表
$fleet_list = [];
try {
    $sql = "SELECT f.*, p.name as project_name 
            FROM fleet f 
            JOIN projects p ON f.project_id = p.id 
            WHERE $where_clause 
            ORDER BY f.fleet_number ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $fleet_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "获取车队列表失败: " . $e->getMessage();
}

// 车辆类型映射
$vehicle_type_map = [
    'car' => '轿车',
    'van' => '商务车',
    'minibus' => '中巴车',
    'bus' => '大巴车',
    'truck' => '货车',
    'other' => '其他'
];

// 状态映射
$status_map = [
    'active' => '正常',
    'inactive' => '停用',
    'maintenance' => '维修中'
];

?>

<?php include 'includes/header.php'; ?>

<style>
/* 车牌号码样式 - 仿真实车牌 */
.license-plate {
    display: inline-block;
    padding: 5px 10px;
    font-size: 1.1rem;
    font-weight: bold;
    color: #ffffff;
    background: #007bff;
    border: 2px solid #ffffff;
    border-radius: 5px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    min-width: 130px;
    text-align: center;
    font-family: 'Microsoft YaHei', Arial, sans-serif;
    letter-spacing: 1px;
}

/* 车牌分隔符样式 */
.license-plate .separator {
    margin: 0 2px;
    color: #ffffff;
}

/* 响应式车牌样式 */
@media (max-width: 768px) {
    .license-plate {
        font-size: 1rem;
        padding: 4px 8px;
        min-width: 110px;
    }
}

/* 确保在表格中的显示效果 */
td .license-plate {
    margin: 2px 0;
}

/* 车牌悬停效果 */
.license-plate:hover {
    box-shadow: 0 3px 6px rgba(0,0,0,0.3);
    transform: translateY(-1px);
    transition: all 0.2s ease;
}
</style>

<div class="container-fluid">
    <?php if (isset($error) && $error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i> <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-list"></i> 车队列表</h5>
            <a href="add_fleet.php?project_id=<?php echo $current_project_id; ?>" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> 添加车辆
            </a>
        </div>
        <div class="card-body">
                
                <!-- 项目选择 -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label>选择项目：</label>
                        <select class="form-control" id="projectSelect" onchange="changeProject()">
                            <?php foreach ($projects as $project): ?>
                                <option value="<?php echo $project['id']; ?>" 
                                        <?php echo $project['id'] == $current_project_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($project['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- 筛选表单 -->
                <form method="GET" class="mb-3">
                    <input type="hidden" name="project_id" value="<?php echo $current_project_id; ?>">
                    <div class="row">
                        <div class="col-md-3">
                            <label>车辆类型：</label>
                            <select name="vehicle_type" class="form-control" onchange="this.form.submit()">
                                <option value="">全部类型</option>
                                <?php foreach ($vehicle_type_map as $key => $value): ?>
                                    <option value="<?php echo $key; ?>" 
                                            <?php echo $key == $vehicle_type_filter ? 'selected' : ''; ?>>
                                        <?php echo $value; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label>状态：</label>
                            <select name="status" class="form-control" onchange="this.form.submit()">
                                <option value="">全部状态</option>
                                <?php foreach ($status_map as $key => $value): ?>
                                    <option value="<?php echo $key; ?>" 
                                            <?php echo $key == $status_filter ? 'selected' : ''; ?>>
                                        <?php echo $value; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 text-right">
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <div>
                                    <a href="fleet_management.php?project_id=<?php echo $current_project_id; ?>" 
                                       class="btn btn-secondary">清除筛选</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>

                <!-- 车队列表 -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover" style="min-width: 900px;">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 12%">车队编号</th>
                                <th style="width: 10%">车辆类型</th>
                                <th style="width: 10%">具体车型</th>
                                <th style="width: 15%">车牌号码</th>
                                <th style="width: 8%">座位数</th>
                                <th style="width: 10%">驾驶员</th>
                                <th style="width: 10%">联系电话</th>
                                <th style="width: 10%">状态</th>
                                <th style="width: 15%">操作</th>
                            </tr>
                        </thead>
                        <tbody class="table-group-divider">
                            <?php if (empty($fleet_list)): ?>
                                <tr>
                                    <td colspan="8" class="text-center">暂无车队信息</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($fleet_list as $vehicle): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-primary px-2 py-1 fw-bold text-white d-inline-block w-100 text-truncate" style="font-size: 0.85rem; max-width: 120px;" title="<?php echo htmlspecialchars($vehicle['fleet_number']); ?>"><?php echo htmlspecialchars($vehicle['fleet_number']); ?></span>
                                        </td>
                                        <td><?php echo $vehicle_type_map[$vehicle['vehicle_type']]; ?></td>
                                        <td><?php echo htmlspecialchars($vehicle['vehicle_model'] ?? '-'); ?></td>
                                        <td>
                                            <?php 
                                            // 显示车牌号码，添加分隔符
                                            $license_plate = $vehicle['license_plate'];
                                            if (!empty($license_plate)) {
                                                // 检查是否已经有分隔符
                                                if (mb_strpos($license_plate, '·') === false && mb_strlen($license_plate) > 2) {
                                                    // 在省份代码后添加分隔符
                                                    $display_plate = mb_substr($license_plate, 0, 2) . '·' . mb_substr($license_plate, 2);
                                                } else {
                                                    $display_plate = $license_plate;
                                                }
                                            } else {
                                                $display_plate = '-';
                                            }
                                            ?>
                                            <span class="license-plate" title="<?php echo htmlspecialchars($vehicle['license_plate']); ?>"><?php echo htmlspecialchars($display_plate); ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($vehicle['seats'] ?? '5'); ?>座</td>
                                        <td><?php echo htmlspecialchars($vehicle['driver_name'] ?? '-'); ?></td>
                                        <td>
                                            <?php if ($vehicle['driver_phone']): ?>
                                                <a href="tel:<?php echo $vehicle['driver_phone']; ?>">
                                                    <?php echo htmlspecialchars($vehicle['driver_phone']); ?>
                                                </a>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge fs-6 px-3 py-2 fw-bold <?php 
                                                echo $vehicle['status'] == 'active' ? 'bg-success text-white' : 
                                                       ($vehicle['status'] == 'maintenance' ? 'bg-warning text-dark' : 'bg-secondary text-white');
                                            ?>">
                                                <?php echo $status_map[$vehicle['status']]; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="edit_fleet.php?id=<?php echo $vehicle['id']; ?>" 
                                                   class="btn btn-sm btn-outline-primary">编辑</a>
                                                <a href="assign_fleet.php?project_id=<?php echo $current_project_id; ?>" 
                                                   class="btn btn-sm btn-outline-success">分配</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function changeProject() {
    const projectId = document.getElementById('projectSelect').value;
    window.location.href = 'fleet_management.php?project_id=' + projectId;
}
</script>

<?php include 'includes/footer.php'; ?>