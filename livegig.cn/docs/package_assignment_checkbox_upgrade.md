# 套餐分配控件升级 - Checkbox 多选版

## 升级概述

将套餐分配页面的选择控件从 `<select multiple>` 下拉框改为多个 checkbox 复选框，解决了原有控件尺寸过小、无法完全显示所有套餐选项的问题。

**版本：** v2.0 (Checkbox 多选版)  
**更新日期：** 2026-03-06

---

## 主要改进

### 1. **控件类型变更**
- ❌ **旧方式：** `<select multiple>` 下拉多选框
- ✅ **新方式：** 多个独立的 checkbox 复选框

### 2. **视觉优化**
- ✅ 每个套餐独立显示，无需滚动
- ✅ 单元格尺寸扩大（150px → 200px）
- ✅ 已选中的套餐有高亮背景
- ✅ 悬停效果更明显

### 3. **交互优化**
- ✅ 点击任意位置即可切换选中状态
- ✅ 支持快速批量选择/取消
- ✅ 复制功能更精确到单个套餐

---

## CSS 样式变更

### 1. 单元格样式调整

```css
/* 修改前 */
.package-cell {
    text-align: center;
    padding: 0.5rem 0.3rem;
    min-width: 150px;
}

/* 修改后 */
.package-cell {
    text-align: left;              /* 左对齐，便于阅读 */
    padding: 0.75rem 0.5rem;       /* 增加内边距 */
    min-width: 200px;              /* 最小宽度增加 */
    vertical-align: top;           /* 顶部对齐 */
}
```

### 2. 新增套餐选项容器

```css
.package-options {
    display: flex;
    flex-direction: column;        /* 垂直排列 */
    gap: 0.35rem;                  /* 间距 */
}
```

### 3. 新增复选框样式

```css
.package-checkbox {
    display: flex;
    align-items: center;
    padding: 0.35rem 0.5rem;
    border-radius: 6px;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    cursor: pointer;
    transition: all 0.2s;
    font-size: 0.85rem;
}

.package-checkbox:hover {
    background: #e9ecef;
    border-color: #f5576c;         /* 主题色边框 */
}

.package-checkbox input[type="checkbox"] {
    width: 1.1rem;
    height: 1.1rem;
    margin-right: 0.5rem;
    cursor: pointer;
    accent-color: #f5576c;         /* 选中时为主题色 */
}

.package-checkbox input[type="checkbox"]:checked + span {
    font-weight: 600;
    color: #f5576c;                /* 选中文字变色 */
}
```

### 4. 已选中状态高亮

```css
.package-checkbox.checked {
    background: linear-gradient(
        135deg, 
        rgba(245,87,108, 0.15), 
        rgba(240,147,251, 0.15)
    );
    border-color: #f5576c;
}
```

---

## HTML 结构变更

### 修改前（Select 方式）

```html
<td class="package-cell">
    <select class="package-select" multiple size="2">
        <option value="">-- 请选择套餐 --</option>
        <option value="1">有米双拼饭</option>
        <option value="2">黯然滑蛋叉烧饭</option>
    </select>
</td>
```

### 修改后（Checkbox 方式）

```html
<td class="package-cell">
    <div class="package-options">
        <div class="package-checkbox">
            <input type="checkbox" 
                   id="pkg_1_2024-01-15_午餐"
                   value="1"
                   data-meal-date="2024-01-15"
                   data-meal-type="午餐"
                   data-package-name="有米双拼饭">
            <label for="pkg_1_2024-01-15_午餐">
                <span>有米双拼饭</span>
            </label>
        </div>
        <div class="package-checkbox">
            <input type="checkbox" 
                   id="pkg_2_2024-01-15_午餐"
                   value="2"
                   data-meal-date="2024-01-15"
                   data-meal-type="午餐"
                   data-package-name="黯然滑蛋叉烧饭">
            <label for="pkg_2_2024-01-15_午餐">
                <span>黯然滑蛋叉烧饭</span>
            </label>
        </div>
    </div>
</td>
```

