# 批量报餐页面强制刷新测试指南

## ✅ 已应用的修改

文件 `/www/wwwroot/livegig.cn/user/batch_meal_order.php` 已经包含以下更新：

### 1. 防缓存 Header（Line 7-9）
```php
// 禁止浏览器缓存
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
```

### 2. 版本标识（Line 4）
```php
// 版本：2026-03-05-v2 (包含部门选择显示修复)
```

### 3. JavaScript 调试信息（Line 774-785）
```javascript
console.log('=== 批量报餐页面脚本已加载 ===');
console.log('版本：2026-03-05-v2');
console.log('包含功能：部门选择显示、全区域点击、状态同步');

document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM 加载完成');
    
    // 测试函数是否存在
    console.log('toggleDepartmentSelection:', typeof window.toggleDepartmentSelection);
    console.log('updateSelectedPersonnelFromDepartments:', typeof window.updateSelectedPersonnelFromDepartments);
    // ...
});
```

---

## 🧪 测试步骤（必须严格按顺序执行）

### 步骤 1：清除所有缓存

**Chrome 浏览器：**
1. 按 `F12` 打开开发者工具
2. 按 `Ctrl + Shift + Delete`
3. 时间范围选择"**所有时间**"
4. 勾选：
   - ✅ 浏览历史记录
   - ✅ Cookie 及其他网站数据
   - ✅ 缓存的图片和文件
5. 点击"**清除数据**"

### 步骤 2：关闭所有浏览器窗口

完全关闭 Chrome 浏览器（包括所有标签页和后台进程）

### 步骤 3：重新启动浏览器并访问

1. 重新打开 Chrome 浏览器
2. **不要打开书签或历史记录**
3. 直接在地址栏输入：`http://your-domain/user/batch_meal_order.php`
4. 按 Enter 访问

### 步骤 4：打开开发者工具检查

1. 按 `F12` 打开开发者工具
2. 切换到 **Console（控制台）** 标签
3. 应该看到以下输出：

```
=== 批量报餐页面脚本已加载 ===
版本：2026-03-05-v2
包含功能：部门选择显示、全区域点击、状态同步
DOM 加载完成
toggleDepartmentSelection: function
updateSelectedPersonnelFromDepartments: function
```

**如果看到以上输出，说明脚本已成功加载！**

### 步骤 5：Network 面板验证

1. 在开发者工具中，切换到 **Network（网络）** 标签
2. 刷新页面（`F5` 或 `Ctrl+R`）
3. 找到 `batch_meal_order.php` 请求
4. 点击该请求
5. 查看 **Response Headers（响应头）**
6. 应该看到：

```
Cache-Control: no-cache, no-store, must-revalidate
Pragma: no-cache
Expires: 0
```

**如果看到这些 header，说明防缓存设置已生效！**

### 步骤 6：功能测试

#### 6.1 部门选择模式测试

1. **选择"按部门选择"模式**
   - 点击"按部门选择"按钮
   - 部门选择区域应该展开

2. **点击任意部门标签**
   - 点击标签的任意位置（如：开发部）
   - 观察控制台应该有日志输出

3. **检查右侧显示**
   - 应该出现一个蓝色边框的信息框
   - 标题："已选部门 1 个"
   - 内容："[部门名称] (X 人)"，例如："开发部 (12 人)"
   - 底部："ℹ️ 共 X 人"

4. **多选测试**
   - 按住 `Ctrl` 键（Mac 为 `Cmd` 键）
   - 点击另一个部门
   - 右侧应该显示两个部门
   - 总人数应该正确累加

5. **取消选择测试**
   - 再次点击已选部门
   - 右侧信息应该更新
   - 全部取消后，信息框应该消失

#### 6.2 个人选择模式测试

1. **切换到个人选择**
   - 点击"按个人选择"按钮
   - 个人选择区域展开
   - 右侧显示"已选人员 0 人"

2. **点击人员标签**
   - 点击任意人员标签
   - 右侧显示"已选人员 1 人"
   - 显示该人员姓名的蓝色徽章

3. **全选测试**
   - 点击"全选"按钮
   - 所有人员都被选中
   - 右侧显示实际人数

---

## ❌ 如果仍然无效，请检查以下内容

### 检查 1：文件修改时间

在终端执行：
```bash
ls -la /www/wwwroot/livegig.cn/user/batch_meal_order.php
```

应该显示最近的修改时间（2026-03-05 或之后）

### 检查 2：PHP 语法

