<?php
session_start();
// 引入权限中间件进行权限验证
require_once '../includes/PermissionMiddleware.php';
$database = new Database();
$db = $database->getConnection();
$middleware = new PermissionMiddleware($db);
$middleware->checkUserPagePermission('frontend:transport:list');


if (!isset($_SESSION['project_id'])) {
    header("Location: project_login.php");
    exit;
}

$project_id = $_SESSION['project_id'];
$project_name = $_SESSION['project_name'] ?? '项目';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($project_name); ?> - 车辆管理</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .vehicle-card {
            transition: transform 0.2s;
        }
        .vehicle-card:hover {
            transform: translateY(-2px);
        }
        .assigned-vehicle {
            border-left: 4px solid #28a745;
        }
        .available-vehicle {
            border-left: 4px solid #ffc107;
        }
    </style>
</head>
<body>
    <?php 
require_once '../config/database.php';require_once 'includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
 
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">车辆管理</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <input type="date" class="form-control" id="travelDate" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="loadData()">
                            <i class="bi bi-arrow-clockwise"></i> 刷新
                        </button>
                    </div>
                </div>

                <!-- 统计信息 -->
                <div class="row mb-4" id="stats">
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title" id="totalRecords">-</h5>
                                <p class="card-text">出行记录</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title" id="assignedVehicles">-</h5>
                                <p class="card-text">已分配车辆</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title" id="totalPassengers">-</h5>
                                <p class="card-text">总乘客数</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title" id="availableVehicles">-</h5>
                                <p class="card-text">可用车辆</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 出行记录 -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">出行记录</h5>
                    </div>
                    <div class="card-body">
                        <div id="transportationList">
                            <div class="text-center">
                                <div class="spinner-border" role="status">
                                    <span class="visually-hidden">加载中...</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 可用车辆 -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">项目专属车辆</h5>
                    </div>
                    <div class="card-body">
                        <div id="availableVehiclesList">
                            <div class="text-center">
                                <div class="spinner-border" role="status">
                                    <span class="visually-hidden">加载中...</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        let projectId = <?php echo $project_id; ?>;

        function loadData() {
            const date = document.getElementById('travelDate').value;
            loadTransportation(date);
            loadFleet(date);
        }

        async function loadTransportation(date) {
            try {
                const url = `../api/get_project_transportation.php?project_id=${projectId}${date ? '&date=' + date : ''}`;
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.success) {
                    document.getElementById('totalRecords').textContent = data.stats.total_records || 0;
                    document.getElementById('assignedVehicles').textContent = data.stats.assigned_vehicles || 0;
                    document.getElementById('totalPassengers').textContent = data.stats.total_passengers || 0;
                    
                    displayTransportation(data.data);
                }
            } catch (error) {
                console.error('加载出行记录失败:', error);
                document.getElementById('transportationList').innerHTML = '<div class="alert alert-danger">加载失败</div>';
            }
        }

        async function loadFleet(date) {
            try {
                const url = `../api/get_project_fleet.php?project_id=${projectId}${date ? '&date=' + date : ''}`;
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.success) {
                    document.getElementById('availableVehicles').textContent = data.total || 0;
                    displayFleet(data.data);
                }
            } catch (error) {
                console.error('加载车辆信息失败:', error);
                document.getElementById('availableVehiclesList').innerHTML = '<div class="alert alert-danger">加载失败</div>';
            }
        }

        function displayTransportation(records) {
            let html = '';
            
            if (records.length === 0) {
                html = '<div class="text-center text-muted">暂无出行记录</div>';
            } else {
                html = '<div class="row">';
                records.forEach(record => {
                    html += `
                        <div class="col-md-6 mb-3">
                            <div class="card ${record.fleet_id ? 'assigned-vehicle' : 'available-vehicle'}">
                                <div class="card-body">
                                    <h6 class="card-title">${record.personnel_name} - ${record.travel_type}</h6>
                                    <p class="card-text">
                                        <small>
                                            日期: ${record.travel_date}<br>
                                            时间: ${record.departure_time || '未设置'}<br>
                                            路线: ${record.departure_location} → ${record.destination_location}<br>
                                            乘客: ${record.passenger_count}人
                                        </small>
                                    </p>
                                    ${record.fleet_id ? `
                                        <div class="border-top pt-2">
                                            <small class="text-success">
                                                已分配: ${record.vehicle_model} (${record.license_plate})<br>
                                                司机: ${record.driver_name} ${record.driver_phone}
                                            </small>
                                        </div>
                                    ` : '<span class="text-warning">未分配车辆</span>'}
                                </div>
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
            }
            
            document.getElementById('transportationList').innerHTML = html;
        }

        function displayFleet(vehicles) {
            let html = '';
            
            if (vehicles.length === 0) {
                html = '<div class="text-center text-muted">暂无可用车辆</div>';
            } else {
                html = '<div class="row">';
                vehicles.forEach(vehicle => {
                    html += `
                        <div class="col-md-4 mb-3">
                            <div class="card vehicle-card">
                                <div class="card-body">
                                    <h6 class="card-title">${vehicle.vehicle_model}</h6>
                                    <p class="card-text">
                                        <small>
                                            车牌: ${vehicle.license_plate}<br>
                                            座位: ${vehicle.seats}座<br>
                                            司机: ${vehicle.driver_name}<br>
                                            电话: ${vehicle.driver_phone}
                                        </small>
                                    </p>
                                    <span class="badge ${vehicle.status === 'available' ? 'bg-success' : 'bg-warning'}">
                                        ${vehicle.status_text}
                                    </span>
                                </div>
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
            }
            
            document.getElementById('availableVehiclesList').innerHTML = html;
        }

        // 页面加载完成后初始化
        document.addEventListener('DOMContentLoaded', function() {
            loadData();
            
            // 日期变化时重新加载
            document.getElementById('travelDate').addEventListener('change', loadData);
        });
    </script>
<?php include 'includes/footer.php'; ?>
</body>
</html>