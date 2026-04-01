<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/pinyin_functions.php';

// 酒店房间数据表格页面（房间表二）
// 包含序号、姓名、证件号、性别、部门、房号、房型、入住时间、退房时间、特殊要求
// 与房间表一的区别：去除为每一天生成一列

// 启动session
session_start();
requireLogin();
checkProjectPermission($_SESSION['project_id']);

$projectId = $_SESSION['project_id'];
$projectName = $_SESSION['project_name'] ?? '项目';

// 获取数据库连接
$database = new Database();
$db = $database->getConnection();

// 获取项目信息（开始日期和结束日期）
$project_query = "SELECT start_date, end_date FROM projects WHERE id = :project_id";
$project_stmt = $db->prepare($project_query);
$project_stmt->bindParam(':project_id', $projectId);
$project_stmt->execute();
$project_info = $project_stmt->fetch(PDO::FETCH_ASSOC);

// 如果没有获取到项目信息，设置默认值
$project_start_date = $project_info['start_date'] ?? date('Y-m-d');
$project_end_date = $project_info['end_date'] ?? date('Y-m-d', strtotime('+30 days'));

// 获取查询参数
$hotel_id = $_GET['hotel_id'] ?? '';

// 获取当前项目的酒店列表
$hotels_query = "SELECT DISTINCT 
                    CASE 
                        WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
                        ELSE hotel_name
                    END as hotel_name
                 FROM hotel_reports 
                 WHERE project_id = :project_id AND hotel_name IS NOT NULL
                 ORDER BY hotel_name";

$hotels_stmt = $db->prepare($hotels_query);
$hotels_stmt->bindParam(':project_id', $projectId);
$hotels_stmt->execute();
$hotels = $hotels_stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取房间数据并按酒店分组
$room_data_by_hotel = [];

// 如果有筛选特定酒店，只获取该酒店的数据
if (!empty($hotel_id)) {
    $room_data_query = "SELECT 
        hr.id,
        p.name as personnel_name,
        p.id_card as id_card,
        p.gender as gender,
        d.name as department_name,
        d.sort_order as department_sort_order,
        hr.hotel_name,
        hr.room_type,
        hr.check_in_date,
        hr.check_out_date,
        hr.special_requirements,
        hr.room_number,
        hr.shared_room_info
    FROM hotel_reports hr
    JOIN personnel p ON hr.personnel_id = p.id
    LEFT JOIN project_department_personnel pdp ON p.id = pdp.personnel_id AND hr.project_id = pdp.project_id
    LEFT JOIN departments d ON pdp.department_id = d.id
    WHERE hr.project_id = :project_id 
        AND (CASE 
            WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1)
            ELSE hr.hotel_name
        END = :hotel_name)
        AND hr.hotel_name IS NOT NULL
    ORDER BY COALESCE(d.sort_order, 0) ASC, d.id ASC, p.name";

    $room_data_stmt = $db->prepare($room_data_query);
    $room_data_stmt->bindParam(':project_id', $projectId);
    $room_data_stmt->bindParam(':hotel_name', $hotel_id);
    $room_data_stmt->execute();
    $raw_room_data = $room_data_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 将数据按酒店分组
    foreach ($raw_room_data as $room) {
        $hotel_name = $room['hotel_name'];
        // 处理复合酒店名称
        if (strpos($hotel_name, ' - ') !== false) {
            $hotel_name = substr($hotel_name, 0, strpos($hotel_name, ' - '));
        }
        $room_data_by_hotel[$hotel_name][] = $room;
    }
} else {
    // 如果没有筛选特定酒店，获取所有酒店的数据并按酒店分组
    $room_data_query = "SELECT 
        hr.id,
        p.name as personnel_name,
        p.id_card as id_card,
        p.gender as gender,
        d.name as department_name,
        d.sort_order as department_sort_order,
        hr.hotel_name,
        hr.room_type,
        hr.check_in_date,
        hr.check_out_date,
        hr.special_requirements,
        hr.room_number,
        hr.shared_room_info
    FROM hotel_reports hr
    JOIN personnel p ON hr.personnel_id = p.id
    LEFT JOIN project_department_personnel pdp ON p.id = pdp.personnel_id AND hr.project_id = pdp.project_id
    LEFT JOIN departments d ON pdp.department_id = d.id
    WHERE hr.project_id = :project_id 
        AND hr.hotel_name IS NOT NULL
    ORDER BY COALESCE(d.sort_order, 0) ASC, d.id ASC, p.name";

    $room_data_stmt = $db->prepare($room_data_query);
    $room_data_stmt->bindParam(':project_id', $projectId);
    $room_data_stmt->execute();
    $raw_room_data = $room_data_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 将数据按酒店分组
    foreach ($raw_room_data as $room) {
        $hotel_name = $room['hotel_name'];
        // 处理复合酒店名称
        if (strpos($hotel_name, ' - ') !== false) {
            $hotel_name = substr($hotel_name, 0, strpos($hotel_name, ' - '));
        }
        $room_data_by_hotel[$hotel_name][] = $room;
    }
}

