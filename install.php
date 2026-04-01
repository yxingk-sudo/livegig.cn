<?php
require_once 'config/database.php';

// 创建数据库表
function createDatabaseAndTables($db) {
    try {
        // 创建数据库（如果不存在）
        $db->exec("CREATE DATABASE IF NOT EXISTS team_reception CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        // 选择数据库
        $db->exec("USE team_reception");
        
        // 创建表的SQL语句
        $sql = "
        -- 公司表
        CREATE TABLE IF NOT EXISTS companies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            contact_person VARCHAR(100),
            phone VARCHAR(50),
            email VARCHAR(255),
            address TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 项目表
        CREATE TABLE IF NOT EXISTS projects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            code VARCHAR(50) UNIQUE NOT NULL,
            description TEXT,
            start_date DATE,
            end_date DATE,
            status ENUM('active', 'inactive', 'completed') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
        );

        -- 项目用户表
        CREATE TABLE IF NOT EXISTS project_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            username VARCHAR(100) NOT NULL,
            password VARCHAR(255) NOT NULL,
            display_name VARCHAR(100),
            role ENUM('admin', 'user') DEFAULT 'user',
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            UNIQUE KEY unique_project_user (project_id, username)
        );

        -- 部门表
        CREATE TABLE IF NOT EXISTS departments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        );

        -- 人员表
        CREATE TABLE IF NOT EXISTS personnel (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            phone VARCHAR(50),
            email VARCHAR(255),
            id_card VARCHAR(50),
            gender ENUM('男', '女', '其他'),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- 项目部门人员关联表
        CREATE TABLE IF NOT EXISTS project_department_personnel (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            department_id INT NOT NULL,
            personnel_id INT NOT NULL,
            position VARCHAR(100),
            join_date DATE,
            status ENUM('active', 'inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
            FOREIGN KEY (personnel_id) REFERENCES personnel(id) ON DELETE CASCADE,
            UNIQUE KEY unique_project_dept_person (project_id, department_id, personnel_id)
        );

        -- 报餐记录表
        CREATE TABLE IF NOT EXISTS meal_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            personnel_id INT NOT NULL,
            meal_date DATE NOT NULL,
            meal_type ENUM('早餐', '午餐', '晚餐') NOT NULL,
            meal_count INT DEFAULT 1,
            special_requirements TEXT,
            status ENUM('pending', 'confirmed', 'cancelled') DEFAULT 'pending',
            reported_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (personnel_id) REFERENCES personnel(id) ON DELETE CASCADE,
            FOREIGN KEY (reported_by) REFERENCES project_users(id) ON DELETE CASCADE
        );

        -- 酒店预订表
        CREATE TABLE IF NOT EXISTS hotel_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            personnel_id INT NOT NULL,
            check_in_date DATE NOT NULL,
            check_out_date DATE NOT NULL,
            hotel_name VARCHAR(255),
            room_type VARCHAR(100),
            room_count INT DEFAULT 1,
            special_requirements TEXT,
            status ENUM('pending', 'confirmed', 'cancelled') DEFAULT 'pending',
            reported_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (personnel_id) REFERENCES personnel(id) ON DELETE CASCADE,
            FOREIGN KEY (reported_by) REFERENCES project_users(id) ON DELETE CASCADE
        );

        -- 出行车预订表
        CREATE TABLE IF NOT EXISTS transportation_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            personnel_id INT NOT NULL,
            travel_date DATE NOT NULL,
            travel_type ENUM('接站', '送站', '混合交通安排') NOT NULL,
            departure_location VARCHAR(255),
            destination_location VARCHAR(255),
            departure_time TIME,
            vehicle_type VARCHAR(100),
            passenger_count INT DEFAULT 1,
            contact_phone VARCHAR(50),
            special_requirements TEXT,
            status ENUM('pending', 'confirmed', 'cancelled') DEFAULT 'pending',
            reported_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
            FOREIGN KEY (personnel_id) REFERENCES personnel(id) ON DELETE CASCADE,
            FOREIGN KEY (reported_by) REFERENCES project_users(id) ON DELETE CASCADE
        );

        -- 插入示例数据
        INSERT INTO companies (name, contact_person, phone, email, address) VALUES
        ('阿里巴巴', '张三', '13800138001', 'zhangsan@alibaba.com', '杭州市西湖区'),
        ('腾讯', '李四', '13800138002', 'lisi@tencent.com', '深圳市南山区'),
        ('百度', '王五', '13800138003', 'wangwu@baidu.com', '北京市海淀区');

        INSERT INTO projects (company_id, name, code, description, start_date, end_date) VALUES
        (1, '阿里巴巴技术大会', 'ALI20241201000001', '2024年度技术大会接待', '2024-12-15', '2024-12-20'),
        (2, '腾讯产品发布会', 'TEN20241201000002', '新产品发布会接待安排', '2024-12-10', '2024-12-15'),
        (3, '百度AI峰会', 'BAI20241201000003', '人工智能峰会接待', '2024-12-20', '2024-12-25');

        INSERT INTO project_users (project_id, username, password, display_name, role) VALUES
        (1, 'admin1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '管理员1', 'admin'),
        (1, 'user1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '用户1', 'user'),
        (2, 'admin2', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '管理员2', 'admin'),
        (3, 'admin3', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '管理员3', 'admin');

        INSERT INTO departments (project_id, name, description) VALUES
        (1, '技术部', '技术团队'),
        (1, '市场部', '市场团队'),
        (2, '产品部', '产品团队'),
        (2, '运营部', '运营团队'),
        (3, '研发部', '研发团队'),
        (3, '销售部', '销售团队');

        INSERT INTO personnel (name, phone, email, id_card, gender) VALUES
        ('张三', '13900139001', 'zhangsan@example.com', '110101199001011234', '男'),
        ('李四', '13900139002', 'lisi@example.com', '110101199001022345', '女'),
        ('王五', '13900139003', 'wangwu@example.com', '110101199001033456', '男'),
        ('赵六', '13900139004', 'zhaoliu@example.com', '110101199001044567', '女'),
        ('孙七', '13900139005', 'sunqi@example.com', '110101199001055678', '男');

        INSERT INTO project_department_personnel (project_id, department_id, personnel_id, position) VALUES
        (1, 1, 1, '技术总监'),
        (1, 1, 2, '高级工程师'),
        (1, 2, 3, '市场经理'),
        (2, 3, 4, '产品经理'),
        (2, 4, 5, '运营专员'),
        (3, 5, 1, '研发经理'),
        (3, 6, 2, '销售总监');
        ";
        
        $db->exec($sql);
        
        return true;
    } catch(PDOException $e) {
        return false;
    }
}

