<?php
require '../vendor/autoload.php'; // PhpSpreadsheet库

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// 获取行程数据（请替换为实际数据获取逻辑）
$data = [
    // 示例数据结构
    // ['date'=>'7/25(五)','time'=>'1645','group'=>'舞者(2)','人数'=>'10人', ...]
];

// 创建Excel
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// 设置表头及样式
$sheet->mergeCells('A1:H1');
$sheet->setCellValue('A1', 'AGA ONEDERFUL LIVE 江滢珊中国巡回演唱会 - 深圳站 - 草莓素');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12)->getColor()->setRGB('000000');
$sheet->getRowDimension('1')->setRowHeight(24);

$sheet->mergeCells('A2:H2');
$sheet->setCellValue('A2', '深圳湾体育中心“春茧”体育馆（酒店及场馆距离约15分钟）');
$sheet->getStyle('A2')->getFont()->setSize(10);

$sheet->mergeCells('A3:H3');
$sheet->setCellValue('A3', '2004年7月25日 至 27日');
$sheet->getStyle('A3')->getFont()->setSize(10);

// 表头
$headers = ['日期', '班组/组别', '时间', '搭乘人数', '行程', '搭乘人员', '接送车辆/司机电话'];
$sheet->fromArray($headers, NULL, 'A4');
$sheet->getStyle('A4:H4')->getFont()->setBold(true);
$sheet->getStyle('A4:H4')->getFill()->setFillType('solid')->getStartColor()->setRGB('EDEDED');

// 设置列宽
$sheet->getColumnDimension('A')->setWidth(12);
$sheet->getColumnDimension('B')->setWidth(16);
$sheet->getColumnDimension('C')->setWidth(8);
$sheet->getColumnDimension('D')->setWidth(10);
$sheet->getColumnDimension('E')->setWidth(18);
$sheet->getColumnDimension('F')->setWidth(22);
$sheet->getColumnDimension('G')->setWidth(30);

// 填充数据
$row = 5;
foreach ($data as $item) {
    $sheet->setCellValue("A{$row}", $item['date']);
    $sheet->setCellValue("B{$row}", $item['group']);
    $sheet->setCellValue("C{$row}", $item['time']);
    $sheet->setCellValue("D{$row}", $item['人数']);
    $sheet->setCellValue("E{$row}", $item['行程']);
    $sheet->setCellValue("F{$row}", $item['搭乘人员']);
    $sheet->setCellValue("G{$row}", $item['接送车辆']);
    // 可根据需要设置行高、字体、背景色等
    $row++;
}

// 输出Excel文件
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="行程表.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
