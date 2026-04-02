<?php
session_start();
// 引入权限中间件进行权限验证
require_once '../includes/PermissionMiddleware.php';
$database = new Database();
$db = $database->getConnection();
$middleware = new PermissionMiddleware($db);
$middleware->checkUserPagePermission('frontend:transport:export');

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/site_config.php';

// 导出行程表HTML版本，适合打印或转换为PDF
requireLogin();
checkProjectPermission($_SESSION['project_id']);

$projectId = $_SESSION['project_id'];
$projectName = $_SESSION['project_name'] ?? '项目行程表';
$username = $_SESSION['username'] ?? '未知用户';

// 从项目数据库获取场地位置和酒店信息 - 使用与dashboard.php一致的逻辑
$venue_name = '未设置场地';
$hotel_name = '未设置酒店';
$hotel_locations = '';

try {
    // 检查project_hotels表是否存在
    $check_table_query = "SHOW TABLES LIKE 'project_hotels'";
    $check_stmt = $db->prepare($check_table_query);
    $check_stmt->execute();
    $table_exists = ($check_stmt->rowCount() > 0);
    
    if ($table_exists) {
        // 使用新的多酒店关联模式获取项目信息
        $query = "SELECT p.location as venue_name, 
                  GROUP_CONCAT(DISTINCT h.hotel_name_cn ORDER BY h.hotel_name_cn SEPARATOR ', ') as hotel_names,
                  GROUP_CONCAT(DISTINCT CONCAT(h.province, '省 ', h.city, '市') ORDER BY h.hotel_name_cn SEPARATOR '; ') as hotel_locations
                  FROM projects p 
                  LEFT JOIN project_hotels ph ON p.id = ph.project_id
                  LEFT JOIN hotels h ON ph.hotel_id = h.id 
                  WHERE p.id = :project_id
                  GROUP BY p.id";
    } else {
        // 使用旧的项目单酒店模式（向后兼容）
        $check_hotel_field = "SHOW COLUMNS FROM projects LIKE 'hotel_id'";
        $check_field_stmt = $db->prepare($check_hotel_field);
        $check_field_stmt->execute();
        $hotel_field_exists = ($check_field_stmt->rowCount() > 0);
        
        if ($hotel_field_exists) {
            $query = "SELECT p.location as venue_name, 
                      h.hotel_name_cn as hotel_name_cn, h.hotel_name_en as hotel_name_en,
                      h.province as hotel_province, h.city as hotel_city, h.address as hotel_address
                      FROM projects p 
                      LEFT JOIN hotels h ON p.hotel_id = h.id 
                      WHERE p.id = :project_id";
        } else {
            // 只获取项目地点信息
            $query = "SELECT location as venue_name FROM projects WHERE id = :project_id";
        }
    }
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':project_id', $projectId);
    $stmt->execute();
    $project_details = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($project_details) {
        // 设置场地名称
        $venue_name = $project_details['venue_name'] ?? '未设置场地';
        
        // 处理酒店信息
        if ($table_exists) {
            // 多酒店模式
            $hotel_name = $project_details['hotel_names'] ?? '未设置酒店';
            $hotel_locations = $project_details['hotel_locations'] ?? '';
        } else {
            // 单酒店模式
            $hotel_name = $project_details['hotel_name_cn'] ?? $project_details['hotel_name_en'] ?? '未设置酒店';
            
            // 构建酒店位置信息
            if (!empty($project_details['hotel_province']) || !empty($project_details['hotel_city'])) {
                $hotel_locations = '';
                if (!empty($project_details['hotel_province'])) {
                    $hotel_locations .= $project_details['hotel_province'] . '省';
                }
                if (!empty($project_details['hotel_city'])) {
                    $hotel_locations .= ' ' . $project_details['hotel_city'] . '市';
                }
                if (!empty($project_details['hotel_address'])) {
                    $hotel_locations .= ' ' . $project_details['hotel_address'];
                }
                $hotel_locations = trim($hotel_locations);
            }
        }
    }
    
} catch (PDOException $e) {
    // 如果查询失败，使用默认值
    $venue_name = '未设置场地';
    $hotel_name = '未设置酒店';
    $hotel_locations = '';
}

