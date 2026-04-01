<?php
// 测试菜单激活逻辑
session_start();

// 模拟页面设置
$current_page = 'fleet_management.php';
echo "当前页面设置: $current_page\n";

// 模拟 sidebar.php 的逻辑
function getCurrentPage() {
    return basename($_SERVER['PHP_SELF']);
}

$sidebar_current_page = getCurrentPage();
echo "Sidebar 获取的页面: $sidebar_current_page\n";

// 检查激活条件
$is_active = ($current_page == 'fleet_management.php');
echo "激活状态: " . ($is_active ? 'ACTIVE' : 'INACTIVE') . "\n";

// 模拟菜单HTML输出
$menu_class = $is_active ? 'active' : 'text-dark';
echo "菜单CSS类: $menu_class\n";

?>