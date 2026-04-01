<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
// 导出行程表PDF

// 引入TCPDF库
require_once('../vendor/tecnickcom/tcpdf/tcpdf.php');

session_start();
requireLogin();
checkProjectPermission($_SESSION['project_id']);

$projectId = $_SESSION['project_id'];
$projectName = $_SESSION['project_name'] ?? '项目行程表';

// 获取筛选参数
$filter_date = $_GET['date'] ?? '';
$filter_type = $_GET['type'] ?? '';
$filter_status = $_GET['status'] ?? '';

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

// 只选择主行程
$where_conditions[] = "tr.parent_transport_id IS NULL";
$where_clause = implode(' AND ', $where_conditions);

// 获取行程数据
$query = "SELECT 
    tr.id,
    tr.travel_date,
    tr.travel_type,
    tr.departure_time,
    tr.departure_location,
    tr.destination_location,
    tr.status,
    tr.passenger_count,
    tr.contact_phone,
    tr.special_requirements,
    p.name as personnel_name,
    d.name as department_name,
    GROUP_CONCAT(DISTINCT f.fleet_number) as fleet_numbers,
    GROUP_CONCAT(DISTINCT f.license_plate) as license_plates,
    GROUP_CONCAT(DISTINCT f.driver_name) as driver_names
FROM transportation_reports tr 
LEFT JOIN personnel p ON tr.personnel_id = p.id
LEFT JOIN project_department_personnel pdp ON p.id = pdp.personnel_id AND pdp.project_id = tr.project_id
LEFT JOIN departments d ON pdp.department_id = d.id
LEFT JOIN transportation_fleet_assignments tfa ON tr.id = tfa.transportation_report_id
LEFT JOIN fleet f ON tfa.fleet_id = f.id
WHERE {$where_clause}
GROUP BY tr.id, tr.travel_date, tr.travel_type, tr.departure_time, tr.departure_location, tr.destination_location, tr.status, tr.passenger_count, tr.contact_phone, tr.special_requirements, p.name, d.name
ORDER BY tr.travel_date DESC, tr.departure_time DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$transports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 状态映射
$status_map = [
    'pending' => '待处理',
    'assigned' => '已分配',
    'in_progress' => '进行中',
    'completed' => '已完成',
    'cancelled' => '已取消'
];

// 创建PDF
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// 设置文档信息
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('行程管理系统');
$pdf->SetTitle($projectName . ' - 行程表');
$pdf->SetSubject('行程列表');

// 设置默认字体
$pdf->SetFont('stsongstdlight', '', 10);

// 设置边距
$pdf->SetMargins(15, 20, 15);
$pdf->SetHeaderMargin(10);
$pdf->SetFooterMargin(10);

// 添加页面
$pdf->AddPage();

// 设置标题
$pdf->SetFont('stsongstdlight', 'B', 16);
$pdf->Cell(0, 10, $projectName . ' - 行程表', 0, 1, 'C');

// 添加筛选条件信息
$filter_info = [];
if ($filter_date) $filter_info[] = '日期: ' . $filter_date;
if ($filter_type) $filter_info[] = '类型: ' . $filter_type;
if ($filter_status) $filter_info[] = '状态: ' . $status_map[$filter_status] ?? $filter_status;

if (!empty($filter_info)) {
    $pdf->SetFont('stsongstdlight', '', 10);
    $pdf->Cell(0, 8, '筛选条件: ' . implode(', ', $filter_info), 0, 1, 'L');
}

$pdf->Cell(0, 5, '导出时间: ' . date('Y-m-d H:i:s'), 0, 1, 'L');
$pdf->Ln(5);

// 创建表头
$pdf->SetFont('stsongstdlight', 'B', 9);
$pdf->SetFillColor(240, 240, 240);
$pdf->Cell(15, 8, 'ID', 1, 0, 'C', true);
$pdf->Cell(20, 8, '日期', 1, 0, 'C', true);
$pdf->Cell(15, 8, '类型', 1, 0, 'C', true);
$pdf->Cell(20, 8, '时间', 1, 0, 'C', true);
$pdf->Cell(35, 8, '出发地点', 1, 0, 'C', true);
$pdf->Cell(35, 8, '目的地点', 1, 0, 'C', true);
$pdf->Cell(25, 8, '乘车人', 1, 0, 'C', true);
$pdf->Cell(10, 8, '人数', 1, 0, 'C', true);
$pdf->Cell(20, 8, '状态', 1, 0, 'C', true);
$pdf->Cell(25, 8, '车辆/司机', 1, 1, 'C', true);

// 设置内容字体
$pdf->SetFont('stsongstdlight', '', 8);

// 填充数据
foreach ($transports as $transport) {
    $status_text = $status_map[$transport['status']] ?? $transport['status'];
    $time = $transport['departure_time'] ? substr($transport['departure_time'], 0, 5) : '未指定';
    
    // 处理车辆信息
    $vehicle_info = '未分配';
    if ($transport['fleet_numbers']) {
        $fleets = explode(',', $transport['fleet_numbers']);
        $drivers = explode(',', $transport['driver_names'] ?? '');
        $vehicle_info = '';
        foreach ($fleets as $i => $fleet) {
            if ($i > 0) $vehicle_info .= "\n";
            $driver = $drivers[$i] ?? '未指定';
            $vehicle_info .= $fleet . '/' . $driver;
        }
    }
    
    // 设置行高
    $row_height = 12;
    
    $pdf->Cell(15, $row_height, $transport['id'], 1, 0, 'C');
    $pdf->Cell(20, $row_height, $transport['travel_date'], 1, 0, 'C');
    $pdf->Cell(15, $row_height, $transport['travel_type'], 1, 0, 'C');
    $pdf->Cell(20, $row_height, $time, 1, 0, 'C');
    $pdf->Cell(35, $row_height, $transport['departure_location'], 1, 0, 'L');
    $pdf->Cell(35, $row_height, $transport['destination_location'], 1, 0, 'L');
    $pdf->Cell(25, $row_height, $transport['personnel_name'] ?? '未指定', 1, 0, 'L');
    $pdf->Cell(10, $row_height, $transport['passenger_count'], 1, 0, 'C');
    $pdf->Cell(20, $row_height, $status_text, 1, 0, 'C');
    $pdf->MultiCell(25, $row_height, $vehicle_info, 1, 'L');
}

// 添加统计信息
$pdf->Ln(5);
$pdf->SetFont('stsongstdlight', 'B', 10);
$pdf->Cell(0, 8, '统计信息', 0, 1, 'L');
$pdf->SetFont('stsongstdlight', '', 9);
$pdf->Cell(0, 6, '总行程数: ' . count($transports) . ' 条', 0, 1, 'L');

// 输出PDF
$filename = $projectName . '_行程表_' . date('Ymd_His') . '.pdf';
$pdf->Output($filename, 'D');
?>
