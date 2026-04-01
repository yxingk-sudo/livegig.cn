# 菜单激活状态修复报告

## 问题描述
当访问 `/admin/personnel_statistics.php` 页面时，左侧导航菜单中的"人员管理"和"人员统计"两个菜单项同时被激活高亮显示，这是不正确的。

## 根本原因分析

在 `sidebar.php` 中，"人员管理"菜单项的激活条件设置为：
```php
<?php echo ($current_page == 'personnel_enhanced.php' || $current_editing_page == 'personnel_statistics') ? 'active' : 'text-dark'; ?>
```

同时，在 `personnel_statistics.php` 中设置了：
```php
$current_editing_page = 'personnel_statistics';
```

这导致当用户访问 `personnel_statistics.php` 时：
1. `$current_page == 'personnel_statistics.php'` （符合"人员统计"的激活条件）
2. `$current_editing_page == 'personnel_statistics'` （同时符合"人员管理"的激活条件）

因此两个菜单项都被激活了。

## 修复方案

### 1. 移除不必要的 $current_editing_page 变量设置

**修改文件：`/admin/includes/sidebar.php`**

将第 18-29 行的菜单识别逻辑改为只处理项目相关页面：

```php
if (empty($current_editing_page)) {
    // 根据当前页面判断属于哪个菜单组
    $current_editing_page = '';
    
    // 项目相关页面
    if (in_array($current_page, ['project_edit.php', 'project_add.php'])) {
        $current_editing_page = 'projects';
    }
    // 其他页面的特殊处理可以在这里添加
}
```

### 2. 修正"人员管理"菜单项的激活条件

**修改文件：`/admin/includes/sidebar.php`**

将第 243 行的激活条件改为只检查当前页面：

```php
<?php echo $current_page == 'personnel_enhanced.php' ? 'active' : 'text-dark'; ?>
```

这样：
- 当访问 `personnel_enhanced.php` 时，只有"人员管理"被激活
- 当访问 `personnel_statistics.php` 时，只有"人员统计"被激活

## 修改详情

### 修改文件列表
- `/admin/includes/sidebar.php`：修改了两处激活逻辑

### 修改前后对比

#### 修改 1：菜单识别逻辑（第 25-26 行）
**之前：**
```php
if (in_array($current_page, ['project_edit.php', 'project_add.php', 'personnel_statistics.php'])) {
    $current_editing_page = 'personnel_statistics';
}
```

**之后：**
```php
if (in_array($current_page, ['project_edit.php', 'project_add.php'])) {
    $current_editing_page = 'projects';
}
```

#### 修改 2："人员管理"菜单激活条件（第 243 行）
**之前：**
```php
<?php echo ($current_page == 'personnel_enhanced.php' || $current_editing_page == 'personnel_statistics') ? 'active' : 'text-dark'; ?>
```

**之后：**
```php
<?php echo $current_page == 'personnel_enhanced.php' ? 'active' : 'text-dark'; ?>
```

## 修复后的效果

### 访问 /admin/personnel_enhanced.php 时
- ✅ "人员管理"菜单项：激活（蓝色背景）
- ✅ "人员统计"菜单项：非激活
- ✅ "项目组"菜单组：自动展开

### 访问 /admin/personnel_statistics.php 时
- ✅ "人员管理"菜单项：非激活
- ✅ "人员统计"菜单项：激活（蓝色背景）
- ✅ "项目组"菜单组：自动展开

### 访问其他项目相关页面时
- ✅ 项目编辑页面：只有"项目管理"被激活
- ✅ 项目添加页面：只有"项目管理"被激活
- ✅ 其他菜单项：正常工作

## 验证方式

1. 访问 `/admin/personnel_enhanced.php`，验证只有"人员管理"被激活
2. 访问 `/admin/personnel_statistics.php`，验证只有"人员统计"被激活
3. 返回 `/admin/personnel_enhanced.php`，验证"人员管理"重新被激活
4. 访问其他相关页面，验证菜单激活状态正确

## PHP 语法检查
✅ 无语法错误

## 影响范围
- ✅ 仅修改菜单激活逻辑
- ✅ 不影响页面功能
- ✅ 不影响其他菜单项的激活
- ✅ 不影响菜单组的展开/折叠

## 完成时间
2026-02-27

## 回滚方法
如需回滚，恢复原始的菜单激活条件：
```bash
git checkout /www/wwwroot/livegig.cn/admin/includes/sidebar.php
```

