<?php
session_start();
require_once '../config/database.php';
require_once 'page_functions.php';

// 检查管理员是否已登录
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();

include 'includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <h2 class="mb-4">自动添加标准部门</h2>
            
            <?php
            try {
                // 获取标准部门列表
                $standard_departments = [
                    '导演组' => '负责项目整体创意和艺术指导',
                    '制片组' => '负责项目预算、进度和资源协调',
                    '摄影组' => '负责项目拍摄工作',
                    '灯光组' => '负责项目灯光布设',
                    '录音组' => '负责项目录音工作',
                    '美术组' => '负责项目美术设计和布景',
                    '化妆组' => '负责演员化妆和造型',
                    '服装组' => '负责演员服装设计和管理',
                    '道具组' => '负责项目道具准备和管理',
                    '场务组' => '负责现场秩序和后勤保障',
                    '后期组' => '负责项目后期制作',
                    '宣传组' => '负责项目宣传推广',
                    '餐饮组' => '负责项目餐饮服务',
                    '交通组' => '负责项目交通安排'
                ];

                // 获取所有项目
                $stmt = $db->prepare("SELECT id, name FROM projects ORDER BY name");
                $stmt->execute();
                $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($projects)) {
                    echo '<div class="alert alert-warning">当前没有项目，请先创建项目。</div>';
                } else {
                    echo '<div class="card">';
                    echo '<div class="card-header"><h5 class="mb-0">添加结果</h5></div>';
                    echo '<div class="card-body">';
                    
                    $total_added = 0;
                    foreach ($projects as $project) {
                        echo '<h6>' . htmlspecialchars($project['name']) . '</h6>';
                        echo '<ul>';
                        
                        $added_count = 0;
                        foreach ($standard_departments as $dept_name => $description) {
                            // 检查部门是否已存在
                            $check_stmt = $db->prepare("SELECT id FROM departments WHERE project_id = ? AND name = ?");
                            $check_stmt->execute([$project['id'], $dept_name]);
                            
                            if (!$check_stmt->fetch()) {
                                // 添加部门
                                $insert_stmt = $db->prepare("INSERT INTO departments (project_id, name, description) VALUES (?, ?, ?)");
                                $insert_stmt->execute([$project['id'], $dept_name, $description]);
                                echo '<li>已添加：' . htmlspecialchars($dept_name) . '</li>';
                                $added_count++;
                                $total_added++;
                            } else {
                                echo '<li>已存在：' . htmlspecialchars($dept_name) . '</li>';
                            }
                        }
                        
                        echo '</ul>';
                        echo '<p>项目 "' . htmlspecialchars($project['name']) . '" 添加了 ' . $added_count . ' 个新部门。</p>';
                        echo '<hr>';
                    }
                    
                    echo '<div class="alert alert-success">操作完成！总共添加了 ' . $total_added . ' 个新部门。</div>';
                    echo '</div>';
                    echo '</div>';
                }
                
                echo '<p class="mt-3"><a href="departments_enhanced.php" class="btn btn-primary">返回部门管理</a></p>';
                
            } catch (Exception $e) {
                echo '<div class="alert alert-danger">错误：' . $e->getMessage() . '</div>';
            }
            ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>