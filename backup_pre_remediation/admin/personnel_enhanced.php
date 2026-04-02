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

// 处理AJAX请求获取人员数据
if (isset($_GET['action']) && $_GET['action'] === 'get_person' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    
    $person_id = intval($_GET['id']);
    
    // 获取人员基本信息
    $query = "SELECT * FROM personnel WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $person_id);
    $stmt->execute();
    $person = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($person) {
        // 获取项目部门关联
        $query = "SELECT 
                    pdp.project_id,
                    pdp.department_id,
                    pdp.position,
                    p2.name as project_name,
                    d.name as department_name
                  FROM project_department_personnel pdp
                  LEFT JOIN projects p2 ON pdp.project_id = p2.id
                  LEFT JOIN departments d ON pdp.department_id = d.id
                  WHERE pdp.personnel_id = :personnel_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':personnel_id', $person_id);
        $stmt->execute();
        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'person' => $person,
            'assignments' => $assignments
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '人员未找到']);
    }
    exit;
}

// 处理AJAX请求获取人员详细信息（包含用餐、住宿、交通记录）
if (isset($_GET['action']) && $_GET['action'] === 'get_person_details' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    
    $person_id = intval($_GET['id']);
    
    try {
        // 获取人员基本信息
        $query = "SELECT * FROM personnel WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $person_id);
        $stmt->execute();
        $person = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$person) {
            echo json_encode(['success' => false, 'message' => '人员未找到']);
            exit;
        }
        
        // 获取项目部门关联
        $query = "SELECT 
                    pdp.project_id,
                    pdp.department_id,
                    pdp.position,
                    p2.name as project_name,
                    d.name as department_name
                  FROM project_department_personnel pdp
                  LEFT JOIN projects p2 ON pdp.project_id = p2.id
                  LEFT JOIN departments d ON pdp.department_id = d.id
                  WHERE pdp.personnel_id = :personnel_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':personnel_id', $person_id);
        $stmt->execute();
        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 获取用餐记录（最近5条）
        try {
            $query = "SELECT mr.*, p.name as project_name 
                      FROM meal_reports mr 
                      LEFT JOIN projects p ON mr.project_id = p.id 
                      WHERE mr.personnel_id = :personnel_id 
                      ORDER BY mr.meal_date DESC, mr.created_at DESC 
                      LIMIT 5";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':personnel_id', $person_id);
            $stmt->execute();
            $meal_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $meal_records = [];
        }
        
        // 获取住宿记录（最近5条）
        try {
            $query = "SELECT hr.*, hr.hotel_name as hotel_name 
                      FROM hotel_reports hr 
                      WHERE hr.personnel_id = :personnel_id 
                      ORDER BY hr.check_in_date DESC, hr.created_at DESC 
                      LIMIT 5";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':personnel_id', $person_id);
            $stmt->execute();
            $hotel_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $hotel_records = [];
        }
        
        // 获取交通记录（最近5条）
        try {
            $query = "SELECT tr.* 
                      FROM transportation_reports tr 
                      WHERE tr.personnel_id = :personnel_id 
                      ORDER BY tr.travel_date DESC, tr.created_at DESC 
                      LIMIT 5";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':personnel_id', $person_id);
            $stmt->execute();
            $transport_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $transport_records = [];
        }
        
        echo json_encode([
            'success' => true,
            'person' => $person,
            'assignments' => $assignments,
            'meal_records' => $meal_records,
            'hotel_records' => $hotel_records,
            'transport_records' => $transport_records
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '加载失败：' . $e->getMessage()]);
    }
    exit;
}

// 引入header.php文件
require_once 'includes/header.php';

// 处理表单提交
$message = '';
$error = '';

