<?php
session_start();
require_once '../config/database.php';

echo '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>权限检查</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <div class="card">
        <div class="card-header">
            <h3>当前权限检查</h3>
        </div>
        <div class="card-body">
            <h5>会话信息：</h5>
            <ul class="list-group">
                <li class="list-group-item"><strong>用户ID:</strong> ' . ($_SESSION['user_id'] ?? '未设置') . '</li>
                <li class="list-group-item"><strong>用户名:</strong> ' . ($_SESSION['username'] ?? '未设置') . '</li>
                <li class="list-group-item"><strong>显示名:</strong> ' . ($_SESSION['user_name'] ?? '未设置') . '</li>
                <li class="list-group-item"><strong>角色:</strong> ' . ($_SESSION['role'] ?? '未设置') . '</li>
                <li class="list-group-item"><strong>是否为管理员:</strong> ' . (strtolower(trim($_SESSION['role'] ?? '')) === 'admin' ? '<span class="text-success">是</span>' : '<span class="text-danger">否</span>') . '</li>
            </ul>
            
            <h5 class="mt-4">登录状态：</h5>
            <p>';

if (isset($_SESSION['user_id'])) {
    echo '<span class="text-success">✓ 已登录用户系统</span>';
} else {
    echo '<span class="text-danger">✗ 未登录用户系统</span>';
}

echo '</p>

<h5 class="mt-4">管理员权限：</h5>
<p>';

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    if ($conn) {
        // 检查当前用户是否为管理员
        if (isset($_SESSION['user_id'])) {
            $stmt = $conn->prepare("SELECT role FROM personnel WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user_role = $stmt->fetchColumn();
            
            echo '<strong>数据库中的角色:</strong> "' . htmlspecialchars($user_role) . '"<br>';
            echo '<strong>是否为管理员:</strong> ' . (strtolower(trim($user_role)) === 'admin' ? '<span class="text-success">是</span>' : '<span class="text-danger">否</span>');
        }
    } else {
        echo '<span class="text-danger">数据库连接失败</span>';
    }
} catch (Exception $e) {
    echo '<span class="text-danger">错误: ' . htmlspecialchars($e->getMessage()) . '</span>';
}

echo '</p>

<div class="mt-4">
    <a href="personnel.php" class="btn btn-primary">返回人员列表</a>
    <a href="login.php" class="btn btn-secondary">重新登录</a>
</div>

</div>
</div>
</div>
</body>
</html>';