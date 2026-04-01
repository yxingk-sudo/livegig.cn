-- 完善套餐菜品项目数据
USE default;

-- 删除现有的套餐项目数据，重新插入完整数据
DELETE FROM meal_package_items;

-- 插入完整的套餐项目数据
-- 标准早餐套餐 (id=1)
INSERT INTO meal_package_items (package_id, item_name, item_description, quantity, unit, sort_order) VALUES
(1, '豆浆', '新鲜热豆浆', 1, '杯', 1),
(1, '包子', '猪肉包子', 2, '个', 2),
(1, '鸡蛋', '水煮鸡蛋', 1, '个', 3),
(1, '咸菜', '爽口咸菜', 1, '份', 4);

-- 丰盛早餐套餐 (id=2) 
INSERT INTO meal_package_items (package_id, item_name, item_description, quantity, unit, sort_order) VALUES
(2, '牛奶', '纯牛奶', 1, '盒', 1),
(2, '面包', '全麦面包', 2, '片', 2),
(2, '培根', '煎培根', 2, '片', 3),
(2, '鸡蛋', '煎蛋', 1, '个', 4),
(2, '水果', '时令水果', 1, '份', 5);

-- 商务午餐套餐 (id=3)
INSERT INTO meal_package_items (package_id, item_name, item_description, quantity, unit, sort_order) VALUES
(3, '红烧肉', '经典红烧肉', 1, '份', 1),
(3, '宫保鸡丁', '川式宫保鸡丁', 1, '份', 2),
(3, '清炒时蔬', '当季蔬菜', 1, '份', 3),
(3, '米饭', '香米饭', 1, '份', 4),
(3, '汤', '紫菜蛋花汤', 1, '份', 5);

-- 营养午餐套餐 (id=4)
INSERT INTO meal_package_items (package_id, item_name, item_description, quantity, unit, sort_order) VALUES
(4, '清蒸鱼', '新鲜海鱼', 1, '份', 1),
(4, '蒜蓉西兰花', '营养搭配', 1, '份', 2),
(4, '土豆丝', '酸辣土豆丝', 1, '份', 3),
(4, '小米粥', '养胃小米粥', 1, '份', 4),
(4, '水果', '餐后水果', 1, '份', 5);

-- 家常晚餐套餐 (id=5)
INSERT INTO meal_package_items (package_id, item_name, item_description, quantity, unit, sort_order) VALUES
(5, '糖醋里脊', '酸甜可口', 1, '份', 1),
(5, '麻婆豆腐', '川式经典', 1, '份', 2),
(5, '炒青菜', '时令青菜', 1, '份', 3),
(5, '米饭', '香米饭', 1, '份', 4),
(5, '汤', '冬瓜排骨汤', 1, '份', 5);

-- 精品晚餐套餐 (id=6)
INSERT INTO meal_package_items (package_id, item_name, item_description, quantity, unit, sort_order) VALUES
(6, '白切鸡', '嫩滑白切鸡', 1, '份', 1),
(6, '红烧茄子', '家常红烧茄子', 1, '份', 2),
(6, '清炒菠菜', '新鲜菠菜', 1, '份', 3),
(6, '蒸蛋', '水蒸蛋', 1, '份', 4),
(6, '米饭', '香米饭', 1, '份', 5),
(6, '汤', '番茄鸡蛋汤', 1, '份', 6);

-- 夜宵套餐A (id=7)
INSERT INTO meal_package_items (package_id, item_name, item_description, quantity, unit, sort_order) VALUES
(7, '白粥', '温润白粥', 1, '碗', 1),
(7, '咸菜', '下粥小菜', 1, '份', 2),
(7, '花生米', '水煮花生', 1, '份', 3);

-- 夜宵套餐B (id=8)
INSERT INTO meal_package_items (package_id, item_name, item_description, quantity, unit, sort_order) VALUES
(8, '牛肉面', '红烧牛肉面', 1, '碗', 1),
(8, '卤蛋', '五香卤蛋', 1, '个', 2),
(8, '青菜', '时令青菜', 1, '份', 3);

-- 验证插入结果
SELECT mp.name as '套餐名称', mp.meal_type as '餐类型', COUNT(mpi.id) as '菜品数量'
FROM meal_packages mp 
LEFT JOIN meal_package_items mpi ON mp.id = mpi.package_id 
GROUP BY mp.id 
ORDER BY mp.meal_type, mp.name;