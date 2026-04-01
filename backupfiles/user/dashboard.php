<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/site_config.php';

// 如果用户未登录，重定向到登录页面
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// 统一用户信息获取 - 从数据库获取真实用户名
$user_id = $_SESSION['user_id'];
$user_name = '未知用户'; // 默认值
$username = '未知用户'; // 默认值

// 从数据库中获取真实的用户信息 - 修复使用不存在的users表的问题
try {
    $database = new Database();
    $db = $database->getConnection();
    
    if ($db) {
        // 从正确的project_users表查询用户信息
        $query = "SELECT username, display_name FROM project_users WHERE id = :user_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user_data) {
            $user_name = $user_data['display_name'] ?? $user_data['username'] ?? '未知用户';
            $username = $user_data['username'] ?? '未知用户';
        }
    }
} catch (PDOException $e) {
    // 数据库错误处理
    $error_message = "数据库错误: " . $e->getMessage();
}

// 获取网站配置
$site_config = new SiteConfig();
$site_info = $site_config->getSiteInfo();

// 统一项目信息获取 - 修复项目信息显示混乱
$project_id = $_SESSION['project_id'] ?? null;
$project_info = [
    'id' => $project_id,
    'name' => '未分配项目',
    'code' => '',
    'location' => '',
    'start_date' => '',
    'end_date' => '',
    'description' => '',
    'hotel_name' => '',
    'hotel_province' => '',
    'hotel_city' => '',
    'total_personnel' => 0
];

// 初始化统计数据
$stats = [
    'total_personnel' => 0,
    'today_meals' => 0,
    'pending_hotels' => 0,
    'pending_transport' => 0
];

