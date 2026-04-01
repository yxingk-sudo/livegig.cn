-- ============================================================
-- 前后台分离多级权限管理系统 - 数据库设计
-- ============================================================

-- 禁用外键检查
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 注意：跳过已存在的基础表（companies, projects, project_users等）
-- ============================================================

-- 1. 角色表（roles）
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `role_name` VARCHAR(50) NOT NULL UNIQUE COMMENT '角色名称',
    `role_key` VARCHAR(50) NOT NULL UNIQUE COMMENT '角色标识（超级管理员：super_admin，管理员：admin，项目管理员：project_admin，前台管理员：user_admin，前台用户：user）',
    `role_type` ENUM('backend', 'frontend') NOT NULL COMMENT '角色类型：backend-后台，frontend-前台',
    `description` TEXT COMMENT '角色描述',
    `status` TINYINT(1) DEFAULT 1 COMMENT '状态：1-启用，0-禁用',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_role_type` (`role_type`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色表';

-- 2. 权限资源表（permissions）
DROP TABLE IF EXISTS `permissions`;
CREATE TABLE `permissions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `permission_name` VARCHAR(100) NOT NULL COMMENT '权限名称',
    `permission_key` VARCHAR(100) NOT NULL UNIQUE COMMENT '权限标识（如：user:add, hotel:view）',
    `permission_type` ENUM('page', 'function', 'data') NOT NULL COMMENT '权限类型：page-页面，function-功能，data-数据',
    `resource_type` ENUM('backend', 'frontend') NOT NULL COMMENT '资源类型：backend-后台，frontend-前台',
    `parent_id` INT DEFAULT 0 COMMENT '父权限ID（0表示顶级权限）',
    `resource_path` VARCHAR(255) COMMENT '资源路径（页面路径或API路径）',
    `menu_icon` VARCHAR(50) COMMENT '菜单图标',
    `sort_order` INT DEFAULT 0 COMMENT '排序顺序',
    `description` TEXT COMMENT '权限描述',
    `status` TINYINT(1) DEFAULT 1 COMMENT '状态：1-启用，0-禁用',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_permission_key` (`permission_key`),
    INDEX `idx_resource_type` (`resource_type`),
    INDEX `idx_parent_id` (`parent_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='权限资源表';

-- 3. 角色权限关联表（role_permissions）
DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE `role_permissions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `role_id` INT NOT NULL COMMENT '角色ID',
    `permission_id` INT NOT NULL COMMENT '权限ID',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_role_permission` (`role_id`, `permission_id`),
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='角色权限关联表';

-- 4. 后台用户表（admin_users）- 替代原有的admins表
DROP TABLE IF EXISTS `admin_users`;
CREATE TABLE `admin_users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(100) NOT NULL UNIQUE COMMENT '用户名',
    `password` VARCHAR(255) NOT NULL COMMENT '密码（加密）',
    `real_name` VARCHAR(100) COMMENT '真实姓名',
    `email` VARCHAR(255) COMMENT '邮箱',
    `phone` VARCHAR(50) COMMENT '手机号',
    `role_id` INT NOT NULL COMMENT '角色ID',
    `company_id` INT DEFAULT NULL COMMENT '所属公司ID（管理员级别需要）',
    `avatar` VARCHAR(255) COMMENT '头像',
    `status` TINYINT(1) DEFAULT 1 COMMENT '状态：1-启用，0-禁用',
    `last_login_time` TIMESTAMP NULL COMMENT '最后登录时间',
    `last_login_ip` VARCHAR(50) COMMENT '最后登录IP',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE,
    INDEX `idx_username` (`username`),
    INDEX `idx_role_id` (`role_id`),
    INDEX `idx_company_id` (`company_id`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='后台用户表';

-- 5. 后台用户项目权限表（admin_user_projects）
DROP TABLE IF EXISTS `admin_user_projects`;
CREATE TABLE `admin_user_projects` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `admin_user_id` INT NOT NULL COMMENT '后台用户ID',
    `project_id` INT NOT NULL COMMENT '项目ID',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_admin_project` (`admin_user_id`, `project_id`),
    FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='后台用户项目权限表（项目管理员专用）';

