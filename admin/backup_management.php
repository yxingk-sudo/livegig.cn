<?php
// 确保在任何输出之前启动会话
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once '../config/database.php';
require_once '../includes/functions.php';

// 加载备份配置
if (file_exists('../config/backup_config.php')) {
    require_once '../config/backup_config.php';
} else {
    // 默认配置
    define('BACKUP_PASSWORD', 'backup123');
    define('BACKUP_MAX_ATTEMPTS', 5);
    define('BACKUP_LOCKOUT_TIME', 300);
}

// 检查用户是否已登录
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

// 备份管理页面密码保护
// 初始化消息变量
$message = '';
$message_type = '';

// 检查是否已通过密码验证
$backup_authenticated = isset($_SESSION['backup_authenticated']) && $_SESSION['backup_authenticated'] === true;

// 处理密码提交
if (isset($_POST['backup_password'])) {
    // 检查是否被锁定
    $lockout_key = 'backup_lockout_' . $_SERVER['REMOTE_ADDR'];
    $attempts_key = 'backup_attempts_' . $_SERVER['REMOTE_ADDR'];
    
    // 检查锁定时间
    if (isset($_SESSION[$lockout_key]) && $_SESSION[$lockout_key] > time()) {
        $remaining_time = $_SESSION[$lockout_key] - time();
        $message = "密码错误次数过多，请在 " . ceil($remaining_time / 60) . " 分钟后再试。";
        $message_type = "error";
    } else {
        if ($_POST['backup_password'] === BACKUP_PASSWORD) {
            // 密码正确，重置尝试次数并设置认证状态
            $_SESSION['backup_authenticated'] = true;
            $_SESSION[$attempts_key] = 0;
            unset($_SESSION[$lockout_key]);
            $backup_authenticated = true;
        } else {
            // 密码错误，增加尝试次数
            $_SESSION[$attempts_key] = isset($_SESSION[$attempts_key]) ? $_SESSION[$attempts_key] + 1 : 1;
            
            // 检查是否超过最大尝试次数
            if ($_SESSION[$attempts_key] >= BACKUP_MAX_ATTEMPTS) {
                $_SESSION[$lockout_key] = time() + BACKUP_LOCKOUT_TIME;
                $message = "密码错误次数过多，账户已被锁定 " . (BACKUP_LOCKOUT_TIME / 60) . " 分钟。";
            } else {
                $remaining_attempts = BACKUP_MAX_ATTEMPTS - $_SESSION[$attempts_key];
                $message = "密码错误，还有 " . $remaining_attempts . " 次尝试机会。";
            }
            $message_type = "error";
        }
    }
}

// 如果未通过备份密码验证，显示密码输入页面
if (!$backup_authenticated) {
    $page_title = "备份管理 - 密码验证";
    include 'includes/header.php';
    ?>
    
    <style>
    .password-container {
        max-width: 400px;
        margin: 100px auto;
        padding: 2rem;
        border-radius: 10px;
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        background-color: #fff;
    }
    .password-form .form-control {
        padding: 0.75rem 1rem;
    }
    .password-form .btn {
        padding: 0.75rem 1rem;
        font-size: 1rem;
    }
    .attempts-info {
        font-size: 0.875rem;
        color: #6c757d;
    }
    </style>
    
    <div class="container">
        <div class="password-container">
            <div class="text-center mb-4">
                <i class="bi bi-shield-lock" style="font-size: 3rem; color: #0d6efd;"></i>
                <h2 class="mt-3">备份管理</h2>
                <p class="text-muted">请输入密码访问备份管理功能</p>
            </div>
            
            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <form method="POST" class="password-form">
                <div class="mb-3">
                    <label for="backup_password" class="form-label">备份管理密码</label>
                    <input type="password" class="form-control" id="backup_password" name="backup_password" required autofocus>
                </div>
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-box-arrow-in-right"></i> 进入备份管理
                    </button>
                </div>
            </form>
            
            <div class="text-center mt-4">
                <a href="index.php" class="text-decoration-none">
                    <i class="bi bi-arrow-left"></i> 返回管理首页
                </a>
            </div>
        </div>
    </div>
    
    <?php
    include 'includes/footer.php';
    exit;
}

// 页面标题
$page_title = "备份管理";