```bash
php -l /www/wwwroot/livegig.cn/user/batch_meal_order.php
```

应该输出：`No syntax errors detected`

### 检查 3：关键代码存在性

```bash
grep -n "departmentSelectedBox" /www/wwwroot/livegig.cn/user/batch_meal_order.php
```

应该输出至少 4 行结果

### 检查 4：服务器重启

如果是生产环境，可能需要重启 Web 服务器：

```bash
# Apache
sudo systemctl restart httpd
# 或
sudo systemctl restart apache2

# Nginx + PHP-FPM
sudo systemctl restart nginx
sudo systemctl restart php-fpm
# 或根据 PHP 版本
sudo systemctl restart php7.4-fpm
sudo systemctl restart php8.0-fpm
sudo systemctl restart php8.1-fpm
```

### 检查 5：OPcache 清除

如果使用了 OPcache，需要清除：

**方法 1：重启 PHP-FPM**（推荐）
```bash
sudo systemctl restart php-fpm
```

**方法 2：创建清除脚本**
```php
<?php
// clear_opcache.php
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache 已清除";
} else {
    echo "未启用 OPcache";
}
?>
```
然后访问：`http://your-domain/clear_opcache.php`

---

## 🔍 调试技巧

### 技巧 1：使用无痕模式

**最可靠的方法！**

Chrome: `Ctrl + Shift + N`
Firefox: `Ctrl + Shift + P`
Edge: `Ctrl + Shift + N`

然后访问：`http://your-domain/user/batch_meal_order.php`

### 技巧 2：添加时间戳参数

在 URL 后面添加时间戳：
```
http://your-domain/user/batch_meal_order.php?t=20260305150000
```

### 技巧 3：禁用所有扩展

某些浏览器扩展可能干扰页面加载：
1. 访问 `chrome://extensions/`
2. 禁用所有扩展
3. 刷新页面测试

### 技巧 4：使用 curl 测试

在终端执行：
```bash
curl -I http://your-domain/user/batch_meal_order.php
```

检查响应头是否包含防缓存设置

### 技巧 5：查看实际 HTML

在浏览器中：
1. 右键点击页面
2. 选择"**查看页面源代码**"（不是"检查"）
3. 搜索 `departmentSelectedBox`
4. 如果找到，说明 HTML 已正确返回

---

## 📊 预期控制台输出

成功的标志是看到以下所有输出：

```javascript
=== 批量报餐页面脚本已加载 ===
版本：2026-03-05-v2
包含功能：部门选择显示、全区域点击、状态同步
DOM 加载完成
toggleDepartmentSelection: function
updateSelectedPersonnelFromDepartments: function

// 当你点击部门时，还应该有：
// （无错误信息）
```

**如果看到任何错误信息（红色），请截图并提供给我！**

---

## 🎯 成功标准

✅ **HTML 结构**
- [ ] 部门选择区域包含 `id="departmentSelectedBox"` 的 div
- [ ] 该 div 初始状态为 `display:none`

✅ **JavaScript 功能**
- [ ] 控制台显示 "toggleDepartmentSelection: function"
- [ ] 控制台显示 "updateSelectedPersonnelFromDepartments: function"
- [ ] 无 JavaScript 错误

✅ **交互功能**
- [ ] 点击部门标签能切换复选框状态
- [ ] 选中后标签显示蓝色边框和右上角徽章
- [ ] 右侧显示"已选部门 X 个"
- [ ] 显示部门名称和人数

✅ **响应头**
- [ ] Cache-Control: no-cache, no-store, must-revalidate
- [ ] Pragma: no-cache
- [ ] Expires: 0

---

## 📞 需要进一步帮助？

如果以上所有步骤都完成了，但仍然看不到效果，请提供：

1. **控制台完整截图**
   - 包含所有日志和错误信息

2. **Network 面板截图**
   - batch_meal_order.php 请求的响应头

3. **页面源代码**
   - 右键 → 查看页面源代码
   - 搜索 "departmentSelectedBox"
   - 复制包含该词的整段 HTML

4. **浏览器信息**
   - 浏览器类型和版本
   - 操作系统
   - 是否使用了代理或 CDN

5. **测试结果**
   - 哪些步骤成功了
   - 哪些步骤失败了
   - 具体表现是什么

---

**更新时间**: 2026-03-05  
**版本**: v2  
**文件**: `/www/wwwroot/livegig.cn/user/batch_meal_order.php`  
**状态**: ✅ 已应用防缓存设置，✅ 已添加调试信息，✅ PHP 语法通过
