# 批量报餐页面修改确认和缓存清除指南

## 修改确认

### ✅ 已完成的修改

我已经确认 `/www/wwwroot/livegig.cn/user/batch_meal_order.php` 文件包含了所有必要的修改：

#### 1. HTML 结构修改（Line 612-645）

```html
<!-- 部门选择 -->
<div class="bmo-select-section" id="departmentSelection" style="display:none;">
    <h6><i class="bi bi-diagram-3"></i>选择部门</h6>
    <div class="row">
        <?php foreach ($departments as $dept): ?>
            <div class="col-md-4 mb-3">
                <div class="bmo-dept-item" data-dept-id="<?php echo $dept['id']; ?>" 
                     onclick="toggleDepartmentSelection(event, <?php echo $dept['id']; ?>)">
                    <div class="form-check">
                        <input class="form-check-input department-checkbox" type="checkbox" 
                               name="selected_departments[]" value="<?php echo $dept['id']; ?>" 
                               id="dept_<?php echo $dept['id']; ?>">
                        <label class="form-check-label" for="dept_<?php echo $dept['id']; ?>" 
                               style="cursor:pointer;width:100%;" 
                               onclick="toggleDepartmentSelection(event, <?php echo $dept['id']; ?>)">
                            <strong><?php echo htmlspecialchars($dept['name']); ?></strong>
                            <div class="text-muted small mt-1"><?php echo $dept['person_count']; ?> 人</div>
                        </label>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <!-- ✅ 部门选择时的已选信息展示 -->
    <div class="bmo-selected-box mt-3" id="departmentSelectedBox" style="display:none;">
        <div class="bmo-selected-title">
            已选部门 <span class="bmo-selected-count" id="selectedDeptCount">0</span> 个
        </div>
        <div id="selectedDepartmentsList" class="mt-2">
            <p class="text-muted mb-0">暂无选择</p>
        </div>
    </div>
</div>
```

**关键特性：**
- ✅ 部门标签添加了 `onclick` 事件
- ✅ Label 标签添加了 `onclick` 事件
- ✅ 复选框没有 `stopPropagation()`
- ✅ 新增了部门已选信息显示区

#### 2. JavaScript 事件监听器（Line 814-832）

```javascript
// 部门选择变更
document.querySelectorAll('.department-checkbox').forEach(checkbox => {
    checkbox.addEventListener('change', function(e) {
        e.stopPropagation(); // 阻止冒泡到父元素
        updateDepartmentVisual(this);
        updateSelectedPersonnelFromDepartments();
        checkAndEnableMealTypes();
    });
});

// 个人选择变更
document.querySelectorAll('.personnel-checkbox').forEach(checkbox => {
    checkbox.addEventListener('change', function(e) {
        e.stopPropagation(); // 阻止冒泡到父元素
        updatePersonVisual(this);
        updateSelectedPersonnel();
        checkAndEnableMealTypes();
    });
});
```

**关键特性：**
- ✅ change 事件中阻止冒泡
- ✅ 调用视觉更新函数
- ✅ 调用计数更新函数

#### 3. 标签点击切换函数（Line 834-869）

```javascript
// 部门标签点击切换
window.toggleDepartmentSelection = function(event, deptId) {
    // 如果点击的是复选框本身，不处理（由 change 事件处理）
    if (event.target.classList.contains('department-checkbox')) {
        return;
    }
    
    // 阻止事件冒泡，避免触发父元素的事件
    event.stopPropagation();
    
    const checkbox = document.getElementById(`dept_${deptId}`);
    if (checkbox) {
        // 切换复选框状态
        checkbox.checked = !checkbox.checked;
        // 更新视觉状态
        updateDepartmentVisual(checkbox);
        // 更新人员计数
        updateSelectedPersonnelFromDepartments();
        // 检查并启用餐类型
        checkAndEnableMealTypes();
    }
};

// 人员标签点击切换
window.togglePersonSelection = function(event, personId) {
    // 如果点击的是复选框本身，不处理（由 change 事件处理）
    if (event.target.classList.contains('personnel-checkbox')) {
        return;
    }
    
    // 阻止事件冒泡，避免触发父元素的事件
    event.stopPropagation();
    
    const checkbox = document.getElementById(`person_${personId}`);
    if (checkbox) {
        // 切换复选框状态
        checkbox.checked = !checkbox.checked;
        // 更新视觉状态
        updatePersonVisual(checkbox);
        // 更新人员计数
        updateSelectedPersonnel();
        // 检查并启用餐类型
        checkAndEnableMealTypes();
    }
};
```

