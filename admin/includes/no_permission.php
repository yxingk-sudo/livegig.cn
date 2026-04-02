<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>无权访问 - 管理系统</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        .error-container {
            background: white;
            border-radius: 20px;
            padding: 3rem;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 500px;
        }
        .error-icon {
            font-size: 5rem;
            color: #dc3545;
            margin-bottom: 1rem;
        }
        .error-title {
            font-size: 2rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 1rem;
        }
        .error-message {
            color: #6c757d;
            font-size: 1.1rem;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        .error-details {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            font-size: 0.9rem;
            color: #6c757d;
        }
        .btn-group-custom {
            display: flex;
            gap: 1rem;
            justify-content: center;
        }
        .btn-custom {
            padding: 0.75rem 2rem;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .btn-primary-custom {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
        }
        .btn-outline-custom {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">
            <i class="bi bi-shield-lock"></i>
        </div>
        
        <h1 class="error-title">无权访问</h1>
        
        <p class="error-message">
            抱歉，您没有权限访问此页面。<br>
            如需访问，请联系系统管理员获取相应权限。
        </p>
        
        <?php if (isset($message) && !empty($message)): ?>
        <div class="error-details">
            <i class="bi bi-info-circle"></i> 
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>
        
        <div class="btn-group-custom">
            <a href="javascript:history.back()" class="btn btn-custom btn-outline-custom">
                <i class="bi bi-arrow-left"></i> 返回上一页
            </a>
            
            <a href="index.php" class="btn btn-custom btn-primary-custom">
                <i class="bi bi-house-door"></i> 返回首页
            </a>
        </div>
        
        <div class="mt-4">
            <small class="text-muted">
                <i class="bi bi-question-circle"></i> 
                需要帮助？请联系 <a href="mailto:admin@example.com">admin@example.com</a>
            </small>
        </div>
    </div>
    
    <script>
        // 如果是从其他页面跳转而来，显示来源信息
        if (document.referrer) {
            const referrerUrl = document.referrer;
            console.log('用户来自:', referrerUrl);
        }
        
        // 记录未授权访问尝试（可选）
        console.warn('未授权访问尝试已记录');
    </script>
</body>
</html>
