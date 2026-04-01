<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>酒店统计 - 简化版</title>
    
    <!-- Bootstrap CSS -->
    <link href="assets/css/app.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/admin.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- 侧边栏 -->
            <nav class="col-md-3 col-lg-2 d-md-block bg-light sidebar">
                <div class="position-sticky pt-3">
                    <h5 class="text-primary px-3 mb-3">
                        <i class="bi bi-grid-3x3-gap-fill"></i> 管理后台
                    </h5>
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link active" href="#">
                                <i class="bi bi-bar-chart me-2"></i>酒店统计
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#">
                                <i class="bi bi-house me-2"></i>酒店管理
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>
            
            <!-- 主内容区 -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><i class="bi bi-bar-chart"></i> 酒店信息统计</h1>
                </div>
                
                <!-- 统计卡片 -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <h3 class="stats-number text-primary">125</h3>
                                <small class="text-muted">总预订次数</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <h3 class="stats-number text-success">89</h3>
                                <small class="text-muted">总房间数</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <h3 class="stats-number text-info">156</h3>
                                <small class="text-muted">入住人次</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card stats-card">
                            <div class="card-body text-center">
                                <h3 class="stats-number text-warning">423</h3>
                                <small class="text-muted">总房晚数</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 筛选条件 -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-funnel"></i> 筛选条件</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <label class="form-label">选择项目</label>
                                <select class="form-select">
                                    <option>所有项目</option>
                                    <option>项目A</option>
                                    <option>项目B</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">选择酒店</label>
                                <select class="form-select">
                                    <option>所有酒店</option>
                                    <option>希尔顿酒店</option>
                                    <option>万豪酒店</option>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button class="btn btn-primary">
                                    <i class="bi bi-search"></i> 查询
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 数据表格 -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="bi bi-table"></i> 数据表格</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>项目</th>
                                        <th>人员</th>
                                        <th>酒店</th>
                                        <th>入住日期</th>
                                        <th>房型</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>测试项目</td>
                                        <td>张三</td>
                                        <td>希尔顿酒店</td>
                                        <td>2024-01-15</td>
                                        <td>标准间</td>
                                    </tr>
                                    <tr>
                                        <td>测试项目</td>
                                        <td>李四</td>
                                        <td>万豪酒店</td>
                                        <td>2024-01-16</td>
                                        <td>大床房</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="assets/js/app.min.js"></script>
</body>
</html>