// 处理共享房间合并逻辑（按酒店分组处理）
$processed_room_data = [];
foreach ($room_data_by_hotel as $hotel_name => $raw_room_data) {
    $room_data = [];
    $processed_ids = [];
    
    // 按照共享信息分组
    $grouped_data = [];
    
    // 首先处理非共享房间和独立记录
    foreach ($raw_room_data as $room) {
        // 如果已经处理过这个记录，跳过
        if (in_array($room['id'], $processed_ids)) {
            continue;
        }
        
        // 检查是否是共享房间（指定房型且有共享信息）
        if ((in_array($room['room_type'], ['双床房', '套房', '大床房', '总统套房', '副总统套房'])) && !empty($room['shared_room_info'])) {
            // 查找共享同一房间的其他记录
            $shared_group = [];
            foreach ($raw_room_data as $other_room) {
                if ((in_array($other_room['room_type'], ['双床房', '套房', '大床房', '总统套房', '副总统套房'])) && 
                    $other_room['shared_room_info'] === $room['shared_room_info'] &&
                    $other_room['check_in_date'] === $room['check_in_date'] &&
                    $other_room['check_out_date'] === $room['check_out_date'] &&
                    $other_room['hotel_name'] === $room['hotel_name'] &&
                    $other_room['room_number'] === $room['room_number']) {
                    $shared_group[] = $other_room;
                    $processed_ids[] = $other_room['id'];
                }
            }
            
            // 如果有共享组，添加到分组数据中
            if (count($shared_group) > 1) {
                // 创建分组键
                $group_key = $room['hotel_name'] . '|' . $room['room_type'] . '|' . $room['check_in_date'] . '|' . $room['check_out_date'] . '|' . $room['room_number'] . '|' . $room['special_requirements'] . '|' . $room['shared_room_info'];
                $grouped_data[$group_key] = $shared_group;
            } else {
                // 单独的双床房，不是共享房间
                $room['is_shared'] = false;
                $room_data[] = $room;
                $processed_ids[] = $room['id'];
            }
        } else {
            // 非双床房，直接添加
            $room['is_shared'] = false;
            $room_data[] = $room;
            $processed_ids[] = $room['id'];
        }
    }
    
    // 处理共享房间组
    foreach ($grouped_data as $group) {
        if (count($group) > 1) {
            // 创建合并后的记录
            $merged_room = $group[0];
            $merged_room['shared_personnel'] = $group;
            $merged_room['is_shared'] = true;
            $merged_room['occupant_count'] = count($group);
            
            $room_data[] = $merged_room;
        }
    }
    
    // 保持部门排序顺序
    usort($room_data, function($a, $b) {
        // 如果是共享房间，使用第一个共享人员的信息进行排序
        $dept_sort_a = $a['is_shared'] ? ($a['shared_personnel'][0]['department_sort_order'] ?? 0) : ($a['department_sort_order'] ?? 0);
        $dept_sort_b = $b['is_shared'] ? ($b['shared_personnel'][0]['department_sort_order'] ?? 0) : ($b['department_sort_order'] ?? 0);
        
        // 首先按部门排序
        if ($dept_sort_a != $dept_sort_b) {
            return $dept_sort_a <=> $dept_sort_b;
        }
        
        // 如果部门排序相同，按部门ID排序
        $dept_id_a = $a['is_shared'] ? ($a['shared_personnel'][0]['department_id'] ?? 0) : ($a['department_id'] ?? 0);
        $dept_id_b = $b['is_shared'] ? ($b['shared_personnel'][0]['department_id'] ?? 0) : ($b['department_id'] ?? 0);
        
        if ($dept_id_a != $dept_id_b) {
            return $dept_id_a <=> $dept_id_b;
        }
        
        // 最后按姓名排序
        $name_a = $a['is_shared'] ? ($a['shared_personnel'][0]['personnel_name'] ?? '') : ($a['personnel_name'] ?? '');
        $name_b = $b['is_shared'] ? ($b['shared_personnel'][0]['personnel_name'] ?? '') : ($b['personnel_name'] ?? '');
        
        return $name_a <=> $name_b;
    });
    
    $processed_room_data[$hotel_name] = $room_data;
}

