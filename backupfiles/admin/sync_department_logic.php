<?php
// 部门归属逻辑统一脚本
require_once '../config/database.php';

header('Content-Type: text/html; charset=utf-8');

echo '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>部门归属逻辑统一</title>
    <link href="assets/css/app.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <h1>部门归属逻辑统一</h1>
        
        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h3>解决方案说明</h3>
                    </div>
                    <div class="card-body">
                        <h5>当前问题：</h5>
                        <ul>
                            <li><code>transportation_reports.php</code> 使用人员表的 <code>department_id</code> 字段</li>
                            <li><code>personnel_enhanced.php</code> 使用 <code>project_department_personnel</code> 表的关联</li>
                            <li>两个系统显示的人员部门归属不一致</li>
                        </ul>
                        
                        <h5>统一方案：</h5>
                        <ol>
                            <li><strong>方案一</strong>：修改 transportation_reports.php，使用项目部门关联</li>
                            <li><strong>方案二</strong>：同步人员表 department_id 与项目部门关联</li>
                            <li><strong>方案三</strong>：创建统一视图</li>
                        </ol>
                        
                        <div class="alert alert-warning">
                            <strong>推荐：</strong>使用方案一，统一使用项目部门关联逻辑
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h3>操作选项</h3>
                    </div>
                    <div class="card-body">
                        <a href="debug_department_consistency.php" class="btn btn-info mb-2 w-100">查看详细对比</a>
                        <a href="?action=update_transportation" class="btn btn-primary mb-2 w-100">更新 transportation_reports.php</a>
                        <a href="?action=sync_data" class="btn btn-secondary mb-2 w-100">同步部门数据</a>
                        <a href="?action=create_view" class="btn btn-success mb-2 w-100">创建统一视图</a>
                    </div>
                </div>
            </div>
        </div>';

// 创建数据库连接
$database = new Database();
$pdo = $database->getConnection();

if (!$pdo) {
    $host = 'localhost';
    $username = 'root';
    $password = '';
    $dbname = 'team_reception';

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch(PDOException $e) {
        die('<div class="alert alert-danger">数据库连接失败: ' . $e->getMessage() . '</div>');
    }
}

// 处理不同操作
$action = $_GET['action'] ?? '';

if ($action === 'update_transportation') {
    echo '<div class="card mt-4">
        <div class="card-header">
            <h3>更新 transportation_reports.php 的部门查询逻辑</h3>
        </div>
        <div class="card-body">';
    
    // 读取原始文件内容
    $file_path = 'transportation_reports.php';
    if (file_exists($file_path)) {
        $content = file_get_contents($file_path);
        
        // 查找需要替换的查询部分
        $patterns = [
            // 行程列表查询中的部门关联
            '/LEFT JOIN departments d ON pr\.department_id = d\.id/' => 'LEFT JOIN project_department_personnel pdp ON pdp.personnel_id = tr.personnel_id AND pdp.project_id = (SELECT project_id FROM transportation_reports WHERE id = tr.id LIMIT 1) LEFT JOIN departments d ON pdp.department_id = d.id',
            
            // 乘客查询中的部门关联
            '/LEFT JOIN departments d ON pr\.department_id = d\.id/' => 'LEFT JOIN project_department_personnel pdp ON pdp.personnel_id = tp.personnel_id AND pdp.project_id = (SELECT project_id FROM transportation_reports WHERE id = tp.transportation_report_id LIMIT 1) LEFT JOIN departments d ON pdp.department_id = d.id',
            
            // 部门名称字段
            '/d\.name as department_name/' => 'COALESCE(d.name, "未分配部门") as department_name'
        ];
        
        $new_content = $content;
        $changes_made = false;
        
        foreach ($patterns as $pattern => $replacement) {
            if (preg_match($pattern, $new_content)) {
                $new_content = preg_replace($pattern, $replacement, $new_content);
                $changes_made = true;
                echo '<div class="alert alert-success">已替换模式: ' . htmlspecialchars($pattern) . '</div>';
            }
        }
        
        if ($changes_made) {
            // 创建备份
            $backup_path = 'transportation_reports_backup_' . date('Ymd_His') . '.php';
            file_put_contents($backup_path, $content);
            
            // 写入新文件
            file_put_contents($file_path, $new_content);
            
            echo '<div class="alert alert-success">
                <strong>更新成功！</strong><br>
                已创建备份文件: ' . $backup_path . '<br>
                已更新 transportation_reports.php 使用项目部门关联逻辑
            </div>';
        } else {
            echo '<div class="alert alert-warning">未找到需要替换的查询模式</div>';
        }
    } else {
        echo '<div class="alert alert-danger">文件不存在: ' . $file_path . '</div>';
    }
    
    echo '</div></div>';
}