-- 6. 前台用户角色表（user_roles）
DROP TABLE IF EXISTS `user_roles`;
CREATE TABLE `user_roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL COMMENT '前台用户ID（project_users表）',
    `role_id` INT NOT NULL COMMENT '角色ID',
    `project_id` INT NOT NULL COMMENT '项目ID',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_user_role_project` (`user_id`, `role_id`, `project_id`),
    FOREIGN KEY (`user_id`) REFERENCES `project_users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='前台用户角色表';

-- 7. 用户自定义权限表（user_custom_permissions）
DROP TABLE IF EXISTS `user_custom_permissions`;
CREATE TABLE `user_custom_permissions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL COMMENT '用户ID',
    `user_type` ENUM('admin', 'project_user') NOT NULL COMMENT '用户类型：admin-后台用户，project_user-前台用户',
    `permission_id` INT NOT NULL COMMENT '权限ID',
    `permission_action` ENUM('grant', 'deny') NOT NULL DEFAULT 'grant' COMMENT '权限操作：grant-授权，deny-拒绝',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_user_permission` (`user_id`, `user_type`, `permission_id`),
    FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE,
    INDEX `idx_user` (`user_id`, `user_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户自定义权限表（覆盖角色权限）';

-- 8. 权限操作日志表（permission_logs）
DROP TABLE IF EXISTS `permission_logs`;
CREATE TABLE `permission_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `operator_id` INT NOT NULL COMMENT '操作者ID',
    `operator_type` ENUM('admin', 'project_user') NOT NULL COMMENT '操作者类型',
    `action_type` ENUM('role_create', 'role_update', 'role_delete', 'permission_grant', 'permission_revoke', 'user_create', 'user_update', 'user_delete') NOT NULL COMMENT '操作类型',
    `target_type` VARCHAR(50) COMMENT '目标类型（角色、用户、权限等）',
    `target_id` INT COMMENT '目标ID',
    `action_detail` TEXT COMMENT '操作详情（JSON格式）',
    `ip_address` VARCHAR(50) COMMENT '操作IP',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_operator` (`operator_id`, `operator_type`),
    INDEX `idx_action_type` (`action_type`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='权限操作日志表';

-- ============================================================
-- 初始化基础角色数据
-- ============================================================

INSERT INTO `roles` (`role_name`, `role_key`, `role_type`, `description`) VALUES
('超级管理员', 'super_admin', 'backend', '系统最高权限，拥有所有后台功能的完全访问权限'),
('管理员', 'admin', 'backend', '基于公司维度的权限控制，可管理该公司下所有项目'),
('项目管理员', 'project_admin', 'backend', '限定于指定项目的权限管理'),
('前台管理员', 'user_admin', 'frontend', '具备配置所有前台一般用户权限的管理能力'),
('前台用户', 'user', 'frontend', '前台一般用户，严格遵循已分配的权限集访问系统功能');

-- ============================================================
-- 初始化权限资源数据 - 后台权限
-- ============================================================

-- 后台一级菜单权限
INSERT INTO `permissions` (`permission_name`, `permission_key`, `permission_type`, `resource_type`, `parent_id`, `resource_path`, `menu_icon`, `sort_order`, `description`) VALUES
-- 仪表板
('仪表板', 'backend:dashboard', 'page', 'backend', 0, 'dashboard.php', 'bi-speedometer2', 1, '后台首页仪表板'),
-- 系统管理
('系统管理', 'backend:system', 'page', 'backend', 0, '', 'bi-gear', 100, '系统管理模块'),
-- 公司管理
('公司管理', 'backend:company', 'page', 'backend', 0, '', 'bi-building', 10, '公司管理模块'),
-- 项目管理
('项目管理', 'backend:project', 'page', 'backend', 0, '', 'bi-folder', 20, '项目管理模块'),
-- 人员管理
('人员管理', 'backend:personnel', 'page', 'backend', 0, '', 'bi-people', 30, '人员管理模块'),
-- 报餐管理
('报餐管理', 'backend:meal', 'page', 'backend', 0, '', 'bi-cup-hot', 40, '报餐管理模块'),
-- 酒店管理
('酒店管理', 'backend:hotel', 'page', 'backend', 0, '', 'bi-building', 50, '酒店管理模块'),
-- 交通管理
('交通管理', 'backend:transport', 'page', 'backend', 0, '', 'bi-truck', 60, '交通管理模块'),
-- 备份管理
('备份管理', 'backend:backup', 'page', 'backend', 0, 'backup_management.php', 'bi-database', 70, '系统备份管理');

-- 获取父权限ID（用于后续插入子权限）
SET @dashboard_id = (SELECT id FROM permissions WHERE permission_key = 'backend:dashboard');
SET @system_id = (SELECT id FROM permissions WHERE permission_key = 'backend:system');
SET @company_id = (SELECT id FROM permissions WHERE permission_key = 'backend:company');
SET @project_id = (SELECT id FROM permissions WHERE permission_key = 'backend:project');
SET @personnel_id = (SELECT id FROM permissions WHERE permission_key = 'backend:personnel');
SET @meal_id = (SELECT id FROM permissions WHERE permission_key = 'backend:meal');
SET @hotel_id = (SELECT id FROM permissions WHERE permission_key = 'backend:hotel');
SET @transport_id = (SELECT id FROM permissions WHERE permission_key = 'backend:transport');
SET @backup_id = (SELECT id FROM permissions WHERE permission_key = 'backend:backup');

-- 系统管理子权限
INSERT INTO `permissions` (`permission_name`, `permission_key`, `permission_type`, `resource_type`, `parent_id`, `resource_path`, `sort_order`) VALUES
('用户管理', 'backend:system:user', 'page', 'backend', @system_id, 'admin_users.php', 1),
('用户添加', 'backend:system:user:add', 'function', 'backend', @system_id, '', 2),
('用户编辑', 'backend:system:user:edit', 'function', 'backend', @system_id, '', 3),
('用户删除', 'backend:system:user:delete', 'function', 'backend', @system_id, '', 4),
('角色管理', 'backend:system:role', 'page', 'backend', @system_id, 'roles.php', 5),
('角色添加', 'backend:system:role:add', 'function', 'backend', @system_id, '', 6),
('角色编辑', 'backend:system:role:edit', 'function', 'backend', @system_id, '', 7),
('角色删除', 'backend:system:role:delete', 'function', 'backend', @system_id, '', 8),
('权限管理', 'backend:system:permission', 'page', 'backend', @system_id, 'permissions.php', 9),
('权限分配', 'backend:system:permission:assign', 'function', 'backend', @system_id, '', 10),
('系统配置', 'backend:system:config', 'page', 'backend', @system_id, 'site_config.php', 11),
('操作日志', 'backend:system:log', 'page', 'backend', @system_id, 'permission_logs.php', 12);

-- 公司管理子权限
INSERT INTO `permissions` (`permission_name`, `permission_key`, `permission_type`, `resource_type`, `parent_id`, `resource_path`, `sort_order`) VALUES
('公司列表', 'backend:company:list', 'page', 'backend', @company_id, 'companies.php', 1),
('公司添加', 'backend:company:add', 'function', 'backend', @company_id, '', 2),
('公司编辑', 'backend:company:edit', 'function', 'backend', @company_id, '', 3),
('公司删除', 'backend:company:delete', 'function', 'backend', @company_id, '', 4);

-- 项目管理子权限
INSERT INTO `permissions` (`permission_name`, `permission_key`, `permission_type`, `resource_type`, `parent_id`, `resource_path`, `sort_order`) VALUES
('项目列表', 'backend:project:list', 'page', 'backend', @project_id, 'projects.php', 1),
('项目添加', 'backend:project:add', 'function', 'backend', @project_id, 'project_add.php', 2),
('项目编辑', 'backend:project:edit', 'function', 'backend', @project_id, 'project_edit.php', 3),
('项目删除', 'backend:project:delete', 'function', 'backend', @project_id, '', 4),
('部门管理', 'backend:project:department', 'page', 'backend', @project_id, 'departments.php', 5);

-- 人员管理子权限
INSERT INTO `permissions` (`permission_name`, `permission_key`, `permission_type`, `resource_type`, `parent_id`, `resource_path`, `sort_order`) VALUES
('人员列表', 'backend:personnel:list', 'page', 'backend', @personnel_id, 'personnel.php', 1),
('人员添加', 'backend:personnel:add', 'function', 'backend', @personnel_id, 'batch_add_personnel.php', 2),
('人员编辑', 'backend:personnel:edit', 'function', 'backend', @personnel_id, '', 3),
('人员删除', 'backend:personnel:delete', 'function', 'backend', @personnel_id, '', 4),
('人员统计', 'backend:personnel:statistics', 'page', 'backend', @personnel_id, 'personnel_statistics.php', 5);

-- 报餐管理子权限
INSERT INTO `permissions` (`permission_name`, `permission_key`, `permission_type`, `resource_type`, `parent_id`, `resource_path`, `sort_order`) VALUES
('报餐记录', 'backend:meal:list', 'page', 'backend', @meal_id, 'meal_reports.php', 1),
('报餐统计', 'backend:meal:statistics', 'page', 'backend', @meal_id, 'meal_statistics.php', 2),
('餐补管理', 'backend:meal:allowance', 'page', 'backend', @meal_id, 'meal_allowance.php', 3),
('套餐管理', 'backend:meal:package', 'page', 'backend', @meal_id, 'meal_packages.php', 4),
('报餐审核', 'backend:meal:approve', 'function', 'backend', @meal_id, '', 5),
('报餐删除', 'backend:meal:delete', 'function', 'backend', @meal_id, '', 6);

-- 酒店管理子权限
INSERT INTO `permissions` (`permission_name`, `permission_key`, `permission_type`, `resource_type`, `parent_id`, `resource_path`, `sort_order`) VALUES
('酒店记录', 'backend:hotel:list', 'page', 'backend', @hotel_id, 'hotel_reports.php', 1),
('酒店统计', 'backend:hotel:statistics', 'page', 'backend', @hotel_id, 'hotel_statistics_admin.php', 2),
('酒店管理', 'backend:hotel:manage', 'page', 'backend', @hotel_id, 'hotel_management.php', 3),
('酒店编辑', 'backend:hotel:edit', 'function', 'backend', @hotel_id, '', 4),
('酒店删除', 'backend:hotel:delete', 'function', 'backend', @hotel_id, '', 5);

-- 交通管理子权限
INSERT INTO `permissions` (`permission_name`, `permission_key`, `permission_type`, `resource_type`, `parent_id`, `resource_path`, `sort_order`) VALUES
('交通记录', 'backend:transport:list', 'page', 'backend', @transport_id, 'transportation_reports.php', 1),
('交通统计', 'backend:transport:statistics', 'page', 'backend', @transport_id, 'transportation_statistics.php', 2),
('车队管理', 'backend:transport:fleet', 'page', 'backend', @transport_id, 'fleet_management.php', 3),
('车辆分配', 'backend:transport:assign', 'function', 'backend', @transport_id, 'assign_fleet.php', 4),
('交通编辑', 'backend:transport:edit', 'function', 'backend', @transport_id, '', 5),
('交通删除', 'backend:transport:delete', 'function', 'backend', @transport_id, '', 6);

-- 备份管理子权限
INSERT INTO `permissions` (`permission_name`, `permission_key`, `permission_type`, `resource_type`, `parent_id`, `resource_path`, `sort_order`) VALUES
('备份查看', 'backend:backup:view', 'page', 'backend', @backup_id, '', 1),
('备份创建', 'backend:backup:create', 'function', 'backend', @backup_id, '', 2),
('备份下载', 'backend:backup:download', 'function', 'backend', @backup_id, '', 3),
('备份删除', 'backend:backup:delete', 'function', 'backend', @backup_id, '', 4),
('备份恢复', 'backend:backup:restore', 'function', 'backend', @backup_id, '', 5);

-- ============================================================
-- 初始化权限资源数据 - 前台权限
-- ============================================================

-- 前台一级菜单权限
INSERT INTO `permissions` (`permission_name`, `permission_key`, `permission_type`, `resource_type`, `parent_id`, `resource_path`, `menu_icon`, `sort_order`, `description`) VALUES
-- 仪表板
('仪表板', 'frontend:dashboard', 'page', 'frontend', 0, 'dashboard.php', 'bi-speedometer2', 1, '前台首页仪表板'),
-- 人员管理
('人员管理', 'frontend:personnel', 'page', 'frontend', 0, '', 'bi-people', 10, '前台人员管理模块'),
-- 报餐管理
('报餐管理', 'frontend:meal', 'page', 'frontend', 0, '', 'bi-cup-hot', 20, '前台报餐管理模块'),
-- 酒店管理
('酒店管理', 'frontend:hotel', 'page', 'frontend', 0, '', 'bi-building', 30, '前台酒店管理模块'),
-- 交通管理
('交通管理', 'frontend:transport', 'page', 'frontend', 0, '', 'bi-truck', 40, '前台交通管理模块');

-- 获取前台父权限ID
SET @f_dashboard_id = (SELECT id FROM permissions WHERE permission_key = 'frontend:dashboard');
SET @f_personnel_id = (SELECT id FROM permissions WHERE permission_key = 'frontend:personnel');
SET @f_meal_id = (SELECT id FROM permissions WHERE permission_key = 'frontend:meal');
SET @f_hotel_id = (SELECT id FROM permissions WHERE permission_key = 'frontend:hotel');
SET @f_transport_id = (SELECT id FROM permissions WHERE permission_key = 'frontend:transport');

-- 前台人员管理子权限
INSERT INTO `permissions` (`permission_name`, `permission_key`, `permission_type`, `resource_type`, `parent_id`, `resource_path`, `sort_order`) VALUES
('人员列表', 'frontend:personnel:list', 'page', 'frontend', @f_personnel_id, 'personnel.php', 1),
('人员添加', 'frontend:personnel:add', 'function', 'frontend', @f_personnel_id, 'batch_add_personnel.php', 2),
('人员编辑', 'frontend:personnel:edit', 'function', 'frontend', @f_personnel_id, '', 3),
('人员删除', 'frontend:personnel:delete', 'function', 'frontend', @f_personnel_id, '', 4);

-- 前台报餐管理子权限
INSERT INTO `permissions` (`permission_name`, `permission_key`, `permission_type`, `resource_type`, `parent_id`, `resource_path`, `sort_order`) VALUES
('批量报餐', 'frontend:meal:batch', 'page', 'frontend', @f_meal_id, 'batch_meal_order.php', 1),
('报餐统计', 'frontend:meal:statistics', 'page', 'frontend', @f_meal_id, 'meals_new.php', 2),
('报餐记录', 'frontend:meal:list', 'page', 'frontend', @f_meal_id, 'meals_statistics.php', 3),
('餐费补助', 'frontend:meal:allowance', 'page', 'frontend', @f_meal_id, 'meal_allowance.php', 4),
('报餐编辑', 'frontend:meal:edit', 'function', 'frontend', @f_meal_id, '', 5),
('报餐删除', 'frontend:meal:delete', 'function', 'frontend', @f_meal_id, '', 6);

-- 前台酒店管理子权限
INSERT INTO `permissions` (`permission_name`, `permission_key`, `permission_type`, `resource_type`, `parent_id`, `resource_path`, `sort_order`) VALUES
('添加入住', 'frontend:hotel:add', 'page', 'frontend', @f_hotel_id, 'hotel_add.php', 1),
('入住记录', 'frontend:hotel:list', 'page', 'frontend', @f_hotel_id, 'hotels.php', 2),
('入住统计', 'frontend:hotel:statistics', 'page', 'frontend', @f_hotel_id, 'hotel_statistics.php', 3),
('房表一', 'frontend:hotel:room_list_1', 'page', 'frontend', @f_hotel_id, 'hotel_room_list.php', 4),
('房表二', 'frontend:hotel:room_list_2', 'page', 'frontend', @f_hotel_id, 'hotel_room_list_2.php', 5),
('入住编辑', 'frontend:hotel:edit', 'function', 'frontend', @f_hotel_id, '', 6),
('入住删除', 'frontend:hotel:delete', 'function', 'frontend', @f_hotel_id, '', 7);

-- 前台交通管理子权限
INSERT INTO `permissions` (`permission_name`, `permission_key`, `permission_type`, `resource_type`, `parent_id`, `resource_path`, `sort_order`) VALUES
('快速安排', 'frontend:transport:quick', 'page', 'frontend', @f_transport_id, 'quick_transport.php', 1),
('批量安排', 'frontend:transport:batch', 'page', 'frontend', @f_transport_id, 'transport_enhanced.php', 2),
('行程列表', 'frontend:transport:list', 'page', 'frontend', @f_transport_id, 'transport_list.php', 3),
('导出行程', 'frontend:transport:export', 'page', 'frontend', @f_transport_id, 'export_transport.php', 4),
('行程编辑', 'frontend:transport:edit', 'function', 'frontend', @f_transport_id, '', 5),
('行程删除', 'frontend:transport:delete', 'function', 'frontend', @f_transport_id, '', 6);

-- ============================================================
-- 初始化超级管理员权限（拥有所有权限）
-- ============================================================

-- 为超级管理员角色分配所有后台权限
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT 
    (SELECT id FROM roles WHERE role_key = 'super_admin'),
    id
FROM permissions
WHERE resource_type = 'backend';

-- ============================================================
-- 创建默认超级管理员账号
-- ============================================================

-- 密码：admin123（MD5加密）
INSERT INTO `admin_users` (`username`, `password`, `real_name`, `email`, `role_id`, `status`) VALUES
('admin', MD5('admin123'), '超级管理员', 'admin@example.com', (SELECT id FROM roles WHERE role_key = 'super_admin'), 1);

-- ============================================================
-- 完成提示
-- ============================================================
SELECT '权限系统数据库初始化完成！' AS message;
SELECT CONCAT('默认超级管理员账号：admin，密码：admin123') AS credentials;

-- 启用外键检查
SET FOREIGN_KEY_CHECKS = 1;
