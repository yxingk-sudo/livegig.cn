<?php
/**
 * 备份管理配置文件
 */

// 备份管理密码
define('BACKUP_PASSWORD', 'backup123');

// 密码错误尝试次数限制
define('BACKUP_MAX_ATTEMPTS', 5);

// 密码错误锁定时间（秒）
define('BACKUP_LOCKOUT_TIME', 300); // 5分钟
?>