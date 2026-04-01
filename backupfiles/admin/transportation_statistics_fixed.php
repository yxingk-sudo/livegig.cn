<?php
// 适配实际数据库结构的交通统计页面

require_once '../config/database.php';
require_once '../includes/functions.php';

// 检查登录状态
if (!isLoggedIn()) {
    redirect('login.php');
}

// 获取当前用户ID
$user_id = $_SESSION['user_id'] ?? 0;

// 检查权限
if (!hasPermission('transportation_view')) {
    setFlashMessage('您没有权限查看交通统计信息', 'error');
    redirect('dashboard.php');
}

// 获取筛选条件
$filter_project = $_GET['project'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_vehicle_type = $_GET['vehicle_type'] ?? '';

// 构建查询条件
$where_conditions = [];
$params = [];

if (!empty($filter_project)) {
    $where_conditions[] = "tr.project_id = :project_id";
    $params['project_id'] = $filter_project;
}

if (!empty($filter_date_from)) {
    $where_conditions[] = "tr.travel_date >= :date_from";
    $params['date_from'] = $filter_date_from;
}

if (!empty($filter_date_to)) {
    $where_conditions[] = "tr.travel_date <= :date_to";
    $params['date_to'] = $filter_date_to;
}

if (!empty($filter_status)) {
    $where_conditions[] = "tr.status = :status";
    $params['status'] = $filter_status;
}

if (!empty($filter_vehicle_type)) {
    $where_conditions[] = "tr.travel_type = :vehicle_type";
    $params['vehicle_type'] = $filter_vehicle_type;
}

$where_sql = $where_conditions ? "WHERE " . implode(" AND ", $where_conditions) : "";

// 适配实际数据库结构的查询
$sql = "
    SELECT 
        tr.id,
        tr.travel_date,
        tr.travel_type as vehicle_type,
        tr.departure_location as start_location,
        tr.destination_location as end_location,
        tr.departure_time,
        COALESCE(tr.cost, 0) as cost,
        tr.description,
        tr.status,
        tr.passenger_count,
        tr.contact_phone,
        tr.special_requirements,
        tr.fleet_number,
        tr.driver_name,
        tr.driver_phone,
        tr.license_plate,
        tr.vehicle_model,
        p.name as project_name,
        p.code as project_code,
        u.username as reported_by_name,
        tr.created_at,
        tr.updated_at
    FROM transportation_reports tr
    LEFT JOIN projects p ON tr.project_id = p.id
    LEFT JOIN users u ON tr.reported_by = u.id
    {$where_sql}
    ORDER BY tr.travel_date DESC, tr.created_at DESC
";

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $transportation_reports = $stmt->fetchAll();
    
    // 获取项目列表
    $projects = $db->query("SELECT id, name, code FROM projects ORDER BY name ASC")->fetchAll();
    
    // 获取统计数据
    $stats_sql = "
        SELECT 
            COUNT(*) as total_records,
            SUM(COALESCE(cost, 0)) as total_cost,
            COUNT(DISTINCT project_id) as active_projects,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
            COUNT(CASE WHEN status = 'confirmed' THEN 1 END) as confirmed_count,
            COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_count
        FROM transportation_reports tr
        {$where_sql}
    ";
    
    $stmt = $db->prepare($stats_sql);
    $stmt->execute($params);
    $statistics = $stmt->fetch();
    
} catch (PDOException $e) {
    die("数据库错误：" . $e->getMessage());
}