**关键特性：**
- ✅ 检测并跳过复选框点击
- ✅ 阻止事件冒泡
- ✅ 切换复选框状态
- ✅ 更新视觉和计数

#### 4. 部门计数更新函数（Line 921-975）

```javascript
// 从部门选择统计人员数量
function updateSelectedPersonnelFromDepartments() {
    const selectedDepts = document.querySelectorAll('input[name="selected_departments[]"]:checked');
    let totalPeople = 0;
    
    // 统计总人数
    selectedDepts.forEach(checkbox => {
        const deptItem = checkbox.closest('.bmo-dept-item');
        if (deptItem) {
            const personCountEl = deptItem.querySelector('.text-muted.small');
            if (personCountEl) {
                const count = parseInt(personCountEl.textContent) || 0;
                totalPeople += count;
            }
        }
    });
    
    // 显示部门选择信息框
    const departmentSelectedBox = document.getElementById('departmentSelectedBox');
    const selectedDeptCount = document.getElementById('selectedDeptCount');
    const selectedDepartmentsList = document.getElementById('selectedDepartmentsList');
    
    // 更新已选部门数量
    selectedDeptCount.textContent = selectedDepts.length;
    
    // 根据是否有选择显示不同内容
    if (selectedDepts.length === 0) {
        departmentSelectedBox.style.display = 'none';
    } else {
        departmentSelectedBox.style.display = 'block';
        
        // 构建部门名称列表
        const deptNames = Array.from(selectedDepts).map(cb => {
            const deptItem = cb.closest('.bmo-dept-item');
            if (deptItem) {
                const nameEl = deptItem.querySelector('strong');
                const personCountEl = deptItem.querySelector('.text-muted.small');
                const name = nameEl ? nameEl.textContent.trim() : '未知';
                const count = personCountEl ? parseInt(personCountEl.textContent) || 0 : 0;
                return `${name} (${count}人)`;
            }
            return '未知';
        });
        
        // 生成 HTML
        selectedDepartmentsList.innerHTML = `
            <div class="d-flex flex-wrap gap-2">
                ${deptNames.map(name => `<span class="bmo-person-badge">${name}</span>`).join('')}
            </div>
            <div class="mt-2 text-muted small">
                <i class="bi bi-info-circle me-1"></i>共 ${totalPeople} 人
            </div>
        `;
    }
}
```

**关键特性：**
- ✅ 统计选中部门的总人数
- ✅ 控制显示/隐藏信息框
- ✅ 显示部门名称和人数
- ✅ 显示总计信息

---

## 为什么页面刷新后没有变化？

### 可能的原因

1. **浏览器缓存了 PHP 输出**
   - PHP 是服务器端语言，每次请求都会重新执行
   - 但浏览器可能缓存了 HTML/CSS/JS

2. **CDN 或代理缓存**
   - 如果使用了 CDN 或反向代理，可能缓存了页面

3. **OPcache 缓存**
   - PHP OPcache 可能缓存了编译后的代码

4. **会话数据未重置**
   - `$_SESSION['project_id']` 等会话数据影响页面内容

---

## 解决方案

### 方法 1：强制刷新浏览器（推荐）

**Windows/Linux:**
```
Ctrl + Shift + R
或
Ctrl + F5
```

**Mac:**
```
Cmd + Shift + R
```

### 方法 2：清除浏览器缓存

**Chrome:**
1. 按 `F12` 打开开发者工具
2. 右键点击刷新按钮
3. 选择"清空缓存并硬性重新加载"