try {
    if ($db && $project_id) {
        // 获取完整的项目详细信息 - 支持多酒店功能
        // 检查project_hotels表是否存在
        $check_table_query = "SHOW TABLES LIKE 'project_hotels'";
        $check_stmt = $db->prepare($check_table_query);
        $check_stmt->execute();
        $table_exists = ($check_stmt->rowCount() > 0);
        
        if ($table_exists) {
            // 使用新的多酒店关联模式获取项目信息，包含接送机/站交通地点
            $query = "SELECT p.*, 
                      GROUP_CONCAT(DISTINCT h.hotel_name_cn ORDER BY h.hotel_name_cn SEPARATOR ', ') as hotel_names,
                      GROUP_CONCAT(DISTINCT CONCAT(h.province, '省 ', h.city, '市') ORDER BY h.hotel_name_cn SEPARATOR '; ') as hotel_locations,
                      p.arrival_airport, p.arrival_railway_station, p.departure_airport, p.departure_railway_station
                      FROM projects p 
                      LEFT JOIN project_hotels ph ON p.id = ph.project_id
                      LEFT JOIN hotels h ON ph.hotel_id = h.id 
                      WHERE p.id = :project_id
                      GROUP BY p.id";
        } else {
            // 使用旧的项目单酒店模式（向后兼容），包含接送机/站交通地点
            $query = "SELECT p.*, h.hotel_name_cn, h.hotel_name_en, h.province as hotel_province, h.city as hotel_city, h.address as hotel_address,
                      p.arrival_airport, p.arrival_railway_station, p.departure_airport, p.departure_railway_station
                      FROM projects p 
                      LEFT JOIN hotels h ON p.hotel_id = h.id 
                      WHERE p.id = :project_id";
        }
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':project_id', $project_id);
        $stmt->execute();
        $project_details = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($project_details) {
            // 统一项目信息
            $project_info = array_merge($project_info, $project_details);
            $project_info['name'] = $project_details['name'] ?? $_SESSION['project_name'] ?? '未命名项目';
            $project_info['code'] = $project_details['code'] ?? $_SESSION['project_code'] ?? '';
            
            // 处理酒店信息 - 支持多酒店
            if ($table_exists) {
                $project_info['hotel_name'] = $project_details['hotel_names'] ?? '';
                $project_info['hotel_locations'] = $project_details['hotel_locations'] ?? '';
            } else {
                // 旧模式单酒店
                $project_info['hotel_name'] = $project_details['hotel_name_cn'] ?? $project_details['hotel_name_en'] ?? '';
                $project_info['hotel_province'] = $project_details['hotel_province'] ?? '';
                $project_info['hotel_city'] = $project_details['hotel_city'] ?? '';
            }
        }
        
        // 获取项目人员数量 - 使用与 personnel.php 一致的 project_department_personnel 表
        $query = "SELECT COUNT(DISTINCT p.id) as total 
                  FROM personnel p 
                  INNER JOIN project_department_personnel pdp ON p.id = pdp.personnel_id
                  WHERE pdp.project_id = :project_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':project_id', $project_id);
        $stmt->execute();
        $project_info['total_personnel'] = $stmt->fetchColumn() ?? 0;
        $stats['total_personnel'] = $project_info['total_personnel'];
        
        // 获取总报餐数
        $query = "SELECT COUNT(*) FROM meal_reports mr 
                  JOIN personnel p ON mr.personnel_id = p.id 
                  JOIN project_department_personnel pdp ON p.id = pdp.personnel_id 
                  WHERE pdp.project_id = :project_id AND mr.project_id = :project_id2";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':project_id', $project_id);
        $stmt->bindParam(':project_id2', $project_id);
        $stmt->execute();
        $stats['total_meals'] = $stmt->fetchColumn();

        // 获取总酒店房晚数（与hotel_statistics.php保持一致的计算逻辑）
        $query = "SELECT COALESCE(SUM(DATEDIFF(hr.check_out_date, hr.check_in_date) * 
            CASE 
                WHEN hr.room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND hr.shared_room_info IS NOT NULL AND hr.shared_room_info != '' THEN 1
                ELSE hr.room_count
            END
        ), 0) as total_room_nights
        FROM (
            SELECT 
                MIN(id) as id,
                room_type,
                room_count,
                shared_room_info,
                check_in_date,
                check_out_date
            FROM hotel_reports 
            WHERE project_id = :project_id
            GROUP BY 
                room_type,
                room_count,
                shared_room_info,
                check_in_date,
                check_out_date,
                CASE 
                    WHEN room_type IN ('双床房', '套房', '大床房', '总统套房', '副总统套房') AND shared_room_info IS NOT NULL AND shared_room_info != '' THEN shared_room_info
                    ELSE CONCAT(id, '-', room_type, '-', room_count)
                END
        ) as grouped_rooms
        JOIN hotel_reports hr ON grouped_rooms.id = hr.id
        JOIN personnel p ON hr.personnel_id = p.id 
        JOIN project_department_personnel pdp ON p.id = pdp.personnel_id 
        WHERE pdp.project_id = :project_id2";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':project_id', $project_id);
        $stmt->bindParam(':project_id2', $project_id);
        $stmt->execute();
        $stats['total_room_nights'] = $stmt->fetchColumn();

        // 获取交通行程总数
        $query = "SELECT COUNT(*) FROM transportation_reports tr 
                  JOIN personnel p ON tr.personnel_id = p.id 
                  JOIN project_department_personnel pdp ON p.id = pdp.personnel_id 
                  WHERE pdp.project_id = :project_id AND tr.project_id = :project_id2";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':project_id', $project_id);
        $stmt->bindParam(':project_id2', $project_id);
        $stmt->execute();
        $stats['total_transport'] = $stmt->fetchColumn();
        
        // 获取最新记录
        $recent_data = getRecentData($db, $project_id);
    }
    
} catch (PDOException $e) {
    $error_message = "数据库错误: " . $e->getMessage();
}