// 获取筛选参数和排序参数
$filter_date = $_GET['date'] ?? '';
$filter_type = $_GET['type'] ?? '';
$filter_status = $_GET['status'] ?? '';
$sort_order = $_GET['sort'] ?? 'desc'; // 默认降序排序

// 验证排序参数，只允许asc和desc
$sort_order = ($sort_order === 'asc') ? 'asc' : 'desc';

// 过滤掉非法字符，防止注入
$filter_date = preg_replace('/[^\d-]/', '', $filter_date);
$filter_type = preg_replace('/[^\w-]/', '', $filter_type);
$filter_status = preg_replace('/[^\w-]/', '', $filter_status);

// 构建查询条件
$where_conditions = ["tr.project_id = :project_id"];
$params = [':project_id' => $projectId];

if ($filter_date) {
    $where_conditions[] = "tr.travel_date = :travel_date";
    $params[':travel_date'] = $filter_date;
}

if ($filter_type) {
    $where_conditions[] = "tr.travel_type = :travel_type";
    $params[':travel_type'] = $filter_type;
}

if ($filter_status) {
    $where_conditions[] = "tr.status = :status";
    $params[':status'] = $filter_status;
}

$where_conditions[] = "tr.parent_transport_id IS NULL";
$where_clause = implode(' AND ', $where_conditions);

// 获取行程数据 - 根据排序参数动态排序
$query = "SELECT 
    tr.id as main_id,
    tr.travel_date,
    tr.travel_type,
    tr.departure_time,
    tr.departure_location,
    tr.destination_location,
    tr.status,
    tr.id as transport_ids,
    tr.contact_phone as contact_phones,
    tr.special_requirements as special_requirements,
    GROUP_CONCAT(DISTINCT CONCAT(p.name, '|', COALESCE(d.name, '未分配部门')) ORDER BY p.name) as personnel_info,
    1 as trip_count,
    tr.passenger_count as total_passengers,
    tr.vehicle_requirements,
    GROUP_CONCAT(DISTINCT f.fleet_number) as fleet_numbers,
    GROUP_CONCAT(DISTINCT f.license_plate) as license_plates,
    GROUP_CONCAT(DISTINCT f.driver_name) as driver_names
FROM transportation_reports tr 
LEFT JOIN transportation_passengers tp ON tr.id = tp.transportation_report_id
LEFT JOIN personnel p ON tp.personnel_id = p.id OR tr.personnel_id = p.id
LEFT JOIN project_department_personnel pdp ON p.id = pdp.personnel_id AND pdp.project_id = tr.project_id
LEFT JOIN departments d ON pdp.department_id = d.id
LEFT JOIN transportation_fleet_assignments tfa ON tr.id = tfa.transportation_report_id
LEFT JOIN fleet f ON tfa.fleet_id = f.id
WHERE {$where_clause}
GROUP BY tr.id, tr.travel_date, tr.travel_type, tr.departure_time, tr.departure_location, tr.destination_location, tr.status, tr.contact_phone, tr.special_requirements, tr.passenger_count, tr.vehicle_requirements
ORDER BY tr.travel_date " . ($sort_order === 'asc' ? 'ASC' : 'DESC') . ", tr.departure_time " . ($sort_order === 'asc' ? 'ASC' : 'DESC');
$stmt = $db->prepare($query);
$stmt->execute($params);
$transports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 设置页面标题
$page_title = $projectName . ' - 行程表导出';
$show_page_title = '行程表导出';

