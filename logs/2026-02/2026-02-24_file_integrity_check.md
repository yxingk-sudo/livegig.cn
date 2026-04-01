# 项目文件完整性检查报告

**检查日期**: 2026年2月24日  
**检查范围**: 全站功能文件完整性  
**检查状态**: 已完成

---

## 一、缺失文件清单

### 1. 核心公共函数文件 (高优先级)

| 缺失文件 | 被引用位置 | 影响范围 |
|---------|-----------|----------|
| `admin/page_functions.php` | 多个admin页面 | getCurrentPage()函数缺失导致侧边栏高亮异常 |

**被引用文件**:
- `admin/hotel_edit.php`
- `admin/batch_add_personnel.php`
- `admin/batch_add_personnel_step2.php`
- `admin/unified_transport_view.php`
- `admin/transportation_statistics.php`
- `admin/transportation_reports.php`
- `admin/personnel_statistics.php`
- `admin/personnel_enhanced.php`
- `admin/project_add.php`
- `admin/hotel_management.php`
- `admin/site_config.php`
- `admin/project_edit.php`
- `admin/includes/sidebar.php`

**影响**: 这些文件都包含了容错代码（检查文件是否存在并定义默认函数），但建议创建统一的page_functions.php文件。

---

### 2. Admin API接口文件 (高优先级)

| 缺失文件 | 被引用位置 | 功能描述 |
|---------|-----------|----------|
| `admin/api/projects/get_all_projects.php` | `admin/personnel_statistics.php` | 获取所有项目列表 |
| `admin/api/departments/get_departments_by_project.php` | `admin/personnel_statistics.php` | 根据项目获取部门列表 |
| `admin/api/api_delete_passenger.php` | `admin/edit_transportation.php` | 删除乘客API |
| `admin/api/get_department_personnel.php` | `admin/departments.php` | 获取部门人员列表 |

**备注**: `admin/api/departments/` 目录存在但为空

---

### 3. Admin AJAX处理文件 (高优先级)

| 缺失文件 | 被引用位置 | 功能描述 |
|---------|-----------|----------|
| `admin/ajax_update_transport_location.php` | `admin/project_edit.php` | 更新交通地点 |
| `admin/ajax_update_hotel_report_days.php` | `admin/meal_allowance.php` | 更新酒店报告天数 |
| `admin/ajax_create_hotel_report.php` | `admin/meal_allowance.php` | 创建酒店报告 |
| `admin/transportation_report_delete.php` | `admin/transportation_reports.php` | 删除交通报告 |

---

### 4. User端核心页面文件 (高优先级)

| 缺失文件 | 被引用位置 | 功能描述 |
|---------|-----------|----------|
| `user/batch_meal_order.php` | `user/includes/header.php` | 批量报餐页面 |
| `user/meals_new.php` | `user/includes/header.php` | 报餐统计页面 |
| `user/hotel_room_list_2.php` | `user/includes/header.php` | 房表二页面 |
| `user/quick_transport.php` | `user/includes/header.php` | 快速安排行程 |
| `user/transport_enhanced.php` | `user/includes/header.php` | 批量安排行程 |
| `user/export_transport_html.php` | `user/includes/header.php` | 导出车程表 |
| `user/logout.php` | `user/includes/header.php` | 用户登出页面 |

---

### 5. User端AJAX处理文件 (中优先级)

| 缺失文件 | 被引用位置 | 功能描述 |
|---------|-----------|----------|
| `user/ajax/update_position.php` | `user/personnel.php` | 更新人员职位 |
| `user/ajax/update_badge_type.php` | `user/personnel.php` | 更新证件类型 |
| `user/get_transport_info.php` | `user/transport_list.php` | 获取交通信息 |
| `user/add_roommate.php` | `user/hotels.php` | 添加室友 |

---

### 6. 资源文件 (低优先级)

| 缺失文件/目录 | 位置 | 备注 |
|-------------|------|------|
| `user/assets/css/style.css` | `user/includes/header.php` 引用 | CSS样式文件缺失 |
| `user/assets/css/` | 空目录 | 无CSS文件 |
| `user/assets/js/` | 空目录 | 无JS文件 |
| `user/assets/images/` | 空目录 | 无图片文件 |
| `assets/fonts/` | 空目录 | 字体文件缺失 |
| `assets/images/` | 空目录 | 图片文件缺失 |

---

## 二、空目录清单

| 目录路径 | 建议处理 |
|---------|----------|
| `admin/api/departments/` | 需要创建部门相关API文件 |
| `user/assets/css/` | 需要创建style.css或移除引用 |
| `user/assets/js/` | 需要检查是否需要JS文件 |
| `user/assets/images/` | 需要添加必要的图片资源 |
| `user/assets/config/` | 空目录，建议删除 |
| `user/config/` | 空目录，建议删除 |
| `assets/fonts/` | 需要添加本地字体文件 |
| `assets/images/` | 需要添加图片资源 |