// 获取最新记录的函数
function getRecentData($db, $project_id) {
    $today = date('Y-m-d');
    
    // 最新报餐记录（不限于今日）
    $query = "SELECT mr.*, p.name as personnel_name 
              FROM meal_reports mr 
              JOIN personnel p ON mr.personnel_id = p.id 
              JOIN project_department_personnel pdp ON p.id = pdp.personnel_id 
              WHERE pdp.project_id = :project_id AND mr.project_id = :project_id2
              ORDER BY mr.meal_date DESC, mr.created_at DESC LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->execute([':project_id' => $project_id, ':project_id2' => $project_id]);
    $recent_meals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 最新酒店预订
    $query = "SELECT hr.*, p.name as personnel_name 
              FROM hotel_reports hr 
              JOIN personnel p ON hr.personnel_id = p.id 
              JOIN project_department_personnel pdp ON p.id = pdp.personnel_id 
              WHERE pdp.project_id = :project_id AND hr.project_id = :project_id2
              ORDER BY hr.created_at DESC LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->execute([':project_id' => $project_id, ':project_id2' => $project_id]);
    $recent_hotels = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 最新交通预订
    $query = "SELECT tr.*, p.name as personnel_name 
              FROM transportation_reports tr 
              JOIN personnel p ON tr.personnel_id = p.id 
              JOIN project_department_personnel pdp ON p.id = pdp.personnel_id 
              WHERE pdp.project_id = :project_id AND tr.project_id = :project_id2
              ORDER BY tr.created_at DESC LIMIT 5";
    $stmt = $db->prepare($query);
    $stmt->execute([':project_id' => $project_id, ':project_id2' => $project_id]);
    $recent_transport = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'meals' => $recent_meals,
        'hotels' => $recent_hotels,
        'transport' => $recent_transport
    ];
}

// 设置页面变量
$page_title = $project_id ? 
    htmlspecialchars($project_info['name']) . ' - ' . htmlspecialchars($site_info['site_name']) : 
    '工作台 - ' . htmlspecialchars($site_info['site_name']);
    
$active_page = 'dashboard';
$show_page_title = '工作台';
$page_icon = 'speedometer2';

