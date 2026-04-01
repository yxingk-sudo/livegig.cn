## 0. 文档信息

- **文档版本**: 1.0
- **最后更新**: 2025-04-05
- **作者**: Qoder AI Assistant
- **相关文件**: [/user/transport_enhanced.php](file:///www/wwwroot/livegig.cn/user/transport_enhanced.php), [/user/quick_transport.php](file:///www/wwwroot/livegig.cn/user/quick_transport.php)

# 表单布局调整设计文档

## 1. 概述

本文档描述了对 [/user/transport_enhanced.php](file:///www/wwwroot/livegig.cn/user/transport_enhanced.php) 文件的表单布局调整需求，将乘客选择部分移到右侧，其他输入元素放在左侧，并添加类似 [/user/quick_transport.php](file:///www/wwwroot/livegig.cn/user/quick_transport.php) 的"已选乘客"摘要显示功能。

## 2. 设计目标

- 调整表单布局，将乘客选择区域放在右侧，其他输入元素放在左侧
- 添加"已选乘客"摘要容器，显示已选择乘客的姓名和部门信息
- 摘要容器默认隐藏，当有乘客被选中时显示
- 保持现有功能完整性

## 3. 现有功能分析

### 3.1 [/user/transport_enhanced.php](file:///www/wwwroot/livegig.cn/user/transport_enhanced.php) 现有布局

当前文件采用垂直布局，每个行程包含以下元素：
- 时间设置（出行日期、交通类型、时间）
- 地点设置（出发地点、目的地点）
- 车型需求
- 特殊要求
- 乘客选择区域

具体结构如下：
1. 出行日期、交通类型、时间等设置在顶部
2. 出发地点和目的地点选择区域
3. 车型需求选择区域
4. 特殊要求输入区域
5. 乘客选择区域，包含搜索和筛选功能

### 3.2 [/user/quick_transport.php](file:///www/wwwroot/livegig.cn/user/quick_transport.php) 的已选乘客摘要功能

该文件实现了已选乘客摘要功能：
- 在乘客选择区域下方显示摘要容器
- 摘要以徽章形式展示已选乘客的姓名和部门
- 格式为"姓名（部门）"
- 默认隐藏，当有乘客被选中时显示

具体实现特点：
1. 使用徽章样式展示已选乘客信息
2. 显示乘客姓名和所属部门
3. 动态更新显示，实时反映选择状态
4. 未选择时隐藏，选择后显示

## 4. 设计方案

### 4.1 布局调整

将每个行程的表单布局调整为左右两列：
- 左侧列（7列）：包含时间设置、地点设置、车型需求、特殊要求等输入元素
- 右侧列（5列）：包含乘客选择区域和已选乘客摘要

具体调整如下：
1. 将现有的单一垂直布局结构调整为 Bootstrap 的网格系统布局
2. 左侧区域包含：出行日期、交通类型、时间设置、出发地点、目的地点、车型需求、特殊要求等输入元素
3. 右侧区域包含：乘客搜索筛选区域、乘客选择区域、已选乘客摘要显示区域
4. 左右两列在不同屏幕尺寸下自动调整宽度，确保响应式显示效果

### 4.2 已选乘客摘要功能实现

#### 4.2.1 HTML 结构
在每个行程的乘客选择区域下方添加摘要容器：
```html
<div id="selectedSummary_0" class="mt-2" style="display:none;">
    <div class="small text-muted mb-1">已选乘客：</div>
    <div id="selectedSummaryList_0" class="d-flex flex-wrap gap-1"></div>
</div>
```

对于动态添加的行程，使用对应的索引值：
```html
<div id="selectedSummary_${routeIndex}" class="mt-2" style="display:none;">
    <div class="small text-muted mb-1">已选乘客：</div>
    <div id="selectedSummaryList_${routeIndex}" class="d-flex flex-wrap gap-1"></div>
</div>
```

#### 4.2.2 JavaScript 实现
实现 `updateSelectedSummary()` 函数，用于更新摘要显示：
```javascript
function updateSelectedSummary(routeIndex) {
    // 获取摘要容器和列表元素
    const container = document.getElementById(`selectedSummary_${routeIndex}`);
    const list = document.getElementById(`selectedSummaryList_${routeIndex}`);
    
    // 检查元素是否存在
    if (!container || !list) return;

    // 清空现有内容
    list.innerHTML = '';

    // 获取当前行程的所有选中乘客复选框
    const checkboxes = document.querySelectorAll(`input[name="routes[${routeIndex}][personnel_ids][]"]:checked`);
    
    // 如果没有选中的乘客，隐藏容器并返回
    if (checkboxes.length === 0) {
        container.style.display = 'none';
        return;
    }

    // 为每个选中的乘客创建徽章
    checkboxes.forEach(cb => {
        // 获取包含乘客信息的元素
        const item = cb.closest('.personnel-item');
        if (!item) return;
        
        // 获取标签元素
        const nameLabel = item.querySelector('.form-check-label');
        if (!nameLabel) return;
        
        // 提取姓名（从加粗的span中获取）
        const nameEl = nameLabel.querySelector('.fw-bold');
        const name = (nameEl ? nameEl.textContent : '').trim();
        
        // 提取部门信息
        const deptEl = nameLabel.querySelector('.text-muted');
        const dept = (deptEl ? deptEl.textContent : '').trim() || '未分配部门';

        // 创建徽章元素
        const tag = document.createElement('span');
        tag.className = 'badge rounded-pill me-1 mb-1';
        tag.textContent = `${name}（${dept}）`;
        list.appendChild(tag);
    });

    // 显示容器
    container.style.display = 'block';
}
```

#### 4.2.3 事件绑定
在乘客选择发生变化时调用 `updateSelectedSummary()` 函数：

1. 在 `setupPersonnelSelection` 函数中的 `updatePersonnelCount` 函数内：
```javascript
function setupPersonnelSelection(container, index) {
    // ... 现有代码 ...
    
    // 更新计数和乘客数量
    function updatePersonnelCount() {
        const selectedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
        if (selectedCountSpan) {
            selectedCountSpan.textContent = selectedCount;
        }
        if (passengerCountInput) {
            passengerCountInput.value = selectedCount || 0;
        }
        
        // 更新新的乘客数量显示
        const passengerDisplay = container.querySelector('.selected-count-display');
        if (passengerDisplay) {
            passengerDisplay.textContent = selectedCount || 0;
        }
        
        // 更新已选乘客摘要
        updateSelectedSummary(index);
    }
    
    // ... 现有代码 ...
}
```

2. 在页面加载完成后初始化时调用：
```javascript
// 页面加载完成后初始化第一个行程的地点选项和交换按钮显示
document.addEventListener('DOMContentLoaded', function() {
    console.log('页面加载完成，开始初始化');
    // 等待一小段时间确保DOM完全渲染
    setTimeout(function() {
        updateLocationOptions(0);
        updateSelectedSummary(0); // 初始化摘要显示
        console.log('初始化第一个行程完成');
    }, 100);
    
    // ... 现有代码 ...
});
```

3. 在动态添加新行程时调用：
```javascript
function addRoute() {
    // ... 现有代码 ...
    
    container.appendChild(newRoute);
    routeIndex++;
    
    // 为新添加的行程绑定人员选择事件
    setupPersonnelSelection(newRoute, routeIndex - 1);
    // 初始化新行程的地点选项
    updateLocationOptions(routeIndex - 1);
    // 初始化新行程的摘要显示
    updateSelectedSummary(routeIndex - 1);
}
```

### 4.3 样式调整

添加必要的CSS样式以确保摘要徽章的显示效果：
```css
/* 已选乘客摘要徽章样式 */
#selectedSummaryList_0 .badge,
#selectedSummaryList_1 .badge,
#selectedSummaryList_2 .badge {
    background-color: #fff3cd; /* 浅黄背景 */
    color: #a15c00;            /* 深橙文字 */
    border: 1px solid #ffd78a; /* 浅橙边框 */
    font-weight: 500;
}

/* 响应式设计样式 */
@media (max-width: 768px) {
    .route-row .row {
        flex-direction: column;
    }
    
    .route-row .col-md-7,
    .route-row .col-md-5 {
        width: 100%;
    }
}
```

## 5. 实现细节

### 5.1 HTML 结构修改

将每个行程的表单结构调整为左右两列布局：

1. 左侧列（7列）包含：
   - 时间设置区域
   - 地点设置区域
   - 车型需求区域
   - 特殊要求输入区域

2. 右侧列（5列）包含：
   - 乘客搜索和筛选区域
   - 乘客选择区域
   - 已选乘客摘要容器

在乘客选择区域下方添加摘要容器：
```html
<!-- 已选乘客摘要容器 -->
<div id="selectedSummary_0" class="mt-2" style="display:none;">
    <div class="small text-muted mb-1">已选乘客：</div>
    <div id="selectedSummaryList_0" class="d-flex flex-wrap gap-1"></div>
</div>
```

对于动态添加的行程，使用对应的索引值：
```html
<!-- 已选乘客摘要容器 -->
<div id="selectedSummary_${routeIndex}" class="mt-2" style="display:none;">
    <div class="small text-muted mb-1">已选乘客：</div>
    <div id="selectedSummaryList_${routeIndex}" class="d-flex flex-wrap gap-1"></div>
</div>
```

### 5.2 左右侧布局实现

将现有表单结构调整为左右两列布局，使用 Bootstrap 的网格系统：

```html
<div class="row">
    <!-- 左侧列：输入元素 -->
    <div class="col-md-7">
        <!-- 时间设置 -->
        <div class="row g-2">
            <div class="col-md-4">
                <label class="form-label">出行日期 <span class="text-danger">*</span></label>
                <input type="date" name="routes[0][travel_date]" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
            </div>
            <!-- 其他时间设置元素 -->
        </div>
        
        <!-- 地点设置 -->
        <div class="row g-2">
            <!-- 出发地点和目的地点元素 -->
        </div>
        
        <!-- 车型需求 -->
        <div class="row g-2">
            <!-- 车型需求元素 -->
        </div>
        
        <!-- 特殊要求 -->
        <div class="row g-2">
            <!-- 特殊要求元素 -->
        </div>
    </div>
    
    <!-- 右侧列：乘客选择和摘要 -->
    <div class="col-md-5">
        <!-- 乘客搜索和筛选 -->
        <div class="search-filter-row">
            <!-- 搜索和筛选元素 -->
        </div>
        
        <!-- 乘客选择区域 -->
        <div class="border rounded" style="max-height: 140px; overflow-y: auto; padding: 0.5rem 0.3rem 0.5rem 2rem;">
            <!-- 人员选项 -->
        </div>
        
        <!-- 已选乘客摘要 -->
        <div id="selectedSummary_0" class="mt-2" style="display:none;">
            <div class="small text-muted mb-1">已选乘客：</div>
            <div id="selectedSummaryList_0" class="d-flex flex-wrap gap-1"></div>
        </div>
    </div>
</div>
```

### 5.3 JavaScript 函数实现

实现 `updateSelectedSummary()` 函数，根据行程索引获取对应的容器，并更新容器内的乘客信息。

```javascript
function updateSelectedSummary(routeIndex) {
    // 获取摘要容器和列表元素
    const container = document.getElementById(`selectedSummary_${routeIndex}`);
    const list = document.getElementById(`selectedSummaryList_${routeIndex}`);
    
    // 检查元素是否存在
    if (!container || !list) return;

    // 清空现有内容
    list.innerHTML = '';

    // 获取当前行程的所有选中乘客复选框
    const checkboxes = document.querySelectorAll(`input[name="routes[${routeIndex}][personnel_ids][]"]:checked`);
    
    // 如果没有选中的乘客，隐藏容器并返回
    if (checkboxes.length === 0) {
        container.style.display = 'none';
        return;
    }

    // 为每个选中的乘客创建徽章
    checkboxes.forEach(cb => {
        // 获取包含乘客信息的元素
        const item = cb.closest('.personnel-item');
        if (!item) return;
        
        // 获取标签元素
        const nameLabel = item.querySelector('.form-check-label');
        if (!nameLabel) return;
        
        // 提取姓名（从加粗的span中获取）
        const nameEl = nameLabel.querySelector('.fw-bold');
        const name = (nameEl ? nameEl.textContent : '').trim();
        
        // 提取部门信息
        const deptEl = nameLabel.querySelector('.text-muted');
        const dept = (deptEl ? deptEl.textContent : '').trim() || '未分配部门';

        // 创建徽章元素
        const tag = document.createElement('span');
        tag.className = 'badge rounded-pill me-1 mb-1';
        tag.textContent = `${name}（${dept}）`;
        list.appendChild(tag);
    });

    // 显示容器
    container.style.display = 'block';
}
```

### 5.4 事件处理

在乘客选择变化时调用摘要更新函数，确保信息同步。这需要在以下位置添加调用：

1. 在 `setupPersonnelSelection` 函数中的 `updatePersonnelCount` 函数内：
```javascript
function setupPersonnelSelection(container, index) {
    // ... 现有代码 ...
    
    // 更新计数和乘客数量
    function updatePersonnelCount() {
        const selectedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
        if (selectedCountSpan) {
            selectedCountSpan.textContent = selectedCount;
        }
        if (passengerCountInput) {
            passengerCountInput.value = selectedCount || 0;
        }
        
        // 更新新的乘客数量显示
        const passengerDisplay = container.querySelector('.selected-count-display');
        if (passengerDisplay) {
            passengerDisplay.textContent = selectedCount || 0;
        }
        
        // 更新已选乘客摘要
        updateSelectedSummary(index);
    }
    
    // ... 现有代码 ...
}
```

2. 在页面加载完成后初始化时调用：
```javascript
// 页面加载完成后初始化第一个行程的地点选项和交换按钮显示
document.addEventListener('DOMContentLoaded', function() {
    console.log('页面加载完成，开始初始化');
    // 等待一小段时间确保DOM完全渲染
    setTimeout(function() {
        updateLocationOptions(0);
        updateSelectedSummary(0); // 初始化摘要显示
        console.log('初始化第一个行程完成');
    }, 100);
    
    // ... 现有代码 ...
});
```

3. 在动态添加新行程时调用：
```javascript
function addRoute() {
    // ... 现有代码 ...
    
    container.appendChild(newRoute);
    routeIndex++;
    
    // 为新添加的行程绑定人员选择事件
    setupPersonnelSelection(newRoute, routeIndex - 1);
    // 初始化新行程的地点选项
    updateLocationOptions(routeIndex - 1);
    // 初始化新行程的摘要显示
    updateSelectedSummary(routeIndex - 1);
}
```

## 6. 响应式设计

为确保在不同设备上的显示效果，需要实现响应式设计：
1. 在大屏幕设备上采用左右两列布局
2. 在小屏幕设备上自动调整为垂直堆叠布局
3. 确保各元素在不同屏幕尺寸下的可读性和可操作性

## 7. 测试要点

1. 验证表单布局调整后各元素的显示效果
2. 测试乘客选择功能是否正常工作
3. 验证已选乘客摘要功能：
   - 默认隐藏摘要容器
   - 选择乘客后正确显示摘要
   - 取消选择后正确更新摘要
   - 动态添加的行程也能正确显示摘要
4. 确保在不同屏幕尺寸下的响应式显示效果

## 8. 兼容性考虑

- 保持与现有浏览器的兼容性
- 确保在移动设备上的显示效果
- 不影响现有的表单提交功能

## 9. 风险评估

- 布局调整可能影响现有样式，需要充分测试
- JavaScript 函数的实现需要考虑性能问题
- 动态添加行程的功能需要确保摘要功能正常工作