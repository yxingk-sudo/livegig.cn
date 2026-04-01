<?php
/**
 * 强制刷新并显示套餐分配页面
 * 添加防缓存头部
 */

// 禁止缓存
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
header("X-Refresh-Time: " . date('Y-m-d H:i:s'));

// 重定向到原页面
header("Location: meal_package_assign.php?" . time());
exit;
?>