// 按日期统计房型数据（新添加的查询）
// 标准化酒店名称：提取中文名部分进行统计
// 重新设计：按日期显示每日各房型多少间，总数多少间
// 修复共享房间计算逻辑：共享房间无论多少人只算一间房
// 修复问题：当没有筛选特定酒店时，应该合并所有酒店的数据而不是按酒店分组
$daily_room_type_stats_query = "SELECT 
    dates.date,
    hotel_data.room_type,
    SUM(
        CASE 
            WHEN hotel_data.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND hotel_data.shared_room_info IS NOT NULL AND hotel_data.shared_room_info != '' THEN 1
            ELSE hotel_data.room_count
        END
    ) as room_count,
    SUM(
        CASE 
            WHEN hotel_data.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND hotel_data.shared_room_info IS NOT NULL AND hotel_data.shared_room_info != '' THEN 1
            ELSE hotel_data.room_count
        END
    ) as room_nights
FROM (
    -- 生成日期序列，为每个入住记录生成其入住期间的每一天
    SELECT 
        hr.id,
        DATE_ADD(DATE(hr.check_in_date), INTERVAL seq.n DAY) as date
    FROM hotel_reports hr
    CROSS JOIN (
        SELECT a.N + b.N * 10 as n
        FROM 
        (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) a
        CROSS JOIN
        (SELECT 0 AS N UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) b
    ) seq
    WHERE hr.project_id = :project_id 
        AND (CASE 
            WHEN hr.hotel_name LIKE '% - %' THEN SUBSTRING(hr.hotel_name, 1, LOCATE(' - ', hr.hotel_name) - 1)
            ELSE hr.hotel_name
        END = :hotel_name OR :hotel_name = '')
        AND hr.hotel_name IS NOT NULL
        AND DATE_ADD(DATE(hr.check_in_date), INTERVAL seq.n DAY) < hr.check_out_date
) as dates
JOIN (
    -- 修正共享房间分组逻辑：确保共享房间只计算一次
    SELECT 
        MIN(id) as id,
        CASE 
            WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
            ELSE hotel_name
        END as hotel_name,
        room_type,
        room_count,
        shared_room_info,
        check_in_date,
        check_out_date
    FROM hotel_reports 
    WHERE project_id = :project_id 
        AND (CASE 
            WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
            ELSE hotel_name
        END = :hotel_name OR :hotel_name = '')
        AND hotel_name IS NOT NULL
    GROUP BY 
        CASE 
            WHEN hotel_name LIKE '% - %' THEN SUBSTRING(hotel_name, 1, LOCATE(' - ', hotel_name) - 1)
            ELSE hotel_name
        END,
        room_type,
        room_count,
        shared_room_info,
        check_in_date,
        check_out_date,
        CASE 
            WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' THEN shared_room_info
            ELSE CONCAT(id, '-', room_type, '-', room_count)
        END
) as hotel_data ON dates.id = hotel_data.id
GROUP BY dates.date, hotel_data.room_type
ORDER BY dates.date ASC, hotel_data.room_type";

$daily_room_type_stmt = $db->prepare($daily_room_type_stats_query);
$daily_room_type_stmt->bindParam(':project_id', $projectId);
$daily_room_type_stmt->bindParam(':hotel_name', $hotel_id);
$daily_room_type_stmt->execute();
$daily_room_type_stats = $daily_room_type_stmt->fetchAll(PDO::FETCH_ASSOC);

