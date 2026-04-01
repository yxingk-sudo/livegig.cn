-- 创建人员与项目用户的关联表
CREATE TABLE IF NOT EXISTS `personnel_project_users` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `personnel_id` int(11) NOT NULL,
    `project_user_id` int(11) NOT NULL,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_personnel_project_user` (`personnel_id`, `project_user_id`),
    KEY `personnel_id` (`personnel_id`),
    KEY `project_user_id` (`project_user_id`),
    CONSTRAINT `personnel_project_users_ibfk_1` FOREIGN KEY (`personnel_id`) REFERENCES `personnel` (`id`) ON DELETE CASCADE,
    CONSTRAINT `personnel_project_users_ibfk_2` FOREIGN KEY (`project_user_id`) REFERENCES `project_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 检查并移除project_department_personnel表中的join_date字段（如果存在）
-- ALTER TABLE `project_department_personnel` DROP COLUMN IF EXISTS `join_date`;