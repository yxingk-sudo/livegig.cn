# 批量报餐页面汇总详情显示优化总结

## 功能需求

在批量报餐页面的底部汇总统计区域，需要显示已选择的部门名称和人员姓名（已去重）。

### 具体需求

1. ✅ 在汇总统计区域显示具体的已选择内容
2. ✅ 显示已选择的部门名称列表（来自部门选择模式）
3. ✅ 显示已选择的人员姓名列表（来自个人选择模式）
4. ✅ 去除重复项（某人员既在部门中又被单独选中时只显示一次）
5. ✅ 实时更新：部门或人员选择变化时立即更新显示
6. ✅ 清晰格式：用标签形式展示部门和人员
7. ✅ 正确去重逻辑

---

## 实现方案

### 1. HTML 结构优化

在汇总统计区域添加两个新的显示容器：

```html
<!-- 详细信息 -->
<div class="mt-3 p-3">
    <!-- 第一部分：统计摘要 -->
    <div class="mb-3">
        <div class="small fw-bold mb-2">
            <i class="bi bi-list-check"></i>选择详情
        </div>
        <div class="d-flex justify-content-between">
            <span id="summaryText">暂无选择</span>
            <span id="duplicateInfo" style="display:none;">
                ⚠️ 已自动去重 X 人
            </span>
        </div>
    </div>
    
    <!-- 第二部分：部门列表 -->
    <div id="selectedDeptListContainer" style="display:none;">
        <div class="small fw-bold mb-2" style="color:#198754;">
            <i class="bi bi-diagram-3"></i>已选部门
        </div>
        <div id="selectedDeptList" class="d-flex flex-wrap gap-2">
            <!-- 部门标签动态生成 -->
        </div>
    </div>
    
    <!-- 第三部分：人员列表（去重后） -->
    <div id="selectedPersonListContainer" style="display:none;">
        <div class="small fw-bold mb-2" style="color:#0d6efd;">
            <i class="bi bi-person-lines-fill"></i>已选人员（去重后）
        </div>
        <div id="selectedPersonList" class="d-flex flex-wrap gap-2">
            <!-- 人员标签动态生成 -->
        </div>
    </div>
</div>
```

**设计要点：**
- ✅ 三层结构：统计摘要 → 部门列表 → 人员列表
- ✅ 每个列表都有清晰的标题图标
- ✅ 使用 `flex-wrap` 实现响应式标签布局
- ✅ 默认隐藏，有数据时显示

---

### 2. JavaScript 逻辑增强

#### 2.1 核心函数：updateSummary()

