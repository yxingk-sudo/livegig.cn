# 批量报餐页面"人员 534"问题诊断与修复

## 问题现象

用户反馈在"已选人员（去重后）"列表中仍然出现"人员 534"：

```
选择详情
总计已选 2 人（来自 1 个部门 + 1 名个人）

已选部门
Artist/艺人

已选人员（去重后）
人员 534                    ← 这是问题
NANTON III PADGET EUSTACE
```

---

## 问题分析

### 可能的原因

1. **数据库中姓名为空**
   - personnel 表中某条记录的 name 字段为空或为"人员 534"
   
2. **AJAX 返回数据缺失**
   - `deptPersonNames` 映射中缺少该 ID 对应的姓名
   
3. **ID 类型不匹配**
   - 前端使用字符串 ID，后端返回整数 ID，导致 Map 查找失败

4. **部门 ID 混入**
   - 某个部门 ID 被错误地添加到人员 ID 列表中

---

## 已实施的修复

### 1. 增强姓名获取逻辑

**修改前：**
```javascript
const name = allPersonNames.get(id) || `人员${id}`;
```

**修改后：**
```javascript
let name = allPersonNames.get(id);

// 如果还是没有，说明这个 ID 不在映射中，可能是数据问题
if (!name || name === `人员${id}`) {
    console.warn(`警告：人员 ID ${id} 没有对应的姓名，可能是部门 ID 混入或姓名为空`);
    console.log('deptPersonNames:', deptPersonNames);
    console.log('selectedPersonNames:', Object.fromEntries(selectedPersonNames));
    console.log('allPersonNames:', Object.fromEntries(allPersonNames));
    name = `未知人员${id}`; // 使用更明确的标识
}
```

**改进点：**
- ✅ 添加详细的调试日志
- ✅ 输出所有姓名映射供检查
- ✅ 使用"未知人员 XXX"替代"人员 XXX"便于识别

### 2. AJAX API 增强

**修改前：**
```php
foreach ($personnelList as $person) {
    $personnelNames[$person['id']] = $person['name'];
}
```

**修改后：**
```php
foreach ($personnelList as $person) {
    // 确保 name 不为空
    $personId = intval($person['id']);
    $personName = !empty($person['name']) ? $person['name'] : "未知人员{$personId}";
    $personnelNames[$personId] = $personName;
}

// 调试日志
error_log('部门 ID: ' . json_encode($deptIds));
error_log('查询结果数：' . count($personnelList));
error_log('人员 IDs: ' . json_encode($personnelIds));
error_log('人员姓名映射：' . json_encode($personnelNames));
```

**改进点：**
- ✅ 确保键名为整数类型
- ✅ 处理空姓名的情况
- ✅ 添加服务器端调试日志

---

## 调试步骤

### 第一步：打开浏览器控制台

1. 按 `F12` 打开开发者工具
2. 切换到"Console"标签
3. 访问批量报餐页面

### 第二步：触发问题

1. 选择"Artist/艺人"部门
2. 观察控制台输出

### 第三步：查看警告信息

控制台应该显示类似以下内容：

```javascript
警告：人员 ID 534 没有对应的姓名，可能是部门 ID 混入或姓名为空

deptPersonNames: {
    "23": "NANTON III PADGET EUSTACE",
    "534": "",  // ← 可能是空的
    // ...
}

selectedPersonNames: {
    // ...
}

allPersonNames: {
    "23": "NANTON III PADGET EUSTACE",
    "534": "",  // ← 问题所在
    // ...
}
```

### 第四步：分析输出

根据控制台输出，可以确定：

1. **如果是空字符串** → 数据库问题
2. **如果是部门 ID** → 逻辑问题
3. **如果是其他原因** → 需要进一步调查

---

## 可能的解决方案

### 方案 1：数据库姓名为空

**检查：**
```sql
SELECT id, name FROM personnel 
WHERE id = 534;
```

**如果 name 为空或为"人员 534"：**
```sql
UPDATE personnel 
SET name = '正确的姓名' 
WHERE id = 534;
```

### 方案 2：部门 ID 混入

**检查项目：**
- 确认 `project_department_personnel` 表中的关联关系
- 确认部门 ID 和人员 ID 是否有重叠

**SQL 检查：**
```sql
-- 检查 ID 534 是人员还是部门
SELECT 'personnel' as type, id, name FROM personnel WHERE id = 534
UNION ALL
SELECT 'department' as type, id, name FROM departments WHERE id = 534;
```

### 方案 3：数据类型不匹配

**前端修复：**
```javascript
// 确保使用字符串键名查找
const name = allPersonNames.get(String(id)) || 
             allPersonNames.get(id) || 
             `未知人员${id}`;
```

### 方案 4：AJAX 返回格式问题

**检查返回的 JSON：**
```javascript
{
    "success": true,
    "personnel_ids": [23, 534, ...],
    "personnel_names": {
        "23": "NANTON III PADGET EUSTACE",
        "534": ""  // ← 这里可能有问题
    }
}
```

---

## 临时解决方案

如果暂时无法确定根本原因，可以使用以下临时方案：

### 前端兜底逻辑

