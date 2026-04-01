# 报餐管理页面菜单集成 - 使用说明

## 功能概述

将新开发的"报餐管理"页面（`meal_management.php`）添加到用户端头部导航菜单的"报餐"下拉菜单中，使用户可以通过导航栏快速访问该页面。

## 修改内容

### 1. 文件位置
- **修改文件：** `/www/wwwroot/livegig.cn/user/includes/header.php`
- **修改位置：** 第 103-141 行（报餐管理下拉菜单区域）

### 2. 具体变更

#### (1) 更新 active_page 检测数组
```php
// 修改前
in_array($active_page ?? '', ['meals', 'meals_new', 'meals_statistics', 'batch_meal_order', 'meal_allowance'])

// 修改后
in_array($active_page ?? '', ['meals', 'meals_new', 'meals_statistics', 'batch_meal_order', 'meal_allowance', 'meal_management'])
```

**说明：** 添加 `'meal_management'` 到检测数组，使页面激活状态正确显示。

#### (2) 添加新的菜单项
在报餐下拉菜单顶部添加新的菜单项：

```php
<li>
    <a class="dropdown-item <?php echo ($active_page ?? '') === 'meal_management' ? 'active' : ''; ?>" 
       href="./meal_management.php">
        <i class="bi bi-calendar-check"></i> 报餐管理
    </a>
</li>
```

**菜单位置：** 位于"报餐"下拉菜单的第一项

### 3. 菜单结构

修改后的完整菜单结构如下：

```
📋 报餐 (下拉菜单)
├── 🗓️ 报餐管理          ← 新增（链接到 meal_management.php）
├── 📅 报餐              ← 原有（链接到 batch_meal_order.php）
├── 📊 报餐统计          ← 原有（链接到 meals_new.php）
├── 📈 报餐记录          ← 原有（链接到 meals_statistics.php）
└── 💰 餐费补助明细      ← 原有（链接到 meal_allowance.php）
```

## 视觉效果

### 菜单样式
- ✅ 使用 Bootstrap Icons 图标 `bi-calendar-check`
- ✅ 与其他菜单项保持一致的样式
- ✅ 支持激活状态高亮显示
- ✅ 鼠标悬停效果统一

### 激活状态
当访问 `meal_management.php` 页面时：
- 导航栏"报餐"下拉按钮高亮
- "报餐管理"菜单项高亮显示
- 使用 `.active` 类实现视觉反馈

## 使用方法

### 方式一：通过下拉菜单
1. 登录系统
2. 点击顶部导航栏的 **"📋 报餐"**
3. 在下拉菜单中选择 **"🗓️ 报餐管理"**

### 方式二：直接访问
```
http://your-domain.com/user/meal_management.php
```

## 技术实现

### PHP 代码逻辑

#### 1. Active 状态检测
```php
<?php echo in_array($active_page ?? '', ['meal_management']) ? 'active' : ''; ?>
```

**工作原理：**
- 检查 `$active_page` 变量是否等于 `'meal_management'`
- 如果匹配，添加 `active` CSS 类
- 实现当前页面的视觉高亮

#### 2. 图标使用
```html
<i class="bi bi-calendar-check"></i>
```

**图标含义：** 日历 + 勾选 = 报餐管理

### CSS 样式
```css
.nav-link.active {
    background-color: rgba(255, 255, 255, 0.2) !important;
    font-weight: 600 !important;
}

.dropdown-item.active {
    background-color: rgba(13, 110, 253, 0.1);
    color: #0d6efd;
    font-weight: 600;
}
```

## 兼容性

### 向后兼容
- ✅ 不影响现有菜单项
- ✅ 不改变其他页面功能
- ✅ 保持原有导航结构

### 浏览器支持
- ✅ Chrome 90+
- ✅ Firefox 88+
- ✅ Safari 14+
- ✅ Edge 90+

## 测试验证

### 测试场景

#### 场景 1：访问报餐管理页面
1. 点击"报餐" → "报餐管理"
2. 验证跳转到 `meal_management.php`
3. 验证"报餐管理"菜单项高亮

#### 场景 2：从其他页面切换
1. 从"报餐统计"页面点击"报餐管理"
2. 验证页面正确切换
3. 验证高亮状态正确更新

#### 场景 3：刷新页面
1. 在报餐管理页面刷新
2. 验证菜单高亮状态保持
3. 验证页面状态正常

### 预期结果

✅ **导航栏显示：**
- "报餐"下拉按钮可见
- "报餐管理"菜单项可见
- 图标和文字正确显示

✅ **交互功能：**
- 点击菜单项可跳转
- 跳转后页面加载正常
- 激活状态正确显示

✅ **视觉样式：**
- 菜单项样式统一
- 悬停效果正常
- 高亮状态明显

## 注意事项

### 1. Active Page 变量
确保在每个页面顶部设置正确的 `$active_page` 变量：

```php
// 在 meal_management.php 中添加
$active_page = 'meal_management';
```

### 2. 路径问题
- 使用相对路径 `./meal_management.php`
- 确保路径相对于 header.php 的位置
- 避免使用绝对路径导致环境切换问题

### 3. 权限控制
如果需要限制访问，可以在 `meal_management.php` 中添加权限检查：

```php
if (!hasPermission('meal_management')) {
    die('无权访问此页面');
}
```

## 相关文件

### 主要文件
- `/www/wwwroot/livegig.cn/user/includes/header.php` - 导航菜单
- `/www/wwwroot/livegig.cn/user/meal_management.php` - 报餐管理页面

### 相关文档
- `/www/wwwroot/livegig.cn/docs/date_multiselect_control.md` - 日期多选功能
- `/www/wwwroot/livegig.cn/docs/date_column_background.md` - 日期列背景色

## 常见问题

### Q1: 菜单项显示了但不高亮？
**A:** 检查 `$active_page` 变量是否正确设置：
```php
$active_page = 'meal_management';
```

### Q2: 点击菜单项无法跳转？
**A:** 检查文件路径是否正确：
- 确认 `meal_management.php` 存在
- 确认路径为 `./meal_management.php`

### Q3: 菜单样式与其他项不一致？
**A:** 检查 HTML 结构和 CSS 类名：
- 确保使用相同的类名结构
- 检查图标类名是否正确

### Q4: 如何在管理员菜单中也添加？
**A:** 类似地修改 `/admin/includes/header.php`：
```php
<li>
    <a class="dropdown-item" href="../user/meal_management.php">
        <i class="bi bi-calendar-check"></i> 报餐管理
    </a>
</li>
```

---

**最后更新时间：** 2024-01-XX  
**版本：** v1.0  
**修改文件：** `/www/wwwroot/livegig.cn/user/includes/header.php`
