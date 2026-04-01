<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();

// 获取报出行车列表 - 使用统一的部门归属逻辑
$query = "SELECT 
    tr.id,
    tr.travel_date,
    tr.travel_type,
    tr.departure_time,
    tr.departure_location,
    tr.destination_location,
    tr.status,
    tr.contact_phone,
    p.code as project_code,
    p.name as project_name,
    pr.name as personnel_name,
    COALESCE(
        (SELECT d.name 
         FROM project_department_personnel pdp 
         JOIN departments d ON pdp.department_id = d.id 
         WHERE pdp.personnel_id = tr.personnel_id 
         AND pdp.project_id = tr.project_id 
         ORDER BY pdp.project_id LIMIT 1),
        (SELECT d2.name FROM departments d2 WHERE d2.id = pr.department_id),
        '未分配部门'
    ) as department_name,
    (SELECT COUNT(*) FROM transportation_passengers WHERE transportation_report_id = tr.id) as passenger_count
FROM transportation_reports tr
JOIN projects p ON tr.project_id = p.id
LEFT JOIN personnel pr ON tr.personnel_id = pr.id
ORDER BY tr.travel_date DESC, tr.created_at DESC";

$stmt = $db->prepare($query);
$stmt->execute();
$transportation_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取项目列表用于筛选
$projects = $db->query("SELECT id, name FROM projects ORDER BY name");
$projects = $projects->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>报出行车管理 - 统一部门归属</title>
    <link href="assets/css/app.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">管理系统</a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="sync_department_logic.php">部门统一工具</a>
                <a class="nav-link" href="debug_department_consistency.php">一致性检查</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h1>报出行车管理 <small class="text-muted">(统一部门归属)</small></h1>
                    <a href="add_transportation.php" class="btn btn-primary">新增报出行车</a>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">报出行车列表 (<?= count($transportation_reports) ?>条)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($transportation_reports)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-car fa-3x text-muted mb-3"></i>
                                <h5 class="text-muted">暂无报出行车记录</h5>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>项目</th>
                                            <th>出行日期</th>
                                            <th>时间</th>
                                            <th>车型</th>
                                            <th>出发地</th>
                                            <th>目的地</th>
                                            <th>申请人</th>
                                            <th>部门</th>
                                            <th>状态</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($transportation_reports as $report): ?>
                                            <tr>
                                                <td><?= $report['id'] ?></td>
                                                <td>
                                                    <small class="text-muted"><?= htmlspecialchars($report['project_code']) ?></small><br>
                                                    <?= htmlspecialchars($report['project_name']) ?>
                                                </td>
                                                <td><?= $report['travel_date'] ?></td>
                                                <td><?= $report['departure_time'] ?></td>
                                                <td><?= $report['travel_type'] ?></td>
                                                <td><?= htmlspecialchars($report['departure_location']) ?></td>
                                                <td><?= htmlspecialchars($report['destination_location']) ?></td>
                                                <td><?= htmlspecialchars($report['personnel_name']) ?></td>
                                                <td><?= htmlspecialchars($report['department_name']) ?></td>
                                                <td>
                                                    <?php 
                                                    $status_class = [
                                                        'pending' => 'warning',
                                                        'confirmed' => 'success',
                                                        'cancelled' => 'danger'
                                                    ][$report['status']] ?? 'secondary';
                                                    $status_text = [
                                                        'pending' => '待确认',
                                                        'confirmed' => '已确认',
                                                        'cancelled' => '已取消'
                                                    ][$report['status']] ?? $report['status'];
                                                    ?>
                                                    <span class="badge bg-<?= $status_class ?>"><?= $status_text ?></span>
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
        </div>
    </div>

    <script src="assets/js/app.min.js"></script>
</body>
</html>