---

## 三、已存在但可能存在问题的文件

### 1. 重复文件
项目中存在大量备份文件在 `backupfiles/` 目录，与当前使用的文件可能不同步：
- `backupfiles/admin/` 目录包含多个admin页面的备份版本

### 2. 用户端函数文件位置
- `user/includes/functions.php` 存在，但与 `includes/functions.php` 可能功能重复

---

## 四、修复建议

### 高优先级修复

1. **创建 `admin/page_functions.php`**
```php
<?php
/**
 * 页面公共函数文件
 */

/**
 * 获取当前页面文件名
 */
function getCurrentPage() {
    return basename($_SERVER['PHP_SELF']);
}

/**
 * 检查当前页面是否为指定页面
 */
function isCurrentPage($page) {
    return getCurrentPage() === $page;
}
```

2. **创建缺失的Admin API文件**
   - 创建 `admin/api/projects/get_all_projects.php`
   - 创建 `admin/api/departments/get_departments_by_project.php`
   - 创建 `admin/api/api_delete_passenger.php`

3. **创建缺失的User页面文件**
   - 创建 `user/logout.php`（基本的登出功能）
   - 创建其他缺失的功能页面

### 中优先级修复

4. **创建缺失的AJAX处理文件**
   - `admin/ajax_update_transport_location.php`
   - `admin/ajax_update_hotel_report_days.php`
   - `user/ajax/update_position.php`
   - `user/ajax/update_badge_type.php`

### 低优先级修复

5. **资源文件整理**
   - 创建 `user/assets/css/style.css` 或修改header.php引用路径
   - 清理空目录

---

## 五、备注

1. 许多文件的引用都包含了容错处理，但这不是最佳实践
2. 建议统一前端资源的引用方式（CDN vs 本地）
3. 建议整理backupfiles目录，确定是否需要保留
4. 需要检查数据库表结构是否与PHP代码一致

---

---

## 六、Backupfiles对比分析结果

### 对比说明
对比 `backupfiles/` 目录（早期完整备份）与当前项目目录，确认缺失的核心功能文件。

### 1. Admin端缺失的核心功能文件

| 缺失文件 | 备份位置 | 功能描述 | 优先级 |
|---------|----------|----------|--------|
| `page_functions.php` | `backupfiles/admin/page_functions.php` | 页面公共函数（getCurrentPage等） | **高** |
| `transportation_report_delete.php` | `backupfiles/admin/transportation_report_delete.php` | 删除交通报告 | **高** |

### 2. User端缺失的核心功能文件（全部为高优先级）

| 缺失文件 | 备份位置 | 功能描述 |
|---------|----------|----------|
| `logout.php` | `backupfiles/user/logout.php` | 用户登出功能 |
| `batch_meal_order.php` | `backupfiles/user/batch_meal_order.php` | 批量报餐页面 |
| `meals_new.php` | `backupfiles/user/meals_new.php` | 报餐统计页面 |
| `hotel_room_list_2.php` | `backupfiles/user/hotel_room_list_2.php` | 房表二页面 |
| `quick_transport.php` | `backupfiles/user/quick_transport.php` | 快速安排行程 |
| `transport_enhanced.php` | `backupfiles/user/transport_enhanced.php` | 批量安排行程 |
| `export_transport_html.php` | `backupfiles/user/export_transport_html.php` | 导出车程表 |
| `add_roommate.php` | `backupfiles/user/add_roommate.php` | 添加室友功能 |

### 3. User端缺失的辅助文件

| 缺失文件 | 备份位置 | 功能描述 | 优先级 |
|---------|----------|----------|--------|
| `includes/auth_check.php` | `backupfiles/user/includes/auth_check.php` | 权限验证 | 中 |
| `export_transport_pdf.php` | `backupfiles/user/export_transport_pdf.php` | 导出PDF | 低 |
| `export_transport_schedule.php` | `backupfiles/user/export_transport_schedule.php` | 导出行程表 | 低 |
| `batch_shared_rooms.php` | `backupfiles/user/batch_shared_rooms.php` | 批量共享房间 | 低 |
| `auto_complete_pinyin.php` | `backupfiles/user/auto_complete_pinyin.php` | 自动完成拼音 | 低 |

---

## 七、修复操作建议

### 方案一：从backupfiles恢复（推荐）

直接从backupfiles复制缺失文件到对应位置：