// 设置页面标题
$page_title = $projectName . ' - 房间表二';
$active_page = 'hotel_room_list_2';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        
        .table-hover tbody tr:hover {
            background-color: #f1f3f4;
        }
        
        .hotel-name {
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .personnel-name {
            font-weight: 500;
            white-space: normal;
            word-wrap: break-word; /* 允许长单词换行 */
            word-break: break-all; /* 允许单词内换行 */
        }
        
        .date-display {
            white-space: nowrap;
        }
        
        .room-type-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
            white-space: nowrap;
        }
        
        .room-type-single { background-color: #d1ecf1; color: #0c5460; }
        .room-type-double { background-color: #d4edda; color: #155724; }
        .room-type-suite { background-color: #fff3cd; color: #856404; }
        .room-type-executive { background-color: #f8d7da; color: #721c24; }
        
        .special-requirements {
            white-space: normal; /* 允许换行 */
            word-wrap: break-word; /* 允许长单词换行 */
            word-break: break-word; /* 允许单词内换行 */
            max-width: 200px; /* 保持最大宽度限制 */
            overflow: visible; /* 显示完整内容 */
            text-overflow: clip; /* 不使用省略号 */
        }
        
        /* 调整表格容器和表格样式以适应所有列 */
        .table-responsive {
            overflow-x: visible; /* 改为visible，避免横向滚动 */
            -webkit-overflow-scrolling: touch;
        }
        
        .table {
            width: 100%; /* 确保表格占据容器的100%宽度 */
            white-space: normal; /* 允许内容换行 */
            border-collapse: collapse;
        }
        
        /* 为整个表格添加边框 */
        .table-bordered {
            border: 2px solid #333; /* 加粗边框 */
        }
        
        .table-bordered th,
        .table-bordered td {
            border: 2px solid #333; /* 加粗边框 */
        }
        
        .table-bordered thead th,
        .table-bordered thead td {
            border-bottom-width: 3px; /* 特别加粗表头下边框 */
        }
        
        .table th, .table td {
            vertical-align: middle;
            padding: 0.5rem;
            /* 移除固定宽度，让列根据内容自动调整 */
        }
        
        /* 优化小屏幕显示 */
        @media (max-width: 768px) {
            .table th, .table td {
                padding: 0.25rem;
                font-size: 0.75rem;
            }
        }
        
        /* 设置表格容器居中并限制最大宽度 */
        .table-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 15px;
        }
        
        /* 设置表格样式 */
        .centered-table {
            width: 100%;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
        }
        
        /* 酒店表格之间的间距 */
        .hotel-table-section {
            margin-bottom: 2rem;
        }
        
        /* 酒店标题样式 */
        .hotel-table-title {
            background-color: #f8f9fa;
            padding: 1rem;
            border: 1px solid #dee2e6;
            border-bottom: none;
            border-radius: 0.375rem 0.375rem 0 0;
            margin-bottom: 0;
        }
        
        /* 项目标题样式 */
        .project-title {
            text-align: center;
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            font-size: 1.5rem;
            font-weight: bold;
            color: #495057;
        }
        
        /* 筛选器居中样式 */
        .filter-container {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 15px;
            margin: 20px 0;
            flex-wrap: wrap;
        }
        
        .filter-select {
            width: 250px;
            padding: 8px 12px;
            font-size: 0.9rem;
        }
        
        .filter-buttons {
            display: flex;
            gap: 10px;
        }
        
        .filter-btn {
            padding: 8px 15px;
            font-size: 0.9rem;
        }
        
        /* 导出模式样式 */
        .export-mode .filter-container {
            display: none !important;
        }
        
        .export-mode .download-buttons {
            display: none !important;
        }
        
        /* 旋转动画 */
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .spin {
            animation: spin 1s linear infinite;
        }
        
        /* 姓名与拼音分行显示 */
        .personnel-name-container {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        
        .personnel-name-chinese {
            font-weight: 500;
        }
        
        .personnel-name-pinyin {
            font-size: 0.85em;
            color: #6c757d;
        }
        
        /* 房号列文字缩小加粗 */
        .room-number {
            font-size: 0.9em;
            font-weight: 600;
        }
        
        /* 未分配房间样式 */
        .room-not-assigned {
            font-size: 0.8em;
            color: #adb5bd;
            font-style: italic;
        }
        
        /* 房型列居中并稍微加大字体 */
        .room-type-container {
            text-align: center;
            font-size: 1.1em;
        }
        
        /* 特殊房型徽章样式 */
        .room-type-fuzongtong { 
            background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%); 
            color: #fff; 
        }
        
        .room-type-zongtong { 
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); 
            color: #fff; 
        }
        
        /* 每日房型统计表样式 */
        .daily-room-type-table th {
            text-align: center;
            background-color: #e9ecef;
        }
        
        .daily-room-type-table td:first-child,
        .daily-room-type-table th:first-child {
            background-color: #f8f9fa;
            font-weight: bold;
        }
        
        .text-danger-custom { color: #dc3545 !important; }
        
        /* 共享/独享徽章样式 */
        .sharing-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 600;
            margin-top: 5px;
            margin-bottom: 2px;
        }
        
        .sharing-badge.shared {
            background-color: #e7f3ff;
            color: #0d6efd;
            border: 1px solid #0d6efd;
        }
        
        .sharing-badge.private {
            background-color: #f0f0f0;
            color: #6c757d;
            border: 1px solid #6c757d;
        }
    </style>
</head>
<body>
    <div class="table-container mt-4">
        <!-- 项目标题 -->
        <div class="project-title">
            <?php echo htmlspecialchars($projectName); ?> - 房间表二
        </div>

        <!-- 筛选条件 -->
        <div class="filter-container">
            <form method="GET" id="filterForm" class="d-flex align-items-center gap-3">
                <select class="form-select filter-select" id="hotel_id" name="hotel_id" onchange="submitFilterForm()">
                    <option value="">全部酒店</option>
                    <?php foreach ($hotels as $hotel): ?>
                        <option value="<?php echo htmlspecialchars($hotel['hotel_name']); ?>" 
                                <?php echo $hotel_id == $hotel['hotel_name'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($hotel['hotel_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="filter-buttons">
                    <button type="submit" class="btn btn-primary filter-btn">
                        <i class="bi bi-search"></i> 查询
                    </button>
                    <a href="hotel_room_list_2.php" class="btn btn-outline-secondary filter-btn">
                        <i class="bi bi-arrow-clockwise"></i> 重置
                    </a>
                </div>
            </form>
        </div>

        <!-- 按酒店分组显示数据表格 -->
        <div id="export-area">
            <?php if (!empty($processed_room_data)): ?>
                <?php foreach ($processed_room_data as $hotel_name => $room_data): ?>
                    <?php if (!empty($room_data) && (empty($hotel_id) || $hotel_name == $hotel_id)): ?>
                        <div class="hotel-table-section">
                            <h5 class="hotel-table-title">
                                <i class="bi bi-hotel"></i> <?php echo htmlspecialchars($hotel_name); ?> 房间数据
                            </h5>
                            <div class="table-responsive centered-table">
                                <table class="table table-striped table-hover table-bordered">
                                    <thead class="table-light">
                                        <tr class="text-center">
                                            <th>序号</th>
                                            <th>姓名</th>
                                            <th>证件号</th>
                                            <th>性别</th>
                                            <th>部门</th>
                                            <th>房号</th>
                                            <th>房型</th>
                                            <th>入住时间</th>
                                            <th>退房时间</th>
                                            <th>特殊要求</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($room_data as $index => $room): ?>
                                            <?php if ($room['is_shared'] && isset($room['shared_personnel'])): ?>
                                                <!-- 处理共享房间，显示多行 -->
                                                <?php $rowspan = count($room['shared_personnel']); ?>
                                                <?php foreach ($room['shared_personnel'] as $sub_index => $person): ?>
                                                    <tr>
                                                        <?php if ($sub_index === 0): ?>
                                                            <!-- 第一行显示合并的单元格 -->
                                                            <td class="text-center" rowspan="<?php echo $rowspan; ?>"><?php echo $index + 1; ?></td>
                                                        <?php endif; ?>
                                                        
                                                        <td class="text-center personnel-name">
                                                            <?php 
                                                                // 检查姓名是否包含中文字符并添加拼音
                                                                if (!empty($person['personnel_name'])) {
                                                                    $name = $person['personnel_name'];
                                                                    // 获取拼音
                                                                    $pinyin = getPinyinByDocType($name, $person['id_card'] ?? '');
                                                                    
                                                                    // 根据性别添加称谓前缀
                                                                    $prefix = '';
                                                                    if (!empty($person['gender'])) {
                                                                        if ($person['gender'] === '男') {
                                                                            $prefix = 'Mr. ';
                                                                        } elseif ($person['gender'] === '女') {
                                                                            $prefix = 'Ms. ';
                                                                        }
                                                                    }
                                                                    
                                                                    // 姓名与拼音分行显示
                                                                    echo '<div class="personnel-name-container">';
                                                                    echo '<div class="personnel-name-chinese">' . htmlspecialchars($name) . '</div>';
                                                                    if (!empty($pinyin)) {
                                                                        echo '<div class="personnel-name-pinyin">' . $prefix . $pinyin . '</div>';
                                                                    }
                                                                    echo '</div>';
                                                                }
                                                            ?>
                                                        </td>
                                                        <td class="text-center"><?php echo htmlspecialchars($person['id_card'] ?? ''); ?></td>
                                                        <td class="text-center"><?php echo htmlspecialchars($person['gender'] ?? ''); ?></td>
                                                        <td class="text-center"><?php echo htmlspecialchars($person['department_name'] ?? ''); ?></td>
                                                        
                                                        <?php if ($sub_index === 0): ?>
                                                            <!-- 第一行显示房间信息 -->
                                                            <td class="text-center" rowspan="<?php echo $rowspan; ?>">
                                                                <?php if (!empty($room['room_number'])): ?>
                                                                    <span class="room-number"><?php echo htmlspecialchars($room['room_number']); ?></span>
                                                                <?php else: ?>
                                                                    <span class="room-not-assigned">未分配</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="text-center" rowspan="<?php echo $rowspan; ?>">
                                                                <?php 
                                                                $type = $room['room_type'];
                                                                $badgeClass = '';
                                                                switch ($type) {
                                                                    case '大床房': $badgeClass = 'room-type-single'; break;
                                                                    case '双床房': $badgeClass = 'room-type-double'; break;
                                                                    case '套房': $badgeClass = 'room-type-suite'; break;
                                                                    case '行政房': $badgeClass = 'room-type-executive'; break;
                                                                    case '副总统套房': $badgeClass = 'room-type-fuzongtong'; break;
                                                                    case '总统套房': $badgeClass = 'room-type-zongtong'; break;
                                                                    default: $badgeClass = 'room-type-badge';
                                                                }
                                                                
                                                                // 显示房型徽章，居中并稍微加大字体
                                                                echo '<div class="room-type-container">';
                                                                echo '<span class="room-type-badge ' . $badgeClass . '">' . htmlspecialchars($type) . '</span>';
                                                                echo '</div>';
                                                                ?>
                                                            </td>
                                                            <td class="text-center" rowspan="<?php echo $rowspan; ?>" class="date-display"><?php echo date('m/d', strtotime($room['check_in_date'])); ?></td>
                                                            <td class="text-center" rowspan="<?php echo $rowspan; ?>" class="date-display"><?php echo date('m/d', strtotime($room['check_out_date'])); ?></td>
                                                            
                                                            <td class="text-center" rowspan="<?php echo $rowspan; ?>" class="special-requirements" title="<?php echo htmlspecialchars($room['special_requirements'] ?? ''); ?>">
                                                                <?php echo htmlspecialchars($room['special_requirements'] ?? ''); ?>
                                                            </td>
                                                        <?php endif; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <!-- 处理非共享房间，正常显示 -->
                                                <tr>
                                                    <td class="text-center"><?php echo $index + 1; ?></td>
                                                    <td class="text-center">
                                                        <?php 
                                                            // 检查姓名是否包含中文字符并添加拼音
                                                            if (!empty($room['personnel_name'])) {
                                                                $name = $room['personnel_name'];
                                                                // 获取拼音
                                                                $pinyin = getPinyinByDocType($name, $room['id_card'] ?? '');
                                                                
                                                                // 根据性别添加称谓前缀
                                                                $prefix = '';
                                                                if (!empty($room['gender'])) {
                                                                    if ($room['gender'] === '男') {
                                                                        $prefix = 'Mr. ';
                                                                    } elseif ($room['gender'] === '女') {
                                                                        $prefix = 'Ms. ';
                                                                    }
                                                                }
                                                                
                                                                // 姓名与拼音分行显示
                                                                echo '<div class="personnel-name-container">';
                                                                echo '<div class="personnel-name-chinese">' . htmlspecialchars($name) . '</div>';
                                                                if (!empty($pinyin)) {
                                                                    echo '<div class="personnel-name-pinyin">' . $prefix . $pinyin . '</div>';
                                                                }
                                                                echo '</div>';
                                                            }
                                                        ?>
                                                    </td>
                                                    <td class="text-center"><?php echo htmlspecialchars($room['id_card'] ?? ''); ?></td>
                                                    <td class="text-center"><?php echo htmlspecialchars($room['gender'] ?? ''); ?></td>
                                                    <td class="text-center"><?php echo htmlspecialchars($room['department_name'] ?? ''); ?></td>
                                                    <td class="text-center">
                                                        <?php if (!empty($room['room_number'])): ?>
                                                            <span class="room-number"><?php echo htmlspecialchars($room['room_number']); ?></span>
                                                        <?php else: ?>
                                                            <span class="room-not-assigned">未分配</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php 
                                                        $type = $room['room_type'];
                                                        $badgeClass = '';
                                                        switch ($type) {
                                                            case '大床房': $badgeClass = 'room-type-single'; break;
                                                            case '双床房': $badgeClass = 'room-type-double'; break;
                                                            case '套房': $badgeClass = 'room-type-suite'; break;
                                                            case '行政房': $badgeClass = 'room-type-executive'; break;
                                                            case '副总统套房': $badgeClass = 'room-type-fuzongtong'; break;
                                                            case '总统套房': $badgeClass = 'room-type-zongtong'; break;
                                                            default: $badgeClass = 'room-type-badge';
                                                        }
                                                        
                                                        // 显示房型徽章，居中并稍微加大字体
                                                        echo '<div class="room-type-container">';
                                                        echo '<span class="room-type-badge ' . $badgeClass . '">' . htmlspecialchars($type) . '</span>';
                                                        // 移除共享/独立徽章显示
                                                        echo '</div>';
                                                        ?>
                                                    </td>
                                                    <td class="text-center date-display"><?php echo date('m/d', strtotime($room['check_in_date'])); ?></td>
                                                    <td class="text-center date-display"><?php echo date('m/d', strtotime($room['check_out_date'])); ?></td>
                                                    <td class="text-center special-requirements" title="<?php echo htmlspecialchars($room['special_requirements'] ?? ''); ?>">
                                                        <?php echo htmlspecialchars($room['special_requirements'] ?? ''); ?>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="bi bi-info-circle text-muted" style="font-size: 3rem;"></i>
                    <p class="text-muted mt-3">
                        <?php if (!empty($hotel_id)): ?>
                            <?php echo htmlspecialchars($hotel_id); ?> 暂无房间数据
                        <?php else: ?>
                            当前项目暂无房间数据
                        <?php endif; ?>
                    </p>
                    <a href="hotels.php" class="btn btn-primary">
                        <i class="bi bi-plus-circle"></i> 添加酒店预订
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- 按日期房型统计（新添加的部分） -->
        <?php if (!empty($daily_room_type_stats)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-calendar-check"></i> 每日房型统计</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <!-- 创建一个透视表，按日期作为行，房型作为列 -->
                    <?php
                    // 处理数据，构建透视表
                    $room_types = [];
                    $data_matrix = [];
                    
                    // 收集所有房型
                    foreach ($daily_room_type_stats as $stat) {
                        $room_type = $stat['room_type'];
                        if (!in_array($room_type, $room_types)) {
                            $room_types[] = $room_type;
                        }
                        
                        // 构建数据矩阵
                        $date = $stat['date'];
                        if (!isset($data_matrix[$date])) {
                            $data_matrix[$date] = [];
                        }
                        
                        if (!isset($data_matrix[$date][$room_type])) {
                            $data_matrix[$date][$room_type] = [
                                'room_count' => 0,
                                'room_nights' => 0
                            ];
                        }
                        
                        $data_matrix[$date][$room_type]['room_count'] += $stat['room_count'];
                        $data_matrix[$date][$room_type]['room_nights'] += $stat['room_nights'];
                    }
                    
                    // 生成从项目开始日期到结束日期的所有日期
                    $dates = [];
                    $current = strtotime($project_start_date);
                    $end = strtotime($project_end_date);
                    
                    while ($current <= $end) {
                        $dates[] = date('Y-m-d', $current);
                        $current = strtotime('+1 day', $current);
                    }
                    
                    sort($room_types);
                    ?>
                    
                    <table class="table table-striped table-bordered daily-room-type-table">
                        <thead class="table-light">
                            <tr>
                                <th>日期</th>
                                <?php foreach ($room_types as $room_type): ?>
                                    <th class="text-center"><?php echo htmlspecialchars($room_type); ?></th>
                                <?php endforeach; ?>
                                <th class="text-center">总计</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $daily_totals = []; // 每日总计
                            $room_type_totals = [
                                'room_count' => [],
                                'room_nights' => []
                            ]; // 每种房型总计
                            
                            // 初始化房型总计数组
                            foreach ($room_types as $room_type) {
                                $room_type_totals['room_count'][$room_type] = 0;
                                $room_type_totals['room_nights'][$room_type] = 0;
                            }
                            
                            foreach ($dates as $date): 
                                $daily_total = [
                                    'room_count' => 0,
                                    'room_nights' => 0
                                ];
                            ?>
                                <tr>
                                    <td><?php echo $date; ?></td>
                                    <?php foreach ($room_types as $room_type): ?>
                                        <td class="text-center number-display text-success-custom">
                                            <?php 
                                            $count = isset($data_matrix[$date][$room_type]) ? $data_matrix[$date][$room_type]['room_count'] : 0;
                                            $nights = isset($data_matrix[$date][$room_type]) ? $data_matrix[$date][$room_type]['room_nights'] : 0;
                                            echo $count;
                                            if ($nights > 0 && $nights != $count) {
                                                echo '<br><small class="text-muted">(' . $nights . '晚)</small>';
                                            }
                                            $daily_total['room_count'] += $count;
                                            $daily_total['room_nights'] += $nights;
                                            $room_type_totals['room_count'][$room_type] += $count;
                                            $room_type_totals['room_nights'][$room_type] += $nights;
                                            ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="text-center number-display text-warning-custom">
                                        <?php 
                                        echo $daily_total['room_count'];
                                        if ($daily_total['room_nights'] > 0 && $daily_total['room_nights'] != $daily_total['room_count']) {
                                            echo '<br><small class="text-muted">(' . $daily_total['room_nights'] . '晚)</small>';
                                        }
                                        ?>
                                    </td>
                                    <?php $daily_totals[$date] = $daily_total; ?>
                                </tr>
                            <?php endforeach; ?>
                            <!-- 总计行 -->
                            <tr class="table-primary">
                                <td><strong>总计</strong></td>
                                <?php 
                                $grand_total = [
                                    'room_count' => 0,
                                    'room_nights' => 0
                                ];
                                foreach ($room_types as $room_type): 
                                    $grand_total['room_count'] += $room_type_totals['room_count'][$room_type];
                                    $grand_total['room_nights'] += $room_type_totals['room_nights'][$room_type];
                                ?>
                                    <td class="text-center number-display text-primary-custom">
                                        <strong>
                                            <?php 
                                            echo $room_type_totals['room_count'][$room_type];
                                            if ($room_type_totals['room_nights'][$room_type] > 0 && 
                                                $room_type_totals['room_nights'][$room_type] != $room_type_totals['room_count'][$room_type]) {
                                                echo '<br><small>(' . $room_type_totals['room_nights'][$room_type] . '晚)</small>';
                                            }
                                            ?>
                                        </strong>
                                    </td>
                                <?php endforeach; ?>
                                <td class="text-center number-display text-danger-custom">
                                    <strong>
                                        <?php 
                                        echo $grand_total['room_count'];
                                        if ($grand_total['room_nights'] > 0 && $grand_total['room_nights'] != $grand_total['room_count']) {
                                            echo '<br><small>(' . $grand_total['room_nights'] . '晚)</small>';
                                        }
                                        ?>
                                    </strong>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- 下载按钮区域 -->
        <div class="download-buttons" style="margin:30px 0;text-align:center;">
            <button id="download-img-btn" style="padding:10px 24px;margin-right:18px;font-size:1em;background:#1976d2;color:#fff;border:none;border-radius:6px;cursor:pointer;">以图片下载</button>
            <button id="download-pdf-btn" style="padding:10px 24px;font-size:1em;background:#dc3545;color:#fff;border:none;border-radius:6px;cursor:pointer;">下载为PDF</button>
        </div>
    </div>

    <script>
    function submitFilterForm() {
        const form = document.getElementById('filterForm');
        if (form) {
            form.submit();
        }
    }

    // 一键补全拼音功能
    document.addEventListener('DOMContentLoaded', function() {
        // 表单提交事件处理
        const filterForm = document.getElementById('filterForm');
        if (filterForm) {
            filterForm.addEventListener('submit', function(e) {
                e.preventDefault();
                submitFilterForm();
            });
        }
        
        // 下载功能
        function setExportMode(on) {
            var exportArea = document.querySelector('.table-container');
            if (on) {
                exportArea.classList.add('export-mode');
                window.__exportMode = true;
            } else {
                exportArea.classList.remove('export-mode');
                window.__exportMode = false;
            }
        }

        // 下载为图片
        if (document.getElementById('download-img-btn')) {
            document.getElementById('download-img-btn').onclick = function() {
                setExportMode(true);
                var exportArea = document.querySelector('.table-container');
                html2canvas(exportArea, {
                    scale: 2,
                    useCORS: true,
                    backgroundColor: '#fff'
                }).then(function(canvas) {
                    setExportMode(false);
                    var link = document.createElement('a');
                    link.download = '房间表二_<?php echo date("Ymd_His"); ?>.png';
                    link.href = canvas.toDataURL('image/png');
                    link.click();
                });
            };
        }

        // 下载为PDF
        if (document.getElementById('download-pdf-btn')) {
            document.getElementById('download-pdf-btn').onclick = function() {
                setExportMode(true);
                var exportArea = document.querySelector('.table-container');
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
                    pdf.save('房间表二_<?php echo date("Ymd_His"); ?>.pdf');
                });
            };
        }
    });
    </script>

    <!-- 引入 html2canvas 和 jsPDF CDN -->
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
</body>
</html>