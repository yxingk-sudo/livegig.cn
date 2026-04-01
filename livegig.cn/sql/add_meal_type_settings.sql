-- 为 projects 表添加餐类型启用/禁用配置字段
-- 执行时间：2024-01-XX
-- 描述：添加早餐、午餐、晚餐、宵夜的启用/禁用控制字段

ALTER TABLE projects 
ADD COLUMN breakfast_enabled TINYINT(1) DEFAULT 1 COMMENT '早餐是否启用',
ADD COLUMN lunch_enabled TINYINT(1) DEFAULT 1 COMMENT '午餐是否启用',
ADD COLUMN dinner_enabled TINYINT(1) DEFAULT 1 COMMENT '晚餐是否启用',
ADD COLUMN supper_enabled TINYINT(1) DEFAULT 1 COMMENT '宵夜是否启用';

-- 验证查询（可选执行）
-- SELECT id, project_name, breakfast_enabled, lunch_enabled, dinner_enabled, supper_enabled FROM projects;