```bash
# Admin端文件
cp /www/wwwroot/livegig.cn/backupfiles/admin/page_functions.php /www/wwwroot/livegig.cn/admin/
cp /www/wwwroot/livegig.cn/backupfiles/admin/transportation_report_delete.php /www/wwwroot/livegig.cn/admin/

# User端核心文件
cp /www/wwwroot/livegig.cn/backupfiles/user/logout.php /www/wwwroot/livegig.cn/user/
cp /www/wwwroot/livegig.cn/backupfiles/user/batch_meal_order.php /www/wwwroot/livegig.cn/user/
cp /www/wwwroot/livegig.cn/backupfiles/user/meals_new.php /www/wwwroot/livegig.cn/user/
cp /www/wwwroot/livegig.cn/backupfiles/user/hotel_room_list_2.php /www/wwwroot/livegig.cn/user/
cp /www/wwwroot/livegig.cn/backupfiles/user/quick_transport.php /www/wwwroot/livegig.cn/user/
cp /www/wwwroot/livegig.cn/backupfiles/user/transport_enhanced.php /www/wwwroot/livegig.cn/user/
cp /www/wwwroot/livegig.cn/backupfiles/user/export_transport_html.php /www/wwwroot/livegig.cn/user/
cp /www/wwwroot/livegig.cn/backupfiles/user/add_roommate.php /www/wwwroot/livegig.cn/user/

# User端辅助文件
cp /www/wwwroot/livegig.cn/backupfiles/user/includes/auth_check.php /www/wwwroot/livegig.cn/user/includes/
```

### 恢复后验证清单

- [ ] 测试用户登出功能 (`user/logout.php`)
- [ ] 测试批量报餐页面 (`user/batch_meal_order.php`)
- [ ] 测试报餐统计页面 (`user/meals_new.php`)
- [ ] 测试房表二页面 (`user/hotel_room_list_2.php`)
- [ ] 测试快速安排行程 (`user/quick_transport.php`)
- [ ] 测试批量安排行程 (`user/transport_enhanced.php`)
- [ ] 测试导出车程表 (`user/export_transport_html.php`)
- [ ] 测试添加室友功能 (`user/add_roommate.php`)
- [ ] 测试Admin侧边栏高亮 (`admin/page_functions.php`)
- [ ] 测试删除交通报告 (`admin/transportation_report_delete.php`)

---

## 八、恢复操作执行记录

### 执行时间
2026年2月24日 10:36-10:45

### 恢复文件清单

| 序号 | 文件 | 源位置 | 目标位置 | 状态 |
|-----|------|--------|--------|------|
| 1 | page_functions.php | backupfiles/admin/ | admin/ | ✅ 已恢复 |
| 2 | transportation_report_delete.php | backupfiles/admin/ | admin/ | ✅ 已恢复 |
| 3 | logout.php | backupfiles/user/ | user/ | ✅ 已恢复 |
| 4 | batch_meal_order.php | backupfiles/user/ | user/ | ✅ 已恢复 |
| 5 | meals_new.php | backupfiles/user/ | user/ | ✅ 已恢复 |
| 6 | hotel_room_list_2.php | backupfiles/user/ | user/ | ✅ 已恢复 |
| 7 | quick_transport.php | backupfiles/user/ | user/ | ✅ 已恢复 |
| 8 | transport_enhanced.php | backupfiles/user/ | user/ | ✅ 已恢复 |
| 9 | export_transport_html.php | backupfiles/user/ | user/ | ✅ 已恢复 |
| 10 | add_roommate.php | backupfiles/user/ | user/ | ✅ 已恢复 |
| 11 | auth_check.php | backupfiles/user/includes/ | user/includes/ | ✅ 已恢复 |

### 验证结果

#### PHP语法检查
- ✅ 所有11个文件语法检查通过

#### 依赖文件检查
- ✅ config/database.php 存在
- ✅ includes/functions.php 存在
- ✅ includes/site_config.php 存在
- ✅ includes/pinyin_functions.php 存在

#### HTTP响应验证
| 页面 | HTTP状态码 | 说明 |
|------|------------|------|
| logout.php | 302 | 正常（重定向登录） |
| batch_meal_order.php | 302 | 正常（重定向登录） |
| meals_new.php | 302 | 正常（重定向登录） |
| quick_transport.php | 302 | 正常（重定向登录） |
| transport_enhanced.php | 302 | 正常（重定向登录） |
| hotel_room_list_2.php | 302 | 正常（重定向登录） |
| export_transport_html.php | 302 | 正常（重定向登录） |
| add_roommate.php | 302 | 正常（重定向登录） |
| transportation_report_delete.php | 401 | 正常（需管理员登录） |

### 文件权限设置
- 所有恢复文件已设置为 www:www 所有者
- 权限设置为 644

### 恢复结果
**✅ 恢复完成 - 所有11个文件已成功恢复并验证通过**

---

**检查人员**: AI Assistant  
**更新时间**: 2026年2月24日  
**任务状态**: ✅ 完成
**下次检查建议**: 建议实际登录系统后测试各功能页面的完整操作流程
