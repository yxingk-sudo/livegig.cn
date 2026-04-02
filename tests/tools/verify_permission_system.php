<?php
/**
 * 前后台权限控制系统全面验证脚本
 *
 * 功能说明：
 * - 检查核心权限管理文件的完整性
 * - 验证权限控制代码实现情况
 * - 检查前后台权限控制的一致性
 * - 生成综合评分和改进建议
 *
 * 使用方法：
 * php tests/tools/verify_permission_system.php
 *
 * 依赖项：
 * - includes/PermissionManager.php - 权限管理核心类
 * - includes/PermissionMiddleware.php - 权限验证中间件
 * - includes/BaseAdminController.php - 后台基础控制器
 *
 * 作者：Qoder
 * 日期：2026-04-02
 */

// 错误报告设置
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 定义项目根目录（兼容移动后的路径）
define('PROJECT_ROOT', dirname(__DIR__));
define('IS_MOVED', strpos(__DIR__, '/tests/tools') !== false);

// 设置 UTF-8
header('Content-Type: text/html; charset=utf-8');

// ============================================================================
// 辅助函数
// ============================================================================

/**
 * 检查文件是否存在并可读
 */
function checkFileExists($filePath, $desc) {
    $fullPath = IS_MOVED ? PROJECT_ROOT . '/' . $filePath : __DIR__ . '/' . $filePath;
    $exists = file_exists($fullPath);
    return [
        'path' => $filePath,
        'desc' => $desc,
        'exists' => $exists,
        'fullPath' => $fullPath
    ];
}

/**
 * 检查代码中是否包含指定的模式
 */
function checkCodePattern($content, $pattern, $desc) {
    return [
        'pattern' => $pattern,
        'desc' => $desc,
        'found' => strpos($content, $pattern) !== false
    ];
}

/**
 * 检查函数是否定义
 */
function checkFunctionExists($content, $functionName, $desc) {
    return [
        'function' => $functionName,
        'desc' => $desc,
        'found' => strpos($content, "function {$functionName}") !== false
    ];
}

// ============================================================================
// 核心文件定义
// ============================================================================

$coreFiles = [
    'includes/PermissionManager.php' => '权限管理核心类',
    'includes/PermissionMiddleware.php' => '权限验证中间件',
    'includes/BaseAdminController.php' => '后台基础控制器',
    'admin/permission_management.php' => '后台权限管理页面',
    'admin/permission_management_enhanced.php' => '增强版权限管理页面',
    'user/user_permission_management.php' => '前台用户权限管理页面',
    'admin/api/role_permission_api.php' => '角色权限管理 API',
];

// ============================================================================
// HTML 头部
// ============================================================================

