-- 添加每日餐费补助金额字段到 personnel 表
ALTER TABLE `personnel` 
ADD COLUMN `meal_allowance` DECIMAL(10,2) DEFAULT '100.00' COMMENT '每日餐费补助金额' AFTER `position`;