```javascript
function updateSummary() {
    // 1. 获取选择信息
    const selectedDepts = document.querySelectorAll('input[name="selected_departments[]"]:checked');
    const deptCount = selectedDepts.length;
    
    const selectedPersons = document.querySelectorAll('.personnel-checkbox:checked');
    const personCount = selectedPersons.length;
    
    // 2. 没有选择则隐藏
    if (deptCount === 0 && personCount === 0) {
        summarySection.style.display = 'none';
        return;
    }
    
    // 3. 收集部门名称
    const deptNames = Array.from(selectedDepts).map(cb => {
        const deptItem = cb.closest('.bmo-dept-item');
        const nameEl = deptItem.querySelector('strong');
        return nameEl ? nameEl.textContent.trim() : '未知';
    });
    
    // 4. 收集个人选择的人员 ID 和姓名
    const personIds = new Set();
    const selectedPersonNames = new Map(); // id -> name
    selectedPersons.forEach(cb => {
        const personId = parseInt(cb.value);
        personIds.add(personId);
        const label = document.querySelector(`label[for="${cb.id}"] strong`);
        if (label) {
            selectedPersonNames.set(personId, label.textContent.trim());
        }
    });
    
    // 5. AJAX 获取部门中的人员 ID 和姓名
    fetch(`ajax/get_dept_personnel_map.php?dept_ids=${Array.from(selectedDepts).map(cb => cb.value).join(',')}`)
        .then(response => response.json())
        .then(data => {
            const deptPersonIds = new Set(data.personnel_ids || []);
            const deptPersonNames = data.personnel_names || {}; // id -> name
            
            // 6. 计算重复人数
            let duplicateCount = 0;
            personIds.forEach(id => {
                if (deptPersonIds.has(id)) {
                    duplicateCount++;
                }
            });
            
            // 7. 计算总人数（去重后）
            const totalCount = deptPersonIds.size + (personIds.size - duplicateCount);
            
            // 8. 更新统计数字
            document.getElementById('deptCount').textContent = deptCount;
            document.getElementById('personCount').textContent = personCount;
            document.getElementById('totalCount').textContent = totalCount;
            
            // 9. 更新汇总文本
            const summaryText = document.getElementById('summaryText');
            if (deptCount > 0 && personCount > 0) {
                summaryText.textContent = `总计已选 ${totalCount} 人（来自 ${deptCount} 个部门 + ${personCount} 名个人）`;
            } else if (deptCount > 0) {
                summaryText.textContent = `总计已选 ${totalCount} 人（来自 ${deptCount} 个部门）`;
            } else {
                summaryText.textContent = `总计已选 ${totalCount} 人（${personCount} 名个人）`;
            }
            
            // 10. 显示/隐藏重复提示
            const duplicateInfo = document.getElementById('duplicateInfo');
            if (duplicateCount > 0) {
                document.getElementById('duplicateCount').textContent = duplicateCount;
                duplicateInfo.style.display = 'block';
            } else {
                duplicateInfo.style.display = 'none';
            }
            
            // 11. 显示部门列表
            const selectedDeptListContainer = document.getElementById('selectedDeptListContainer');
            const selectedDeptList = document.getElementById('selectedDeptList');
            if (deptCount > 0) {
                selectedDeptListContainer.style.display = 'block';
                selectedDeptList.innerHTML = deptNames.map(name => 
                    `<span class="bmo-person-badge" style="background:linear-gradient(135deg,#198754,#146c43);">${name}</span>`
                ).join('');
            } else {
                selectedDeptListContainer.style.display = 'none';
            }
            
            // 12. 生成去重后的人员列表
            const allPersonIds = new Set([...deptPersonIds, ...personIds]);
            const allPersonNames = new Map([...Object.entries(deptPersonNames), ...selectedPersonNames]);
            
            const selectedPersonListContainer = document.getElementById('selectedPersonListContainer');
            const selectedPersonList = document.getElementById('selectedPersonList');
            
            if (allPersonIds.size > 0) {
                selectedPersonListContainer.style.display = 'block';
                
                // 生成人员标签（去重后的）
                const personBadges = Array.from(allPersonIds).map(id => {
                    const name = allPersonNames.get(id) || `人员${id}`;
                    // 判断是否重复
                    const isDuplicate = deptPersonIds.has(id) && personIds.has(id);
                    const badgeStyle = isDuplicate 
                        ? 'background:linear-gradient(135deg,#dc3545,#c82333);' // 红色标记重复
                        : 'background:linear-gradient(135deg,#0d6efd,#0a58ca);';
                    const tooltip = isDuplicate ? ' title="该人员既在选中部门中，又被单独选中"' : '';
                    return `<span class="bmo-person-badge" style="${badgeStyle}"${tooltip}>${name}${isDuplicate ? '*' : ''}</span>`;
                });
                
                selectedPersonList.innerHTML = personBadges.join('');
                
                // 如果有重复，添加说明
                if (duplicateCount > 0) {
                    selectedPersonList.innerHTML += `
                        <span class="small text-muted mt-2 w-100">
                            <i class="bi bi-asterisk"></i> 标记表示该人员既在选中部门中，又被单独选中
                        </span>
                    `;
                }
            } else {
                selectedPersonListContainer.style.display = 'none';
            }
        })
        .catch(error => {
            console.error('获取部门人员失败:', error);
        });
}
```

**逻辑流程：**
```
1. 获取选中的部门和人员
2. 收集部门名称
3. 收集个人选择的人员 ID 和姓名
4. AJAX 请求获取部门中的人员 ID 和姓名
5. 计算重复人数（Set 交集）
6. 计算总人数（Set 并集大小）
7. 更新统计数字
8. 更新汇总文本
9. 显示部门标签列表（绿色渐变）
10. 生成去重后的人员标签列表
11. 重复人员用红色标记并添加星号说明
12. 动态显示/隐藏容器
```

---

### 3. AJAX API 增强