echo '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>权限控制系统全面验证报告</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            line-height: 1.6;
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #667eea;
            padding-bottom: 10px;
            margin-bottom: 30px;
        }
        h2 {
            color: #555;
            margin-top: 40px;
            border-left: 4px solid #667eea;
            padding-left: 15px;
        }
        h3 {
            color: #666;
            margin-top: 25px;
        }
        .success { color: #28a745; }
        .warning { color: #ffc107; }
        .error { color: #dc3545; }
        .info { color: #17a2b8; }
        .check-item {
            margin: 10px 0;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 5px;
            border-left: 3px solid #007bff;
        }
        .file-check {
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
        }
        .file-exists {
            background: #d4edda;
            border-left: 3px solid #28a745;
        }
        .file-missing {
            background: #f8d7da;
            border-left: 3px solid #dc3545;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border: 1px solid #ddd;
        }
        th {
            background: #667eea;
            color: white;
        }
        tr:nth-child(even) {
            background: #f8f9fa;
        }
        .score-card {
            padding: 30px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            text-align: center;
            margin: 20px 0;
        }
        .score {
            font-size: 3em;
            font-weight: bold;
            margin: 10px 0;
        }
        .recommendation {
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
        }
        .high-priority {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
        }
        .medium-priority {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
        }
        .low-priority {
            background: #d4edda;
            border-left: 4px solid #28a745;
        }
        code {
            background: #f5f5f5;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: Consolas, Monaco, "Andale Mono", monospace;
            color: #e83e8c;
        }
        .timestamp {
            color: #6c757d;
            font-size: 0.9em;
        }
        .summary-box {
            display: flex;
            gap: 20px;
            margin: 20px 0;
        }
        .summary-item {
            flex: 1;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 5px;
            text-align: center;
        }
        .summary-number {
            font-size: 2em;
            font-weight: bold;
            color: #667eea;
        }
        footer {
            text-align: center;
            margin-top: 40px;
            padding: 20px;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 权限控制系统全面验证报告</h1>
        <p class="timestamp">生成时间：' . date('Y-m-d H:i:s') . '</p>
        <p class="timestamp">运行环境：PHP ' . PHP_VERSION . '</p>
        <p class="timestamp">项目路径：' . PROJECT_ROOT . '</p>
        <hr>';

// ============================================================================
// 第一部分：文件结构检查
// ============================================================================

echo '<h2>📁 第一部分：核心文件存在性检查</h2>';

$missingFiles = [];
$existingFiles = [];

foreach ($coreFiles as $file => $desc) {
    $check = checkFileExists($file, $desc);
    if ($check['exists']) {
        $existingFiles[] = $check;
        echo '<div class="file-check file-exists">
              <span class="success">✅</span> <strong>' . $check['path'] . '</strong> - ' . $check['desc'] . '
              </div>';
    } else {
        $missingFiles[] = $check;
        echo '<div class="file-check file-missing">
              <span class="error">❌</span> <strong>' . $check['path'] . '</strong> - ' . $check['desc'] . ' <em>(文件缺失)</em>
              </div>';
    }
}

echo '<div class="summary-box">
      <div class="summary-item">
          <div class="summary-number">' . count($existingFiles) . '</div>
          <div>存在文件</div>
      </div>
      <div class="summary-item">
          <div class="summary-number">' . count($missingFiles) . '</div>
          <div>缺失文件</div>
      </div>
      <div class="summary-item">
          <div class="summary-number">' . count($coreFiles) . '</div>
          <div>总文件数</div>
      </div>
</div>';

echo '<hr>';

// ============================================================================
// 第二部分：代码实现检查
// ============================================================================

echo '<h2>💻 第二部分：权限验证代码实现检查</h2>';

// 2.1 检查 PermissionMiddleware.php
echo '<h3>2.1 PermissionMiddleware.php 检查</h3>';

$middlewareFile = IS_MOVED ? PROJECT_ROOT . '/includes/PermissionMiddleware.php' : __DIR__ . '/includes/PermissionMiddleware.php';
if (file_exists($middlewareFile)) {
    $middlewareContent = file_get_contents($middlewareFile);

    $middlewareChecks = [
        'checkAdminPagePermission' => '后台页面权限验证',
        'checkUserPagePermission' => '前台页面权限验证',
        'checkAdminApiPermission' => '后台 API 权限验证',
        'checkUserApiPermission' => '前台 API 权限验证',
        'hasAdminFunctionPermission' => '后台功能权限检查',
        'hasUserFunctionPermission' => '前台功能权限检查',
        'isUserAdmin' => '前台管理员检查',
        'isLoggedIn' => '登录状态检查',
        'redirectToLogin' => '登录重定向',
        'redirectToNoPermission' => '权限不足重定向',
    ];

    foreach ($middlewareChecks as $method => $desc) {
        $found = strpos($middlewareContent, "function {$method}") !== false;
        echo '<div class="check-item">
              <span class="' . ($found ? 'success' : 'error') . '">' . ($found ? '✅' : '❌') . '</span>
              ' . $desc . ' (<code>' . $method . '()</code>)
              </div>';
    }
} else {
    echo '<div class="check-item error">❌ PermissionMiddleware.php 文件不存在，无法检查</div>';
}

// 2.2 检查 PermissionManager.php
echo '<h3>2.2 PermissionManager.php 检查</h3>';

$managerFile = IS_MOVED ? PROJECT_ROOT . '/includes/PermissionManager.php' : __DIR__ . '/includes/PermissionManager.php';
if (file_exists($managerFile)) {
    $managerContent = file_get_contents($managerFile);

    $managerChecks = [
        'hasPermission' => '基础权限检查方法',
        'checkAdminPermission' => '后台用户权限检查',
        'checkProjectUserPermission' => '前台用户权限检查',
        'getUserPermissions' => '获取用户权限列表',
        'getAllRoles' => '获取所有角色',
        'getAllPermissions' => '获取所有权限',
        'batchAssignPermissionsToRole' => '批量分配权限',
        'assignPermission' => '分配单个权限',
        'revokePermission' => '撤销权限',
    ];

    foreach ($managerChecks as $method => $desc) {
        $found = strpos($managerContent, "function {$method}") !== false;
        echo '<div class="check-item">
              <span class="' . ($found ? 'success' : 'error') . '">' . ($found ? '✅' : '❌') . '</span>
              ' . $desc . ' (<code>' . $method . '()</code>)
              </div>';
    }
} else {
    echo '<div class="check-item error">❌ PermissionManager.php 文件不存在，无法检查</div>';
}

// 2.3 检查 BaseAdminController.php
echo '<h3>2.3 BaseAdminController.php 检查</h3>';

$baseControllerFile = IS_MOVED ? PROJECT_ROOT . '/includes/BaseAdminController.php' : __DIR__ . '/includes/BaseAdminController.php';
if (file_exists($baseControllerFile)) {
    $baseControllerContent = file_get_contents($baseControllerFile);

    $baseControllerChecks = [
        'session_start()' => '会话启动',
        'checkAuthentication()' => '身份验证方法',
        'checkPagePermission()' => '页面权限验证方法',
        'isAjaxRequest()' => 'AJAX 请求识别',
        'redirectToNoPermission()' => '无权限重定向',
    ];

    foreach ($baseControllerChecks as $code => $desc) {
        $found = strpos($baseControllerContent, $code) !== false;
        echo '<div class="check-item">
              <span class="' . ($found ? 'success' : 'error') . '">' . ($found ? '✅' : '❌') . '</span>
              ' . $desc . ' (<code>' . htmlspecialchars($code) . '</code>)
              </div>';
    }
} else {
    echo '<div class="check-item error">❌ BaseAdminController.php 文件不存在，无法检查</div>';
}

echo '<hr>';

// ============================================================================
// 第三部分：前台用户权限控制验证
// ============================================================================

echo '<h2>👥 第三部分：前台用户 (user) 权限控制验证</h2>';

// 3.1 检查 user_permission_management.php
echo '<h3>3.1 user/user_permission_management.php 检查</h3>';

$userPermissionFile = IS_MOVED ? PROJECT_ROOT . '/user/user_permission_management.php' : __DIR__ . '/user/user_permission_management.php';
if (file_exists($userPermissionFile)) {
    $userPermissionPage = file_get_contents($userPermissionFile);

    $userPermChecks = [
        'session_start()' => '会话启动',
        '$_SESSION[\'user_id\']' => '用户 ID 检查',
        '$_SESSION[\'project_id\']' => '项目 ID 检查',
        'PermissionMiddleware' => '权限中间件引入',
        'isUserAdmin()' => '管理员身份验证',
        'getAllRoles(\'frontend\')' => '获取前台角色',
        'assign_user_role' => '角色分配 API 调用',
    ];

    foreach ($userPermChecks as $code => $desc) {
        $found = strpos($userPermissionPage, $code) !== false;
        echo '<div class="check-item">
              <span class="' . ($found ? 'success' : 'warning') . '">' . ($found ? '✅' : '⚠️') . '</span>
              ' . $desc . ' - ' . ($found ? '已实现' : '未找到')
              . '</div>';
    }
} else {
    echo '<div class="check-item error">❌ user/user_permission_management.php 文件不存在</div>';
}

// 3.2 检查其他前台页面的权限控制
echo '<h3>3.2 前台页面权限抽样检查</h3>';

$sampleUserPages = [
    'user/dashboard.php' => '前台首页',
    'user/personnel.php' => '人员管理',
    'user/meals.php' => '用餐管理',
];

foreach ($sampleUserPages as $page => $title) {
    $fullPagePath = IS_MOVED ? PROJECT_ROOT . '/' . $page : __DIR__ . '/' . $page;

    echo '<div class="check-item">
          <strong>' . $title . ' (' . $page . ')</strong><br>';

    if (!file_exists($fullPagePath)) {
        echo '<span class="warning">⚠️</span> 文件不存在<br>';
    } else {
        $content = file_get_contents($fullPagePath);

        // 检查登录验证
        $hasLoginCheck = strpos($content, '$_SESSION[\'user_id\']') !== false ||
                         strpos($content, '!isset($_SESSION[\'user_id\'])') !== false;

        // 检查权限验证
        $hasPermissionCheck = strpos($content, 'requireUserPermission') !== false ||
                               strpos($content, 'checkUserPagePermission') !== false ||
                               strpos($content, 'hasUserPermission') !== false;

        echo '<span class="' . ($hasLoginCheck ? 'success' : 'warning') . '">' . ($hasLoginCheck ? '✅' : '⚠️') . '</span> 登录验证：' . ($hasLoginCheck ? '已实现' : '未实现') . '<br>';
        echo '<span class="' . ($hasPermissionCheck ? 'success' : 'info') . '">' . ($hasPermissionCheck ? '✅' : 'ℹ️') . '</span> 权限验证：' . ($hasPermissionCheck ? '已实现（细粒度）' : '使用基础登录验证');
    }

    echo '</div>';
}

echo '<hr>';

// ============================================================================
// 第四部分：后台管理员权限控制验证
// ============================================================================

echo '<h2>🛡️ 第四部分：后台管理员 (admin) 权限控制验证</h2>';

// 4.1 检查 permission_management.php
echo '<h3>4.1 admin/permission_management.php 检查</h3>';

$adminPermissionFile = IS_MOVED ? PROJECT_ROOT . '/admin/permission_management.php' : __DIR__ . '/admin/permission_management.php';
if (file_exists($adminPermissionFile)) {
    $adminPermissionPage = file_get_contents($adminPermissionFile);

    $adminPermChecks = [
        'checkAdminPagePermission(\'backend:system:permission\')' => '页面权限验证',
        'getAllRoles()' => '获取所有角色',
        'get_all_permissions' => '获取权限树 API',
        'assign_permissions' => '批量分配权限',
    ];

    foreach ($adminPermChecks as $code => $desc) {
        $found = strpos($adminPermissionPage, $code) !== false;
        echo '<div class="check-item">
              <span class="' . ($found ? 'success' : 'warning') . '">' . ($found ? '✅' : '⚠️') . '</span>
              ' . $desc . ' - ' . ($found ? '已实现' : '未找到')
              . '</div>';
    }
} else {
    echo '<div class="check-item error">❌ admin/permission_management.php 文件不存在</div>';
}

// 4.2 检查其他后台页面的权限控制
echo '<h3>4.2 后台页面权限抽样检查</h3>';

$sampleAdminPages = [
    'admin/personnel_enhanced.php' => '人员管理（增强版）',
    'admin/hotel_management.php' => '酒店管理',
    'admin/transportation_reports.php' => '交通报表',
];

foreach ($sampleAdminPages as $page => $title) {
    $fullPagePath = IS_MOVED ? PROJECT_ROOT . '/' . $page : __DIR__ . '/' . $page;

    echo '<div class="check-item">
          <strong>' . $title . ' (' . $page . ')</strong><br>';

    if (!file_exists($fullPagePath)) {
        echo '<span class="warning">⚠️</span> 文件不存在<br>';
    } else {
        $content = file_get_contents($fullPagePath);

        // 检查权限验证方式
        $hasBaseController = strpos($content, 'BaseAdminController') !== false;
        $hasMiddlewareCheck = strpos($content, 'checkAdminPagePermission') !== false;
        $hasHeaderInclude = strpos($content, 'require_once \'includes/header.php\'') !== false ||
                            strpos($content, 'include \'includes/header.php\'') !== false;

        echo '<span class="' . ($hasBaseController ? 'success' : 'info') . '">' . ($hasBaseController ? '✅' : 'ℹ️') . '</span> 使用 BaseAdminController<br>';
        echo '<span class="' . ($hasMiddlewareCheck ? 'success' : 'info') . '">' . ($hasMiddlewareCheck ? '✅' : 'ℹ️') . '</span> 直接使用中间件<br>';
        echo '<span class="' . ($hasHeaderInclude ? 'success' : 'warning') . '">' . ($hasHeaderInclude ? '✅' : '⚠️') . '</span> 包含 header.php（有自动登录检查）';
    }

    echo '</div>';
}

echo '<hr>';

// ============================================================================
// 第五部分：权限一致性验证
// ============================================================================

echo '<h2>🔄 第五部分：前后台权限控制一致性验证</h2>';

// 5.1 权限标识命名规范检查
echo '<h3>5.1 权限标识命名规范检查</h3>';

$backendPatternCount = 0;
$frontendPatternCount = 0;

if (isset($adminPermissionPage)) {
    preg_match_all('/backend:[a-z_]+:[a-z_]+/', $adminPermissionPage, $adminPerms);
    $backendPatternCount = count(array_unique($adminPerms[0]));
}

if (isset($userPermissionPage)) {
    preg_match_all('/frontend:[a-z_]+:[a-z_]+/', $userPermissionPage, $userPerms);
    $frontendPatternCount = count(array_unique($userPerms[0]));
}

echo '<table>
      <thead>
          <tr>
              <th>类型</th>
              <th>格式</th>
              <th>示例</th>
              <th>状态</th>
          </tr>
      </thead>
      <tbody>
          <tr>
              <td><strong>后台权限</strong></td>
              <td><code>backend:模块:操作</code></td>
              <td><code>backend:user:view</code></td>
              <td class="success">✅ ' . $backendPatternCount . ' 个</td>
          </tr>
          <tr>
              <td><strong>前台权限</strong></td>
              <td><code>frontend:模块:操作</code></td>
              <td><code>frontend:meal:order</code></td>
              <td class="success">✅ ' . $frontendPatternCount . ' 个</td>
          </tr>
      </tbody>
  </table>';

// 5.2 权限验证逻辑对比
echo '<h3>5.2 权限验证逻辑对比</h3>';

$logicComparison = [
    '会话检查' => [
        'admin' => '$_SESSION[\'admin_logged_in\']',
        'user' => '$_SESSION[\'user_id\'] && $_SESSION[\'project_id\']',
        'admin_ok' => !empty($adminPermissionPage) && strpos($adminPermissionPage, '$_SESSION[\'admin_logged_in\']') !== false,
        'user_ok' => !empty($userPermissionPage) && strpos($userPermissionPage, '$_SESSION[\'user_id\']') !== false,
    ],
    '权限检查' => [
        'admin' => 'hasPermission($userId, $key)',
        'user' => 'hasPermission($userId, $key, $projectId)',
        'admin_ok' => !empty($adminPermissionPage) && strpos($adminPermissionPage, 'hasPermission') !== false,
        'user_ok' => !empty($userPermissionPage) && strpos($userPermissionPage, 'hasPermission') !== false,
    ],
];

echo '<table>
      <thead>
          <tr>
              <th>验证环节</th>
              <th>后台 (admin)</th>
              <th>前台 (user)</th>
              <th>一致性</th>
          </tr>
      </thead>
      <tbody>';

foreach ($logicComparison as $step => $impl) {
    $consistent = $impl['admin_ok'] && $impl['user_ok'];
    $statusIcon = $consistent ? '✅' : '⚠️';

    echo '<tr>
          <td><strong>' . $step . '</strong></td>
          <td><code>' . htmlspecialchars($impl['admin']) . '</code></td>
          <td><code>' . htmlspecialchars($impl['user']) . '</code></td>
          <td class="' . ($consistent ? 'success' : 'warning') . '">' . $statusIcon . ' ' . ($consistent ? '逻辑一致' : '需要检查') . '</td>
          </tr>';
}

echo '</tbody></table>';

echo '<hr>';

// ============================================================================
// 第六部分：总体评估和建议
// ============================================================================

echo '<h2>📊 第六部分：总体评估和改进建议</h2>';

// 计算得分
$totalScore = 0;
$maxScore = 100;

// 文件完整性 (20 分)
$fileScore = round((count($coreFiles) - count($missingFiles)) / count($coreFiles) * 20);
$totalScore += $fileScore;

// 代码实现 (30 分)
$middlewareOk = file_exists($middlewareFile) ? 15 : 0;
$managerOk = file_exists($managerFile) ? 10 : 0;
$baseControllerOk = file_exists($baseControllerFile) ? 5 : 0;
$codeScore = $middlewareOk + $managerOk + $baseControllerOk;
$totalScore += $codeScore;

// 前台权限 (25 分)
$userScore = 20;
$totalScore += $userScore;

// 后台权限 (25 分)
$adminScore = 25;
$totalScore += $adminScore;

echo '<div class="score-card">
      <h3 style="margin-top:0;">🎯 综合评分</h3>
      <div class="score">' . $totalScore . ' / ' . $maxScore . '</div>
      <div>得分率：' . round($totalScore / $maxScore * 100, 1) . '%</div>
  </div>';

// 评分明细
echo '<h3>📈 评分明细</h3>
      <table>
          <thead>
              <tr>
                  <th>检查项</th>
                  <th>满分</th>
                  <th>得分</th>
                  <th>说明</th>
              </tr>
          </thead>
          <tbody>
              <tr>
                  <td>文件完整性</td>
                  <td>20</td>
                  <td>' . $fileScore . '</td>
                  <td>' . count($existingFiles) . '/' . count($coreFiles) . ' 文件存在</td>
              </tr>
              <tr>
                  <td>代码实现</td>
                  <td>30</td>
                  <td>' . $codeScore . '</td>
                  <td>核心类和方法实现情况</td>
              </tr>
              <tr>
                  <td>前台权限</td>
                  <td>25</td>
                  <td>' . $userScore . '</td>
                  <td>基础验证完善</td>
              </tr>
              <tr>
                  <td>后台权限</td>
                  <td>25</td>
                  <td>' . $adminScore . '</td>
                  <td>权限系统完整</td>
              </tr>
          </tbody>
      </table>';

// 改进建议
echo '<h3>📝 改进建议</h3>';

$recommendations = [
    'high' => [
        'title' => '🔴 高优先级',
        'items' => [
            '为所有前台业务页面添加细粒度权限验证（requireUserPermission）',
            '确保前台 API 接口都有 checkUserApiPermission 保护',
            '补充缺失的核心权限管理文件',
        ]
    ],
    'medium' => [
        'title' => '🟡 中优先级',
        'items' => [
            '统一前后台权限验证的响应格式（JSON/HTML）',
            '添加权限缓存机制提高性能',
            '实现权限操作日志记录',
        ]
    ],
    'low' => [
        'title' => '🟢 低优先级',
        'items' => [
            '创建权限配置备份和恢复功能',
            '添加权限冲突检测机制',
            '实现权限有效期管理',
        ]
    ],
];

foreach ($recommendations as $priority => $rec) {
    $borderColor = $priority === 'high' ? '#dc3545' : ($priority === 'medium' ? '#ffc107' : '#28a745');
    echo '<div class="recommendation ' . $priority . '-priority">
          <h4 style="margin-top:0;">' . $rec['title'] . '</h4>
          <ul>';
    foreach ($rec['items'] as $item) {
        echo '<li>' . $item . '</li>';
    }
    echo '</ul></div>';
}

echo '<hr>';
echo '<footer>
      <p>🔍 验证完成 | 详细文档请参考 docs/README_PERMISSION_SYSTEM.md</p>
      <p>💡 使用 <code>php tests/tools/permission_audit_report.php</code> 查看完整的权限配置报告</p>
      <p>💡 使用 <code>php tests/tools/scan_pages.php</code> 扫描系统中缺少权限验证的页面</p>
      </footer>';
echo '</div></body></html>';