// 设置页面标题
$page_title = "交通统计信息";
include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">交通统计信息</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="exportData()">
                            <i class="fas fa-download"></i> 导出数据
                        </button>
                        <a href="transportation_report_add.php" class="btn btn-sm btn-primary">
                            <i class="fas fa-plus"></i> 添加记录
                        </a>
                    </div>
                </div>
            </div>

            <!-- 统计卡片 -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h4 class="mb-0"><?php echo $statistics['total_records']; ?></h4>
                                    <p class="mb-0">总记录数</p>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-car fa-2x"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-success text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h4 class="mb-0">¥<?php echo number_format($statistics['total_cost'], 2); ?></h4>
                                    <p class="mb-0">总费用</p>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-money-bill-wave fa-2x"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-info text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h4 class="mb-0"><?php echo $statistics['confirmed_count']; ?></h4>
                                    <p class="mb-0">已确认</p>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-check-circle fa-2x"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-warning text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h4 class="mb-0"><?php echo $statistics['pending_count']; ?></h4>
                                    <p class="mb-0">待确认</p>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-clock fa-2x"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 筛选表单 -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">筛选条件</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label for="project" class="form-label">项目</label>
                            <select name="project" id="project" class="form-select">
                                <option value="">所有项目</option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?php echo $project['id']; ?>" 
                                            <?php echo $filter_project == $project['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($project['name'] . ' (' . $project['code'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="date_from" class="form-label">开始日期</label>
                            <input type="date" name="date_from" id="date_from" class="form-control" 
                                   value="<?php echo $filter_date_from; ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="date_to" class="form-label">结束日期</label>
                            <input type="date" name="date_to" id="date_to" class="form-control" 
                                   value="<?php echo $filter_date_to; ?>">
                        </div>
                        <div class="col-md-2">
                            <label for="status" class="form-label">状态</label>
                            <select name="status" id="status" class="form-select">
                                <option value="">所有状态</option>
                                <option value="pending" <?php echo $filter_status == 'pending' ? 'selected' : ''; ?>>待确认</option>
                                <option value="confirmed" <?php echo $filter_status == 'confirmed' ? 'selected' : ''; ?>>已确认</option>
                                <option value="cancelled" <?php echo $filter_status == 'cancelled' ? 'selected' : ''; ?>>已取消</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label for="vehicle_type" class="form-label">交通类型</label>
                            <select name="vehicle_type" id="vehicle_type" class="form-select">
                                <option value="">所有类型</option>
                                <option value="接站" <?php echo $filter_vehicle_type == '接站' ? 'selected' : ''; ?>>接站</option>
                                <option value="送站" <?php echo $filter_vehicle_type == '送站' ? 'selected' : ''; ?>>送站</option>
                                <option value="混合交通安排" <?php echo ($filter_vehicle_type == '混合交通安排') ? 'selected' : ''; ?>>混合交通安排</option>
                            </select>
                        </div>
                        <div class="col-md-1">
                            <label class="form-label">&nbsp;</label>
                            <div>
                                <button type="submit" class="btn btn-primary">筛选</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- 数据表格 -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">交通记录列表</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover" id="transportationTable">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>日期</th>
                                    <th>项目</th>
                                    <th>交通类型</th>
                                    <th>起点</th>
                                    <th>终点</th>
                                    <th>费用</th>
                                    <th>状态</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($transportation_reports)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center">暂无数据</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($transportation_reports as $report): ?>
                                        <tr>
                                            <td><?php echo $report['id']; ?></td>
                                            <td><?php echo $report['travel_date']; ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($report['project_name'] ?? '未指定'); ?>
                                                <br>
                                                <small class="text-muted"><?php echo htmlspecialchars($report['project_code'] ?? ''); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?php echo $report['vehicle_type']; ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($report['start_location'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($report['end_location'] ?? ''); ?></td>
                                            <td>¥<?php echo number_format($report['cost'], 2); ?></td>
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
                                                <span class="badge bg-<?php echo $status_class[$report['status']] ?? 'secondary'; ?>">
                                                    <?php echo $status_text[$report['status']] ?? $report['status']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="transportation_report_edit.php?id=<?php echo $report['id']; ?>" 
                                                       class="btn btn-outline-primary" title="编辑">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <button type="button" class="btn btn-outline-danger" 
                                                            onclick="deleteRecord(<?php echo $report['id']; ?>)" title="删除">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
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
        </main>
    </div>
</div>

<script>
function exportData() {
    const url = new URL('export_transportation.php', window.location.origin);
    const params = new URLSearchParams(window.location.search);
    url.search = params.toString();
    window.open(url, '_blank');
}

function deleteRecord(id) {
    if (confirm('确定要删除这条记录吗？此操作不可撤销。')) {
        fetch('transportation_report_delete.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'id=' + id
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('删除失败：' + data.message);
            }
        });
    }
}

// 初始化数据表
$(document).ready(function() {
    $('#transportationTable').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.11.5/i18n/zh.json'
        },
        order: [[1, 'desc']],
        pageLength: 25
    });
});
</script>

<?php include 'includes/footer.php'; ?>