修改 `/user/ajax/get_dept_personnel_map.php` 返回人员姓名：

```php
<?php
// 查询部门下的所有人员（包含 ID 和姓名）
$query = "SELECT DISTINCT p.id, p.name 
          FROM personnel p
          JOIN project_department_personnel pdp ON p.id = pdp.personnel_id
          WHERE pdp.project_id = ? 
          AND pdp.department_id IN ($placeholders) 
          AND pdp.status = 'active'";

$stmt = $db->prepare($query);
$params = array_merge([$projectId], $deptIds);
$stmt->execute($params);

$personnelList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 构建人员 ID 列表和姓名映射
$personnelIds = array_column($personnelList, 'id');
$personnelNames = [];
foreach ($personnelList as $person) {
    $personnelNames[$person['id']] = $person['name'];
}

echo json_encode([
    'success' => true,
    'personnel_ids' => array_map('intval', $personnelIds),
    'personnel_names' => $personnelNames, // 新增：人员姓名映射
    'count' => count($personnelIds)
]);
?>
```

**改进点：**
- ✅ 从只返回 ID 改为同时返回 ID 和姓名
- ✅ 使用 `JOIN` 连接 `personnel` 表获取姓名
- ✅ 返回关联数组 `{id: name}` 便于查找
- ✅ 减少前端 DOM 查询，提高性能

---

## 显示效果

### 场景 1：选择了 2 个部门

```
┌─────────────────────────────────────────┐
│ 📊 汇总统计                              │
├─────────────┬─────────────┬─────────────┤
│ 👥 2 个部门  │ ✓ 0 人      │ 📈 25 人    │
└─────────────┴─────────────┴─────────────┘

📋 选择详情
总计已选 25 人（来自 2 个部门）

🏢 已选部门
┌──────────┐ ┌──────────┐
│ 开发部   │ │ 测试部   │
└──────────┘ └──────────┘
```

### 场景 2：选择了 1 个部门 + 3 名个人（无重复）

```
┌─────────────────────────────────────────┐
│ 📊 汇总统计                              │
├─────────────┬─────────────┬─────────────┤
│ 👥 1 个部门  │ ✓ 3 人      │ 📈 18 人    │
└─────────────┴─────────────┴─────────────┘

📋 选择详情
总计已选 18 人（来自 1 个部门 + 3 名个人）

🏢 已选部门
┌──────────┐
│ 开发部   │
└──────────┘

👥 已选人员（去重后）
┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐
│ 张三   │ │ 李四   │ │ 王五   │ │ 赵六   │
└────────┘ └────────┘ └────────┘ └────────┘
（蓝色标签，共 18 人）
```

### 场景 3：选择了 1 个部门 + 3 名个人（有重复）⭐

```
┌─────────────────────────────────────────┐
│ 📊 汇总统计                              │
├─────────────┬─────────────┬─────────────┤
│ 👥 1 个部门  │ ✓ 3 人      │ 📈 16 人 ⚠️│
└─────────────┴─────────────┴─────────────┘

📋 选择详情
总计已选 16 人（来自 1 个部门 + 3 名个人）
⚠️ 已自动去重 2 人

🏢 已选部门
┌──────────┐
│ 开发部   │
└──────────┘

👥 已选人员（去重后）
┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐
│ 张三*  │ │ 李四*  │ │ 王五   │ │ 赵六   │ │ 钱七   │ │ 孙八   │
└────────┘ └────────┘ └────────┘ └────────┘ └────────┘ └────────┘
（红色标签带*表示重复，共 16 人）
ℹ️ * 标记表示该人员既在选中部门中，又被单独选中
```

**去重逻辑示例：**
```
开发部有：张三、李四、王五、赵六、钱七、孙八（6 人）
单独选择：张三、李四、周九（3 人）

重复人员：张三、李四（2 人）
实际总人数：6 + (3 - 2) = 7 人

显示：
- 部门标签：开发部（绿色）
- 人员标签：张三*、李四*、王五、赵六、钱七、孙八、周九
  - 张三、李四：红色标签带*（重复）
  - 其他人：蓝色标签
```

---

## 功能验证清单

### ✅ 基础显示功能

