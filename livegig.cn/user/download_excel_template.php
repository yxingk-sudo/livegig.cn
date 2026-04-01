<?php
/**
 * 人员导入Excel模板下载
 * 生成CSV格式的模板文件（可被Excel打开）
 */

// 设置响应头
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="personnel_import_template.csv"');

// 创建输出流
$output = fopen('php://output', 'w');

// 添加UTF-8 BOM（让Excel正确识别中文）
fputs($output, "\xEF\xBB\xBF");

// 写入标题行
fputcsv($output, [
    '姓名 (Name) *必填',
    '邮箱 (Email)',
    '电话/手机 (Phone)',
    '身份证/证件号 (ID Card)',
    '性别 (Gender)',
    '部门 (Department)',
    '职位 (Position)'
]);

// 写入示例数据
fputcsv($output, ['张三', 'zhangsan@example.com', '13800138000', '440801199001011234', '男', '技术部', '工程师']);
fputcsv($output, ['李四', 'lisi@example.com', '13900139000', '440801199002022345', '女', '市场部', '经理']);
fputcsv($output, ['John Smith', 'john.smith@example.com', '13700137000', 'P12345678', '男', '国际部', 'Director']);
fputcsv($output, ['王五', '', '', '', '', '人事部', '专员']);
fputcsv($output, ['赵六', '', '13600136000', '', '女', '', '助理']);

// 写入说明
fputs($output, "\n");
fputs($output, "填写说明：\n");
fputs($output, "1. 姓名列必填，其他列可选\n");
fputs($output, "2. 性别可填：男/女/M/F/male/female\n");
fputs($output, "3. 证件号支持：身份证、护照(P开头)、港澳通行证(H/M开头)\n");
fputs($output, "4. 部门名称需与系统中已创建的部门一致\n");
fputs($output, "5. 保存为CSV格式后可直接上传导入\n");

fclose($output);
exit;
?>