// 检查是否有从其他页面传来的消息
if (isset($_GET['message'])) {
    $message = $_GET['message'];
}
if (isset($_GET['error'])) {
    $error = $_GET['error'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'add') {
            // 添加人员
            $name = trim($_POST['name']);
            $email = trim($_POST['email']);
            $phone = trim($_POST['phone']);
            $id_card = trim($_POST['id_card']);
            $gender = $_POST['gender'];
            
            if (empty($name)) {
                $error = '姓名不能为空！';
            } else {
                try {
                    $db->beginTransaction();
                    
                    // 检查是否存在相同身份证号的人员（仅在身份证号不为空时检查）
                    $existing_person = null;
                    if (!empty($id_card)) {
                        $check_query = "SELECT id, name FROM personnel WHERE id_card = :id_card";
                        $check_stmt = $db->prepare($check_query);
                        $check_stmt->bindParam(':id_card', $id_card);
                        $check_stmt->execute();
                        $existing_person = $check_stmt->fetch(PDO::FETCH_ASSOC);
                    }
                    
                    // 检查是否要合并人员
                    if (isset($_POST['merge_with_id']) && !empty($_POST['merge_with_id'])) {
                        // 合并操作：将新创建的人员信息复制到已存在的人员
                        $target_personnel_id = intval($_POST['merge_with_id']);
                        
                        // 更新现有人员信息（使用新输入的信息）
                        $update_query = "UPDATE personnel SET name = :name, email = :email, phone = :phone, 
                                         gender = :gender, id_card = :id_card WHERE id = :id";
                        $update_stmt = $db->prepare($update_query);
                        $update_stmt->bindParam(':name', $name);
                        $update_stmt->bindParam(':email', $email);
                        $update_stmt->bindParam(':phone', $phone);
                        $update_stmt->bindParam(':gender', $gender);
                        $update_stmt->bindParam(':id_card', $id_card);
                        $update_stmt->bindParam(':id', $target_personnel_id);
                        $update_stmt->execute();
                        
                        // 使用现有人员的ID作为人员ID
                        $personnel_id = $target_personnel_id;
                        
                        // 处理项目部门关联
                        if (!empty($_POST['project_assignments'])) {
                            $assignments = json_decode($_POST['project_assignments'], true);
                            foreach ($assignments as $assignment) {
                                $project_id = intval($assignment['project_id']);
                                $department_id = intval($assignment['department_id']);
                                $position = $assignment['position'] ?? '';
                                
                                if ($project_id > 0 && $department_id > 0) {
                                    $query = "INSERT INTO project_department_personnel (project_id, department_id, personnel_id, position) 
                                             VALUES (:project_id, :department_id, :personnel_id, :position)";
                                    $stmt = $db->prepare($query);
                                    $stmt->bindParam(':project_id', $project_id);
                                    $stmt->bindParam(':department_id', $department_id);
                                    $stmt->bindParam(':personnel_id', $personnel_id);
                                    $stmt->bindParam(':position', $position);
                                    $stmt->execute();
                                }
                            }
                        }
                        
                        $db->commit();
                        $message = '人员信息已合并到现有人员记录中！';
                    } else {
                        if ($existing_person) {
                            // 如果存在相同身份证号的人员，提示是否合并
                            $error = '存在相同身份证号的人员：' . $existing_person['name'] . ' (ID: ' . $existing_person['id'] . ')。请确认是否要合并信息。';
                            $db->rollBack();
                        } else {
                            // 添加人员基本信息
                            $query = "INSERT INTO personnel (name, email, phone, id_card, gender) 
                                     VALUES (:name, :email, :phone, :id_card, :gender)";
                            $stmt = $db->prepare($query);
                            $stmt->bindParam(':name', $name);
                            $stmt->bindParam(':email', $email);
                            $stmt->bindParam(':phone', $phone);
                            $stmt->bindParam(':id_card', $id_card);
                            $stmt->bindParam(':gender', $gender);
                            $stmt->execute();
                            
                            $personnel_id = $db->lastInsertId();
                            
                            // 处理项目部门关联
                            if (!empty($_POST['project_assignments'])) {
                                $assignments = json_decode($_POST['project_assignments'], true);
                                foreach ($assignments as $assignment) {
                                    $project_id = intval($assignment['project_id']);
                                    $department_id = intval($assignment['department_id']);
                                    $position = $assignment['position'] ?? '';
                                    
                                    if ($project_id > 0 && $department_id > 0) {
                                        $query = "INSERT INTO project_department_personnel (project_id, department_id, personnel_id, position) 
                                                 VALUES (:project_id, :department_id, :personnel_id, :position)";
                                        $stmt = $db->prepare($query);
                                        $stmt->bindParam(':project_id', $project_id);
                                        $stmt->bindParam(':department_id', $department_id);
                                        $stmt->bindParam(':personnel_id', $personnel_id);
                                        $stmt->bindParam(':position', $position);
                                        $stmt->execute();
                                    }
                                }
                            }
                            
                            $db->commit();
                            $message = '人员添加成功！';
                        }
                    }
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = '添加人员失败：' . $e->getMessage();
                }
            }
        } elseif ($action === 'edit' && isset($_POST['id'])) {
            // 编辑人员
            $id = intval($_POST['id']);
            $name = trim($_POST['name']);
            $email = trim($_POST['email']);
            $phone = trim($_POST['phone']);
            $id_card = trim($_POST['id_card']);
            $gender = $_POST['gender'];
            
            if (empty($name)) {
                $error = '姓名不能为空！';
            } else {
                try {
                    $db->beginTransaction();
                    
                    // 检查是否存在其他相同身份证号的人员（仅在身份证号不为空时检查）
                    $existing_person = null;
                    if (!empty($id_card)) {
                        $check_query = "SELECT id, name FROM personnel WHERE id_card = :id_card AND id != :id";
                        $check_stmt = $db->prepare($check_query);
                        $check_stmt->bindParam(':id_card', $id_card);
                        $check_stmt->bindParam(':id', $id);
                        $check_stmt->execute();
                        $existing_person = $check_stmt->fetch(PDO::FETCH_ASSOC);
                    }
                    
                    // 检查是否要合并人员
                    if (isset($_POST['merge_with_id']) && !empty($_POST['merge_with_id'])) {
                        // 合并到现有人员：将编辑的信息复制到现有人员，并删除当前人员
                        $merge_id = intval($_POST['merge_with_id']);
                        $current_id = $id; // 保存当前正在编辑的人员ID
                        
                        // 更新现有人员信息（使用编辑的信息）
                        $update_query = "UPDATE personnel SET name = :name, email = :email, phone = :phone, 
                                         gender = :gender, id_card = :id_card WHERE id = :id";
                        $update_stmt = $db->prepare($update_query);
                        $update_stmt->bindParam(':name', $name);
                        $update_stmt->bindParam(':email', $email);
                        $update_stmt->bindParam(':phone', $phone);
                        $update_stmt->bindParam(':gender', $gender);
                        $update_stmt->bindParam(':id_card', $id_card);
                        $update_stmt->bindParam(':id', $merge_id);
                        $update_stmt->execute();
                        
                        // 获取当前人员的项目分配，并添加到现有人员（避免重复）
                        // 先删除现有人员在当前项目中的分配，然后再添加新的分配
                        $delete_query = "DELETE FROM project_department_personnel WHERE personnel_id = :personnel_id";
                        $delete_stmt = $db->prepare($delete_query);
                        $delete_stmt->bindParam(':personnel_id', $merge_id);
                        $delete_stmt->execute();
                        
                        if (!empty($_POST['project_assignments'])) {
                            $assignments = json_decode($_POST['project_assignments'], true);
                            foreach ($assignments as $assignment) {
                                $project_id = intval($assignment['project_id']);
                                $department_id = intval($assignment['department_id']);
                                $position = $assignment['position'] ?? '';
                                
                                if ($project_id > 0 && $department_id > 0) {
                                    $query = "INSERT INTO project_department_personnel (project_id, department_id, personnel_id, position) 
                                             VALUES (:project_id, :department_id, :personnel_id, :position)";
                                    $stmt = $db->prepare($query);
                                    $stmt->bindParam(':project_id', $project_id);
                                    $stmt->bindParam(':department_id', $department_id);
                                    $stmt->bindParam(':personnel_id', $merge_id);
                                    $stmt->bindParam(':position', $position);
                                    $stmt->execute();
                                }
                            }
                        }
                        
                        // 删除当前人员记录（因为我们已经将信息合并到现有人员）
                        $delete_query = "DELETE FROM personnel WHERE id = :id";
                        $delete_stmt = $db->prepare($delete_query);
                        $delete_stmt->bindParam(':id', $current_id);
                        $delete_stmt->execute();
                        
                        // 删除当前人员的项目分配记录
                        $delete_pdp_query = "DELETE FROM project_department_personnel WHERE personnel_id = :id";
                        $delete_pdp_stmt = $db->prepare($delete_pdp_query);
                        $delete_pdp_stmt->bindParam(':id', $current_id);
                        $delete_pdp_stmt->execute();
                        
                        $db->commit();
                        $message = '人员信息已合并到现有人员记录中！';
                    } else if ($existing_person) {
                        // 如果存在其他相同身份证号的人员，提示是否合并
                        $error = '存在其他相同身份证号的人员：' . $existing_person['name'] . ' (ID: ' . $existing_person['id'] . ')。请确认是否要合并信息。';
                        $db->rollBack();
                    } else {
                        // 更新人员基本信息
                        $query = "UPDATE personnel SET name = :name, email = :email, phone = :phone, 
                                 id_card = :id_card, gender = :gender WHERE id = :id";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':name', $name);
                        $stmt->bindParam(':email', $email);
                        $stmt->bindParam(':phone', $phone);
                        $stmt->bindParam(':id_card', $id_card);
                        $stmt->bindParam(':gender', $gender);
                        $stmt->bindParam(':id', $id);
                        $stmt->execute();
                        
                        // 处理项目部门关联
                        // 先删除现有的项目分配记录，然后再插入新的记录
                        $delete_query = "DELETE FROM project_department_personnel WHERE personnel_id = :personnel_id";
                        $delete_stmt = $db->prepare($delete_query);
                        $delete_stmt->bindParam(':personnel_id', $id);
                        $delete_stmt->execute();
                        
                        if (!empty($_POST['project_assignments'])) {
                            $assignments = json_decode($_POST['project_assignments'], true);
                            foreach ($assignments as $assignment) {
                                $project_id = intval($assignment['project_id']);
                                $department_id = intval($assignment['department_id']);
                                $position = $assignment['position'] ?? '';
                                
                                if ($project_id > 0 && $department_id > 0) {
                                    $query = "INSERT INTO project_department_personnel (project_id, department_id, personnel_id, position) 
                                             VALUES (:project_id, :department_id, :personnel_id, :position)";
                                    $stmt = $db->prepare($query);
                                    $stmt->bindParam(':project_id', $project_id);
                                    $stmt->bindParam(':department_id', $department_id);
                                    $stmt->bindParam(':personnel_id', $id);
                                    $stmt->bindParam(':position', $position);
                                    $stmt->execute();
                                }
                            }
                        }
                        
                        $db->commit();
                        $message = '人员信息更新成功！';
                    }
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = '更新人员失败：' . $e->getMessage();
                }
            }
        } elseif ($action === 'delete' && isset($_POST['id'])) {
            // 删除人员 - 只删除人员在当前项目的分配
            // 只有当人员在其他项目中也没有分配时才删除该人员的基本信息
            $id = intval($_POST['id']);
            
            // 获取项目ID（从GET参数）
            $project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
            
            if ($project_id == 0) {
                $error = '项目ID无效！';
            } else {
                try {
                    $db->beginTransaction();
                    
                    // 删除人员在当前项目的分配
                    $delete_pdp_query = "DELETE FROM project_department_personnel WHERE personnel_id = :personnel_id AND project_id = :project_id";
                    $delete_pdp_stmt = $db->prepare($delete_pdp_query);
                    $delete_pdp_stmt->bindParam(':personnel_id', $id);
                    $delete_pdp_stmt->bindParam(':project_id', $project_id);
                    $delete_pdp_stmt->execute();
                    
                    // 检查该人员是否还有其他项目分配
                    $check_query = "SELECT COUNT(*) as count FROM project_department_personnel WHERE personnel_id = :personnel_id";
                    $check_stmt = $db->prepare($check_query);
                    $check_stmt->bindParam(':personnel_id', $id);
                    $check_stmt->execute();
                    $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // 只有当人员在其他项目中也没有分配时才删除该人员的基本信息
                    if ($result['count'] == 0) {
                        $delete_personnel_query = "DELETE FROM personnel WHERE id = :id";
                        $delete_personnel_stmt = $db->prepare($delete_personnel_query);
                        $delete_personnel_stmt->bindParam(':id', $id);
                        $delete_personnel_stmt->execute();
                        $message = '人员删除成功！';
                    } else {
                        $message = '人员在当前项目的分配已删除！';
                    }
                    
                    $db->commit();
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = '删除人员失败：' . $e->getMessage();
                }
            }
        } elseif ($action === 'batch_delete' && isset($_POST['ids'])) {
            // 批量删除人员 - 只删除人员在当前项目的分配
            // 只有当人员在其他项目中也没有分配时才删除该人员的基本信息
            $ids = $_POST['ids'];
            
            // 获取项目ID（从GET参数）
            $project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
            
            if ($project_id == 0) {
                $error = '项目ID无效！';
            } elseif (empty($ids) || !is_array($ids)) {
                $error = '请选择要删除的人员！';
            } else {
                try {
                    $db->beginTransaction();
                    
                    $deleted_count = 0;
                    $processed_count = 0;
                    foreach ($ids as $id) {
                        $id = intval($id);
                        if ($id > 0) {
                            // 删除人员在当前项目的分配
                            $delete_pdp_query = "DELETE FROM project_department_personnel WHERE personnel_id = :personnel_id AND project_id = :project_id";
                            $delete_pdp_stmt = $db->prepare($delete_pdp_query);
                            $delete_pdp_stmt->bindParam(':personnel_id', $id);
                            $delete_pdp_stmt->bindParam(':project_id', $project_id);
                            $delete_pdp_stmt->execute();
                            
                            // 检查该人员是否还有其他项目分配
                            $check_query = "SELECT COUNT(*) as count FROM project_department_personnel WHERE personnel_id = :personnel_id";
                            $check_stmt = $db->prepare($check_query);
                            $check_stmt->bindParam(':personnel_id', $id);
                            $check_stmt->execute();
                            $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
                            
                            // 只有当人员在其他项目中也没有分配时才删除该人员的基本信息
                            if ($result['count'] == 0) {
                                $delete_personnel_query = "DELETE FROM personnel WHERE id = :id";
                                $delete_personnel_stmt = $db->prepare($delete_personnel_query);
                                $delete_personnel_stmt->bindParam(':id', $id);
                                $delete_personnel_stmt->execute();
                                $deleted_count++;
                            }
                            $processed_count++;
                        }
                    }
                    
                    $db->commit();
                    $message = "成功处理 {$processed_count} 个人员，其中 {$deleted_count} 个人员的基本信息已删除！";
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = '批量删除失败：' . $e->getMessage();
                }
            }
        } elseif ($action === 'batch_update_department' && isset($_POST['selected_personnel']) && isset($_POST['department_id'])) {
            // 批量修改部门
            $selected_personnel = $_POST['selected_personnel'];
            $department_id = intval($_POST['department_id']);
            
            // 获取项目ID（从GET参数）
            $project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
            
            if ($project_id == 0) {
                $error = '项目ID无效！';
            } elseif (empty($selected_personnel) || !is_array($selected_personnel)) {
                $error = '请选择要修改的人员！';
            } elseif ($department_id <= 0) {
                $error = '请选择有效的部门！';
            } else {
                try {
                    $db->beginTransaction();
                    
                    // 更新project_department_personnel表中的部门ID
                    $update_sql = "
                        UPDATE project_department_personnel 
                        SET department_id = :department_id 
                        WHERE personnel_id = :personnel_id AND project_id = :project_id
                    ";
                    $stmt = $db->prepare($update_sql);
                    
                    foreach ($selected_personnel as $personnel_id) {
                        $personnel_id = intval($personnel_id);
                        if ($personnel_id > 0) {
                            $stmt->execute([
                                'department_id' => $department_id,
                                'personnel_id' => $personnel_id,
                                'project_id' => $project_id
                            ]);
                        }
                    }
                    
                    $db->commit();
                    $message = '批量修改部门成功！';
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = '批量修改部门失败：' . $e->getMessage();
                }
            }
        } elseif ($action === 'batch_update_gender' && isset($_POST['selected_personnel']) && isset($_POST['gender'])) {
            // 批量修改性别
            $selected_personnel = $_POST['selected_personnel'];
            $gender = $_POST['gender'];
            
            if (empty($selected_personnel) || !is_array($selected_personnel)) {
                $error = '请选择要修改的人员！';
            } elseif (empty($gender)) {
                $error = '请选择有效的性别！';
            } else {
                try {
                    $db->beginTransaction();
                    
                    // 更新personnel表中的性别字段
                    $update_sql = "
                        UPDATE personnel 
                        SET gender = :gender 
                        WHERE id = :personnel_id
                    ";
                    $stmt = $db->prepare($update_sql);
                    
                    foreach ($selected_personnel as $personnel_id) {
                        $personnel_id = intval($personnel_id);
                        if ($personnel_id > 0) {
                            $stmt->execute([
                                'gender' => $gender,
                                'personnel_id' => $personnel_id
                            ]);
                        }
                    }
                    
                    $db->commit();
                    $message = '批量修改性别成功！';
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = '批量修改性别失败：' . $e->getMessage();
                }
            }
        }
    }
}

