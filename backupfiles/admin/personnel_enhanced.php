<?php
session_start();
require_once '../config/database.php';
require_once 'page_functions.php';

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


// 处理表单提交
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
                                                        <?php else: ?>
                                                            <span class="text-muted">未分配</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="project-department-cell">
                                                        <?php if ($person['project_access_count'] > 0): ?>
                                                            <div class="project-access-container">
                                                                <span class="badge bg-success mb-1">
                                                                    <i class="bi bi-key-fill"></i> 已开通 <?php echo $person['project_access_count']; ?> 个
                                                                </span>
                                                                <div class="project-access-details">
                                                                    <?php if ($person['project_access_details']): ?>
                                                                        <?php 
                                                                        $details = explode('; ', $person['project_access_details']);
                                                                        $unique_details = array();
                                                                        foreach ($details as $detail) {
                                                                            if (trim($detail) && !in_array($detail, $unique_details)) {
                                                                                $unique_details[] = $detail;
                                                                            }
                                                                        }
                                                                        foreach ($unique_details as $project_info):
                                                                            if (trim($project_info)):
                                                                        ?>
                                                                            <span class="project-complete-tag">
                                                                                <?php echo htmlspecialchars(trim($project_info)); ?>
                                                                            </span>
                                                                        <?php 
                                                                            endif;
                                                                        endforeach; 
                                                                        ?>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">
                                                                <i class="bi bi-x-circle"></i> 未开通
                                                            </span>
                                                        <?php endif; ?>
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
                                                            <a href="personnel_project_access.php?id=<?php echo $person['id']; ?>" 
                                                               class="btn btn-sm btn-outline-success" title="项目访问管理">
                                                                <i class="bi bi-key"></i>
                                                            </a>
                                                            <button type="button" class="btn btn-sm btn-outline-danger" 
                                                                    onclick="deletePerson(<?php echo $person['id']; ?>, '<?php echo htmlspecialchars($person['name']); ?>')" title="删除人员">
                                                                <i class="bi bi-trash"></i>
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
                    <?php else: ?>
                        <!-- 空状态 - 未选择项目 -->
                        <div class="empty-state">
                            <i class="bi bi-building display-1"></i>
                            <h5 class="mt-3">请先选择项目</h5>
                            <p class="text-muted mb-4">选择一个项目后才能查看和管理该项目的人员信息</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

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

    <!-- 合并确认对话框 -->
    <div class="modal fade" id="mergeConfirmModal" tabindex="-1" style="z-index: 10051;">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">确认合并人员信息</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>检测到存在相同身份证号的人员：</p>
                    <p><strong>姓名：</strong><span id="existingPersonName"></span></p>
                    <p><strong>ID：</strong><span id="existingPersonId"></span></p>
                    <p>是否要将当前信息合并到该人员？</p>
                    <div class="alert alert-warning">
                        <strong>注意：</strong>合并后，当前输入的信息将更新到现有人员记录中，
                        并且当前人员的项目分配信息将添加到现有人员的项目分配中。
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="cancelMergeBtn">不合并，继续添加</button>
                    <button type="button" class="btn btn-primary" id="confirmMergeBtn">合并</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 添加/编辑人员模态框 -->
    <div class="modal fade" id="addPersonModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle"><?php echo $edit_person ? '编辑人员' : '新增人员'; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="personForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="<?php echo $edit_person ? 'edit' : 'add'; ?>">
                        <?php if ($edit_person): ?>
                            <input type="hidden" name="id" value="<?php echo $edit_person['id']; ?>">
                        <?php endif; ?>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">姓名 *</label>
                                    <input type="text" class="form-control" name="name" 
                                           value="<?php echo $edit_person ? htmlspecialchars($edit_person['name']) : ''; ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">性别 *</label>
                                    <select class="form-select" name="gender" required>
                                        <option value="">请选择性别</option>
                                        <option value="男" <?php echo $edit_person && $edit_person['gender'] === '男' ? 'selected' : ''; ?>>男</option>
                                        <option value="女" <?php echo $edit_person && $edit_person['gender'] === '女' ? 'selected' : ''; ?>>女</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">电话</label>
                                    <input type="tel" class="form-control" name="phone" 
                                           value="<?php echo $edit_person ? htmlspecialchars($edit_person['phone']) : ''; ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">邮箱</label>
                                    <input type="email" class="form-control" name="email" 
                                           value="<?php echo $edit_person ? htmlspecialchars($edit_person['email']) : ''; ?>">
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">身份证号</label>
                            <input type="text" class="form-control" name="id_card" 
                                   value="<?php echo $edit_person ? htmlspecialchars($edit_person['id_card']) : ''; ?>">
                        </div>

                        <div class="mb-3">
                            <h6><i class="bi bi-briefcase"></i> 项目分配 *</h6>
                            <div id="projectAssignments">
                                <?php if ($edit_assignments): ?>
                                    <?php foreach ($edit_assignments as $assignment): ?>
                                        <div class="project-assignment">
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <label class="form-label">项目 *</label>
                                                    <select class="form-select project-select" name="project_id[]" onchange="loadDepartments(this)" required>
                                                        <option value="">选择项目</option>
                                                        <?php foreach ($all_projects as $project): ?>
                                                            <option value="<?php echo $project['id']; ?>" 
                                                                    <?php echo $assignment['project_id'] == $project['id'] ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($project['name']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label">部门 *</label>
                                                    <select class="form-select department-select" name="department_id[]" required>
                                                        <option value="">先选择项目</option>
                                                        <?php
                                                        // 预先加载对应项目的部门
                                                        $project_depts = array_filter($all_departments, function($dept) use ($assignment) {
                                                            return $dept['project_id'] == $assignment['project_id'];
                                                        });
                                                        foreach ($project_depts as $dept): ?>
                                                            <option value="<?php echo $dept['id']; ?>" 
                                                                <?php echo $dept['id'] == $assignment['department_id'] ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($dept['name']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label">职位</label>
                                                    <input type="text" class="form-control" name="position[]" 
                                                           value="<?php echo htmlspecialchars($assignment['position']); ?>" placeholder="职位">
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label">&nbsp;</label>
                                                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeAssignment(this)">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="project-assignment">
                                        <div class="row">
                                            <div class="col-md-4">
                                                <label class="form-label">项目 *</label>
                                                <select class="form-select project-select" name="project_id[]" onchange="loadDepartments(this)" required>
                                                    <option value="">选择项目</option>
                                                    <?php foreach ($all_projects as $project): ?>
                                                        <option value="<?php echo $project['id']; ?>"><?php echo htmlspecialchars($project['name']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">部门 *</label>
                                                <select class="form-select department-select" name="department_id[]" required>
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
                                <?php endif; ?>
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
        
        // 全选/取消全选人员
        function toggleAllPersonnel(selectAllCheckbox) {
            const checkboxes = document.querySelectorAll('.personnel-checkbox');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = selectAllCheckbox.checked;
            });
            // 更新按钮状态
            updateButtonState();
        }
        
        // 更新全选状态
        function updateSelectAllPersonnel() {
            const selectAllCheckbox = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.personnel-checkbox');
            const checkedBoxes = document.querySelectorAll('.personnel-checkbox:checked');
            
            if (selectAllCheckbox) {
                if (checkedBoxes.length === 0) {
                    // 没有选中的复选框
                    selectAllCheckbox.checked = false;
                    selectAllCheckbox.indeterminate = false;
                } else if (checkedBoxes.length === checkboxes.length) {
                    // 所有复选框都被选中
                    selectAllCheckbox.checked = true;
                    selectAllCheckbox.indeterminate = false;
                } else {
                    // 部分复选框被选中
                    selectAllCheckbox.checked = false;
                    selectAllCheckbox.indeterminate = true;
                }
            }
            // 更新按钮状态
            updateButtonState();
        }
        
        // 批量删除人员
        function batchDeletePersonnel() {
            const checkedBoxes = document.querySelectorAll('.personnel-checkbox:checked');
            
            if (checkedBoxes.length === 0) {
                alert('请先选择要删除的人员！');
                return;
            }
            
            const ids = Array.from(checkedBoxes).map(cb => cb.value);
            const names = Array.from(checkedBoxes).map(cb => {
                const row = cb.closest('tr');
                const nameCell = row.querySelector('td:nth-child(2) strong');
                return nameCell ? nameCell.textContent : '未知';
            });
            
            // 不论任何情况，都只删除人员在当前项目的分配
            // 只有当人员在其他项目中也没有分配时才删除该人员的基本信息
            let confirmMessage = "确定要删除以下 " + ids.length + " 个人员吗？\\n\\n" + names.join("\\n") + "\\n\\n";
            confirmMessage += "注意：这将删除他们在当前项目的分配，如果他们在其他项目中也没有分配，将同时删除人员基本信息。";
            
            if (confirm(confirmMessage)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="batch_delete">';
                
                ids.forEach(id => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'ids[]';
                    input.value = id;
                    form.appendChild(input);
                });
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // 全局数据
        const allProjects = <?php echo json_encode($all_projects); ?>;
        const allDepartments = <?php echo json_encode($all_departments); ?>;
        
        // 动态加载部门
        function loadDepartments(projectSelect) {
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
                    departmentSelect.appendChild(option);
                });
            }
        }
        
        // 添加新的项目分配
        function addAssignment() {
            const container = document.getElementById('projectAssignments');
            const newAssignment = document.createElement('div');
            newAssignment.className = 'project-assignment';
            newAssignment.innerHTML = '<div class="row">' +
                '<div class="col-md-4">' +
                    '<label class="form-label">项目</label>' +
                    '<select class="form-select project-select" name="project_id[]" onchange="loadDepartments(this)">' +
                        '<option value="">选择项目</option>' +
                        '<?php foreach ($all_projects as $project): ?>' +
                            '<option value="<?php echo $project["id"]; ?>"><?php echo htmlspecialchars($project["name"]); ?></option>' +
                        '<?php endforeach; ?>' +
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
                    '<input type="text" class="form-control" name="position[]" placeholder="职位">' +
                '</div>' +
                '<div class="col-md-1">' +
                    '<label class="form-label">&nbsp;</label>' +
                    '<button type="button" class="btn btn-outline-danger btn-sm" onclick="removeAssignment(this)">' +
                        '<i class="bi bi-trash"></i>' +
                    '</button>' +
                '</div>' +
            '</div>';
            container.appendChild(newAssignment);
            
            // 确保新添加的项目选择框可以正常工作
            const newProjectSelect = newAssignment.querySelector('.project-select');
            if (newProjectSelect) {
                newProjectSelect.addEventListener('change', function() {
                    loadDepartments(this);
                });
            }
        }
        
        // 移除项目分配
        function removeAssignment(button) {
            const assignment = button.closest('.project-assignment');
            assignment.remove();
        }
        
        // 编辑人员
        function editPerson(id) {
            // 使用AJAX加载编辑数据，避免页面重载
            const baseUrl = window.location.pathname;
            const url = `${baseUrl}?action=get_person&id=${id}`;
            
            fetch(url)
                .then(response => {
                    // 检查响应是否为JSON格式
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        throw new Error('服务器返回的不是JSON格式数据');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        // 填充表单数据
                        const modalElement = document.getElementById('addPersonModal');
                        if (!modalElement) {
                            console.error('找不到 addPersonModal 元素');
                            alert('页面元素加载错误，请刷新页面后重试');
                            return;
                        }
                        
                        const form = document.getElementById('personForm');
                        
                        // 设置隐藏字段 - 使用更安全的选择方式
                        const actionInput = form.querySelector('input[name="action"]');
                        const idInput = form.querySelector('input[name="id"]');
                        
                        if (actionInput) actionInput.value = 'edit';
                        if (idInput) idInput.value = data.person.id;
                        else {
                            // 如果id输入框不存在，创建一个
                            const newIdInput = document.createElement('input');
                            newIdInput.type = 'hidden';
                            newIdInput.name = 'id';
                            newIdInput.value = data.person.id;
                            form.appendChild(newIdInput);
                        }
                        
                        // 填充基本信息 - 添加null检查
                        const nameInput = form.querySelector('input[name="name"]');
                        const genderSelect = form.querySelector('select[name="gender"]');
                        const phoneInput = form.querySelector('input[name="phone"]');
                        const emailInput = form.querySelector('input[name="email"]');
                        const idCardInput = form.querySelector('input[name="id_card"]');
                        
                        if (nameInput && data.person.name !== undefined) nameInput.value = data.person.name;
                        if (genderSelect && data.person.gender !== undefined) genderSelect.value = data.person.gender;
                        if (phoneInput && data.person.phone !== undefined) phoneInput.value = data.person.phone;
                        if (emailInput && data.person.email !== undefined) emailInput.value = data.person.email;
                        if (idCardInput && data.person.id_card !== undefined) idCardInput.value = data.person.id_card;
                        
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
                        
                        // 确保Bootstrap已加载
                        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                            const modal = new bootstrap.Modal(modalElement);
                            modal.show();
                        } else {
                            console.error('Bootstrap Modal 未定义');
                            // 降级处理，使用jQuery或原生方式显示模态框
                            if (modalElement) {
                                modalElement.classList.add('show');
                                modalElement.style.display = 'block';
                                document.body.classList.add('modal-open');
                                // 添加遮罩层
                                const backdrop = document.createElement('div');
                                backdrop.className = 'modal-backdrop fade show';
                                document.body.appendChild(backdrop);
                            }
                        }
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
        
        // 查看人员详情
        function viewPersonDetails(personId) {
            currentPersonnelId = personId;
            
            // 检查模态框元素是否存在
            const modalElement = document.getElementById('personnelDetailModal');
            if (!modalElement) {
                console.error('找不到 personnelDetailModal 元素');
                alert('页面元素加载错误，请刷新页面后重试');
                return;
            }
            
            document.getElementById('personnelDetailsContent').innerHTML = `
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">加载中...</span>
                    </div>
                    <p class="mt-2">正在加载人员详情...</p>
                </div>
            `;
            
            // 使用共用的API文件
            const url = `api/personnel/get_personnel_details.php?id=${personId}`;
            
            fetch(url)
                .then(response => {
                    // 检查响应是否为JSON格式
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        throw new Error('服务器返回的不是JSON格式数据');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        displayPersonnelDetails(data);
                        // 确保Bootstrap已加载
                        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                            new bootstrap.Modal(modalElement).show();
                        } else {
                            console.error('Bootstrap Modal 未定义');
                            // 降级处理，使用jQuery或原性方式显示模态框
                            if (modalElement) {
                                modalElement.classList.add('show');
                                modalElement.style.display = 'block';
                                document.body.classList.add('modal-open');
                                // 添加遮罩层
                                const backdrop = document.createElement('div');
                                backdrop.className = 'modal-backdrop fade show';
                                document.body.appendChild(backdrop);
                            }
                        }
                    } else {
                        console.error('后端返回错误:', data.message);
                        document.getElementById('personnelDetailsContent').innerHTML = `
                            <div class="text-center py-5">
                                <i class="bi bi-exclamation-triangle text-danger" style="font-size: 3rem;"></i>
                                <h5 class="text-danger mt-3">加载失败</h5>
                                <p class="text-muted">${data.message || '获取数据失败'}</p>
                                <button class="btn btn-primary" onclick="viewPersonDetails(${personId})">
                                    <i class="bi bi-arrow-clockwise"></i> 重试
                                </button>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('网络请求错误:', error);
                    document.getElementById('personnelDetailsContent').innerHTML = `
                        <div class="text-center py-5">
                            <i class="bi bi-exclamation-triangle text-danger" style="font-size: 3rem;"></i>
                            <h5 class="text-danger mt-3">加载失败</h5>
                            <p class="text-muted">${error.message || '网络错误，请重试'}</p>
                            <button class="btn btn-primary" onclick="viewPersonDetails(${personId})">
                                <i class="bi bi-arrow-clockwise"></i> 重试
                            </button>
                        </div>
                    `;
                });
        }

        // 编辑当前查看的人员
        function editCurrentPerson() {
            if (currentPersonnelId) {
                const modalElement = document.getElementById('personnelDetailModal');
                if (modalElement) {
                    // 确保Bootstrap已加载
                    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                        bootstrap.Modal.getInstance(modalElement).hide();
                    } else {
                        // 降级处理，使用原生方式隐藏模态框
                        modalElement.classList.remove('show');
                        modalElement.style.display = 'none';
                        document.body.classList.remove('modal-open');
                        // 移除遮罩层
                        const backdrop = document.querySelector('.modal-backdrop');
                        if (backdrop) {
                            backdrop.remove();
                        }
                    }
                }
                editPerson(currentPersonnelId);
            }
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
                                    <div class="col-sm-8">${person.id_card}</div>
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
        }
        
        // 删除人员
        function deletePerson(id, name) {
            let confirmMessage = '确定要删除人员 "' + name + '" 吗？\n\n';
            confirmMessage += "注意：这将删除该人员在当前项目的分配，如果该人员在其他项目中没有分配，将同时删除人员基本信息。";
            
            if (confirm(confirmMessage)) {
                const form = document.createElement('form');
                form.method = 'POST';
                // 获取当前URL中的项目ID
                const urlParams = new URLSearchParams(window.location.search);
                const projectId = urlParams.get('project_id');
                
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="${id}">
                    ${projectId ? `<input type="hidden" name="project_id" value="${projectId}">` : ''}
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // 检查身份证号是否重复
        function checkIdCardDuplicate(idCard, callback) {
            // 获取当前正在编辑的人员ID（如果有的话）
            const form = document.getElementById('personForm');
            const idInput = form.querySelector('input[name="id"]');
            const currentPersonId = idInput ? parseInt(idInput.value) : 0;
            
            // 添加AJAX请求到服务器检查身份证号是否重复
            fetch('check_id_card.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    id_card: idCard,
                    current_person_id: currentPersonId  // 传递当前编辑的人员ID
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.exists) {
                    callback(true, data.person);
                } else {
                    callback(false, null);
                }
            })
            .catch(error => {
                console.error('检查身份证号失败:', error);
                callback(false, null);
            });
        }
        
        // 显示合并确认对话框
        function showMergeConfirmation(personInfo, form) {
            const modalElement = document.getElementById('mergeConfirmModal');
            if (!modalElement) {
                console.error('找不到 mergeConfirmModal 元素');
                alert('页面元素加载错误，请刷新页面后重试');
                return;
            }
            
            // 填充对话框内容
            document.getElementById('existingPersonName').textContent = personInfo.name;
            document.getElementById('existingPersonId').textContent = personInfo.id;
            
            // 设置合并按钮事件
            document.getElementById('confirmMergeBtn').onclick = function() {
                // 确保Bootstrap已加载
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    bootstrap.Modal.getInstance(modalElement).hide();
                } else {
                    // 降级处理，使用原生方式隐藏模态框
                    modalElement.classList.remove('show');
                    modalElement.style.display = 'none';
                    document.body.classList.remove('modal-open');
                    // 移除遮罩层
                    const backdrop = document.querySelector('.modal-backdrop');
                    if (backdrop) {
                        backdrop.remove();
                    }
                }
                submitFormWithMerge(form, personInfo.id);
            };
            
            // 设置取消按钮事件
            document.getElementById('cancelMergeBtn').onclick = function() {
                // 确保Bootstrap已加载
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    bootstrap.Modal.getInstance(modalElement).hide();
                } else {
                    // 降级处理，使用原生方式隐藏模态框
                    modalElement.classList.remove('show');
                    modalElement.style.display = 'none';
                    document.body.classList.remove('modal-open');
                    // 移除遮罩层
                    const backdrop = document.querySelector('.modal-backdrop');
                    if (backdrop) {
                        backdrop.remove();
                    }
                }
                // 用户选择不合并，正常提交表单
                submitForm(form);
            };
            
            // 确保Bootstrap已加载
            if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                const modal = new bootstrap.Modal(modalElement);
                modal.show();
            } else {
                console.error('Bootstrap Modal 未定义');
                // 降级处理，使用jQuery或原生方式显示模态框
                if (modalElement) {
                    modalElement.classList.add('show');
                    modalElement.style.display = 'block';
                    document.body.classList.add('modal-open');
                    // 添加遮罩层
                    const backdrop = document.createElement('div');
                    backdrop.className = 'modal-backdrop fade show';
                    document.body.appendChild(backdrop);
                }
            }
        }
        
        // 表单提交前验证
        document.getElementById('personForm').addEventListener('submit', function(e) {
            // 阻止默认提交行为
            e.preventDefault();
            
            
            // 获取表单元素
            const nameInput = this.querySelector('input[name="name"]');
            const phoneInput = this.querySelector('input[name="phone"]');
            const idCardInput = this.querySelector('input[name="id_card"]');
            const genderSelect = this.querySelector('select[name="gender"]');
            
            
            // 验证基本信息必填项
            if (!nameInput.value.trim()) {
                alert('请输入姓名');
                nameInput.focus();
                return false;
            }
            
            if (!genderSelect.value) {
                alert('请选择性别');
                genderSelect.focus();
                return false;
            }
            
            // 保存当前表单引用
            const currentForm = this;
            
            // 检查身份证号是否重复（仅在身份证号不为空时检查）
            if (idCardInput.value.trim()) {
                checkIdCardDuplicate(idCardInput.value.trim(), function(isDuplicate, personInfo) {
                    if (isDuplicate) {
                        // 显示合并确认对话框
                        showMergeConfirmation(personInfo, currentForm);
                    } else {
                        // 直接提交表单
                        submitForm(currentForm);
                    }
                });
            } else {
                // 身份证号为空，直接提交表单
                submitForm(currentForm);
            }
        });
        
        // 正常提交表单
        function submitForm(form) {
            
            // 收集项目分配数据
            const assignmentDivs = document.querySelectorAll('.project-assignment');
            let isValid = true;
            let firstInvalidElement = null;
            
            
            // 验证项目分配必填项
            if (assignmentDivs.length === 0) {
                alert('请至少添加一个项目分配');
                isValid = false;
            } else {
                assignmentDivs.forEach((div, index) => {
                    const projectSelect = div.querySelector('select[name="project_id[]"]');
                    const departmentSelect = div.querySelector('select[name="department_id[]"]');
                    
                    if (!projectSelect.value) {
                        if (!firstInvalidElement) firstInvalidElement = projectSelect;
                        alert('第' + (index + 1) + '行项目分配：请选择项目');
                        isValid = false;
                    } else if (!departmentSelect.value) {
                        if (!firstInvalidElement) firstInvalidElement = departmentSelect;
                        alert('第' + (index + 1) + '行项目分配：请选择部门');
                        isValid = false;
                    }
                });
            }
            
            if (!isValid) {
                if (firstInvalidElement) {
                    firstInvalidElement.focus();
                }
                return false;
            }
            
            // 收集项目分配数据
            const assignments = [];
            assignmentDivs.forEach(div => {
                const projectId = div.querySelector('select[name="project_id[]"]').value;
                const departmentId = div.querySelector('select[name="department_id[]"]').value;
                const position = div.querySelector('input[name="position[]"]').value;
                
                
                if (projectId && departmentId) {
                    assignments.push({
                        project_id: projectId,
                        department_id: departmentId,
                        position: position
                    });
                }
            });
            
            // 创建隐藏字段存储项目分配数据
            let hiddenInput = form.querySelector('input[name="project_assignments"]');
            if (!hiddenInput) {
                hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'project_assignments';
                form.appendChild(hiddenInput);
            }
            hiddenInput.value = JSON.stringify(assignments);
            
            
            // 提交表单
            try {
                form.submit();
            } catch (error) {
                console.error('表单提交失败:', error);
                alert('表单提交失败，请重试: ' + error.message);
            }
        }
        
        // 合并人员信息后提交表单
        function submitFormWithMerge(form, existingPersonId) {
            
            // 添加隐藏字段来标识要合并的人员ID
            let mergeInput = form.querySelector('input[name="merge_with_id"]');
            if (!mergeInput) {
                mergeInput = document.createElement('input');
                mergeInput.type = 'hidden';
                mergeInput.name = 'merge_with_id';
                mergeInput.value = existingPersonId;
                form.appendChild(mergeInput);
            } else {
                mergeInput.value = existingPersonId;
            }
            
            // 调用正常提交流程
            submitForm(form);
        }
        
        // 页面加载完成后初始化
            
            // 确保合并确认对话框的事件监听器正确设置
            const confirmMergeBtn = document.getElementById('confirmMergeBtn');
            const cancelMergeBtn = document.getElementById('cancelMergeBtn');
            
            if (confirmMergeBtn) {
                confirmMergeBtn.addEventListener('click', function() {
                    const modalElement = document.getElementById('mergeConfirmModal');
                    if (modalElement) {
                        // 确保Bootstrap已加载
                        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                            const modal = bootstrap.Modal.getInstance(modalElement);
                            if (modal) {
                                modal.hide();
                            }
                        } else {
                            // 降级处理，使用原生方式隐藏模态框
                            modalElement.classList.remove('show');
                            modalElement.style.display = 'none';
                            document.body.classList.remove('modal-open');
                            // 移除遮罩层
                            const backdrop = document.querySelector('.modal-backdrop');
                            if (backdrop) {
                                backdrop.remove();
                            }
                        }
                    }
                });
            }
            
            if (cancelMergeBtn) {
                cancelMergeBtn.addEventListener('click', function() {
                    const modalElement = document.getElementById('mergeConfirmModal');
                    if (modalElement) {
                        // 确保Bootstrap已加载
                        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                            const modal = bootstrap.Modal.getInstance(modalElement);
                            if (modal) {
                                modal.hide();
                            }
                        } else {
                            // 降级处理，使用原生方式隐藏模态框
                            modalElement.classList.remove('show');
                            modalElement.style.display = 'none';
                            document.body.classList.remove('modal-open');
                            // 移除遮罩层
                            const backdrop = document.querySelector('.modal-backdrop');
                            if (backdrop) {
                                backdrop.remove();
                            }
                        }
                    }
                });
            }
            
            // 获取必要的元素
            const selectAllCheckbox = document.getElementById('selectAll');
            const personnelCheckboxes = document.querySelectorAll('.personnel-checkbox');
            const clearSelectionBtn = document.getElementById('clearSelectionBtn');
            const batchDepartmentForm = document.getElementById('batchDepartmentForm');
            const batchDepartmentSelect = document.getElementById('batchDepartment');
            const batchGenderForm = document.getElementById('batchGenderForm');
            const batchGenderSelect = document.getElementById('batchGender');
            
            // 获取按钮元素
            const batchUpdateDepartmentBtn = document.getElementById('batchUpdateDepartmentBtn');
            const batchUpdateGenderBtn = document.getElementById('batchUpdateGenderBtn');
            
            // 全选/取消全选
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    personnelCheckboxes.forEach(checkbox => {
                        checkbox.checked = selectAllCheckbox.checked;
                    });
                    // 更新按钮状态
                    updateButtonState();
                });
            }
            
            // 单个复选框变化时更新全选状态和按钮状态
            personnelCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    updateSelectAllState();
                    updateButtonState();
                });
            });
            
            // 取消选择按钮
            if (clearSelectionBtn) {
                clearSelectionBtn.addEventListener('click', function() {
                    personnelCheckboxes.forEach(checkbox => {
                        checkbox.checked = false;
                    });
                    if (selectAllCheckbox) {
                        selectAllCheckbox.checked = false;
                        selectAllCheckbox.indeterminate = false;
                    }
                    updateButtonState();
                });
            }
            
            // 更新全选复选框状态
            function updateSelectAllState() {
                const checkedCount = document.querySelectorAll('.personnel-checkbox:checked').length;
                const totalCount = personnelCheckboxes.length;
                
                if (selectAllCheckbox) {
                    if (checkedCount === 0) {
                        selectAllCheckbox.checked = false;
                        selectAllCheckbox.indeterminate = false;
                    } else if (checkedCount === totalCount && totalCount > 0) {
                        selectAllCheckbox.checked = true;
                        selectAllCheckbox.indeterminate = false;
                    } else {
                        selectAllCheckbox.checked = false;
                        selectAllCheckbox.indeterminate = true;
                    }
                }
            }
            
            // 更新按钮状态
            function updateButtonState() {
                const checkedCount = document.querySelectorAll('.personnel-checkbox:checked').length;
                
                if (batchUpdateDepartmentBtn && batchUpdateGenderBtn && clearSelectionBtn) {
                    if (checkedCount > 0) {
                        batchUpdateDepartmentBtn.disabled = false;
                        batchUpdateGenderBtn.disabled = false;
                        clearSelectionBtn.style.display = 'inline-block';
                        batchUpdateDepartmentBtn.innerHTML = `<i class="bi bi-building"></i> 修改部门 (${checkedCount})`;
                        batchUpdateGenderBtn.innerHTML = `<i class="bi bi-gender-ambiguous"></i> 修改性别 (${checkedCount})`;
                    } else {
                        batchUpdateDepartmentBtn.disabled = true;
                        batchUpdateGenderBtn.disabled = true;
                        clearSelectionBtn.style.display = 'none';
                        batchUpdateDepartmentBtn.innerHTML = '<i class="bi bi-building"></i> 修改部门';
                        batchUpdateGenderBtn.innerHTML = '<i class="bi bi-gender-ambiguous"></i> 修改性别';
                    }
                }
            }
            
            // 批量修改部门表单验证
            if (batchDepartmentForm) {
                batchDepartmentForm.addEventListener('submit', function(e) {
                    const checkedCheckboxes = document.querySelectorAll('.personnel-checkbox:checked');
                    const checkedCount = checkedCheckboxes.length;
                    
                    if (checkedCount === 0) {
                        e.preventDefault();
                        alert('批量修改部门：请先勾选要修改的人员左侧复选框');
                        return;
                    }
                    
                    if (!batchDepartmentSelect.value) {
                        e.preventDefault();
                        alert('请选择要修改到的部门');
                        return;
                    }
                    
                    const departmentName = batchDepartmentSelect.options[batchDepartmentSelect.selectedIndex].text;
                    const personnelNames = [];
                    
                    // 清空之前的隐藏输入
                    const departmentInputs = document.getElementById('departmentPersonnelInputs');
                    if (departmentInputs) {
                        departmentInputs.innerHTML = '';
                    }
                    
                    // 添加新的隐藏输入
                    checkedCheckboxes.forEach(checkbox => {
                        const row = checkbox.closest('tr');
                        const name = row.cells[1].textContent; // 姓名在第二列
                        personnelNames.push(name);
                        
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'selected_personnel[]';
                        input.value = checkbox.value;
                        if (departmentInputs) {
                            departmentInputs.appendChild(input);
                        }
                    });
                    
                    const confirmMessage = `⚠️ 批量修改部门确认

您即将修改 ${checkedCount} 名人员的部门：

${personnelNames.map(name => `• ${name}`).join('\n')}

修改到部门：${departmentName}

⚠️ 警告：此操作将覆盖现有部门分配！

是否确认执行？`;
                    
                    if (!confirm(confirmMessage)) {
                        e.preventDefault();
                    }
                });
            }
            
            // 批量修改性别表单验证
            if (batchGenderForm) {
                batchGenderForm.addEventListener('submit', function(e) {
                    const checkedCheckboxes = document.querySelectorAll('.personnel-checkbox:checked');
                    const checkedCount = checkedCheckboxes.length;
                    
                    if (checkedCount === 0) {
                        e.preventDefault();
                        alert('批量修改性别：请先勾选要修改的人员左侧复选框');
                        return;
                    }
                    
                    if (!batchGenderSelect.value) {
                        e.preventDefault();
                        alert('请选择要修改到的性别');
                        return;
                    }
                    
                    const genderName = batchGenderSelect.options[batchGenderSelect.selectedIndex].text;
                    const personnelNames = [];
                    
                    // 清空之前的隐藏输入
                    const genderInputs = document.getElementById('genderPersonnelInputs');
                    if (genderInputs) {
                        genderInputs.innerHTML = '';
                    }
                    
                    // 添加新的隐藏输入
                    checkedCheckboxes.forEach(checkbox => {
                        const row = checkbox.closest('tr');
                        const name = row.cells[1].textContent; // 姓名在第二列
                        personnelNames.push(name);
                        
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'selected_personnel[]';
                        input.value = checkbox.value;
                        if (genderInputs) {
                            genderInputs.appendChild(input);
                        }
                    });
                    
                    const confirmMessage = `⚠️ 批量修改性别确认

您即将修改 ${checkedCount} 名人员的性别：

${personnelNames.map(name => `• ${name}`).join('\n')}

修改到性别：${genderName}

⚠️ 警告：此操作将覆盖现有性别信息！

是否确认执行？`;
                    
                    if (!confirm(confirmMessage)) {
                        e.preventDefault();
                    }
                });
            }
            
            // 初始化按钮状态
            updateButtonState();
    </script>

    <script>
        // 页面加载完成后初始化
        document.addEventListener('DOMContentLoaded', function() {
            // 初始化按钮状态
            updateButtonState();
            
            // 添加项目账户详情的点击事件处理
            const projectAccessContainers = document.querySelectorAll('.project-access-container');
            projectAccessContainers.forEach(container => {
                const badge = container.querySelector('.badge');
                if (badge) {
                    badge.addEventListener('click', function(e) {
                        e.stopPropagation();
                        container.classList.toggle('active');
                    });
                }
            });
            
            // 点击页面其他地方时隐藏项目详情
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.project-access-container')) {
                    projectAccessContainers.forEach(container => {
                        container.classList.remove('active');
                    });
                }
            });
            
            // 调试信息
            console.log('viewPersonDetails function:', typeof viewPersonDetails);
            console.log('editPerson function:', typeof editPerson);
            console.log('deletePerson function:', typeof deletePerson);
        });
    </script>

    <?php require_once 'includes/footer.php'; ?>
