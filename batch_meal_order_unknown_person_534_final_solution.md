# 批量报餐页面"未知人员 534"问题最终解决方案

## 问题诊断结果

### ✅ 已确认的事实

1. **人员 534 的姓名正常**
   ```sql
   ID: 534, 姓名：江海迦 ✓
   ```

2. **数据库关联正常**
   ```
   项目 4, 部门 62 (Artist/艺人), 人员 534 (江海迦), 状态：active
   ```

3. **AJAX API 逻辑正确**
   - 查询包含 `WHERE pdp.project_id = ?`
   - 只返回当前项目的人员
   - 姓名映射逻辑完整

### 🔍 可能的原因

#### 原因 1：浏览器缓存
- 旧的 AJAX 响应被缓存
- 导致显示过时的数据

#### 原因 2：Session 未更新
- `$_SESSION['project_id']` 可能不正确
- 导致查询了错误的项目数据

#### 原因 3：前端 Map 键类型问题
- JavaScript Map 的键可能是字符串 vs 整数不匹配

---

## 完整解决方案

### 第一步：清除所有缓存

#### 1.1 浏览器端清除缓存

**Chrome/Edge:**
```
按 Ctrl + Shift + Delete
选择"缓存的图片和文件"
点击"清除数据"
```

**或者使用无痕模式:**
```
按 Ctrl + Shift + N
重新打开页面
```

#### 1.2 服务器端清除 OPcache

创建清除缓存脚本：

```php
<?php
// /www/wwwroot/livegig.cn/scripts/clear_cache.php
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache 已清除\n";
} else {
    echo "OPcache 未安装\n";
}

if (function_exists('apcu_clear_cache')) {
    apcu_clear_cache();
    echo "APCu cache 已清除\n";
}
?>
```

运行：
```bash
php /www/wwwroot/livegig.cn/scripts/clear_cache.php
```

### 第二步：验证 Session 和项目

创建测试脚本：

```php
<?php
// /www/wwwroot/livegig.cn/scripts/test_session.php
session_start();

echo "=== Session 信息 ===\n\n";
echo "User ID: " . ($_SESSION['user_id'] ?? '未设置') . "\n";
echo "Project ID: " . ($_SESSION['project_id'] ?? '未设置') . "\n";
echo "Project Name: " . ($_SESSION['project_name'] ?? '未设置') . "\n";
?>
```

### 第三步：检查 AJAX 实际请求

在 `batch_meal_order.php` 中添加更详细的日志：

```javascript
fetch(`ajax/get_dept_personnel_map.php?dept_ids=${deptIdValues.join(',')}`)
    .then(response => response.json())
    .then(data => {
        console.log('=== AJAX 响应详情 ===');
        console.log('请求的部门 IDs:', deptIdValues);
        console.log('返回的人员 IDs:', data.personnel_ids);
        console.log('返回的姓名映射:', data.personnel_names);
        console.log('人员数量:', data.count);
        
        // 验证人员 534
        if (data.personnel_ids.includes(534)) {
            console.log('✓ 找到人员 534');
            console.log('  姓名:', data.personnel_names[534]);
        }
        
        // ... 后续处理
    })
```

### 第四步：修复可能的 Map 键类型问题

修改 `updateSummaryDisplayOnly()` 函数：

```javascript
// 生成去重后的人员列表
const allPersonIds = new Set([...deptPersonIds, ...personIds]);
const allPersonNames = new Map([...Object.entries(deptPersonNames), ...selectedPersonNames]);

const selectedPersonListContainer = document.getElementById('selectedPersonListContainer');
const selectedPersonList = document.getElementById('selectedPersonList');

if (allPersonIds.size > 0) {
    selectedPersonListContainer.style.display = 'block';
    
    // 生成人员标签（去重后的）
    const personBadges = Array.from(allPersonIds).map(id => {
        // 增强的姓名查找逻辑
        let name;
        
        // 尝试多种方式获取姓名
        name = allPersonNames.get(id);
        
        // 如果找不到，尝试字符串键
        if (!name) {
            name = allPersonNames.get(String(id));
        }
        
        // 如果还是没有，使用默认值
        if (!name) {
            console.warn(`人员 ID ${id} 姓名为空，从 DOM 查找...`);
            // 尝试从 DOM 中查找
            const personElement = document.querySelector(`[data-person-id="${id}"] strong`);
            if (personElement) {
                name = personElement.textContent.trim();
                console.log(`  ✓ 从 DOM 找到姓名：${name}`);
            }
        }
        
        // 最后的兜底方案
        if (!name || name === '') {
            name = `待完善姓名 (ID:${id})`;
            console.warn(`  ⚠️ 使用兜底姓名：${name}`);
        }
        
        // 判断是否重复
        const isDuplicate = deptPersonIds.has(id) && personIds.has(id);
        const badgeStyle = isDuplicate 
            ? 'background:linear-gradient(135deg,#dc3545,#c82333);'
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
```

---

## 立即执行步骤

### 操作清单

请按以下顺序执行：

#### 1. 清除浏览器缓存
```
按 Ctrl + Shift + Delete
清除"缓存的图片和文件"
关闭所有浏览器窗口
```

#### 2. 重启浏览器并使用无痕模式
```
按 Ctrl + Shift + N
访问批量报餐页面
```

#### 3. 测试功能
```
1. 选择"Artist/艺人"部门
2. 观察右侧汇总区域
3. 查看控制台输出
```

#### 4. 检查结果
```
应该显示：
┌──────────────────────────────┐
│ 江海迦                      │ ← 正确的姓名
└──────────────────────────────┘
```

---

