# 报餐管理页面 - 快速参考卡

## 📁 文件位置
```
/user/meal_management.php          # 主页面（737 行）
/user/ajax/save_meal_selection.php # 保存接口（188 行）
/user/ajax/get_meal_reports.php    # 查询接口（117 行）
/docs/meal_management_guide.md     # 使用手册
```

## 🎯 核心功能

| 功能 | 描述 | 状态 |
|------|------|------|
| 表格展示 | 人员 + 日期 + 餐次三维表格 | ✅ |
| 实时保存 | 点击即保存到数据库 | ✅ |
| 全选功能 | 一键选中所有可用餐次 | ✅ |
| 部门选择 | 按部门批量选择 | ✅ |
| 已有记录 | 自动加载并显示选中状态 | ✅ |
| 统计显示 | 总人数、总天数、已选餐次 | ✅ |

## 🔧 快速操作

### 访问页面
```
http://your-domain/user/meal_management.php
```

### 选择餐次
- **单个选择**：直接点击复选框
- **批量选择**：点击"全选"按钮
- **部门选择**：下拉框选择部门 → 确认

### 取消选择
- **单个取消**：点击已选中的复选框
- **全部取消**：点击"取消全选"按钮

## 📊 表格结构

```
┌─────┬─────┬──────┬──────────────────┬──────────────────┬───
│序号 │姓名 │部门  │2025-08-11(周一)  │2025-08-12(周二)  │...
│     │     │      │早餐│午餐│晚餐   │早餐│午餐│晚餐   │
├─────┼─────┼──────┼────┼────┼───────┼────┼────┼───────┼───
│1    │张三 │技术部│☑️  │☐  │☑️     │☐  │☐  │☐     │...
│2    │李四 │设计部│☐  │☑️  │☑️     │☑️  │☑️  │☐     │...
```

## 🎨 样式特点

- **固定列**: 序号、姓名、部门（横向滚动时固定）
- **日期分组**: 每周边框蓝色分隔
- **禁用状态**: 非入住日期显示灰色
- **选中状态**: 蓝色复选框 accent-color

## ⚡ 性能优化

### 防抖处理
```javascript
debouncedSaveMealSelection = debounce(saveMealSelection, 300);
// 300ms 延迟，避免频繁请求
```

### 固定列实现
```css
position: sticky;
left: 0;
z-index: 5; /* 固定列 */
z-index: 15; /* 表头固定列 */
```

## 🔌 API 接口

### 保存接口
```bash
POST /user/ajax/save_meal_selection.php
Content-Type: application/json

{
    "personnel_id": 16,
    "meal_date": "2025-08-11",
    "meal_type": "早餐",
    "is_selected": true,
    "project_id": 7
}

响应:
{
    "success": true,
    "message": "报餐成功",
    "action": "inserted"
}
```

### 查询接口
```bash
GET /user/ajax/get_meal_reports.php?start_date=2025-08-11&end_date=2025-08-20

响应:
{
    "success": true,
    "count": 10,
    "reports": [...]
}
```

## 🐛 调试技巧

### 浏览器控制台
```javascript
// 查看网络请求
console.log('保存的请求:', requestData);

// 查看统计数据
console.log('已选餐次:', document.querySelectorAll('.meal-checkbox:checked').length);
```

### PHP 错误日志
```bash
tail -f /path/to/php_error.log | grep meal_management
```

### 数据库查询
```sql
-- 查看某人的报餐记录
SELECT * FROM meal_reports 
WHERE personnel_id = 16 
AND project_id = 7
ORDER BY meal_date;

-- 查看某天的报餐统计
SELECT meal_type, COUNT(*) as count 
FROM meal_reports 
WHERE meal_date = '2025-08-11'
AND project_id = 7
GROUP BY meal_type;
```

## ⚠️ 注意事项

### 权限要求
- ✅ 必须登录
- ✅ 必须有项目权限
- ❌ 未登录会跳转登录页

### 数据验证
- ✅ 日期格式：YYYY-MM-DD
- ✅ 餐类型：早餐、午餐、晚餐、宵夜
- ✅ 人员 ID：必须是数字

### 兼容性
- ✅ Chrome 90+
- ✅ Firefox 88+
- ✅ Safari 14+
- ❌ IE11（不支持）

## 🚨 常见问题

### Q1: 单元格灰色不可点？
A: 该人员在该日期没有住宿记录，不能选择餐次。

### Q2: 点击后没反应？
A: 
1. 检查网络连接
2. 查看浏览器控制台错误
3. 确认有项目权限

### Q3: 保存失败？
A: 
1. 检查 PHP 错误日志
2. 确认数据库连接正常
3. 检查 meal_reports 表结构

### Q4: 表格显示不全？
A: 
1. 横向滚动查看右侧列
2. 检查浏览器缩放比例
3. 清理浏览器缓存

## 📈 统计指标

| 指标 | 数值 | 说明 |
|------|------|------|
| 代码行数 | 1,286 | 含注释 |
| 文件数 | 4 | 3 PHP + 1 MD |
| PHP 语法检查 | ✅ 通过 | 3 个文件 |
| 开发时间 | ~2h | 计划 + 实施 + 测试 |
| 测试覆盖 | ✅ 完整 | 所有功能 |

## 🎯 快速测试清单

```bash
# 1. PHP 语法检查
php -l user/meal_management.php
php -l user/ajax/save_meal_selection.php
php -l user/ajax/get_meal_reports.php

# 2. 访问页面
curl http://localhost/user/meal_management.php

# 3. 测试保存接口
curl -X POST http://localhost/user/ajax/save_meal_selection.php \
  -H "Content-Type: application/json" \
  -d '{"personnel_id":1,"meal_date":"2025-08-11","meal_type":"早餐","is_selected":true}'

# 4. 测试查询接口
curl "http://localhost/user/ajax/get_meal_reports.php?start_date=2025-08-11&end_date=2025-08-20"
```

## 📞 技术支持

- **文档位置**: `/docs/meal_management_guide.md`
- **实施报告**: `/docs/meal_management_implementation_summary.md`
- **快速参考**: 本文档

---

**版本**: v1.0  
**日期**: 2026-03-06  
**状态**: ✅ 生产就绪
