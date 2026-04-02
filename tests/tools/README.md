# 测试工具目录

此目录用于存放辅助测试工具和诊断脚本，包括权限系统验证工具。

## 目录结构

```
tools/
├── README.md                           # 本文档
├── quick_test.php                      # 快速测试工具
├── scan_pages.php                      # 页面扫描工具
├── permission_audit_report.php         # 权限配置审计报告
└── verify_permission_system.php        # 权限系统全面验证
```

## 权限系统检查工具

### 1. permission_audit_report.php - 权限配置完整性检查报告

**功能说明：**
- 检查系统中所有页面的权限配置完整性
- 对比数据库中的权限配置与实际页面
- 分析权限树结构
- 检查权限键值命名规范
- 生成详细的改进建议

**使用方法：**
```bash
php tests/tools/permission_audit_report.php
```

**输出内容：**
- 系统页面统计（管理后台和前台用户页面数量）
- 权限配置详情（已配置和缺少配置的页面）
- 权限树结构分析
- 权限键值规范性检查
- 改进建议

**依赖项：**
- `config/database.php` - 数据库配置
- `permissions` 表 - 权限配置表

### 2. scan_pages.php - 系统页面扫描与权限对比

**功能说明：**
- 扫描 admin 和 user 目录下的所有 PHP 页面
- 检测页面是否配置了权限验证
- 识别缺少权限验证的页面
- 生成权限配置对比报告

**使用方法：**
```bash
php tests/tools/scan_pages.php
```

**输出内容：**
- 各目录页面扫描结果
- 每个页面的权限配置状态
- 缺少权限验证的页面列表
- 统计信息（总页面数、已配置数、缺少数）
- 添加权限验证的建议代码

**特点：**
- 无需数据库连接，纯文件扫描
- 兼容移动后的目录结构

### 3. verify_permission_system.php - 权限系统全面验证

**功能说明：**
- 检查核心权限管理文件的完整性
- 验证权限控制代码实现情况
- 检查前后台权限控制的一致性
- 生成综合评分和改进建议

**使用方法：**
```bash
php tests/tools/verify_permission_system.php
```

**输出内容：**
- HTML 格式的综合报告
- 核心文件存在性检查
- PermissionMiddleware.php 方法检查
- PermissionManager.php 方法检查
- BaseAdminController.php 方法检查
- 前台和后台权限验证抽样检查
- 权限一致性验证
- 综合评分（0-100分）
- 分优先级（高/中/低）的改进建议

**依赖项：**
- `includes/PermissionManager.php`
- `includes/PermissionMiddleware.php`
- `includes/BaseAdminController.php`
- `admin/permission_management.php`
- `user/user_permission_management.php`

## 工具使用建议

### 建议的执行顺序

1. **首先运行** `verify_permission_system.php`
   - 检查核心权限管理文件是否完整
   - 了解整体权限系统状态

2. **其次运行** `scan_pages.php`
   - 快速扫描系统中缺少权限验证的页面
   - 获取概览

3. **最后运行** `permission_audit_report.php`
   - 获取详细的权限配置审计报告
   - 生成具体的改进建议

### 常用命令

```bash
# 快速检查权限系统完整性
php tests/tools/verify_permission_system.php

# 扫描缺少权限验证的页面
php tests/tools/scan_pages.php

# 生成完整的权限配置审计报告
php tests/tools/permission_audit_report.php
```

## 其他测试工具

- `quick_test.php` - 快速测试工具

## 文件命名规范

```
check_<检查项>.php          # 检查工具
diagnose_<问题>.php         # 诊断工具
quick_<功能>test.php        # 快速测试
scan_<目标>.php             # 扫描工具
verify_<系统>.php           # 验证工具
*_report.php               # 报告生成
*_audit.php                # 审计工具
```

## 注意事项

1. **权限要求：** 部分工具需要数据库连接权限
2. **路径兼容：** 所有工具都兼容移动后的目录结构
3. **错误处理：** 工具包含完善的错误处理机制
4. **输出格式：** 提供清晰的彩色输出（终端）或 HTML 格式（浏览器）

## 更新历史

| 日期 | 变更内容 |
|------|---------|
| 2026-04-02 | 优化三个权限检查工具，统一代码风格，增强错误处理 |