// 获取数据库连接
$database = new Database();
$db = $database->getConnection();

// 重置消息变量（用于备份管理页面）
$message = '';
$message_type = '';

// 处理备份请求
if (isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'backup_site':
            // 检查是否是AJAX请求
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                // AJAX请求，返回JSON响应
                header('Content-Type: application/json');
                // 禁用错误输出到浏览器
                ini_set('display_errors', 0);
                // 捕获所有输出，确保只返回JSON
                ob_start();
                try {
                    // 执行完整网站备份
                    $result = backupSite($db);
                    // 清除缓冲区中的任何输出
                    ob_clean();
                    if ($result['success']) {
                        echo json_encode(['success' => true, 'message' => "网站备份成功创建: " . $result['file']]);
                    } else {
                        echo json_encode(['success' => false, 'message' => "备份失败: " . $result['error']]);
                    }
                } catch (Exception $e) {
                    // 清除缓冲区中的任何输出
                    ob_clean();
                    echo json_encode(['success' => false, 'message' => "备份过程中发生错误: " . $e->getMessage()]);
                }
                // 结束缓冲并发送响应
                ob_end_flush();
                exit;
            } else {
                // 普通POST请求
                $result = backupSite($db);
                if ($result['success']) {
                    $message = "网站备份成功创建: " . $result['file'];
                    $message_type = 'success';
                } else {
                    $message = "备份失败: " . $result['error'];
                    $message_type = 'error';
                }
            }
            break;
            
        case 'download_backup':
            // 下载备份文件
            $file = $_POST['file'] ?? '';
            if ($file) {
                downloadBackup($file);
            }
            break;
            
        case 'delete_backup':
            // 删除备份文件
            $file = $_POST['file'] ?? '';
            if ($file) {
                $result = deleteBackup($file);
                if ($result['success']) {
                    $message = $result['message'];
                    $message_type = 'success';
                } else {
                    $message = $result['error'];
                    $message_type = 'error';
                }
            }
            break;
    }
}

// 获取备份文件列表
$backup_files = getBackupFiles();

// 通过脚本备份网站函数
function backupSiteWithScript() {
    // 在受限环境中，直接返回错误信息
    return ['success' => false, 'error' => '系统禁用了所有执行函数，无法执行备份脚本'];
}

