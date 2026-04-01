<?php
session_start();
require_once '../config/database.php';
// 更可靠地包含page_functions.php，如果文件不存在则定义默认函数
$page_functions_path = __DIR__ . '/page_functions.php';
if (file_exists($page_functions_path)) {
    require_once $page_functions_path;
} else {
    // 定义默认的getCurrentPage函数
    if (!function_exists('getCurrentPage')) {
        function getCurrentPage() {
            return basename($_SERVER['PHP_SELF']);
        }
    }
}

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit;
}

$database = new Database();
$db = $database->getConnection();

// 处理表单提交 - 合并功能
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'merge_personnel' && isset($_POST['personnel_ids'])) {
            // 合并人员功能 - 多选合并
            // 处理通过逗号分隔的ID字符串或数组
            if (is_array($_POST['personnel_ids'])) {
                $personnel_ids = array_map('intval', $_POST['personnel_ids']);
            } else {
                $personnel_ids = array_map('intval', explode(',', $_POST['personnel_ids']));
            }
            
            if (count($personnel_ids) < 2) {
                $error = '请至少选择两个人员进行合并！';
            } else {
                // 第一个选中的人作为目标，其余作为源
                $target_id = $personnel_ids[0];
                $source_ids = array_slice($personnel_ids, 1);
                
                try {
                    $db->beginTransaction();
                    
                    // 获取目标人员信息
                    $target_query = "SELECT * FROM personnel WHERE id = :id";
                    $target_stmt = $db->prepare($target_query);
                    $target_stmt->bindParam(':id', $target_id);
                    $target_stmt->execute();
                    $target_person = $target_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$target_person) {
                        $error = '目标人员不存在！';
                    } else {
                        $merged_count = 0;
                        
                        // 遍历所有源人员
                        foreach ($source_ids as $source_id) {
                            if ($source_id == $target_id) {
                                continue; // 跳过自身
                            }
                            
                            // 获取源人员信息
                            $source_query = "SELECT * FROM personnel WHERE id = :id";
                            $source_stmt = $db->prepare($source_query);
                            $source_stmt->bindParam(':id', $source_id);
                            $source_stmt->execute();
                            $source_person = $source_stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($source_person) {
                                // 更新目标人员信息（使用源人员的非空信息）
                                $update_fields = [];
                                $update_params = [':id' => $target_id];
                                
                                // 只有当目标人员的字段为空且源人员字段不为空时才更新
                                if (empty($target_person['email']) && !empty($source_person['email'])) {
                                    $update_fields[] = "email = :email";
                                    $update_params[':email'] = $source_person['email'];
                                }
                                if (empty($target_person['phone']) && !empty($source_person['phone'])) {
                                    $update_fields[] = "phone = :phone";
                                    $update_params[':phone'] = $source_person['phone'];
                                }
                                if (empty($target_person['id_card']) && !empty($source_person['id_card'])) {
                                    $update_fields[] = "id_card = :id_card";
                                    $update_params[':id_card'] = $source_person['id_card'];
                                }
                                
                                if (!empty($update_fields)) {
                                    $update_query = "UPDATE personnel SET " . implode(', ', $update_fields) . " WHERE id = :id";
                                    $update_stmt = $db->prepare($update_query);
                                    $update_stmt->execute($update_params);
                                }
                                
                                // 将源人员的项目分配转移到目标人员（避免重复）
                                $transfer_query = "
                                    INSERT INTO project_department_personnel (project_id, department_id, personnel_id, position, join_date, status)
                                    SELECT project_id, department_id, :target_id, position, join_date, status
                                    FROM project_department_personnel
                                    WHERE personnel_id = :source_id
                                    ON DUPLICATE KEY UPDATE
                                        position = VALUES(position),
                                        join_date = VALUES(join_date),
                                        status = VALUES(status)";
                                $transfer_stmt = $db->prepare($transfer_query);
                                $transfer_stmt->bindParam(':target_id', $target_id);
                                $transfer_stmt->bindParam(':source_id', $source_id);
                                $transfer_stmt->execute();
                                
                                // 删除源人员记录
                                $delete_query = "DELETE FROM personnel WHERE id = :id";
                                $delete_stmt = $db->prepare($delete_query);
                                $delete_stmt->bindParam(':id', $source_id);
                                $delete_stmt->execute();
                                
                                // 删除源人员的项目分配记录
                                $delete_pdp_query = "DELETE FROM project_department_personnel WHERE personnel_id = :id";
                                $delete_pdp_stmt = $db->prepare($delete_pdp_query);
                                $delete_pdp_stmt->bindParam(':id', $source_id);
                                $delete_pdp_stmt->execute();
                                
                                $merged_count++;
                            }
                        }
                        
                        $db->commit();
                        
                        if ($merged_count > 0) {
                            $message = "成功合并 {$merged_count} 个人员到目标人员！";
                        } else {
                            $message = '没有找到有效的源人员进行合并。';
                        }
                    }
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = '合并人员失败：' . $e->getMessage();
                }
            }
        } elseif ($action === 'merge_group' && isset($_POST['group_type']) && isset($_POST['group_value'])) {
            // 按组合并人员功能
            $group_type = $_POST['group_type'];
            $group_value = $_POST['group_value'];
            
            try {
                $db->beginTransaction();
                
                // 根据组类型获取人员ID列表
                if ($group_type === 'name') {
                    $query = "SELECT id FROM personnel WHERE name = :group_value ORDER BY id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':group_value', $group_value);
                } elseif ($group_type === 'id_card') {
                    $query = "SELECT id FROM personnel WHERE id_card = :group_value ORDER BY id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':group_value', $group_value);
                } else {
                    throw new Exception('无效的组类型');
                }
                
                $stmt->execute();
                $personnel_ids = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
                
                if (count($personnel_ids) < 2) {
                    $error = '该组人员少于两人，无法合并！';
                } else {
                    // 第一个人员作为目标，其余作为源
                    $target_id = $personnel_ids[0];
                    $source_ids = array_slice($personnel_ids, 1);
                    
                    // 获取目标人员信息
                    $target_query = "SELECT * FROM personnel WHERE id = :id";
                    $target_stmt = $db->prepare($target_query);
                    $target_stmt->bindParam(':id', $target_id);
                    $target_stmt->execute();
                    $target_person = $target_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$target_person) {
                        $error = '目标人员不存在！';
                    } else {
                        $merged_count = 0;
                        
                        // 遍历所有源人员
                        foreach ($source_ids as $source_id) {
                            if ($source_id == $target_id) {
                                continue; // 跳过自身
                            }
                            
                            // 获取源人员信息
                            $source_query = "SELECT * FROM personnel WHERE id = :id";
                            $source_stmt = $db->prepare($source_query);
                            $source_stmt->bindParam(':id', $source_id);
                            $source_stmt->execute();
                            $source_person = $source_stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($source_person) {
                                // 更新目标人员信息（使用源人员的非空信息）
                                $update_fields = [];
                                $update_params = [':id' => $target_id];
                                
                                // 只有当目标人员的字段为空且源人员字段不为空时才更新
                                if (empty($target_person['email']) && !empty($source_person['email'])) {
                                    $update_fields[] = "email = :email";
                                    $update_params[':email'] = $source_person['email'];
                                }
                                if (empty($target_person['phone']) && !empty($source_person['phone'])) {
                                    $update_fields[] = "phone = :phone";
                                    $update_params[':phone'] = $source_person['phone'];
                                }
                                if (empty($target_person['id_card']) && !empty($source_person['id_card'])) {
                                    $update_fields[] = "id_card = :id_card";
                                    $update_params[':id_card'] = $source_person['id_card'];
                                }
                                
                                if (!empty($update_fields)) {
                                    $update_query = "UPDATE personnel SET " . implode(', ', $update_fields) . " WHERE id = :id";
                                    $update_stmt = $db->prepare($update_query);
                                    $update_stmt->execute($update_params);
                                }
                                
                                // 将源人员的项目分配转移到目标人员（避免重复）
                                $transfer_query = "
                                    INSERT INTO project_department_personnel (project_id, department_id, personnel_id, position, join_date, status)
                                    SELECT project_id, department_id, :target_id, position, join_date, status
                                    FROM project_department_personnel
                                    WHERE personnel_id = :source_id
                                    ON DUPLICATE KEY UPDATE
                                        position = VALUES(position),
                                        join_date = VALUES(join_date),
                                        status = VALUES(status)";
                                $transfer_stmt = $db->prepare($transfer_query);
                                $transfer_stmt->bindParam(':target_id', $target_id);
                                $transfer_stmt->bindParam(':source_id', $source_id);
                                $transfer_stmt->execute();
                                
                                // 删除源人员记录
                                $delete_query = "DELETE FROM personnel WHERE id = :id";
                                $delete_stmt = $db->prepare($delete_query);
                                $delete_stmt->bindParam(':id', $source_id);
                                $delete_stmt->execute();
                                
                                // 删除源人员的项目分配记录
                                $delete_pdp_query = "DELETE FROM project_department_personnel WHERE personnel_id = :id";
                                $delete_pdp_stmt = $db->prepare($delete_pdp_query);
                                $delete_pdp_stmt->bindParam(':id', $source_id);
                                $delete_pdp_stmt->execute();
                                
                                $merged_count++;
                            }
                        }
                        
                        $db->commit();
                        
                        // 修复语法错误：将复杂的表达式移到大括号外面
                        $group_type_text = ($group_type === 'name') ? '同姓名' : '同证件号';
                        $message = "成功合并 {$merged_count} 个{$group_type_text}人员到目标人员！";
                    }
                }
            } catch (Exception $e) {
                $db->rollBack();
                $error = '按组合并人员失败：' . $e->getMessage();
            }
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            // 删除人员
            $id = intval($_POST['id']);
            
            try {
                $db->beginTransaction();
                
                // 删除人员的项目分配记录
                $delete_pdp_query = "DELETE FROM project_department_personnel WHERE personnel_id = :id";
                $delete_pdp_stmt = $db->prepare($delete_pdp_query);
                $delete_pdp_stmt->bindParam(':id', $id);
                $delete_pdp_stmt->execute();
                
                // 删除人员记录
                $delete_query = "DELETE FROM personnel WHERE id = :id";
                $delete_stmt = $db->prepare($delete_query);
                $delete_stmt->bindParam(':id', $id);
                $delete_stmt->execute();
                
                $db->commit();
                $message = '人员删除成功！';
            } catch (Exception $e) {
                $db->rollBack();
                $error = '删除人员失败：' . $e->getMessage();
            }
        }
    }
}