```javascript
let name = allPersonNames.get(id);

if (!name || name === '' || name === `人员${id}`) {
    // 尝试从 DOM 中查找
    const personElement = document.querySelector(`[data-person-id="${id}"] strong`);
    if (personElement) {
        name = personElement.textContent.trim();
    } else {
        // 最后的手段：显示友好提示
        name = `待完善姓名 (ID:${id})`;
    }
}
```

---

## 长期解决方案

### 1. 数据完整性检查

**定期检查脚本：**
```php
<?php
// 检查所有人员的姓名
$query = "SELECT id, name FROM personnel WHERE name IS NULL OR name = '' OR name LIKE '人员%'";
$stmt = $db->query($query);
$incomplete = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($incomplete)) {
    error_log('发现不完整的人员记录：' . json_encode($incomplete));
    // 可以发送邮件通知管理员
}
?>
```

### 2. 数据验证

**添加约束：**
```sql
ALTER TABLE personnel 
MODIFY COLUMN name VARCHAR(100) NOT NULL;

ALTER TABLE personnel 
ADD CONSTRAINT chk_name_not_empty 
CHECK (name <> '');
```

### 3. 前端显示优化

```javascript
// 对于姓名有问题的记录，显示特殊样式
if (!name || name === '' || name.startsWith('人员') || name.startsWith('未知')) {
    badgeStyle += ' border: 2px dashed #dc3545;';
    tooltip = ' title="该人员信息不完整，请联系管理员更新"';
}
```

---

## 测试验证

### 测试场景 1：正常情况

**预期输出：**
```
已选人员（去重后）：
┌──────────────────────────────┐
│ NANTON III PADGET EUSTACE   │
│ 张三                         │
│ 李四                         │
└──────────────────────────────┘
```

### 测试场景 2：姓名为空

**预期输出：**
```
已选人员（去重后）：
┌──────────────────────────────┐
│ NANTON III PADGET EUSTACE   │
│ 未知人员 534 ⚠️              │  ← 红色虚线边框
└──────────────────────────────┘

ℹ️ 鼠标悬停提示："该人员信息不完整，请联系管理员更新"
```

### 测试场景 3：部门 ID 混入

**控制台输出：**
```javascript
警告：人员 ID 534 没有对应的姓名，可能是部门 ID 混入或姓名为空
deptPersonNames: {...}
selectedPersonNames: {...}
```

**此时应该：**
1. 检查控制台完整输出
2. 根据日志定位问题来源
3. 修复相关逻辑

---

## 文件修改清单

### 修改的文件

1. **`/www/wwwroot/livegig.cn/user/batch_meal_order.php`**
   - Line 1300-1319: 增强姓名获取逻辑
   - 添加调试日志输出

2. **`/www/wwwroot/livegig.cn/user/ajax/get_dept_personnel_map.php`**
   - Line 52-56: 确保姓名字段不为空
   - Line 58-61: 添加服务器端调试日志

### 关键改动

#### batch_meal_order.php

```javascript
// 第 1300-1319 行
const personBadges = Array.from(allPersonIds).map(id => {
    let name = allPersonNames.get(id);
    
    // 增强的姓名获取逻辑
    if (!name || name === `人员${id}`) {
        console.warn(`警告：人员 ID ${id} 没有对应的姓名...`);
        console.log('deptPersonNames:', deptPersonNames);
        console.log('selectedPersonNames:', Object.fromEntries(selectedPersonNames));
        console.log('allPersonNames:', Object.fromEntries(allPersonNames));
        name = `未知人员${id}`;
    }
    
    // 判断是否重复
    const isDuplicate = deptPersonIds.has(id) && personIds.has(id);
    // ...
});
```

#### get_dept_personnel_map.php

```php
// 第 52-56 行
foreach ($personnelList as $person) {
    $personId = intval($person['id']);
    $personName = !empty($person['name']) ? $person['name'] : "未知人员{$personId}";
    $personnelNames[$personId] = $personName;
}

// 第 58-61 行：调试日志
error_log('部门 ID: ' . json_encode($deptIds));
error_log('查询结果数：' . count($personnelList));
error_log('人员 IDs: ' . json_encode($personnelIds));
error_log('人员姓名映射：' . json_encode($personnelNames));
```

---

## 下一步行动

### 立即执行

1. ✅ 清除浏览器缓存
2. ✅ 刷新页面
3. ✅ 打开控制台
4. ✅ 重现问题
5. ✅ 复制控制台输出

### 根据输出确定方案

**如果输出显示姓名为空：**
→ 执行 SQL 更新姓名

**如果输出显示部门 ID 混入：**
→ 检查部门选择和人员选择的逻辑

**如果输出显示其他问题：**
→ 根据具体情况进行修复

### 联系管理员

如果以上方法都无法解决，请提供以下信息给技术支持：

1. 控制台完整输出
2. 网络请求的响应内容
3. 具体的操作步骤
4. 涉及的部门和人员 ID

---

**修复日期**: 2026-03-05  
**修复文件**: 
- `/www/wwwroot/livegig.cn/user/batch_meal_order.php`
- `/www/wwwroot/livegig.cn/user/ajax/get_dept_personnel_map.php`

**验证状态**: 
- ✅ PHP 语法检查通过
- ✅ 添加调试日志
- ✅ 增强错误处理
- ✅ 等待用户反馈调试信息