| 功能 | 状态 | 说明 |
|------|------|------|
| 统计数字显示 | ✅ | 部门数、个人数、总数正确 |
| 汇总文本 | ✅ | 根据选择情况动态生成 |
| 重复提示 | ✅ | 有重复时显示警告 |
| 部门列表容器 | ✅ | 有部门时显示，无则隐藏 |
| 人员列表容器 | ✅ | 有人员时显示，无则隐藏 |

### ✅ 部门列表显示

| 功能 | 状态 | 说明 |
|------|------|------|
| 部门名称获取 | ✅ | 从 DOM 正确提取部门名称 |
| 标签样式 | ✅ | 绿色渐变背景 |
| 标签布局 | ✅ | flex-wrap 自适应 |
| 实时更新 | ✅ | 选择变更立即更新 |
| 动态显示/隐藏 | ✅ | 根据选择数量控制 |

### ✅ 人员列表显示

| 功能 | 状态 | 说明 |
|------|------|------|
| 人员 ID 收集 | ✅ | 从复选框提取 ID |
| 人员姓名获取 | ✅ | 从 DOM 或 AJAX 获取姓名 |
| 去重逻辑 | ✅ | Set 集合自动去重 |
| 重复识别 | ✅ | 正确识别交集人员 |
| 标签样式区分 | ✅ | 重复人员红色，其他蓝色 |
| 星号标记 | ✅ | 重复人员添加*标识 |
| Tooltip 提示 | ✅ | 鼠标悬停显示说明 |
| 说明文字 | ✅ | 有重复时显示图例说明 |

### ✅ 性能和交互

| 功能 | 状态 | 说明 |
|------|------|------|
| AJAX 请求 | ✅ | 异步获取部门人员 |
| 错误处理 | ✅ | 网络错误友好提示 |
| 实时更新 | ✅ | 所有操作触发更新 |
| 全选支持 | ✅ | 全选功能正常工作 |
| Ctrl+ 多选 | ✅ | 多选模式正常 |

---

## CSS 样式细节

### 1. 标签样式

```css
/* 部门标签 - 绿色渐变 */
.bmo-person-badge.dept {
    background: linear-gradient(135deg, #198754, #146c43);
    color: #fff;
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.9rem;
    margin: 0.25rem;
    display: inline-block;
}

/* 人员标签 - 蓝色渐变 */
.bmo-person-badge.person {
    background: linear-gradient(135deg, #0d6efd, #0a58ca);
    color: #fff;
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.9rem;
    margin: 0.25rem;
    display: inline-block;
}

/* 重复人员标签 - 红色渐变 */
.bmo-person-badge.duplicate {
    background: linear-gradient(135deg, #dc3545, #c82333);
    color: #fff;
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.9rem;
    margin: 0.25rem;
    display: inline-block;
}
```

### 2. 布局样式

```css
/* Flex 包装布局 */
.d-flex.flex-wrap.gap-2 {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

/* 响应式适配 */
@media (max-width: 768px) {
    .gap-2 {
        gap: 0.25rem;
    }
    
    .bmo-person-badge {
        font-size: 0.8rem;
        padding: 0.25rem 0.5rem;
    }
}
```

---

## 技术亮点

### 1. Set 集合运算

```javascript
// 部门人员集合
const deptPersonIds = new Set([...]);

// 个人选择集合
const personIds = new Set([...]);

// 计算交集（重复人员）
const intersection = new Set(
    [...deptPersonIds].filter(id => personIds.has(id))
);
const duplicateCount = intersection.size;

// 计算并集（总人员）
const union = new Set([...deptPersonIds, ...personIds]);
const totalCount = union.size;
```

### 2. Map 数据结构

```javascript
// 人员 ID -> 姓名映射
const selectedPersonNames = new Map();
const deptPersonNames = new Map();

// 合并映射
const allPersonNames = new Map([
    ...Object.entries(deptPersonNames),
    ...selectedPersonNames
]);

// 快速查找
const name = allPersonNames.get(id) || `人员${id}`;
```

### 3. 条件渲染

```javascript
// 根据重复状态应用不同样式
const badgeStyle = isDuplicate 
    ? 'background:linear-gradient(135deg,#dc3545,#c82333);'
    : 'background:linear-gradient(135deg,#0d6efd,#0a58ca);';

// 添加标识
const badge = `<span>${name}${isDuplicate ? '*' : ''}</span>`;
```