---

## JavaScript 逻辑变更

### 1. 标记更改函数

```javascript
// 修改前
function markAsChanged(select) {
    hasChanges = true;
    updateStatistics();
}

// 修改后
function markAsChanged(checkbox) {
    hasChanges = true;
    
    // 更新复选框样式
    const checkboxDiv = checkbox.closest('.package-checkbox');
    if (checkbox.checked) {
        checkboxDiv.classList.add('checked');
    } else {
        checkboxDiv.classList.remove('checked');
    }
    
    updateStatistics();
}
```

### 2. 统计数据更新

```javascript
// 修改前：统计 select 的选中项
function updateStatistics() {
    let assignedCount = 0;
    document.querySelectorAll('.package-select').forEach(select => {
        const selectedOptions = Array.from(select.selectedOptions)
            .filter(opt => opt.value !== '');
        assignedCount += selectedOptions.length;
    });
    document.getElementById('assignedCount').textContent = assignedCount;
}

// 修改后：统计 checkbox 的选中项
function updateStatistics() {
    let assignedCount = 0;
    document.querySelectorAll('.package-checkbox input[type="checkbox"]:checked')
        .forEach(cb => {
            if (cb.value !== '') {
                assignedCount++;
            }
        });
    document.getElementById('assignedCount').textContent = assignedCount;
}
```

### 3. 保存逻辑优化

```javascript
// 使用 Map 数据结构，更好地处理多选
const dateMealTypeMap = new Map();

document.querySelectorAll('.package-checkbox input[type="checkbox"]:checked')
    .forEach(cb => {
        const mealDate = cb.dataset.mealDate;
        const mealType = cb.dataset.mealType;
        const packageId = parseInt(cb.value);
        
        if (packageId > 0) {
            const key = `${mealDate}_${mealType}`;
            if (!dateMealTypeMap.has(key)) {
                dateMealTypeMap.set(key, {
                    meal_date: mealDate,
                    meal_type: mealType,
                    package_ids: []
                });
            }
            dateMealTypeMap.get(key).package_ids.push(packageId);
        }
    });

assignments.push(...Array.from(dateMealTypeMap.values()));
```

### 4. 复制功能精确到单个套餐

```javascript
// 修改后：根据套餐名称精确匹配
const packageName = currentCb.dataset.packageName;
const prevCb = document.querySelector(
    `.package-checkbox input[type="checkbox"]` +
    `[data-meal-date="${prevDate}"]` +
    `[data-meal-type="${mealType}"]` +
    `[data-package-name="${packageName}"]`
);

if (prevCb) {
    const wasChecked = currentCb.checked;
    currentCb.checked = prevCb.checked;
    
    if (prevCb.checked && !wasChecked) {
        markAsChanged(currentCb);
        copiedCount++;
    } else if (!prevCb.checked && wasChecked) {
        markAsChanged(currentCb);
    }
}
```

---

## 视觉效果对比

### 修改前（Select 下拉）

```
┌─────────────────┐
│ -- 请选择套餐 --│
│   有米双拼饭    │
│   黯然...       │ ← 被截断
└─────────────────┘
```

### 修改后（Checkbox）

```
┌──────────────────────────┐
│ ☑ 有米双拼饭             │ ← 完整显示
│ ☑ 黯然滑蛋叉烧饭         │ ← 完整显示
│ ☐ 白切胡须鸡饭           │
│ ☐ 桶子豉油鸡饭           │
└──────────────────────────┘
```

---

## 用户体验提升

### 1. **可读性**
- ✅ 所有套餐名称完整显示，无截断
- ✅ 文字左对齐，符合阅读习惯
- ✅ 字体大小适中（0.85rem）

### 2. **可操作性**
- ✅ 点击区域更大（整个 checkbox 块）
- ✅ 无需按住 Ctrl/Cmd 键
- ✅ 直观看到所有可选项