**Firefox:**
1. 按 `Ctrl + Shift + Delete`
2. 选择"缓存"
3. 点击"立即清除"

**Edge:**
1. 按 `Ctrl + Shift + Delete`
2. 选择"缓存的图片和文件"
3. 点击"立即清除"

### 方法 3：使用无痕模式

**最简单的方法：**
- Chrome: `Ctrl + Shift + N`
- Firefox: `Ctrl + Shift + P`
- Edge: `Ctrl + Shift + N`

然后访问：`http://your-domain/user/batch_meal_order.php`

### 方法 4：清除服务器端缓存

**清除 PHP OPcache:**
```bash
# SSH 登录服务器
sudo systemctl restart php-fpm
# 或
sudo systemctl restart php7.4-fpm
# 或根据你的 PHP 版本
sudo systemctl restart php8.0-fpm
```

**清除 APCu 缓存（如果使用）:**
```bash
sudo systemctl restart php-fpm
```

### 方法 5：添加版本号强制刷新

在页面的 CSS 和 JS 引用中添加版本号参数：

```php
<!-- 在 header.php 或页面底部 -->
<link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
<script src="assets/js/main.js?v=<?php echo time(); ?>"></script>
```

或者直接在 batch_meal_order.php 中：

```html
<style>
/* CSS 代码 */
</style>
<script>
// JavaScript 代码
</script>
```

由于 CSS 和 JS 是直接内联在 PHP 文件中的，PHP 每次都会重新执行，所以应该会获取最新代码。

---

## 验证步骤

### 步骤 1：检查文件修改时间

```bash
ls -la /www/wwwroot/livegig.cn/user/batch_meal_order.php
```

应该显示最近的修改时间。

### 步骤 2：检查 PHP 语法

```bash
php -l /www/wwwroot/livegig.cn/user/batch_meal_order.php
```

应该输出：`No syntax errors detected`

### 步骤 3：查看文件内容确认修改

```bash
# 检查是否包含部门信息显示区
grep -n "departmentSelectedBox" /www/wwwroot/livegig.cn/user/batch_meal_order.php

# 检查是否包含更新函数
grep -n "updateSelectedPersonnelFromDepartments" /www/wwwroot/livegig.cn/user/batch_meal_order.php

# 检查标签点击事件
grep -n "toggleDepartmentSelection" /www/wwwroot/livegig.cn/user/batch_meal_order.php | head -5
```

### 步骤 4：浏览器开发者工具

1. 按 `F12` 打开开发者工具
2. 切换到 **Network（网络）** 标签
3. 勾选 **Disable cache（禁用缓存）**
4. 刷新页面
5. 查看 `batch_meal_order.php` 的请求
6. 点击响应，查看返回的 HTML 代码
7. 搜索 `departmentSelectedBox` 确认是否存在

### 步骤 5：控制台测试

1. 按 `F12` 打开开发者工具
2. 切换到 **Console（控制台）** 标签
3. 输入以下命令测试：

```javascript
// 测试函数是否存在
console.log(typeof toggleDepartmentSelection); // 应该输出 "function"
console.log(typeof updateSelectedPersonnelFromDepartments); // 应该输出 "function"

// 测试 DOM 元素是否存在
console.log(document.getElementById('departmentSelectedBox')); // 应该输出 div 元素

// 手动触发测试
const deptCheckbox = document.querySelector('.department-checkbox');
if (deptCheckbox) {
    deptCheckbox.click();
    console.log('部门复选框已点击');
}
```

---

## 功能测试清单

### ✅ 部门选择模式测试

1. **选择单个部门**
   - [ ] 点击部门标签任意位置
   - [ ] 复选框被勾选
   - [ ] 标签显示选中样式（蓝色边框、渐变背景、右上角徽章）
   - [ ] 右侧显示"已选部门 1 个"
   - [ ] 显示部门名称和人数（例如：开发部 (12 人)）
   - [ ] 显示"共 12 人"