if ($action === 'sync_data') {
    echo '<div class="card mt-4">
        <div class="card-header">
            <h3>同步人员表 department_id 与项目部门关联</h3>
        </div>
        <div class="card-body">';
    
    try {
        // 开始事务
        $pdo->beginTransaction();
        
        // 1. 获取每个人员的第一个项目部门
        $stmt = $pdo->query("
            SELECT 
                p.id as personnel_id,
                MIN(pdp.department_id) as first_department_id
            FROM personnel p
            LEFT JOIN project_department_personnel pdp ON p.id = pdp.personnel_id
            WHERE pdp.department_id IS NOT NULL
            GROUP BY p.id
        ");
        
        $sync_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 2. 更新人员表 department_id
        $update_stmt = $pdo->prepare("UPDATE personnel SET department_id = ? WHERE id = ?");
        $updated_count = 0;
        
        foreach ($sync_data as $data) {
            $update_stmt->execute([$data['first_department_id'], $data['personnel_id']]);
            $updated_count++;
        }
        
        $pdo->commit();
        
        echo '<div class="alert alert-success">
            <strong>同步完成！</strong><br>
            已更新 ' . $updated_count . ' 条人员记录
        </div>';
        
        // 显示更新结果
        $stmt = $pdo->query("
            SELECT 
                p.id,
                p.name,
                d.name as new_department,
                (SELECT d2.name FROM project_department_personnel pdp2 
                 JOIN departments d2 ON pdp2.department_id = d2.id 
                 WHERE pdp2.personnel_id = p.id LIMIT 1) as project_department
            FROM personnel p
            LEFT JOIN departments d ON p.department_id = d.id
            WHERE p.department_id IS NOT NULL AND p.department_id != 0
            ORDER BY p.id DESC LIMIT 10
        ");
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo '<h4>最近更新的记录:</h4>';
        echo '<table class="table table-bordered table-sm">
            <thead class="table-light"><tr><th>ID</th><th>姓名</th><th>新部门</th><th>项目部门</th></tr></thead>
            <tbody>';
        foreach ($results as $row) {
            echo "<tr><td>{$row['id']}</td><td>{$row['name']}</td><td>{$row['new_department']}</td><td>{$row['project_department']}</td></tr>";
        }
        echo '</tbody></table>';
        
    } catch(PDOException $e) {
        $pdo->rollBack();
        echo '<div class="alert alert-danger">同步失败: ' . $e->getMessage() . '</div>';
    }
    
    echo '</div></div>';
}

if ($action === 'create_view') {
    echo '<div class="card mt-4">
        <div class="card-header">
            <h3>创建统一部门归属视图</h3>
        </div>
        <div class="card-body">';
    
    try {
        // 删除已存在的视图
        $pdo->exec("DROP VIEW IF EXISTS personnel_department_view");
        
        // 创建统一视图
        $create_view_sql = "
            CREATE VIEW personnel_department_view AS
            SELECT 
                p.id,
                p.name,
                p.phone,
                p.email,
                COALESCE(
                    (SELECT d.name 
                     FROM project_department_personnel pdp 
                     JOIN departments d ON pdp.department_id = d.id 
                     WHERE pdp.personnel_id = p.id 
                     ORDER BY pdp.project_id LIMIT 1),
                    (SELECT d2.name FROM departments d2 WHERE d2.id = p.department_id),
                    '未分配部门'
                ) as department_name,
                COALESCE(
                    (SELECT d.id 
                     FROM project_department_personnel pdp 
                     JOIN departments d ON pdp.department_id = d.id 
                     WHERE pdp.personnel_id = p.id 
                     ORDER BY pdp.project_id LIMIT 1),
                    p.department_id,
                    NULL
                ) as department_id
            FROM personnel p
        ";
        
        $pdo->exec($create_view_sql);
        
        echo '<div class="alert alert-success">
            <strong>视图创建成功！</strong><br>
            视图名称: <code>personnel_department_view</code>
        </div>';
        
        // 显示视图数据示例
        $stmt = $pdo->query("SELECT * FROM personnel_department_view ORDER BY id LIMIT 10");
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo '<h4>视图数据示例:</h4>';
        echo '<table class="table table-bordered table-sm">
            <thead class="table-light"><tr><th>ID</th><th>姓名</th><th>部门</th></tr></thead>
            <tbody>';
        foreach ($results as $row) {
            echo "<tr><td>{$row['id']}</td><td>{$row['name']}</td><td>{$row['department_name']}</td></tr>";
        }
        echo '</tbody></table>';
        
        echo '<div class="alert alert-info">
            <strong>使用说明:</strong><br>
            现在可以在任何查询中使用 <code>personnel_department_view</code> 视图来获取统一的部门归属信息<br>
            例如: <code>SELECT * FROM personnel_department_view WHERE id = 1</code>
        </div>';
        
    } catch(PDOException $e) {
        echo '<div class="alert alert-danger">视图创建失败: ' . $e->getMessage() . '</div>';
    }
    
    echo '</div></div>';
}

echo '</div>
</body>
</html>';
?>