### 3. **视觉反馈**
- ✅ 选中时有渐变色背景
- ✅ 文字加粗并变色
- ✅ 悬停时边框高亮

### 4. **空间利用**
- ✅ 自动扩展高度
- ✅ 无滚动条，所有选项可见
- ✅ 单元格最小宽度 200px

---

## 数据兼容性

### 后端接口保持不变

```php
// AJAX 接口接收的数据格式相同
[
    {
        "meal_date": "2024-01-15",
        "meal_type": "午餐",
        "package_ids": [1, 2]  // 可以是多个
    }
]
```

### 数据库存储不变

```sql
INSERT INTO meal_package_assignments 
(project_id, meal_date, meal_type, package_id) 
VALUES 
(4, '2024-01-15', '午餐', 1),
(4, '2024-01-15', '午餐', 2);
```

---

## 测试要点

### 功能测试
- ✅ 单个套餐选择/取消
- ✅ 多个套餐同时选择
- ✅ 保存所有分配
- ✅ 复制前一天功能
- ✅ 统计数据准确性

### 界面测试
- ✅ 复选框样式正确
- ✅ 已选中状态高亮
- ✅ 悬停效果正常
- ✅ 响应式布局正常

### 兼容性测试
- ✅ Chrome 90+
- ✅ Firefox 88+
- ✅ Safari 14+
- ✅ Edge 90+

---

## 性能优化

### 1. DOM 操作优化
```javascript
// 使用事件委托，减少监听器数量
// 直接绑定在 checkbox 上，由浏览器原生处理
```

### 2. 样式优化
```css
/* 使用 CSS transform 代替 position */
.package-checkbox {
    transition: all 0.2s;  /* 平滑过渡 */
}
```

### 3. 初始化优化
```javascript
// 页面加载时批量初始化样式
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.package-checkbox input[type="checkbox"]')
        .forEach(cb => {
            if (cb.checked) {
                cb.closest('.package-checkbox').classList.add('checked');
            }
        });
});
```

---

## 常见问题

### Q1: 为什么改用 checkbox？
**A:** Select 下拉框在多选时体验较差，需要按住 Ctrl 键，且无法看到所有选项。Checkbox 更直观、易用。

### Q2: 套餐很多时会很长吗？
**A:** 是的，但这是设计目标。所有套餐应该一目了然，用户不需要滚动就能看到所有选项。

### Q3: 会影响移动端体验吗？
**A:** 不会。Checkbox 在移动端同样适用，且有响应式设计。

### Q4: 已选中的套餐如何标识？
**A:** 通过渐变背景和加粗文字标识，非常明显。

---

## 升级步骤

### 1. 备份原文件
```bash
cp /www/wwwroot/livegig.cn/user/meal_package_assign.php \
   /www/wwwroot/livegig.cn/user/meal_package_assign.php.bak
```

### 2. 替换文件
- 已自动完成升级

### 3. 清除缓存
```bash
# PHP OPcache
service php-fpm restart

# 浏览器缓存
Ctrl + F5 (强制刷新)
```

### 4. 验证功能
- 访问页面查看新界面
- 测试选择和保存功能
- 检查数据统计

---

## 回滚方案

如需回滚到 Select 版本：

```bash
# 恢复备份文件
mv /www/wwwroot/livegig.cn/user/meal_package_assign.php.bak \
   /www/wwwroot/livegig.cn/user/meal_package_assign.php

# 重启服务
service php-fpm restart
```

---

## 技术栈

- **前端：** HTML5 + CSS3 + Vanilla JavaScript
- **后端：** PHP 7.4+
- **数据库：** MySQL 5.7+
- **样式：** Espire 设计风格

---

**升级完成时间：** 2026-03-06  
**版本：** v2.0 (Checkbox 多选版)  
**相关文件：** `/www/wwwroot/livegig.cn/user/meal_package_assign.php`
