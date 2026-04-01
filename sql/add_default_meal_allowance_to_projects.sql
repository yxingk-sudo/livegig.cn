-- 向projects表添加默认餐费补助金额字段
ALTER TABLE projects 
ADD COLUMN default_meal_allowance DECIMAL(10,2) DEFAULT '100.00' COMMENT '项目默认每日餐费补助金额';