// 直接输出HTML，不包含头部文件
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
<style>
    /* 复制 transport_list.php 表格样式 */
    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #fff; }
    .table { border-collapse: collapse; width: 100%; margin: 0 auto; font-size: 14px; }
    .table th, .table td { border: 1px solid #495057; padding: 8px; }
    .table th { background: #f8f9fa; font-weight: bold; color: #495057; }
    .date-divider td { background: #fff5f5; border-top: 3px solid #dc3545; border-bottom: 1px solid #495057; }
    .date-header { display: flex; align-items: center; justify-content: center; gap: 12px; }
    .date-main { font-size: 1.1em; font-weight: bold; color: #2c3e50; background: #dc3545; color: #fff; padding: 4px 12px; border-radius: 6px; }
    .date-weekday { font-size: 0.95em; color: #5a6c7d; background: #e8f4fd; padding: 4px 8px; border-radius: 12px; }
    .date-count { font-size: 0.85em; color: #6c757d; background: #f1f3f4; padding: 2px 8px; border-radius: 10px; }
    .passenger-tags { display: flex; flex-direction: column; gap: 8px; align-items: stretch; }
    .department-group { display: block; border-left: 3px solid #e3f2fd; padding-left: 8px; margin-bottom: 12px; text-align: center; }
    .dept-tag { background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%); color: #1976d2; border: 1px solid #90caf9; padding: 4px 8px; border-radius: 12px; font-size: 0.85rem; font-weight: 600; display: block; text-align: center; margin-bottom: 4px; }
    .count-badge { background: #1976d2; color: #fff; padding: 2px 8px; border-radius: 12px; font-size: 0.8rem; margin-left: 0; font-weight: 700; display: block; margin: 4px auto 0; width: fit-content; }
    .names-list { color: #212529; font-size: 1rem; line-height: 1.6; font-weight: 600; margin-top: 8px; display: block; text-align: center; }
    .vehicle-info-card { display: flex; flex-direction: column; gap: 8px; padding: 6px; background: #f8f9fa; }
    .vehicle-number { font-size: 1em; font-weight: bold; color: #007bff; }
    .vehicle-plate { font-size: 0.9em; color: #6c757d; }
    .vehicle-plate .badge { font-size: 0.85em; padding: 0.2em 0.4em; font-family: 'Courier New', monospace; font-weight: 600; background: #fff3cd; color: #856404; border-radius: 3px; word-break: break-all; display: inline-block; max-width: 100%; }
    .vehicle-driver { font-size: 0.9em; color: #495057; font-weight: 500; }
    .vehicle-multi-item { padding: 8px; background: #fff; }
    .vehicle-info-empty .badge { font-size: 0.9em; padding: 0.6em 1em; background: #ffc107; color: #856404; }
    .vehicle-requirements-display { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .vehicle-requirements-display .badge { font-size: 0.9em; padding: 0.5em 0.8em; background: #1976d2; color: #fff; }
    .vehicle-requirements { font-size: 0.9em; color: #d9534f; font-weight: bold; }
    .badge { display: inline-block; border-radius: 8px; padding: 2px 8px; font-size: 0.85em; }
    .bg-light { background: #f8f9fa; color: #212529; }
    .bg-info { background: #17a2b8; color: #fff; }
    .bg-primary { background: #007bff; color: #fff; }
    .bg-warning { background: #ffc107; color: #212529; }
    .bg-danger { background: #dc3545; color: #fff; }
    .bg-success { background: #28a745; color: #fff; }
    .text-dark { color: #212529; }
    .text-muted { color: #6c757d; }
    .text-danger { color: #dc3545; }
    .fw-bold { font-weight: bold; }
    .text-center { text-align: center; }
    /* 优化部门标签样式 - 移除背景色和边框，使用蓝色字体 */
    .dept-tag {
        font-weight: bold;
        color: #0066cc;
        margin-right: 8px;
        background: none;
        border: none;
        padding: 0;
    }
    /* 乘车人列全面优化 - PC端和移动端完整显示 */
    .passenger-tags {
        /* 基础布局优化 */
        max-width: 100%;
        overflow-wrap: break-word;
        word-wrap: break-word;
        word-break: break-word;
        hyphens: auto;
        line-height: 1.4;
        font-size: 0.95em;
        padding: 2px 0; /* 内边距优化 */
    }
    
    .department-group {
        /* 部门分组样式优化 */
        margin-bottom: 8px;
        padding: 6px 0;
        border-bottom: 1px solid #f0f0f0;
        transition: background-color 0.2s ease; /* 悬停过渡效果 */
    }
    
    .department-group:hover {
        background-color: #fafafa; /* 悬停背景色 */
        border-radius: 4px;
        padding-left: 4px;
        padding-right: 4px;
        margin-left: -4px;
        margin-right: -4px;
    }
    
    .department-group:last-child {
        border-bottom: none;
        margin-bottom: 0;
    }
    
    .dept-tag {
        /* 部门标签样式增强 */
        font-weight: 600;
        color: #0066cc;
        margin-right: 8px;
        background: none;
        border: none;
        padding: 0;
        display: inline-flex;
        align-items: center;
        font-size: 0.9em;
        line-height: 1.2;
        cursor: default;
    }
    /* 导出模式下部门标签放大并左对齐 */
    .export-mode .dept-tag {
        font-size: 1.3em !important;
        justify-content: flex-start !important;
        text-align: left !important;
        margin-bottom: 0.5em;
    }
    /* 导出模式下隐藏姓名列表 */
    .export-mode .names-list {
        display: none !important;
    }
    /* 时间列样式 - 移除span样式，改为td亮黄色底色 */
    .time-column {
        background-color: #fffacd; /* 亮黄色 */
        font-weight: bold;
        color: #333;
    }
</style>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <h1 class="text-center mb-4"><?php echo htmlspecialchars($projectName); ?> - 行程表</h1>
    <div id="export-area">
        <!-- 主标题改为项目名称 -->
        <h2 style="text-align:center;margin:30px 0 10px 0;"><?php echo htmlspecialchars($projectName); ?></h2>
        <!-- 增加"车程表"字样 -->
        <div style="text-align:center;font-size:1.3em;font-weight:bold;margin-bottom:10px;color:#1976d2;">车程表</div>
        <!-- 项目信息区域，场地和酒店名称检查 -->
        <div style="text-align:center;margin-bottom:18px;color:#555;">
            <span style="margin-right:30px;"><strong>场地位置：</strong><?php echo htmlspecialchars($venue_name); ?></span>
            <span style="margin-right:30px;"><strong>酒店名称：</strong><?php echo htmlspecialchars($hotel_name); ?></span>
            <?php if (!empty($hotel_locations)): ?>
            <span style="margin-right:30px;"><strong>酒店位置：</strong><?php echo htmlspecialchars($hotel_locations); ?></span>
            <?php endif; ?></br></br>
            <span><strong>导出用户：</strong><?php echo htmlspecialchars($username); ?></span>
            <!-- 排序选择控件 -->
            <div style="margin-top:15px;">
                <strong>排序方式：</strong>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'desc'])); ?>" 
                   style="margin:0 5px;padding:5px 10px;background:<?php echo $sort_order === 'desc' ? '#007bff' : '#f8f9fa'; ?>;color:<?php echo $sort_order === 'desc' ? '#fff' : '#007bff'; ?>;text-decoration:none;border-radius:4px;"
                   title="按日期降序排序（最新日期在前）">降序</a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'asc'])); ?>" 
                   style="margin:0 5px;padding:5px 10px;background:<?php echo $sort_order === 'asc' ? '#007bff' : '#f8f9fa'; ?>;color:<?php echo $sort_order === 'asc' ? '#fff' : '#007bff'; ?>;text-decoration:none;border-radius:4px;"
                   title="按日期升序排序（最早日期在前）">升序</a>
            </div>
            <!-- 排序提示信息 -->
            <div style="margin-top:8px;font-size:0.9em;color:#6c757d;">
                当前按日期<?php echo $sort_order === 'desc' ? '降序' : '升序'; ?>排序，
                序号<?php echo $sort_order === 'desc' ? '从最新记录开始为1' : '从最早记录开始为1'; ?>
            </div>
        </div>
        <table class="table" style="table-layout: fixed;">
            <thead>
                <tr>
                    <th style="width: 4%;">序号</th>
                    <th style="width: 7%;">时间</th>
                    <th style="width: 35%;">乘车人</th>
                    <th style="width: 6%;">乘客数</th>
                    <th style="width: 8%;">交通类型</th>
                    <th style="width: 18%;">路线</th>
                    <th style="width: 12%;">车辆信息</th>
                    <th style="width: 14%;">特殊要求</th>
                </tr>
            </thead>
            <tbody>
            <?php
            // 按日期分组行程数据
            $grouped_transports = [];
            foreach ($transports as $transport) {
                $date = $transport['travel_date'];
                if (!isset($grouped_transports[$date])) {
                    $grouped_transports[$date] = [];
                }
                $grouped_transports[$date][] = $transport;
            }
            
            // 根据排序参数对日期进行排序
            if ($sort_order === 'asc') {
                ksort($grouped_transports); // 升序排序（最早日期在前）
                $current_sequence = 1; // 升序：从1开始递增
            } else {
                krsort($grouped_transports); // 降序排序（最新日期在前）
                $current_sequence = count($transports); // 降序：从总数开始递减
            }

            foreach ($grouped_transports as $date => $date_transports):
                $date_count = count($date_transports);
                foreach ($date_transports as $index => $transport):
                    if ($index === 0):
            ?>
                <tr class="date-divider">
                    <td colspan="8">
                        <div class="date-header">
                            <span class="date-main"><?= date('Y/m/d', strtotime($date)) ?></span>
                            <span class="date-weekday">(<?= [
                                'Monday' => '星期一',
                                'Tuesday' => '星期二', 
                                'Wednesday' => '星期三',
                                'Thursday' => '星期四',
                                'Friday' => '星期五',
                                'Saturday' => '星期六',
                                'Sunday' => '星期日'
                            ][date('l', strtotime($date))] ?>)</span>
                            <span class="date-count">共 <?= $date_count ?> 条</span>
                        </div>
                    </td>
                </tr>
            <?php
                    endif;
                    // 乘车人分部门
                    $department_groups = [];
                    if ($transport['personnel_info']) {
                        $personnel_data = explode(',', $transport['personnel_info']);
                        foreach ($personnel_data as $data) {
                            if (!empty($data)) {
                                $parts = explode('|', $data);
                                $name = htmlspecialchars($parts[0] ?? '');
                                $department = htmlspecialchars($parts[1] ?? '未分配部门');
                                if (!empty($name)) {
                                    if (!isset($department_groups[$department])) {
                                        $department_groups[$department] = [];
                                    }
                                    $department_groups[$department][] = $name;
                                }
                            }
                        }
                    }
                    ksort($department_groups);
            ?>
                <tr>
                    <td class="text-center"><span class="badge bg-light text-dark"><?php 
                        echo $current_sequence;
                        // 根据排序方向更新序号
                        if ($sort_order === 'asc') {
                            $current_sequence++;
                        } else {
                            $current_sequence--;
                        }
                    ?></span></td>
                    <!-- 时间列使用亮黄色底色 -->
                    <td class="text-center time-column">
                        <?php if ($transport['travel_type'] === '接机/站'): ?>
                            <span class="badge bg-danger text-white" style="font-size: 0.95rem;">
                                <?php echo htmlspecialchars(substr($transport['departure_time'],0,5)); ?>
                            </span>
                            <div style="margin-top: 4px;">
                                <span class="badge bg-danger text-white" style="font-size: 0.6rem; padding: 0.2em 0.45em;">航班/车次<br>到站时间</span>
                            </div>
                        <?php else: ?>
                            <?php echo htmlspecialchars(substr($transport['departure_time'],0,5)); ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="passenger-tags">
                            <?php if (!empty($department_groups)): ?>
                                <?php foreach ($department_groups as $department => $names): ?>
                                    <?php $count = count($names); ?>
                                    <div class="department-group">
                                        <div class="dept-tag">
                                            <?= $department ?>
                                            <span class="count-badge">(<?= $count ?>人)</span>
                                        </div>
                                        <span class="names-list"><?= implode('、', $names) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-muted">无乘车人</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="text-center"><span class="badge bg-info text-white"><?php echo htmlspecialchars($transport['total_passengers']); ?>人</span></td>
                    <td class="text-center"><span class="badge bg-primary"><?php echo htmlspecialchars($transport['travel_type']); ?></span></td>
                    <td>
                        <div><strong>出发:</strong> <?php echo htmlspecialchars($transport['departure_location']); ?></div>
                        <div><strong>到达:</strong> <?php echo htmlspecialchars($transport['destination_location']); ?></div>
                    </td>
                    <td>
                        <?php if ($transport['fleet_numbers']): ?>
                            <div class="vehicle-info-card">
                                <?php
                                $fleet_numbers = explode(',', $transport['fleet_numbers']);
                                $license_plates = explode(',', $transport['license_plates'] ?? '');
                                $driver_names = explode(',', $transport['driver_names'] ?? '');
                                $vehicle_count = count($fleet_numbers);
                                for ($i = 0; $i < $vehicle_count; $i++):
                                    $fleet_number = htmlspecialchars(trim($fleet_numbers[$i] ?? ''));
                                    $license_plate = htmlspecialchars(trim($license_plates[$i] ?? ''));
                                    $driver_name = htmlspecialchars(trim($driver_names[$i] ?? '未指定'));
                                    if (empty($fleet_number)) continue;
                                ?>
                                    <div class="vehicle-multi-item">
                                        <div class="vehicle-number"><strong><?php echo $fleet_number; ?></strong></div>
                                        <div class="vehicle-plate">
                                            <span class="badge"><?php echo $license_plate ?: '无车牌'; ?></span>
                                        </div>
                                        <div class="vehicle-driver"><?php echo $driver_name; ?></div>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        <?php else: ?>
                            <div class="vehicle-info-empty">
                                <span class="badge bg-warning text-dark">未分配车辆</span>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="vehicle-requirements">
                            <?php if (!empty($transport['special_requirements'])): ?>
                                <?php echo htmlspecialchars($transport['special_requirements']); ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endforeach; ?>
            </tbody>
        </table>
        <div style="margin:40px 0 0 0;text-align:center;color:#888;">导出时间：<?= date('Y-m-d H:i:s') ?></div>
    </div>
    <!-- 下载按钮区域 -->
    <div style="margin:30px 0;text-align:center;">
        <button id="download-img-btn" style="padding:10px 24px;margin-right:18px;font-size:1em;background:#1976d2;color:#fff;border:none;border-radius:6px;cursor:pointer;">以图片下载</button>
        <button id="download-pdf-btn" style="padding:10px 24px;font-size:1em;background:#dc3545;color:#fff;border:none;border-radius:6px;cursor:pointer;">下载为PDF</button>
    </div>
    <!-- 引入 html2canvas 和 jsPDF CDN -->
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
    <script>
    function setExportMode(on) {
        var exportArea = document.getElementById('export-area');
        if (on) {
            exportArea.classList.add('export-mode');
            window.__exportMode = true;
        } else {
            exportArea.classList.remove('export-mode');
            window.__exportMode = false;
        }
    }

    // 下载为图片
    document.getElementById('download-img-btn').onclick = function() {
        setExportMode(true);
        var exportArea = document.getElementById('export-area');
        html2canvas(exportArea, {
            scale: 2,
            useCORS: true,
            backgroundColor: '#fff'
        }).then(function(canvas) {
            setExportMode(false);
            var link = document.createElement('a');
            link.download = '车程表_<?php echo date("Ymd_His"); ?>.png';
            link.href = canvas.toDataURL('image/png');
            link.click();
        });
    };

    // 下载为PDF（自动分页，确保所有内容完整显示）
    document.getElementById('download-pdf-btn').onclick = function() {
        setExportMode(true);
        var exportArea = document.getElementById('export-area');
        html2canvas(exportArea, {
            scale: 2,
            useCORS: true,
            backgroundColor: '#fff'
        }).then(function(canvas) {
            setExportMode(false);
            var imgData = canvas.toDataURL('image/png');
            var pdf = new window.jspdf.jsPDF('l', 'pt', 'a4');
            var pageWidth = pdf.internal.pageSize.getWidth();
            var pageHeight = pdf.internal.pageSize.getHeight();
            var imgWidth = canvas.width;
            var imgHeight = canvas.height;
            var ratio = Math.min(pageWidth / imgWidth, 1);
            var pdfWidth = imgWidth * ratio;
            var pdfHeight = imgHeight * ratio;
            var y = 20;
            if (pdfHeight < pageHeight - y) {
                pdf.addImage(imgData, 'PNG', (pageWidth - pdfWidth) / 2, y, pdfWidth, pdfHeight);
            } else {
                var pageCount = Math.ceil(pdfHeight / (pageHeight - y));
                for (var i = 0; i < pageCount; i++) {
                    var sourceY = i * (canvas.height / pageCount);
                    var sourceHeight = canvas.height / pageCount;
                    var pageCanvas = document.createElement('canvas');
                    pageCanvas.width = canvas.width;
                    pageCanvas.height = sourceHeight;
                    var ctx = pageCanvas.getContext('2d');
                    ctx.drawImage(canvas, 0, sourceY, canvas.width, sourceHeight, 0, 0, canvas.width, sourceHeight);
                    var pageImgData = pageCanvas.toDataURL('image/png');
                    pdf.addImage(pageImgData, 'PNG', (pageWidth - pdfWidth) / 2, y, pdfWidth, pageHeight - y);
                    if (i < pageCount - 1) pdf.addPage();
                }
            }
            pdf.save('车程表_<?php echo date("Ymd_His"); ?>.pdf');
        });
    };
    </script>
</div>
</body>
</html>
