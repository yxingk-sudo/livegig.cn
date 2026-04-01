-- 报餐系统升级SQL脚本
-- 添加菜品套餐管理和宵夜支持

-- 1. 修改meal_reports表，添加宵夜类型和时间字段
ALTER TABLE meal_reports 
MODIFY meal_type ENUM('早餐','午餐','晚餐','宵夜') NOT NULL;

ALTER TABLE meal_reports 
ADD COLUMN meal_time TIME NULL COMMENT '用餐时间',
ADD COLUMN delivery_time TIME NULL COMMENT '送餐时间',
ADD COLUMN package_id INT NULL COMMENT '套餐ID',
ADD INDEX idx_package_id (package_id);

-- 2. 创建菜品套餐表
CREATE TABLE IF NOT EXISTS meal_packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    name VARCHAR(255) NOT NULL COMMENT '套餐名称',
    meal_type ENUM('早餐','午餐','晚餐','宵夜') NOT NULL COMMENT '适用餐类型',
    description TEXT COMMENT '套餐描述',
    price DECIMAL(10,2) DEFAULT 0.00 COMMENT '套餐价格',
    is_active BOOLEAN DEFAULT TRUE COMMENT '是否启用',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX idx_project_meal_type (project_id, meal_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='菜品套餐表';

-- 3. 创建套餐详情表
CREATE TABLE IF NOT EXISTS meal_package_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    package_id INT NOT NULL,
    item_name VARCHAR(255) NOT NULL COMMENT '菜品名称',
    item_description TEXT COMMENT '菜品描述',
    quantity INT DEFAULT 1 COMMENT '数量',
    unit VARCHAR(50) DEFAULT '份' COMMENT '单位',
    sort_order INT DEFAULT 0 COMMENT '排序',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (package_id) REFERENCES meal_packages(id) ON DELETE CASCADE,
    INDEX idx_package_sort (package_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='套餐详情表';

-- 4. 创建报餐详情表（关联套餐和人员）
CREATE TABLE IF NOT EXISTS meal_report_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    personnel_id INT NOT NULL,
    package_id INT NULL,
    meal_count INT DEFAULT 1 COMMENT '用餐份数',
    special_requirements TEXT COMMENT '特殊要求',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (report_id) REFERENCES meal_reports(id) ON DELETE CASCADE,
    FOREIGN KEY (personnel_id) REFERENCES personnel(id) ON DELETE CASCADE,
    FOREIGN KEY (package_id) REFERENCES meal_packages(id) ON DELETE SET NULL,
    UNIQUE KEY unique_report_personnel (report_id, personnel_id),
    INDEX idx_report_id (report_id),
    INDEX idx_personnel_id (personnel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='报餐详情表';

-- 5. 插入示例套餐数据
INSERT IGNORE INTO meal_packages (project_id, name, meal_type, description, price) VALUES
(1, '标准早餐套餐', '早餐', '包含豆浆、包子、鸡蛋、咸菜', 15.00),
(1, '丰盛早餐套餐', '早餐', '包含牛奶、面包、培根、鸡蛋、水果', 25.00),
(1, '商务午餐套餐', '午餐', '两荤一素配米饭和汤', 28.00),
(1, '营养午餐套餐', '午餐', '精选荤素搭配，营养均衡', 35.00),
(1, '家常晚餐套餐', '晚餐', '三菜一汤配米饭', 32.00),
(1, '精品晚餐套餐', '晚餐', '四菜一汤，荤素搭配', 45.00),
(1, '夜宵套餐A', '宵夜', '粥类配小菜', 18.00),
(1, '夜宵套餐B', '宵夜', '面条配卤蛋', 22.00);

-- 6. 插入示例套餐详情
INSERT IGNORE INTO meal_package_items (package_id, item_name, item_description, quantity, unit, sort_order) VALUES
-- 标准早餐套餐
(1, '豆浆', '新鲜豆浆', 1, '杯', 1),
(1, '包子', '猪肉包子', 2, '个', 2),
(1, '鸡蛋', '水煮鸡蛋', 1, '个', 3),
(1, '咸菜', '爽口咸菜', 1, '份', 4),

-- 丰盛早餐套餐
(2, '牛奶', '纯牛奶', 1, '盒', 1),
(2, '面包', '全麦面包', 2, '片', 2),
(2, '培根', '煎培根', 2, '片', 3),
(2, '鸡蛋', '煎蛋', 1, '个', 4),
(2, '水果', '时令水果', 1, '份', 5),

-- 商务午餐套餐
(3, '红烧肉', '经典红烧肉', 1, '份', 1),
(3, '宫保鸡丁', '川式宫保鸡丁', 1, '份', 2),
(3, '清炒时蔬', '当季蔬菜', 1, '份', 3),
(3, '米饭', '香米饭', 1, '份', 4),
(3, '汤', '紫菜蛋花汤', 1, '份', 5);

-- 7. 创建报餐统计视图
CREATE OR REPLACE VIEW meal_statistics_view AS
SELECT 
    mr.project_id,
    mr.meal_date,
    mr.meal_type,
    mp.name as package_name,
    p.name as personnel_name,
    d.name as department_name,
    mrd.meal_count,
    mr.meal_time,
    mr.delivery_time,
    mr.status,
    mr.created_at
FROM meal_reports mr
LEFT JOIN meal_report_details mrd ON mr.id = mrd.report_id
LEFT JOIN personnel p ON mrd.personnel_id = p.id
LEFT JOIN meal_packages mp ON mrd.package_id = mp.id
LEFT JOIN project_department_personnel pdp ON (mr.project_id = pdp.project_id AND p.id = pdp.personnel_id)
LEFT JOIN departments d ON pdp.department_id = d.id
ORDER BY mr.meal_date DESC, mr.meal_type, p.name;