// 包含统一头部文件
include 'includes/header.php';
?>

    <div class="container mt-4">
        <!-- 用户信息卡片 - 修复用户信息显示混乱 -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card bg-light">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <!-- 修复h2标签：统一使用$user_name -->
                                <h2 class="mb-1">欢迎回来，<?php echo htmlspecialchars($user_name); ?></h2>
                                <?php if ($project_id): ?>
                                    <!-- 修复h4标签：统一使用$project_info数组 -->
                                    <h4 class="text-primary mb-0">
                                        <i class="bi bi-building"></i> 
                                        <?php echo htmlspecialchars($project_info['name']); ?>
                                        <?php if (!empty($project_info['code'])): ?>
                                        <small class="text-muted">(<?php echo htmlspecialchars($project_info['code']); ?>)</small>
                                        <?php endif; ?>
                                    </h4>
                                <?php else: ?>
                                    <h4 class="text-muted mb-0">系统管理员</h4>
                                <?php endif; ?>
                            </div>
                            <div class="text-end">
                                <span class="text-muted">今天是 <?php echo date('Y年m月d日'); ?></span><br>
                                <small class="text-muted">登录用户: <?php echo htmlspecialchars($username); ?></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 项目详细信息卡片 - 修复项目信息不一致 -->
        <?php if ($project_id): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-primary">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-info-circle"></i> 项目详细信息</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <h6><i class="bi bi-geo-alt"></i> 项目基本信息</h6>
                                <table class="table table-sm">
                                    <tr>
                                        <td width="30%"><strong>项目名称：</strong></td>
                                        <td><?php echo htmlspecialchars($project_info['name']); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>项目代码：</strong></td>
                                        <td><?php echo htmlspecialchars($project_info['code']); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>场地位置：</strong></td>
                                        <td><?php echo htmlspecialchars($project_info['location'] ?: '未设置'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>开始日期：</strong></td>
                                        <td><?php echo $project_info['start_date'] ? date('Y年m月d日', strtotime($project_info['start_date'])) : '未设置'; ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>结束日期：</strong></td>
                                        <td><?php echo $project_info['end_date'] ? date('Y年m月d日', strtotime($project_info['end_date'])) : '未设置'; ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-4">
                                <h6><i class="bi bi-building"></i> 酒店信息</h6>
                                <?php if ($project_info['hotel_name']): ?>
                                <table class="table table-sm">
                                    <tr>
                                        <td><strong>酒店名称：</strong></td>
                                        <td><?php echo htmlspecialchars($project_info['hotel_name'] ?: '未指定'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><strong>酒店位置：</strong></td>
                                        <td>
                                            <?php 
                                            // 支持多酒店位置显示 - 兼容新旧模式
                                            $location = '';
                                            if (!empty($project_info['hotel_locations'])) {
                                                // 新模式：多酒店位置
                                                $location = $project_info['hotel_locations'];
                                            } else {
                                                // 旧模式：单酒店位置
                                                if (!empty($project_info['hotel_province'])) {
                                                    $location .= $project_info['hotel_province'] . ' ';
                                                }
                                                if (!empty($project_info['hotel_city'])) {
                                                    $location .= $project_info['hotel_city'] . '';
                                                }
                                                if (!empty($project_info['hotel_address'])) {
                                                    $location .= ' ' . $project_info['hotel_address'];
                                                }
                                            }
                                            echo htmlspecialchars($location ?: '未指定');
                                            ?>
                                        </td>
                                    </tr>
                                </table>
                                <?php else: ?>
                                <p class="text-muted"><i class="bi bi-exclamation-triangle"></i> 暂未指定酒店</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- 接送机/站交通地点 - 调整至酒店信息下方 -->
                        <div class="row mt-3">
                            <div class="col-12">
                                <h6><i class="bi bi-airplane"></i> 接送机/站交通地点</h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-sm">
                                            <?php if (!empty($project_info['arrival_airport']) && $project_info['arrival_airport'] !== '未设置'): ?>
                                            <tr>
                                                <td width="25%"><strong>机场/高铁站：</strong></td>
                                                <td><?php echo htmlspecialchars($project_info['arrival_airport']); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php if (!empty($project_info['arrival_railway_station']) && $project_info['arrival_railway_station'] !== '未设置'): ?>
                                            <tr>
                                                <td><strong>机场/高铁站：</strong></td>
                                                <td><?php echo htmlspecialchars($project_info['arrival_railway_station']); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-sm">
                                            <?php if (!empty($project_info['departure_airport']) && $project_info['departure_airport'] !== '未设置'): ?>
                                            <tr>
                                                <td width="25%"><strong>机场/高铁站：</strong></td>
                                                <td><?php echo htmlspecialchars($project_info['departure_airport']); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php if (!empty($project_info['departure_railway_station']) && $project_info['departure_railway_station'] !== '未设置'): ?>
                                            <tr>
                                                <td><strong>机场/高铁站：</strong></td>
                                                <td><?php echo htmlspecialchars($project_info['departure_railway_station']); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- 人员统计 - 与交通地点并列显示 -->
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <h6><i class="bi bi-people"></i> 人员统计</h6>
                                <div class="card bg-light">
                                    <div class="card-body py-2">
                                        <p class="mb-2">
                                            <strong>项目总人数：</strong> 
                                            <span class="badge bg-primary fs-6"><?php echo $project_info['total_personnel']; ?>人</span>
                                        </p>
                                        <p class="mb-0">
                                            <strong>今日报餐人数：</strong> 
                                            <span class="badge bg-success fs-6"><?php echo $stats['today_meals']; ?>人</span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php if (!empty($project_info['description'])): ?>
                        <div class="row mt-3">
                            <div class="col-12">
                                <h6><i class="bi bi-file-text"></i> 项目描述</h6>
                                <p class="text-muted"><?php echo htmlspecialchars($project_info['description']); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 统计卡片 - 修复显示一致性 -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-center border-primary">
                    <div class="card-header bg-primary text-white">
                        <h5 class="card-title mb-0">项目人员</h5>
                    </div>
                    <div class="card-body">
                        <h2 class="text-primary"><?php echo $project_info['total_personnel']; ?></h2>
                        <small class="text-muted">总人数</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-success">
                    <div class="card-header bg-success text-white">
                        <h5 class="card-title mb-0">总报餐数</h5>
                    </div>
                    <div class="card-body">
                        <h2 class="text-success"><?php echo $stats['total_meals'] ?? 0; ?></h2>
                        <small class="text-muted">总记录数</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-warning">
                    <div class="card-header bg-warning text-white">
                        <h5 class="card-title mb-0">总酒店房晚数</h5>
                    </div>
                    <div class="card-body">
                        <h2 class="text-warning"><?php echo $stats['total_room_nights']; ?></h2>
                        <small class="text-muted">房晚数</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-info">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title mb-0">交通行程总数</h5>
                    </div>
                    <div class="card-body">
                        <h2 class="text-info"><?php echo $stats['total_transport']; ?></h2>
                        <small class="text-muted">总行程数</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- 最新记录 -->
        <div class="row">
            <?php if ($project_id): ?>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-cup-hot"></i> 最新报餐</h6>
                    </div>
                    <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                        <?php 
                        if (isset($recent_data) && !empty($recent_data['meals'])): 
                            echo '<ul class="list-unstyled">';
                            foreach ($recent_data['meals'] as $meal) {
                                echo '<li class="mb-3 pb-2 border-bottom">';
                                echo '<div class="d-flex justify-content-between align-items-start">';
                                echo '<div>';
                                echo '<strong class="d-block">' . htmlspecialchars($meal['personnel_name']) . '</strong>';
                                echo '<small class="text-muted d-block mt-1">';
                                echo '<i class="bi bi-calendar3"></i> ' . date('m月d日', strtotime($meal['meal_date'])) . ' ';
                                echo '<i class="bi bi-egg-fried ms-2"></i> ' . htmlspecialchars($meal['meal_type']);
                                echo '</small>';
                                echo '</div>';
                                echo '<span class="badge bg-success">' . $meal['meal_count'] . '人</span>';
                                echo '</div>';
                                echo '</li>';
                            }
                            echo '</ul>';
                        else: 
                            echo '<p class="text-muted text-center py-4"><i class="bi bi-info-circle"></i> 暂无报餐记录</p>';
                        endif; 
                        ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-building"></i> 最新酒店预订</h6>
                    </div>
                    <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                        <?php 
                        if (isset($recent_data) && !empty($recent_data['hotels'])): 
                            echo '<ul class="list-unstyled">';
                            foreach ($recent_data['hotels'] as $hotel) {
                                echo '<li class="mb-2">';
                                echo '<strong>' . htmlspecialchars($hotel['personnel_name']) . '</strong><br>';
                                echo '<small class="text-muted">';
                                echo htmlspecialchars($hotel['check_in_date']) . ' 至 ';
                                echo htmlspecialchars($hotel['check_out_date']);
                                echo '</small>';
                                echo '</li>';
                            }
                            echo '</ul>';
                        else: 
                            echo '<p class="text-muted"><i class="bi bi-info-circle"></i> 暂无酒店预订记录</p>';
                        endif; 
                        ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="bi bi-truck"></i> 最新交通预订</h6>
                    </div>
                    <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                        <?php 
                        if (isset($recent_data) && !empty($recent_data['transport'])): 
                            echo '<ul class="list-unstyled">';
                            foreach ($recent_data['transport'] as $transport) {
                                echo '<li class="mb-2">';
                                echo '<strong>' . htmlspecialchars($transport['personnel_name']) . '</strong><br>';
                                echo '<small class="text-muted">';
                                echo htmlspecialchars($transport['travel_type']) . ' - ';
                                echo htmlspecialchars($transport['travel_date']);
                                echo '</small>';
                                echo '</li>';
                            }
                            echo '</ul>';
                        else: 
                            echo '<p class="text-muted"><i class="bi bi-info-circle"></i> 暂无交通预订记录</p>';
                        endif; 
                        ?>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="bi bi-info-circle"></i> 系统提示</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-0">
                            <i class="bi bi-exclamation-triangle"></i> 
                            您当前未分配到任何项目。请联系管理员为您分配项目权限。
                        </p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include 'includes/footer.php'; ?>
</body>
</html>