// 获取项目筛选参数
$filters = [
    'project_id' => isset($_GET['project_id']) ? intval($_GET['project_id']) : 0,
    'gender' => isset($_GET['gender']) ? $_GET['gender'] : '',
    'department_id' => isset($_GET['department_id']) ? intval($_GET['department_id']) : 0
];

// 获取所有项目用于选择
$projects_query = "SELECT id, name, code FROM projects ORDER BY name ASC";
$projects_stmt = $db->prepare($projects_query);
$projects_stmt->execute();
$all_projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);

// 只有选择了项目才查询人员数据
$personnel = [];
if ($filters['project_id']) {
    // 构建筛选条件
    $where_conditions = ['pdp2.project_id = :project_id'];
    $params = [':project_id' => $filters['project_id']];
    
    // 性别筛选
    if (!empty($filters['gender'])) {
        $where_conditions[] = 'p.gender = :gender';
        $params[':gender'] = $filters['gender'];
    }
    
    // 部门筛选
    if (!empty($filters['department_id'])) {
        $where_conditions[] = 'pdp2.department_id = :department_id';
        $params[':department_id'] = $filters['department_id'];
    }
    
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    
    // 获取人员列表及其详细关联信息，包括项目访问状态
    $query = "SELECT 
                p.id,
                p.name,
                p.gender,
                p.phone,
                p.email,
                p.id_card,
                p.created_at,
                GROUP_CONCAT(DISTINCT CONCAT(p2.id, ':', p2.name) SEPARATOR '|') as projects,
                GROUP_CONCAT(DISTINCT CONCAT(d.id, ':', d.name) SEPARATOR '|') as departments,
                GROUP_CONCAT(DISTINCT CONCAT(p2.name, ' - ', d.name, ' (', pdp.position, ')') SEPARATOR '; ') as project_details,
                (SELECT COUNT(DISTINCT pu2.id) FROM personnel_project_users ppu2 
                 LEFT JOIN project_users pu2 ON ppu2.project_user_id = pu2.id AND pu2.is_active = 1 
                 WHERE ppu2.personnel_id = p.id) as project_access_count,
                (SELECT GROUP_CONCAT(DISTINCT CONCAT(p3.name, ':', pu3.username) ORDER BY p3.name, pu3.username SEPARATOR '; ')
                 FROM personnel_project_users ppu3 
                 LEFT JOIN project_users pu3 ON ppu3.project_user_id = pu3.id AND pu3.is_active = 1
                 LEFT JOIN projects p3 ON pu3.project_id = p3.id
                 WHERE ppu3.personnel_id = p.id AND p3.id IS NOT NULL) as project_access_details,
                MIN(COALESCE(d2.sort_order, 0)) as current_project_sort_order,
                MIN(d2.name) as current_project_dept_name,
                pdp2.position as current_project_position  /* 添加当前项目的职位信息 */
             FROM personnel p 
             LEFT JOIN project_department_personnel pdp ON p.id = pdp.personnel_id 
             LEFT JOIN departments d ON pdp.department_id = d.id
             LEFT JOIN projects p2 ON pdp.project_id = p2.id
             LEFT JOIN project_department_personnel pdp2 ON p.id = pdp2.personnel_id AND pdp2.project_id = :project_id
             LEFT JOIN departments d2 ON pdp2.department_id = d2.id
             $where_clause
             GROUP BY p.id, p.name, p.gender, p.phone, p.email, p.id_card, p.created_at, pdp2.position
             ORDER BY current_project_sort_order, current_project_dept_name, p.name";

    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $personnel = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 获取所有公司、项目和部门用于选择
$companies_query = "SELECT id, name FROM companies ORDER BY name";
$companies_stmt = $db->prepare($companies_query);
$companies_stmt->execute();
$all_companies = $companies_stmt->fetchAll(PDO::FETCH_ASSOC);

$projects_query = "SELECT id, name, code, company_id FROM projects ORDER BY name";
$projects_stmt = $db->prepare($projects_query);
$projects_stmt->execute();
$all_projects = $projects_stmt->fetchAll(PDO::FETCH_ASSOC);

$departments_query = "SELECT id, name, project_id FROM departments ORDER BY name";
$departments_stmt = $db->prepare($departments_query);
$departments_stmt->execute();
$all_departments = $departments_stmt->fetchAll(PDO::FETCH_ASSOC);

// 处理登出
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// 处理编辑请求（保留向后兼容性，但不再通过URL参数触发）
$edit_person = null;
$edit_assignments = [];
?>

        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <!-- 项目选择区域 -->
                    <div class="project-selector">
                        <h4>
                            <i class="bi bi-building"></i>
                            项目选择
                        </h4>
                        <form method="GET" id="projectForm">
                            <!-- 保持其他筛选条件 -->
                            <?php if (isset($_GET['gender'])): ?><input type="hidden" name="gender" value="<?php echo htmlspecialchars($_GET['gender']); ?>"><?php endif; ?>
                            <?php if (isset($_GET['department_id'])): ?><input type="hidden" name="department_id" value="<?php echo htmlspecialchars($_GET['department_id']); ?>"><?php endif; ?>
                            
                            <div class="row align-items-end">
                                <div class="col-md-8">
                                    <label for="project_id" class="form-label fw-semibold">请选择要查看的项目</label>
                                    <select class="form-select" id="project_id" name="project_id" onchange="this.form.submit()">
                                        <option value="">请选择项目...</option>
                                        <?php foreach ($all_projects as $project): ?>
                                            <option value="<?php echo $project['id']; ?>" 
                                                    <?php echo $filters['project_id'] == $project['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($project['name']); ?>
                                                <?php if (!empty($project['code'])): ?>
                                                    (<?php echo htmlspecialchars($project['code']); ?>)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <?php if ($filters['project_id']): ?>
                                        <div class="project-status selected">
                                            <i class="bi bi-check-circle-fill"></i>
                                            已选择项目
                                        </div>
                                    <?php else: ?>
                                        <div class="project-status not-selected">
                                            <i class="bi bi-exclamation-circle-fill"></i>
                                            请先选择项目
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    </div>

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

                    <?php if ($filters['project_id']): ?>
                        <!-- 人员管理区域 -->
                        <div class="reports-container">
                            <div class="reports-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0">
                                        <i class="bi bi-table me-2"></i>人员管理
                                        <span class="badge bg-light text-dark ms-2"><?php echo count($personnel); ?></span>
                                    </h5>
                                    <div>
                                        <a href="batch_add_personnel.php" class="btn btn-light btn-sm me-2">
                                            <i class="bi bi-people-fill"></i> 批量添加
                                        </a>
                                        <button type="button" class="btn btn-light btn-sm me-2" data-bs-toggle="modal" data-bs-target="#addPersonModal">
                                            <i class="bi bi-plus-circle"></i> 新增人员
                                        </button>
                                        <button type="button" class="btn btn-danger btn-sm" onclick="batchDeletePersonnel()">
                                            <i class="bi bi-trash"></i> 批量删除
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="reports-body">
                                <?php if (empty($personnel)): ?>
                                    <div class="empty-state">
                                        <i class="bi bi-people display-1"></i>
                                        <h5 class="mt-3">该项目暂无人员</h5>
                                        <p class="text-muted mb-4">可以开始添加人员到该项目</p>
                                        <div>
                                            <button type="button" class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#addPersonModal">
                                                <i class="bi bi-plus-circle"></i> 新增人员
                                            </button>
                                            <a href="batch_add_personnel.php" class="btn btn-success">
                                                <i class="bi bi-people-fill"></i> 批量添加
                                            </a>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <!-- 筛选区域 -->
                                    <div class="card mb-4">
                                        <div class="card-header">
                                            <h6 class="mb-0"><i class="bi bi-funnel me-2"></i>筛选条件</h6>
                                        </div>
                                        <div class="card-body">
                                            <form method="GET" class="row g-3">
                                                <input type="hidden" name="project_id" value="<?php echo $filters['project_id']; ?>">
                                                
                                                <div class="col-md-4">
                                                    <label for="gender_filter" class="form-label">按性别筛选</label>
                                                    <select class="form-select" id="gender_filter" name="gender" onchange="this.form.submit()">
                                                        <option value="">全部性别</option>
                                                        <option value="男" <?php echo ($filters['gender'] === '男') ? 'selected' : ''; ?>>男</option>
                                                        <option value="女" <?php echo ($filters['gender'] === '女') ? 'selected' : ''; ?>>女</option>
                                                    </select>
                                                </div>
                                                
                                                <div class="col-md-4">
                                                    <label for="department_filter" class="form-label">按部门筛选</label>
                                                    <select class="form-select" id="department_filter" name="department_id" onchange="this.form.submit()">
                                                        <option value="">全部部门</option>
                                                        <?php 
                                                        // 只显示当前项目的部门
                                                        $project_departments = array_filter($all_departments, function($dept) use ($filters) {
                                                            return $dept['project_id'] == $filters['project_id'];
                                                        });
                                                        foreach ($project_departments as $dept): ?>
                                                            <option value="<?php echo $dept['id']; ?>" <?php echo ($filters['department_id'] == $dept['id']) ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($dept['name']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                
                                                <div class="col-md-4 d-flex align-items-end">
                                                    <button type="submit" class="btn btn-primary me-2">筛选</button>
                                                    <a href="?project_id=<?php echo $filters['project_id']; ?>" class="btn btn-secondary">重置</a>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                    
                                    <!-- 批量操作区域 -->
                                    <div class="card mb-4">
                                        <div class="card-header">
                                            <h6 class="mb-0"><i class="bi bi-list-check me-2"></i>批量操作</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <!-- 批量修改部门 -->
                                                <div class="col-md-6 mb-3">
                                                    <form id="batchDepartmentForm" method="POST" action="?project_id=<?php echo $filters['project_id']; ?>">
                                                        <div class="row">
                                                            <div class="col-md-8">
                                                                <select class="form-select" id="batchDepartment" name="department_id" required>
                                                                    <option value="">选择部门...</option>
                                                                    <?php 
                                                                    // 只显示当前项目的部门
                                                                    $project_departments = array_filter($all_departments, function($dept) use ($filters) {
                                                                        return $dept['project_id'] == $filters['project_id'];
                                                                    });
                                                                    foreach ($project_departments as $dept): ?>
                                                                        <option value="<?php echo $dept['id']; ?>">
                                                                            <?php echo htmlspecialchars($dept['name']); ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <button type="submit" name="action" value="batch_update_department" class="btn btn-warning" id="batchUpdateDepartmentBtn" disabled>
                                                                    <i class="bi bi-building"></i> 批量修改部门
                                                                </button>
                                                            </div>
                                                        </div>
                                                        <!-- 动态添加选中的人员ID -->
                                                        <div id="departmentPersonnelInputs"></div>
                                                    </form>
                                                </div>
                                                
                                                <!-- 批量修改性别 -->
                                                <div class="col-md-6 mb-3">
                                                    <form id="batchGenderForm" method="POST" action="?project_id=<?php echo $filters['project_id']; ?>">
                                                        <div class="row">
                                                            <div class="col-md-8">
                                                                <select class="form-select" id="batchGender" name="gender" required>
                                                                    <option value="">选择性别...</option>
                                                                    <option value="男">男</option>
                                                                    <option value="女">女</option>
                                                                </select>
                                                            </div>
                                                            <div class="col-md-4">
                                                                <button type="submit" name="action" value="batch_update_gender" class="btn btn-info" id="batchUpdateGenderBtn" disabled>
                                                                    <i class="bi bi-gender-ambiguous"></i> 批量修改性别
                                                                </button>
                                                            </div>
                                                        </div>
                                                        <!-- 动态添加选中的人员ID -->
                                                        <div id="genderPersonnelInputs"></div>
                                                    </form>
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-12">
                                                    <button type="button" class="btn btn-secondary" id="clearSelectionBtn" style="display: none;">
                                                        <i class="bi bi-x-circle"></i> 取消选择
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th width="30"><input type="checkbox" id="selectAll" onchange="toggleAllPersonnel(this)"></th>
                                                    <th>姓名</th>
                                                    <th>性别</th>
                                                    <th>部门</th>
                                                    <th>项目/部门</th>
                                                    <th>项目账户</th>
                                                    <th>操作</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($personnel as $person): ?>
                                                <tr>
                                                    <td><input type="checkbox" class="personnel-checkbox" value="<?php echo $person['id']; ?>" onchange="updateSelectAllPersonnel()"></td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($person['name']); ?></strong>
                                                        <br><small class="text-muted"><?php echo htmlspecialchars($person['id_card']); ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?php echo $person['gender'] === '男' ? 'primary' : 'danger'; ?>">
                                                            <?php echo $person['gender']; ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $deptName = $person['current_project_dept_name'];
                                                        $position = $person['current_project_position'];
                                                        $isArtistOrGuest = (strpos($deptName, '艺人') !== false || strpos($deptName, '嘉宾') !== false);
                                                        ?>
                                                        <?php if ($isArtistOrGuest): ?>
                                                            <span class="badge artist-badge">
                                                                <?php echo htmlspecialchars($deptName); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="badge bg-info"><?php echo htmlspecialchars($deptName); ?></span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($position)): ?>
                                                            <span class="department-position"><?php echo htmlspecialchars($position); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="project-department-cell">
                                                        <?php if ($person['project_details']): ?>
                                                            <div class="project-tag-container">
                                                                <?php 
                                                                $project_details = $person['project_details'];
                                                                $projects = array_unique(explode('; ', $project_details));
                                                                foreach ($projects as $project_info):
                                                                    if (trim($project_info)):
                                                                ?>
                                                                    <span class="project-complete-tag"><?php echo htmlspecialchars(trim($project_info)); ?></span>
                                                                <?php 
                                                                    endif;
                                                                endforeach; 
                                                                ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($person['project_access_count'] > 0): ?>
                                                            <span class="badge bg-success"><?php echo $person['project_access_count']; ?>个账户</span>
                                                            <button type="button" class="btn btn-sm btn-outline-primary ms-2" onclick="showProjectAccess(<?php echo $person['id']; ?>)">
                                                                <i class="bi bi-eye"></i> 查看
                                                            </button>
                                                            <a href="personnel_project_access.php?id=<?php echo $person['id']; ?>" class="btn btn-sm btn-outline-success ms-1" title="管理项目账户">
                                                                <i class="bi bi-gear"></i> 管理
                                                            </a>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">无账户</span>
                                                            <a href="personnel_project_access.php?id=<?php echo $person['id']; ?>" class="btn btn-sm btn-outline-success ms-2" title="分配项目账户">
                                                                <i class="bi bi-plus-circle"></i> 分配
                                                            </a>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group" role="group">
                                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="editPerson(<?php echo $person['id']; ?>)">
                                                                <i class="bi bi-pencil"></i> 编辑
                                                            </button>
                                                            <button type="button" class="btn btn-sm btn-outline-info" onclick="viewPersonDetails(<?php echo $person['id']; ?>)">
                                                                <i class="bi bi-info-circle"></i> 详情
                                                            </button>
                                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="deletePerson(<?php echo $person['id']; ?>)">
                                                                <i class="bi bi-trash"></i> 删除
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 添加人员模态框 -->
        <div class="modal fade" id="addPersonModal" tabindex="-1" aria-labelledby="addPersonModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form id="addPersonForm" method="POST">
                        <input type="hidden" name="action" value="add">
                        <div class="modal-header">
                            <h5 class="modal-title" id="addPersonModalLabel">新增人员</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="add_name" class="form-label">姓名 *</label>
                                        <input type="text" class="form-control" id="add_name" name="name" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="add_gender" class="form-label">性别 *</label>
                                        <select class="form-select" id="add_gender" name="gender" required>
                                            <option value="">请选择...</option>
                                            <option value="男">男</option>
                                            <option value="女">女</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="add_phone" class="form-label">手机号</label>
                                        <input type="text" class="form-control" id="add_phone" name="phone">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="add_email" class="form-label">邮箱</label>
                                        <input type="email" class="form-control" id="add_email" name="email">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="add_id_card" class="form-label">身份证号</label>
                                        <input type="text" class="form-control" id="add_id_card" name="id_card" maxlength="18">
                                    </div>
                                </div>
                            </div>
                            
                            <hr>
                            
                            <h6>项目分配</h6>
                            <div class="mb-3">
                                <label for="add_project_id" class="form-label">选择项目</label>
                                <select class="form-select" id="add_project_id">
                                    <option value="">请选择项目...</option>
                                    <?php foreach ($all_projects as $project): ?>
                                        <option value="<?php echo $project['id']; ?>"><?php echo htmlspecialchars($project['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div id="add_department_container" style="display: none;">
                                <div class="mb-3">
                                    <label for="add_department_id" class="form-label">选择部门</label>
                                    <select class="form-select" id="add_department_id">
                                        <option value="">请选择部门...</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="add_position" class="form-label">职位</label>
                                    <input type="text" class="form-control" id="add_position" placeholder="请输入职位">
                                </div>
                                
                                <button type="button" class="btn btn-success" onclick="addAssignment()">
                                    <i class="bi bi-plus-circle"></i> 添加分配
                                </button>
                            </div>
                            
                            <div class="mt-3">
                                <h6>已添加的分配</h6>
                                <div id="add_assignments_list"></div>
                                <input type="hidden" id="add_project_assignments" name="project_assignments" value="[]">
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

        <!-- 编辑人员模态框 -->
        <div class="modal fade" id="editPersonModal" tabindex="-1" aria-labelledby="editPersonModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <form id="editPersonForm" method="POST">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" id="edit_id" name="id">
                        <div class="modal-header">
                            <h5 class="modal-title" id="editPersonModalLabel">编辑人员</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="edit_name" class="form-label">姓名 *</label>
                                        <input type="text" class="form-control" id="edit_name" name="name" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="edit_gender" class="form-label">性别 *</label>
                                        <select class="form-select" id="edit_gender" name="gender" required>
                                            <option value="">请选择...</option>
                                            <option value="男">男</option>
                                            <option value="女">女</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="edit_phone" class="form-label">手机号</label>
                                        <input type="text" class="form-control" id="edit_phone" name="phone">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="edit_email" class="form-label">邮箱</label>
                                        <input type="email" class="form-control" id="edit_email" name="email">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="edit_id_card" class="form-label">身份证号</label>
                                        <input type="text" class="form-control" id="edit_id_card" name="id_card" maxlength="18">
                                    </div>
                                </div>
                            </div>
                            
                            <hr>
                            
                            <h6>项目分配</h6>
                            <div class="mb-3">
                                <label for="edit_project_id" class="form-label">选择项目</label>
                                <select class="form-select" id="edit_project_id">
                                    <option value="">请选择项目...</option>
                                    <?php foreach ($all_projects as $project): ?>
                                        <option value="<?php echo $project['id']; ?>"><?php echo htmlspecialchars($project['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div id="edit_department_container" style="display: none;">
                                <div class="mb-3">
                                    <label for="edit_department_id" class="form-label">选择部门</label>
                                    <select class="form-select" id="edit_department_id">
                                        <option value="">请选择部门...</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="edit_position" class="form-label">职位</label>
                                    <input type="text" class="form-control" id="edit_position" placeholder="请输入职位">
                                </div>
                                
                                <button type="button" class="btn btn-success" onclick="editAddAssignment()">
                                    <i class="bi bi-plus-circle"></i> 添加分配
                                </button>
                            </div>
                            
                            <div class="mt-3">
                                <h6>已添加的分配</h6>
                                <div id="edit_assignments_list"></div>
                                <input type="hidden" id="edit_project_assignments" name="project_assignments" value="[]">
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

        <!-- 人员详情模态框 -->
        <div class="modal fade" id="personDetailsModal" tabindex="-1" aria-labelledby="personDetailsModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="personDetailsModalLabel">人员详情</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="personDetailsContent"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- 项目账户详情模态框 -->
        <div class="modal fade" id="projectAccessModal" tabindex="-1" aria-labelledby="projectAccessModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="projectAccessModalLabel">项目账户详情</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="projectAccessContent"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        // 全局变量
        let addAssignments = [];
        let editAssignments = [];
        let currentEditPersonId = null;

        // 页面加载完成后初始化
        document.addEventListener('DOMContentLoaded', function() {
            // 初始化项目选择器
            initializeProjectSelectors();
            
            // 初始化表单提交事件
            initializeFormSubmissions();
            
            // 初始化模态框事件
            initializeModalEvents();
        });

        // 初始化项目选择器
        function initializeProjectSelectors() {
            // 添加人员模态框的项目选择器
            document.getElementById('add_project_id').addEventListener('change', function() {
                const projectId = this.value;
                const container = document.getElementById('add_department_container');
                
                if (projectId) {
                    container.style.display = 'block';
                    loadDepartments(projectId, 'add_department_id');
                } else {
                    container.style.display = 'none';
                }
            });
            
            // 编辑人员模态框的项目选择器
            document.getElementById('edit_project_id').addEventListener('change', function() {
                const projectId = this.value;
                const container = document.getElementById('edit_department_container');
                
                if (projectId) {
                    container.style.display = 'block';
                    loadDepartments(projectId, 'edit_department_id');
                } else {
                    container.style.display = 'none';
                }
            });
        }

        // 加载部门数据
        function loadDepartments(projectId, selectId) {
            const select = document.getElementById(selectId);
            
            // 清空现有选项
            select.innerHTML = '<option value="">请选择部门...</option>';
            
            // 根据项目ID过滤部门
            <?php foreach ($all_departments as $dept): ?>
            if (<?php echo $dept['project_id']; ?> == projectId) {
                const option = document.createElement('option');
                option.value = '<?php echo $dept['id']; ?>';
                option.textContent = '<?php echo htmlspecialchars($dept['name']); ?>';
                select.appendChild(option);
            }
            <?php endforeach; ?>
        }

        // 初始化表单提交事件
        function initializeFormSubmissions() {
            // 添加人员表单提交
            document.getElementById('addPersonForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                // 设置项目分配数据
                document.getElementById('add_project_assignments').value = JSON.stringify(addAssignments);
                
                // 提交表单
                this.submit();
            });
            
            // 编辑人员表单提交
            document.getElementById('editPersonForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                // 设置项目分配数据
                document.getElementById('edit_project_assignments').value = JSON.stringify(editAssignments);
                
                // 提交表单
                this.submit();
            });
        }

        // 初始化模态框事件
        function initializeModalEvents() {
            // 添加人员模态框显示事件
            document.getElementById('addPersonModal').addEventListener('show.bs.modal', function() {
                resetAddForm();
            });
            
            // 编辑人员模态框显示事件
            document.getElementById('editPersonModal').addEventListener('show.bs.modal', function() {
                resetEditForm();
            });
        }

        // 重置添加表单
        function resetAddForm() {
            document.getElementById('addPersonForm').reset();
            addAssignments = [];
            updateAddAssignmentsList();
            document.getElementById('add_department_container').style.display = 'none';
        }

        // 重置编辑表单
        function resetEditForm() {
            document.getElementById('editPersonForm').reset();
            editAssignments = [];
            updateEditAssignmentsList();
            document.getElementById('edit_department_container').style.display = 'none';
        }

        // 添加分配
        function addAssignment() {
            const projectId = document.getElementById('add_project_id').value;
            const departmentId = document.getElementById('add_department_id').value;
            const position = document.getElementById('add_position').value;
            
            if (!projectId || !departmentId) {
                alert('请选择项目和部门');
                return;
            }
            
            // 获取项目和部门名称
            const projectName = document.getElementById('add_project_id').options[document.getElementById('add_project_id').selectedIndex].text;
            const departmentName = document.getElementById('add_department_id').options[document.getElementById('add_department_id').selectedIndex].text;
            
            // 添加到分配列表
            addAssignments.push({
                project_id: projectId,
                department_id: departmentId,
                position: position,
                project_name: projectName,
                department_name: departmentName
            });
            
            // 更新显示
            updateAddAssignmentsList();
            
            // 重置选择器
            document.getElementById('add_project_id').value = '';
            document.getElementById('add_department_id').value = '';
            document.getElementById('add_position').value = '';
            document.getElementById('add_department_container').style.display = 'none';
        }

        // 编辑时添加分配
        function editAddAssignment() {
            const projectId = document.getElementById('edit_project_id').value;
            const departmentId = document.getElementById('edit_department_id').value;
            const position = document.getElementById('edit_position').value;
            
            if (!projectId || !departmentId) {
                alert('请选择项目和部门');
                return;
            }
            
            // 获取项目和部门名称
            const projectName = document.getElementById('edit_project_id').options[document.getElementById('edit_project_id').selectedIndex].text;
            const departmentName = document.getElementById('edit_department_id').options[document.getElementById('edit_department_id').selectedIndex].text;
            
            // 添加到分配列表
            editAssignments.push({
                project_id: projectId,
                department_id: departmentId,
                position: position,
                project_name: projectName,
                department_name: departmentName
            });
            
            // 更新显示
            updateEditAssignmentsList();
            
            // 重置选择器
            document.getElementById('edit_project_id').value = '';
            document.getElementById('edit_department_id').value = '';
            document.getElementById('edit_position').value = '';
            document.getElementById('edit_department_container').style.display = 'none';
        }

        // 更新添加分配列表显示
        function updateAddAssignmentsList() {
            const container = document.getElementById('add_assignments_list');
            
            if (addAssignments.length === 0) {
                container.innerHTML = '<p class="text-muted">暂无分配</p>';
                return;
            }
            
            let html = '<div class="list-group">';
            addAssignments.forEach((assignment, index) => {
                html += `
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong>${assignment.project_name}</strong> - ${assignment.department_name}
                            ${assignment.position ? `(${assignment.position})` : ''}
                        </div>
                        <button type="button" class="btn btn-sm btn-danger" onclick="removeAddAssignment(${index})">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                `;
            });
            html += '</div>';
            
            container.innerHTML = html;
        }

        // 更新编辑分配列表显示
        function updateEditAssignmentsList() {
            const container = document.getElementById('edit_assignments_list');
            
            if (editAssignments.length === 0) {
                container.innerHTML = '<p class="text-muted">暂无分配</p>';
                return;
            }
            
            let html = '<div class="list-group">';
            editAssignments.forEach((assignment, index) => {
                html += `
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong>${assignment.project_name}</strong> - ${assignment.department_name}
                            ${assignment.position ? `(${assignment.position})` : ''}
                        </div>
                        <button type="button" class="btn btn-sm btn-danger" onclick="removeEditAssignment(${index})">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                `;
            });
            html += '</div>';
            
            container.innerHTML = html;
        }

        // 移除添加分配
        function removeAddAssignment(index) {
            addAssignments.splice(index, 1);
            updateAddAssignmentsList();
        }

        // 移除编辑分配
        function removeEditAssignment(index) {
            editAssignments.splice(index, 1);
            updateEditAssignmentsList();
        }

        // 编辑人员
        function editPerson(id) {
            fetch(`?action=get_person&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const person = data.person;
                        const assignments = data.assignments;
                        
                        // 填充基本信息
                        document.getElementById('edit_id').value = person.id;
                        document.getElementById('edit_name').value = person.name;
                        document.getElementById('edit_gender').value = person.gender;
                        document.getElementById('edit_phone').value = person.phone || '';
                        document.getElementById('edit_email').value = person.email || '';
                        document.getElementById('edit_id_card').value = person.id_card || '';
                        
                        // 设置分配信息
                        editAssignments = assignments.map(assignment => ({
                            project_id: assignment.project_id,
                            department_id: assignment.department_id,
                            position: assignment.position,
                            project_name: assignment.project_name,
                            department_name: assignment.department_name
                        }));
                        
                        updateEditAssignmentsList();
                        
                        // 显示模态框
                        const modal = new bootstrap.Modal(document.getElementById('editPersonModal'));
                        modal.show();
                    } else {
                        alert('获取人员信息失败：' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('获取人员信息时发生错误');
                });
        }

        // 查看人员详情
        function viewPersonDetails(id) {
            fetch(`?action=get_person_details&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const person = data.person;
                        const assignments = data.assignments;
                        const mealRecords = data.meal_records;
                        const hotelRecords = data.hotel_records;
                        const transportRecords = data.transport_records;
                        
                        let html = `
                            <div class="row">
                                <div class="col-md-6">
                                    <h5>基本信息</h5>
                                    <table class="table table-bordered">
                                        <tr>
                                            <th>姓名</th>
                                            <td>${person.name}</td>
                                        </tr>
                                        <tr>
                                            <th>性别</th>
                                            <td>${person.gender}</td>
                                        </tr>
                                        <tr>
                                            <th>手机号</th>
                                            <td>${person.phone || '未填写'}</td>
                                        </tr>
                                        <tr>
                                            <th>邮箱</th>
                                            <td>${person.email || '未填写'}</td>
                                        </tr>
                                        <tr>
                                            <th>身份证号</th>
                                            <td>${person.id_card || '未填写'}</td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <h5>项目分配</h5>
                                    ${assignments.length > 0 ? `
                                        <table class="table table-bordered">
                                            <thead>
                                                <tr>
                                                    <th>项目</th>
                                                    <th>部门</th>
                                                    <th>职位</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                ${assignments.map(assignment => `
                                                    <tr>
                                                        <td>${assignment.project_name}</td>
                                                        <td>${assignment.department_name}</td>
                                                        <td>${assignment.position || '未填写'}</td>
                                                    </tr>
                                                `).join('')}
                                            </tbody>
                                        </table>
                                    ` : '<p class="text-muted">暂无项目分配</p>'}
                                </div>
                            </div>
                            
                            <hr>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <h5>用餐记录 (${mealRecords.length})</h5>
                                    ${mealRecords.length > 0 ? `
                                        <div class="table-responsive">
                                            <table class="table table-bordered table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>项目</th>
                                                        <th>日期</th>
                                                        <th>餐别</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    ${mealRecords.map(record => `
                                                        <tr>
                                                            <td>${record.project_name}</td>
                                                            <td>${record.meal_date}</td>
                                                            <td>${record.meal_type}</td>
                                                        </tr>
                                                    `).join('')}
                                                </tbody>
                                            </table>
                                        </div>
                                    ` : '<p class="text-muted">暂无用餐记录</p>'}
                                </div>
                                <div class="col-md-4">
                                    <h5>住宿记录 (${hotelRecords.length})</h5>
                                    ${hotelRecords.length > 0 ? `
                                        <div class="table-responsive">
                                            <table class="table table-bordered table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>酒店</th>
                                                        <th>入住</th>
                                                        <th>退房</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    ${hotelRecords.map(record => `
                                                        <tr>
                                                            <td>${record.hotel_name}</td>
                                                            <td>${record.check_in_date}</td>
                                                            <td>${record.check_out_date}</td>
                                                        </tr>
                                                    `).join('')}
                                                </tbody>
                                            </table>
                                        </div>
                                    ` : '<p class="text-muted">暂无住宿记录</p>'}
                                </div>
                                <div class="col-md-4">
                                    <h5>交通记录 (${transportRecords.length})</h5>
                                    ${transportRecords.length > 0 ? `
                                        <div class="table-responsive">
                                            <table class="table table-bordered table-sm">
                                                <thead>
                                                    <tr>
                                                        <th>类型</th>
                                                        <th>日期</th>
                                                        <th>出发地</th>
                                                        <th>目的地</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    ${transportRecords.map(record => `
                                                        <tr>
                                                            <td>${record.transport_type}</td>
                                                            <td>${record.travel_date}</td>
                                                            <td>${record.departure_location}</td>
                                                            <td>${record.arrival_location}</td>
                                                        </tr>
                                                    `).join('')}
                                                </tbody>
                                            </table>
                                        </div>
                                    ` : '<p class="text-muted">暂无交通记录</p>'}
                                </div>
                            </div>
                        `;
                        
                        document.getElementById('personDetailsContent').innerHTML = html;
                        
                        // 显示模态框
                        const modal = new bootstrap.Modal(document.getElementById('personDetailsModal'));
                        modal.show();
                    } else {
                        alert('获取人员详情失败：' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('获取人员详情时发生错误');
                });
        }

        // 删除人员
        function deletePerson(id) {
            if (confirm('确定要删除这个人员吗？')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = `?project_id=<?php echo $filters['project_id']; ?>`;
                
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

        // 批量删除人员
        function batchDeletePersonnel() {
            const selected = document.querySelectorAll('.personnel-checkbox:checked');
            
            if (selected.length === 0) {
                alert('请先选择要删除的人员');
                return;
            }
            
            if (confirm(`确定要删除这 ${selected.length} 个人员吗？`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = `?project_id=<?php echo $filters['project_id']; ?>`;
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'batch_delete';
                form.appendChild(actionInput);
                
                selected.forEach(checkbox => {
                    const idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    idInput.name = 'ids[]';
                    idInput.value = checkbox.value;
                    form.appendChild(idInput);
                });
                
                document.body.appendChild(form);
                form.submit();
            }
        }

        // 显示项目账户详情
        function showProjectAccess(personnelId) {
            const modal = new bootstrap.Modal(document.getElementById('projectAccessModal'));
            const contentDiv = document.getElementById('projectAccessContent');
            
            // 显示加载状态
            contentDiv.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted">加载中...</p></div>';
            modal.show();
            
            // 发送AJAX请求获取数据
            fetch(`ajax_get_personnel_project_access.php?personnel_id=${personnelId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderProjectAccessContent(data);
                    } else {
                        contentDiv.innerHTML = `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> ${data.message}</div>`;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    contentDiv.innerHTML = '<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> 获取数据失败，请重试</div>';
                });
        }
        
        // 渲染项目账户内容
        function renderProjectAccessContent(data) {
            const contentDiv = document.getElementById('projectAccessContent');
            const personnel = data.personnel;
            const accounts = data.accounts;
            
            let html = `
                <div class="mb-3">
                    <h6 class="text-primary"><i class="bi bi-person"></i> 人员信息</h6>
                    <div class="bg-light p-3 rounded">
                        <div class="row">
                            <div class="col-md-4"><strong>姓名:</strong> ${escapeHtml(personnel.name)}</div>
                            <div class="col-md-4"><strong>电话:</strong> ${personnel.phone ? escapeHtml(personnel.phone) : '-'}</div>
                            <div class="col-md-4"><strong>邮箱:</strong> ${personnel.email ? escapeHtml(personnel.email) : '-'}</div>
                        </div>
                    </div>
                </div>
            `;
            
            if (accounts.length === 0) {
                html += '<div class="alert alert-info"><i class="bi bi-info-circle"></i> 该人员暂无项目账户</div>';
            } else {
                html += `<h6 class="text-primary mb-3"><i class="bi bi-key"></i> 项目账户列表 (${accounts.length}个)</h6>`;
                html += '<div class="table-responsive"><table class="table table-sm table-hover">';
                html += `
                    <thead class="table-light">
                        <tr>
                            <th>账户ID</th>
                            <th>项目名称</th>
                            <th>访问代码</th>
                            <th>用户名</th>
                            <th style="width: 90px;">角色</th>
                            <th style="width: 70px;">状态</th>
                            <th style="width: 130px;">创建时间</th>
                            <th style="width: 130px;">更新时间</th>
                            <th style="width: 60px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                `;
                
                accounts.forEach(account => {
                    const roleBadge = account.role === 'admin' 
                        ? '<span class="badge bg-danger">前台管理员</span>' 
                        : '<span class="badge bg-info">前台用户</span>';
                    const statusBadge = account.is_active 
                        ? '<span class="badge bg-success">正常</span>' 
                        : '<span class="badge bg-secondary">已禁用</span>';
                    const createdAt = account.created_at ? formatDateTime(account.created_at) : '-';
                    const updatedAt = account.updated_at ? formatDateTime(account.updated_at) : '-';
                    
                    html += `
                        <tr>
                            <td>${account.account_id}</td>
                            <td>
                                <div>${escapeHtml(account.project_name)}</div>
                                <small class="text-muted">${escapeHtml(account.company_name)}</small>
                            </td>
                            <td><code>${account.project_code}</code></td>
                            <td>${escapeHtml(account.username)}</td>
                            <td>${roleBadge}</td>
                            <td>${statusBadge}</td>
                            <td>${createdAt}</td>
                            <td>${updatedAt}</td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="copyAccessUrl('${account.access_url}')" title="复制访问地址">
                                    <i class="bi bi-link-45deg"></i>
                                </button>
                            </td>
                        </tr>
                    `;
                });
                
                html += '</tbody></table></div>';
            }
            
            contentDiv.innerHTML = html;
        }
        
        // HTML转义函数
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // 格式化日期时间
        function formatDateTime(datetime) {
            if (!datetime) return '-';
            const date = new Date(datetime);
            return date.toLocaleString('zh-CN', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
        }
        
        // 复制访问地址
        function copyAccessUrl(url) {
            navigator.clipboard.writeText(url).then(() => {
                // 创建临时提示
                const toast = document.createElement('div');
                toast.className = 'position-fixed top-0 end-0 m-3 alert alert-success';
                toast.style.zIndex = '9999';
                toast.innerHTML = '<i class="bi bi-check-circle"></i> 已复制访问地址';
                document.body.appendChild(toast);
                
                setTimeout(() => {
                    document.body.removeChild(toast);
                }, 2000);
            }).catch(err => {
                console.error('复制失败:', err);
                alert('复制失败，请手动复制');
            });
        }

        // 全选/取消全选
        function toggleAllPersonnel(source) {
            const checkboxes = document.querySelectorAll('.personnel-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = source.checked;
            });
            updateBatchButtons();
        }

        // 更新选择状态
        function updateSelectAllPersonnel() {
            const checkboxes = document.querySelectorAll('.personnel-checkbox');
            const selectAll = document.getElementById('selectAll');
            
            const allChecked = Array.from(checkboxes).every(checkbox => checkbox.checked);
            selectAll.checked = allChecked;
            
            updateBatchButtons();
        }

        // 更新批量操作按钮状态
        function updateBatchButtons() {
            const selected = document.querySelectorAll('.personnel-checkbox:checked');
            const hasSelection = selected.length > 0;
            
            document.getElementById('batchUpdateDepartmentBtn').disabled = !hasSelection;
            document.getElementById('batchUpdateGenderBtn').disabled = !hasSelection;
            document.getElementById('clearSelectionBtn').style.display = hasSelection ? 'inline-block' : 'none';
            
            // 更新隐藏字段
            updateBatchHiddenFields();
        }

        // 更新批量操作隐藏字段
        function updateBatchHiddenFields() {
            // 清空现有的隐藏字段
            document.getElementById('departmentPersonnelInputs').innerHTML = '';
            document.getElementById('genderPersonnelInputs').innerHTML = '';
            
            // 获取选中的人员ID
            const selected = document.querySelectorAll('.personnel-checkbox:checked');
            const ids = Array.from(selected).map(checkbox => checkbox.value);
            
            // 为批量修改部门表单添加隐藏字段
            const departmentForm = document.getElementById('batchDepartmentForm');
            ids.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_personnel[]';
                input.value = id;
                document.getElementById('departmentPersonnelInputs').appendChild(input);
            });
            
            // 为批量修改性别表单添加隐藏字段
            const genderForm = document.getElementById('batchGenderForm');
            ids.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'selected_personnel[]';
                input.value = id;
                document.getElementById('genderPersonnelInputs').appendChild(input);
            });
        }
    </script>
    <?php require_once 'includes/footer.php'; ?>
</body>
</html>
