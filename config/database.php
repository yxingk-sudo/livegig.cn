<?php
class Database {
    private $host = "43.160.193.67";
    private $db_name = "team_reception";
    private $username = "team_reception";
    private $password = "team_reception";
    private $conn;

    public function getConnection() {
        $this->conn = null;

        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->exec("set names utf8mb4");
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $exception) {
            error_log("数据库连接错误: " . $exception->getMessage());
            return null;
        }

        return $this->conn;
    }

    // 创建site_config表
    public function createSiteConfigTable() {
        $query = "CREATE TABLE IF NOT EXISTS site_config (
            id INT AUTO_INCREMENT PRIMARY KEY,
            config_key VARCHAR(100) NOT NULL UNIQUE,
            config_value TEXT,
            config_type VARCHAR(50) DEFAULT 'string',
            description VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        
        try {
            $this->conn->exec($query);
            
            // 插入默认配置
            $stmt = $this->conn->prepare("INSERT IGNORE INTO site_config (config_key, config_value, config_type, description) VALUES
                ('site_name', '企业项目管理系统', 'string', '网站名称'),
                ('site_url', 'http://localhost/', 'string', '网站访问地址'),
                ('frontend_title', '企业项目管理系统 - 前端', 'string', '前端网页标题'),
                ('admin_title', '企业项目管理系统 - 管理后台', 'string', '管理后台标题'),
                ('meta_description', '专业的企业项目管理系统', 'string', '网站描述'),
                ('meta_keywords', '项目管理,企业系统', 'string', '网站关键词'),
                ('footer_text', '© 2024 企业项目管理系统. 版权所有.', 'string', '页脚文本'),
                ('contact_email', 'admin@example.com', 'string', '联系邮箱'),
                ('contact_phone', '400-123-4567', 'string', '联系电话')");
            $stmt->execute();
            
            return true;
        } catch(PDOException $e) {
            error_log("创建site_config表错误: " . $e->getMessage());
            return false;
        }
    }

    // 获取网站配置
    public function getSiteConfig($key, $default = null) {
        try {
            $stmt = $this->conn->prepare("SELECT config_value FROM site_config WHERE config_key = ?");
            $stmt->execute([$key]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['config_value'] : $default;
        } catch(PDOException $e) {
            return $default;
        }
    }

    // 更新网站配置
    public function updateSiteConfig($key, $value) {
        try {
            $stmt = $this->conn->prepare("INSERT INTO site_config (config_key, config_value, config_type, description) VALUES (?, ?, 'string', '') 
                ON DUPLICATE KEY UPDATE config_value = ?, updated_at = CURRENT_TIMESTAMP");
            return $stmt->execute([$key, $value, $value]);
        } catch(PDOException $e) {
            error_log("更新配置错误: " . $e->getMessage());
            return false;
        }
    }

    // 获取所有配置
    public function getAllSiteConfig() {
        try {
            $stmt = $this->conn->query("SELECT config_key, config_value FROM site_config");
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch(PDOException $e) {
            return [];
        }
    }
}

// 创建数据库表
function createTables($db) {
    $queries = [
        // 公司表
        "CREATE TABLE IF NOT EXISTS companies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            contact_person VARCHAR(100),
            phone VARCHAR(50),
            email VARCHAR(255),
            address TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        
        // 项目表
        "CREATE TABLE IF NOT EXISTS projects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            code VARCHAR(50) UNIQUE NOT NULL,
            description TEXT,
            location VARCHAR(255),
            start_date DATE,
            end_date DATE,
            status ENUM('active', 'inactive', 'completed') DEFAULT 'active',
            arrival_airport VARCHAR(255) NULL COMMENT '到达机场',
            arrival_railway_station VARCHAR(255) NULL COMMENT '到达高铁站',
            departure_airport VARCHAR(255) NULL COMMENT '出发机场',
            departure_railway_station VARCHAR(255) NULL COMMENT '出发高铁站',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
        )",
        
        // 项目用户表
        "CREATE TABLE IF NOT EXISTS project_users (
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
        )",
        
        // 部门表
        "CREATE TABLE IF NOT EXISTS departments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        )",
        
        // 人员表
        "CREATE TABLE IF NOT EXISTS personnel (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            phone VARCHAR(50),
            email VARCHAR(255),
            id_card VARCHAR(50),
            gender ENUM('男', '女', '其他'),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        
        // 项目部门人员关联表
        "CREATE TABLE IF NOT EXISTS project_department_personnel (
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
        )",
        
        // 报餐记录表
        "CREATE TABLE IF NOT EXISTS meal_reports (
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
        )",
        
        // 酒店预订表
        "CREATE TABLE IF NOT EXISTS hotel_reports (
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
        )",
        
        // 出行车预订表
        "CREATE TABLE IF NOT EXISTS transportation_reports (
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
        )",
        
        // 人员项目关联表（替代project_department_personnel表）
        "CREATE TABLE IF NOT EXISTS personnel_projects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            personnel_id INT NOT NULL,
            company_id INT NOT NULL,
            project_id INT NOT NULL,
            department_ids VARCHAR(255),
            position VARCHAR(100),
            start_date DATE,
            end_date DATE,
            status ENUM('active', 'inactive', 'completed', 'on_hold', 'cancelled') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (personnel_id) REFERENCES personnel(id) ON DELETE CASCADE,
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
            FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
        )",
        
        // 管理员表
        "CREATE TABLE IF NOT EXISTS admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            email VARCHAR(255) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            display_name VARCHAR(100),
            role ENUM('admin', 'super_admin') DEFAULT 'admin',
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )"
    ];

    foreach ($queries as $query) {
        try {
            $db->exec($query);
        } catch(PDOException $e) {
            error_log("创建表错误: " . $e->getMessage());
        }
    }
}

// 初始化数据库连接
$database = new Database();
$db = $database->getConnection();

// 如果需要创建表，取消下面的注释
// createTables($db);
?>