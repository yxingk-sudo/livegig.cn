-- 为现有项目表添加交通地点字段
-- 如果字段已存在，请先删除再重新添加

-- 检查并添加到达机场字段
ALTER TABLE projects 
ADD COLUMN IF NOT EXISTS arrival_airport VARCHAR(255) NULL COMMENT '到达机场';

-- 检查并添加到达高铁站字段
ALTER TABLE projects 
ADD COLUMN IF NOT EXISTS arrival_railway_station VARCHAR(255) NULL COMMENT '到达高铁站';

-- 检查并添加出发机场字段
ALTER TABLE projects 
ADD COLUMN IF NOT EXISTS departure_airport VARCHAR(255) NULL COMMENT '出发机场';

-- 检查并添加出发高铁站字段
ALTER TABLE projects 
ADD COLUMN IF NOT EXISTS departure_railway_station VARCHAR(255) NULL COMMENT '出发高铁站';

-- 如果location字段不存在，也添加它
ALTER TABLE projects 
ADD COLUMN IF NOT EXISTS location VARCHAR(255) NULL COMMENT '项目场地';

-- 显示表结构确认
DESCRIBE projects;