2. **选择多个部门**
   - [ ] 按住 Ctrl/Cmd 点击多个部门
   - [ ] 每个部门都显示选中样式
   - [ ] 右侧显示"已选部门 X 个"
   - [ ] 显示所有选中部门的列表
   - [ ] 总人数正确累加

3. **取消选择**
   - [ ] 再次点击已选部门
   - [ ] 复选框取消勾选
   - [ ] 标签取消选中样式
   - [ ] 右侧信息更新
   - [ ] 全部取消后显示"暂无选择"或直接隐藏

4. **直接点击复选框**
   - [ ] 点击复选框本身
   - [ ] 复选框状态切换
   - [ ] 标签样式同步更新
   - [ ] 右侧信息更新

### ✅ 个人选择模式测试

1. **选择单个人员**
   - [ ] 点击人员标签任意位置
   - [ ] 复选框被勾选
   - [ ] 标签显示选中样式（绿色边框、渐变背景、右上角徽章）
   - [ ] 右侧显示"已选人员 1 人"
   - [ ] 显示人员姓名徽章

2. **全选功能**
   - [ ] 点击"全选"按钮
   - [ ] 所有人员都被选中
   - [ ] 右侧显示实际人数
   - [ ] 再次点击取消全选

### ✅ 交互功能测试

1. **Ctrl+ 点击多选**
   - [ ] 按住 Ctrl 键（Mac 为 Cmd 键）
   - [ ] 点击多个标签
   - [ ] 每个都能独立选择/取消

2. **视觉反馈**
   - [ ] 悬停时上浮动画
   - [ ] 选中时边框加粗
   - [ ] 右上角圆形徽章
   - [ ] 渐变背景效果

3. **实时更新**
   - [ ] 选择后立即显示
   - [ ] 取消选择后立即更新
   - [ ] 数字变化无延迟

---

## 如果仍然无效

### 调试步骤

1. **检查 PHP 错误日志**
```bash
tail -f /var/log/php/error.log
```

2. **检查文件权限**
```bash
ls -la /www/wwwroot/livegig.cn/user/batch_meal_order.php
# 确保 web 服务器用户有读取权限
```

3. **检查会话数据**
```php
// 在文件顶部添加调试代码
<?php
session_start();
error_log("Project ID: " . ($_SESSION['project_id'] ?? 'null'));
error_log("User ID: " . ($_SESSION['user_id'] ?? 'null'));
?>
```

4. **使用 var_dump 调试**
```php
// 在部门选择区域前添加
<?php
echo "<pre>";
var_dump($departments);
echo "</pre>";
?>
```

### 备用方案

如果以上方法都无效，可以尝试：

1. **重命名文件测试**
```bash
cp /www/wwwroot/livegig.cn/user/batch_meal_order.php \
   /www/wwwroot/livegig.cn/user/batch_meal_order_test.php
```
然后访问新文件测试

2. **创建全新的测试文件**
```php
<?php
// 简单的测试文件
echo "Hello from test file!<br>";
echo "Current time: " . date('Y-m-d H:i:s') . "<br>";
echo "File modified: " . date('Y-m-d H:i:s', filemtime(__FILE__));
?>
```

---

## 联系支持

如果所有方法都无效，请提供以下信息：

1. **浏览器信息**
   - 浏览器类型和版本
   - 操作系统
   - 是否有浏览器扩展

2. **服务器信息**
   - PHP 版本 (`php -v`)
   - Web 服务器类型（Apache/Nginx）
   - 是否有使用缓存系统（Redis/Memcached）

3. **测试结果**
   - 哪些功能正常
   - 哪些功能不正常
   - 浏览器控制台的错误信息
   - Network 面板的截图

4. **截图或录屏**
   - 页面的完整截图
   - 开发者工具的 Console 标签
   - Network 面板的请求详情

---

**文档创建时间**: 2026-03-04  
**文件路径**: `/www/wwwroot/livegig.cn/user/batch_meal_order.php`  
**验证状态**: ✅ 代码已修改，✅ 语法检查通过，⚠️ 需要清除缓存测试