// 检查是否已安装
try {
    $database = new Database();
    $db = $database->getConnection();
    
    // 检查表是否存在
    $stmt = $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'team_reception' AND table_name = 'companies'");
    $tableExists = $stmt->fetchColumn() > 0;
    
    if ($tableExists) {
        $message = "系统已经安装完成！";
        $installed = true;
    } else {
        // 执行安装
        if (isset($_POST['install'])) {
            if (createDatabaseAndTables($db)) {
                $message = "安装成功！数据库和表已创建完成。";
                $installed = true;
            } else {
                $message = "安装失败！请检查数据库连接配置。";
                $installed = false;
            }
        } else {
            $message = "点击下面的按钮开始安装系统。";
            $installed = false;
        }
    }
} catch(PDOException $e) {
    $message = "数据库连接失败: " . $e->getMessage();
    $installed = false;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统安装 - 团队接待处理系统</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .install-container {
            max-width: 600px;
            margin: 100px auto;
        }
    </style>
</head>
<body>
    <div class="container install-container">
        <div class="card">
            <div class="card-header text-center">
                <h3>团队接待处理系统 - 安装向导</h3>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <?php echo $message; ?>
                </div>
                
                <?php if (!$installed): ?>
                    <form method="POST">
                        <div class="d-grid gap-2">
                            <button type="submit" name="install" class="btn btn-primary btn-lg">
                                <i class="bi bi-download"></i> 开始安装
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="d-grid gap-2">
                        <a href="index.php" class="btn btn-success">访问首页</a>
                        <a href="admin/" class="btn btn-info">管理后台</a>
                        <div class="mt-3">
                            <h6>示例项目访问：</h6>
                            <ul class="list-unstyled">
                                <li><a href="project.php?code=ALI20241201000001" target="_blank">阿里巴巴技术大会</a></li>
                                <li><a href="project.php?code=TEN20241201000002" target="_blank">腾讯产品发布会</a></li>
                                <li><a href="project.php?code=BAI20241201000003" target="_blank">百度AI峰会</a></li>
                            </ul>
                            <p class="text-muted small">用户名：admin1, user1 等，密码：password</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>