// 备份网站函数 (原始方法)
function backupSite($db) {
    $backup_dir = '/www/wwwroot/livegig.cn/backups';
    $date = date('Y-m-d_H-i-s');
    $backup_name = "site_backup_" . $date;
    $backup_path = $backup_dir . '/' . $backup_name;
    
    try {
        // 检查备份目录是否存在，不存在则创建
        if (!is_dir($backup_dir)) {
            if (!mkdir($backup_dir, 0755, true)) {
                return ['success' => false, 'error' => '无法创建备份目录'];
            }
        }
        
        // 检查备份目录是否可写
        if (!is_writable($backup_dir)) {
            return ['success' => false, 'error' => '备份目录不可写'];
        }
        
        // 创建备份目录
        if (!is_dir($backup_path)) {
            if (!mkdir($backup_path, 0755, true)) {
                return ['success' => false, 'error' => '无法创建备份临时目录'];
            }
        }
        
        // 1. 备份数据库
        $db_backup_result = backupDatabase($db, $backup_path);
        if (!$db_backup_result['success']) {
            // 清理已创建的目录
            if (is_dir($backup_path)) {
                deleteDirectory($backup_path);
            }
            return ['success' => false, 'error' => '数据库备份失败: ' . $db_backup_result['error']];
        }
        
        // 2. 备份网站文件（排除备份目录本身）
        $site_backup_result = backupSiteFiles($backup_path);
        if (!$site_backup_result['success']) {
            // 清理已创建的目录
            if (is_dir($backup_path)) {
                deleteDirectory($backup_path);
            }
            return ['success' => false, 'error' => '网站文件备份失败: ' . $site_backup_result['error']];
        }
        
        // 3. 创建压缩包
        $zip_file = $backup_path . '.zip';
        $zip_result = createZipArchive($backup_path, $zip_file);
        if (!$zip_result['success']) {
            // 清理已创建的目录和文件
            if (is_dir($backup_path)) {
                deleteDirectory($backup_path);
            }
            if (file_exists($zip_file)) {
                unlink($zip_file);
            }
            return ['success' => false, 'error' => '压缩包创建失败: ' . $zip_result['error']];
        }
        
        // 4. 删除临时目录
        if (is_dir($backup_path)) {
            deleteDirectory($backup_path);
        }
        
        // 检查压缩文件是否存在
        if (!file_exists($zip_file)) {
            return ['success' => false, 'error' => '压缩文件创建失败'];
        }
        
        // 设置压缩文件权限
        chmod($zip_file, 0644);
        if (function_exists('chown')) {
            chown($zip_file, 'www');
        }
        if (function_exists('chgrp')) {
            chgrp($zip_file, 'www');
        }
        
        return ['success' => true, 'file' => $backup_name . '.zip'];
    } catch (Exception $e) {
        // 清理已创建的目录
        if (is_dir($backup_path)) {
            deleteDirectory($backup_path);
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// 删除备份文件函数
function deleteBackup($file) {
    $backup_dir = '/www/wwwroot/livegig.cn/backups';
    $file_path = $backup_dir . '/' . $file;
    
    // 验证文件名，防止路径遍历攻击
    if (!preg_match('/^site_backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.zip$/', $file)) {
        return ['success' => false, 'error' => '无效的文件名'];
    }
    
    if (file_exists($file_path) && is_file($file_path)) {
        if (unlink($file_path)) {
            return ['success' => true, 'message' => '备份文件删除成功'];
        } else {
            return ['success' => false, 'error' => '无法删除备份文件'];
        }
    } else {
        return ['success' => false, 'error' => '备份文件不存在'];
    }
}

// 备份数据库函数（使用PHP原生方法）
function backupDatabase($db, $backup_path) {
    try {
        $backup_file = $backup_path . '/database.sql';
        
        // 打开文件用于写入
        $file_handle = fopen($backup_file, 'w');
        if (!$file_handle) {
            return ['success' => false, 'error' => '无法创建数据库备份文件'];
        }
        
        // 获取所有表名
        $tables = [];
        $result = $db->query("SHOW TABLES");
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        
        // 写入头部信息
        $header = "-- Database backup created on " . date('Y-m-d H:i:s') . "\n";
        $header .= "-- Database: team_reception\n";
        $header .= "-- Total tables: " . count($tables) . "\n\n";
        fwrite($file_handle, $header);
        
        // 记录表数量
        fwrite($file_handle, "-- Found " . count($tables) . " tables\n\n");
        
        // 记录表数量
        fwrite($file_handle, "-- Found " . count($tables) . " tables\n\n");
        
        // 备份每个表
        $tables_processed = 0;
        foreach ($tables as $table) {
            $tables_processed++;
            
            // 获取表结构
            $structure = "\n-- Table structure for table `$table` (" . $tables_processed . "/" . count($tables) . ")\n";
            $structure .= "DROP TABLE IF EXISTS `$table`;\n";
            
            // 尝试获取表结构
            try {
                $create_result = $db->query("SHOW CREATE TABLE `$table`");
                if ($create_result) {
                    $create_row = $create_result->fetch(PDO::FETCH_NUM);
                    if ($create_row && isset($create_row[1])) {
                        $structure .= $create_row[1] . ";\n\n";
                    } else {
                        $structure .= "-- Warning: Could not retrieve table structure for `$table`\n\n";
                    }
                } else {
                    $structure .= "-- Warning: Could not retrieve table structure for `$table`\n\n";
                }
            } catch (Exception $e) {
                $structure .= "-- Warning: Error retrieving table structure for `$table`: " . $e->getMessage() . "\n\n";
            }
            
            fwrite($file_handle, $structure);
            
            // 获取表数据（分批处理以避免内存问题）
            $structure = "-- Dumping data for table `$table`\n";
            fwrite($file_handle, $structure);
            
            // 获取表行数
            try {
                $count_result = $db->query("SELECT COUNT(*) FROM `$table`");
                $row_count_total = $count_result->fetchColumn();
                fwrite($file_handle, "-- Total rows: " . $row_count_total . "\n");
            } catch (Exception $e) {
                $row_count_total = 0;
                fwrite($file_handle, "-- Warning: Could not count rows for `$table`: " . $e->getMessage() . "\n");
            }
            
            // 使用分页查询避免内存溢出
            $offset = 0;
            $limit = 1000; // 每次处理1000行
            $rows_processed = 0;
            
            do {
                try {
                    $data_result = $db->query("SELECT * FROM `$table` LIMIT $limit OFFSET $offset");
                    if (!$data_result) {
                        fwrite($file_handle, "-- Warning: Could not retrieve data for `$table` (offset: $offset)\n");
                        break;
                    }
                    
                    $batch_row_count = 0;
                    
                    while ($row = $data_result->fetch(PDO::FETCH_ASSOC)) {
                        $batch_row_count++;
                        $rows_processed++;
                        $sql = "INSERT INTO `$table` VALUES (";
                        $values = [];
                        foreach ($row as $value) {
                            if (is_null($value)) {
                                $values[] = "NULL";
                            } else {
                                $values[] = "'" . addslashes($value) . "'";
                            }
                        }
                        $sql .= implode(", ", $values) . ");\n";
                        fwrite($file_handle, $sql);
                    }
                    
                    $offset += $limit;
                    
                    // 如果这批次少于限制数量，说明已经处理完所有数据
                    if ($batch_row_count < $limit) {
                        break;
                    }
                    
                    // 每处理10000行写入一次进度信息
                    if ($rows_processed % 10000 == 0) {
                        fwrite($file_handle, "-- Processed $rows_processed rows so far\n");
                    }
                    
                } catch (Exception $e) {
                    fwrite($file_handle, "-- Error processing data for `$table` (offset: $offset): " . $e->getMessage() . "\n");
                    break;
                }
                
            } while (true);
            
            fwrite($file_handle, "-- Finished dumping data for `$table`. Rows processed: $rows_processed\n\n");
        }
        
        // 写入总结信息
        fwrite($file_handle, "-- Database backup completed. Tables processed: $tables_processed\n");
        
        // 关闭文件
        fclose($file_handle);
        
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// 备份网站文件函数（使用PHP原生方法）
function backupSiteFiles($backup_path) {
    try {
        $site_root = '/www/wwwroot/livegig.cn';
        $backup_files_dir = $backup_path . '/files';
        
        // 创建文件备份目录
        if (!is_dir($backup_files_dir)) {
            if (!mkdir($backup_files_dir, 0755, true)) {
                return ['success' => false, 'error' => '无法创建文件备份目录'];
            }
        }
        
        // 检查源目录是否存在
        if (!is_dir($site_root)) {
            return ['success' => false, 'error' => '网站根目录不存在'];
        }
        
        // 复制文件（排除备份目录本身）
        copyDirectory($site_root, $backup_files_dir, ['backups', 'backup']);
        
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// 复制目录函数
function copyDirectory($src, $dst, $exclude_dirs = []) {
    $dir = opendir($src);
    if (!is_dir($dst)) {
        mkdir($dst, 0755, true);
    }
    
    while (($file = readdir($dir)) !== false) {
        if ($file != '.' && $file != '..') {
            $src_file = $src . '/' . $file;
            $dst_file = $dst . '/' . $file;
            
            // 检查是否在排除目录中
            if (in_array($file, $exclude_dirs)) {
                continue;
            }
            
            // 处理符号链接
            if (is_link($src_file)) {
                // 获取符号链接指向的目标
                $link_target = readlink($src_file);
                // 创建新的符号链接
                if (file_exists($dst_file)) {
                    unlink($dst_file);
                }
                symlink($link_target, $dst_file);
            } elseif (is_dir($src_file)) {
                copyDirectory($src_file, $dst_file, $exclude_dirs);
            } else {
                // 检查文件是否可读
                if (is_readable($src_file)) {
                    copy($src_file, $dst_file);
                }
            }
        }
    }
    closedir($dir);
}

// 创建压缩包函数
function createZipArchive($source, $destination) {
    try {
        $zip = new ZipArchive();
        if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
            $source = str_replace('\\', '/', realpath($source));
            
            if (is_dir($source) === true) {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($source),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                
                foreach ($files as $file) {
                    $file = str_replace('\\', '/', $file);
                    
                    // 忽略 "." 和 ".." 文件夹
                    if (in_array(substr($file, strrpos($file, '/') + 1), ['.', '..'])) {
                        continue;
                    }
                    
                    $file = realpath($file);
                    $relative_path = substr($file, strlen($source) + 1);
                    
                    // 检查文件是否存在且可读
                    if (is_file($file) && is_readable($file)) {
                        // 检查文件大小，避免添加过大的文件导致内存问题
                        $file_size = filesize($file);
                        if ($file_size !== false) {
                            // 如果文件大于100MB，分块读取
                            if ($file_size > 100 * 1024 * 1024) {
                                // 对于大文件，使用addFile方法
                                $zip->addFile($file, $relative_path);
                            } else {
                                // 对于小文件，使用addFromString方法
                                $content = file_get_contents($file);
                                if ($content !== false) {
                                    $zip->addFromString($relative_path, $content);
                                }
                            }
                        }
                    } elseif (is_dir($file)) {
                        $zip->addEmptyDir($relative_path);
                    }
                }
            }
            
            $zip->close();
            return ['success' => true];
        } else {
            return ['success' => false, 'error' => '无法创建压缩文件'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// 下载备份文件函数
function downloadBackup($file) {
    $backup_dir = '/www/wwwroot/livegig.cn/backups';
    $file_path = $backup_dir . '/' . $file;
    
    // 验证文件名，防止路径遍历攻击
    if (!preg_match('/^site_backup_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.zip$/', $file)) {
        // 重定向回备份页面并显示错误
        $_SESSION['message'] = '无效的文件名';
        $_SESSION['message_type'] = 'error';
        header('Location: backup_management.php');
        exit;
    }
    
    if (file_exists($file_path) && is_file($file_path)) {
        // 检查文件是否可读
        if (!is_readable($file_path)) {
            // 尝试更改文件权限
            if (!chmod($file_path, 0644)) {
                // 重定向回备份页面并显示错误
                $_SESSION['message'] = '备份文件权限不足，无法下载';
                $_SESSION['message_type'] = 'error';
                header('Location: backup_management.php');
                exit;
            }
        }
        
        header('Content-Description: File Transfer');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit;
    } else {
        // 重定向回备份页面并显示错误
        $_SESSION['message'] = '备份文件不存在';
        $_SESSION['message_type'] = 'error';
        header('Location: backup_management.php');
        exit;
    }
}

// 获取备份文件列表函数
function getBackupFiles() {
    $backup_dir = '/www/wwwroot/livegig.cn/backups';
    $files = [];
    
    if (is_dir($backup_dir)) {
        $iterator = new DirectoryIterator($backup_dir);
        foreach ($iterator as $fileinfo) {
            if ($fileinfo->isFile() && $fileinfo->getExtension() === 'zip') {
                $files[] = [
                    'name' => $fileinfo->getFilename(),
                    'size' => formatBytes($fileinfo->getSize()),
                    'date' => date('Y-m-d H:i:s', $fileinfo->getMTime())
                ];
            }
        }
    }
    
    // 按日期排序，最新的在前
    usort($files, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });
    
    return $files;
}

// 格式化字节大小函数
function formatBytes($size, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
        $size /= 1024;
    }
    
    return round($size, $precision) . ' ' . $units[$i];
}

// 删除目录函数
function deleteDirectory($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            unlink($path);
        }
    }
    
    return rmdir($dir);
}

// 检查是否有会话消息
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}
?>

<?php include 'includes/header.php'; ?>

<style>
/* 备份进度条样式 */
#backupProgressContainer {
    transition: all 0.3s ease;
}

.progress {
    height: 20px;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.progress-bar {
    transition: width 0.6s ease;
    font-size: 0.75rem;
    line-height: 20px;
}

/* 备份卡片样式 */
.backup-card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.backup-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

/* 文件大小显示 */
.file-size {
    font-weight: 500;
}

/* 删除按钮样式 */
.delete-btn {
    background-color: #dc3545;
    border-color: #dc3545;
}

.delete-btn:hover {
    background-color: #c82333;
    border-color: #bd2130;
}
</style>

<div class="container-fluid">
    <div class="row">
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">备份管理</h1>
            </div>
            
            <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <div class="row">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-cloud-arrow-up"></i> 创建完整备份
                            </h5>
                        </div>
                        <div class="card-body">
                            <p>点击下面的按钮创建网站和数据库的完整备份。备份将包含：</p>
                            <ul>
                                <li>完整的数据库内容</li>
                                <li>所有网站文件（包括配置、脚本、资源等）</li>
                                <li>生成一个可下载的ZIP压缩包</li>
                            </ul>
                            <p class="text-muted">
                                <i class="bi bi-info-circle"></i> 备份过程可能需要几分钟时间，请耐心等待。
                            </p>
                            
                            <!-- 备份进度条 -->
                            <div id="backupProgressContainer" class="mt-3" style="display: none;">
                                <div class="progress">
                                    <div id="backupProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <div class="d-flex justify-content-between mt-2">
                                    <small id="progressText" class="text-muted">准备备份...</small>
                                    <small id="progressPercent" class="text-muted">0%</small>
                                </div>
                            </div>
                            
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="action" value="backup_site">
                                <button type="submit" class="btn btn-primary" id="backupBtn">
                                    <i class="bi bi-archive"></i> 创建完整备份
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-info-circle"></i> 备份信息
                            </h5>
                        </div>
                        <div class="card-body">
                            <p><strong>备份位置:</strong> /www/wwwroot/livegig.cn/backups/</p>
                            <p><strong>保留策略:</strong> 自动清理30天前的备份</p>
                            <p><strong>安全提醒:</strong> 请妥善保管备份文件</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-folder2"></i> 备份文件列表
                            </h5>
                            <span class="badge bg-secondary"><?php echo count($backup_files); ?> 个文件</span>
                        </div>
                        <div class="card-body">
                            <?php if (empty($backup_files)): ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-folder" style="font-size: 3rem; color: #ddd;"></i>
                                    <p class="mt-3">暂无备份文件</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>文件名</th>
                                                <th>大小</th>
                                                <th>创建时间</th>
                                                <th>操作</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($backup_files as $file): ?>
                                            <tr>
                                                <td>
                                                    <i class="bi bi-file-earmark-zip"></i>
                                                    <?php echo htmlspecialchars($file['name']); ?>
                                                </td>
                                                <td>
                                                    <span class="file-size">
                                                        <i class="bi bi-hdd"></i> <?php echo $file['size']; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <i class="bi bi-calendar"></i> <?php echo $file['date']; ?>
                                                </td>
                                                <td>
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="download_backup">
                                                        <input type="hidden" name="file" value="<?php echo htmlspecialchars($file['name']); ?>">
                                                        <button type="submit" class="btn btn-sm btn-success">
                                                            <i class="bi bi-download"></i> 下载
                                                        </button>
                                                    </form>
                                                    <form method="POST" class="d-inline ms-2" onsubmit="return confirm('确定要删除这个备份文件吗？此操作不可恢复。')">
                                                        <input type="hidden" name="action" value="delete_backup">
                                                        <input type="hidden" name="file" value="<?php echo htmlspecialchars($file['name']); ?>">
                                                        <button type="submit" class="btn btn-sm delete-btn">
                                                            <i class="bi bi-trash"></i> 删除
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // 更新进度条函数
    function updateProgress(percent, text) {
        const progressBar = document.getElementById('backupProgressBar');
        const progressText = document.getElementById('progressText');
        const progressPercent = document.getElementById('progressPercent');
        
        if (progressBar && progressText && progressPercent) {
            progressBar.style.width = percent + '%';
            progressBar.setAttribute('aria-valuenow', percent);
            progressPercent.textContent = percent + '%';
            progressText.textContent = text;
        }
    }
    
    // 显示进度条
    function showProgress() {
        const container = document.getElementById('backupProgressContainer');
        if (container) {
            container.style.display = 'block';
        }
    }
    
    // 隐藏进度条
    function hideProgress() {
        const container = document.getElementById('backupProgressContainer');
        if (container) {
            container.style.display = 'none';
        }
    }
    
    // 显示消息
    function showMessage(message, type) {
        // 移除现有的消息
        const existingAlert = document.querySelector('.alert:not(.alert-permanent)');
        if (existingAlert) {
            existingAlert.remove();
        }
        
        // 创建新的消息元素
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
        alertDiv.role = 'alert';
        alertDiv.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        // 插入到页面顶部
        const container = document.querySelector('.container-fluid') || document.querySelector('main');
        if (container && container.firstChild) {
            container.insertBefore(alertDiv, container.firstChild);
        }
    }
    
    // AJAX备份函数
    function ajaxBackup(form, action) {
        // 显示进度条
        showProgress();
        updateProgress(5, '正在初始化备份...');
        
        // 禁用按钮
        const button = form.querySelector('button');
        const originalHtml = button.innerHTML;
        button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> 正在备份...';
        button.disabled = true;
        
        // 创建FormData对象
        const formData = new FormData(form);
        
        // 发送AJAX请求
        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'backup_management.php', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.timeout = 300000; // 设置5分钟超时
        
        // 监听进度更新（模拟）
        const progressSteps = [
            {percent: 15, text: '正在准备备份环境...'},
            {percent: 30, text: '正在备份数据库...'},
            {percent: 50, text: '正在备份网站文件...'},
            {percent: 70, text: '正在创建压缩包...'},
            {percent: 85, text: '正在清理临时文件...'},
            {percent: 95, text: '正在完成备份...'}
        ];
        
        let step = 0;
        const progressInterval = setInterval(() => {
            if (step < progressSteps.length) {
                updateProgress(progressSteps[step].percent, progressSteps[step].text);
                step++;
            } else {
                clearInterval(progressInterval);
            }
        }, 800);
        
        // 处理响应
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                clearInterval(progressInterval);
                button.innerHTML = originalHtml;
                button.disabled = false;
                
                if (xhr.status === 200) {
                    try {
                        // 检查响应是否为空
                        if (!xhr.responseText) {
                            throw new Error('服务器返回空响应');
                        }
                        
                        // 尝试解析JSON
                        const response = JSON.parse(xhr.responseText);
                        
                        // 检查响应格式是否正确
                        if (typeof response !== 'object' || response === null) {
                            throw new Error('服务器返回无效的JSON格式');
                        }
                        
                        updateProgress(100, '备份完成!');
                        setTimeout(() => {
                            hideProgress();
                            showMessage(response.message, response.success ? 'success' : 'danger');
                            // 如果备份成功，刷新页面以显示新的备份文件
                            if (response.success) {
                                setTimeout(() => {
                                    location.reload();
                                }, 2000);
                            }
                        }, 500);
                    } catch (e) {
                        hideProgress();
                        console.error('解析错误:', e); // 在控制台记录详细错误信息
                        // 显示更友好的错误信息
                        let errorMessage = '响应解析失败: ' + e.message;
                        if (xhr.responseText) {
                            errorMessage += ' (响应内容预览: ' + xhr.responseText.substring(0, 100) + (xhr.responseText.length > 100 ? '...' : '') + ')';
                        }
                        showMessage(errorMessage, 'danger');
                    }
                } else {
                    hideProgress();
                    let errorMessage = '请求失败，状态码: ' + xhr.status;
                    if (xhr.responseText) {
                        errorMessage += ' (响应内容预览: ' + xhr.responseText.substring(0, 100) + (xhr.responseText.length > 100 ? '...' : '') + ')';
                    }
                    showMessage(errorMessage, 'danger');
                }
            }
        };
        
        // 处理网络错误
        xhr.onerror = function() {
            clearInterval(progressInterval);
            button.innerHTML = originalHtml;
            button.disabled = false;
            hideProgress();
            showMessage('网络错误，请检查网络连接', 'danger');
        };
        
        // 处理超时
        xhr.ontimeout = function() {
            clearInterval(progressInterval);
            button.innerHTML = originalHtml;
            button.disabled = false;
            hideProgress();
            showMessage('请求超时，请稍后重试', 'danger');
        };
        
        // 发送请求
        xhr.send(formData);
    }
    
    // 备份按钮点击事件
    const backupBtn = document.getElementById('backupBtn');
    if (backupBtn) {
        backupBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const form = this.closest('form');
            if (form) {
                ajaxBackup(form, 'backup_site');
            } else {
                console.error('未找到表单元素');
                alert('系统错误：无法找到备份表单');
            }
        });
    } else {
        console.warn('未找到备份按钮 #backupBtn');
    }
});
</script>

<?php include 'includes/footer.php'; ?>