// 获取统计信息
$total_personnel = 0;
$personnel_by_gender = array(); // 初始化为空数组
$personnel_by_project = array(); // 初始化为空数组
$personnel_details = array(); // 初始化为空数组
$duplicate_names_info = array(); // 初始化为空数组
$duplicate_id_cards_info = array(); // 初始化为空数组
$no_id_card_count = 0;
$no_contact_count = 0;

try {
    // 总人数
    $total_query = "SELECT COUNT(*) as count FROM personnel";
    $total_stmt = $db->prepare($total_query);
    $total_stmt->execute();
    $total_personnel = $total_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // 按性别统计
    $gender_query = "SELECT gender, COUNT(*) as count FROM personnel GROUP BY gender";
    $gender_stmt = $db->prepare($gender_query);
    $gender_stmt->execute();
    $personnel_by_gender = $gender_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 按项目统计
    $project_query = "
        SELECT 
            p.name as project_name,
            COUNT(DISTINCT pdp.personnel_id) as personnel_count
        FROM projects p
        LEFT JOIN project_department_personnel pdp ON p.id = pdp.project_id
        GROUP BY p.id, p.name
        ORDER BY p.name";
    $project_stmt = $db->prepare($project_query);
    $project_stmt->execute();
    $personnel_by_project = $project_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 无证件人数统计
    $no_id_card_query = "SELECT COUNT(*) as count FROM personnel WHERE id_card IS NULL OR id_card = ''";
    $no_id_card_stmt = $db->prepare($no_id_card_query);
    $no_id_card_stmt->execute();
    $no_id_card_count = $no_id_card_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // 无联系方式人数统计（电话和邮箱都为空）
    $no_contact_query = "SELECT COUNT(*) as count FROM personnel WHERE (phone IS NULL OR phone = '') AND (email IS NULL OR email = '')";
    $no_contact_stmt = $db->prepare($no_contact_query);
    $no_contact_stmt->execute();
    $no_contact_count = $no_contact_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // 人员详细信息（包括参与的项目）
    $details_query = "
        SELECT 
            p.id,
            p.name,
            p.gender,
            p.phone,
            p.email,
            p.id_card,
            p.created_at,
            GROUP_CONCAT(DISTINCT CONCAT(pr.name, ' - ', d.name, ' (', pdp.position, ')') SEPARATOR '; ') as project_details,
            COUNT(DISTINCT pdp.project_id) as project_count
        FROM personnel p
        LEFT JOIN project_department_personnel pdp ON p.id = pdp.personnel_id
        LEFT JOIN projects pr ON pdp.project_id = pr.id
        LEFT JOIN departments d ON pdp.department_id = d.id
        GROUP BY p.id, p.name, p.gender, p.phone, p.email, p.id_card, p.created_at
        ORDER BY p.name";
    $details_stmt = $db->prepare($details_query);
    $details_stmt->execute();
    $personnel_details = $details_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 查找同姓名的人员
    $duplicate_names_query = "
        SELECT name, COUNT(*) as count 
        FROM personnel 
        GROUP BY name 
        HAVING COUNT(*) > 1";
    $duplicate_names_stmt = $db->prepare($duplicate_names_query);
    $duplicate_names_stmt->execute();
    $duplicate_names = $duplicate_names_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 创建同姓名人员的映射
    $duplicate_names_map = array();
    if (!empty($duplicate_names)) {
        foreach ($duplicate_names as $dup) {
            $duplicate_names_map[$dup['name']] = $dup['count'];
        }
    }
    
    // 为每个人员添加同名标记
    if (!empty($personnel_details)) {
        foreach ($personnel_details as $key => $person) {
            $personnel_details[$key]['is_duplicate'] = isset($duplicate_names_map[$person['name']]);
            $personnel_details[$key]['duplicate_count'] = isset($duplicate_names_map[$person['name']]) ? $duplicate_names_map[$person['name']] : 1;
        }
    }
    
    // 获取同姓名人员的详细信息
    if (!empty($duplicate_names)) {
        $name_list = array_column($duplicate_names, 'name');
        $name_placeholders = str_repeat('?,', count($name_list) - 1) . '?';
        
        $duplicate_names_info_query = "
            SELECT 
                p.name,
                GROUP_CONCAT(
                    CONCAT(p.id, ' (', COALESCE(p.id_card, '无证件号'), ')') 
                    SEPARATOR '; '
                ) as personnel_info,
                COUNT(*) as count
            FROM personnel p
            WHERE p.name IN ($name_placeholders)
            GROUP BY p.name
            ORDER BY p.name";
        
        $duplicate_names_info_stmt = $db->prepare($duplicate_names_info_query);
        $duplicate_names_info_stmt->execute($name_list);
        $duplicate_names_info = $duplicate_names_info_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // 查找同证件号的人员（排除空证件号）
    $duplicate_id_cards_query = "
        SELECT id_card, COUNT(*) as count 
        FROM personnel 
        WHERE id_card IS NOT NULL AND id_card != ''
        GROUP BY id_card 
        HAVING COUNT(*) > 1";
    $duplicate_id_cards_stmt = $db->prepare($duplicate_id_cards_query);
    $duplicate_id_cards_stmt->execute();
    $duplicate_id_cards = $duplicate_id_cards_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 获取同证件号人员的详细信息
    if (!empty($duplicate_id_cards)) {
        $id_card_list = array_column($duplicate_id_cards, 'id_card');
        $id_card_placeholders = str_repeat('?,', count($id_card_list) - 1) . '?';
        
        $duplicate_id_cards_info_query = "
            SELECT 
                p.id_card,
                GROUP_CONCAT(
                    CONCAT(p.name, ' (ID', p.id, ')') 
                    SEPARATOR '; '
                ) as personnel_info,
                COUNT(*) as count
            FROM personnel p
            WHERE p.id_card IN ($id_card_placeholders)
            GROUP BY p.id_card
            ORDER BY p.id_card";
        
        $duplicate_id_cards_info_stmt = $db->prepare($duplicate_id_cards_info_query);
        $duplicate_id_cards_info_stmt->execute($id_card_list);
        $duplicate_id_cards_info = $duplicate_id_cards_info_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (Exception $e) {
    $error = '获取统计信息失败：' . $e->getMessage();
}

// 获取所有人员列表（用于合并功能）
$all_personnel = array();
try {
    $personnel_query = "SELECT id, name, id_card FROM personnel ORDER BY name";
    $personnel_stmt = $db->prepare($personnel_query);
    $personnel_stmt->execute();
    $all_personnel = $personnel_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = '获取人员列表失败：' . $e->getMessage();
}

// 处理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}
?>
<?php include 'includes/header.php'; ?>
<!-- Personnel Statistics CSS -->
<link href="assets/css/personnel-statistics-optimized.css" rel="stylesheet">

<div class="container-fluid">
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill"></i> <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- 统计信息 -->
    <div class="row">
        <div class="col-md-3 mb-3">
            <div class="stat-card h-100">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-people me-2"></i>总人数</h5>
                </div>
                <div class="card-body text-center">
                    <h2 class="display-4 text-primary"><?php echo $total_personnel; ?></h2>
                    <p class="text-muted small mb-0">系统内所有人员</p>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="stat-card h-100">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-card-text me-2"></i>无证件人数</h5>
                </div>
                <div class="card-body text-center">
                    <h2 class="display-4 text-warning"><?php echo $no_id_card_count; ?></h2>
                    <p class="text-muted small mb-0">缺少身份证号</p>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="stat-card h-100">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-telephone me-2"></i>无联系方式人数</h5>
                </div>
                <div class="card-body text-center">
                    <h2 class="display-4 text-warning"><?php echo $no_contact_count; ?></h2>
                    <p class="text-muted small mb-0">缺少电话和邮箱</p>
                </div>
            </div>
        </div>
        
        <div class="col-md-3 mb-3">
            <div class="stat-card h-100">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-gender-ambiguous me-2"></i>性别分布</h5>
                </div>
                <div class="card-body text-center">
                    <!-- 性别分布图表容器 -->
                    <div id="gender-distribution-chart" class="gender-distribution-chart"></div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-12 mb-3">
            <div class="stat-card h-100">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-building me-2"></i>项目分布</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($personnel_by_project)): ?>
                        <!-- 图表容器 -->
                        <div id="project-distribution-chart" class="project-distribution-chart"></div>
                        
                        <!-- 项目列表（备用显示） -->
                        <div class="project-list-container mt-4">
                            <div class="row g-3 px-2">
                                <?php 
                                // 将项目分为三列
                                $total_projects = count($personnel_by_project);
                                $projects_per_column = ceil($total_projects / 3);
                                $columns = array_chunk($personnel_by_project, $projects_per_column);
                                
                                // 确保始终有三列
                                while (count($columns) < 3) {
                                    $columns[] = [];
                                }
                                
                                foreach ($columns as $column_projects): ?>
                                    <div class="col-md-4">
                                        <?php foreach ($column_projects as $project_stat): ?>
                                            <div class="d-flex justify-content-between align-items-center mb-2 p-2 rounded bg-light project-item">
                                                <span class="text-truncate project-name" title="<?php echo htmlspecialchars($project_stat['project_name']); ?>">
                                                    <?php echo htmlspecialchars($project_stat['project_name'] ?: '未分配项目'); ?>
                                                </span>
                                                <span class="badge bg-info ms-2 project-count">
                                                    <?php echo $project_stat['personnel_count']; ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">暂无数据</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 重复人员提示框 -->
    <?php if (!empty($duplicate_names_info) || !empty($duplicate_id_cards_info)): ?>
    <div class="row">
        <?php if (!empty($duplicate_names_info)): ?>
        <div class="col-md-6 mb-3">
            <div class="stat-card h-100">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i>同姓名人员</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted small">系统中存在 <?php echo count($duplicate_names_info); ?> 组同姓名人员</p>
                    <div class="accordion" id="duplicateNamesAccordion">
                        <?php foreach ($duplicate_names_info as $index => $dup_info): ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed small" type="button" data-bs-toggle="collapse" 
                                        data-bs-target="#collapseNames<?php echo $index; ?>">
                                    <?php echo htmlspecialchars($dup_info['name']); ?> 
                                    <span class="badge bg-danger ms-2"><?php echo $dup_info['count']; ?> 人</span>
                                </button>
                            </h2>
                            <div id="collapseNames<?php echo $index; ?>" class="accordion-collapse collapse" 
                                 data-bs-parent="#duplicateNamesAccordion">
                                <div class="accordion-body small">
                                    <small><?php echo htmlspecialchars($dup_info['personnel_info']); ?></small>
                                    <div class="mt-2">
                                        <button class="btn btn-sm btn-primary merge-group-btn" 
                                                data-group-type="name" 
                                                data-group-value="<?php echo htmlspecialchars($dup_info['name']); ?>">
                                            <i class="bi bi-arrow-left-right"></i> 合并
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($duplicate_id_cards_info)): ?>
        <div class="col-md-6 mb-3">
            <div class="stat-card h-100">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i>同证件号人员</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted small">系统中存在 <?php echo count($duplicate_id_cards_info); ?> 组同证件号人员</p>
                    <div class="accordion" id="duplicateIdCardsAccordion">
                        <?php foreach ($duplicate_id_cards_info as $index => $dup_info): ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed small" type="button" data-bs-toggle="collapse" 
                                        data-bs-target="#collapseIdCards<?php echo $index; ?>">
                                    <?php echo htmlspecialchars($dup_info['id_card']); ?> 
                                    <span class="badge bg-danger ms-2"><?php echo $dup_info['count']; ?> 人</span>
                                </button>
                            </h2>
                            <div id="collapseIdCards<?php echo $index; ?>" class="accordion-collapse collapse" 
                                 data-bs-parent="#duplicateIdCardsAccordion">
                                <div class="accordion-body small">
                                    <small><?php echo htmlspecialchars($dup_info['personnel_info']); ?></small>
                                    <div class="mt-2">
                                        <button class="btn btn-sm btn-primary merge-group-btn" 
                                                data-group-type="id_card" 
                                                data-group-value="<?php echo htmlspecialchars($dup_info['id_card']); ?>">
                                            <i class="bi bi-arrow-left-right"></i> 合并
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- 人员详细信息 -->
    <!-- 筛选容器 -->
    <div class="filter-container">
        <h5 class="mb-3"><i class="bi bi-funnel me-2"></i>筛选条件</h5>
        <div class="row">
            <div class="col-md-4">
                <div class="form-group">
                    <label class="form-label">按参与项目筛选</label>
                    <select class="form-select" id="projectFilter">
                        <option value="">所有项目</option>
                        <?php 
                        // 获取所有项目用于筛选
                        $all_projects = array();
                        try {
                            $projects_query = "SELECT id, name FROM projects ORDER BY name";
                            $projects_stmt = $db->prepare($projects_query);
                            $projects_stmt->execute();
                            $all_projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);
                        } catch (Exception $e) {
                            // 静默处理错误
                        }
                        
                        foreach ($all_projects as $project): ?>
                            <option value="<?php echo $project['id']; ?>"><?php echo htmlspecialchars($project['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-group">
                    <label class="form-label">按性别筛选</label>
                    <select class="form-select" id="genderFilter">
                        <option value="">所有性别</option>
                        <option value="男">男</option>
                        <option value="女">女</option>
                    </select>
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-group">
                    <label class="form-label">按项目数筛选</label>
                    <select class="form-select" id="projectCountFilter">
                        <option value="">所有项目数</option>
                        <option value="0">未分配项目</option>
                        <option value="1">1个项目</option>
                        <option value="2">2个项目</option>
                        <option value="3">3个项目</option>
                        <option value="4">4个及以上项目</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="mt-3">
            <button type="button" class="btn btn-primary btn-sm" id="applyFilterBtn">
                <i class="bi bi-funnel me-1"></i>应用筛选
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="resetFilterBtn">
                <i class="bi bi-arrow-counterclockwise me-1"></i>重置筛选
            </button>
        </div>
    </div>

    <div class="table-responsive">
        <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-table me-2"></i>人员详细信息</h5>
            <div>
                <button type="button" class="btn btn-outline-primary btn-sm me-2" id="selectAllBtn">
                    <i class="bi bi-check-all"></i> 全选
                </button>
                <button type="button" class="btn btn-primary btn-sm" id="mergeSelectedBtn" disabled>
                    <i class="bi bi-arrow-left-right"></i> 合并选中
                </button>
                <span class="badge bg-secondary ms-2 small"><?php echo count($personnel_details); ?> 人</span>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="personnelTable">
                    <thead>
                        <tr>
                            <th>
                                <input type="checkbox" id="selectAllCheckbox" class="form-check-input">
                            </th>
                            <th class="small">序号</th>
                            <th class="small">姓名/身份证号</th>
                            <th class="small">性别</th>
                            <th class="small">参与项目</th>
                            <th class="small">项目数</th>
                            <th class="small">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($personnel_details)): ?>
                            <?php $row_number = 1; foreach ($personnel_details as $person): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="form-check-input personnel-checkbox" 
                                           data-id="<?php echo $person['id']; ?>"
                                           data-name="<?php echo htmlspecialchars($person['name']); ?>">
                                </td>
                                <td class="small"><?php echo $row_number++; ?></td>
                                <td>
                                    <div class="personnel-name-container">
                                        <div class="d-flex align-items-center">
                                            <span><?php echo htmlspecialchars($person['name']); ?> <span class="personnel-id">（ID<?php echo $person['id']; ?>）</span></span>
                                            <?php if ($person['is_duplicate']): ?>
                                                <span class="badge bg-warning ms-2" title="存在 <?php echo $person['duplicate_count']; ?> 个同名人员">
                                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($person['id_card']) && $person['id_card'] !== '-'): ?>
                                            <div class="personnel-id-card small text-muted mt-1">
                                                <?php echo htmlspecialchars($person['id_card']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $person['gender'] === '男' ? 'primary' : 'danger'; ?> small">
                                        <?php echo $person['gender']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($person['project_details']): ?>
                                        <div class="project-tag-container">
                                            <?php 
                                            $projects = array_unique(explode('; ', $person['project_details']));
                                            foreach ($projects as $project_info):
                                                if (trim($project_info)):
                                            ?>
                                                <div class="project-complete-tag"><?php echo htmlspecialchars(trim($project_info)); ?></div>
                                            <?php 
                                                endif;
                                            endforeach; 
                                            ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small">未分配</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-info small"><?php echo $person['project_count']; ?></span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                                onclick="viewPersonDetails(<?php echo $person['id']; ?>)" title="查看详情">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-warning" 
                                                onclick="editPerson(<?php echo $person['id']; ?>)" title="编辑人员信息">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                                onclick="deletePerson(<?php echo $person['id']; ?>, '<?php echo htmlspecialchars($person['name']); ?>')" title="删除人员">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr class="no-data">
                                <td colspan="7" class="text-center py-4 text-muted">
                                    <i class="bi bi-info-circle me-2"></i>暂无人员数据
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- 合并确认模态框 -->
<div class="modal fade merge-modal" id="mergeConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-arrow-left-right me-2"></i>合并人员确认</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small">确定要合并以下人员吗？第一个选中的人将作为目标，其余人员将被删除。</p>
                <ul id="mergeList" class="small"></ul>
                <div class="alert alert-warning small">
                    <i class="bi bi-exclamation-triangle-fill"></i> 此操作不可逆，请谨慎操作！
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary btn-sm" id="confirmMergeBtn">确认合并</button>
            </div>
        </div>
    </div>
</div>

<form id="mergeForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="merge_personnel">
    <input type="hidden" name="personnel_ids[]" id="personnelIdsInput">
</form>

<!-- 按组合并表单 -->
<form id="mergeGroupForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="merge_group">
    <input type="hidden" name="group_type" id="groupTypeInput">
    <input type="hidden" name="group_value" id="groupValueInput">
</form>

<!-- 人员详情模态框 -->
<div class="modal fade" id="personnelDetailModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">人员详细信息</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="personnelDetailsContent">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">加载中...</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
                <button type="button" class="btn btn-warning" onclick="editCurrentPerson()">编辑人员</button>
            </div>
        </div>
    </div>
</div>

<!-- 添加/编辑人员模态框 -->
<div class="modal fade" id="addPersonModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">编辑人员</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="personForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="personId">

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">姓名 *</label>
                                <input type="text" class="form-control" name="name" id="personName" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">性别 *</label>
                                <select class="form-select" name="gender" id="personGender" required>
                                    <option value="">请选择性别</option>
                                    <option value="男">男</option>
                                    <option value="女">女</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">电话</label>
                                <input type="tel" class="form-control" name="phone" id="personPhone">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">邮箱</label>
                                <input type="email" class="form-control" name="email" id="personEmail">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">身份证号</label>
                        <input type="text" class="form-control" name="id_card" id="personIdCard">
                    </div>

                    <div class="mb-3">
                        <h6><i class="bi bi-briefcase"></i> 项目分配</h6>
                        <div id="projectAssignments">
                            <div class="project-assignment">
                                <div class="row">
                                    <div class="col-md-4">
                                        <label class="form-label">项目</label>
                                        <select class="form-select project-select" name="project_id[]" onchange="loadDepartments(this)">
                                            <option value="">选择项目</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">部门</label>
                                        <select class="form-select department-select" name="department_id[]">
                                            <option value="">先选择项目</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">职位</label>
                                        <input type="text" class="form-control" name="position[]" placeholder="职位">
                                    </div>
                                    <div class="col-md-1">
                                        <label class="form-label">&nbsp;</label>
                                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeAssignment(this)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-outline-primary btn-sm mt-2" onclick="addAssignment()">
                            <i class="bi bi-plus"></i> 添加项目分配
                        </button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">保存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // 全局变量
    let currentPersonnelId = null;
    let allProjects = [];
    let allDepartments = [];
    
    // 页面加载完成后初始化
    function initializePage() {
        // 获取相关元素
        const selectAllCheckbox = document.getElementById('selectAllCheckbox');
        const personnelCheckboxes = document.querySelectorAll('.personnel-checkbox');
        const selectAllBtn = document.getElementById('selectAllBtn');
        const mergeSelectedBtn = document.getElementById('mergeSelectedBtn');
        const mergeConfirmModal = new bootstrap.Modal(document.getElementById('mergeConfirmModal'));
        const mergeList = document.getElementById('mergeList');
        const confirmMergeBtn = document.getElementById('confirmMergeBtn');
        const mergeForm = document.getElementById('mergeForm');
        const personnelIdsInput = document.getElementById('personnelIdsInput');
        const mergeGroupForm = document.getElementById('mergeGroupForm');
        const groupTypeInput = document.getElementById('groupTypeInput');
        const groupValueInput = document.getElementById('groupValueInput');
        
        // 全选复选框事件
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                const isChecked = this.checked;
                personnelCheckboxes.forEach(checkbox => {
                    checkbox.checked = isChecked;
                });
                updateMergeButtonState();
            });
        }
        
        // 全选按钮事件
        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', function() {
                const allChecked = selectAllCheckbox ? selectAllCheckbox.checked : false;
                if (selectAllCheckbox) {
                    selectAllCheckbox.checked = !allChecked;
                }
                personnelCheckboxes.forEach(checkbox => {
                    checkbox.checked = !allChecked;
                });
                updateMergeButtonState();
            });
        }
        
        // 人员复选框事件
        personnelCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateSelectAllState();
                updateMergeButtonState();
            });
        });
        
        // 合并选中按钮事件
        if (mergeSelectedBtn) {
            mergeSelectedBtn.addEventListener('click', function() {
                const selectedCheckboxes = document.querySelectorAll('.personnel-checkbox:checked');
                if (selectedCheckboxes.length < 2) {
                    alert('请至少选择两个人员进行合并！');
                    return;
                }
                
                // 构建合并列表
                mergeList.innerHTML = '';
                const personnelIds = [];
                selectedCheckboxes.forEach(checkbox => {
                    const id = checkbox.getAttribute('data-id');
                    const name = checkbox.getAttribute('data-name');
                    personnelIds.push(id);
                    
                    const listItem = document.createElement('li');
                    listItem.textContent = `${name} (ID: ${id})`;
                    mergeList.appendChild(listItem);
                });
                
                // 设置表单数据
                personnelIdsInput.value = personnelIds.join(',');
                
                // 显示确认模态框
                mergeConfirmModal.show();
            });
        }
        
        // 确认合并按钮事件
        if (confirmMergeBtn) {
            confirmMergeBtn.addEventListener('click', function() {
                mergeForm.submit();
            });
        }
        
        // 按组合并按钮事件
        document.querySelectorAll('.merge-group-btn').forEach(button => {
            button.addEventListener('click', function() {
                const groupType = this.getAttribute('data-group-type');
                const groupValue = this.getAttribute('data-group-value');
                
                groupTypeInput.value = groupType;
                groupValueInput.value = groupValue;
                mergeGroupForm.submit();
            });
        });
        
        // 初始化选择状态
        updateSelectAllState();
        updateMergeButtonState();
    }
    
    // 更新全选状态
    function updateSelectAllState() {
        const selectAllCheckbox = document.getElementById('selectAllCheckbox');
        const personnelCheckboxes = document.querySelectorAll('.personnel-checkbox');
        const checkedCheckboxes = document.querySelectorAll('.personnel-checkbox:checked');
        
        if (selectAllCheckbox) {
            selectAllCheckbox.checked = personnelCheckboxes.length > 0 && 
                                      checkedCheckboxes.length === personnelCheckboxes.length;
        }
    }
    
    // 更新合并按钮状态
    function updateMergeButtonState() {
        const mergeSelectedBtn = document.getElementById('mergeSelectedBtn');
        const checkedCheckboxes = document.querySelectorAll('.personnel-checkbox:checked');
        
        if (mergeSelectedBtn) {
            mergeSelectedBtn.disabled = checkedCheckboxes.length < 2;
        }
    }
    
    // 查看人员详情
    function viewPersonDetails(id) {
        const modal = new bootstrap.Modal(document.getElementById('personnelDetailModal'));
        const contentDiv = document.getElementById('personnelDetailsContent');
        
        // 显示加载动画
        contentDiv.innerHTML = '<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">加载中...</span></div></div>';
        modal.show();
        
        // 使用 AJAX 获取人员详情
        fetch(`api/personnel/get_personnel_details.php?id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayPersonnelDetails(data);
                } else {
                    contentDiv.innerHTML = '<div class="alert alert-danger">加载失败：' + data.message + '</div>';
                }
            })
            .catch(error => {
                contentDiv.innerHTML = '<div class="alert alert-danger">加载失败，请稍后重试。</div>';
                console.error('Error:', error);
            });
    }
    
    // 显示人员详情
    function displayPersonnelDetails(data) {
        const person = data.person;
        const assignments = data.assignments || [];
        const mealRecords = data.meal_records || [];
        const hotelRecords = data.hotel_records || [];
        const transportRecords = data.transport_records || [];
        
        // 格式化日期显示
        function formatDate(dateStr) {
            if (!dateStr) return '-';
            return new Date(dateStr).toLocaleDateString('zh-CN');
        }
        
        // 处理交通类型中文显示
        function getTransportType(type) {
            const typeMap = {
                'car': '轿车',
                'van': '商务车',
                'bus': '大巴',
                'truck': '货车'
            };
            return typeMap[type] || type;
        }
        
        // HTML转义函数
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        let html = `
            <div class="row">
                <div class="col-md-6">
                    <div class="card mb-3">
                        <div class="card-header bg-primary text-white">
                            <h6 class="mb-0"><i class="bi bi-person-circle"></i> 基本信息</h6>
                        </div>
                        <div class="card-body">
                            <div class="row mb-2">
                                <div class="col-sm-4"><strong>姓名:</strong></div>
                                <div class="col-sm-8">${escapeHtml(person.name)}</div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-sm-4"><strong>性别:</strong></div>
                                <div class="col-sm-8">${person.gender}</div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-sm-4"><strong>身份证:</strong></div>
                                <div class="col-sm-8">${person.id_card || '-'}</div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-sm-4"><strong>电话:</strong></div>
                                <div class="col-sm-8">${person.phone || '-'}</div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-sm-4"><strong>邮箱:</strong></div>
                                <div class="col-sm-8">${person.email || '-'}</div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-sm-4"><strong>创建时间:</strong></div>
                                <div class="col-sm-8">${formatDate(person.created_at)}</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card mb-3">
                        <div class="card-header bg-info text-white">
                            <h6 class="mb-0"><i class="bi bi-building"></i> 项目部门分配</h6>
                        </div>
                        <div class="card-body">
                            ${assignments.length > 0 ? assignments.map(assignment => `
                                <div class="border-start border-info ps-3 mb-3">
                                    <h6 class="text-primary mb-1">${assignment.project_name}</h6>
                                    <p class="mb-1"><strong>部门:</strong> ${assignment.department_name}</p>
                                    ${assignment.position ? `<p class="mb-1"><strong>职位:</strong> ${assignment.position}</p>` : ''}
                                </div>
                            `).join('') : '<div class="text-muted">暂无分配</div>'}
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-4">
                    <div class="card mb-3">
                        <div class="card-header bg-warning text-dark">
                            <h6 class="mb-0"><i class="bi bi-cup-hot"></i> 最近用餐记录</h6>
                        </div>
                        <div class="card-body">
                            ${mealRecords.length > 0 ? mealRecords.map(record => `
                                <div class="border-start border-warning ps-3 mb-2">
                                    <strong>${formatDate(record.meal_date)}</strong><br>
                                    <small>${record.meal_type || '用餐'} (${record.quantity || 1}份)</small><br>
                                    <small class="text-muted">${record.project_name || '-'}</small>
                                </div>
                            `).join('') : '<div class="text-muted">暂无用餐记录</div>'}
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card mb-3">
                        <div class="card-header bg-success text-white">
                            <h6 class="mb-0"><i class="bi bi-building"></i> 住宿记录</h6>
                        </div>
                        <div class="card-body">
                            ${hotelRecords.length > 0 ? hotelRecords.map(record => `
                                <div class="border-start border-success ps-3 mb-2">
                                    <strong>${record.hotel_name || '酒店'}</strong><br>
                                    <small>${formatDate(record.check_in_date)} - ${record.check_out_date ? formatDate(record.check_out_date) : '未退房'}</small><br>
                                    ${record.room_number ? `<small>房间: ${record.room_number}</small>` : ''}
                                </div>
                            `).join('') : '<div class="text-muted">暂无住宿记录</div>'}
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="card mb-3">
                        <div class="card-header bg-primary text-white">
                            <h6 class="mb-0"><i class="bi bi-car-front"></i> 交通记录</h6>
                        </div>
                        <div class="card-body">
                            ${transportRecords.length > 0 ? transportRecords.map(record => `
                                <div class="border-start border-primary ps-3 mb-2">
                                    <strong>${formatDate(record.travel_date || record.transport_date)}</strong><br>
                                    <small>${getTransportType(record.vehicle_type || record.transport_type)}</small><br>
                                    <small>${record.departure_location || record.origin} → ${record.destination_location || record.destination}</small><br>
                                    ${record.fleet_number || record.vehicle_number ? `<small>车牌: ${record.fleet_number || record.vehicle_number}</small>` : ''}
                                </div>
                            `).join('') : '<div class="text-muted">暂无交通记录</div>'}
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        document.getElementById('personnelDetailsContent').innerHTML = html;
        currentPersonnelId = person.id;
    }
    
    // 编辑人员
    function editPerson(id) {
        // 使用AJAX加载编辑数据
        fetch(`personnel_enhanced.php?action=get_person&id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 填充表单数据到模态框
                    const modalElement = document.getElementById('addPersonModal');
                    const form = document.getElementById('personForm');
                    
                    // 设置隐藏字段
                    const actionInput = form.querySelector('input[name="action"]');
                    const idInput = form.querySelector('input[name="id"]');
                    
                    if (actionInput) actionInput.value = 'edit';
                    if (idInput) idInput.value = data.person.id;
                    
                    // 填充基本信息
                    const nameInput = form.querySelector('input[name="name"]');
                    const genderSelect = form.querySelector('select[name="gender"]');
                    const phoneInput = form.querySelector('input[name="phone"]');
                    const emailInput = form.querySelector('input[name="email"]');
                    const idCardInput = form.querySelector('input[name="id_card"]');
                    
                    if (nameInput) nameInput.value = data.person.name || '';
                    if (genderSelect) genderSelect.value = data.person.gender || '';
                    if (phoneInput) phoneInput.value = data.person.phone || '';
                    if (emailInput) emailInput.value = data.person.email || '';
                    if (idCardInput) idCardInput.value = data.person.id_card || '';
                    
                    // 清空并重新填充项目分配
                    const container = document.getElementById('projectAssignments');
                    if (container) {
                        container.innerHTML = '';
                        
                        if (data.assignments && data.assignments.length > 0) {
                            data.assignments.forEach(assignment => {
                                addAssignmentWithData(assignment);
                            });
                        } else {
                            addAssignment(); // 添加一个空行
                        }
                    }
                    
                    // 更新模态框标题
                    const modalTitle = document.getElementById('modalTitle');
                    if (modalTitle) modalTitle.textContent = '编辑人员';
                    
                    // 显示模态框
                    const modal = new bootstrap.Modal(modalElement);
                    modal.show();
                } else {
                    alert('获取人员数据失败: ' + (data.message || '未知错误'));
                }
            })
            .catch(error => {
                console.error('加载人员数据失败:', error);
                alert('加载人员数据失败: ' + error.message);
            });
    }
    
    // 添加带有数据的分配行
    function addAssignmentWithData(assignment) {
        const container = document.getElementById('projectAssignments');
        const newAssignment = document.createElement('div');
        newAssignment.className = 'project-assignment';
        
        // 项目选项HTML
        let projectOptions = '<option value="">选择项目</option>';
        
        // 使用JavaScript生成项目选项
        const allProjects = <?php echo json_encode($all_projects); ?>;
        allProjects.forEach(project => {
            projectOptions += '<option value="' + project.id + '"' + 
                (assignment.project_id == project.id ? ' selected' : '') + 
                '>' + project.name + '</option>';
        });
        
        let html = '<div class="row">' +
            '<div class="col-md-4">' +
                '<label class="form-label">项目</label>' +
                '<select class="form-select project-select" name="project_id[]" onchange="loadDepartments(this)">' +
                    projectOptions +
                '</select>' +
            '</div>' +
            '<div class="col-md-4">' +
                '<label class="form-label">部门</label>' +
                '<select class="form-select department-select" name="department_id[]">' +
                    '<option value="">先选择项目</option>' +
                '</select>' +
            '</div>' +
            '<div class="col-md-3">' +
                '<label class="form-label">职位</label>' +
                '<input type="text" class="form-control" name="position[]" value="' + (assignment.position || '') + '" placeholder="职位">' +
            '</div>' +
            '<div class="col-md-1">' +
                '<label class="form-label">&nbsp;</label>' +
                '<button type="button" class="btn btn-outline-danger btn-sm" onclick="removeAssignment(this)">' +
                    '<i class="bi bi-trash"></i>' +
                '</button>' +
            '</div>' +
        '</div>';
        
        newAssignment.innerHTML = html;
        container.appendChild(newAssignment);
        
        // 加载部门并设置选中值
        const projectSelect = newAssignment.querySelector('.project-select');
        if (projectSelect && projectSelect.value) {
            loadDepartmentsForElement(projectSelect, assignment.department_id);
        }
    }
    
    // 为特定选择框加载项目列表
    function loadProjectsForSelect(selectElement, selectedProjectId) {
        // 通过 AJAX 获取实际的项目列表
        fetch('api/projects/get_all_projects.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 清空现有选项（除了默认选项）
                    selectElement.innerHTML = '<option value="">选择项目</option>';
                    
                    // 添加项目选项
                    data.projects.forEach(project => {
                        const option = document.createElement('option');
                        option.value = project.id;
                        option.textContent = project.name;
                        if (project.id == selectedProjectId) {
                            option.selected = true;
                        }
                        selectElement.appendChild(option);
                    });
                } else {
                    // 如果获取失败，使用模拟数据
                    loadProjectsForSelectMock(selectElement, selectedProjectId);
                }
            })
            .catch(error => {
                console.error('获取项目列表失败:', error);
                // 如果获取失败，使用模拟数据
                loadProjectsForSelectMock(selectElement, selectedProjectId);
            });
    }
    
    // 为特定选择框加载项目列表（模拟数据）
    function loadProjectsForSelectMock(selectElement, selectedProjectId) {
        const projects = [
            {id: 1, name: '项目A'},
            {id: 2, name: '项目B'},
            {id: 3, name: '项目C'}
        ];
        
        // 清空现有选项（除了默认选项）
        selectElement.innerHTML = '<option value="">选择项目</option>';
        
        // 添加项目选项
        projects.forEach(project => {
            const option = document.createElement('option');
            option.value = project.id;
            option.textContent = project.name;
            if (project.id == selectedProjectId) {
                option.selected = true;
            }
            selectElement.appendChild(option);
        });
    }
    
    // 为特定选择框加载部门列表
    function loadDepartmentsForSelect(selectElement, projectId, selectedDepartmentId) {
        if (!projectId) {
            selectElement.innerHTML = '<option value="">先选择项目</option>';
            return;
        }
        
        // 通过 AJAX 根据项目ID获取部门列表
        fetch(`api/departments/get_departments_by_project.php?project_id=${projectId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // 清空现有选项
                    selectElement.innerHTML = '<option value="">选择部门</option>';
                    
                    // 添加部门选项
                    data.departments.forEach(department => {
                        const option = document.createElement('option');
                        option.value = department.id;
                        option.textContent = department.name;
                        if (department.id == selectedDepartmentId) {
                            option.selected = true;
                        }
                        selectElement.appendChild(option);
                    });
                } else {
                    // 如果获取失败，使用模拟数据
                    loadDepartmentsForSelectMock(selectElement, projectId, selectedDepartmentId);
                }
            })
            .catch(error => {
                console.error('获取部门列表失败:', error);
                // 如果获取失败，使用模拟数据
                loadDepartmentsForSelectMock(selectElement, projectId, selectedDepartmentId);
            });
    }
    
    // 为特定选择框加载部门列表（模拟数据）
    function loadDepartmentsForSelectMock(selectElement, projectId, selectedDepartmentId) {
        const departments = [
            {id: 1, name: '部门A1'},
            {id: 2, name: '部门A2'},
            {id: 3, name: '部门A3'}
        ];
        
        // 清空现有选项
        selectElement.innerHTML = '<option value="">选择部门</option>';
        
        // 添加部门选项
        departments.forEach(department => {
            const option = document.createElement('option');
            option.value = department.id;
            option.textContent = department.name;
            if (department.id == selectedDepartmentId) {
                option.selected = true;
            }
            selectElement.appendChild(option);
        });
    }
    
    // 为特定元素加载部门并设置选中值
    function loadDepartmentsForElement(projectSelect, selectedDepartmentId) {
        const projectId = projectSelect.value;
        const assignmentDiv = projectSelect.closest('.project-assignment');
        const departmentSelect = assignmentDiv.querySelector('.department-select');
        
        departmentSelect.innerHTML = '<option value="">选择部门</option>';
        
        if (projectId) {
            const filteredDepartments = allDepartments.filter(dept => dept.project_id == projectId);
            filteredDepartments.forEach(dept => {
                const option = document.createElement('option');
                option.value = dept.id;
                option.textContent = dept.name;
                option.selected = (dept.id == selectedDepartmentId);
                departmentSelect.appendChild(option);
            });
        }
    }
    
    // 编辑当前人员（在详情模态框中）
    function editCurrentPerson() {
        if (currentPersonnelId) {
            // 隐藏详情模态框
            const detailModal = document.getElementById('personnelDetailModal');
            if (detailModal) {
                const modal = bootstrap.Modal.getInstance(detailModal);
                if (modal) modal.hide();
            }
            
            // 打开编辑模态框
            editPerson(currentPersonnelId);
        }
    }
    
    // 删除人员
    function deletePerson(id, name) {
        if (confirm(`确定要删除人员 "${name}" 吗？此操作不可逆！`)) {
            // 创建隐藏表单并提交
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete';
            form.appendChild(actionInput);
            
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'id';
            idInput.value = id;
            form.appendChild(idInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    // 添加项目分配
    function addAssignment() {
        const container = document.getElementById('projectAssignments');
        const assignmentDiv = document.createElement('div');
        assignmentDiv.className = 'project-assignment';
        assignmentDiv.innerHTML = `
            <div class="row mt-2">
                <div class="col-md-4">
                    <label class="form-label">项目</label>
                    <select class="form-select project-select" name="project_id[]" onchange="loadDepartments(this)">
                        <option value="">选择项目</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">部门</label>
                    <select class="form-select department-select" name="department_id[]">
                        <option value="">先选择项目</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">职位</label>
                    <input type="text" class="form-control" name="position[]" placeholder="职位">
                </div>
                <div class="col-md-1">
                    <label class="form-label">&nbsp;</label>
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeAssignment(this)">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        `;
        container.appendChild(assignmentDiv);
        
        // 重新加载项目选项
        loadProjects();
    }
    
    // 移除项目分配
    function removeAssignment(button) {
        const assignmentDiv = button.closest('.project-assignment');
        if (assignmentDiv) {
            assignmentDiv.remove();
        }
    }
    
    // 加载项目列表
    function loadProjects() {
        // 这里应该通过 AJAX 获取项目列表
        // 为简化代码，这里使用模拟数据
        const projectSelects = document.querySelectorAll('.project-select');
        projectSelects.forEach(select => {
            if (select.children.length <= 1) { // 只有默认选项时才加载
                // 模拟项目数据
                const projects = [
                    {id: 1, name: '项目A'},
                    {id: 2, name: '项目B'},
                    {id: 3, name: '项目C'}
                ];
                
                projects.forEach(project => {
                    const option = document.createElement('option');
                    option.value = project.id;
                    option.textContent = project.name;
                    select.appendChild(option);
                });
            }
        });
    }
    
    // 为特定选择框加载项目列表
    function loadProjectsForSelect(selectElement, selectedProjectId) {
        // 这里应该通过 AJAX 获取项目列表
        // 为简化代码，这里使用模拟数据
        const projects = [
            {id: 1, name: '项目A'},
            {id: 2, name: '项目B'},
            {id: 3, name: '项目C'}
        ];
        
        // 清空现有选项（除了默认选项）
        selectElement.innerHTML = '<option value="">选择项目</option>';
        
        // 添加项目选项
        projects.forEach(project => {
            const option = document.createElement('option');
            option.value = project.id;
            option.textContent = project.name;
            if (project.id == selectedProjectId) {
                option.selected = true;
            }
            selectElement.appendChild(option);
        });
    }
    
    // 加载部门列表
    function loadDepartments(projectSelect) {
        const projectId = projectSelect.value;
        const departmentSelect = projectSelect.closest('.row').querySelector('.department-select');
        
        // 清空部门选项
        departmentSelect.innerHTML = '<option value="">先选择项目</option>';
        
        if (projectId) {
            // 这里应该通过 AJAX 根据项目ID获取部门列表
            // 为简化代码，这里使用模拟数据
            const departments = [
                {id: 1, name: '部门A1'},
                {id: 2, name: '部门A2'},
                {id: 3, name: '部门A3'}
            ];
            
            departments.forEach(department => {
                const option = document.createElement('option');
                option.value = department.id;
                option.textContent = department.name;
                departmentSelect.appendChild(option);
            });
            
            departmentSelect.innerHTML = '<option value="">选择部门</option>' + departmentSelect.innerHTML;
        }
    }
    
    // 为特定选择框加载部门列表
    function loadDepartmentsForSelect(selectElement, projectId, selectedDepartmentId) {
        if (!projectId) {
            selectElement.innerHTML = '<option value="">先选择项目</option>';
            return;
        }
        
        // 这里应该通过 AJAX 根据项目ID获取部门列表
        // 为简化代码，这里使用模拟数据
        const departments = [
            {id: 1, name: '部门A1'},
            {id: 2, name: '部门A2'},
            {id: 3, name: '部门A3'}
        ];
        
        // 清空现有选项
        selectElement.innerHTML = '<option value="">选择部门</option>';
        
        // 添加部门选项
        departments.forEach(department => {
            const option = document.createElement('option');
            option.value = department.id;
            option.textContent = department.name;
            if (department.id == selectedDepartmentId) {
                option.selected = true;
            }
            selectElement.appendChild(option);
        });
    }
    
    // 页面加载完成后执行初始化
    document.addEventListener('DOMContentLoaded', function() {
        initializePage();
        
        // 初始化图表
        initializeChart();
        
        // 初始化筛选功能
        initializeFilters();
    });
    
    // 初始化图表
    function initializeChart() {
        // 检查是否引入了 ApexCharts
        if (typeof ApexCharts === 'undefined') {
            console.warn('ApexCharts 未加载，无法显示图表');
            return;
        }
        
        // 准备图表数据
        const projectData = <?php echo json_encode($personnel_by_project); ?>;
        
        if (projectData && projectData.length > 0) {
            // 提取项目名称和人员数量
            const projectNames = projectData.map(item => item.project_name || '未分配项目');
            const personnelCounts = projectData.map(item => parseInt(item.personnel_count));
            
            // 计算图表高度，确保每个项目有足够的显示空间
            // 在移动设备上增加高度以适应长项目名称
            const isMobile = window.innerWidth < 768;
            const rowHeight = isMobile ? 40 : 30;  // 恢复原来的高度设置
            // 限制最大高度，避免图表过大
            const chartHeight = Math.min(400, Math.max(350, projectNames.length * rowHeight));
            
            // 图表配置
            const chartOptions = {
                series: [{
                    name: "人员数量",
                    data: personnelCounts,
                    color: '#11a1fd'
                }],
                chart: {
                    type: 'bar',
                    height: chartHeight,
                    toolbar: {
                        show: false
                    },
                    zoom: {
                        enabled: false
                    },
                    animations: {
                        enabled: true,
                        easing: 'easeinout'
                    }
                },
                plotOptions: {
                    bar: {
                        horizontal: true,
                        barHeight: '70%'
                    }
                },
                dataLabels: {
                    enabled: true,
                    formatter: function(val) {
                        return val + " 人";
                    },
                    style: {
                        colors: ['#fff'],
                        fontSize: '12px'
                    },
                    offsetX: 10,
                    background: {
                        enabled: true,
                        foreColor: '#000',
                        padding: 4,
                        borderRadius: 2,
                        borderWidth: 1,
                        borderColor: '#fff',
                        opacity: 0.9
                    }
                },
                xaxis: {
                    categories: projectNames,
                    labels: {
                        style: {
                            fontSize: '12px'
                        }
                    }
                },
                yaxis: {
                    labels: {
                        style: {
                            fontSize: '12px'
                        },
                        offsetX: -10,
                        maxWidth: window.innerWidth < 768 ? 120 : 200,
                        trim: false
                    }
                },
                tooltip: {
                    y: {
                        formatter: function(val) {
                            return val + " 人";
                        }
                    }
                },
                legend: {
                    fontSize: '12px'
                }
            };
            
            // 渲染图表
            const chart = new ApexCharts(document.querySelector("#project-distribution-chart"), chartOptions);
            chart.render();
        }
        
        // 初始化性别分布饼图
        initializeGenderChart();
    }
    
    // 初始化性别分布饼图
    function initializeGenderChart() {
        // 准备性别分布数据
        const genderData = <?php echo json_encode($personnel_by_gender); ?>;
        
        if (genderData && genderData.length > 0) {
            // 提取性别标签和人员数量
            const labels = genderData.map(item => item.gender || '未知');
            const counts = genderData.map(item => parseInt(item.count));
            
            // 颜色配置
            const colors = [];
            labels.forEach(label => {
                if (label === '男') {
                    colors.push('#11a1fd'); // 蓝色
                } else if (label === '女') {
                    colors.push('#f46363'); // 红色
                } else {
                    colors.push('#6c757d'); // 灰色
                }
            });
            
            // 图表配置 - 使用Simple pie样式并调整尺寸
            const genderChartOptions = {
                series: counts,
                chart: {
                    width: 250,  // 缩小宽度
                    height: 250, // 设置固定高度
                    type: "pie"
                },
                labels: labels,
                colors: colors,
                responsive: [
                    {
                        breakpoint: 480,
                        options: {
                            chart: {
                                width: 200,
                                height: 200
                            },
                            legend: {
                                position: "bottom"
                            }
                        }
                    }
                ],
                legend: {
                    position: "bottom",
                    fontSize: '12px'
                },
                dataLabels: {
                    enabled: true,
                    formatter: function(val, opts) {
                        return opts.w.globals.labels[opts.seriesIndex] + ": " + opts.w.globals.series[opts.seriesIndex] + "人";
                    },
                    style: {
                        fontSize: '12px'
                    }
                },
                tooltip: {
                    y: {
                        formatter: function(val) {
                            return val + " 人";
                        }
                    }
                }
            };
            
            // 渲染性别分布图表
            const genderChart = new ApexCharts(document.querySelector("#gender-distribution-chart"), genderChartOptions);
            genderChart.render();
        }
    }
    
    // 筛选功能
    function initializeFilters() {
        const projectFilter = document.getElementById('projectFilter');
        const genderFilter = document.getElementById('genderFilter');
        const projectCountFilter = document.getElementById('projectCountFilter');
        const applyFilterBtn = document.getElementById('applyFilterBtn');
        const resetFilterBtn = document.getElementById('resetFilterBtn');
        const tableRows = document.querySelectorAll('#personnelTable tbody tr:not(.no-data)');
        
        // 应用筛选
        function applyFilters() {
            const projectValue = projectFilter.value;
            const genderValue = genderFilter.value;
            const projectCountValue = projectCountFilter.value;
            
            let visibleRowCount = 0;
            
            tableRows.forEach(row => {
                let showRow = true;
                
                // 按项目筛选
                if (projectValue && showRow) {
                    const projectTags = row.querySelectorAll('.project-complete-tag');
                    let hasProject = false;
                    
                    // 获取选定项目的名称
                    const selectedProjectOption = projectFilter.options[projectFilter.selectedIndex];
                    const selectedProjectName = selectedProjectOption.textContent;
                    
                    projectTags.forEach(tag => {
                        // 获取标签文本并检查是否包含选定的项目名称
                        const tagText = tag.textContent;
                        // 查找项目名称（标签格式为 "项目名 - 部门名 (职位)"）
                        const projectName = tagText.split(' - ')[0];
                        
                        if (projectName === selectedProjectName) {
                            hasProject = true;
                        }
                    });
                    
                    if (!hasProject) {
                        showRow = false;
                    }
                }
                
                // 按性别筛选
                if (genderValue && showRow) {
                    const genderBadge = row.querySelector('td:nth-child(4) .badge');
                    if (genderBadge && genderBadge.textContent.trim() !== genderValue) {
                        showRow = false;
                    }
                }
                
                // 按项目数筛选
                if (projectCountValue !== '' && showRow) {
                    const projectCountBadge = row.querySelector('td:nth-child(6) .badge');
                    if (projectCountBadge) {
                        const count = parseInt(projectCountBadge.textContent);
                        switch (projectCountValue) {
                            case '0':
                                if (count > 0) showRow = false;
                                break;
                            case '1':
                                if (count !== 1) showRow = false;
                                break;
                            case '2':
                                if (count !== 2) showRow = false;
                                break;
                            case '3':
                                if (count !== 3) showRow = false;
                                break;
                            case '4':
                                if (count < 4) showRow = false;
                                break;
                        }
                    } else if (projectCountValue !== '0') {
                        // 如果没有项目数徽章且筛选条件不是"未分配项目"，则隐藏该行
                        showRow = false;
                    }
                }
                
                row.style.display = showRow ? '' : 'none';
                if (showRow) visibleRowCount++;
            });
            
            // 显示或隐藏"无数据"行
            const noDataRow = document.querySelector('#personnelTable tbody tr.no-data');
            if (noDataRow) {
                noDataRow.style.display = visibleRowCount > 0 ? 'none' : '';
            }
        }
        
        // 应用筛选按钮事件
        if (applyFilterBtn) {
            applyFilterBtn.addEventListener('click', applyFilters);
        }
        
        // 重置筛选
        if (resetFilterBtn) {
            resetFilterBtn.addEventListener('click', function() {
                projectFilter.value = '';
                genderFilter.value = '';
                projectCountFilter.value = '';
                applyFilters();
            });
        }
        
        // 为筛选器添加实时筛选功能（可选）
        // projectFilter.addEventListener('change', applyFilters);
        // genderFilter.addEventListener('change', applyFilters);
        // projectCountFilter.addEventListener('change', applyFilters);
    }
</script>

<?php include 'includes/footer.php'; ?>
