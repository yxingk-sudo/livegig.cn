<?php
/**
 * 管理后台页面通用函数
 */

/**
 * 获取当前页面标题
 * @return string 页面标题
 */
function get_current_page_title() {
    $current_page = basename($_SERVER['PHP_SELF']);
    $titles = [
        'index.php' => '控制台',
        'companies.php' => '公司管理',
        'projects.php' => '项目管理',
        'personnel.php' => '人员管理',
        'departments.php' => '部门管理',
        'meal_reports.php' => '用餐管理',
        'hotel_reports.php' => '住宿管理',
        'hotel_statistics_admin.php' => '酒店统计管理',
        'transportation_reports.php' => '交通管理',
        'site_config.php' => '网站配置',
        'edit_hotel_report.php' => '编辑入住记录'
    ];
    return $titles[$current_page] ?? '管理后台';
}

/**
 * 获取当前页面标识
 * @return string 当前页面文件名
 */
function getCurrentPage() {
    return basename($_SERVER['PHP_SELF']);
}

?>