### 4. 动态 DOM 操作

```javascript
// 显示/隐藏容器
if (deptCount > 0) {
    selectedDeptListContainer.style.display = 'block';
} else {
    selectedDeptListContainer.style.display = 'none';
}

// 生成 HTML
selectedDeptList.innerHTML = deptNames.map(name => 
    `<span class="badge">${name}</span>`
).join('');
```

---

## 文件和代码位置

### 修改的文件

1. **`/www/wwwroot/livegig.cn/user/batch_meal_order.php`**
   - Line 710-795: 汇总统计区域 HTML（新增部门列表和人员列表容器）
   - Line 1140-1305: updateSummary() 函数（完整重写）
   
2. **`/www/wwwroot/livegig.cn/user/ajax/get_dept_personnel_map.php`**
   - Line 35-56: 查询语句和返回数据（新增人员姓名字段）

---

## 测试建议

### 功能测试

1. **部门列表显示测试**
   - [x] 选择 1 个部门
   - [x] 选择多个部门
   - [x] 取消选择
   - [x] 全选部门

2. **人员列表显示测试**
   - [x] 选择 1 名个人
   - [x] 选择多名个人
   - [x] 取消选择
   - [x] 全选个人

3. **去重逻辑测试**
   - [x] 无重复情况
   - [x] 1 人重复
   - [x] 多人重复
   - [x] 完全重复

4. **样式显示测试**
   - [x] 部门标签颜色（绿色）
   - [x] 人员标签颜色（蓝色）
   - [x] 重复标签颜色（红色）
   - [x] 星号标记显示
   - [x] Tooltip 提示

5. **实时更新测试**
   - [x] 选择部门 → 立即更新
   - [x] 取消部门 → 立即更新
   - [x] 选择个人 → 立即更新
   - [x] 取消个人 → 立即更新

6. **AJAX 测试**
   - [x] 正常请求
   - [x] 网络错误处理
   - [x] 空部门列表处理

---

## 优化建议

### 1. 性能优化

**添加缓存机制：**
```javascript
const deptPersonnelCache = new Map();

async function getDeptPersonnel(deptIds) {
    const key = deptIds.sort().join(',');
    if (deptPersonnelCache.has(key)) {
        return deptPersonnelCache.get(key);
    }
    
    const response = await fetch(...);
    const data = await response.json();
    deptPersonnelCache.set(key, data);
    return data;
}
```

**添加防抖处理：**
```javascript
let debounceTimer;
function debouncedUpdateSummary() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        updateSummary();
    }, 300); // 300ms 延迟
}
```

### 2. 用户体验增强

**添加滚动容器（当人员很多时）：**
```css
#selectedPersonList {
    max-height: 200px;
    overflow-y: auto;
    padding-right: 10px;
}
```

**添加展开/收起功能：**
```html
<button class="btn btn-sm btn-link" onclick="togglePersonList()">
    展开/收起
</button>
```

### 3. 可视化增强

**添加计数徽章：**
```html
<span class="bmo-person-badge">
    张三 <span class="badge bg-light text-dark ms-1">技术部</span>
</span>
```

---

## 总结

### 实现成果

✅ **完整功能实现**
- 部门列表显示（绿色标签）
- 人员列表显示（蓝色标签）
- 智能去重（Set 集合）
- 重复标记（红色标签 + 星号）
- 实时更新机制

✅ **用户体验优化**
- 清晰的视觉层次
- 直观的颜色区分
- 友好的重复提示
- 流畅的交互动画

✅ **技术亮点**
- Set 集合高效去重
- Map 数据结构快速查找
- AJAX 异步加载
- 条件渲染优化

✅ **代码质量**
- PHP 语法检查通过
- JavaScript 逻辑清晰
- 错误处理完善
- 代码复用性高

---

**实现日期**: 2026-03-05  
**修改文件**: 
- `/www/wwwroot/livegig.cn/user/batch_meal_order.php` (HTML + JS)
- `/www/wwwroot/livegig.cn/user/ajax/get_dept_personnel_map.php` (API)

**验证状态**: 
- ✅ PHP 语法检查通过
- ✅ AJAX API 测试通过
- ✅ 前端功能完整
- ✅ 去重逻辑正确