## 如果问题仍然存在

### 收集调试信息

请提供以下信息：

1. **浏览器控制台输出**
   - 打开 F12
   - 复制所有 Console 输出
   - 特别是包含"人员 534"的相关日志

2. **Network 请求详情**
   - 找到 `get_dept_personnel_map.php` 请求
   - 查看 Request URL（完整的 URL）
   - 查看 Response（响应内容）

3. **当前 Session 信息**
   - 访问 `/scripts/test_session.php`
   - 复制输出的 Session 信息

4. **具体操作步骤**
   - 选择的部门名称
   - 是否单独选择了人员
   - 当前所在的项目

### 进一步排查

如果以上步骤都无法解决问题，可能需要：

1. **检查数据库完整性**
   ```sql
   -- 检查 personnel 表
   SELECT id, name FROM personnel WHERE id = 534;
   
   -- 检查关联表
   SELECT * FROM project_department_personnel 
   WHERE personnel_id = 534;
   
   -- 检查是否有触发器或视图
   SHOW TRIGGERS LIKE 'project_department_personnel';
   ```

2. **检查 PHP 错误日志**
   ```bash
   tail -f /www/server/php/81/var/log/php_error.log | grep "人员 534"
   ```

3. **启用详细调试模式**
   在 `batch_meal_order.php` 顶部添加：
   ```php
   error_reporting(E_ALL);
   ini_set('display_errors', 1);
   ```

---

## 预防措施

### 1. 数据完整性约束

```sql
-- 确保 name 字段不为空
ALTER TABLE personnel 
MODIFY COLUMN name VARCHAR(100) NOT NULL;

-- 添加检查约束
ALTER TABLE personnel 
ADD CONSTRAINT chk_name_not_empty 
CHECK (name <> '');
```

### 2. 定期数据检查

创建定时任务脚本：

```php
<?php
// /www/wwwroot/livegig.cn/scripts/daily_check.php
require_once '/www/wwwroot/livegig.cn/config/database.php';

$database = new Database();
$db = $database->getConnection();

// 检查姓名为空的人员
$query = "SELECT id FROM personnel WHERE name IS NULL OR name = ''";
$stmt = $db->query($query);
$empty = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (!empty($empty)) {
    $message = "发现 " . count($empty) . " 名人员姓名为空：" . implode(', ', $empty);
    error_log("人员数据检查警告：" . $message);
    // 可以发送邮件通知管理员
}

// 检查重复关联
$query = "SELECT project_id, department_id, personnel_id, COUNT(*) as cnt
          FROM project_department_personnel
          GROUP BY project_id, department_id, personnel_id
          HAVING cnt > 1";
$duplicates = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);

if (!empty($duplicates)) {
    foreach ($duplicates as $dup) {
        error_log("发现重复关联：项目{$dup['project_id']},部门{$dup['department_id']},人员{$dup['personnel_id']}");
    }
}
?>
```

### 3. 前端防御性编程

```javascript
// 永远不要相信数据是完整的
function getSafeName(id, namesMap, defaultPrefix = '人员') {
    let name = namesMap.get(id);
    
    if (!name || name === '') {
        // 尝试字符串键
        name = namesMap.get(String(id));
    }
    
    if (!name || name === '') {
        // 尝试从 DOM 获取
        const el = document.querySelector(`[data-person-id="${id}"] strong`);
        if (el) {
            name = el.textContent.trim();
        }
    }
    
    if (!name || name === '') {
        // 最后的手段
        name = `${defaultPrefix}${id}(信息待完善)`;
        console.warn(`使用了兜底姓名：${name}`);
    }
    
    return name;
}
```

---

## 文件修改清单

### 已修改的文件

1. **`/www/wwwroot/livegig.cn/user/batch_meal_order.php`**
   - Line 1300-1319: 增强姓名获取逻辑
   - 添加调试日志

2. **`/www/wwwroot/livegig.cn/user/ajax/get_dept_personnel_map.php`**
   - Line 52-57: 确保姓名字段处理
   - Line 60-63: 添加服务器端日志

### 新增的调试脚本

1. **`/www/wwwroot/livegig.cn/scripts/check_empty_names.php`**
   - 检查姓名为空的人员

2. **`/www/wwwroot/livegig.cn/scripts/fix_duplicate_dept_personnel.php`**
   - 修复重复的部门人员关联

3. **`/www/wwwroot/livegig.cn/scripts/check_artist_dept_detail.php`**
   - 详细检查 Artist/艺人部门

4. **`/www/wwwroot/livegig.cn/scripts/test_session.php`** (需创建)
   - 测试 Session 信息

5. **`/www/wwwroot/livegig.cn/scripts/clear_cache.php`** (需创建)
   - 清除服务器缓存

---

## 总结

### 当前状态

✅ **已知信息：**
- 人员 534 的姓名是"江海迦"
- 数据库记录完整
- AJAX API 逻辑正确
- 前端代码已增强

⚠️ **待确认：**
- 浏览器缓存是否已清除
- Session 是否正确
- Map 键类型是否匹配

### 下一步行动

1. ✅ 清除浏览器缓存（Ctrl + Shift + N）
2. ✅ 重新测试功能
3. ✅ 观察控制台输出
4. ✅ 反馈测试结果

### 成功标准

```
选择部门：Artist/艺人
显示：
┌──────────────────────────────┐
│ 江海迦                      │ ✓
└──────────────────────────────┘
```

---

**更新时间**: 2026-03-05  
**诊断结论**: 数据库数据正常，问题可能在缓存或前端数据处理  
**建议操作**: 清除缓存 + 无痕模式测试
