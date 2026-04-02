# 测试文件管理规范

## 概述

本文档定义了项目中测试文件的管理规范，确保所有测试相关文件都存放在统一的目录结构中，便于维护和管理。

## 目录结构

```
/www/wwwroot/livegig.cn/tests/
├── README.md                    # 本文档
├── scripts/                     # 脚本类测试工具
│   ├── test_user_permissions.php
│   └── verify_permission_management.php
├── debug/                      # 错误追踪和调试工具
│   ├── php_error_simulator.php
│   ├── runtime_error_catcher.php
│   ├── deep_error_detector.php
│   └── realtime_error_tracer.php
├── integration/                # 集成测试文件（待扩展）
│   └── README.md
├── tools/                      # 辅助测试工具
│   └── README.md
├── *.php                       # 其他测试文件
└── menu_test.php
    test_menu_*.php
```

## 目录分类说明

| 目录 | 用途 | 文件类型示例 |
|------|------|-------------|
| `scripts/` | 脚本类测试工具 | 权限测试、配置验证 |
| `debug/` | 错误追踪和调试工具 | 错误模拟器、追踪器 |
| `integration/` | 集成测试文件 | API 测试、流程测试 |
| `tools/` | 辅助测试工具 | 快速测试、诊断工具 |
| 根目录 | 临时或杂项测试文件 | 菜单测试等 |

## 文件命名规范

1. **前缀要求**：
   - 测试文件应使用 `test_` 前缀
   - 调试工具可使用 `_debug` 后缀
   - 错误模拟器可使用 `_simulator` 后缀

2. **命名格式**：
   ```php
   test_<功能模块>_<具体功能>.php
   test_user_permissions.php
   test_menu_activation.php
   ```

## 规范要求

### 必须遵守

1. **统一存放**：所有新生成的测试文件必须存放在 `tests/` 目录及其子目录中
2. **禁止散落**：禁止在项目根目录、admin/、user/、scripts/ 等目录中创建新的测试文件
3. **目录分类**：根据测试文件类型将其放置在相应的子目录中
4. **路径更新**：移动测试文件后，必须更新所有引用该文件的代码路径

### 建议遵守

1. **文档注释**：测试文件头部应包含使用说明
2. **功能描述**：清楚描述测试的功能和预期结果
3. **执行方法**：注明运行方式（如 `php tests/scripts/xxx.php`）

## 现有测试文件清单

### scripts/ 目录
| 文件名 | 功能 | 运行方式 |
|--------|------|---------|
| `test_user_permissions.php` | 前台权限验证测试 | `php tests/scripts/test_user_permissions.php` |
| `verify_permission_management.php` | 权限管理系统验证 | `php tests/scripts/verify_permission_management.php` |

### debug/ 目录
| 文件名 | 功能 |
|--------|------|
| `php_error_simulator.php` | PHP 错误模拟器 |
| `runtime_error_catcher.php` | 运行时错误捕获器 |
| `deep_error_detector.php` | 深度错误检测器 |
| `realtime_error_tracer.php` | 实时错误追踪器 |

### 根目录测试文件
| 文件名 | 功能 |
|--------|------|
| `menu_test.php` | 菜单测试工具 |
| `test_menu_activation.php` | 菜单激活测试 |
| `test_menu_logic.php` | 菜单逻辑测试 |

## 常见问题

### Q: 如何运行测试文件？
A: 使用 PHP 命令行运行，例如：
```bash
php tests/scripts/test_user_permissions.php
```

### Q: 测试文件需要数据库连接吗？
A: 大部分测试文件需要包含正确的数据库配置文件。确保在运行时能找到 `config.php`。

### Q: 如何添加新的测试文件？
A:
1. 确定测试文件类型，选择合适的子目录
2. 使用 `test_` 前缀命名
3. 添加文档注释说明功能和使用方法
4. 移动到 `tests/` 目录

## 变更历史

| 日期 | 变更内容 |
|------|---------|
| 2026-04-02 | 初始版本，建立 tests/ 目录结构 |

## 参考

- 项目文档目录：`/www/wwwroot/livegig.cn/docs/`
- 日志目录：`/www/wwwroot/livegig.cn/logs/`
- 脚本目录：`/www/wwwroot/livegig.cn/scripts/`（仅存放业务脚本）
